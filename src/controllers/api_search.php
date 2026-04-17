<?php
/**
 * API de búsqueda — devuelve JSON para autocomplete.
 */
$q = trim($_GET['q'] ?? '');

$qCleanForSku = preg_replace('/\.\w{2,4}$/i', '', $q);

if (strlen($q) < 2) {
    jsonCachedResponse([], 30);
}

$db = getDB();

// Buscar por SKU exacto primero, luego LIKE, luego FULLTEXT
$results = [];

// 1) Match exacto de SKU
$stmt = $db->prepare(
    "SELECT sku, name, category, cover_image_url, parent_sku FROM products 
     WHERE archived = 0 AND sku = ? 
     LIMIT 1"
);
$stmt->execute([$qCleanForSku]);
$exact = $stmt->fetchAll();

// 2) LIKE parcial (SKU, nombre o carpeta)
$stmt = $db->prepare(
    "SELECT p.sku, p.name, p.category, p.cover_image_url, p.parent_sku 
     FROM products p
     LEFT JOIN albums a ON p.album_id = a.drive_id
     WHERE p.archived = 0 
       AND (p.sku LIKE ? OR p.name LIKE ? OR a.name LIKE ?)
       AND p.sku != ?
     ORDER BY p.sku ASC 
     LIMIT 30"
);
$escaped = addcslashes($q, '%_');
$like = "%{$escaped}%";
$escapedClean = addcslashes($qCleanForSku, '%_');
$likeClean = "%{$escapedClean}%";
$stmt->execute([$likeClean, $like, $like, $qCleanForSku]);
$partial = $stmt->fetchAll();

$results = array_merge($exact, $partial);

// Eliminar duplicados por Familia (parent_sku)
$seen = [];
$unique = [];
foreach ($results as $r) {
    $family = !empty($r['parent_sku']) ? $r['parent_sku'] : extractRootSku($r['sku']);
    
    if (!isset($seen[$family])) {
        $seen[$family] = true;
        $unique[] = $r;
    }
}

jsonCachedResponse(array_slice($unique, 0, 10), 30);
