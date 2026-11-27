<?php
/**
 * Pedido núcleo (v2 módulo 3, migración 041). Un pedido = número único + etapa + historial.
 * Une el pedido del cliente (client_orders) y el checklist de bodega (warehouse_orders) sin cambiar sus tablas.
 */
class Orders
{
    public const STAGES = [
        'recibido'  => 'Recibido',
        'por_alistar' => 'Por alistar',
        'en_alistamiento' => 'En alistamiento',
        'listo_facturar' => 'Listo para facturar',
        'facturado' => 'Facturado',
        'cancelado' => 'Cancelado',
    ];
    /** Quién puede pasar a cada etapa (dev/admin siempre). Matriz etapa × rol aprobada 2026-11-27 (docs/AVANCES.md). */
    private const WHO = [
        'por_alistar' => ['vendedor', 'facturacion'],
        'en_alistamiento' => ['bodega'],
        'listo_facturar' => ['bodega'],
        'facturado' => ['facturacion'],
        'cancelado' => ['facturacion'], // regla 5: solo Admin o Facturación
    ];
    /** Acciones dentro del pedido (dev/admin siempre). El servidor las valida en orders_v2.php y warehouse_orders.php. */
    private const CAN = [
        'alistar' => ['bodega'],              // cantidades, «Alistado», nota, finalizar
        'desbloquear' => ['facturacion'],     // abrir un producto ya alistado (con su contraseña)
        'facturar' => ['facturacion'],        // marcar / quitar «Facturado»
        'descuento' => ['vendedor', 'facturacion'],
    ];
    public const MAX_SELLER_DISCOUNT = 10.0; // regla 4: más de esto solo Admin

    public static function can(string $role, string $action): bool
    {
        if (in_array($role, ['admin', 'dev'], true)) return true;
        return in_array($role, self::CAN[$action] ?? [], true);
    }

    /** Descuento máximo del rol (null = sin límite, 0 = no puede). */
    public static function maxDiscount(string $role): ?float
    {
        if (in_array($role, ['admin', 'dev'], true)) return null;
        return self::can($role, 'descuento') ? self::MAX_SELLER_DISCOUNT : 0.0;
    }

    public static function label(string $s): string { return self::STAGES[$s] ?? $s; }

    public static function phoneKey(string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', $phone) ?: '';
        return strlen($d) >= 7 ? substr($d, -10) : null;
    }

    public static function canMove(string $role, string $to): bool
    {
        if (in_array($role, ['admin', 'dev'], true)) return true;
        return in_array($role, self::WHO[$to] ?? [], true);
    }

    /** Etapas a las que se puede pasar desde la actual (hacia delante, o cancelar). */
    public static function nextStages(string $from): array
    {
        $order = array_keys(self::STAGES);
        $i = array_search($from, $order, true);
        if ($i === false || $from === 'facturado' || $from === 'cancelado') return [];
        return [$order[$i + 1], 'cancelado'];
    }

    /** Ficha del cliente por WhatsApp (se crea sola, regla 1). */
    public static function customer(PDO $db, string $name, string $phone): ?int
    {
        $key = self::phoneKey($phone);
        if ($key === null) return null;
        $s = $db->prepare('SELECT id FROM customers WHERE phone_key = ?');
        $s->execute([$key]);
        if ($id = $s->fetchColumn()) return (int) $id;
        $db->prepare('INSERT INTO customers (name, phone, phone_key) VALUES (?, ?, ?)')->execute([$name, $phone, $key]);
        return (int) $db->lastInsertId();
    }

    private static function addHistory(PDO $db, int $id, string $from, string $to, string $note, ?array $user): void
    {
        $name = $user ? ($user['name'] !== '' ? $user['name'] : $user['username']) : '';
        $db->prepare('INSERT INTO order_history (order_id, stage_from, stage_to, note, user_id, user_name) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $from, $to, mb_substr($note, 0, 1000), $user['id'] ?? null, $name]);
    }

    /** Crea el pedido núcleo y le pone número P-00001. $f: stage, origin, customer_*, client_order_id, warehouse_order_id, items_count, total, seller_user_id, created_by. */
    public static function create(PDO $db, array $f): int
    {
        $stage = $f['stage'] ?? 'recibido';
        $custId = self::customer($db, (string) ($f['customer_name'] ?? ''), (string) ($f['customer_phone'] ?? ''));
        $seller = $f['seller_user_id'] ?? null;
        if (!$seller && $custId) {
            $s = $db->prepare('SELECT seller_user_id FROM customers WHERE id = ?');
            $s->execute([$custId]);
            $seller = $s->fetchColumn() ?: null;
        }
        $db->prepare('INSERT INTO orders (stage, origin, customer_id, customer_name, customer_phone, seller_user_id, client_order_id, warehouse_order_id, items_count, total, note, created_by, stage_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$stage, $f['origin'] ?? 'cliente', $custId, mb_substr((string) ($f['customer_name'] ?? ''), 0, 255), mb_substr((string) ($f['customer_phone'] ?? ''), 0, 40),
                $seller, $f['client_order_id'] ?? null, $f['warehouse_order_id'] ?? null, (int) ($f['items_count'] ?? 0), (float) ($f['total'] ?? 0),
                mb_substr((string) ($f['note'] ?? ''), 0, 1000), mb_substr((string) ($f['created_by'] ?? ''), 0, 120)]);
        $id = (int) $db->lastInsertId();
        $db->prepare('UPDATE orders SET number = ? WHERE id = ?')->execute([sprintf('P-%05d', $id), $id]);
        self::addHistory($db, $id, '', $stage, 'Pedido creado (' . ($f['origin'] ?? 'cliente') . ')', $f['user'] ?? null);
        return $id;
    }

    /** Cambia de etapa con historial. Devuelve mensaje de error o null. */
    public static function move(PDO $db, int $id, string $to, string $note, ?array $user, string $role): ?string
    {
        if (!isset(self::STAGES[$to])) return 'Etapa no válida.';
        $s = $db->prepare('SELECT stage FROM orders WHERE id = ?');
        $s->execute([$id]);
        $from = $s->fetchColumn();
        if ($from === false) return 'Pedido no encontrado.';
        if (!in_array($to, self::nextStages($from), true)) return 'No se puede pasar de «' . self::label($from) . '» a «' . self::label($to) . '».';
        if (!self::canMove($role, $to)) return 'Tu perfil no puede pasar el pedido a «' . self::label($to) . '».';
        if ($to === 'cancelado' && trim($note) === '') return 'Para cancelar escribe el motivo.';
        if (in_array($to, ['listo_facturar', 'facturado'], true)) {
            // El alistamiento es obligatorio: solo se pasa a facturar cuando la bodega finalizó su checklist.
            $w = $db->prepare("SELECT w.status FROM orders o LEFT JOIN warehouse_orders w ON w.id = o.warehouse_order_id WHERE o.id = ?");
            $w->execute([$id]);
            if ($w->fetchColumn() !== 'listo') return 'Primero hay que terminar el alistamiento: marca todos los productos y toca «Finalizar alistamiento».';
        }
        $db->prepare('UPDATE orders SET stage = ?, stage_at = NOW(), responsible_user_id = ? WHERE id = ?')->execute([$to, $user['id'] ?? null, $id]);
        if ($to === 'por_alistar') self::toWarehouse($db, $id);
        self::addHistory($db, $id, $from, $to, $note, $user);
        return null;
    }

    /** Al pasar a «Por alistar», un pedido de cliente crea su checklist de bodega (warehouse_orders) con los mismos productos. */
    private static function toWarehouse(PDO $db, int $id): void
    {
        $o = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $o->execute([$id]);
        $o = $o->fetch(PDO::FETCH_ASSOC);
        if (!$o || $o['warehouse_order_id'] || !$o['client_order_id']) return;
        $it = $db->prepare('SELECT sku, name, qty, unit_price FROM client_order_items WHERE order_id = ? ORDER BY id');
        $it->execute([$o['client_order_id']]);
        $items = [];
        foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = ['code' => $r['sku'], 'desc' => $r['name'], 'qty' => (int) $r['qty'], 'priceText' => (string) (float) $r['unit_price']];
        }
        if (!$items) return;
        $order = ['order' => ['number' => $o['number'], 'client' => $o['customer_name'], 'date' => date('d/m/Y')], 'items' => $items, 'priceIsLineTotal' => false, 'docType' => 'pedido'];
        $db->prepare('INSERT INTO warehouse_orders (order_key, number, client, order_date, items_count, order_json) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([sha1('v2|' . $o['number']), $o['number'], mb_substr($o['customer_name'], 0, 255), date('d/m/Y'), count($items), json_encode($order, JSON_UNESCAPED_UNICODE)]);
        $db->prepare('UPDATE orders SET warehouse_order_id = ? WHERE id = ?')->execute([(int) $db->lastInsertId(), $id]);
    }

    /** Lo llama Pedidos bodega al crear/guardar/facturar: refleja su avance en la etapa del pedido núcleo. */
    public static function syncWarehouse(PDO $db, int $whId): void
    {
        try {
            $w = $db->prepare('SELECT * FROM warehouse_orders WHERE id = ?');
            $w->execute([$whId]);
            $w = $w->fetch(PDO::FETCH_ASSOC);
            if (!$w) return;
            $o = $db->prepare('SELECT id, stage FROM orders WHERE warehouse_order_id = ?');
            $o->execute([$whId]);
            $o = $o->fetch(PDO::FETCH_ASSOC);
            if (!$o) {
                $id = self::create($db, ['stage' => 'por_alistar', 'origin' => 'extractor', 'customer_name' => $w['client'], 'warehouse_order_id' => $whId,
                    'items_count' => $w['items_count'], 'created_by' => 'Lista Bodega']);
                $o = ['id' => $id, 'stage' => 'por_alistar'];
            }
            $to = $w['invoiced_at'] ? 'facturado' : ($w['status'] === 'listo' ? 'listo_facturar' : ((int) $w['checked_count'] > 0 ? 'en_alistamiento' : 'por_alistar'));
            $order = array_keys(self::STAGES);
            if ($o['stage'] === 'cancelado' || array_search($to, $order, true) <= array_search($o['stage'], $order, true)) {
                if ($w['total_final'] !== null) $db->prepare('UPDATE orders SET total = ? WHERE id = ?')->execute([$w['total_final'], $o['id']]);
                return;
            }
            $db->prepare('UPDATE orders SET stage = ?, stage_at = NOW(), items_count = ?, total = COALESCE(?, total) WHERE id = ?')
                ->execute([$to, $w['items_count'], $w['total_final'], $o['id']]);
            self::addHistory($db, (int) $o['id'], $o['stage'], $to, 'Desde Pedidos bodega', null);
        } catch (\Throwable $e) { /* migración 041 pendiente: no frenar bodega */ }
    }

    /** Condición SQL de lo que ve el usuario (regla 3): vendedor = sus clientes y pedidos; los demás todo. */
    public static function visibility(string $role, ?array $user, string $alias = 'o'): array
    {
        if ($role === 'vendedor' && $user) return ["($alias.seller_user_id = ?)", [(int) $user['id']]];
        return ['1=1', []];
    }
}
