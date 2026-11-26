<?php
$adminNav = 'productos';
$qs = fn(array $o = []) => http_build_query(array_filter($o + $filters + ['orden' => $orden], fn($v) => $v !== '' && $v !== 'codigo'));
$money = fn($n) => '$' . number_format((float) $n, 0, ',', '.');
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos · Viewfinder Admin</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .kp-totals { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.6rem; margin:1rem 0; }
        .kp-totals div { background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-control,8px); padding:.6rem .8rem; }
        .kp-totals b { display:block; font-size:1.15rem; } .kp-totals span { font-size:.78rem; color:var(--color-text-muted); }
        .kp-filters { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
        .kp-filters .form-input { width:auto; padding:.5rem .75rem; font-size:.85rem; }
        .kp-table { width:100%; border-collapse:collapse; font-size:.88rem; }
        .kp-table th, .kp-table td { padding:.5rem .6rem; border-bottom:1px solid var(--color-border); text-align:left; vertical-align:middle; }
        .kp-table th a { color:var(--color-text-muted); text-decoration:none; } .kp-table th a.on { color:var(--color-primary); }
        .kp-table .num { text-align:right; white-space:nowrap; } .kp-table .ctr { text-align:center; }
        .kp-thumb { width:56px; height:56px; border-radius:8px; object-fit:contain; background:var(--color-surface-2); display:flex; align-items:center; justify-content:center; }
        .kp-table tr.off td { opacity:.55; } .kp-table tr.off td.ctr { opacity:1; }
        .kp-n { font-weight:600; } .kp-code { font-family:monospace; color:var(--color-text-muted); font-size:.8rem; }
        .kp-out { color:var(--color-danger,#f87171); } .kp-pager { display:flex; gap:.5rem; align-items:center; justify-content:center; margin:1rem 0; }
        @media (max-width:700px) { .kp-table .hide-m { display:none; } }
    </style>
</head>
<body>
<div class="container" style="padding-top:1rem;">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <h1 style="font-size:1.3rem;">Productos</h1>

    <div class="kp-totals">
        <div><b><?= number_format($total) ?></b><span>productos en el filtro</span></div>
        <div><b><?= number_format((int) $totals['con']) ?></b><span>en stock</span></div>
        <div><b><?= number_format((int) $totals['sin']) ?></b><span>sin stock</span></div>
        <div><b><?= e($money($totals['valor'])) ?></b><span>valor en stock (stock × precio)</span></div>
    </div>

    <form class="kp-filters" method="get">
        <input class="form-input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Buscar código, nombre, marca…" style="min-width:220px;">
        <select class="form-input" name="categoria"><option value="">Categoría: todas</option>
            <?php foreach ($categories as $c): ?><option value="<?= e($c['category']) ?>" <?= $filters['categoria'] === $c['category'] ? 'selected' : '' ?>><?= e($c['category']) ?> (<?= (int) $c['n'] ?>)</option><?php endforeach; ?>
        </select>
        <select class="form-input" name="marca"><option value="">Marca: todas</option>
            <?php foreach ($brands as $b): ?><option value="<?= e($b['brand']) ?>" <?= $filters['marca'] === $b['brand'] ? 'selected' : '' ?>><?= e($b['brand']) ?> (<?= (int) $b['n'] ?>)</option><?php endforeach; ?>
        </select>
        <select class="form-input" name="stock"><option value="">Stock: todos</option>
            <option value="con" <?= $filters['stock'] === 'con' ? 'selected' : '' ?>>Con stock</option>
            <option value="sin" <?= $filters['stock'] === 'sin' ? 'selected' : '' ?>>Sin stock</option></select>
        <select class="form-input" name="estado"><option value="">Catálogo: todos</option>
            <option value="activos" <?= $filters['estado'] === 'activos' ? 'selected' : '' ?>>En el catálogo</option>
            <option value="inactivos" <?= $filters['estado'] === 'inactivos' ? 'selected' : '' ?>>Fuera del catálogo</option>
            <option value="sinfoto" <?= $filters['estado'] === 'sinfoto' ? 'selected' : '' ?>>Sin foto</option></select>
        <input type="hidden" name="orden" value="<?= e($orden) ?>">
        <button class="btn btn-primary" type="submit">Filtrar</button>
        <a class="btn" href="/admin/productos">Limpiar</a>
        <a class="btn" href="/admin/products?<?= e(http_build_query(array_filter($filters, fn($v) => $v !== '') + ['descargar' => 'xlsx'])) ?>">⬇ Excel</a>
    </form>

    <table class="kp-table">
        <thead><tr>
            <th>Foto</th>
            <th><a class="<?= $orden === 'nombre' ? 'on' : '' ?>" href="?<?= e($qs(['orden' => 'nombre'])) ?>">Nombre</a> / <a class="<?= $orden === 'codigo' ? 'on' : '' ?>" href="?<?= e($qs(['orden' => 'codigo'])) ?>">Código</a></th>
            <th class="hide-m">Categoría</th>
            <th class="num"><a class="<?= $orden === 'stock' ? 'on' : '' ?>" href="?<?= e($qs(['orden' => 'stock'])) ?>">Stock</a></th>
            <th class="num"><a class="<?= $orden === 'precio' ? 'on' : '' ?>" href="?<?= e($qs(['orden' => 'precio'])) ?>">Precio</a></th>
            <th class="ctr">Catálogo</th>
        </tr></thead>
        <tbody>
        <?php foreach ($products as $p): $cover = parseStoredCover($p['cover_image_url'], 120); $s = $p['stock_n']; ?>
            <tr data-id="<?= (int) $p['id'] ?>" class="<?= $p['is_active'] ? '' : 'off' ?>">
                <td><?php if ($cover['url']): ?><img class="kp-thumb" src="<?= e($cover['url']) ?>" alt="" loading="lazy" onerror="this.outerHTML='<div class=\'kp-thumb\'>📷</div>'"><?php else: ?><div class="kp-thumb">📷</div><?php endif; ?></td>
                <td><div class="kp-n"><?= e($p['name'] !== '' ? $p['name'] : $p['sku']) ?></div>
                    <a class="kp-code" href="/producto/<?= urlencode($p['sku']) ?>" target="_blank"><?= e($p['sku']) ?></a></td>
                <td class="hide-m"><?= e($p['category']) ?></td>
                <td class="num <?= $s !== null && (float) $s <= 0 ? 'kp-out' : '' ?>"><?= $s === null ? '—' : (int) $s ?></td>
                <td class="num"><?= (float) $p['price_suggested'] > 0 ? e($money($p['price_suggested'])) : '—' ?></td>
                <td class="ctr"><label class="toggle-switch" title="<?= $p['is_active'] ? 'En el catálogo' : 'Fuera del catálogo' ?>">
                    <input type="checkbox" <?= $p['is_active'] ? 'checked' : '' ?> onchange="kpToggle(this)"><span class="toggle-slider"></span></label></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--color-text-muted);">Sin resultados.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <div class="kp-pager">
        <?php if ($page > 1): ?><a class="btn" href="?<?= e($qs(['page' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
        <span>Página <?= $page ?> de <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a class="btn" href="?<?= e($qs(['page' => $page + 1])) ?>">Siguiente →</a><?php endif; ?>
    </div>
</div>
<script>
const CSRF = '<?= csrfToken() ?>';
async function kpToggle(cb) {
    const row = cb.closest('tr'), on = cb.checked, body = new FormData();
    body.append('active', on ? '1' : '0'); body.append('ids', row.dataset.id);
    try {
        const r = await fetch('/admin/products/active', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF }, body });
        if (!r.ok) throw new Error();
        row.classList.toggle('off', !on);
    } catch (e) { cb.checked = !on; alert('No se pudo cambiar. Intenta de nuevo.'); }
}
</script>
</body>
</html>
