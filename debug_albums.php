<?php
require 'config/app.php';
require 'config/database.php';
$db = getDB();

$total_prods = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$with_album = $db->query("SELECT COUNT(*) FROM products WHERE album_id IS NOT NULL")->fetchColumn();
$with_cover = $db->query("SELECT COUNT(*) FROM products WHERE cover_image_url IS NOT NULL AND cover_image_url != ''")->fetchColumn();

echo "Total Productos: $total_prods\n";
echo "Con Album ID: $with_album\n";
echo "Con Portada: $with_cover\n";

echo "\n--- Muestra SIN Album ---\n";
$missing = $db->query("SELECT id, sku, cover_image_url, album_id FROM products WHERE album_id IS NULL AND cover_image_url IS NOT NULL LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($missing);

echo "\n--- Muestra CON Album ---\n";
$has = $db->query("SELECT id, sku, cover_image_url, album_id FROM products WHERE album_id IS NOT NULL LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($has);
