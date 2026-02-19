<?php
/**
 * Admin Sync Covers — Auto-asignar portadas de Drive a productos.
 * POST /admin/media/sync-covers
 * - Match EXACTO por SKU (nombre de archivo sin extensión = SKU)
 * - Si no hay imagen, usa thumbnail de video como fallback
 * - Limita a 20 productos por ejecución
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
 * Filtra archivos que coinciden EXACTAMENTE con el SKU.
 * El nombre del archivo sin extensión debe ser igual al SKU (case-insensitive).
 */
function exactSkuMatch(array $files, string $sku): array
{
    return array_values(array_filter($files, function ($f) use ($sku) {
        $nameOnly = pathinfo($f['name'] ?? '', PATHINFO_FILENAME);
        return strcasecmp($nameOnly, $sku) === 0;
    }));
}

$updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
$assigned = 0;
$assignedVideos = 0;
$errors = [];

foreach ($products as $prod) {
    try {
        // Buscar archivos por SKU (global + recursivo en subcarpetas)
        $allFiles = $drive->findBySku($rootFolderId, $prod['sku']);

        // Filtrar SOLO archivos con nombre EXACTO del SKU
        $exactFiles = exactSkuMatch($allFiles, $prod['sku']);

        // Separar imágenes y videos
        $images = array_filter($exactFiles, fn($f) => str_starts_with($f['mimeType'] ?? '', 'image/'));
        $videos = array_filter($exactFiles, fn($f) => str_starts_with($f['mimeType'] ?? '', 'video/'));

        if (!empty($images)) {
            // Prioridad: usar imagen
            $bestImage = array_values($images)[0];
            $drive->makePublic($bestImage['id']);
            $coverUrl = "https://lh3.googleusercontent.com/d/{$bestImage['id']}";
            $updateStmt->execute([$coverUrl, $prod['id']]);
            $assigned++;
        } elseif (!empty($videos)) {
            // Fallback: usar thumbnail del video
            $bestVideo = array_values($videos)[0];
            $drive->makePublic($bestVideo['id']);
            // Marcar como video con prefijo [VIDEO] para que el frontend muestre icono
            $coverUrl = "[VIDEO]https://drive.google.com/thumbnail?id={$bestVideo['id']}&sz=w400";
            $updateStmt->execute([$coverUrl, $prod['id']]);
            $assignedVideos++;
            $assigned++;
        }
    } catch (Exception $e) {
        $errors[] = "SKU {$prod['sku']}: {$e->getMessage()}";
    }
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
    'message' => $assigned > 0
        ? "⭐ {$assigned} producto(s) recibieron portada (" . ($assigned - $assignedVideos) . " imagen(es), {$assignedVideos} video(s))." . ($remaining > 0 ? " Quedan {$remaining} sin portada." : '')
        : "No se encontraron archivos con nombre exacto de SKU para los " . count($products) . " productos procesados."
]);

