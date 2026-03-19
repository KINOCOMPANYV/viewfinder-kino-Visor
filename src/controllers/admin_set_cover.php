<?php
/**
 * Admin — Establecer imagen principal (cover) de un producto.
 * POST: product_id, image_url
 */
header('Content-Type: application/json');

$db = getDB();

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$productId = intval($_POST['product_id'] ?? 0);
$imageUrl = trim($_POST['image_url'] ?? '');

if (!$productId || !$imageUrl) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan datos.']);
    exit;
}

// Verificar que el producto existe
$stmt = $db->prepare("SELECT id FROM products WHERE id = ?");
$stmt->execute([$productId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Producto no encontrado.']);
    exit;
}

// Actualizar cover_image_url + timestamp
$update = $db->prepare("UPDATE products SET cover_image_url = ?, updated_at = NOW() WHERE id = ?");
$update->execute([$imageUrl, $productId]);

echo json_encode(['ok' => true, 'cover_image_url' => $imageUrl]);
