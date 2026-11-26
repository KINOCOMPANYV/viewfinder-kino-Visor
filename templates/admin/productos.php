<?php
$qs = fn(array $o = []) => http_build_query(array_filter($o + $filters + ['orden' => $orden], fn($v) => $v !== '' && $v !== 'codigo'));
$money = fn($n) => '$' . number_format((float) $n, 0, ',', '.');
?>
<?php
$v2 = ['nav' => 'productos', 'title' => 'Productos', 'subtitle' => 'Mostrando ' . number_format(count($products)) . ' de ' . number_format($total) . ' artículos'];
include __DIR__ . '/partials/v2_start.php';
$nFilt = count(array_filter([$filters['categoria'], $filters['marca'], $filters['stock'], $filters['estado']], fn($v) => $v !== ''));
$ico = fn($p) => '<svg viewBox="0 0 24 24" aria-hidden="true">' . $p . '</svg>';
$xlsx = '/admin/products?' . http_build_query(array_filter($filters, fn($v) => $v !== '') + ['descargar' => 'xlsx']);
?>
<form class="v2-card" method="get" id="kpForm">
    <div class="v2-bar">
        <label class="v2-search"><?= $ico('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>') ?>
            <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Buscar código, nombre, marca…" autocomplete="off"></label>
        <label class="v2-chip<?= $nFilt ? ' on' : '' ?>" for="kpFt">Filtro <?php if ($nFilt): ?><b><?= $nFilt ?></b><?php endif; ?></label>
        <span class="v2-spacer"></span>
        <a class="v2-act v2-hide-m" href="#" title="Categorías: próximamente" onclick="return false"><?= $ico('<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>') ?>Categorías</a>
        <a class="v2-act" href="<?= e($xlsx) ?>"><?= $ico('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5-5 5 5"/><path d="M12 5v12"/>') ?>Exportar</a>
        <a class="v2-btn soon" href="/admin/products" title="Crear/editar productos: por ahora en Productos v1 y la Hoja master"><?= $ico('<path d="M12 5v14M5 12h14"/>') ?>Producto</a>
    </div>
    <input type="checkbox" id="kpFt" hidden <?= $nFilt ? 'checked' : '' ?> onchange="document.getElementById('kpFl').hidden=!this.checked">
    <div class="v2-filters" id="kpFl" <?= $nFilt ? '' : 'hidden' ?>>
        <select name="categoria" onchange="this.form.submit()"><option value="">Categoría: todas</option>
            <?php foreach ($categories as $c): ?><option value="<?= e($c['category']) ?>" <?= $filters['categoria'] === $c['category'] ? 'selected' : '' ?>><?= e($c['category']) ?> (<?= (int) $c['n'] ?>)</option><?php endforeach; ?>
        </select>
        <select name="marca" onchange="this.form.submit()"><option value="">Marca: todas</option>
            <?php foreach ($brands as $b): ?><option value="<?= e($b['brand']) ?>" <?= $filters['marca'] === $b['brand'] ? 'selected' : '' ?>><?= e($b['brand']) ?> (<?= (int) $b['n'] ?>)</option><?php endforeach; ?>
        </select>
        <select name="stock" onchange="this.form.submit()"><option value="">Stock: todos</option>
            <option value="con" <?= $filters['stock'] === 'con' ? 'selected' : '' ?>>Con stock</option>
            <option value="sin" <?= $filters['stock'] === 'sin' ? 'selected' : '' ?>>Sin stock</option></select>
        <select name="estado" onchange="this.form.submit()"><option value="">Catálogo: todos</option>
            <option value="activos" <?= $filters['estado'] === 'activos' ? 'selected' : '' ?>>En el catálogo</option>
            <option value="inactivos" <?= $filters['estado'] === 'inactivos' ? 'selected' : '' ?>>Fuera del catálogo</option>
            <option value="sinfoto" <?= $filters['estado'] === 'sinfoto' ? 'selected' : '' ?>>Sin foto</option></select>
        <input type="hidden" name="orden" value="<?= e($orden) ?>">
        <a class="v2-act" href="/admin/productos">Limpiar</a>
    </div>

    <div class="v2-stats">
        <div><b><?= e($money($totals['valor'])) ?></b><span>Valor en stock</span></div>
        <div><b><?= number_format((int) $totals['con']) ?></b><span><i class="v2-dot" style="background:var(--color-success)"></i>En stock</span></div>
        <div><b><?= number_format((int) $totals['sin']) ?></b><span><i class="v2-dot" style="background:var(--color-danger)"></i>Sin stock</span></div>
        <div><b><?= number_format($total) ?></b><span>Productos en el filtro</span></div>
    </div>

    <div style="overflow-x:auto">
    <table class="v2-table">
        <thead><tr>
            <th><a class="<?= in_array($orden, ['nombre', 'codigo'], true) ? 'on' : '' ?>" href="?<?= e($qs(['orden' => 'nombre'])) ?>">Producto</a> <a class="<?= $orden === 'codigo' ? 'on' : '' ?>" href="?<?= e($qs(['orden' => 'codigo'])) ?>" title="Ordenar por código" style="font-weight:400">· código</a></th>
            <th class="v2-hide-m">Categoría</th>
            <th><a class="<?= $orden === 'stock' ? 'on' : '' ?>" href="?<?= e($qs(['orden' => 'stock'])) ?>">Stock</a></th>
            <th><a class="<?= $orden === 'precio' ? 'on' : '' ?>" href="?<?= e($qs(['orden' => 'precio'])) ?>">Precio</a></th>
            <th>Catálogo</th>
        </tr></thead>
        <tbody>
        <?php foreach ($products as $p): $cover = parseStoredCover($p['cover_image_url'], 120); $s = $p['stock_n']; ?>
            <tr data-id="<?= (int) $p['id'] ?>" class="<?= $p['is_active'] ? '' : 'off' ?>">
                <td><div class="v2-prod">
                    <?php if ($cover['url']): ?><img class="v2-thumb" src="<?= e($cover['url']) ?>" alt="" loading="lazy" onerror="this.outerHTML='<div class=\'v2-thumb\'>📷</div>'"><?php else: ?><div class="v2-thumb">📷</div><?php endif; ?>
                    <div style="min-width:0"><div class="v2-name"><?= e($p['name'] !== '' ? $p['name'] : $p['sku']) ?></div>
                        <a class="v2-code" href="/producto/<?= urlencode($p['sku']) ?>" target="_blank">Cód.: <?= e($p['sku']) ?></a></div></div></td>
                <td class="v2-cat v2-hide-m"><?= e($p['category']) ?></td>
                <td class="<?= $s !== null && (float) $s <= 0 ? 'v2-out' : '' ?>"><?= $s === null ? '—' : (int) $s ?></td>
                <td><?= (float) $p['price_suggested'] > 0 ? e($money($p['price_suggested'])) : '—' ?></td>
                <td><div class="v2-swc"><label class="toggle-switch" title="<?= $p['is_active'] ? 'En el catálogo' : 'Fuera del catálogo' ?>">
                    <input type="checkbox" <?= $p['is_active'] ? 'checked' : '' ?> onchange="kpToggle(this)"><span class="toggle-slider"></span></label>
                    <span class="v2-sw"><span class="t <?= $p['is_active'] ? 'on-t' : '' ?>"><?= $p['is_active'] ? 'Sí' : 'No' ?></span></span></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--color-text-muted);">Sin resultados.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <div class="v2-pager">
        <?php if ($page > 1): ?><a href="?<?= e($qs(['page' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
        <span>Página <?= $page ?> de <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a href="?<?= e($qs(['page' => $page + 1])) ?>">Siguiente →</a><?php endif; ?>
    </div>
</form>
<script>
const CSRF = '<?= csrfToken() ?>';
async function kpToggle(cb) {
    const row = cb.closest('tr'), on = cb.checked, body = new FormData();
    body.append('active', on ? '1' : '0'); body.append('ids', row.dataset.id);
    try {
        const r = await fetch('/admin/products/active', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF }, body });
        if (!r.ok) throw new Error();
        row.classList.toggle('off', !on);
        const t = row.querySelector('.t'); t.textContent = on ? 'Sí' : 'No'; t.classList.toggle('on-t', on);
    } catch (e) { cb.checked = !on; alert('No se pudo cambiar. Intenta de nuevo.'); }
}
</script>
<?php include __DIR__ . '/partials/v2_end.php'; ?>
