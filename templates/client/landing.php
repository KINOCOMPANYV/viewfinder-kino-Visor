<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Viewfinder — Centro de Contenido</title>
    <meta name="description"
        content="Centro de contenido exclusivo para distribuidores. Busca por SKU y descarga fotos y videos — Viewfinder Kino Visor.">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23c9a84c'/><text x='50%25' y='55%25' dominant-baseline='middle' text-anchor='middle' font-size='18' fill='black' font-weight='bold'>K</text></svg>">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo">
                <span class="logo-icon">VF</span>
                Viewfinder
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
                            <?php
                            $coverUrl = $p['cover_image_url'] ?? '';
                            $isVideo = str_starts_with($coverUrl, '[VIDEO]');
                            if ($isVideo)
                                $coverUrl = substr($coverUrl, 7);
                            ?>
                            <?php if ($coverUrl): ?>
                                <img src="<?= e($coverUrl) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                                <?php if ($isVideo): ?>
                                    <span
                                        style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:2.5rem; color:#fff; text-shadow:0 2px 8px rgba(0,0,0,0.6); pointer-events:none;">▶</span>
                                <?php endif; ?>
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
                            </div>
                            <button class="btn-whatsapp"
                                onclick="event.preventDefault(); event.stopPropagation(); shareWhatsApp('<?= e($p['sku']) ?>', '<?= e(addslashes($p['name'])) ?>');"
                                title="Enviar por WhatsApp">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                    <path
                                        d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                                </svg>
                                Enviar
                            </button>
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
            <p>Solo para distribuidores autorizados · Viewfinder Kino Visor ©
                <?= date('Y') ?>
            </p>
        </div>
    </footer>

    <!-- WhatsApp Share -->
    <script>
        function shareWhatsApp(sku, name) {
            const url = window.location.origin + '/producto/' + sku;
            const text = `📦 *${name}*\n🔗 SKU: ${sku}\n\n📸 Ver fotos y videos:\n${url}`;
            window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
        }
    </script>

    <!-- Autocomplete JS -->
    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
</body>

</html>