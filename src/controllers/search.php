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
        <?= $q ? e($q) . ' — ' : '' ?>Búsqueda · Viewfinder
    </title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
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
                        <div class="card-image" data-sku="<?= e($p['sku']) ?>"
                             <?php
                             $coverUrl = $p['cover_image_url'] ?? '';
                             $isVideo = str_starts_with($coverUrl, '[VIDEO]');
                             if ($isVideo) $coverUrl = substr($coverUrl, 7);
                             if ($coverUrl): ?>
                                data-cover="<?= e($coverUrl) ?>"
                                data-video="<?= $isVideo ? '1' : '0' ?>"
                             <?php endif; ?>
                        >
                            <div class="cover-loader" style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--color-text-muted);font-size:1.5rem;">
                                ⏳
                            </div>
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
            <p>Solo para distribuidores autorizados · Viewfinder Kino Visor ©
                <?= date('Y') ?>
            </p>
        </div>
    </footer>

    <script>
        function shareWhatsApp(sku, name) {
            const url = window.location.origin + '/producto/' + sku;
            const text = `📦 *${name}*\n🔗 SKU: ${sku}\n\n📸 Ver fotos y videos:\n${url}`;
            window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
        }

        // Cargar portadas dinámicamente desde Drive API
        function renderCover(el, imgUrl, isVideo) {
            el.innerHTML = '';
            const img = document.createElement('img');
            img.src = imgUrl;
            img.alt = el.dataset.sku;
            img.loading = 'lazy';
            img.style.transition = 'opacity 0.3s';
            img.style.opacity = '0';
            img.onload = () => img.style.opacity = '1';
            img.onerror = () => { el.innerHTML = '📷'; };
            el.appendChild(img);
            if (isVideo) {
                const play = document.createElement('span');
                play.textContent = '▶';
                play.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:2.5rem;color:rgba(255,255,255,.85);text-shadow:0 2px 8px rgba(0,0,0,.6);pointer-events:none;';
                el.appendChild(play);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.card-image[data-sku]');
            const needsFetch = [];

            cards.forEach(el => {
                if (el.dataset.cover) {
                    renderCover(el, el.dataset.cover, el.dataset.video === '1');
                } else {
                    needsFetch.push(el);
                }
            });

            if (needsFetch.length === 0) return;

            const skus = needsFetch.map(el => el.dataset.sku);
            fetch('/api/covers/batch', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({skus})
            })
            .then(r => r.json())
            .then(data => {
                const covers = data.covers || {};
                needsFetch.forEach((el, i) => {
                    setTimeout(() => {
                        const cover = covers[el.dataset.sku];
                        if (cover && cover.url) {
                            renderCover(el, cover.url, cover.video);
                        } else {
                            el.innerHTML = '📷';
                        }
                    }, i * 50);
                });
            })
            .catch(() => needsFetch.forEach(el => el.innerHTML = '📷'));
        });
    </script>

    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
</body>

</html>