<?php
$db = getDB();

// === Conteos principales (sincronizados con la BD real) ===
$totalProducts = (int) $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$activeProducts = (int) $db->query("SELECT COUNT(*) FROM products WHERE archived = 0")->fetchColumn();
$archivedProducts = (int) $db->query("SELECT COUNT(*) FROM products WHERE archived = 1")->fetchColumn();
$discontinuedProducts = (int) $db->query("SELECT COUNT(*) FROM products WHERE status = 'discontinued'")->fetchColumn();

// Productos con portada vs sin portada
$withCover = (int) $db->query("SELECT COUNT(*) FROM products WHERE cover_image_url IS NOT NULL AND cover_image_url != '' AND archived = 0")->fetchColumn();
$withoutCover = $activeProducts - $withCover;

// Última actualización
$recentImport = $db->query("SELECT MAX(updated_at) FROM products")->fetchColumn();

// Productos actualizados hoy
$updatedToday = (int) $db->query("SELECT COUNT(*) FROM products WHERE DATE(updated_at) = CURDATE()")->fetchColumn();

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
$zipCacheCount = 0;
$zipCacheSize = 0;

try {
    $mediaSearchCount = (int) $db->query("SELECT COUNT(*) FROM media_search_cache")->fetchColumn();
} catch (Exception $e) {
}
try {
    $driveCacheCount = (int) $db->query("SELECT COUNT(*) FROM drive_cache")->fetchColumn();
} catch (Exception $e) {
}

$zipDir = BASE_DIR . '/storage/zip_cache';
if (is_dir($zipDir)) {
    $zipFiles = glob($zipDir . '/*');
    foreach ($zipFiles as $f) {
        if (is_file($f) && basename($f) !== '.gitkeep') {
            $zipCacheCount++;
            $zipCacheSize += filesize($f);
        }
    }
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
            background: #0a0a0f;
            color: #e8e8f0
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" media="print"
        onload="this.media='all'">
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
        <nav class="admin-nav">
            <a href="/admin" class="active">📊 Dashboard</a>
            <a href="/admin/products">📦 Productos</a>
            <a href="/admin/import">📥 Importar Excel</a>
            <a href="/admin/albums">🗂️ Álbumes</a>
            <a href="/admin/media">🖼️ Media</a>
            <a href="/" target="_blank">🌐 Ver Portal</a>
        </nav>

        <h1 style="font-size:1.5rem; margin-bottom:1.5rem;">Dashboard</h1>

        <!-- Stat Cards Row 1: Principales -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">
                    <?= number_format($totalProducts) ?>
                </div>
                <div class="stat-label">Productos Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--color-success);">
                    <?= number_format($activeProducts) ?>
                </div>
                <div class="stat-label">Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--color-danger);">
                    <?= number_format($archivedProducts) ?>
                </div>
                <div class="stat-label">Archivados</div>
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
            <div class="stat-card">
                <div class="stat-value" style="color:#4ade80;">
                    <?= number_format($withCover) ?>
                </div>
                <div class="stat-label">🖼️ Con Portada</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:#fbbf24;">
                    <?= number_format($withoutCover) ?>
                </div>
                <div class="stat-label">📷 Sin Portada</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--color-accent, #4a9eff);">
                    <?= number_format($updatedToday) ?>
                </div>
                <div class="stat-label">📅 Actualizados Hoy</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:#f87171;">
                    <?= number_format($discontinuedProducts) ?>
                </div>
                <div class="stat-label">⛔ Descontinuados</div>
            </div>
        </div>

        <!-- Desglose por Categoría y Género -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; margin-top:1.5rem;">
            <?php if (!empty($topCategories)): ?>
                <div
                    style="background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem;">
                    <h3 style="font-size:0.9rem; color:var(--color-text-muted); margin-bottom:0.75rem;">📊 Top Categorías
                        (activos)</h3>
                    <?php foreach ($topCategories as $cat): ?>
                        <div
                            style="display:flex; justify-content:space-between; align-items:center; padding:0.4rem 0; border-bottom:1px solid var(--color-border);">
                            <span style="font-size:0.85rem; color:var(--color-text);"><?= e($cat['category']) ?></span>
                            <span
                                style="font-size:0.8rem; font-weight:700; color:var(--color-primary); background:rgba(201,168,76,0.1); padding:0.15rem 0.5rem; border-radius:4px;">
                                <?= number_format($cat['cnt']) ?>
                            </span>
                        </div>
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
                    $genderColors = ['hombre' => '#60a5fa', 'mujer' => '#f472b6', 'unisex' => '#a78bfa'];
                    foreach ($genderStats as $gs):
                        $label = $genderLabels[$gs['gender']] ?? ucfirst($gs['gender']);
                        $color = $genderColors[$gs['gender']] ?? 'var(--color-text)';
                        $pct = $activeProducts > 0 ? round(($gs['cnt'] / $activeProducts) * 100, 1) : 0;
                        ?>
                        <div style="margin-bottom:0.6rem;">
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
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick actions -->
        <h2 style="font-size:1.1rem; color:var(--color-text-muted); margin:2rem 0 1rem;">Acciones rápidas</h2>
        <div class="btn-group">
            <a href="/admin/import" class="btn btn-primary">📥 Importar Excel</a>
            <a href="/admin/products" class="btn btn-secondary">📦 Ver Productos</a>
            <a href="/admin/albums" class="btn btn-secondary">🗂️ Álbumes</a>
            <a href="/admin/media" class="btn btn-secondary">🖼️ Google Drive Media</a>
            <a href="/" target="_blank" class="btn btn-secondary">🌐 Abrir Portal</a>
        </div>

        <!-- Cache Management -->
        <h2 style="font-size:1.1rem; color:var(--color-text-muted); margin:2rem 0 1rem;">🗄️ Gestión de Caché</h2>

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
                    <button type="submit" class="btn btn-sm btn-danger" <?= $driveCacheCount === 0 ? 'disabled' : '' ?>
                        onclick="return confirm('¿Limpiar caché Drive?')">
                        🧹 Limpiar
                    </button>
                </form>
            </div>

            <!-- ZIP Files Cache -->
            <div class="cache-card">
                <div class="cache-card__icon">📦</div>
                <div class="cache-card__info">
                    <div class="cache-card__name">ZIP Downloads</div>
                    <div class="cache-card__desc">Archivos ZIP temporales de descargas</div>
                    <div class="cache-card__stat">
                        <span class="cache-card__count"><?= $zipCacheCount ?></span> archivos
                        <?php if ($zipCacheSize > 0): ?>
                            <span class="cache-card__size">(<?= round($zipCacheSize / 1048576, 1) ?> MB)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <form method="POST" action="/admin/cache/clear" class="cache-card__action">
                    <input type="hidden" name="cache_type" value="zip_files">
                    <button type="submit" class="btn btn-sm btn-danger" <?= $zipCacheCount === 0 ? 'disabled' : '' ?>
                        onclick="return confirm('¿Eliminar archivos ZIP temporales?')">
                        🧹 Limpiar
                    </button>
                </form>
            </div>
        </div>

        <!-- Limpiar todo -->
        <form method="POST" action="/admin/cache/clear" style="margin-top:1rem;">
            <input type="hidden" name="cache_type" value="all">
            <button type="submit" class="btn btn-danger" <?= ($mediaSearchCount + $driveCacheCount + $zipCacheCount) === 0 ? 'disabled' : '' ?> onclick="return confirm('¿Limpiar TODAS las cachés del sistema?')">
                🗑️ Limpiar Todo
            </button>
        </form>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Viewfinder Admin Panel · v1.0</p>
        </div>
    </footer>
</body>

</html>