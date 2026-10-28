<?php
/**
 * Admin Sync Covers — Índice de Drive + auto-asignar portadas y álbumes.
 * POST /admin/media/sync-covers   ·   GET /api/cron/sync-covers?token=CRON_SECRET
 *
 * ESTRATEGIA (v3, índice local):
 *   1) DriveIndex::sync() recorre la carpeta raíz completa y la guarda en drive_index
 *      (pocas llamadas a Drive; hace públicos los archivos nuevos)
 *   2) Matchear los archivos del índice con productos por SKU (en memoria)
 *   3) Asignar cover_image_url Y album_id
 * Las páginas públicas leen drive_index, así que este es el ÚNICO lugar que recorre Drive.
 */
require_once __DIR__ . '/../services/DriveIndex.php';

header('Content-Type: application/json');
set_time_limit(600);

$db = getDB();

$isCron = false;
$cronToken = env('CRON_SECRET', '');
if ($cronToken !== '' && isset($_GET['token']) && hash_equals($cronToken, $_GET['token'])) {
    $isCron = true;
} else {
    // La sesión ya es manejada por index.php
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido o acceso denegado.']);
        exit;
    }
}

$forceReassign = !empty($_GET['force']) || !empty($_POST['force']);

$drive = new GoogleDriveService();
$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

$token = $drive->getValidToken($db);
if (empty($token)) {
    echo json_encode(['ok' => false, 'error' => 'No hay conexión a Google Drive.']);
    exit;
}

if (empty($rootFolderId)) {
    echo json_encode(['ok' => false, 'error' => 'GOOGLE_DRIVE_FOLDER_ID no configurado.']);
    exit;
}

// ============================================================
// 0) Actualizar el índice local de Drive
// ============================================================
$indexStats = DriveIndex::sync($db, $drive, $rootFolderId);
if (!$indexStats['ok']) {
    echo json_encode(['ok' => false, 'error' => $indexStats['error'], 'index' => $indexStats]);
    exit;
}

// ============================================================
// 1) Cargar TODOS los productos para el índice de coincidencia
// ============================================================
$products = $db->query(
    "SELECT id, sku, cover_image_url, album_id FROM products WHERE archived = 0"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    echo json_encode([
        'ok' => true,
        'assigned' => 0,
        'remaining' => 0,
        'message' => 'No hay productos activos en la base de datos.'
    ]);
    exit;
}

// Construir índice de productos por SKU (limpio, sin extensión y sin prefijo)
$productIndex = [];
foreach ($products as $prod) {
    $sku = trim($prod['sku']);
    // Indexar el SKU original (limpio sin extensión)
    $skuClean = preg_replace('/\.\w{2,4}$/i', '', $sku);
    $skuLower = strtolower($skuClean);
    
    if (!isset($productIndex[$skuLower])) {
        $productIndex[$skuLower] = $prod;
    }
    
    // También indexar con extensión por si acaso
    $skuWithExt = strtolower($sku);
    if ($skuWithExt !== $skuLower && !isset($productIndex[$skuWithExt])) {
        $productIndex[$skuWithExt] = $prod;
    }
    
    // También indexar el SKU raíz (sin sufijos de color/variante)
    $rootSku = strtolower(extractRootSku($skuClean));
    if ($rootSku !== $skuLower && !isset($productIndex[$rootSku])) {
        $productIndex[$rootSku] = $prod;
    }
    
    // También indexar sin prefijo de marca (ej: KNM-8845 → 8845)
    $noPrefijo = strtolower(extractSkuWithoutPrefix($skuClean));
    if ($noPrefijo !== $skuLower && !isset($productIndex[$noPrefijo])) {
        $productIndex[$noPrefijo] = $prod;
    }
    
    // También indexar sin espacios
    $noSpace = str_replace(' ', '', $skuLower);
    if ($noSpace !== $skuLower && !isset($productIndex[$noSpace])) {
        $productIndex[$noSpace] = $prod;
    }
}


// ============================================================
// 2) Álbumes activos (carpetas de primer nivel). Si la tabla está vacía,
//    se crean desde el índice.
// ============================================================
$activeAlbums = $db->query("SELECT drive_id FROM albums WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);

if (empty($activeAlbums)) {
    $topFolders = $db->prepare("SELECT file_id, name FROM drive_index WHERE is_folder = 1 AND parent_id = ?");
    $topFolders->execute([$rootFolderId]);
    $ins = $db->prepare("INSERT IGNORE INTO albums (drive_id, name) VALUES (?, ?)");
    foreach ($topFolders->fetchAll() as $folder) {
        $ins->execute([$folder['file_id'], $folder['name']]);
    }
    $activeAlbums = $db->query("SELECT drive_id FROM albums WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
}
$activeAlbumSet = array_flip($activeAlbums);

// ============================================================
// 3) Archivos media del índice: de álbumes activos + sueltos en la raíz
//    (los sueltos se asignan al "álbum" raíz, como antes)
// ============================================================
$mediaFiles = $db->query(
    "SELECT file_id AS id, name, mime_type AS mimeType, album_id FROM drive_index
     WHERE is_folder = 0 AND (mime_type LIKE 'image/%' OR mime_type LIKE 'video/%')"
)->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 4) Para cada álbum, listar archivos y matchear con productos
// ============================================================
// El puntaje de candidatos a portada vive en scoreCoverCandidate() (src/helpers.php):
// imagen siempre gana a video, y se prefieren nombres "principal/cover/01/-1/f1".

/**
 * Matchea un archivo con un producto del índice.
 * Retorna el SKU del producto que matchea, o null.
 */
function matchFileToProduct(array $file, array &$productIndex): ?string
{
    $baseName = strtolower(pathinfo($file['name'] ?? '', PATHINFO_FILENAME));
    $noSpaceName = str_replace(' ', '', $baseName);
    $noPrefixName = strtolower(extractSkuWithoutPrefix($baseName));
    
    $variations = array_unique([$baseName, $noSpaceName, $noPrefixName]);

    // Match exacto
    foreach ($variations as $v) {
        if (isset($productIndex[$v])) {
            return $v;
        }
    }
    
    // Match por prefijo: el SKU más largo del índice que sea prefijo del nombre.
    // Se prueban los prefijos del nombre de mayor a menor (O(largo del nombre) en
    // vez de recorrer todo el índice por archivo). El carácter siguiente no puede
    // ser un dígito (evita 123-1 → 123-10).
    foreach ($variations as $v) {
        for ($len = strlen($v) - 1; $len > 0; $len--) {
            $prefix = substr($v, 0, $len);
            if (isset($productIndex[$prefix]) && !ctype_digit($v[$len])) {
                return $prefix;
            }
        }
    }
    
    return null;
}

$assigned = 0;
$assignedVideos = 0;
$totalDriveFiles = 0;
$errors = [];
$unmappedFiles = []; // Track files that matched no products
$updatesQueue = []; // id => ['url' => ..., 'album_id' => ...]
$candidates = []; // prodId => ['file'=>..., 'album_id'=>..., 'score'=>..., 'needs_cover'=>..., 'needs_album'=>...]

$albumsProcessed = count($activeAlbums);
foreach ($mediaFiles as $file) {
    $albumId = $file['album_id'] ?? null;
    if ($albumId !== null && !isset($activeAlbumSet[$albumId])) {
        continue; // álbum desactivado en el admin
    }
    $albumId = $albumId ?? $rootFolderId;
    $totalDriveFiles++;

    $matchedSku = matchFileToProduct($file, $productIndex);
    if (!$matchedSku) {
        $unmappedFiles[] = $file['name'];
        continue;
    }

    $prod = $productIndex[$matchedSku];
    $prodId = $prod['id'];

    // Solo asignar portada si el producto no tiene una (o si es modo forzado)
    $needsCover = $forceReassign || empty($prod['cover_image_url']);
    $needsAlbum = $forceReassign || empty($prod['album_id']);

    if (!$needsCover && !$needsAlbum) continue;

    // Guardar el MEJOR candidato por producto en vez del primero que aparezca:
    // una imagen siempre gana a un video (antes el primer match podía ser un
    // video y la portada quedaba siendo el primer cuadro = logo de la marca).
    $score = scoreCoverCandidate($file);
    if (!isset($candidates[$prodId]) || $score > $candidates[$prodId]['score']) {
        $candidates[$prodId] = [
            'file' => $file,
            'album_id' => $albumId,
            'score' => $score,
            'needs_cover' => $needsCover,
            'needs_album' => $needsAlbum,
        ];
    }
}

// Construir la cola de updates a partir del mejor candidato de cada producto
foreach ($candidates as $prodId => $cand) {
    $file = $cand['file'];
    $isVideo = str_starts_with($file['mimeType'] ?? '', 'video/');

    $coverUrl = null;
    if ($cand['needs_cover']) {
        $coverUrl = $isVideo
            ? "[VIDEO]https://lh3.googleusercontent.com/d/{$file['id']}"
            : lh3Url($file['id']);
        if ($isVideo) $assignedVideos++;
    }

    $updatesQueue[$prodId] = [
        'url' => $coverUrl,
        'album_id' => $cand['album_id'],
        'id' => $prodId
    ];
    $assigned++;
}

// ============================================================
// 4) Actualizar DB en lote
// ============================================================
$updateStmt = $db->prepare(
    "UPDATE products SET cover_image_url = COALESCE(?, cover_image_url), album_id = COALESCE(?, album_id) WHERE id = ?"
);

foreach ($updatesQueue as $upd) {
    $updateStmt->execute([$upd['url'], $upd['album_id'], $upd['id']]);
}

// Contar cuántos productos faltan realmente por portada al final
$productsMissing = $db->query(
    "SELECT COUNT(*) FROM products WHERE (cover_image_url IS NULL OR cover_image_url = '' OR album_id IS NULL) AND archived = 0"
)->fetchColumn();

// Invalidar cache
unset($_SESSION['media_linked_count_cache'], $_SESSION['media_linked_count_time']);

$remaining = $productsMissing;

echo json_encode([
    'ok' => true,
    'assigned' => $assigned,
    'assigned_images' => $assigned - $assignedVideos,
    'assigned_videos' => $assignedVideos,
    'total' => count($products),
    'remaining' => $remaining,
    'albums_processed' => $albumsProcessed,
    'drive_files_indexed' => $totalDriveFiles,
    'strategy' => 'drive-index',
    'index' => $indexStats,
    'errors' => $errors,
    'unmapped_files' => $unmappedFiles,
    'message' => "🗂️ Índice de Drive: {$indexStats['files']} archivos en {$indexStats['folders']} carpetas"
        . ($indexStats['made_public'] ? ", {$indexStats['made_public']} hechos públicos" : '') . ". "
        . ($assigned > 0
        ? "⭐ {$assigned} producto(s) actualizado(s). "
          . "Escaneamos {$albumsProcessed} álbumes con {$totalDriveFiles} archivos en Drive. "
          . ($remaining > 0 ? "Quedan {$remaining} sin asignar." : '¡Todos los productos tienen portada y álbum!')
        : "No se encontraron coincidencias para " . count($products) . " productos. "
          . "Escaneamos {$albumsProcessed} álbumes con {$totalDriveFiles} archivos. "
          . "Verifica que los archivos contengan el código del producto (SKU) en su nombre.")
]);
