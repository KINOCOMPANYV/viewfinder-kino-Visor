<?php
/**
 * Admin Sync Covers — Auto-asignar portadas de Drive a productos.
 * POST /admin/media/sync-covers
 * Usa findBySku() por producto para búsqueda precisa con soporte de subcarpetas.
 * Limita a 20 productos por ejecución para evitar timeout.
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

// Palabras clave para priorizar como portada
$coverKeywords = ['principal', 'cover', 'portada', 'front', 'frente'];
$numericPriority = ['01', '_1', '-1', 'f1'];
$updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
$assigned = 0;
$errors = [];

foreach ($products as $prod) {
    try {
        // Buscar archivos por SKU (global + recursivo en subcarpetas)
        $allFiles = $drive->findBySku($rootFolderId, $prod['sku']);

        // Filtrar solo imágenes
        $matched = array_filter($allFiles, function ($f) {
            return str_starts_with($f['mimeType'] ?? '', 'image/');
        });

        if (empty($matched))
            continue;

        $matched = array_values($matched);

        // Ordenar por prioridad (keywords de portada primero)
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

        // Hacer público el archivo para que la URL lh3 funcione
        $drive->makePublic($bestImage['id']);

        $coverUrl = "https://lh3.googleusercontent.com/d/{$bestImage['id']}";
        $updateStmt->execute([$coverUrl, $prod['id']]);
        $assigned++;
    } catch (Exception $e) {
        $errors[] = "SKU {$prod['sku']}: {$e->getMessage()}";
    }
}

$remaining = $totalWithout - $assigned;

echo json_encode([
    'ok' => true,
    'assigned' => $assigned,
    'total' => count($products),
    'remaining' => $remaining,
    'errors' => $errors,
    'message' => $assigned > 0
        ? "⭐ {$assigned} producto(s) recibieron imagen principal." . ($remaining > 0 ? " Quedan {$remaining} sin portada." : '')
        : "No se encontraron imágenes para los " . count($products) . " productos procesados."
]);

