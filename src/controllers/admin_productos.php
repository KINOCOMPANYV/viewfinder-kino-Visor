<?php
/**
 * Admin — Productos (estilo KYTE), v2 módulo 2. GET /admin/productos
 * Pantalla nueva: foto, nombre, código, categoría, stock, precio y el interruptor «Catálogo» (= is_active; el público además
 * exige archived = 0 y portada). Totales sobre TODO el filtro. Reutiliza adminProductFilter(), /admin/products/active y el Excel de v1.
 */
$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'estado' => in_array($_GET['estado'] ?? '', ['activos', 'inactivos', 'sinfoto'], true) ? $_GET['estado'] : '',
    'categoria' => (string) ($_GET['categoria'] ?? ''),
    'marca' => (string) ($_GET['marca'] ?? ''),
    'stock' => in_array($_GET['stock'] ?? '', ['con', 'sin'], true) ? $_GET['stock'] : '',
];
$orders = ['codigo' => 'LENGTH(sku), sku', 'nombre' => 'name', 'precio' => 'price_suggested DESC', 'stock' => 'stock_n DESC'];
$orden = isset($orders[$_GET['orden'] ?? '']) ? $_GET['orden'] : 'codigo';
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$db = getDB();

[$where, $params] = adminProductFilter($filters);
$stock = stockSql();
$t = $db->prepare(
    "SELECT COUNT(*) AS n,
            SUM({$stock} > 0) AS con, SUM({$stock} <= 0) AS sin,
            COALESCE(SUM(CASE WHEN {$stock} > 0 THEN {$stock} * COALESCE(price_suggested, 0) END), 0) AS valor
     FROM products WHERE {$where}"
);
$t->execute($params);
$totals = $t->fetch();
$total = (int) $totals['n'];
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);

$stmt = $db->prepare(
    "SELECT id, sku, name, brand, category, price_suggested, {$stock} AS stock_n, cover_image_url, is_active, in_sheet, from_drive
     FROM products WHERE {$where} ORDER BY {$orders[$orden]}, sku LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$products = $stmt->fetchAll();
$categories = $db->query("SELECT category, COUNT(*) AS n FROM products WHERE category <> '' GROUP BY category ORDER BY category")->fetchAll();
$brands = $db->query("SELECT brand, COUNT(*) AS n FROM products WHERE brand <> '' GROUP BY brand ORDER BY brand")->fetchAll();

$promoCats = promoCategories($db);
// Categorías con productos que hoy se ven en /tienda (activo + foto + stock > 0 + marcas de la tienda): n por categoría
[$tr, $tp] = tiendaRulesSql();
$ts = $db->prepare("SELECT p.category, COUNT(*) AS n FROM products p WHERE TRIM(p.category) <> ''" . $tr . ' GROUP BY p.category');
$ts->execute($tp);
$storeCats = array_column($ts->fetchAll(), 'n', 'category');
include __DIR__ . '/../../templates/admin/productos.php';
