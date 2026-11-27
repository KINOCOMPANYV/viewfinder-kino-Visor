<?php include __DIR__ . '/partials/v2_start.php'; ?>
<?php if (!$cust): ?>
    <p class="v2-card" style="padding:1rem">Ese cliente no existe o no es tuyo. <a href="/clientes">Volver a Clientes</a></p>
<?php else: $bal = (float) $cust['balance']; $dd = (int) $cust['debt_days']; $wa = preg_replace('/\D+/', '', $cust['phone']); if (strlen($wa) === 10) $wa = '57' . $wa; ?>
    <div class="v2-card" style="padding:1rem 1.25rem;margin-bottom:1rem">
        <div style="display:flex;gap:.8rem;flex-wrap:wrap;align-items:center">
            <b style="font-size:1.15rem"><?= e($cust['name'] ?: 'Sin nombre') ?></b>
            <?php if ($wa !== ''): ?><a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener">💬 <?= e($cust['phone']) ?></a><?php endif; ?>
            <small class="v2-code">Vendedor: <?= e($cust['seller_name'] ?: '—') ?></small>
            <span class="v2-spacer" style="flex:1"></span>
            <a class="v2-btn" href="/vender?nombre=<?= urlencode((string) $cust['name']) ?>&tel=<?= urlencode((string) $cust['phone']) ?>">+ Nuevo pedido</a>
            <a class="v2-act" href="/clientes">← Clientes</a>
        </div>
        <p style="margin:.8rem 0 0">
            <?php if ($bal > 0): ?><span class="cli-debt cli-debt--owes">Debe <?= e(priceText($bal)) ?></span><?php if ($dd > 20): ?> <span class="cli-age cli-age--<?= $dd > 40 ? '40' : '20' ?>"><?= $dd > 40 ? '‼' : '⚠' ?> <?= $dd ?> días</span><?php endif; ?>
            <?php else: ?><span class="cli-debt cli-debt--ok">Al día</span><?php endif; ?>
            <?php if ($cust['balance_note'] !== ''): ?> <small class="v2-code"><?= e($cust['balance_note']) ?></small><?php endif; ?>
        </p>
        <p style="margin:.8rem 0 0;display:flex;gap:1.5rem;flex-wrap:wrap">
            <span><small class="v2-code">Pedidos</small><br><b><?= (int) $stats['n'] ?></b></span>
            <span><small class="v2-code">Comprado</small><br><b><?= $stats['tot'] > 0 ? e(priceText($stats['tot'])) : '—' ?></b></span>
            <span><small class="v2-code">Pedido promedio</small><br><b><?= $stats['avg'] > 0 ? e(priceText($stats['avg'])) : '—' ?></b></span>
            <span><small class="v2-code">Último pedido</small><br><b><?= e($stats['last'] ?: '—') ?></b></span>
        </p>
    </div>
    <?php if ($top): ?>
    <div class="v2-card" style="padding:1rem 1.25rem;margin-bottom:1rem"><b>Lo que más compra</b>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:.6rem;margin-top:.7rem">
        <?php foreach ($top as $t): ?>
            <a href="/producto/<?= e(rawurlencode($t['sku'])) ?>" style="display:flex;gap:.6rem;align-items:center;text-decoration:none;color:inherit">
                <?php if (!empty($t['cover'])): $cv = parseStoredCover($t['cover'], 200); ?><img class="v2-thumb" loading="lazy" src="<?= e($cv['url']) ?>" alt=""><?php endif; ?>
                <span><b><?= e($t['sku']) ?></b><br><small class="v2-code"><?= (int) $t['qty'] ?> uds · <?= (int) $t['veces'] ?> <?= (int) $t['veces'] === 1 ? 'pedido' : 'pedidos' ?></small></span></a>
        <?php endforeach; ?></div></div>
    <?php endif; ?>
    <div class="v2-card"><table class="v2-table"><thead><tr><th>Pedido</th><th>Etapa</th><th class="v2-hide-m">Ítems</th><th>Total</th><th class="v2-hide-m">Fecha</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?><tr onclick="location.href='/pedidos/<?= (int) $o['id'] ?>'" style="cursor:pointer">
            <td><a href="/pedidos/<?= (int) $o['id'] ?>"><b><?= e($o['number']) ?></b></a></td>
            <td><span class="v2-stage st-<?= e($o['stage']) ?>"><?= e(Orders::label($o['stage'])) ?></span></td>
            <td class="v2-hide-m"><?= (int) $o['items_count'] ?></td>
            <td><?= (float) $o['total'] > 0 ? e(priceText($o['total'])) : '—' ?></td>
            <td class="v2-hide-m"><?= e(substr($o['created_at'], 0, 16)) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--color-text-muted)">Aún no tiene pedidos.</td></tr><?php endif; ?>
    </tbody></table></div>
<?php endif; ?>
<?php include __DIR__ . '/partials/v2_end.php'; ?>
