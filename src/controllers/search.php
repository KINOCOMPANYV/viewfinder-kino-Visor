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
    <link rel="preconnect" href="https://lh3.googleusercontent.com" crossorigin>
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
            <!-- WhatsApp toolbar -->
            <div class="search-wa-toolbar" id="searchWaBar">
                <label class="wa-toolbar-select">
                    <input type="checkbox" id="searchSelectAll"> 
                    <span>Seleccionar todas</span>
                </label>
                <span id="searchSelectedCount" class="wa-toolbar-count">0</span>
                <button class="wa-toolbar-send" id="btnSearchWaSend" disabled>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    Enviar por WhatsApp
                </button>
            </div>

            <div class="product-grid">
                <?php foreach ($products as $idx => $p): ?>
                    <div class="product-card search-selectable-card">
                        <a href="/producto/<?= e($p['sku']) ?>" style="text-decoration:none; color:inherit; display:block;">
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
                                <?php if ($coverUrl): ?>
                                    <img src="<?= e($coverUrl) ?>" alt="<?= e($p['name']) ?>"
                                         loading="lazy" class="img-fade-in"
                                         onload="this.classList.add('loaded')"
                                         onerror="this.outerHTML='<div class=\'cover-placeholder\'>📷</div>'">
                                <?php else: ?>
                                    <div class="card-image-skeleton skeleton"></div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="card-sku"><?= e($p['sku']) ?></div>
                                <div class="card-name"><?= e($p['name']) ?></div>
                                <div class="card-meta">
                                    <?php if ($p['category']): ?>
                                        <span><?= e($p['category']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                        <!-- Checkbox FUERA de la imagen -->
                        <label class="search-check-footer">
                            <input type="checkbox" class="search-card-check" 
                                   data-index="<?= $idx ?>" 
                                   data-sku="<?= e($p['sku']) ?>"
                                   data-name="<?= e(addslashes($p['name'])) ?>"
                                   data-image="<?= e($p['cover_image_url'] ?? '') ?>">
                            <span class="search-check-icon">✓</span>
                            <span class="search-check-text">Seleccionar</span>
                        </label>
                    </div>
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
        // Renderizar cover dinamicamente
        function renderCover(el, imgUrl, isVideo) {
            el.innerHTML = '';
            const img = document.createElement('img');
            img.src = imgUrl;
            img.alt = el.dataset.sku;
            img.loading = 'lazy';
            img.className = 'img-fade-in';
            img.onload = () => img.classList.add('loaded');
            img.onerror = () => { el.innerHTML = '<div class="cover-placeholder">📷</div>'; };
            el.appendChild(img);
            if (isVideo) {
                const play = document.createElement('span');
                play.textContent = '▶';
                play.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:2.5rem;color:rgba(255,255,255,.85);text-shadow:0 2px 8px rgba(0,0,0,.6);pointer-events:none;';
                el.appendChild(play);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Cargar covers faltantes
            const needsFetch = [];
            document.querySelectorAll('.card-image[data-sku]').forEach(el => {
                if (!el.dataset.cover && !el.querySelector('img')) needsFetch.push(el);
            });
            if (needsFetch.length > 0) {
                const skus = needsFetch.map(el => el.dataset.sku);
                fetch('/api/covers/batch', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({skus})
                })
                .then(r => r.json())
                .then(data => {
                    const covers = data.covers || {};
                    needsFetch.forEach(el => {
                        const cover = covers[el.dataset.sku];
                        if (cover && cover.url) renderCover(el, cover.url, cover.video);
                        else el.innerHTML = '<div class="cover-placeholder">📷</div>';
                    });
                })
                .catch(() => needsFetch.forEach(el => el.innerHTML = '<div class="cover-placeholder">📷</div>'));
            }

            // --- WhatsApp search checkboxes ---
            const waBar = document.getElementById('searchWaBar');
            const selectAllCb = document.getElementById('searchSelectAll');
            const selectedCountEl = document.getElementById('searchSelectedCount');
            const btnSend = document.getElementById('btnSearchWaSend');
            const checks = document.querySelectorAll('.search-card-check');

            function updateCount() {
                const selected = document.querySelectorAll('.search-card-check:checked').length;
                selectedCountEl.textContent = selected;
                btnSend.disabled = selected === 0;
                selectAllCb.checked = selected === checks.length && checks.length > 0;
                selectAllCb.indeterminate = selected > 0 && selected < checks.length;
            }

            checks.forEach(cb => cb.addEventListener('change', updateCount));
            selectAllCb.addEventListener('change', () => {
                checks.forEach(cb => cb.checked = selectAllCb.checked);
                updateCount();
            });

            btnSend.addEventListener('click', async () => {
                const selected = [];
                document.querySelectorAll('.search-card-check:checked').forEach(cb => {
                    selected.push({ sku: cb.dataset.sku, name: cb.dataset.name, image: cb.dataset.image });
                });
                if (selected.length === 0) return;
                if (selected.length > 10) {
                    alert('\u26A0\uFE0F Solo se pueden enviar 10 im\u00E1genes a la vez.\n\nPor favor deselecciona algunas y haz otro env\u00EDo despu\u00E9s.');
                    return;
                }

                // Intentar Web Share API solo en mobile (desktop no tiene WhatsApp en share nativo)
                const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                if (isMobile && navigator.canShare && navigator.share) {
                    btnSend.disabled = true;
                    btnSend.innerHTML = '<div class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></div> Preparando...';
                    try {
                        const files = (await Promise.all(selected.map(async (item, i) => {
                            try {
                                const r = await fetch(item.image, { mode: 'cors' });
                                const b = await r.blob();
                                return new File([b], `imagen_${i + 1}.jpg`, { type: b.type || 'image/jpeg' });
                            } catch { return null; }
                        }))).filter(Boolean);
                        if (files.length > 0) {
                            const sd = { files };
                            if (navigator.canShare(sd)) { await navigator.share(sd); resetBtn(); return; }
                        }
                    } catch (e) { if (e.name === 'AbortError') { resetBtn(); return; } }
                    resetBtn();
                }

                // Fallback: links con imagen
                let text = '\uD83D\uDCE6 *Cat\u00E1logo - ' + selected.length + ' productos*\n\n';
                selected.forEach((item, i) => {
                    text += (i+1) + '. *' + item.sku + '* - ' + item.name + '\n';
                    if (item.image) text += '\uD83D\uDDBC\uFE0F ' + item.image + '\n';
                    text += '\uD83D\uDD17 ' + location.origin + '/producto/' + item.sku + '\n\n';
                });
                window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
            });

            function resetBtn() {
                btnSend.disabled = false;
                btnSend.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> Enviar por WhatsApp';
            }
        });
    </script>

    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
</body>

</html>