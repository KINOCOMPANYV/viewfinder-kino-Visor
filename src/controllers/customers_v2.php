<?php
/** /clientes: fichas básicas (se crean solas por WhatsApp al llegar un pedido) con su cantidad de pedidos. El vendedor solo ve las suyas. POST action=seller (admin). */
require_once __DIR__ . '/../services/Orders.php';
$db = getDB();
$role = currentRole();
$me = sessionUser();
$q = trim((string) ($_GET['q'] ?? ''));
if (($_SERVER['REQUEST_URI'] ?? '') !== '' && str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '', '/clientes/buscar')) {
    // JSON para el autocompletar de /pedidos/nuevo (el vendedor solo ve los suyos)
    $w = $role === 'vendedor' && $me ? 'AND seller_user_id = ' . (int) $me['id'] : '';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $st = $db->prepare("SELECT name, phone FROM customers WHERE (name LIKE ? OR phone LIKE ?) $w ORDER BY updated_at DESC LIMIT 8");
    $st->execute([$like, $like]);
    jsonResponse(['customers' => mb_strlen($q) >= 2 ? $st->fetchAll(PDO::FETCH_ASSOC) : []]);
}
if (str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/clientes/ultimo')) {
    // JSON: último pedido (no cancelado) del cliente por teléfono, para «Repetir último pedido» en Vender
    $key = Orders::phoneKey((string) ($_GET['phone'] ?? ''));
    $out = ['order' => null];
    if ($key !== null) {
        $w = $role === 'vendedor' && $me ? 'AND c.seller_user_id = ' . (int) $me['id'] : '';
        $st = $db->prepare("SELECT o.number, o.created_at, o.client_order_id FROM orders o JOIN customers c ON c.id = o.customer_id
            WHERE c.phone_key = ? $w AND o.client_order_id IS NOT NULL AND o.stage <> 'cancelado' ORDER BY o.id DESC LIMIT 1");
        $st->execute([$key]);
        if ($o = $st->fetch(PDO::FETCH_ASSOC)) {
            $it = $db->prepare('SELECT sku, qty FROM client_order_items WHERE order_id = ? ORDER BY id');
            $it->execute([$o['client_order_id']]);
            $out['order'] = ['number' => $o['number'], 'date' => substr($o['created_at'], 0, 10), 'items' => $it->fetchAll(PDO::FETCH_ASSOC)];
        }
    }
    jsonResponse($out);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // asignar vendedor (Admin/Facturación)
    $cid = (int) ($_POST['id'] ?? 0);
    if (!verifyCsrf()) {
        $_SESSION['orders_flash'] = ['err', 'Token inválido.'];
    } elseif (!in_array($role, ['admin', 'dev', 'facturacion'], true)) {
        $_SESSION['orders_flash'] = ['err', 'Solo Administración o Facturación asigna vendedores.'];
    } else {
        $sid = (int) ($_POST['seller'] ?? 0);
        $ok = $sid === 0 || $db->query("SELECT COUNT(*) FROM users WHERE id = $sid AND role = 'vendedor' AND is_active = 1")->fetchColumn();
        if ($ok) {
            $db->prepare('UPDATE customers SET seller_user_id = ? WHERE id = ?')->execute([$sid ?: null, $cid]);
            $db->prepare("UPDATE orders SET seller_user_id = ? WHERE customer_id = ? AND stage NOT IN ('facturado','cancelado')")->execute([$sid ?: null, $cid]);
            $_SESSION['orders_flash'] = ['ok', 'Vendedor asignado.'];
        }
    }
    redirect('/clientes');
}
$canAssign = in_array($role, ['admin', 'dev', 'facturacion'], true);
$sellers = [];
try { $sellers = $db->query("SELECT id, COALESCE(NULLIF(name,''), username) AS n FROM users WHERE role = 'vendedor' AND is_active = 1 ORDER BY n")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (\Throwable $e) {}
$where = $role === 'vendedor' && $me ? ['c.seller_user_id = ' . (int) $me['id']] : ['1=1'];
$args = [];
if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.phone LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $args = [$like, $like];
}
$rows = [];
$tableOk = true;
try {
    $st = $db->prepare('SELECT c.*, COUNT(o.id) AS n, MAX(o.created_at) AS last_at, COALESCE(SUM(CASE WHEN o.stage <> \'cancelado\' THEN o.total END), 0) AS tot
        FROM customers c LEFT JOIN orders o ON o.customer_id = c.id WHERE ' . implode(' AND ', $where) . ' GROUP BY c.id ORDER BY last_at DESC, c.id DESC LIMIT 200');
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $tableOk = false;
}
$v2 = ['nav' => 'clientes', 'title' => 'Clientes', 'subtitle' => 'Se crean solos con el WhatsApp del pedido'];
include __DIR__ . '/../../templates/admin/customers_list.php';
