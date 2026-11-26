<?php
/**
 * POST /hacer-pedido/enviar (JSON): guarda el pedido del cliente en estado «recibido» (v2 módulo 4).
 * Los precios y nombres salen de la BD, nunca del navegador. Máx. 100 líneas y 999 unidades por línea.
 */
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) ($in['csrf'] ?? ''))) {
    jsonResponse(['error' => 'Sesión vencida. Recarga la página e intenta de nuevo.'], 403);
}
if (trim((string) ($in['website'] ?? '')) !== '') { // campo trampa para robots
    jsonResponse(['ok' => true, 'id' => 0]);
}
$name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120, 'UTF-8');
$phone = mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40, 'UTF-8');
$note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500, 'UTF-8');
if ($name === '' || !preg_match('/\d{7,}/', preg_replace('/\D+/', '', $phone) ?: '') ) {
    jsonResponse(['error' => 'Escribe tu nombre y un teléfono o WhatsApp válido.'], 422);
}
$want = [];
foreach ((array) ($in['items'] ?? []) as $it) {
    $sku = trim((string) ($it['sku'] ?? ''));
    $qty = max(1, min(999, (int) ($it['qty'] ?? 1)));
    if ($sku !== '' && mb_strlen($sku) <= 100) $want[$sku] = ($want[$sku] ?? 0) + $qty;
}
if (!$want || count($want) > 100) {
    jsonResponse(['error' => $want ? 'Máximo 100 referencias por pedido.' : 'Agrega al menos un producto.'], 422);
}

$db = getDB();
$ph = implode(',', array_fill(0, count($want), '?'));
$st = $db->prepare("SELECT sku, name, price_suggested FROM products WHERE archived = 0 AND cover_image_url > '' AND sku IN ($ph)");
$st->execute(array_keys($want));
$found = [];
foreach ($st->fetchAll() as $r) $found[$r['sku']] = $r;
$missing = array_values(array_diff(array_keys($want), array_keys($found)));
if ($missing) {
    jsonResponse(['error' => 'Estos códigos ya no están en el catálogo: ' . implode(', ', array_slice($missing, 0, 5)) . '. Quítalos e intenta de nuevo.'], 422);
}

$total = 0;
foreach ($want as $sku => $qty) $total += $qty * (float) $found[$sku]['price_suggested'];
$userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
try {
    $db->beginTransaction();
    $db->prepare("INSERT INTO client_orders (user_id, customer_name, customer_phone, note, total) VALUES (?, ?, ?, ?, ?)")
        ->execute([$userId, $name, $phone, $note, $total]);
    $id = (int) $db->lastInsertId();
    $ins = $db->prepare("INSERT INTO client_order_items (order_id, sku, name, qty, unit_price) VALUES (?, ?, ?, ?, ?)");
    foreach ($want as $sku => $qty) {
        $ins->execute([$id, $sku, mb_substr((string) $found[$sku]['name'], 0, 255, 'UTF-8'), $qty, (float) $found[$sku]['price_suggested']]);
    }
    $db->commit();
    try { // v2 módulo 3: el pedido núcleo (número, etapa, historial, ficha del cliente)
        require_once __DIR__ . '/../services/Orders.php';
        Orders::create($db, ['stage' => 'recibido', 'origin' => 'cliente', 'customer_name' => $name, 'customer_phone' => $phone, 'client_order_id' => $id,
            'items_count' => count($want), 'total' => $total, 'note' => $note, 'created_by' => $name]);
    } catch (\Throwable $e) { /* migración 041 pendiente */ }
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(['error' => 'No se pudo guardar el pedido. Intenta de nuevo en unos minutos.'], 500);
}
jsonResponse(['ok' => true, 'id' => $id]);
