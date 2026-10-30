<?php
/**
 * Admin Sync Covers — Índice de Drive + auto-asignar portadas y álbumes.
 * POST /admin/media/sync-covers   ·   GET /api/cron/sync-covers?token=CRON_SECRET
 *
 * ESTRATEGIA (v3, índice local):
 *   1) DriveIndex::sync() recorre la carpeta raíz completa y la guarda en drive_index
 *      (pocas llamadas a Drive; hace públicos los archivos nuevos)
 *   2) DriveIndex::relink(): enlace único foto ↔ código (matched_sku) y portada de TODOS
 *      los productos (propia > familia > video; quita las viejas o ajenas)
 *   3) album_id = álbum de la mejor foto propia
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
// 1) Álbumes = carpetas de primer nivel de la raíz actual (sin "_INDICE"/"PORTADAS").
//    Los de una raíz anterior se desactivan; el álbum de un producto que apunte
//    a uno de esos se recalcula.
// ============================================================
$albumSync = DriveIndex::syncAlbums($db, $rootFolderId);
$validAlbumSet = array_flip($albumSync['album_ids']);
// Sin álbumes válidos (raíz equivocada o vacía) no se toca el álbum de ningún producto
$albumsKnown = !empty($validAlbumSet);
$albumsProcessed = (int) $db->query("SELECT COUNT(*) FROM albums WHERE is_active = 1")->fetchColumn();
// Carpetas ocultas en el admin + foto al azar de cada carpeta
$folderState = DriveIndex::refreshFolderState($db);

// ============================================================
// 2) Enlace único foto ↔ producto y portadas (DriveIndex::relink, regla en matchFileToProduct)
// ============================================================
$link = DriveIndex::relink($db, $forceReassign);
if (!$link['ok']) {
    echo json_encode(['ok' => false, 'error' => 'No se pudo enlazar las fotos (¿faltan migraciones?).', 'index' => $indexStats]);
    exit;
}
$unmappedFiles = $link['unlinked'];
$totalDriveFiles = $link['files'];
$assigned = $link['covers_set'];
$coversCleared = $link['covers_cleared'];
$errors = [];

// ============================================================
// 3) Álbum (marca) de cada producto = álbum de su mejor foto propia
// ============================================================
// Álbum: si no tiene, si apunta a un álbum que ya no existe bajo la raíz, o en modo forzado.
$products = $db->query("SELECT id, sku, album_id FROM products WHERE archived = 0")->fetchAll(PDO::FETCH_ASSOC);
$setAlbum = $db->prepare("UPDATE products SET album_id = ? WHERE id = ?");
$albumsFixed = 0;
foreach ($products as $prod) {
    // album_id = raíz (asignado antes a fotos sueltas) no es marca pero no se borra si no hay otra
    $albumIsValid = !empty($prod['album_id']) && (!$albumsKnown || isset($validAlbumSet[$prod['album_id']]));
    $albumIsStale = !empty($prod['album_id']) && !$albumIsValid && $prod['album_id'] !== $rootFolderId;
    $newAlbum = $link['album_of'][$prod['sku']] ?? null;
    if (($forceReassign || !$albumIsValid) && $newAlbum !== null && $newAlbum !== $prod['album_id']) {
        $setAlbum->execute([$newAlbum, $prod['id']]);
        $albumsFixed++;
    } elseif ($albumIsStale && $newAlbum === null) {
        // Apunta a un álbum de una raíz anterior y no hay foto en la actual
        $setAlbum->execute([null, $prod['id']]);
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
    'family_files' => $link['family'],
    'without_photo' => $link['without_photo'],
    'total' => count($products),
    'remaining' => $remaining,
    'albums_processed' => $albumsProcessed,
    'drive_files_indexed' => $totalDriveFiles,
    'strategy' => 'drive-index',
    'index' => $indexStats,
    'folders' => $folderState,
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
        . ($coversCleared ? "🧹 {$coversCleared} portada(s) quitadas (foto vieja, oculta o de otro código, y no hay otra). " : '')
        . (count($unmappedFiles) ? '📋 ' . count($unmappedFiles) . ' archivo(s) con nombre que no es un código (descarga la lista). ' : '')
        . ($assigned > 0
        ? "⭐ {$assigned} portada(s) nuevas o corregidas. "
          . "Escaneamos {$albumsProcessed} álbumes con {$totalDriveFiles} archivos en Drive. "
          . ($remaining > 0 ? "Quedan {$remaining} sin asignar." : '¡Todos los productos tienen portada y álbum!')
        : ($remaining == 0 ? '✅ Todo al día: no hubo cambios.' : "No hubo portadas nuevas. {$remaining} producto(s) siguen sin foto o sin álbum: "
          . "ninguna foto se llama como su código."))
]);
