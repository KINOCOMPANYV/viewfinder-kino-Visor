<?php
/** /clientes: fichas básicas (se crean solas por WhatsApp al llegar un pedido) con su cantidad de pedidos. El vendedor solo ve las suyas. POST action=seller|balance (Admin/Facturación). Cartera: balance > 0 = debe. */
require_once __DIR__ . '/../services/Orders.php';
$db = getDB();
$role = currentRole();
$me = sessionUser();
$q = trim((string) ($_GET['q'] ?? ''));
if (($_SERVER['REQUEST_URI'] ?? '') !== '' && str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '', '/clientes/buscar')) {
    // JSON para el autocompletar de /pedidos/nuevo (el vendedor solo ve los suyos)
    $w = $role === 'vendedor' && $me ? 'AND seller_user_id = ' . (int) $me['id'] : '';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $st = $db->prepare("SELECT name, phone, balance, IF(balance > 0 AND debt_since IS NOT NULL, DATEDIFF(CURDATE(), debt_since), 0) AS debt_days FROM customers WHERE (name LIKE ? OR phone LIKE ?) $w ORDER BY updated_at DESC LIMIT 8");
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
        $_SESSION['orders_flash'] = ['err', 'Solo Administración o Facturación cambia vendedor o cartera.'];
    } elseif (($_POST['action'] ?? 'seller') === 'balance') {
        $bal = (float) preg_replace('/\D+/', '', explode(',', (string) ($_POST['balance'] ?? ''))[0]); // pesos enteros ("1.250.000" o "1250000")
        $since = (string) ($_POST['debt_since'] ?? '');
        $since = preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) && $since <= date('Y-m-d') ? $since : null;
        // Debe desde: la fecha escrita; si no hay, se conserva la anterior o es hoy al empezar a deber; al quedar al día se borra
        $db->prepare('UPDATE customers SET balance = ?, balance_note = ?, balance_at = NOW(),
                debt_since = CASE WHEN ? <= 0 THEN NULL WHEN ? IS NOT NULL THEN ? ELSE COALESCE(debt_since, CURDATE()) END WHERE id = ?')
           ->execute([$bal, mb_substr(trim((string) ($_POST['balance_note'] ?? '')), 0, 255), $bal, $since, $since, $cid]);
        $_SESSION['orders_flash'] = ['ok', $bal > 0 ? 'Cartera guardada: debe ' . priceText($bal) . '.' : 'Cartera guardada: al día.'];
    } else {
        $sid = (int) ($_POST['seller'] ?? 0);
        $ok = $sid === 0 || $db->query("SELECT COUNT(*) FROM users WHERE id = $sid AND role = 'vendedor' AND is_active = 1")->fetchColumn();
        if ($ok) {
            $db->prepare('UPDATE customers SET seller_user_id = ? WHERE id = ?')->execute([$sid ?: null, $cid]);
            $db->prepare("UPDATE orders SET seller_user_id = ? WHERE customer_id = ? AND stage NOT IN ('facturado','cancelado')")->execute([$sid ?: null, $cid]);
            $_SESSION['orders_flash'] = ['ok', 'Vendedor asignado.'];
        }
    }
    $back = (string) ($_POST['back'] ?? '');
    redirect('/clientes' . (in_array($back, ['debe', '20', '40'], true) ? '?cartera=' . $back : ''));
}
$canAssign = in_array($role, ['admin', 'dev', 'facturacion'], true);
$sellers = [];
try { $sellers = $db->query("SELECT id, COALESCE(NULLIF(name,''), username) AS n FROM users WHERE role = 'vendedor' AND is_active = 1 ORDER BY n")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (\Throwable $e) {}
$where = $role === 'vendedor' && $me ? ['c.seller_user_id = ' . (int) $me['id']] : ['1=1'];
$args = [];
$cartera = in_array($_GET['cartera'] ?? '', ['debe', '20', '40'], true) ? $_GET['cartera'] : '';
$onlyDebt = $cartera !== '';
if ($onlyDebt) $where[] = 'c.balance > 0';
if ($cartera === '20' || $cartera === '40') $where[] = 'c.debt_since < CURDATE() - INTERVAL ' . (int) $cartera . ' DAY';
if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.phone LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $args = [$like, $like];
}
$rows = [];
$tableOk = true;
try {
    $st = $db->prepare('SELECT c.*, IF(c.balance > 0 AND c.debt_since IS NOT NULL, DATEDIFF(CURDATE(), c.debt_since), 0) AS debt_days, COUNT(o.id) AS n, MAX(o.created_at) AS last_at, COALESCE(SUM(CASE WHEN o.stage <> \'cancelado\' THEN o.total END), 0) AS tot
        FROM customers c LEFT JOIN orders o ON o.customer_id = c.id WHERE ' . implode(' AND ', $where) . ' GROUP BY c.id ORDER BY last_at DESC, c.id DESC LIMIT 200');
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $tableOk = false;
}
$debtTotal = 0.0; $debtCount = $debt20 = $debt40 = 0;
try {
    $ds = $db->query('SELECT COUNT(*), COALESCE(SUM(balance), 0), SUM(debt_since < CURDATE() - INTERVAL 20 DAY), SUM(debt_since < CURDATE() - INTERVAL 40 DAY)
        FROM customers c WHERE c.balance > 0 AND ' . $where[0])->fetch(PDO::FETCH_NUM);
    [$debtCount, $debtTotal, $debt20, $debt40] = [(int) $ds[0], (float) $ds[1], (int) $ds[2], (int) $ds[3]];
} catch (\Throwable $e) {}
$v2 = ['nav' => 'clientes', 'title' => 'Clientes', 'subtitle' => 'Se crean solos con el WhatsApp del pedido'];
include __DIR__ . '/../../templates/admin/customers_list.php';
