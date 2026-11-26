<?php
/** /pedidos-clientes (solo personal): pedidos recibidos desde «Hacer pedido». Lista simple; las etapas llegan con el módulo 3. */
$db = getDB();
$orders = [];
$items = [];
try {
    $orders = $db->query("SELECT * FROM client_orders ORDER BY id DESC LIMIT 100")->fetchAll();
    if ($orders) {
        $ids = array_column($orders, 'id');
        $st = $db->prepare("SELECT * FROM client_order_items WHERE order_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $items[$r['order_id']][] = $r;
    }
} catch (\Throwable $e) { /* migración 040 pendiente */ }
?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Pedidos de clientes · Viewfinder</title>
<style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
<script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="/assets/css/make_order.css?v=<?= APP_VERSION ?>">
</head><body>
<header class="header"><div class="container header-inner">
    <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
    <div style="display:flex;gap:.4rem;align-items:center"><?= pedidosHeaderBtn() ?></div>
</div></header>
<main class="container mo-main">
    <?= breadcrumbs([['Pedidos de clientes', null]]) ?>
    <h1 class="mo-title">🛒 Pedidos de clientes</h1>
    <?php if (!$orders): ?><p class="mo-help">Todavía no hay pedidos recibidos.</p><?php endif; ?>
    <?php foreach ($orders as $o): ?>
        <details class="mo-order">
            <summary><b>#<?= (int) $o['id'] ?></b> · <?= e($o['customer_name']) ?> · <?= e($o['customer_phone']) ?>
                · <?= e(priceText($o['total'])) ?> · <span class="mo-badge"><?= e($o['status']) ?></span>
                <small><?= e($o['created_at']) ?></small></summary>
            <?php if ($o['note'] !== ''): ?><p class="mo-help">📝 <?= e($o['note']) ?></p><?php endif; ?>
            <table class="mo-table"><tr><th>Código</th><th>Nombre</th><th>Cant.</th><th>Precio c/u</th></tr>
                <?php foreach ($items[$o['id']] ?? [] as $it): ?>
                    <tr><td><a href="/producto/<?= e(rawurlencode($it['sku'])) ?>"><?= e($it['sku']) ?></a></td><td><?= e($it['name']) ?></td><td><?= (int) $it['qty'] ?></td><td><?= e(priceText($it['unit_price'])) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </details>
    <?php endforeach; ?>
</main>
</body></html>
