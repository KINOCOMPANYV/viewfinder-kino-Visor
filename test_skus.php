<?php
require __DIR__ . '/config/app.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/src/helpers.php';
$db = getDB();
$stmt = $db->query('SELECT sku, name FROM products LIMIT 500');
while ($row = $stmt->fetch()) {
    $sku = $row['sku'];
    $root = extractRootSku($sku);
    echo "SKU: $sku -> ROOT: $root\n";
}
