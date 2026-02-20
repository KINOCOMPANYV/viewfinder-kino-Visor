<?php
$db = getDB();
$totalProducts = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$activeProducts = $db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$discontinued = $totalProducts - $activeProducts;
$recentImport = $db->query("SELECT MAX(updated_at) FROM products")->fetchColumn();
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
            <a href="/admin/media">🖼️ Media</a>
            <a href="/" target="_blank">🌐 Ver Portal</a>
        </nav>

        <h1 style="font-size:1.5rem; margin-bottom:1.5rem;">Dashboard</h1>

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
                    <?= number_format($discontinued) ?>
                </div>
                <div class="stat-label">Descontinuados</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="font-size:1rem; color:var(--color-text-muted);">
                    <?= $recentImport ? date('d/m/Y H:i', strtotime($recentImport)) : 'N/A' ?>
                </div>
                <div class="stat-label">Última actualización</div>
            </div>
        </div>

        <!-- Quick actions -->
        <h2 style="font-size:1.1rem; color:var(--color-text-muted); margin:2rem 0 1rem;">Acciones rápidas</h2>
        <div class="btn-group">
            <a href="/admin/import" class="btn btn-primary">📥 Importar Excel</a>
            <a href="/admin/products" class="btn btn-secondary">📦 Ver Productos</a>
            <a href="/admin/media" class="btn btn-secondary">🖼️ Google Drive Media</a>
            <a href="/" target="_blank" class="btn btn-secondary">🌐 Abrir Portal</a>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Viewfinder Admin Panel · v1.0</p>
        </div>
    </footer>
</body>

</html>