<?php
/**
 * Admin — Cambiar el precio de un producto (POST /admin/products/price, header X-CSRF-TOKEN).
 *   id=N & price=12345  → precio editado en el admin (price_manual = 1; el sync de la Hoja master ya no lo pisa)
 *   id=N & reset=1      → vuelve al precio de la Hoja master (price_sheet = PRECIO KYTE, o PRICE_SUGGESTED si no existe esa columna)
 * Solo cambia el visor: la Hoja master no se toca.
 */
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    jsonResponse(['error' => 'Token CSRF inválido.'], 403);
}
$id = (int) ($_POST['id'] ?? 0);
$db = getDB();
$st = $db->prepare("SELECT id, sku, price_sheet, in_sheet FROM products WHERE id = ?");
$st->execute([$id]);
$p = $st->fetch();
if (!$p) jsonResponse(['error' => 'Producto no encontrado.'], 404);

if (($_POST['reset'] ?? '') === '1') {
    $price = (float) ($p['price_sheet'] ?? 0);
    $db->prepare("UPDATE products SET price_suggested = ?, price_manual = 0, updated_at = NOW() WHERE id = ?")->execute([$price, $id]);
    jsonResponse(['ok' => true, 'price' => $price, 'manual' => false, 'text' => priceText($price),
        'message' => '↩️ ' . $p['sku'] . ': precio de la Hoja master (' . priceText($price) . ')']);
}

// Acepta "45.000", "45000", "$ 45.000" (pesos, sin decimales)
$raw = preg_replace('/[^\d]/', '', preg_replace('/,\d{1,2}\s*$/', '', trim((string) ($_POST['price'] ?? ''))));
if ($raw === '' || strlen($raw) > 9) jsonResponse(['error' => 'Escribe el precio en números, p. ej. 45000.'], 400);
$price = (float) $raw;
$db->prepare("UPDATE products SET price_suggested = ?, price_manual = 1, updated_at = NOW() WHERE id = ?")->execute([$price, $id]);
jsonResponse(['ok' => true, 'price' => $price, 'manual' => true, 'text' => priceText($price),
    'message' => '💲 ' . $p['sku'] . ': ' . priceText($price) . ' (editado en el admin)']);
