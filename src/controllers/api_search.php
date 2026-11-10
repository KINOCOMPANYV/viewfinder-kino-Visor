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

// 1) Match exacto de SKU ("8363-3" = "M:8363-3")
$exactForms = skuExactForms($qCleanForSku) ?: [''];
$exactPh = implode(',', array_fill(0, count($exactForms), '?'));
$stmt = $db->prepare(
    "SELECT sku, name, category, cover_image_url, parent_sku FROM products 
     WHERE archived = 0 AND " . skuSearchSql('sku') . " IN ($exactPh)
     LIMIT 3"
);
$stmt->execute($exactForms);
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
        $skuOr = [];
        foreach (skuExactForms($word) as $form) {
            $skuOr[] = skuSearchSql('p.sku') . " LIKE ?";
            $params[] = '%' . addcslashes($form, '%_') . '%';
        }

        $whereParts[] = "(" . implode(' OR ', $skuOr ?: ['0']) . " OR p.name LIKE ? OR p.category LIKE ?)";
        $params[] = $wordLike;
        $params[] = $wordLike;
    }
}
$whereSql = implode(' AND ', $whereParts);

$stmt = $db->prepare(
    "SELECT p.sku, p.name, p.category, p.cover_image_url, p.parent_sku 
     FROM products p
     WHERE p.archived = 0 
       AND ($whereSql)
       AND " . skuSearchSql('p.sku') . " NOT IN ($exactPh)
     ORDER BY CASE WHEN " . implode(' OR ', array_fill(0, count($exactForms), skuSearchSql('p.sku') . ' LIKE ?')) . " THEN 0 ELSE 1 END,
        p.sku ASC
     LIMIT 30"
);
$params = array_merge($params, $exactForms, array_map(fn($f) => addcslashes($f, '%_') . '%', $exactForms));

$stmt->execute($params);
$partial = $stmt->fetchAll();

$results = array_merge($exact, $partial);

// Una fila por referencia (código); las variantes salen cada una con su código
$seen = [];
$unique = [];
foreach ($results as $r) {
    if (!isset($seen[$r['sku']])) {
        $seen[$r['sku']] = true;
        $unique[] = $r;
    }
}

jsonCachedResponse(array_slice($unique, 0, 10), 30);
