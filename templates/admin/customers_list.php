<?php include __DIR__ . '/partials/v2_start.php'; ?>
<?php $fl = $_SESSION['orders_flash'] ?? null; unset($_SESSION['orders_flash']); if ($fl): ?><p class="v2-card" style="padding:.8rem 1rem;color:var(--color-<?= $fl[0] === 'ok' ? 'success' : 'danger' ?>)"><?= e($fl[1]) ?></p><?php endif; ?>
<?php if (!$tableOk): ?><p class="v2-card" style="padding:1rem">Falta aplicar la migración 041.</p><?php endif; ?>
<div class="v2-card">
    <form class="v2-bar" method="get" action="/clientes"><div class="v2-search"><input name="q" value="<?= e($q) ?>" placeholder="Nombre o teléfono"></div></form>
    <table class="v2-table"><thead><tr><th>Cliente</th><th>WhatsApp</th><th>Pedidos</th><th class="v2-hide-m">Comprado</th><th class="v2-hide-m">Último</th><th>Vendedor</th></tr></thead><tbody>
    <?php foreach ($rows as $c): ?><tr>
        <td><b><?= e($c['name'] ?: '—') ?></b></td>
        <td><a href="https://wa.me/<?= e(preg_replace('/\D+/', '', $c['phone'])) ?>" target="_blank" rel="noopener"><?= e($c['phone']) ?></a></td>
        <td><a href="/pedidos?q=<?= urlencode($c['phone']) ?>"><?= (int) $c['n'] ?></a></td>
        <td class="v2-hide-m"><?= (float) $c['tot'] > 0 ? e(priceText($c['tot'])) : '—' ?></td>
        <td class="v2-hide-m"><?= e(substr((string) $c['last_at'], 0, 10)) ?></td>
        <td><?php if ($canAssign): ?><form method="post" action="/clientes"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <select name="seller" onchange="this.form.submit()" style="padding:.35rem;border-radius:8px;border:1px solid var(--color-border-hover);background:var(--color-bg);color:inherit"><option value="0">— Sin asignar —</option>
            <?php foreach ($sellers as $sid => $sn): ?><option value="<?= (int) $sid ?>"<?= (int) $c['seller_user_id'] === (int) $sid ? ' selected' : '' ?>><?= e($sn) ?></option><?php endforeach; ?></select></form>
        <?php else: ?><?= e($sellers[$c['seller_user_id']] ?? '—') ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--color-text-muted)">Sin clientes todavía.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php include __DIR__ . '/partials/v2_end.php'; ?>
