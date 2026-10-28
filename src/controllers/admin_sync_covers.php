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

// Regla única: la foto se enlaza SOLO si su nombre es el código del producto
// ("X6071.jpg") o empieza por el código seguido de un separador ("X6071-A.jpg",
// "X6071 (2).jpg"). Es la misma regla de la galería y la búsqueda
// (fileNameStartsWithSku). Lo que no cumple queda en la lista "sin código".
// Claves: el SKU tal cual y sin ":"/"."/espacios (hoja "M:8392-1" ↔ foto "M8392-1.jpg").
$productIndex = [];
foreach ($products as $prod) {
    $sku = cleanSkuDisplay(trim($prod['sku']));
    $productIndex[strtolower($sku)] ??= $prod;
}
foreach ($products as $prod) {
    $productIndex[skuFileKey(cleanSkuDisplay(trim($prod['sku'])))] ??= $prod;
}
unset($productIndex['']);

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
 * Producto cuyo código es el nombre del archivo (o su inicio + separador).
 * Retorna [clave, calidad] o null. Calidad: 3 = nombre igual al código, 2 = código + sufijo.
 * Gana el código más largo: "839-5-A.jpg" es de "839-5" aunque exista "839".
 */
function matchFileToProduct(array $file, array &$productIndex): ?array
{
    $name = strtolower(trim(pathinfo($file['name'] ?? '', PATHINFO_FILENAME)));
    for ($len = strlen($name); $len > 0; $len--) {
        $prefix = substr($name, 0, $len);
        if (!isset($productIndex[$prefix])) continue;
        if ($len === strlen($name)) return [$prefix, 3];
        if (!ctype_alnum($name[$len])) return [$prefix, 2];
    }
    return null;
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
$fileOwner = []; // file_id => id del producto (para la lista "sin código" y limpiar portadas ajenas)

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

    $prod = $productIndex[$match[0]];
    $fileOwner[$file['id']] = $prod['id'];
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
$clearCover = $db->prepare("UPDATE products SET cover_image_url = NULL WHERE id = ?");
$coversCleared = 0;
$indexedMedia = array_flip(array_column($mediaFiles, 'id'));
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

    // Forzado: si la portada guardada es una foto del índice que NO es de este código
    // (regla vieja más floja), se quita. Portadas fuera del índice no se tocan.
    if ($forceReassign && $coverUrl === null && !empty($prod['cover_image_url'])
        && preg_match('#/d/([\w-]+)#', $prod['cover_image_url'], $cm)
        && isset($indexedMedia[$cm[1]]) && ($fileOwner[$cm[1]] ?? null) !== $prodId) {
        $clearCover->execute([$prodId]);
        $coversCleared++;
    }

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

// Guardar a qué código quedó enlazado cada archivo (lista "sin código" del admin)
$skuById = array_column($products, 'sku', 'id');
$db->beginTransaction();
$db->exec("UPDATE drive_index SET matched_sku = NULL WHERE matched_sku IS NOT NULL");
$setMatch = $db->prepare("UPDATE drive_index SET matched_sku = ? WHERE file_id = ?");
foreach ($fileOwner as $fileId => $prodId) {
    $setMatch->execute([$skuById[$prodId], $fileId]);
}
$db->commit();

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
    'covers_cleared' => $coversCleared,
    'message' => "🗂️ Índice de Drive: {$indexStats['files']} archivos en {$indexStats['folders']} carpetas"
        . ($indexStats['made_public'] ? ", {$indexStats['made_public']} hechos públicos" : '') . ". "
        . ($albumSync['added'] || $albumSync['deactivated']
            ? "📂 Álbumes: {$albumSync['added']} nuevos, {$albumSync['deactivated']} desactivados (ya no están en la carpeta raíz). "
            : '')
        . ($albumsFixed ? "🔁 {$albumsFixed} producto(s) con álbum corregido. " : '')
        . ($coversCleared ? "🧹 {$coversCleared} portada(s) quitadas por no ser de su código. " : '')
        . (count($unmappedFiles) ? '📋 ' . count($unmappedFiles) . ' archivo(s) con nombre que no es un código (descarga la lista). ' : '')
        . ($assigned > 0
        ? "⭐ {$assigned} producto(s) actualizado(s). "
          . "Escaneamos {$albumsProcessed} álbumes con {$totalDriveFiles} archivos en Drive. "
          . ($remaining > 0 ? "Quedan {$remaining} sin asignar." : '¡Todos los productos tienen portada y álbum!')
        : ($remaining == 0 ? '✅ Todo al día: no hubo cambios.' : "No hubo portadas nuevas. {$remaining} producto(s) siguen sin foto o sin álbum: "
          . "ninguna foto se llama como su código."))
]);
