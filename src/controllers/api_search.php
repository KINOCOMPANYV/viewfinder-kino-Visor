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
     WHERE archived = 0 AND " . skuSearchSql('sku') . " = ? 
     LIMIT 1"
);
$exactMatchNoDash = skuSearchNorm($qCleanForSku);
$stmt->execute([$exactMatchNoDash]);
$exact = $stmt->fetchAll();

// 2) LIKE parcial (SKU, nombre o carpeta) multipalabra
$words = array_filter(preg_split('/\s+/', trim($qCleanForSku)));
$whereParts = [];
$params = [];

if (empty($words)) {
    $whereParts[] = "1=1";
} else {
    foreach ($words as $word) {
        $wordEscaped = addcslashes($word, '%_');
        $wordLike = "%{$wordEscaped}%";
        $wordNoDash = skuSearchNorm($word);
        $wordNoDashEscaped = addcslashes($wordNoDash, '%_');
        $wordLikeNoDash = "%{$wordNoDashEscaped}%";

        $whereParts[] = "(" . skuSearchSql('p.sku') . " LIKE ? OR p.name LIKE ? OR p.category LIKE ?)";
        $params[] = $wordLikeNoDash;
        $params[] = $wordLike;
        $params[] = $wordLike;
    }
}
$whereSql = implode(' AND ', $whereParts);
$params[] = $qCleanForSku; // for p.sku != ?

$stmt = $db->prepare(
    "SELECT p.sku, p.name, p.category, p.cover_image_url, p.parent_sku 
     FROM products p
     WHERE p.archived = 0 
       AND ($whereSql)
       AND " . skuSearchSql('p.sku') . " != ?
     ORDER BY p.sku ASC 
     LIMIT 30"
);
// El último parametro es el qCleanForSku sin guiones para evitar duplicar el match exacto
$params[count($params)-1] = $exactMatchNoDash;

$stmt->execute($params);
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
