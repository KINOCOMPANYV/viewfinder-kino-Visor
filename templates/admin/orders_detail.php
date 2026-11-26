<?php include __DIR__ . '/partials/v2_start.php'; ?>
<?php if (!$order): ?>
    <p class="v2-card" style="padding:1rem">Ese pedido no existe o no es de tus clientes. <a href="/pedidos">Volver</a></p>
<?php else: $next = Orders::nextStages($order['stage']); $csrf = csrfToken(); ?>
    <?php if ($flash): ?><p class="v2-card" style="padding:.8rem 1rem;color:var(--color-<?= $flash[0] === 'ok' ? 'success' : 'danger' ?>)"><?= e($flash[1]) ?></p><?php endif; ?>
    <div class="v2-card" style="padding:1rem 1.25rem;margin-bottom:1rem">
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
            <span class="v2-stage st-<?= e($order['stage']) ?>"><?= e(Orders::label($order['stage'])) ?></span>
            <b><?= e($order['customer_name'] ?: 'Sin nombre') ?></b>
            <?php if ($order['customer_phone'] !== ''): ?><a href="https://wa.me/<?= e(preg_replace('/\D+/', '', $order['customer_phone'])) ?>" target="_blank" rel="noopener">💬 <?= e($order['customer_phone']) ?></a><?php endif; ?>
            <?php if ($order['client_order_id']) { $tk = getDB()->prepare('SELECT edit_token FROM client_orders WHERE id = ?'); $tk->execute([$order['client_order_id']]); $tk = (string) $tk->fetchColumn();
                if ($tk !== '' && $order['customer_phone'] !== ''): $cp = preg_replace('/\D+/', '', $order['customer_phone']); if (strlen($cp) === 10) $cp = '57' . $cp; ?>
                <a class="v2-act" target="_blank" rel="noopener" href="https://wa.me/<?= e($cp) ?>?text=<?= rawurlencode('Hola ' . $order['customer_name'] . ', recibimos tu pedido ' . $order['number'] . ' en Kino Company. Aquí están tus productos con fotos: ' . publicBaseUrl() . '/pedido/' . $tk) ?>">📲 Enviar resumen con fotos por WhatsApp</a>
            <?php endif; } ?>
            <small class="v2-code">Origen: <?= e($order['origin']) ?> · <?= e(substr($order['created_at'], 0, 16)) ?></small>
            <?php if ($order['warehouse_order_id']): ?><a class="v2-act" href="/pedido-bodega?id=<?= (int) $order['warehouse_order_id'] ?>">📦 Abrir checklist de bodega</a><?php endif; ?>
        </div>
        <?php if ($order['note'] !== ''): ?><p style="margin:.7rem 0 0">📝 <?= e($order['note']) ?></p><?php endif; ?>
        <?php $pct = (float) $order['discount_pct']; $total = (float) $order['total']; ?>
        <p style="margin:.7rem 0 0"><b>Total <?= $total > 0 ? e(priceText($total * (1 - $pct / 100))) : '—' ?></b>
            <?php if ($pct > 0): ?><small class="v2-code"> (con <?= e(rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.')) ?>% de descuento; sin descuento <?= e(priceText($total)) ?>)</small><?php endif; ?></p>
        <?php if ($next): ?>
        <form method="post" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.9rem;align-items:center">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="move">
            <input name="note" placeholder="Nota (obligatoria para cancelar)" style="flex:1 1 240px;padding:.55rem .7rem;border-radius:8px;border:1px solid var(--color-border-hover);background:var(--color-bg);color:inherit">
            <?php foreach ($next as $to): if (!Orders::canMove($role, $to)) continue; if ($to === 'listo_facturar') continue; /* lo pasa solo la bodega al finalizar el alistamiento */ ?>
                <button class="v2-btn" name="to" value="<?= e($to) ?>" <?= $to === 'cancelado' ? 'style="background:var(--color-danger);color:#fff" onclick="return this.form.note.value.trim()!==\'\'||(alert(\'Escribe el motivo\'),false)"' : '' ?>><?= $to === 'cancelado' ? 'Cancelar pedido' : 'Pasar a «' . e(Orders::label($to)) . '»' ?></button>
            <?php endforeach; ?>
        </form>
        <form method="post" style="display:flex;gap:.5rem;margin-top:.6rem;align-items:center">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="discount">
            <label>Descuento %</label><input name="pct" value="<?= e((string) $pct) ?>" size="4" inputmode="decimal" style="padding:.45rem;border-radius:8px;border:1px solid var(--color-border-hover);background:var(--color-bg);color:inherit">
            <button class="v2-chip" type="submit">Aplicar</button><small class="v2-code">Vendedor: hasta <?= (int) Orders::MAX_SELLER_DISCOUNT ?>%</small>
        </form>
        <?php endif; ?>
    </div>
    <?php if (!empty($whData)): $closed = in_array($order['stage'], ['cancelado'], true); ?>
    <div class="v2-card" style="padding:1rem 1.25rem;margin-bottom:1rem" id="alistar" data-wh='<?= e(json_encode($whData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS)) ?>' data-csrf="<?= e($csrf) ?>" data-readonly="<?= ($whData['status'] === 'listo' || $closed || !in_array($order['stage'], ['por_alistar', 'en_alistamiento'], true)) ? '1' : '0' ?>">
        <b>📦 Alistamiento</b> <small class="v2-code">obligatorio antes de facturar</small>
        <div id="alMsg" class="v2-code" style="margin:.4rem 0"></div><div id="alList"></div>
        <div id="alFoot" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin-top:.8rem"></div>
    </div>
    <script src="/assets/js/v2_alistar.js?v=<?= @filemtime(BASE_DIR . '/assets/js/v2_alistar.js') ?>" defer></script>
    <?php elseif (in_array($order['stage'], ['recibido'], true)): ?>
    <p class="v2-card" style="padding:.8rem 1rem">Al pasar a «Por alistar» se arma aquí la lista de alistamiento para la bodega.</p>
    <?php endif; ?>
    <?php if ($items): ?>
    <div class="v2-card" style="margin-bottom:1rem"><table class="v2-table"><thead><tr><th>Producto</th><th>Cant.</th><th>Precio c/u</th></tr></thead><tbody>
        <?php foreach ($items as $it): ?><tr>
            <td><div class="v2-prod"><?php if (!empty($it['cover_image_url'])): $cv = parseStoredCover($it["cover_image_url"], 200); ?><img class="v2-thumb" loading="lazy" src="<?= e($cv["url"]) ?>" alt=""><?php endif; ?>
                <div><div class="v2-name"><?= e($it['name']) ?></div><a class="v2-code" href="/producto/<?= e(rawurlencode($it['sku'])) ?>"><?= e($it['sku']) ?></a></div></div></td>
            <td><?= (int) $it['qty'] ?></td><td><?= e(priceText($it['unit_price'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <div class="v2-card" style="padding:1rem 1.25rem"><b>Historial</b>
        <ul class="v2-hist"><?php foreach ($history as $h): ?>
            <li><span class="v2-code"><?= e(substr($h['created_at'], 0, 16)) ?></span> · <?= $h['stage_from'] !== '' && $h['stage_from'] !== $h['stage_to'] ? e(Orders::label($h['stage_from'])) . ' → ' : '' ?><b><?= e(Orders::label($h['stage_to'])) ?></b>
                <?php if ($h['user_name'] !== ''): ?> · <?= e($h['user_name']) ?><?php endif; ?><?php if ($h['note'] !== ''): ?> — <?= e($h['note']) ?><?php endif; ?></li>
        <?php endforeach; ?></ul></div>
<?php endif; ?>
<?php include __DIR__ . '/partials/v2_end.php'; ?>
