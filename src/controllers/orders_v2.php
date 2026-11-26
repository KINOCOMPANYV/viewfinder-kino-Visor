<?php
/**
 * /pedidos (lista por etapa) y /pedidos/{id} (detalle, historial, cambio de etapa). Solo personal (requireStaff).
 * POST action=move (to, note) | discount (pct) con csrf_token. Reglas del dueño 2026-11-26: ver docs/AVANCES.md.
 */
require_once __DIR__ . '/../services/Orders.php';
$db = getDB();
$role = currentRole();
$me = sessionUser();
[$visSql, $visArgs] = Orders::visibility($role, $me);
$flash = $_SESSION['orders_flash'] ?? null;
unset($_SESSION['orders_flash']);
$tableOk = true;

if ($orderId > 0) {
    $st = $db->prepare("SELECT o.* FROM orders o WHERE o.id = ? AND $visSql");
    $st->execute(array_merge([$orderId], $visArgs));
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        $order = null;
    }
    if ($order && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = (string) ($_POST['action'] ?? '');
        $err = null;
        if (!verifyCsrf()) {
            $err = 'Token inválido. Recarga la página.';
        } elseif ($act === 'move') {
            $err = Orders::move($db, $orderId, (string) ($_POST['to'] ?? ''), trim((string) ($_POST['note'] ?? '')), $me, $role);
        } elseif ($act === 'discount') {
            $pct = max(0.0, min(100.0, (float) str_replace(',', '.', (string) ($_POST['pct'] ?? '0'))));
            if ($role === 'vendedor' && $pct > Orders::MAX_SELLER_DISCOUNT) {
                $err = 'El vendedor da hasta ' . Orders::MAX_SELLER_DISCOUNT . '%; más lo autoriza un Administrador.';
            } elseif (in_array($order['stage'], ['facturado', 'cancelado'], true)) {
                $err = 'Este pedido ya está cerrado.';
            } else {
                $db->prepare('UPDATE orders SET discount_pct = ? WHERE id = ?')->execute([$pct, $orderId]);
                $db->prepare("INSERT INTO order_history (order_id, stage_from, stage_to, note, user_id, user_name) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$orderId, $order['stage'], $order['stage'], 'Descuento ' . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%', $me['id'] ?? null, $me['username'] ?? 'admin']);
            }
        }
        $_SESSION['orders_flash'] = $err ? ['err', $err] : ['ok', 'Listo.'];
        redirect('/pedidos/' . $orderId);
    }
    $items = [];
    $history = [];
    if ($order) {
        if ($order['client_order_id']) {
            $s = $db->prepare('SELECT i.sku, i.name, i.qty, i.unit_price, p.cover_image_url FROM client_order_items i LEFT JOIN products p ON CONVERT(p.sku USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(i.sku USING utf8mb4) COLLATE utf8mb4_unicode_ci WHERE i.order_id = ? ORDER BY i.id');
            $s->execute([$order['client_order_id']]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
        }
        $s = $db->prepare('SELECT * FROM order_history WHERE order_id = ? ORDER BY id DESC');
        $s->execute([$orderId]);
        $history = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    $v2 = ['nav' => 'pedidos', 'title' => $order ? 'Pedido ' . $order['number'] : 'Pedido no encontrado'];
    include __DIR__ . '/../../templates/admin/orders_detail.php';
    exit;
}

// Lista
$stage = (string) ($_GET['etapa'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$where = [$visSql];
$args = $visArgs;
if (isset(Orders::STAGES[$stage])) { $where[] = 'o.stage = ?'; $args[] = $stage; }
if ($q !== '') {
    $where[] = '(o.number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    array_push($args, $like, $like, $like);
}
$rows = [];
$counts = [];
try {
    $st = $db->prepare('SELECT o.* FROM orders o WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 200');
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT o.stage, COUNT(*) FROM orders o WHERE $visSql GROUP BY o.stage");
    $st->execute($visArgs);
    $counts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $e) {
    $tableOk = false;
}
$v2 = ['nav' => 'pedidos', 'title' => 'Pedidos', 'subtitle' => 'Del pedido recibido a la factura'];
include __DIR__ . '/../../templates/admin/orders_list.php';
