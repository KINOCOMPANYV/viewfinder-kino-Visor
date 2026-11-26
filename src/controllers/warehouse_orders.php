<?php
/**
 * Pedidos bodega (tabla warehouse_orders, migración 035). JSON, sin clave (igual que Lista Bodega), POST con X-CSRF-TOKEN.
 * $woAction: 'list' (GET) | 'create' (POST {order}) | 'get' (GET, $woId) | 'save' (POST {lines, who, checked, total, done}, $woId)
 *   | 'delete' (POST {password}, $woId): borra el pedido si la clave coincide con env PEDIDOS_DELETE_PASSWORD (por defecto 3312).
 *   | 'invoice' (POST {on}, $woId): marca (on=true) o quita la marca «Facturado» (invoiced_at) de un pedido listo.
 * El checklist y el PDF siguen en el dispositivo (assets/js/warehouse_order.js); aquí solo se guarda el pedido y su avance.
 */
$db = getDB();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$woAction = $woAction ?? 'list';
$woId = (int) ($woId ?? 0);
const WO_MAX_JSON = 2000000; // ~2 MB por pedido

if (in_array($woAction, ['create', 'save', 'delete', 'invoice'], true)) {
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
        'invoiced' => $r['invoiced_ts'] === null ? null : (int) $r['invoiced_ts'],
    ];
    if ($full) {
        $out['order'] = json_decode($r['order_json'], true);
        $out['lines'] = $r['lines_json'] ? json_decode($r['lines_json'], true) : null;
    }
    return $out;
};
$cols = 'id, number, client, order_date, items_count, checked_count, status, checked_by, total_final,
         UNIX_TIMESTAMP(created_at) AS created_ts, UNIX_TIMESTAMP(updated_at) AS updated_ts, UNIX_TIMESTAMP(finished_at) AS finished_ts, UNIX_TIMESTAMP(invoiced_at) AS invoiced_ts';

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

if ($woAction === 'delete') {
    $pass = env('PEDIDOS_DELETE_PASSWORD', '3312');
    if (!hash_equals($pass, (string) ($input['password'] ?? ''))) {
        jsonResponse(['error' => 'Contraseña incorrecta.'], 403);
    }
    $st = $db->prepare('DELETE FROM warehouse_orders WHERE id = ?');
    $st->execute([$woId]);
    if ($st->rowCount() === 0) {
        jsonResponse(['error' => 'Ese pedido no existe.'], 404);
    }
    jsonResponse(['ok' => true]);
}

if ($woAction === 'invoice') {
    // Solo un pedido listo (verde) se puede marcar; quitar la marca lo devuelve a verde.
    $on = !empty($input['on']);
    $st = $db->prepare("UPDATE warehouse_orders SET invoiced_at = IF(? = 1, COALESCE(invoiced_at, NOW()), NULL) WHERE id = ? AND status = 'listo'");
    $st->execute([$on ? 1 : 0, $woId]);
    $st = $db->prepare("SELECT $cols FROM warehouse_orders WHERE id = ?");
    $st->execute([$woId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        jsonResponse(['error' => 'Ese pedido no existe.'], 404);
    }
    if ($r['status'] !== 'listo') {
        jsonResponse(['error' => 'Solo se puede marcar como facturado un pedido listo.'], 409);
    }
    jsonResponse(['ok' => true, 'order' => $woRow($r)]);
}

if ($woAction === 'save') {
    $lines = $input['lines'] ?? null;
    if (!is_array($lines)) {
        jsonResponse(['error' => 'Datos inválidos.'], 400);
    }
    $done = !empty($input['done']);
    // Precios bloqueados: el alistador no los cambia. Se conserva el precio ya guardado (o el original si
    // deshace un cambio); un código nuevo toma el precio del visor. Solo se acepta la reparación del #112
    // (precio viejo ÷ 100 y/o ÷ cantidad pedida).
    $prevSt = $db->prepare('SELECT lines_json FROM warehouse_orders WHERE id = ?');
    $prevSt->execute([$woId]);
    $prevJson = $prevSt->fetchColumn();
    $prev = [];
    foreach (($prevJson ? (json_decode($prevJson, true) ?: []) : []) as $pl) {
        if (is_array($pl) && isset($pl['id'])) {
            $prev[(string) $pl['id']] = $pl;
        }
    }
    $visorSt = $db->prepare('SELECT price_suggested FROM products WHERE sku = ? AND archived = 0 AND price_suggested > 0 LIMIT 1');
    $visorPrice = function ($code) use ($visorSt) {
        $visorSt->execute([(string) $code]);
        $v = $visorSt->fetchColumn();
        return $v === false ? null : (float) $v;
    };
    $num = fn($v) => is_numeric($v) ? (float) $v : null;
    $total = 0.0;
    foreach ($lines as $i => $l) {
        if (!is_array($l)) {
            jsonResponse(['error' => 'Datos inválidos.'], 400);
        }
        $code = (string) ($l['code'] ?? '');
        $in = $num($l['price'] ?? null);
        $p = $prev[(string) ($l['id'] ?? '')] ?? null;
        if ($p === null) {
            // Primera vez que se guarda: precios leídos del pedido. Después, un ítem nuevo = precio del visor.
            $price = $prev ? $visorPrice($code) : $in;
        } else {
            $base = is_array($p['base'] ?? null) ? $p['base'] : null;
            if ($code === (string) ($p['code'] ?? '')) {
                $old = $num($p['price'] ?? null);
                $q = max(1, (int) ($p['origQty'] ?? 1));
                $fix = $old !== null && $in !== null && in_array(round($in), [round($old / 100), round($old / $q), round($old / 100 / $q)], false);
                $price = $fix ? $in : ($old ?? $visorPrice($code));
            } elseif ($base && $code === (string) ($base['code'] ?? '')) {
                $price = $num($base['price'] ?? null) ?? $visorPrice($code);
            } else {
                $price = $visorPrice($code);
            }
            if ($base) {
                $bOld = $num($base['price'] ?? null);
                $bIn = is_array($l['base'] ?? null) ? $num($l['base']['price'] ?? null) : null;
                $q = max(1, (int) ($p['origQty'] ?? 1));
                if ($bOld !== null && $bIn !== null && in_array(round($bIn), [round($bOld / 100), round($bOld / $q), round($bOld / 100 / $q)], false)) {
                    $base['price'] = $bIn;
                }
                $l['base'] = $base;
            }
        }
        $l['price'] = $price;
        $l['note'] = $cut($l['note'] ?? '', 1000);
        $lines[$i] = $l;
        $qty = max(0, (int) ($l['qty'] ?? 0));
        if ($price !== null && $qty > 0) {
            $total += $price * $qty;
        }
    }
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
