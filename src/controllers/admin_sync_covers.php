<?php
/**
 * Admin Sync Covers — Auto-asignar portadas de Drive a productos.
 * POST /admin/media/sync-covers
 *
 * Estrategia de matching:
 *   1) Primero busca archivos con nombre EXACTO = SKU (ej: "834-4.jpg")
 *   2) Si no hay exacto, acepta prefijo SKU + separador (ej: "834-4 front.jpg", "834-4_portada.jpg")
 *      PERO rechaza hijos/variantes donde el SKU va seguido de letra+dígito (ej: "834-4F1.jpg")
 *   3) Prioriza imágenes sobre videos. Si solo hay video, usa thumbnail con icono ▶
 *   4) Limitado a 20 productos por ejecución
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

header('Content-Type: application/json');
set_time_limit(120);

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

// Cargar productos sin cover (máximo 20 por ejecución)
$products = $db->query("SELECT id, sku FROM products WHERE cover_image_url IS NULL OR cover_image_url = '' LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

$totalWithout = $db->query("SELECT COUNT(*) FROM products WHERE cover_image_url IS NULL OR cover_image_url = ''")->fetchColumn();

if (empty($products)) {
    echo json_encode(['ok' => true, 'assigned' => 0, 'message' => 'Todos los productos ya tienen portada.']);
    exit;
}

/**
 * Clasificar archivos en: exactos, compatibles (prefijo) y rechazados.
 * - Exacto: filename sin extensión = SKU (case insensitive)
 * - Compatible: filename empieza con SKU y el siguiente char NO es alfanumérico
 *   (acepta espacio, guión bajo, guión, paréntesis, etc.)
 * - Rechazado: filename empieza con SKU pero seguido de letra o dígito (variante/hijo)
 */
function classifyMatches(array $files, string $sku): array
{
    $exact = [];
    $compatible = [];

    foreach ($files as $f) {
        $nameOnly = pathinfo($f['name'] ?? '', PATHINFO_FILENAME);

        // ¿Match exacto?
        if (strcasecmp($nameOnly, $sku) === 0) {
            $exact[] = $f;
            continue;
        }

        // ¿Empieza con el SKU?
        if (stripos($nameOnly, $sku) === 0 && strlen($nameOnly) > strlen($sku)) {
            $nextChar = $nameOnly[strlen($sku)];
            // Aceptar si el siguiente char NO es letra ni dígito (es separador)
            // Esto permite: "834-4 front.jpg", "834-4_portada.jpg", "834-4 (2).jpg"
            // Rechaza: "834-4F1.jpg", "834-4V2.jpg", "834-410.jpg"
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

$updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
$assigned = 0;
$assignedVideos = 0;
$errors = [];
$diagnostics = [];

foreach ($products as $prod) {
    $diag = ['sku' => $prod['sku'], 'status' => 'no_files'];

    try {
        // Clean SKU: strip file extension for matching (1971-1.JPG → 1971-1)
        $cleanSku = preg_replace('/\.\w{2,4}$/i', '', $prod['sku']);
        // Buscar archivos por SKU limpio (global + recursivo en subcarpetas)
        $allFiles = $drive->findBySku($rootFolderId, $cleanSku);
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
            // Ordenar por keywords de portada
            $images = array_values($images);
            usort($images, function ($a, $b) use ($coverKeywords, $numericPriority) {
                return scoreCover($b, $coverKeywords, $numericPriority)
                    - scoreCover($a, $coverKeywords, $numericPriority);
            });

            $best = $images[0];
            $drive->makePublic($best['id']);
            $coverUrl = "https://lh3.googleusercontent.com/d/{$best['id']}";
            $updateStmt->execute([$coverUrl, $prod['id']]);
            $assigned++;
            $diag['status'] = 'image_assigned';
            $diag['assigned_file'] = $best['name'];
        } elseif (!empty($videos)) {
            $best = array_values($videos)[0];
            $drive->makePublic($best['id']);
            $coverUrl = "[VIDEO]https://drive.google.com/thumbnail?id={$best['id']}&sz=w400";
            $updateStmt->execute([$coverUrl, $prod['id']]);
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
