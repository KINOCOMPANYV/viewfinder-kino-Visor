<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos · KINO Admin</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">K</span> KINO Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem;">
        <nav class="admin-nav">
            <a href="/admin">📊 Dashboard</a>
            <a href="/admin/products" class="active">📦 Productos</a>
            <a href="/admin/import">📥 Importar Excel</a>
            <a href="/" target="_blank">🌐 Ver Portal</a>
        </nav>

        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="alert alert-success">
                <?= e($_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-error">
                <?= e($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div
            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
            <h1 style="font-size:1.5rem;">Productos <span style="color:var(--color-text-dim); font-size:0.9rem;">(
                    <?= $total ?>)
                </span></h1>
            <form action="/admin/products" method="GET" style="display:flex; gap:0.5rem;">
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar SKU o nombre..." class="form-input"
                    style="width:250px; padding:0.5rem 0.75rem; font-size:0.85rem;">
                <button type="submit" class="btn btn-sm btn-primary">Buscar</button>
            </form>
        </div>

        <?php if (!empty($products)): ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Nombre</th>
                            <th>Categoría</th>
                            <th>Género</th>
                            <th>Precio</th>
                            <th>Estado</th>
                            <th>Actualizado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><a href="/producto/<?= e($p['sku']) ?>" target="_blank" style="font-weight:600;">
                                        <?= e($p['sku']) ?>
                                    </a></td>
                                <td>
                                    <?= e(truncate($p['name'], 40)) ?>
                                </td>
                                <td>
                                    <?= e($p['category']) ?>
                                </td>
                                <td>
                                    <?= e(ucfirst($p['gender'])) ?>
                                </td>
                                <td>
                                    <?= $p['price_suggested'] > 0 ? formatPrice($p['price_suggested']) : '—' ?>
                                </td>
                                <td>
                                    <?php if ($p['status'] === 'active'): ?>
                                        <span class="badge badge-active">Activo</span>
                                    <?php else: ?>
                                        <span class="badge badge-discontinued">Descont.</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--color-text-dim); font-size:0.8rem;">
                                    <?= date('d/m/y', strtotime($p['updated_at'])) ?>
                                </td>
                                <td>
                                    <form method="POST" action="/admin/product/delete"
                                        onsubmit="return confirm('¿Eliminar producto <?= e($p['sku']) ?>?')"
                                        style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"
                                            style="padding:0.3rem 0.6rem; font-size:0.7rem;">
                                            🗑
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= min($totalPages, 20); $i++): ?>
                        <?php $url = '/admin/products?' . http_build_query(['q' => $q, 'page' => $i]); ?>
                        <?php if ($i === $page): ?>
                            <span class="current">
                                <?= $i ?>
                            </span>
                        <?php else: ?>
                            <a href="<?= $url ?>">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">📦</div>
                <h3>Sin productos</h3>
                <p>Importa tu catálogo desde un archivo Excel.</p>
                <a href="/admin/import" class="btn btn-primary" style="margin-top:1rem;">📥 Importar Excel</a>
            </div>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <div class="container">
            <p>KINO Admin Panel · v1.0</p>
        </div>
    </footer>
</body>

</html>