<?php
/**
 * Admin — Eliminar producto.
 */
if (!verifyCsrf()) {
    jsonResponse(['error' => 'Token CSRF inválido'], 403);
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash_error'] = 'ID de producto inválido.';
    redirect('/admin/products');
}

$db = getDB();
$stmt = $db->prepare("DELETE FROM products WHERE id = ?");
$stmt->execute([$id]);

$_SESSION['flash_success'] = 'Producto eliminado correctamente.';
redirect('/admin/products');
