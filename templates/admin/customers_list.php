<?php include __DIR__ . '/partials/v2_start.php'; ?>
<?php if (!$tableOk): ?><p class="v2-card" style="padding:1rem">Falta aplicar la migración 041.</p><?php endif; ?>
<div class="v2-card">
    <form class="v2-bar" method="get" action="/clientes"><div class="v2-search"><input name="q" value="<?= e($q) ?>" placeholder="Nombre o teléfono"></div></form>
    <table class="v2-table"><thead><tr><th>Cliente</th><th>WhatsApp</th><th>Pedidos</th><th class="v2-hide-m">Comprado</th><th class="v2-hide-m">Último</th></tr></thead><tbody>
    <?php foreach ($rows as $c): ?><tr>
        <td><b><?= e($c['name'] ?: '—') ?></b></td>
        <td><a href="https://wa.me/<?= e(preg_replace('/\D+/', '', $c['phone'])) ?>" target="_blank" rel="noopener"><?= e($c['phone']) ?></a></td>
        <td><a href="/pedidos?q=<?= urlencode($c['phone']) ?>"><?= (int) $c['n'] ?></a></td>
        <td class="v2-hide-m"><?= (float) $c['tot'] > 0 ? e(priceText($c['tot'])) : '—' ?></td>
        <td class="v2-hide-m"><?= e(substr((string) $c['last_at'], 0, 10)) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--color-text-muted)">Sin clientes todavía.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php include __DIR__ . '/partials/v2_end.php'; ?>
