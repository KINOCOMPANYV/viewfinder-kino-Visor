<?php
/**
 * Admin — Productos: listado con filtros y activar/desactivar (uno, varios o todos los del filtro).
 * Los datos vienen de la hoja maestra (solo lectura aquí); el admin solo decide qué se ve.
 */
$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'estado' => $_GET['estado'] ?? '',
    'marca' => $_GET['marca'] ?? '',
    'categoria' => $_GET['categoria'] ?? '',
];
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$db = getDB();

[$where, $params] = adminProductFilter($filters);
$countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE {$where}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);

$stmt = $db->prepare(
    "SELECT id, sku, name, brand, color, category, price_suggested, stock, cover_image_url,
            is_active, in_sheet, from_drive, sheet_status
     FROM products WHERE {$where}
     ORDER BY in_sheet DESC, sheet_row ASC, sku ASC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$products = $stmt->fetchAll();

$summary = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(is_active = 1 AND (in_sheet = 1 OR from_drive = 1)) AS visibles,
            SUM(is_active = 0) AS inactivos,
            SUM(in_sheet = 0) AS fuera
     FROM products"
)->fetch();
$brands = $db->query("SELECT brand, COUNT(*) AS n FROM products WHERE brand <> '' GROUP BY brand ORDER BY brand")->fetchAll();
$categories = $db->query("SELECT category, COUNT(*) AS n FROM products WHERE category <> '' GROUP BY category ORDER BY category")->fetchAll();

include __DIR__ . '/../../templates/admin/products.php';
