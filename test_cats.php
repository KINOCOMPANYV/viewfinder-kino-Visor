<?php
require 'config/app.php';
require 'config/database.php';
$db = getDB();
$albums = $db->query('SELECT name FROM albums LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
$cats = $db->query('SELECT DISTINCT category FROM products LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
echo "ALBUMS:\n"; print_r($albums);
echo "CATS:\n"; print_r($cats);
