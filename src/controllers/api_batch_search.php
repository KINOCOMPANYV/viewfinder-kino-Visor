<?php
/**
 * API Búsqueda por Lote — devuelve JSON con datos de múltiples SKUs.
 * POST /api/batch-search
 * Body: {"codes": ["CODE1", "CODE2", ...]}
 * Response: {"results": {"CODE1": {...}, "CODE2": null, ...}}
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

$input = json_decode(file_get_contents('php://input'), true);
$codes = $input['codes'] ?? [];

// Limpiar y limitar
$codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
$codes = array_slice($codes, 0, 100); // max 100 códigos

if (empty($codes)) {
    jsonCachedResponse(['results' => new \stdClass()], 60);
}

$db = getDB();

// Estrategia batch: 1 query exacta + 1 query LIKE (en vez de 2N queries)
$results = [];

// 1) Búsqueda exacta batch — todos los SKUs en una sola query IN()
$matchedCodes = [];
if (!empty($codes)) {
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $db->prepare(
        "SELECT sku, name, cover_image_url FROM products 
         WHERE archived = 0 AND sku IN ({$placeholders})"
    );
    $stmt->execute($codes);
    $exactRows = $stmt->fetchAll();

    foreach ($exactRows as $row) {
        $coverUrl = $row['cover_image_url'] ?? '';
        if (str_starts_with($coverUrl, '[VIDEO]')) {
            $coverUrl = substr($coverUrl, 7);
        }
        // Mapear al código original (case-insensitive match)
        foreach ($codes as $code) {
            if (strcasecmp($code, $row['sku']) === 0 && !isset($results[$code])) {
                $results[$code] = [
                    'sku' => $row['sku'],
                    'name' => $row['name'],
                    'image' => $coverUrl ?: null,
                ];
                $matchedCodes[] = $code;
                break;
            }
        }
    }
}

// 2) Fallback LIKE — solo para códigos que no tuvieron match exacto
$unmatchedCodes = array_diff($codes, $matchedCodes);
foreach ($unmatchedCodes as $code) {
    $like = "%" . addcslashes($code, '%_') . "%";
    $stmt = $db->prepare(
        "SELECT sku, name, cover_image_url FROM products 
         WHERE archived = 0 AND (sku LIKE ? OR ? LIKE CONCAT('%', sku, '%'))
         ORDER BY LENGTH(sku) ASC
         LIMIT 1"
    );
    $stmt->execute([$like, $code]);
    $product = $stmt->fetch();

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

// Asegurar que todos los códigos tengan entrada (incluso null)
foreach ($codes as $code) {
    if (!isset($results[$code])) {
        $results[$code] = null;
    }
}

jsonCachedResponse(['results' => $results], 60);

