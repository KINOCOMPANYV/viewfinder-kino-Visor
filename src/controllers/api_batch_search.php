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

// Buscar todos los productos que coincidan
$placeholders = implode(',', array_fill(0, count($codes), '?'));
$stmt = $db->prepare(
    "SELECT sku, name, cover_image_url 
     FROM products 
     WHERE status = 'active' AND sku IN ($placeholders)"
);
$stmt->execute($codes);
$products = $stmt->fetchAll();

// Indexar por SKU
$indexed = [];
foreach ($products as $p) {
    $indexed[strtoupper($p['sku'])] = $p;
}

// Construir resultados manteniendo el orden original
$results = [];
foreach ($codes as $code) {
    $key = strtoupper($code);
    if (isset($indexed[$key])) {
        $p = $indexed[$key];
        $coverUrl = $p['cover_image_url'] ?? '';
        // Limpiar prefix [VIDEO] si existe
        if (str_starts_with($coverUrl, '[VIDEO]')) {
            $coverUrl = substr($coverUrl, 7);
        }
        $results[$code] = [
            'sku' => $p['sku'],
            'name' => $p['name'],
            'image' => $coverUrl ?: null,
        ];
    } else {
        $results[$code] = null;
    }
}

echo json_encode(['results' => $results]);
