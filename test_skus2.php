<?php
require 'src/config/app.php';
require 'src/config/database.php';
$db = getDB();
$stmt = $db->query("SELECT sku, cover_image_url FROM products WHERE sku LIKE 'CHA-104%' OR sku LIKE 'CHA-111%' ORDER BY sku ASC");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
