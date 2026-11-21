<?php
/**
 * Pedidos a alistar (tabla warehouse_orders, migración 035). JSON, sin clave (igual que Lista Bodega), POST con X-CSRF-TOKEN.
 * $woAction: 'list' (GET) | 'create' (POST {order}) | 'get' (GET, $woId) | 'save' (POST {lines, who, checked, total, done}, $woId).
 * El checklist y el PDF siguen en el dispositivo (assets/js/warehouse_order.js); aquí solo se guarda el pedido y su avance.
 */
$db = getDB();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$woAction = $woAction ?? 'list';
$woId = (int) ($woId ?? 0);
const WO_MAX_JSON = 2000000; // ~2 MB por pedido

if ($woAction === 'create' || $woAction === 'save') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        jsonResponse(['error' => 'Token inválido. Recarga la página.'], 403);
    }
}
session_write_close();

$woRow = function (array $r, bool $full = false): array {
    $out = [
        'id' => (int) $r['id'],
        'number' => $r['number'],
        'client' => $r['client'],
        'date' => $r['order_date'],
        'items' => (int) $r['items_count'],
        'checked' => (int) $r['checked_count'],
        'status' => $r['status'],
        'who' => $r['checked_by'],
        'total' => $r['total_final'] === null ? null : (float) $r['total_final'],
        'created' => (int) $r['created_ts'],
        'updated' => (int) $r['updated_ts'],
        'finished' => $r['finished_ts'] === null ? null : (int) $r['finished_ts'],
    ];
    if ($full) {
        $out['order'] = json_decode($r['order_json'], true);
        $out['lines'] = $r['lines_json'] ? json_decode($r['lines_json'], true) : null;
    }
    return $out;
};
$cols = 'id, number, client, order_date, items_count, checked_count, status, checked_by, total_final,
         UNIX_TIMESTAMP(created_at) AS created_ts, UNIX_TIMESTAMP(updated_at) AS updated_ts, UNIX_TIMESTAMP(finished_at) AS finished_ts';

if ($woAction === 'list') {
    $rows = $db->query("SELECT $cols FROM warehouse_orders
        ORDER BY status = 'listo', CASE WHEN status = 'listo' THEN finished_at END DESC, created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['orders' => array_map($woRow, $rows)]);
}

if ($woAction === 'get') {
    $st = $db->prepare("SELECT $cols, order_json, lines_json FROM warehouse_orders WHERE id = ?");
    $st->execute([$woId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        jsonResponse(['error' => 'Ese pedido no existe.'], 404);
    }
    jsonResponse($woRow($r, true));
}

$raw = file_get_contents('php://input');
if (strlen($raw) > WO_MAX_JSON) {
    jsonResponse(['error' => 'El pedido es demasiado grande.'], 413);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    jsonResponse(['error' => 'Datos inválidos.'], 400);
}
$cut = fn($v, int $n) => mb_substr(trim((string) ($v ?? '')), 0, $n);

if ($woAction === 'create') {
    $order = $input['order'] ?? null;
    if (!is_array($order) || !is_array($order['items'] ?? null) || !$order['items']) {
        jsonResponse(['error' => 'No hay productos en el pedido.'], 400);
    }
    $info = is_array($order['order'] ?? null) ? $order['order'] : [];
    $sig = $cut($info['number'] ?? '', 100) . '|' . $cut($info['client'] ?? '', 255) . '|'
        . implode(',', array_map(fn($i) => (is_array($i) ? ($i['code'] ?? '') . ':' . ($i['qty'] ?? '') : ''), $order['items']));
    $key = sha1($sig);
    $st = $db->prepare('SELECT id FROM warehouse_orders WHERE order_key = ?');
    $st->execute([$key]);
    if ($id = $st->fetchColumn()) {
        jsonResponse(['id' => (int) $id, 'existing' => true]);
    }
    $st = $db->prepare('INSERT INTO warehouse_orders (order_key, number, client, order_date, items_count, order_json) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([$key, $cut($info['number'] ?? '', 100), $cut($info['client'] ?? '', 255), $cut($info['date'] ?? '', 100),
        count($order['items']), json_encode($order, JSON_UNESCAPED_UNICODE)]);
    jsonResponse(['id' => (int) $db->lastInsertId(), 'existing' => false]);
}

if ($woAction === 'save') {
    $lines = $input['lines'] ?? null;
    if (!is_array($lines)) {
        jsonResponse(['error' => 'Datos inválidos.'], 400);
    }
    $done = !empty($input['done']);
    $total = is_numeric($input['total'] ?? null) ? (float) $input['total'] : null;
    $st = $db->prepare("UPDATE warehouse_orders SET lines_json = ?, checked_by = ?, checked_count = ?, items_count = ?, total_final = ?,
        finished_at = IF(? = 1 AND status <> 'listo', NOW(), finished_at), status = IF(? = 1, 'listo', status) WHERE id = ?");
    $st->execute([json_encode($lines, JSON_UNESCAPED_UNICODE), $cut($input['who'] ?? '', 80), max(0, (int) ($input['checked'] ?? 0)),
        count($lines), $total, $done ? 1 : 0, $done ? 1 : 0, $woId]);
    $chk = $db->prepare('SELECT status FROM warehouse_orders WHERE id = ?');
    $chk->execute([$woId]);
    $status = $chk->fetchColumn();
    if ($status === false) {
        jsonResponse(['error' => 'Ese pedido no existe.'], 404);
    }
    jsonResponse(['ok' => true, 'status' => $status]);
}

jsonResponse(['error' => 'Acción desconocida.'], 400);
