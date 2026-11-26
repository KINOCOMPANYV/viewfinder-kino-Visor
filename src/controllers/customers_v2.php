<?php
/** /clientes: fichas básicas (se crean solas por WhatsApp al llegar un pedido) con su cantidad de pedidos. El vendedor solo ve las suyas. POST action=seller (admin). */
require_once __DIR__ . '/../services/Orders.php';
$db = getDB();
$role = currentRole();
$me = sessionUser();
$q = trim((string) ($_GET['q'] ?? ''));
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
