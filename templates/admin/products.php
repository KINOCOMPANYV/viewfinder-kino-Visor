<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos · Viewfinder Admin</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .prod-filters { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem; }
        .prod-filters .form-input { padding:0.5rem 0.75rem; font-size:0.85rem; width:auto; }
        .prod-tabs { display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:1rem; }
        .prod-tabs a { padding:0.35rem 0.8rem; border-radius:999px; font-size:0.8rem; border:1px solid var(--color-border); color:var(--color-text-muted); text-decoration:none; }
        .prod-tabs a.active, .prod-tabs a:hover { border-color:var(--color-primary); color:var(--color-primary); }
        .bulk-bar { position:sticky; top:0; z-index:5; display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;
            padding:0.6rem 0.9rem; margin-bottom:0.75rem; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius); font-size:0.85rem; }
        .bulk-bar .muted { color:var(--color-text-muted); }
        .bulk-bar .link { background:none; border:none; color:var(--color-primary); cursor:pointer; text-decoration:underline; font-size:0.85rem; padding:0; }
        .prod-thumb { width:44px; height:44px; border-radius:6px; object-fit:cover; background:var(--color-surface-2); display:flex; align-items:center; justify-content:center; }
        tr.is-off td { opacity:0.55; }
        tr.is-off td:first-child, tr.is-off td:last-child { opacity:1; }
        .tag-out { font-size:0.7rem; color:#fbbf24; }
        .cell-muted { color:var(--color-text-muted); font-size:0.8rem; }
        .prod-table th, .prod-table td { padding:0.5rem 0.6rem; vertical-align:middle; }
        .only-mobile { display:none; }
        /* Móvil: cada fila es una tarjeta (casilla · foto · código/nombre · interruptor) */
        @media (max-width: 900px) {
            .prod-filters .form-input { flex:1 1 100%; width:100%; min-width:0 !important; font-size:16px; }
            .prod-filters .btn { flex:1; min-height:44px; justify-content:center; }
            .bulk-bar { top:var(--header-h, 56px); }
            .bulk-bar .bulk-spacer { display:none; }
            .bulk-bar .btn { flex:1; min-height:44px; justify-content:center; }
            .bulk-bar #bulkCount { flex-basis:100%; }
            .prod-tabs a { padding:0.55rem 0.9rem; }
            .prod-table thead tr { display:flex; align-items:center; gap:0.5rem; }
            .prod-table thead th { display:none; }
            .prod-table thead th:first-child { display:block; background:none; }
            .prod-table thead th:first-child::after { content:' Marcar esta página'; font-size:0.8rem; text-transform:none; letter-spacing:0; font-weight:500; }
            .prod-table tbody tr { display:grid; grid-template-columns:28px 48px minmax(0,1fr) auto; column-gap:0.6rem; align-items:center;
                padding:0.6rem 0.5rem; border-top:1px solid var(--color-border); }
            .prod-table tbody td { padding:0; border:none; }
            .prod-table .c-check { grid-row:1 / span 2; }
            .prod-table .c-thumb { grid-row:1 / span 2; }
            .prod-table .c-sku { grid-column:3; grid-row:1; }
            .prod-table .c-name { grid-column:3; grid-row:2; font-size:0.85rem; line-height:1.3; }
            .prod-table .c-active { grid-column:4; grid-row:1 / span 2; }
            .prod-table .c-meta { display:none; }
            .prod-table .only-mobile { display:block; margin-top:0.15rem; font-size:0.75rem; }
            .prod-table input[type=checkbox] { width:22px; height:22px; }
            .prod-thumb { width:48px; height:48px; }
        }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">VF</span> Viewfinder Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem; padding-bottom:3rem;">
        <?php $adminNav = 'products'; include __DIR__ . '/partials/nav.php'; ?>

        <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
            <h1 style="font-size:1.5rem;">Productos</h1>
            <span class="cell-muted">Los datos vienen de la hoja maestra (<a href="/admin/sync" style="color:var(--color-primary);">sincronizar</a>). Aquí solo se activa o desactiva.</span>
        </div>

        <?php
        $baseFilters = array_filter($filters, fn($v) => $v !== '');
        $tabUrl = fn(string $estado) => '/admin/products?' . http_build_query(array_filter(['estado' => $estado] + $baseFilters, fn($v) => $v !== ''));
        $tabs = [
            '' => ['Todos', $summary['total']],
            'activos' => ['Visibles', $summary['visibles']],
            'inactivos' => ['Desactivados', $summary['inactivos']],
            'fuera' => ['Fuera de la hoja', $summary['fuera']],
            'sinfoto' => ['Sin foto', null],
        ];
        ?>
        <div class="prod-tabs">
            <?php foreach ($tabs as $key => [$label, $n]): ?>
                <a href="<?= e($tabUrl($key)) ?>" class="<?= $filters['estado'] === $key ? 'active' : '' ?>">
                    <?= $label ?><?= $n !== null ? ' (' . number_format((int) $n) . ')' : '' ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form action="/admin/products" method="GET" class="prod-filters">
            <input type="hidden" name="estado" value="<?= e($filters['estado']) ?>">
            <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="Código o nombre..." class="form-input" style="min-width:200px;">
            <select name="marca" class="form-input" onchange="this.form.submit()">
                <option value="">Todas las marcas</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= e($b['brand']) ?>" <?= $filters['marca'] === $b['brand'] ? 'selected' : '' ?>><?= e($b['brand']) ?> (<?= $b['n'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <select name="categoria" class="form-input" onchange="this.form.submit()">
                <option value="">Todas las categorías</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c['category']) ?>" <?= $filters['categoria'] === $c['category'] ? 'selected' : '' ?>><?= e($c['category']) ?> (<?= $c['n'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary">Buscar</button>
            <?php if ($baseFilters): ?><a href="/admin/products" class="btn btn-sm btn-secondary">Limpiar</a><?php endif; ?>
        </form>

        <?php if (empty($products)): ?>
            <div class="empty-state">
                <div class="empty-icon">📦</div>
                <h3>Sin productos</h3>
                <p><?= $summary['total'] ? 'Ningún producto coincide con el filtro.' : 'Sincroniza la hoja maestra para traer el catálogo.' ?></p>
                <?php if (!$summary['total']): ?><a href="/admin/sync" class="btn btn-primary" style="margin-top:1rem;">🔄 Sincronizar</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="bulk-bar">
                <span id="bulkCount" class="muted">Marca productos para activarlos o desactivarlos juntos.</span>
                <button type="button" class="link" id="bulkAllBtn" onclick="selectAllFilter()" style="display:none;">
                    Seleccionar los <?= number_format($total) ?> del filtro
                </button>
                <span class="bulk-spacer" style="flex:1;"></span>
                <button type="button" class="btn btn-sm btn-primary" onclick="bulkSet(1)">✅ Activar</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="bulkSet(0)">⏸️ Desactivar</button>
            </div>

            <div class="table-wrapper">
                <table class="table prod-table">
                    <thead>
                        <tr>
                            <th style="width:32px;"><input type="checkbox" id="checkPage" onchange="checkPage(this.checked)" title="Seleccionar esta página"></th>
                            <th style="width:56px;"></th>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Marca</th>
                            <th>Color</th>
                            <th>Categoría</th>
                            <th>Precio</th>
                            <th>Stock</th>
                            <th style="text-align:center;">Activo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p):
                            $cover = parseStoredCover($p['cover_image_url'], 120);
                            $visible = $p['is_active'] && ($p['in_sheet'] || $p['from_drive']);
                        ?>
                            <tr data-id="<?= (int) $p['id'] ?>" class="<?= $visible ? '' : 'is-off' ?>">
                                <td class="c-check"><input type="checkbox" class="row-check" value="<?= (int) $p['id'] ?>" onchange="updateBulk()"></td>
                                <td class="c-thumb">
                                    <?php if ($cover['url']): ?>
                                        <img class="prod-thumb" src="<?= e($cover['url']) ?>" alt="" loading="lazy" onerror="this.outerHTML='<div class=\'prod-thumb\'>📷</div>'">
                                    <?php else: ?>
                                        <div class="prod-thumb">📷</div>
                                    <?php endif; ?>
                                </td>
                                <td class="c-sku">
                                    <a href="/producto/<?= urlencode($p['sku']) ?>" target="_blank" style="color:var(--color-primary); font-weight:600; font-family:monospace;"><?= e($p['sku']) ?></a>
                                    <?php if (!$p['in_sheet']): ?><div class="tag-out"><?= $p['from_drive'] ? 'Solo Drive (falta en la hoja)' : 'No está en la hoja' ?></div><?php endif; ?>
                                </td>
                                <td class="c-name">
                                    <?= e($p['name']) ?>
                                    <div class="cell-muted only-mobile"><?= e(implode(' · ', array_filter([$p['brand'], $p['color'], $p['price_suggested'] > 0 ? '$' . number_format((float) $p['price_suggested'], 0, ',', '.') : '', 'Stock ' . (int) $p['stock']]))) ?></div>
                                </td>
                                <td class="cell-muted c-meta"><?= e($p['brand']) ?: '—' ?></td>
                                <td class="cell-muted c-meta"><?= e($p['color']) ?: '—' ?></td>
                                <td class="cell-muted c-meta"><?= e($p['category']) ?: '—' ?></td>
                                <td class="cell-muted c-meta"><?= $p['price_suggested'] > 0 ? '$' . number_format((float) $p['price_suggested'], 0, ',', '.') : '—' ?></td>
                                <td class="cell-muted c-meta"><?= (int) $p['stock'] ?></td>
                                <td class="c-active" style="text-align:center;">
                                    <label class="toggle-switch" title="<?= $p['is_active'] ? 'Activo' : 'Desactivado' ?>">
                                        <input type="checkbox" <?= $p['is_active'] ? 'checked' : '' ?> onchange="toggleOne(this)">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $pages = array_unique(array_filter(
                        [1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $totalPages],
                        fn($n) => $n >= 1 && $n <= $totalPages
                    ));
                    sort($pages);
                    $prev = 0;
                    foreach ($pages as $i):
                        if ($i - $prev > 1): ?><span>…</span><?php endif;
                        $prev = $i;
                        $url = '/admin/products?' . http_build_query($baseFilters + ['page' => $i]);
                        if ($i === $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= e($url) ?>"><?= $i ?></a>
                        <?php endif;
                    endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <div class="toast-container" id="toast-container"></div>

    <script>
        const CSRF = '<?= csrfToken() ?>';
        const FILTERS = <?= json_encode($filters, JSON_UNESCAPED_UNICODE) ?>;
        const TOTAL = <?= (int) $total ?>;
        let allFilter = false; // true = la acción va a todos los del filtro, no solo a los marcados

        function showToast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `toast ${type}`;
            t.textContent = msg;
            document.getElementById('toast-container').appendChild(t);
            setTimeout(() => t.remove(), 4000);
        }

        function checkedIds() {
            return [...document.querySelectorAll('.row-check:checked')].map(c => c.value);
        }

        function updateBulk() {
            const n = checkedIds().length;
            const all = document.querySelectorAll('.row-check').length;
            const label = document.getElementById('bulkCount');
            const allBtn = document.getElementById('bulkAllBtn');
            if (allFilter) {
                label.textContent = `Seleccionados los ${TOTAL.toLocaleString('es-CO')} productos del filtro.`;
                allBtn.style.display = 'none';
            } else {
                label.textContent = n ? `${n} seleccionado${n === 1 ? '' : 's'}.` : 'Marca productos para activarlos o desactivarlos juntos.';
                allBtn.style.display = (n === all && TOTAL > all) ? '' : 'none';
            }
            document.getElementById('checkPage').checked = n > 0 && n === all;
        }

        function checkPage(on) {
            document.querySelectorAll('.row-check').forEach(c => c.checked = on);
            allFilter = false;
            updateBulk();
        }

        function selectAllFilter() {
            allFilter = true;
            updateBulk();
        }

        async function setActive(active, body) {
            body.append('active', active ? '1' : '0');
            const resp = await fetch('/admin/products/active', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF },
                body
            });
            const data = await resp.json();
            if (!data.ok) throw new Error(data.error || 'Error al guardar');
            return data;
        }

        async function bulkSet(active) {
            const body = new FormData();
            let count;
            if (allFilter) {
                body.append('all', '1');
                Object.entries(FILTERS).forEach(([k, v]) => body.append(k, v));
                count = TOTAL;
            } else {
                const ids = checkedIds();
                if (!ids.length) { showToast('Marca al menos un producto', 'info'); return; }
                body.append('ids', ids.join(','));
                count = ids.length;
            }
            if (count > 50 && !confirm(`¿${active ? 'Activar' : 'Desactivar'} ${count.toLocaleString('es-CO')} productos?`)) return;
            try {
                const data = await setActive(active, body);
                showToast(data.message);
                setTimeout(() => location.reload(), 600);
            } catch (e) {
                showToast(e.message || 'Error de conexión', 'error');
            }
        }

        async function toggleOne(checkbox) {
            const row = checkbox.closest('tr');
            const active = checkbox.checked;
            checkbox.disabled = true;
            const body = new FormData();
            body.append('ids', row.dataset.id);
            try {
                const data = await setActive(active, body);
                const outOfSheet = !!row.querySelector('.tag-out');
                row.classList.toggle('is-off', !active || outOfSheet);
                checkbox.closest('.toggle-switch').title = active ? 'Activo' : 'Desactivado';
                showToast(data.message);
            } catch (e) {
                checkbox.checked = !active;
                showToast(e.message || 'Error de conexión', 'error');
            }
            checkbox.disabled = false;
        }
    </script>
</body>

</html>
