<?php
/**
 * Admin Sync Covers — Auto-asignar portadas de Drive a productos.
 * POST /admin/media/sync-covers
 *
 * ENFOQUE RÁPIDO: Indexa TODOS los archivos media de Drive en una sola
 * llamada bulk (~30 API calls), luego matchea localmente con cada producto
 * sin portada. Esto es 10-50x más rápido que buscar por SKU individual.
 *
 * Procesa TODOS los productos sin portada en cada ejecución.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

header('Content-Type: application/json');
set_time_limit(300);

$db = getDB();

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

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
// 1) Cargar TODOS los productos sin portada o sin album_id
// ============================================================
$products = $db->query(
    "SELECT id, sku, cover_image_url, album_id FROM products WHERE (cover_image_url IS NULL OR cover_image_url = '' OR album_id IS NULL) AND archived = 0"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    echo json_encode([
        'ok' => true,
        'assigned' => 0,
        'remaining' => 0,
        'message' => '✅ Todos los productos ya tienen portada y álbum asignado.'
    ]);
    exit;
}

// ============================================================
// 2) Indexar TODOS los archivos media de Drive de una sola vez
// ============================================================
$bulkResult = $drive->listAllMediaFiles($rootFolderId);
$allDriveFiles = $bulkResult['files'] ?? [];
$strategy = $bulkResult['strategy'] ?? 'unknown';

if (empty($allDriveFiles)) {
    echo json_encode([
        'ok' => true,
        'assigned' => 0,
        'remaining' => count($products),
        'strategy' => $strategy,
        'message' => 'No se encontraron archivos media en Drive. Verifica la conexión.'
    ]);
    exit;
}

// ============================================================
// 3) Construir índice HashMap: nombre_archivo → [archivos]
// ============================================================
$fileIndex = []; 
foreach ($allDriveFiles as $f) {
    $name = strtolower(pathinfo($f['name'] ?? '', PATHINFO_FILENAME));
    if (!isset($fileIndex[$name])) $fileIndex[$name] = [];
    $fileIndex[$name][] = $f;
}

function findMatchesInIndex(string $sku, array &$fileIndex, array &$allDriveFiles): array {
    $skuLower = strtolower($sku);
    $matches = [];
    if (isset($fileIndex[$skuLower])) {
        $matches = array_merge($matches, $fileIndex[$skuLower]);
    }
    foreach ($fileIndex as $key => $files) {
        if ($key === $skuLower) continue; 
        if (stripos($key, $skuLower) === 0) {
            $nextPos = strlen($skuLower);
            if ($nextPos < strlen($key) && ctype_digit($key[$nextPos])) continue;
            $matches = array_merge($matches, $files);
        }
    }
    return $matches;
}

$coverKeywords = ['principal', 'cover', 'portada', 'front', 'frente'];
$numericPriority = ['01', '_1', '-1', 'f1'];

function scoreCover(array $file, array $coverKeywords, array $numericPriority): int
{
    $name = strtolower($file['name'] ?? '');
    $score = 0;
    foreach ($coverKeywords as $kw) {
        if (str_contains($name, $kw)) $score += 10;
    }
    foreach ($numericPriority as $np) {
        if (str_contains($name, $np)) $score += 5;
    }
    return $score;
}

// Pre-indexar: por cada producto, encontrar archivos que matcheen su SKU
$updateStmt = $db->prepare("UPDATE products SET cover_image_url = COALESCE(?, cover_image_url), album_id = COALESCE(?, album_id) WHERE id = ?");
$assigned = 0;
$assignedVideos = 0;
$errors = [];
$fileIdsToPublish = [];
$updatesQueue = [];

foreach ($products as $prod) {
    try {
        $sku = preg_replace('/\.\w{2,4}$/i', '', $prod['sku']);
        $rootSku = extractRootSku($sku);

        // Buscar archivos usando el HashMap (mucho más rápido que O(M) por producto)
        $matchingFiles = findMatchesInIndex($sku, $fileIndex, $allDriveFiles);
        
        // También buscar con SKU raíz si es diferente
        if ($rootSku !== $sku) {
            $rootMatches = findMatchesInIndex($rootSku, $fileIndex, $allDriveFiles);
            $existingIds = array_column($matchingFiles, 'id');
            foreach ($rootMatches as $rm) {
                if (!in_array($rm['id'], $existingIds)) {
                    $matchingFiles[] = $rm;
                }
            }
        }
        
        // También intentar sin prefijo de marca
        $skuNoPrefijo = extractSkuWithoutPrefix($sku);
        if ($skuNoPrefijo !== $sku) {
            $noPrefMatches = findMatchesInIndex($skuNoPrefijo, $fileIndex, $allDriveFiles);
            $existingIds = array_column($matchingFiles, 'id');
            foreach ($noPrefMatches as $npm) {
                if (!in_array($npm['id'], $existingIds)) {
                    $matchingFiles[] = $npm;
                }
            }
        }

        if (empty($matchingFiles)) {
            continue;
        }

        // Separar imágenes y videos
        $images = array_filter($matchingFiles, fn($f) => str_starts_with($f['mimeType'] ?? '', 'image/'));
        $videos = array_filter($matchingFiles, fn($f) => str_starts_with($f['mimeType'] ?? '', 'video/'));

        $bestMedia = null;
        $isVideo = false;

        if (!empty($images)) {
            $images = array_values($images);
            usort($images, function ($a, $b) use ($coverKeywords, $numericPriority) {
                return scoreCover($b, $coverKeywords, $numericPriority)
                    - scoreCover($a, $coverKeywords, $numericPriority);
            });
            $bestMedia = $images[0];
        } elseif (!empty($videos)) {
            $bestMedia = array_values($videos)[0];
            $isVideo = true;
        }

        if ($bestMedia) {
            $fileIdsToPublish[] = $bestMedia['id'];
            $coverUrl = $isVideo
                ? "[VIDEO]https://lh3.googleusercontent.com/d/{$bestMedia['id']}"
                : "https://lh3.googleusercontent.com/d/{$bestMedia['id']}=s400";
            
            $albumId = $drive->getAlbumIdForFile($bestMedia, $rootFolderId);

            $updatesQueue[] = [
                'url' => $coverUrl,
                'album_id' => $albumId,
                'id' => $prod['id']
            ];

            $assigned++;
            if ($isVideo)
                $assignedVideos++;
        }
    } catch (Exception $e) {
        $errors[] = "SKU {$prod['sku']}: {$e->getMessage()}";
    }
}

// ============================================================
// 4) Hacer públicos en batch (paralelo con curl_multi)
// ============================================================
if (!empty($fileIdsToPublish)) {
    $drive->makePublicBatch($fileIdsToPublish);
}

// ============================================================
// 5) Actualizar DB en lote
// ============================================================
foreach ($updatesQueue as $upd) {
    $updateStmt->execute([$upd['url'], $upd['album_id'], $upd['id']]);
}

// Invalidar cache
unset($_SESSION['media_linked_count_cache'], $_SESSION['media_linked_count_time']);

$remaining = count($products) - $assigned;

echo json_encode([
    'ok' => true,
    'assigned' => $assigned,
    'assigned_images' => $assigned - $assignedVideos,
    'assigned_videos' => $assignedVideos,
    'total' => count($products),
    'remaining' => $remaining,
    'drive_files_indexed' => count($allDriveFiles),
    'strategy' => $strategy,
    'errors' => $errors,
    'message' => $assigned > 0
        ? "⭐ {$assigned} portada(s) asignada(s) (" . ($assigned - $assignedVideos) . " img, {$assignedVideos} vid). " 
          . "Indexados " . count($allDriveFiles) . " archivos de Drive. "
          . ($remaining > 0 ? "Quedan {$remaining} sin portada (no se encontraron archivos que coincidan con su SKU)." : '¡Todos los productos tienen portada!')
        : "No se encontraron coincidencias para " . count($products) . " productos. Indexamos " . count($allDriveFiles) . " archivos de Drive. Verifica que los archivos contengan el código del producto en su nombre."
]);
