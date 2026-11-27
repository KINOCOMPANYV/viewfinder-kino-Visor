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
    $st = $db->prepare("SELECT name, phone, balance FROM customers WHERE (name LIKE ? OR phone LIKE ?) $w ORDER BY updated_at DESC LIMIT 8");
    $st->execute([$like, $like]);
    jsonResponse(['customers' => mb_strlen($q) >= 2 ? $st->fetchAll(PDO::FETCH_ASSOC) : []]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // asignar vendedor (Admin/Facturación)
    $cid = (int) ($_POST['id'] ?? 0);
    if (!verifyCsrf()) {
        $_SESSION['orders_flash'] = ['err', 'Token inválido.'];
    } elseif (!in_array($role, ['admin', 'dev', 'facturacion'], true)) {
        $_SESSION['orders_flash'] = ['err', 'Solo Administración o Facturación cambia vendedor o cartera.'];
    } elseif (($_POST['action'] ?? 'seller') === 'balance') {
        $bal = (float) preg_replace('/\D+/', '', explode(',', (string) ($_POST['balance'] ?? ''))[0]); // pesos enteros ("1.250.000" o "1250000")
        $db->prepare('UPDATE customers SET balance = ?, balance_note = ?, balance_at = NOW() WHERE id = ?')
           ->execute([$bal, mb_substr(trim((string) ($_POST['balance_note'] ?? '')), 0, 255), $cid]);
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
    redirect('/clientes' . (($_POST['back'] ?? '') === 'debe' ? '?cartera=debe' : ''));
}
$canAssign = in_array($role, ['admin', 'dev', 'facturacion'], true);
$sellers = [];
try { $sellers = $db->query("SELECT id, COALESCE(NULLIF(name,''), username) AS n FROM users WHERE role = 'vendedor' AND is_active = 1 ORDER BY n")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (\Throwable $e) {}
$where = $role === 'vendedor' && $me ? ['c.seller_user_id = ' . (int) $me['id']] : ['1=1'];
$args = [];
$onlyDebt = ($_GET['cartera'] ?? '') === 'debe';
if ($onlyDebt) $where[] = 'c.balance > 0';
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
$debtTotal = 0.0; $debtCount = 0;
try {
    $ds = $db->query('SELECT COUNT(*), COALESCE(SUM(balance), 0) FROM customers c WHERE c.balance > 0 AND ' . $where[0])->fetch(PDO::FETCH_NUM);
    [$debtCount, $debtTotal] = [(int) $ds[0], (float) $ds[1]];
} catch (\Throwable $e) {}
$v2 = ['nav' => 'clientes', 'title' => 'Clientes', 'subtitle' => 'Se crean solos con el WhatsApp del pedido'];
include __DIR__ . '/../../templates/admin/customers_list.php';
