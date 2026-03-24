<?php
echo "Debug Album Sync Logic\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";

$files = [
    'src/controllers/admin_sync_covers.php',
    'src/controllers/search.php',
    'version.php'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        echo "$f: " . date('Y-m-d H:i:s', filemtime($f)) . "\n";
    } else {
        echo "$f: ❌ NO EXISTE\n";
    }
}

require 'config/database.php';
$db = getDB();
$with_album = $db->query("SELECT COUNT(*) FROM products WHERE album_id IS NOT NULL")->fetchColumn();
echo "Productos con Album ID: $with_album\n";
