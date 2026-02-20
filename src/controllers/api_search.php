<?php
/**
 * API de búsqueda — devuelve JSON para autocomplete.
 */
$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    jsonResponse([]);
}

$db = getDB();

// Buscar por SKU exacto primero, luego LIKE, luego FULLTEXT
$results = [];

// 1) Match exacto de SKU
$stmt = $db->prepare(
    "SELECT sku, name, category, cover_image_url FROM products 
     WHERE archived = 0 AND sku = ? 
     LIMIT 1"
);
$stmt->execute([$q]);
$exact = $stmt->fetchAll();

// 2) LIKE parcial (SKU o nombre)
$stmt = $db->prepare(
    "SELECT sku, name, category, cover_image_url FROM products 
     WHERE archived = 0 
       AND (sku LIKE ? OR name LIKE ?)
       AND sku != ?
     ORDER BY sku ASC 
     LIMIT 10"
);
$like = "%{$q}%";
$stmt->execute([$like, $like, $q]);
$partial = $stmt->fetchAll();

$results = array_merge($exact, $partial);

// Eliminar duplicados por SKU
$seen = [];
$unique = [];
foreach ($results as $r) {
    if (!isset($seen[$r['sku']])) {
        $seen[$r['sku']] = true;
        $unique[] = $r;
    }
}

jsonResponse(array_slice($unique, 0, 10));
