<?php
$db = getDB();

// === Conteos de las tarjetas: el MISMO filtro (adminProductFilter) que la lista y el Excel a los que enlazan,
// así el número de la tarjeta = filas de la lista = filas del Excel
$countFor = function (string $estado) use ($db): int {
    [$w, $p] = adminProductFilter(['estado' => $estado]);
    try { $st = $db->prepare("SELECT COUNT(*) FROM products WHERE $w"); $st->execute($p); return (int) $st->fetchColumn(); }
    catch (\PDOException $e) { return 0; } // columna aún sin migrar
};
$totalProducts = $countFor('');
$activeProducts = $countFor('activos');
$archivedProducts = $countFor('inactivos');
$discontinuedProducts = $countFor('fuera');
$withCover = $countFor('conportada');
$withoutCover = $countFor('sinportada');
$updatedToday = $countFor('hoy');
$noPrice = $countFor('sinprecio');
$manualPrice = $countFor('preciomanual');

// Última actualización
$recentImport = $db->query("SELECT MAX(updated_at) FROM products")->fetchColumn();

// Top categorías (de productos activos)
$topCategories = $db->query(
    "SELECT category, COUNT(*) as cnt 
     FROM products 
     WHERE archived = 0 AND category IS NOT NULL AND category != ''
     GROUP BY category 
     ORDER BY cnt DESC 
     LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

// Géneros (de productos activos)
$genderStats = $db->query(
    "SELECT gender, COUNT(*) as cnt 
     FROM products 
     WHERE archived = 0 
     GROUP BY gender 
     ORDER BY cnt DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Cache stats
$mediaSearchCount = 0;
$driveCacheCount = 0;

try {
    $mediaSearchCount = (int) $db->query("SELECT COUNT(*) FROM media_search_cache")->fetchColumn();
} catch (Exception $e) {
}
try {
    $driveCacheCount = (int) $db->query("SELECT COUNT(*) FROM drive_cache")->fetchColumn();
} catch (Exception $e) {
}

// Flash message
$cacheFlash = $_SESSION['cache_flash'] ?? null;
unset($_SESSION['cache_flash']);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard · Viewfinder Admin</title>
    <style>
        body {
            background: var(--color-bg,#161618);
            color: var(--color-text,#f5f5f7)
        }
        /* Cada tarjeta abre su lista; «⬇ Excel» la descarga completa (mismo filtro que la lista) */
        .stat-wrap { position: relative; display: flex; }
        .stat-wrap > .stat-card { flex: 1; }
        .stat-dl { position: absolute; top: 0.6rem; right: 0.6rem; font-size: 0.72rem; padding: 0.2rem 0.5rem; border-radius: 6px;
            border: 1px solid var(--color-border); color: var(--color-text-muted); text-decoration: none; background: var(--color-surface-2); }
        .stat-dl:hover { color: var(--color-primary); border-color: var(--color-primary); }
    </style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">VF</span> Viewfinder Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem;">
        <?php $adminNav = 'dashboard'; include __DIR__ . '/partials/nav.php'; ?>

        <h1 style="font-size:1.5rem; margin-bottom:1.5rem;">Dashboard</h1>

        <!-- Stat Cards Row 1: Principales (cada tarjeta abre Productos filtrado) -->
        <div class="stats-grid">
            <div class="stat-wrap">
                <a href="/admin/products" class="stat-card stat-card--link" title="Ver estos productos">
                    <div class="stat-value">
                        <?= number_format($totalProducts) ?>
                    </div>
                    <div class="stat-label">Productos Total</div>
                </a>
                <a href="/admin/products?descargar=xlsx" class="stat-dl" title="Descargar esta lista en Excel">⬇ Excel</a>
            </div>
            <div class="stat-wrap">
                <a href="/admin/products?estado=activos" class="stat-card stat-card--link" title="Ver estos productos">
                    <div class="stat-value" style="color:var(--color-success);">
                        <?= number_format($activeProducts) ?>
                    </div>
                    <div class="stat-label">Visibles</div>
                </a>
                <a href="/admin/products?estado=activos&amp;descargar=xlsx" class="stat-dl" title="Descargar esta lista en Excel">⬇ Excel</a>
            </div>
            <div class="stat-wrap">
                <a href="/admin/products?estado=inactivos" class="stat-card stat-card--link" title="Ver estos productos">
                    <div class="stat-value" style="color:var(--color-danger);">
                        <?= number_format($archivedProducts) ?>
                    </div>
                    <div class="stat-label">Desactivados</div>
                </a>
                <a href="/admin/products?estado=inactivos&amp;descargar=xlsx" class="stat-dl" title="Descargar esta lista en Excel">⬇ Excel</a>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="font-size:1rem; color:var(--color-text-muted);">
                    <?= $recentImport ? date('d/m/Y H:i', strtotime($recentImport)) : 'N/A' ?>
                </div>
                <div class="stat-label">Última actualización</div>
            </div>
        </div>

        <!-- Stat Cards Row 2: Detalles -->
        <div class="stats-grid" style="margin-top:1rem;">
            <div class="stat-wrap">
                <a href="/admin/products?estado=conportada" class="stat-card stat-card--link" title="Ver estos productos">
                    <div class="stat-value" style="color:var(--color-success);">
                        <?= number_format($withCover) ?>
                    </div>
                    <div class="stat-label">🖼️ Con Portada</div>
                </a>
                <a href="/admin/products?estado=conportada&amp;descargar=xlsx" class="stat-dl" title="Descargar esta lista en Excel">⬇ Excel</a>
            </div>
            <div class="stat-wrap">
                <a href="/admin/products?estado=sinportada" class="stat-card stat-card--link" title="Ver estos productos">
                    <div class="stat-value" style="color:var(--color-warning);">
                        <?= number_format($withoutCover) ?>
                    </div>
                    <div class="stat-label">📷 Sin Portada</div>
                </a>
                <a href="/admin/products?estado=sinportada&amp;descargar=xlsx" class="stat-dl" title="Descargar esta lista en Excel">⬇ Excel</a>
            </div>
            <div class="stat-wrap">
                <a href="/admin/products?estado=hoy" class="stat-card stat-card--link" title="Ver estos productos">
                    <div class="stat-value" style="color:var(--color-accent, #4a9eff);">
                        <?= number_format($updatedToday) ?>
                    </div>
                    <div class="stat-label">📅 Actualizados Hoy</div>
                </a>
                <a href="/admin/products?estado=hoy&amp;descargar=xlsx" class="stat-dl" title="Descargar esta lista en Excel">⬇ Excel</a>
            </div>
            <div class="stat-wrap">
                <a href="/admin/products?estado=fuera" class="stat-card stat-card--link" title="Ver estos productos">
                    <div class="stat-value" style="color:var(--color-danger);">
                        <?= number_format($discontinuedProducts) ?>
                    </div>
                    <div class="stat-label">⛔ Fuera de la Hoja master</div>
                </a>
                <a href="/admin/products?estado=fuera&amp;descargar=xlsx" class="stat-dl" title="Descargar esta lista en Excel">⬇ Excel</a>
            </div>
            <div class="stat-wrap">
                <a href="/admin/products?estado=sinprecio" class="stat-card stat-card--link" title="Ver y poner precio">
                    <div class="stat-value" style="color:var(--color-warning);">
                        <?= number_format($noPrice) ?>
                    </div>
                    <div class="stat-label">💲 Sin precio en tabla</div>
                </a>
                <a href="/admin/products?estado=sinprecio&amp;descargar=xlsx" class="stat-dl" title="Descargar esta lista en Excel">⬇ Excel</a>
            </div>
            <div class="stat-wrap">
                <a href="/admin/products?estado=preciomanual" class="stat-card stat-card--link" title="Ver precios cambiados en el admin">
                    <div class="stat-value" style="color:var(--color-accent);">
                        <?= number_format($manualPrice) ?>
                    </div>
                    <div class="stat-label">✏️ Precio editado en admin</div>
                </a>
                <a href="/admin/products?estado=preciomanual&amp;descargar=xlsx" class="stat-dl" title="Descargar esta lista en Excel">⬇ Excel</a>
            </div>
        </div>

        <!-- Desglose por Categoría y Género -->
        <div class="dash-two-col" style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; margin-top:1.5rem;">
            <?php if (!empty($topCategories)): ?>
                <div
                    style="background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem;">
                    <h3 style="font-size:0.9rem; color:var(--color-text-muted); margin-bottom:0.75rem;">📊 Top Categorías
                        (activos)</h3>
                    <?php foreach ($topCategories as $cat): ?>
                        <a href="/admin/products?<?= e(http_build_query(['estado' => 'activos', 'categoria' => $cat['category']])) ?>" class="dash-row-link"
                            style="display:flex; justify-content:space-between; align-items:center; padding:0.4rem 0; border-bottom:1px solid var(--color-border);">
                            <span style="font-size:0.85rem; color:var(--color-text);"><?= e($cat['category']) ?></span>
                            <span
                                style="font-size:0.8rem; font-weight:700; color:var(--color-primary); background:rgba(196, 164, 107,0.1); padding:0.15rem 0.5rem; border-radius:4px;">
                                <?= number_format($cat['cnt']) ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($genderStats)): ?>
                <div
                    style="background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem;">
                    <h3 style="font-size:0.9rem; color:var(--color-text-muted); margin-bottom:0.75rem;">👥 Por Género
                        (activos)</h3>
                    <?php
                    $genderLabels = ['hombre' => '♂️ Hombre', 'mujer' => '♀️ Mujer', 'unisex' => '⚧️ Unisex'];
                    $genderColors = ['hombre' => 'var(--color-accent)', 'mujer' => 'var(--ic-pink)', 'unisex' => 'var(--ic-purple)'];
                    foreach ($genderStats as $gs):
                        $label = $genderLabels[$gs['gender']] ?? ucfirst($gs['gender']);
                        $color = $genderColors[$gs['gender']] ?? 'var(--color-text)';
                        $pct = $activeProducts > 0 ? round(($gs['cnt'] / $activeProducts) * 100, 1) : 0;
                        ?>
                        <a href="/admin/products?<?= e(http_build_query(['estado' => 'activos', 'genero' => (string) $gs['gender']])) ?>" class="dash-row-link" style="display:block; margin-bottom:0.6rem;">
                            <div
                                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.25rem;">
                                <span style="font-size:0.85rem; color:var(--color-text);"><?= $label ?></span>
                                <span
                                    style="font-size:0.8rem; font-weight:600; color:<?= $color ?>;"><?= number_format($gs['cnt']) ?>
                                    (<?= $pct ?>%)</span>
                            </div>
                            <div style="height:6px; background:var(--color-surface-2); border-radius:3px; overflow:hidden;">
                                <div
                                    style="height:100%; width:<?= $pct ?>%; background:<?= $color ?>; border-radius:3px; transition:width 0.5s ease;">
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick actions -->
        <div style="background: var(--color-surface); padding: 2rem; border-radius: var(--radius-lg); border: 1px solid var(--color-border); margin: 2rem 0;">
            <h2 style="font-size:1.3rem; margin-bottom: 1.5rem; text-align: center;">⚡ Acciones Rápidas</h2>
            <div class="dash-actions" style="display:flex; flex-wrap:wrap; gap:1rem; justify-content:center;">
                <a href="/admin/sync" class="btn btn-primary" style="padding: 1rem 2rem; font-size:1.1rem;">🔄 Sincronizar hoja maestra</a>
                <a href="/admin/folders" class="btn btn-secondary" style="padding: 1rem 2rem; font-size:1.1rem;">📂 Carpetas</a>
                <a href="/" target="_blank" class="btn btn-secondary" style="padding: 1rem 2rem; font-size:1.1rem; background: var(--color-surface-2);">🌐 Abrir Tienda</a>
            </div>
        </div>

        <!-- Cache Management (Hidden by default to keep it intuitive) -->
        <details style="margin-top:3rem; background: var(--color-surface-2); padding: 1rem; border-radius: var(--radius); border: 1px solid var(--color-border);">
            <summary style="font-size:1rem; font-weight: 600; cursor: pointer; color: var(--color-text-muted);">
                ⚙️ Opciones Avanzadas (Gestión de Caché)
            </summary>
            <div style="margin-top: 1.5rem;">
                <?php if ($cacheFlash): ?>
                    <div class="cache-flash cache-flash--<?= $cacheFlash['type'] ?>">
                        <?= e($cacheFlash['msg']) ?>
                    </div>
                <?php endif; ?>

                <div class="cache-grid">
                    <!-- Media Search Cache -->
                    <div class="cache-card">
                        <div class="cache-card__icon">🔍</div>
                        <div class="cache-card__info">
                            <div class="cache-card__name">Media Search</div>
                            <div class="cache-card__desc">Resultados de búsqueda Drive por SKU</div>
                            <div class="cache-card__stat">
                                <span class="cache-card__count"><?= number_format($mediaSearchCount) ?></span> registros
                            </div>
                        </div>
                        <form method="POST" action="/admin/cache/clear" class="cache-card__action">
                            <input type="hidden" name="cache_type" value="media_search">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-danger" <?= $mediaSearchCount === 0 ? 'disabled' : '' ?>
                                onclick="return confirm('¿Limpiar caché Media Search?')">
                                🧹 Limpiar
                            </button>
                        </form>
                    </div>

                    <!-- Drive Cache -->
                    <div class="cache-card">
                        <div class="cache-card__icon">☁️</div>
                        <div class="cache-card__info">
                            <div class="cache-card__name">Drive Cache</div>
                            <div class="cache-card__desc">Estructura de carpetas Google Drive</div>
                            <div class="cache-card__stat">
                                <span class="cache-card__count"><?= number_format($driveCacheCount) ?></span> registros
                            </div>
                        </div>
                        <form method="POST" action="/admin/cache/clear" class="cache-card__action">
                            <input type="hidden" name="cache_type" value="drive_cache">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-danger" <?= $driveCacheCount === 0 ? 'disabled' : '' ?>
                                onclick="return confirm('¿Limpiar caché Drive?')">
                                🧹 Limpiar
                            </button>
                        </form>
                    </div>

                </div>

                <!-- Limpiar todo -->
                <form method="POST" action="/admin/cache/clear" style="margin-top:1rem; text-align:right;">
                    <input type="hidden" name="cache_type" value="all">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" class="btn btn-danger" <?= ($mediaSearchCount + $driveCacheCount) === 0 ? 'disabled' : '' ?> onclick="return confirm('¿Limpiar TODAS las cachés del sistema?')">
                        🗑️ Limpiar Toda la Caché
                    </button>
                </form>
            </div>
        </details>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Viewfinder Admin Panel · v1.0</p>
        </div>
    </footer>
</body>

</html>