<?php
/** Página pública del pedido (/pedido/{token}): resumen con fotos para el cliente. El token secreto es el edit_token del pedido (mig. 042). */
$db = getDB();
$st = $db->prepare("SELECT co.id, co.customer_name, co.customer_phone, co.city, co.address, co.pay_method, co.note, co.total, co.created_at, o.number, o.stage
    FROM client_orders co LEFT JOIN orders o ON o.client_order_id = co.id WHERE co.edit_token = ?");
$st->execute([$shopToken]);
$o = $st->fetch(PDO::FETCH_ASSOC);
$items = [];
if ($o) {
    $s = $db->prepare("SELECT i.sku, i.name, i.qty, i.unit_price, p.cover_image_url, p.category FROM client_order_items i LEFT JOIN products p ON CONVERT(p.sku USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(i.sku USING utf8mb4) COLLATE utf8mb4_unicode_ci WHERE i.order_id = ? ORDER BY " . categoryOrderSql($db, 'p.category') . ", i.id");
    $s->execute([$o['id']]);
    $items = $s->fetchAll(PDO::FETCH_ASSOC);
} else {
    http_response_code(404);
}
require_once __DIR__ . '/../../src/services/Orders.php';
$wa = 'https://wa.me/' . shopNotifyPhone() . '?text=' . rawurlencode('Hola Kino Company, consulta sobre mi pedido ' . ($o['number'] ?? '') . ': ' . publicBaseUrl() . '/pedido/' . $shopToken);
?><!DOCTYPE html>
<html lang="es" data-default-theme="light"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#fbfbfd">
<title><?= $o ? 'Pedido ' . e((string) $o['number']) : 'Pedido no encontrado' ?> · Kino Company</title>
<style>body{background:var(--color-bg,#fbfbfd);color:var(--color-text,#1d1d1f)}</style>
<script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="/assets/css/tienda.css?v=<?= @filemtime(BASE_DIR . '/assets/css/tienda.css') ?>">
</head><body class="tn">
<header class="header tn-head"><div class="header-inner tn-head-in">
    <a class="tn-brand" href="/tienda"><span class="tn-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span class="tn-name">KINO<small>COMPANY</small></span></a>
</div></header>
<main class="tn-order">
<?php if (!$o): ?>
    <h1>No encontramos este pedido</h1><p style="color:var(--color-text-muted)">Revisa el enlace que te enviamos por WhatsApp.</p>
    <p style="margin-top:1rem"><a class="tn-btn tn-btn-ghost" href="/tienda">Ir a la tienda</a></p>
<?php else: ?>
    <span class="tn-ostage"><?= e(Orders::label((string) ($o['stage'] ?? 'recibido'))) ?></span>
    <h1>Pedido <?= e((string) $o['number']) ?></h1>
    <div class="tn-meta"><span><?= e($o['customer_name']) ?> · <?= e($o['customer_phone']) ?></span><span><?= e(substr((string) $o['created_at'], 0, 10)) ?></span></div>
    <ul class="tn-ol">
    <?php $lc = null; foreach ($items as $i): $cv = parseStoredCover($i['cover_image_url'] ?? '', 400); $ic = trim((string) ($i['category'] ?? ''));
        if ($ic !== $lc): $lc = $ic; ?><li class="tn-cat" style="display:block"><?= e($ic !== '' ? $ic : 'Sin categoría') ?></li><?php endif; ?>
        <li><?= $cv['url'] !== '' ? '<img src="' . e($cv['url']) . '" alt="' . e($i['sku']) . '" loading="lazy">' : '<span></span>' ?>
            <div><b><?= e(trim(str_ireplace($i['sku'], '', (string) $i['name'])) ?: $i['sku']) ?></b><small><?= e($i['sku']) ?> · <?= (int) $i['qty'] ?> × $<?= number_format((float) $i['unit_price'], 0, ',', '.') ?></small></div>
            <b>$<?= number_format((float) $i['unit_price'] * (int) $i['qty'], 0, ',', '.') ?></b></li>
    <?php endforeach; ?>
    </ul>
    <div class="tn-otot"><span>Total</span><b>$<?= number_format((float) $o['total'], 0, ',', '.') ?></b></div>
    <?php if ($o['address'] !== ''): ?><div class="tn-ocard"><b>Entrega</b><br><?= e($o['address']) ?>, <?= e($o['city']) ?><?= $o['note'] !== '' ? '<br><small>' . e($o['note']) . '</small>' : '' ?></div><?php endif; ?>
    <div class="tn-ocard"><b>Pago</b><br><?= $o['pay_method'] === 'transferencia' ? 'Transferencia' : 'Contra entrega' ?>
        <?php if ($o['pay_method'] === 'transferencia'): ?><br><?= shopBankInfo() !== '' ? nl2br(e(shopBankInfo())) . '<br>Envía el comprobante por WhatsApp.' : 'Te enviamos los datos de pago por WhatsApp.' ?><?php endif; ?></div>
    <a class="tn-btn tn-btn-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Escribir a Kino por WhatsApp</a>
    <a class="tn-btn tn-btn-ghost" href="/tienda">Seguir comprando</a>
<?php endif; ?>
</main></body></html>
