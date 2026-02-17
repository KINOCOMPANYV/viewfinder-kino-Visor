<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>KINO — Centro de Contenido</title>
    <meta name="description"
        content="Centro de contenido exclusivo para distribuidores KINO. Busca por SKU y descarga fotos y videos.">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23c9a84c'/><text x='50%25' y='55%25' dominant-baseline='middle' text-anchor='middle' font-size='18' fill='black' font-weight='bold'>K</text></svg>">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo">
                <span class="logo-icon">K</span>
                KINO
            </a>
            <a href="/admin/login" class="btn btn-sm btn-secondary"
                style="font-size:0.75rem; padding:0.4rem 0.8rem; opacity:0.4;">Admin</a>
        </div>
    </header>

    <!-- Hero -->
    <section class="hero">
        <div class="container">
            <h1>Centro de Contenido</h1>
            <p>Busca por referencia o SKU para acceder a fotos, videos y descripción del producto.</p>

            <!-- Search -->
            <div class="search-box">
                <span class="search-icon">🔍</span>
                <form action="/buscar" method="GET" id="searchForm">
                    <input type="text" name="q" id="searchInput" placeholder="Escribe SKU o nombre del producto..."
                        autocomplete="off" autofocus>
                    <button type="submit" class="search-btn">Buscar</button>
                </form>
                <div class="autocomplete-dropdown" id="autocomplete"></div>
            </div>
        </div>
    </section>

    <!-- Recent / Featured Products -->
    <section class="container">
        <?php
        $db = getDB();
        $products = $db->query(
            "SELECT sku, name, category, gender, price_suggested, cover_image_url 
             FROM products 
             WHERE status = 'active' 
             ORDER BY updated_at DESC 
             LIMIT 12"
        )->fetchAll();
        ?>

        <?php if (!empty($products)): ?>
            <h2 style="font-size:1.1rem; color:var(--color-text-muted); margin-bottom:0.5rem;">
                Productos recientes
            </h2>
            <div class="product-grid">
                <?php foreach ($products as $p): ?>
                    <a href="/producto/<?= e($p['sku']) ?>" class="product-card" style="text-decoration:none; color:inherit;">
                        <div class="card-image">
                            <?php if ($p['cover_image_url']): ?>
                                <img src="<?= e($p['cover_image_url']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                            <?php else: ?>
                                ⌚
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="card-sku">
                                <?= e($p['sku']) ?>
                            </div>
                            <div class="card-name">
                                <?= e($p['name']) ?>
                            </div>
                            <div class="card-meta">
                                <?php if ($p['category']): ?>
                                    <span>
                                        <?= e($p['category']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($p['price_suggested'] > 0): ?>
                                    <span class="card-price">
                                        <?= formatPrice($p['price_suggested']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state fade-in">
                <div class="empty-icon">📦</div>
                <h3>Aún no hay productos</h3>
                <p>El catálogo está vacío. El administrador puede importar productos desde Excel.</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>Solo para distribuidores autorizados · KINO ©
                <?= date('Y') ?>
            </p>
        </div>
    </footer>

    <!-- Autocomplete JS -->
    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
</body>

</html>