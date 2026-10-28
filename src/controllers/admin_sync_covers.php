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

// Índice de coincidencia: clave → producto. Claves FUERTES = el SKU tal cual
// (con o sin extensión). Claves DÉBILES = variantes flojas (raíz, sin prefijo de
// marca, sin espacios): sirven de respaldo pero nunca le ganan a una fuerte, así
// una foto "8845.jpg" de otra marca no le quita el álbum a "KNM-8845".
$productIndex = [];
$addKey = function (string $key, array $prod, bool $strong) use (&$productIndex) {
    if (!isset($productIndex[$key]) || ($strong && !$productIndex[$key]['strong'])) {
        $productIndex[$key] = ['prod' => $prod, 'strong' => $strong];
    }
};
foreach ($products as $prod) {
    $addKey(strtolower(cleanSkuDisplay(trim($prod['sku']))), $prod, true);
    $addKey(strtolower(trim($prod['sku'])), $prod, true);
}
foreach ($products as $prod) {
    $skuLower = strtolower(cleanSkuDisplay(trim($prod['sku'])));
    $addKey(strtolower(extractRootSku($skuLower)), $prod, false);
    $addKey(strtolower(extractSkuWithoutPrefix($skuLower)), $prod, false);
    $addKey(str_replace(' ', '', $skuLower), $prod, false);
}

// ============================================================
// 2) Álbumes = carpetas de primer nivel de la raíz actual (sin "_INDICE"/"PORTADAS").
//    Los de una raíz anterior se desactivan; el álbum de un producto que apunte
//    a uno de esos se recalcula.
// ============================================================
$albumSync = DriveIndex::syncAlbums($db, $rootFolderId);
$validAlbumSet = array_flip($albumSync['album_ids']);
// Sin álbumes válidos (raíz equivocada o vacía) no se toca el álbum de ningún producto
$albumsKnown = !empty($validAlbumSet);
$activeAlbumSet = array_flip($db->query("SELECT drive_id FROM albums WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN));

// ============================================================
// 3) Archivos media del índice (orden estable para que los empates no cambien)
// ============================================================
$mediaFiles = $db->query(
    "SELECT file_id AS id, name, mime_type AS mimeType, album_id FROM drive_index
     WHERE is_folder = 0 AND (mime_type LIKE 'image/%' OR mime_type LIKE 'video/%')
     ORDER BY name ASC, file_id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 4) Matchear archivos con productos
// ============================================================
// El puntaje de candidatos a portada vive en scoreCoverCandidate() (src/helpers.php):
// imagen siempre gana a video, y se prefieren nombres "principal/cover/01/-1/f1".

/**
 * Matchea un archivo con un producto del índice.
 * Retorna [clave, calidad] o null. Calidad: 3 = nombre exacto al SKU,
 * 2 = el nombre empieza por el SKU, 1 = coincidencia floja (clave débil o
 * nombre sin prefijo de marca).
 */
function matchFileToProduct(array $file, array &$productIndex): ?array
{
    $baseName = strtolower(pathinfo($file['name'] ?? '', PATHINFO_FILENAME));
    // variación => ¿es el nombre real del archivo? (sin prefijo = floja)
    $variations = [$baseName => true];
    $variations += [str_replace(' ', '', $baseName) => true];
    $variations += [strtolower(extractSkuWithoutPrefix($baseName)) => false];

    $best = null;
    // Match exacto
    foreach ($variations as $v => $realName) {
        if (isset($productIndex[$v])) {
            $q = ($realName && $productIndex[$v]['strong']) ? 3 : 1;
            if (!$best || $q > $best[1]) $best = [$v, $q];
        }
    }
    if ($best && $best[1] === 3) return $best;
    // Un exacto flojo (1) no debe tapar un prefijo fuerte (2): se sigue buscando

    // Match por prefijo: el SKU más largo del índice que sea prefijo del nombre.
    // Se prueban los prefijos del nombre de mayor a menor (O(largo del nombre) en
    // vez de recorrer todo el índice por archivo). El carácter siguiente no puede
    // ser un dígito (evita 123-1 → 123-10).
    foreach ($variations as $v => $realName) {
        for ($len = strlen($v) - 1; $len > 0; $len--) {
            $prefix = substr($v, 0, $len);
            if (isset($productIndex[$prefix]) && !ctype_digit($v[$len])) {
                $q = ($realName && $productIndex[$prefix]['strong']) ? 2 : 1;
                if (!$best || $q > $best[1]) $best = [$prefix, $q];
                break;
            }
        }
    }
    return $best;
}

/** ¿El candidato $a es mejor que $b? Calidad, luego puntaje de portada; empate → se queda el primero (orden estable). */
function betterCandidate(array $a, ?array $b): bool
{
    return $b === null || [$a['quality'], $a['score']] > [$b['quality'], $b['score']];
}

$assigned = 0;
$assignedVideos = 0;
$totalDriveFiles = 0;
$errors = [];
$unmappedFiles = []; // Track files that matched no products
$coverCand = []; // prodId => mejor archivo para portada (cualquier carpeta)
$albumCand = []; // prodId => mejor archivo dentro de un álbum (define la marca)
$prodById = [];

$albumsProcessed = count($activeAlbumSet);
foreach ($mediaFiles as $file) {
    $albumId = $file['album_id'] ?? null;
    if ($albumId !== null && !isset($activeAlbumSet[$albumId])) {
        continue; // álbum desactivado o carpeta auxiliar ("_INDICE")
    }
    $totalDriveFiles++;

    $match = matchFileToProduct($file, $productIndex);
    if (!$match) {
        $unmappedFiles[] = $file['name'];
        continue;
    }

    $prod = $productIndex[$match[0]]['prod'];
    $prodId = $prod['id'];
    $prodById[$prodId] = $prod;

    // Guardar el MEJOR candidato por producto: primero la calidad del match (exacto
    // gana a flojo), luego el puntaje (una imagen siempre gana a un video).
    $cand = ['file' => $file, 'album_id' => $albumId, 'quality' => $match[1], 'score' => scoreCoverCandidate($file)];
    if (betterCandidate($cand, $coverCand[$prodId] ?? null)) {
        $coverCand[$prodId] = $cand;
    }
    // Archivos sueltos en la raíz no definen marca
    if ($albumId !== null && betterCandidate($cand, $albumCand[$prodId] ?? null)) {
        $albumCand[$prodId] = $cand;
    }
}

// ============================================================
// 5) Actualizar DB
// ============================================================
// Portada: solo si no tiene (o modo forzado). Álbum: si no tiene, si apunta a un
// álbum que ya no existe bajo la raíz, o en modo forzado.
$updateStmt = $db->prepare(
    "UPDATE products SET cover_image_url = COALESCE(?, cover_image_url), album_id = IF(?, ?, album_id) WHERE id = ?"
);
$clearAlbum = $db->prepare("UPDATE products SET album_id = NULL WHERE id = ?");
$albumsFixed = 0;

foreach ($products as $prod) {
    $prodId = $prod['id'];
    $needsCover = $forceReassign || empty($prod['cover_image_url']);
    // album_id = raíz (asignado antes a fotos sueltas) no es marca pero no se borra si no hay otra
    $albumIsValid = !empty($prod['album_id']) && (!$albumsKnown || isset($validAlbumSet[$prod['album_id']]));
    $albumIsStale = !empty($prod['album_id']) && !$albumIsValid && $prod['album_id'] !== $rootFolderId;
    $needsAlbum = $forceReassign || !$albumIsValid;

    $coverUrl = null;
    if ($needsCover && isset($coverCand[$prodId])) {
        $file = $coverCand[$prodId]['file'];
        $isVideo = str_starts_with($file['mimeType'] ?? '', 'video/');
        $coverUrl = $isVideo ? "[VIDEO]https://lh3.googleusercontent.com/d/{$file['id']}" : lh3Url($file['id']);
        if ($isVideo) $assignedVideos++;
    }
    $newAlbum = ($needsAlbum && isset($albumCand[$prodId])) ? $albumCand[$prodId]['album_id'] : null;

    // En modo forzado se recalcula todo, pero solo cuenta/escribe lo que cambia
    if ($coverUrl === $prod['cover_image_url']) $coverUrl = null;
    if ($newAlbum === $prod['album_id']) $newAlbum = null;

    if ($coverUrl !== null || $newAlbum !== null) {
        $updateStmt->execute([$coverUrl, $newAlbum !== null ? 1 : 0, $newAlbum, $prodId]);
        $assigned++;
        if ($newAlbum !== null) $albumsFixed++;
    } elseif ($albumIsStale && !isset($albumCand[$prodId])) {
        // Apunta a un álbum de una raíz anterior y no hay foto en la actual
        $clearAlbum->execute([$prodId]);
        $albumsFixed++;
    }
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
    'albums' => ['added' => $albumSync['added'], 'deactivated' => $albumSync['deactivated'], 'products_fixed' => $albumsFixed],
    'errors' => $errors,
    'unmapped_files' => $unmappedFiles,
    'message' => "🗂️ Índice de Drive: {$indexStats['files']} archivos en {$indexStats['folders']} carpetas"
        . ($indexStats['made_public'] ? ", {$indexStats['made_public']} hechos públicos" : '') . ". "
        . ($albumSync['added'] || $albumSync['deactivated']
            ? "📂 Álbumes: {$albumSync['added']} nuevos, {$albumSync['deactivated']} desactivados (ya no están en la carpeta raíz). "
            : '')
        . ($albumsFixed ? "🔁 {$albumsFixed} producto(s) con álbum corregido. " : '')
        . ($assigned > 0
        ? "⭐ {$assigned} producto(s) actualizado(s). "
          . "Escaneamos {$albumsProcessed} álbumes con {$totalDriveFiles} archivos en Drive. "
          . ($remaining > 0 ? "Quedan {$remaining} sin asignar." : '¡Todos los productos tienen portada y álbum!')
        : ($remaining == 0 ? '✅ Todo al día: no hubo cambios.' : "No se encontraron coincidencias para {$remaining} productos. "
          . "Escaneamos {$albumsProcessed} álbumes con {$totalDriveFiles} archivos. "
          . "Verifica que los archivos contengan el código del producto (SKU) en su nombre."))
]);
