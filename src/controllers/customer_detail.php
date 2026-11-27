<?php
/** /clientes/{id}: ficha del cliente (deuda, historial de pedidos, lo que más compra, «Nuevo pedido»). El vendedor solo ve los suyos. */
require_once __DIR__ . '/../services/Orders.php';
$db = getDB();
$role = currentRole();
$me = sessionUser();
$w = $role === 'vendedor' && $me ? ' AND c.seller_user_id = ' . (int) $me['id'] : '';
$cust = null; $orders = []; $top = []; $stats = ['n' => 0, 'tot' => 0.0, 'avg' => 0.0, 'last' => ''];
try {
    $st = $db->prepare("SELECT c.*, IF(c.balance > 0 AND c.debt_since IS NOT NULL, DATEDIFF(CURDATE(), c.debt_since), 0) AS debt_days,
        (SELECT COALESCE(NULLIF(name,''), username) FROM users WHERE id = c.seller_user_id) AS seller_name FROM customers c WHERE c.id = ?$w");
    $st->execute([$customerId]);
    $cust = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($cust) {
        $st = $db->prepare('SELECT id, number, stage, items_count, total, created_at FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 50');
        $st->execute([$customerId]);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as $o) if ($o['stage'] !== 'cancelado') { $stats['n']++; $stats['tot'] += (float) $o['total']; }
        $stats['avg'] = $stats['n'] ? $stats['tot'] / $stats['n'] : 0;
        $stats['last'] = $orders ? substr($orders[0]['created_at'], 0, 10) : '';
        $st = $db->prepare("SELECT i.sku, MAX(i.name) AS name, SUM(i.qty) AS qty, COUNT(DISTINCT o.id) AS veces, MAX(p.cover_image_url) AS cover
            FROM orders o JOIN client_order_items i ON i.order_id = o.client_order_id
            LEFT JOIN products p ON CONVERT(p.sku USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(i.sku USING utf8mb4) COLLATE utf8mb4_unicode_ci
            WHERE o.customer_id = ? AND o.stage <> 'cancelado' GROUP BY i.sku ORDER BY qty DESC LIMIT 8");
        $st->execute([$customerId]);
        $top = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    $cust = null;
}
if (!$cust) http_response_code(404);
$v2 = ['nav' => 'clientes', 'title' => $cust ? ($cust['name'] ?: 'Cliente') : 'Cliente no encontrado', 'subtitle' => $cust ? 'Ficha del cliente' : ''];
include __DIR__ . '/../../templates/admin/customer_detail.php';
