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

    // Filtrar solo imágenes
    $images = array_filter($skuFiles, fn($f) => str_starts_with($f['mimeType'] ?? '', 'image/'));

    if (empty($images))
        continue;

    // Priorizar por keywords en el nombre del archivo
    // "principal", "cover", "portada", "01", "_1" tienen mayor prioridad
    $coverKeywords = ['principal', 'cover', 'portada', 'front', 'frente'];
    $numericPriority = ['01', '_1', '-1', 'f1'];

    usort($images, function ($a, $b) use ($coverKeywords, $numericPriority) {
        $nameA = strtolower($a['name'] ?? '');
        $nameB = strtolower($b['name'] ?? '');

        $scoreA = 0;
        $scoreB = 0;

        // Keywords de portada = máxima prioridad
        foreach ($coverKeywords as $kw) {
            if (str_contains($nameA, $kw))
                $scoreA += 10;
            if (str_contains($nameB, $kw))
                $scoreB += 10;
        }

        // Indicadores numéricos = prioridad media
        foreach ($numericPriority as $np) {
            if (str_contains($nameA, $np))
                $scoreA += 5;
            if (str_contains($nameB, $np))
                $scoreB += 5;
        }

        return $scoreB - $scoreA; // Mayor score primero
    });

    $bestImage = reset($images);
    $coverUrl = "https://lh3.googleusercontent.com/d/{$bestImage['id']}";
    $updateStmt->execute([$coverUrl, $prod['id']]);
    $assigned++;
}


echo json_encode([
    'ok' => true,
    'assigned' => $assigned,
    'total' => count($products),
    'message' => $assigned > 0
        ? "⭐ {$assigned} producto(s) recibieron imagen principal."
        : "No se encontraron imágenes nuevas para asignar."
]);
