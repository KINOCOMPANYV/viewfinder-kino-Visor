<?php
/**
 * Admin Sync Covers — Auto-asignar portadas de Drive a productos.
 * POST /admin/media/sync-covers
 *
 * Estrategia optimizada:
 *   1) Pre-indexa TODOS los archivos de Drive en memoria (un solo listFiles)
 *   2) Para cada producto sin cover, busca match por SKU en el índice local
 *   3) Prioriza imágenes sobre videos, usa keywords para mejor cover
 *   4) makePublicBatch para hacer públicos todos a la vez
 *   5) Procesa hasta 200 productos por ejecución
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

header('Content-Type: application/json');
set_time_limit(300); // 5 minutos

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

// Cargar productos sin cover (hasta 200)
$products = $db->query("SELECT id, sku FROM products WHERE cover_image_url IS NULL OR cover_image_url = '' LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

$totalWithout = $db->query("SELECT COUNT(*) FROM products WHERE cover_image_url IS NULL OR cover_image_url = ''")->fetchColumn();

if (empty($products)) {
    echo json_encode(['ok' => true, 'assigned' => 0, 'message' => 'Todos los productos ya tienen portada.']);
    exit;
}

/**
 * Clasificar archivos en: exactos, compatibles (prefijo) y rechazados.
 */
function classifyMatches(array $files, string $sku): array
{
    $exact = [];
    $compatible = [];

    foreach ($files as $f) {
        $nameOnly = pathinfo($f['name'] ?? '', PATHINFO_FILENAME);

        if (strcasecmp($nameOnly, $sku) === 0) {
            $exact[] = $f;
            continue;
        }

        if (stripos($nameOnly, $sku) === 0 && strlen($nameOnly) > strlen($sku)) {
            $nextChar = $nameOnly[strlen($sku)];
            if (!ctype_alnum($nextChar)) {
                $compatible[] = $f;
            }
        }
    }

    return ['exact' => $exact, 'compatible' => $compatible];
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

// ============================================================
// Pre-index: listar TODOS los archivos de Drive de una vez
// ============================================================
$allDriveFiles = $drive->listAllMediaFiles();
$driveFileIndex = $allDriveFiles['files'] ?? [];

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
        // Clean SKU: strip file extension for matching
        $cleanSku = preg_replace('/\.\w{2,4}$/i', '', $prod['sku']);

        // Buscar en índice pre-cargado usando skuMatchesFilename helper
        $allFiles = [];
        foreach ($driveFileIndex as $file) {
            if (skuMatchesFilename($cleanSku, $file['name'])) {
                $allFiles[] = $file;
            }
        }

        $diag['files_found'] = count($allFiles);
        $diag['file_names'] = array_map(fn($f) => $f['name'] ?? '?', array_slice($allFiles, 0, 10));

        if (empty($allFiles)) {
            $diag['status'] = 'drive_returned_0';
            $diagnostics[] = $diag;
            continue;
        }

        // Clasificar matches usando SKU limpio
        $classes = classifyMatches($allFiles, $cleanSku);
        $diag['exact_count'] = count($classes['exact']);
        $diag['compatible_count'] = count($classes['compatible']);

        // Usar exactos primero, luego compatibles
        $candidates = !empty($classes['exact']) ? $classes['exact'] : $classes['compatible'];

        if (empty($candidates)) {
            $diag['status'] = 'no_matching_names';
            $diagnostics[] = $diag;
            continue;
        }

        // Separar imágenes y videos
        $images = array_filter($candidates, fn($f) => str_starts_with($f['mimeType'] ?? '', 'image/'));
        $videos = array_filter($candidates, fn($f) => str_starts_with($f['mimeType'] ?? '', 'video/'));

        if (!empty($images)) {
            $images = array_values($images);
            usort($images, function ($a, $b) use ($coverKeywords, $numericPriority) {
                return scoreCover($b, $coverKeywords, $numericPriority)
                    - scoreCover($a, $coverKeywords, $numericPriority);
            });

            $best = $images[0];
            $fileIdsToPublish[] = $best['id'];
            $coverUrl = "https://lh3.googleusercontent.com/d/{$best['id']}";
            $updatesQueue[] = ['url' => $coverUrl, 'id' => $prod['id']];
            $assigned++;
            $diag['status'] = 'image_assigned';
            $diag['assigned_file'] = $best['name'];
        } elseif (!empty($videos)) {
            $best = array_values($videos)[0];
            $fileIdsToPublish[] = $best['id'];
            $coverUrl = "[VIDEO]https://drive.google.com/thumbnail?id={$best['id']}&sz=w400";
            $updatesQueue[] = ['url' => $coverUrl, 'id' => $prod['id']];
            $assignedVideos++;
            $assigned++;
            $diag['status'] = 'video_assigned';
            $diag['assigned_file'] = $best['name'];
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

// Hacer públicos en batch (curl_multi — mucho más rápido)
if (!empty($fileIdsToPublish)) {
    $drive->makePublicBatch($fileIdsToPublish);
}

// Actualizar DB
foreach ($updatesQueue as $upd) {
    $updateStmt->execute([$upd['url'], $upd['id']]);
}

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
        : "No se encontraron archivos para los " . count($products) . " productos procesados. Ver diagnósticos."
]);
