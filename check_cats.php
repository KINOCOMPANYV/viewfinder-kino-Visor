<?php
require 'config/app.php';
require 'config/database.php';
$db = getDB();

$albums = $db->query("SELECT name FROM albums")->fetchAll(PDO::FETCH_COLUMN);
$cats = $db->query("SELECT DISTINCT category, COUNT(*) as c FROM products GROUP BY category ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);

echo "--- ÁLBUMES (" . count($albums) . ") ---\n";
foreach($albums as $a) echo "[$a]\n";

echo "\n--- CATEGORÍAS EN PRODUCTOS ---\n";
foreach($cats as $c) {
    $cat = $c['category'];
    $match = in_array($cat, $albums) ? "OK" : "NO MATCH";
    
    // Check case insensitive
    $ciMatch = false;
    foreach($albums as $a) {
        if (strcasecmp(trim($a), trim($cat)) === 0) {
            $ciMatch = true;
            break;
        }
    }
    if ($match === "NO MATCH" && $ciMatch) $match = "MATCH IF TRIMMED/NOCASE";
    
    echo "[$cat] ({$c['c']} prods) -> $match\n";
}
