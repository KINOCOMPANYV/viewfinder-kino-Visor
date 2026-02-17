<?php
/**
 * Búsqueda con resultados — muestra grid de productos.
 */
$q = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;

$db = getDB();

if ($q === '') {
    // Sin query: mostrar todos los activos paginados
    $countStmt = $db->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT sku, name, category, gender, price_suggested, cover_image_url 
         FROM products WHERE status = 'active' 
         ORDER BY name ASC 
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$perPage, $offset]);
    $products = $stmt->fetchAll();
} else {
    // Con query: buscar
    $like = "%{$q}%";
    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM products 
         WHERE status = 'active' AND (sku LIKE ? OR name LIKE ? OR category LIKE ?)"
    );
    $countStmt->execute([$like, $like, $like]);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT sku, name, category, gender, price_suggested, cover_image_url 
         FROM products 
         WHERE status = 'active' AND (sku LIKE ? OR name LIKE ? OR category LIKE ?)
         ORDER BY 
           CASE WHEN sku = ? THEN 0
                WHEN sku LIKE ? THEN 1
                ELSE 2 END,
           name ASC
         LIMIT ? OFFSET ?"
    );
    $startsWith = "{$q}%";
    $stmt->execute([$like, $like, $like, $q, $startsWith, $perPage, $offset]);
    $products = $stmt->fetchAll();
}

$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>
        <?= $q ? e($q) . ' — ' : '' ?>Búsqueda · KINO
    </title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">K</span> KINO</a>
        </div>
    </header>

    <section class="container" style="padding-top:2rem;">
        <!-- Search bar inline -->
        <div class="search-box" style="max-width:100%; margin-bottom:1.5rem;">
            <span class="search-icon">🔍</span>
            <form action="/buscar" method="GET" id="searchForm">
                <input type="text" name="q" id="searchInput" value="<?= e($q) ?>"
                    placeholder="Buscar por SKU o nombre..." autocomplete="off">
                <button type="submit" class="search-btn">Buscar</button>
            </form>
            <div class="autocomplete-dropdown" id="autocomplete"></div>
        </div>

        <!-- Results count -->
        <p style="color:var(--color-text-muted); font-size:0.9rem; margin-bottom:1rem;">
            <?php if ($q): ?>
                <?= $total ?> resultado
                <?= $total !== 1 ? 's' : '' ?> para "<strong style="color:var(--color-text)">
                    <?= e($q) ?>
                </strong>"
            <?php else: ?>
                <?= $total ?> producto
                <?= $total !== 1 ? 's' : '' ?> en el catálogo
            <?php endif; ?>
        </p>

        <?php if (!empty($products)): ?>
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

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php
                        $params = ['q' => $q, 'page' => $i];
                        $url = '/buscar?' . http_build_query($params);
                        ?>
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
            <div class="no-results fade-in">
                <h3>Sin resultados</h3>
                <p>No encontramos productos con "
                    <?= e($q) ?>". Intenta con otro SKU o nombre.
                </p>
            </div>
        <?php endif; ?>
    </section>

    <footer class="footer">
        <div class="container">
            <p>Solo para distribuidores autorizados · KINO ©
                <?= date('Y') ?>
            </p>
        </div>
    </footer>

    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
</body>

</html>