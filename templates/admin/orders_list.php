<?php include __DIR__ . '/partials/v2_start.php'; ?>
<?php if (!$tableOk): ?><p class="v2-card" style="padding:1rem">Falta aplicar la migración 041 (se aplica sola al desplegar).</p><?php endif; ?>
<div class="v2-card">
    <form class="v2-bar" method="get" action="/pedidos">
        <div class="v2-search"><input name="q" value="<?= e($q) ?>" placeholder="Número, cliente o teléfono"><?php if ($stage !== ''): ?><input type="hidden" name="etapa" value="<?= e($stage) ?>"><?php endif; ?></div>
        <?php if ($mineN > 0 || $mine): ?><a class="v2-chip v2-mine<?= $mine ? ' on' : '' ?>" href="/pedidos<?= $mine ? '' : '?mio=1' ?>">⚡ Me toca a mí <b><?= (int) $mineN ?></b></a><?php endif; ?>
        <a class="v2-chip<?= $stage === '' && !$mine ? ' on' : '' ?>" href="/pedidos<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">Todos <b><?= array_sum($counts) ?></b></a>
        <?php foreach (Orders::STAGES as $k => $lbl): ?>
            <a class="v2-chip<?= $stage === $k ? ' on' : '' ?>" href="/pedidos?etapa=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= e($lbl) ?><?php if (!empty($counts[$k])): ?> <b><?= (int) $counts[$k] ?></b><?php endif; ?></a>
        <?php endforeach; ?>
        <span class="v2-spacer"></span>
        <a class="v2-btn" href="/vender">+ Nuevo pedido</a>
        <a class="v2-act" href="/pedidos-alistar">Checklist de bodega</a>
    </form>
    <table class="v2-table">
        <thead><tr><th>Número</th><th>Cliente</th><th class="v2-hide-m">Origen</th><th>Etapa</th><th>Siguiente paso</th><th class="v2-hide-m">Ítems</th><th>Total</th><th class="v2-hide-m">Fecha</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $o): ?>
            <tr onclick="location.href='/pedidos/<?= (int) $o['id'] ?>'" style="cursor:pointer">
                <td><a href="/pedidos/<?= (int) $o['id'] ?>"><b><?= e($o['number']) ?></b></a></td>
                <td><?= e($o['customer_name'] ?: '—') ?><br><small class="v2-code"><?= e($o['customer_phone']) ?></small></td>
                <td class="v2-hide-m"><?= e(['cliente' => 'Cliente', 'extractor' => 'Lista Bodega', 'vendedor' => 'Vendedor'][$o['origin']] ?? $o['origin']) ?></td>
                <?php [$ageTxt, $ageLvl] = Orders::age($o['stage_at'] ?? $o['created_at']); $open = !in_array($o['stage'], ['facturado', 'cancelado'], true); $na = $open ? Orders::nextAction($role, $o['stage']) : null; ?>
                <td><span class="v2-stage st-<?= e($o['stage']) ?>"><?= e(Orders::label($o['stage'])) ?></span><?php if ($open && $ageTxt !== ''): ?><br><small class="v2-age lvl<?= $ageLvl ?>"><?= $ageLvl ? '⏱ ' : '' ?><?= e($ageTxt) ?></small><?php endif; ?></td>
                <td onclick="event.stopPropagation()"><?php if (!$open): ?><small class="v2-code">—</small>
                    <?php elseif ($na && isset($na['move'])): ?><form method="post" action="/pedidos/<?= (int) $o['id'] ?>"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="to" value="<?= e($na['move']) ?>"><input type="hidden" name="back" value="lista"><button class="v2-btn" type="submit"><?= e($na['label']) ?></button></form>
                    <?php elseif ($na): ?><a class="v2-btn" href="/pedidos/<?= (int) $o['id'] ?><?= $na['go'] ?>"><?= e($na['label']) ?></a>
                    <?php else: ?><small class="v2-code">Espera a: <?= e(Orders::TURN[$o['stage']] ?? '') ?></small><?php endif; ?></td>
                <td class="v2-hide-m"><?= (int) $o['items_count'] ?></td>
                <td><?= (float) $o['total'] > 0 ? e(priceText($o['total'])) : '—' ?></td>
                <td class="v2-hide-m"><?= e(substr($o['created_at'], 0, 16)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--color-text-muted)">No hay pedidos<?= $stage !== '' || $q !== '' ? ' con ese filtro' : ' todavía' ?>.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/partials/v2_end.php'; ?>
