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
$isShop = !empty($in['tienda']); // pedido del catálogo de venta público (/tienda): pide ciudad, dirección y forma de pago
$city = mb_substr(trim((string) ($in['city'] ?? '')), 0, 80, 'UTF-8');
$address = mb_substr(trim((string) ($in['address'] ?? '')), 0, 255, 'UTF-8');
$pay = in_array($in['pay'] ?? '', ['contra_entrega', 'transferencia'], true) ? $in['pay'] : '';
if ($isShop && ($city === '' || $address === '' || $pay === '')) {
    jsonResponse(['error' => 'Completa ciudad, dirección y forma de pago.'], 422);
}
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
$st = $db->prepare("SELECT sku, name, price_suggested FROM products WHERE archived = 0 AND is_active = 1 AND cover_image_url > '' AND sku IN ($ph)");
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
$staffUser = isStaff() && !empty($in['staff']) ? (sessionUser() ?: ['id' => null, 'name' => '', 'username' => (string) (env('ADMIN_USER') ?: 'admin'), 'role' => currentRole()]) : null; // pedido hecho por un vendedor (/pedidos/nuevo)
try {
    $db->beginTransaction();
    $token = bin2hex(random_bytes(16));
    try {
        $db->prepare("INSERT INTO client_orders (user_id, customer_name, customer_phone, note, total, edit_token, city, address, pay_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $name, $phone, $note, $total, $token, $city, $address, $pay]);
    } catch (\PDOException $e) { // migración 042/043 pendiente: sin edición ni datos de entrega
        $token = '';
        $db->prepare("INSERT INTO client_orders (user_id, customer_name, customer_phone, note, total) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $name, $phone, $note, $total]);
    }
    $id = (int) $db->lastInsertId();
    $ins = $db->prepare("INSERT INTO client_order_items (order_id, sku, name, qty, unit_price) VALUES (?, ?, ?, ?, ?)");
    foreach ($want as $sku => $qty) {
        $ins->execute([$id, $sku, mb_substr((string) $found[$sku]['name'], 0, 255, 'UTF-8'), $qty, (float) $found[$sku]['price_suggested']]);
    }
    $db->commit();
    $number = '';
    try { // v2 módulo 3: el pedido núcleo (número, etapa, historial, ficha del cliente)
        require_once __DIR__ . '/../services/Orders.php';
        $payTxt = ['contra_entrega' => 'Contra entrega', 'transferencia' => 'Transferencia'][$pay] ?? '';
        $shipNote = $isShop ? trim('Tienda web · Pago: ' . $payTxt . ' · Entrega: ' . $address . ', ' . $city . ($note !== '' ? ' · ' . $note : '')) : $note;
        $f = ['stage' => 'recibido', 'origin' => $isShop ? 'tienda' : 'cliente', 'customer_name' => $name, 'customer_phone' => $phone, 'client_order_id' => $id,
            'items_count' => count($want), 'total' => $total, 'note' => mb_substr($shipNote, 0, 1000, 'UTF-8'), 'created_by' => $name];
        if ($staffUser) {
            $f = array_merge($f, ['origin' => 'vendedor', 'created_by' => $staffUser['name'] ?: $staffUser['username'], 'user' => $staffUser,
                'seller_user_id' => $staffUser['role'] === 'vendedor' ? (int) $staffUser['id'] : null]);
        }
        $coreId = Orders::create($db, $f);
        $number = (string) $db->query('SELECT number FROM orders WHERE id = ' . (int) $coreId)->fetchColumn();
        if ($isShop && ($cid = Orders::customer($db, $name, $phone))) { // ficha del cliente con su ciudad y dirección
            $db->prepare('UPDATE customers SET city = ?, address = ? WHERE id = ?')->execute([$city, $address, $cid]);
        }
    } catch (\Throwable $e) { /* migración 041 pendiente */ }
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(['error' => 'No se pudo guardar el pedido. Intenta de nuevo en unos minutos.'], 500);
}
$shopWa = ['business' => false];
if ($isShop && $token !== '') { // aviso automático solo si la API de WhatsApp está configurada; si no, el cliente usa los enlaces wa.me
    try {
        require_once __DIR__ . '/../services/Whatsapp.php';
        if (Whatsapp::enabled()) {
            $link = publicBaseUrl() . '/pedido/' . $token;
            $shopWa['business'] = Whatsapp::send(shopNotifyPhone(), "🛒 Pedido {$number} de {$name} ({$phone})\n" . count($want) . ' ref. · $' . number_format($total, 0, ',', '.') . "\nPago: " . ($payTxt ?? '') . "\n{$address}, {$city}\n{$link}");
            $shopWa['client'] = Whatsapp::send($phone, "¡Gracias {$name}! Recibimos tu pedido {$number} en Kino Company. Aquí están tus productos con fotos: {$link}");
        }
    } catch (\Throwable $e) { /* el pedido ya quedó guardado */ }
}
jsonResponse(['ok' => true, 'wa' => $shopWa, 'id' => $id, 'number' => $number, 'core' => $coreId ?? 0, 'token' => $staffUser ? '' : $token]);
