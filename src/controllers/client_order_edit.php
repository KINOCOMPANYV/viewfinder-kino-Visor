<?php
/**
 * POST /hacer-pedido/mio (JSON): el cliente ve o edita SU pedido mientras está «Recibido» (regla 2). Sin cuenta: id + edit_token
 * (llave secreta guardada en su dispositivo al enviar). {csrf, id, token} → datos; con {items, note} → reemplaza las líneas.
 * Precios y nombres salen de la BD. Pasado «Recibido» ya no se puede editar.
 */
require_once __DIR__ . '/../services/Orders.php';
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) ($in['csrf'] ?? ''))) {
    jsonResponse(['error' => 'Sesión vencida. Recarga la página e intenta de nuevo.'], 403);
}
$id = (int) ($in['id'] ?? 0);
$token = (string) ($in['token'] ?? '');
$db = getDB();
$q = $db->prepare("SELECT c.*, o.id AS core_id, o.number, o.stage FROM client_orders c JOIN orders o ON o.client_order_id = c.id WHERE c.id = ?");
$q->execute([$id]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row || $row['edit_token'] === null || !preg_match('/^[a-f0-9]{32}$/', $token) || !hash_equals((string) $row['edit_token'], $token)) {
    jsonResponse(['error' => 'No encontramos ese pedido.'], 404);
}
$editable = $row['stage'] === 'recibido';
if (!array_key_exists('items', $in)) {
    $it = $db->prepare('SELECT sku, qty FROM client_order_items WHERE order_id = ? ORDER BY id');
    $it->execute([$id]);
    jsonResponse(['ok' => true, 'number' => $row['number'], 'stage' => $row['stage'], 'stageLabel' => Orders::label($row['stage']), 'editable' => $editable,
        'name' => $row['customer_name'], 'phone' => $row['customer_phone'], 'note' => $row['note'], 'items' => $it->fetchAll(PDO::FETCH_ASSOC)]);
}
if (!$editable) {
    jsonResponse(['error' => 'Tu pedido ya está «' . Orders::label($row['stage']) . '» y no se puede cambiar. Escríbenos por WhatsApp.'], 409);
}
$want = [];
foreach ((array) $in['items'] as $x) {
    $sku = trim((string) ($x['sku'] ?? ''));
    $qty = max(1, min(999, (int) ($x['qty'] ?? 1)));
    if ($sku !== '' && mb_strlen($sku) <= 100) $want[$sku] = ($want[$sku] ?? 0) + $qty;
}
if (!$want || count($want) > 100) jsonResponse(['error' => $want ? 'Máximo 100 referencias por pedido.' : 'Agrega al menos un producto.'], 422);
$ph = implode(',', array_fill(0, count($want), '?'));
$st = $db->prepare("SELECT sku, name, price_suggested FROM products WHERE archived = 0 AND cover_image_url > '' AND sku IN ($ph)");
$st->execute(array_keys($want));
$found = [];
foreach ($st->fetchAll() as $r) $found[$r['sku']] = $r;
$missing = array_values(array_diff(array_keys($want), array_keys($found)));
if ($missing) jsonResponse(['error' => 'Estos códigos ya no están en el catálogo: ' . implode(', ', array_slice($missing, 0, 5)) . '.'], 422);
$total = 0;
foreach ($want as $sku => $qty) $total += $qty * (float) $found[$sku]['price_suggested'];
$note = mb_substr(trim((string) ($in['note'] ?? $row['note'])), 0, 500, 'UTF-8');
try {
    $db->beginTransaction();
    $db->prepare('DELETE FROM client_order_items WHERE order_id = ?')->execute([$id]);
    $ins = $db->prepare('INSERT INTO client_order_items (order_id, sku, name, qty, unit_price) VALUES (?, ?, ?, ?, ?)');
    foreach ($want as $sku => $qty) $ins->execute([$id, $sku, mb_substr((string) $found[$sku]['name'], 0, 255, 'UTF-8'), $qty, (float) $found[$sku]['price_suggested']]);
    $db->prepare('UPDATE client_orders SET total = ?, note = ? WHERE id = ?')->execute([$total, $note, $id]);
    // el pedido núcleo solo se cambia si sigue «recibido» (evita pisar a quien ya lo movió)
    $u = $db->prepare("UPDATE orders SET total = ?, items_count = ?, note = ? WHERE id = ? AND stage = 'recibido'");
    $u->execute([$total, count($want), $note, $row['core_id']]);
    if ($u->rowCount() === 0 && $db->query('SELECT stage FROM orders WHERE id = ' . (int) $row['core_id'])->fetchColumn() !== 'recibido') {
        $db->rollBack();
        jsonResponse(['error' => 'Tu pedido acaba de pasar a otra etapa y ya no se puede cambiar.'], 409);
    }
    $db->prepare("INSERT INTO order_history (order_id, stage_from, stage_to, note, user_name) VALUES (?, 'recibido', 'recibido', ?, ?)")
        ->execute([$row['core_id'], 'El cliente editó el pedido (' . count($want) . ' ref.)', $row['customer_name']]);
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(['error' => 'No se pudo guardar. Intenta de nuevo en unos minutos.'], 500);
}
jsonResponse(['ok' => true, 'number' => $row['number']]);
