<?php
/**
 * Admin — Listado de productos con búsqueda y paginación.
 */
$q = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$db = getDB();

if ($q !== '') {
    $like = "%{$q}%";
    $countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE sku LIKE ? OR name LIKE ?");
    $countStmt->execute([$like, $like]);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT id, sku, name, category, gender, status, price_suggested, updated_at 
         FROM products WHERE sku LIKE ? OR name LIKE ?
         ORDER BY sku ASC LIMIT ? OFFSET ?"
    );
    $stmt->execute([$like, $like, $perPage, $offset]);
} else {
    $total = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $stmt = $db->prepare(
        "SELECT id, sku, name, category, gender, status, price_suggested, updated_at 
         FROM products ORDER BY updated_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute([$perPage, $offset]);
}

$products = $stmt->fetchAll();
$totalPages = ceil($total / $perPage);

include __DIR__ . '/../../templates/admin/products.php';
