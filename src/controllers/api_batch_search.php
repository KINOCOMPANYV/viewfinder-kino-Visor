<?php
/**
 * API Búsqueda por Lote — devuelve JSON con datos de múltiples SKUs.
 * POST /api/batch-search
 * Body: {"codes": ["CODE1", "CODE2", ...]}
 * Response: {"results": {"CODE1": {...}, "CODE2": null, ...}}
 */

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$codes = $input['codes'] ?? [];

// Limpiar y limitar
$codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
$codes = array_slice($codes, 0, 100); // max 100 códigos

if (empty($codes)) {
    echo json_encode(['results' => new \stdClass()]);
    exit;
}

$db = getDB();

// Estrategia: para cada código, buscar exacto primero, luego LIKE
$results = [];

foreach ($codes as $code) {
    // 1) Búsqueda exacta por SKU
    $stmt = $db->prepare(
        "SELECT sku, name, cover_image_url FROM products 
         WHERE status = 'active' AND sku = ? 
         LIMIT 1"
    );
    $stmt->execute([$code]);
    $product = $stmt->fetch();

    // 2) Si no encontró exacto, intentar LIKE (el código contenido en el SKU o viceversa)
    if (!$product) {
        $stmt = $db->prepare(
            "SELECT sku, name, cover_image_url FROM products 
             WHERE status = 'active' AND (sku LIKE ? OR sku LIKE ? OR ? LIKE CONCAT('%', sku, '%'))
             ORDER BY LENGTH(sku) ASC
             LIMIT 1"
        );
        $like = "%{$code}%";
        $stmt->execute([$like, $like, $code]);
        $product = $stmt->fetch();
    }

    if ($product) {
        $coverUrl = $product['cover_image_url'] ?? '';
        if (str_starts_with($coverUrl, '[VIDEO]')) {
            $coverUrl = substr($coverUrl, 7);
        }
        $results[$code] = [
            'sku' => $product['sku'],
            'name' => $product['name'],
            'image' => $coverUrl ?: null,
        ];
    } else {
        $results[$code] = null;
    }
}

echo json_encode(['results' => $results]);

