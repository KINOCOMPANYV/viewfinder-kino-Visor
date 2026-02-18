<?php
/**
 * Admin Sync Covers — Auto-asignar portadas de Drive a productos.
 * POST /admin/media/sync-covers
 * RÁPIDO: Una sola búsqueda masiva de imágenes, match local por SKU.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

header('Content-Type: application/json');
set_time_limit(60);

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

// 1) UNA SOLA búsqueda: traer TODAS las imágenes de Drive (paginada)
$allImages = [];
$nextPage = '';
$maxPages = 5;
$pageCount = 0;
$apiError = '';

do {
    $params = [
        'q' => "mimeType contains 'image/' and trashed = false",
        'fields' => 'nextPageToken,files(id,name,mimeType)',
        'pageSize' => 1000,
        'corpora' => 'user',
    ];
    if ($nextPage)
        $params['pageToken'] = $nextPage;

    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $apiError = "Drive API respondió HTTP {$httpCode}";
        break;
    }

    $data = json_decode($resp, true) ?: [];
    $allImages = array_merge($allImages, $data['files'] ?? []);
    $nextPage = $data['nextPageToken'] ?? '';
    $pageCount++;
} while (!empty($nextPage) && $pageCount < $maxPages);

if (!empty($apiError)) {
    echo json_encode(['ok' => false, 'error' => $apiError]);
    exit;
}

if (empty($allImages)) {
    echo json_encode(['ok' => false, 'error' => 'Drive devolvió 0 imágenes. Verificar permisos de carpeta.']);
    exit;
}

// 2) Match local rápido
$coverKeywords = ['principal', 'cover', 'portada', 'front', 'frente'];
$numericPriority = ['01', '_1', '-1', 'f1'];
$updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
$assigned = 0;

foreach ($products as $prod) {
    $matched = array_filter($allImages, function ($f) use ($prod) {
        return skuMatchesFilename($prod['sku'], $f['name'] ?? '');
    });
    if (empty($matched))
        continue;

    $matched = array_values($matched);
    usort($matched, function ($a, $b) use ($coverKeywords, $numericPriority) {
        $nameA = strtolower($a['name'] ?? '');
        $nameB = strtolower($b['name'] ?? '');
        $scoreA = $scoreB = 0;
        foreach ($coverKeywords as $kw) {
            if (str_contains($nameA, $kw))
                $scoreA += 10;
            if (str_contains($nameB, $kw))
                $scoreB += 10;
        }
        foreach ($numericPriority as $np) {
            if (str_contains($nameA, $np))
                $scoreA += 5;
            if (str_contains($nameB, $np))
                $scoreB += 5;
        }
        return $scoreB - $scoreA;
    });

    $bestImage = $matched[0];
    $coverUrl = "https://lh3.googleusercontent.com/d/{$bestImage['id']}";
    $updateStmt->execute([$coverUrl, $prod['id']]);
    $assigned++;
}

echo json_encode([
    'ok' => true,
    'assigned' => $assigned,
    'total' => count($products),
    'images_found' => count($allImages),
    'message' => $assigned > 0
        ? "⭐ {$assigned} producto(s) recibieron imagen principal."
        : "No se encontraron imágenes que coincidan con los SKU de los " . count($products) . " productos sin portada. (Se encontraron " . count($allImages) . " imágenes en Drive)"
]);
