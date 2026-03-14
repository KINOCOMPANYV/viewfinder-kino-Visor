<?php
/**
 * Admin Sync Covers — Auto-asignar portadas de Drive a productos.
 * POST /admin/media/sync-covers
 *
 * NUEVO ENFOQUE: Usa findBySku() directamente contra la API de Drive
 * (la misma búsqueda que funciona en la página de producto).
 * No depende de matching local por nombre de archivo.
 *
 * Procesa hasta 50 productos por ejecución para evitar timeouts.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

header('Content-Type: application/json');
set_time_limit(300);

$db = getDB();
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

// Cargar productos sin cover (hasta 200 por batch)
$products = $db->query("SELECT id, sku FROM products WHERE (cover_image_url IS NULL OR cover_image_url = '') AND archived = 0 LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

$totalWithout = $db->query("SELECT COUNT(*) FROM products WHERE (cover_image_url IS NULL OR cover_image_url = '') AND archived = 0")->fetchColumn();

if (empty($products)) {
    echo json_encode(['ok' => true, 'assigned' => 0, 'message' => 'Todos los productos ya tienen portada.']);
    exit;
}

// Palabras clave para priorizar como portada
$coverKeywords = ['principal', 'cover', 'portada', 'front', 'frente'];
$numericPriority = ['01', '_1', '-1', 'f1'];

function scoreCover(array $file, array $coverKeywords, array $numericPriority): int
{
    $name = strtolower($file['name'] ?? '');
    $score = 0;
    foreach ($coverKeywords as $kw) {
        if (str_contains($name, $kw))
            $score += 10;
    }
    foreach ($numericPriority as $np) {
        if (str_contains($name, $np))
            $score += 5;
    }
    return $score;
}

$updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
$assigned = 0;
$assignedVideos = 0;
$errors = [];
$diagnostics = [];
$fileIdsToPublish = [];
$updatesQueue = [];

foreach ($products as $prod) {
    $diag = ['sku' => $prod['sku'], 'status' => 'no_files'];

    try {
        $sku = preg_replace('/\.\w{2,4}$/i', '', $prod['sku']);
        $rootSku = extractRootSku($sku);

        // ============================================================
        // NUEVO: Usar findBySku() directamente contra la API de Drive
        // Esta es la MISMA búsqueda que funciona en /api/media/{sku}
        // Busca con "name contains 'SKU'" en la API, no matching local
        // ============================================================
        $allFiles = $drive->findBySku($rootFolderId, $rootSku);

        // Si buscamos con el root y el SKU original es diferente, buscar también por el original
        if ($sku !== $rootSku) {
            $extraFiles = $drive->findBySku($rootFolderId, $sku);
            $existingIds = array_column($allFiles, 'id');
            foreach ($extraFiles as $ef) {
                if (!in_array($ef['id'], $existingIds)) {
                    $allFiles[] = $ef;
                }
            }
        }

        $diag['files_found'] = count($allFiles);
        $diag['root_sku'] = $rootSku;
        $diag['file_names'] = array_map(fn($f) => $f['name'] ?? '?', array_slice($allFiles, 0, 5));

        if (empty($allFiles)) {
            $diag['status'] = 'no_files_in_drive';
            $diagnostics[] = $diag;
            continue;
        }

        // Separar imágenes y videos
        $images = array_filter($allFiles, fn($f) => str_starts_with($f['mimeType'] ?? '', 'image/'));
        $videos = array_filter($allFiles, fn($f) => str_starts_with($f['mimeType'] ?? '', 'video/'));

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

            $updatesQueue[] = [
                'url' => $coverUrl,
                'id' => $prod['id']
            ];

            $assigned++;
            if ($isVideo)
                $assignedVideos++;
            $diag['status'] = ($isVideo ? 'video' : 'image') . '_assigned';
            $diag['assigned_file'] = $bestMedia['name'];
        } else {
            $diag['status'] = 'no_media_files';
        }
    } catch (Exception $e) {
        $diag['status'] = 'error';
        $diag['error'] = $e->getMessage();
        $errors[] = "SKU {$prod['sku']}: {$e->getMessage()}";
    }

    $diagnostics[] = $diag;
}

// Hacer públicos en batch
if (!empty($fileIdsToPublish)) {
    $drive->makePublicBatch($fileIdsToPublish);
}

// Actualizar DB
foreach ($updatesQueue as $upd) {
    $updateStmt->execute([$upd['url'], $upd['id']]);
}

// Invalidar cache de linked count
unset($_SESSION['media_linked_count_cache'], $_SESSION['media_linked_count_time']);

$remaining = $totalWithout - $assigned;

echo json_encode([
    'ok' => true,
    'assigned' => $assigned,
    'assigned_images' => $assigned - $assignedVideos,
    'assigned_videos' => $assignedVideos,
    'total' => count($products),
    'remaining' => $remaining,
    'errors' => $errors,
    'diagnostics' => $diagnostics,
    'message' => $assigned > 0
        ? "⭐ {$assigned} producto(s) recibieron portada (" . ($assigned - $assignedVideos) . " img, {$assignedVideos} vid)." . ($remaining > 0 ? " Quedan {$remaining} sin portada." : '')
        : "No se encontraron archivos para los " . count($products) . " productos. Verifica que los archivos en Drive contengan el código del producto en su nombre."
]);
