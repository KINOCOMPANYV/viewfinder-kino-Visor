<?php
/**
 * Admin Sync Covers — Auto-asignar portadas de Drive a productos.
 * POST /admin/media/sync-covers
 * Busca en Drive (incluyendo subcarpetas) la primera imagen que coincida con cada SKU.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

header('Content-Type: application/json');

$db = getDB();
$drive = new GoogleDriveService();
$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

$token = $drive->getValidToken($db);
if (empty($token)) {
    echo json_encode(['ok' => false, 'error' => 'No hay conexión a Google Drive.']);
    exit;
}

// Cargar productos sin cover
$products = $db->query("SELECT id, sku FROM products WHERE cover_image_url IS NULL OR cover_image_url = ''")->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    echo json_encode(['ok' => true, 'assigned' => 0, 'message' => 'Todos los productos ya tienen portada.']);
    exit;
}

$updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
$assigned = 0;

foreach ($products as $prod) {
    $skuFiles = $drive->findBySku($rootFolderId, $prod['sku']);
    foreach ($skuFiles as $file) {
        $isImage = str_starts_with($file['mimeType'] ?? '', 'image/');
        if ($isImage) {
            $coverUrl = "https://lh3.googleusercontent.com/d/{$file['id']}";
            $updateStmt->execute([$coverUrl, $prod['id']]);
            $assigned++;
            break;
        }
    }
}

echo json_encode([
    'ok' => true,
    'assigned' => $assigned,
    'total' => count($products),
    'message' => $assigned > 0
        ? "⭐ {$assigned} producto(s) recibieron imagen principal."
        : "No se encontraron imágenes nuevas para asignar."
]);
