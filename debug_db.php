<?php
require 'config/app.php';
require 'config/database.php';
$db = getDB();

$cats = $db->query("SELECT category, count(*) as count FROM products GROUP BY category ORDER BY count DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$names = $db->query("SELECT name FROM products LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
$skus = $db->query("SELECT sku FROM products LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);

echo "Top Categorías:\n";
print_r($cats);

echo "\nMuestra Nombres:\n";
print_r($names);

echo "\nMuestra SKUS:\n";
print_r($skus);
