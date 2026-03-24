<?php
require __DIR__ . '/config/app.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/src/helpers.php';
try {
    $db = getDB();
    $stmt = $db->query("SELECT sku FROM products ORDER BY sku ASC");
    $skus = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $grouped = [];
    foreach ($skus as $sku) {
        $clean = preg_replace('/\.\w{2,4}$/i', '', trim($sku));
        $root = extractRootSku($clean);
        if (!isset($grouped[$root])) {
            $grouped[$root] = [];
        }
        $grouped[$root][] = $sku;
    }

    $analysis = [
        'total' => count($skus),
        'families' => count($grouped),
        'grouped_families' => [],
        'singletons' => []
    ];

    foreach ($grouped as $root => $members) {
        if (count($members) > 1) {
            $analysis['grouped_families'][$root] = $members;
        } else {
            $analysis['singletons'][] = $members[0];
        }
    }

    file_put_contents(__DIR__ . '/sku_analysis.json', json_encode($analysis, JSON_PRETTY_PRINT));
    echo "Done. Groups: " . count($analysis['grouped_families']) . " Singletons: " . count($analysis['singletons']) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
