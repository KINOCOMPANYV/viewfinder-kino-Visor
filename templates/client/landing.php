<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Viewfinder — Centro de Contenido</title>
    <meta name="description"
        content="Centro de contenido exclusivo para distribuidores. Busca por SKU y descarga fotos y videos — Viewfinder Kino Visor.">
    <link rel="preconnect" href="https://lh3.googleusercontent.com" crossorigin>
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
                    <div class="search-buttons">
                        <button type="submit" class="search-btn">Buscar</button>
                        <button type="button" class="search-btn batch-btn" id="btnBatchOpen"
                            title="Buscar múltiples códigos a la vez">📋 Lote</button>
                    </div>
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
                        <div class="card-image" id="cover-<?= e($p['sku']) ?>" data-sku="<?= e($p['sku']) ?>" <?php
                            $coverUrl = $p['cover_image_url'] ?? '';
                            $isVideo = str_starts_with($coverUrl, '[VIDEO]');
                            if ($isVideo)
                                $coverUrl = substr($coverUrl, 7);
                            if ($coverUrl): ?> data-cover="<?= e($coverUrl) ?>"
                                data-video="<?= $isVideo ? '1' : '0' ?>" <?php endif; ?>>
                            <?php if ($coverUrl): ?>
                                <img src="<?= e($coverUrl) ?>" alt="<?= e($p['name']) ?>" loading="lazy" class="img-fade-in"
                                    onload="this.classList.add('loaded')"
                                    onerror="this.outerHTML='<div class=\'cover-placeholder\'>📷</div>'">
                            <?php else: ?>
                                <div class="card-image-skeleton skeleton"></div>
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

    <!-- Modal Búsqueda por Lote -->
    <div class="batch-modal-overlay" id="batchModal">
        <div class="batch-modal">
            <div class="batch-modal-header">
                <h2>📋 Búsqueda por Lote</h2>
                <button class="batch-modal-close" id="btnBatchClose">&times;</button>
            </div>
            <div class="batch-modal-body">
                <div id="batchInputSection">
                    <label for="batchCodes">Pega los códigos (uno por línea):</label>
                    <textarea id="batchCodes" class="form-input" rows="8" placeholder="Ejemplo:
KV-1001
KV-1002
KV-1003
..."></textarea>
                    <div class="batch-actions">
                        <span class="batch-count" id="batchCount">0 códigos</span>
                        <button class="btn btn-primary" id="btnBatchSearch">🔍 Buscar Lote</button>
                    </div>
                </div>
                <div id="batchLoading" style="display:none;">
                    <div class="batch-spinner"></div>
                    <p style="text-align:center; color:var(--color-text-muted); margin-top:1rem;">Buscando productos...
                    </p>
                </div>
                <div id="batchResults" style="display:none;">
                    <div class="batch-results-header">
                        <span id="batchSummary"></span>
                        <button class="btn btn-sm btn-secondary" id="btnBatchBack">← Nueva búsqueda</button>
                    </div>
                    <div class="batch-results-grid" id="batchResultsGrid"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- WhatsApp Share -->
    <script>
        function shareWhatsApp(sku, name) {
            const url = window.location.origin + '/producto/' + sku;
            const text = `📦 *${name}*\n🔗 SKU: ${sku}\n\n📸 Ver fotos y videos:\n${url}`;
            window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
        }

        // Renderizar cover dinámicamente (solo para cards sin SSR image)
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
            // Solo buscar cards que NO tienen imagen SSR (sin cover en BD)
            const needsFetch = [];
            document.querySelectorAll('.card-image[data-sku]').forEach(el => {
                if (!el.dataset.cover && !el.querySelector('img')) {
                    needsFetch.push(el);
                }
            });

            if (needsFetch.length === 0) return;

            const skus = needsFetch.map(el => el.dataset.sku);
            fetch('/api/covers/batch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ skus })
            })
                .then(r => r.json())
                .then(data => {
                    const covers = data.covers || {};
                    needsFetch.forEach(el => {
                        const cover = covers[el.dataset.sku];
                        if (cover && cover.url) {
                            renderCover(el, cover.url, cover.video);
                        } else {
                            el.innerHTML = '<div class="cover-placeholder">📷</div>';
                        }
                    });
                })
                .catch(() => needsFetch.forEach(el => el.innerHTML = '<div class="cover-placeholder">📷</div>'));
        });
    </script>

    <!-- Batch Search JS -->
    <script>
        (function () {
            const modal = document.getElementById('batchModal');
            const btnOpen = document.getElementById('btnBatchOpen');
            const btnClose = document.getElementById('btnBatchClose');
            const btnSearch = document.getElementById('btnBatchSearch');
            const btnBack = document.getElementById('btnBatchBack');
            const textarea = document.getElementById('batchCodes');
            const countEl = document.getElementById('batchCount');
            const inputSection = document.getElementById('batchInputSection');
            const loadingSection = document.getElementById('batchLoading');
            const resultsSection = document.getElementById('batchResults');
            const resultsGrid = document.getElementById('batchResultsGrid');
            const summaryEl = document.getElementById('batchSummary');

            function getCodes() {
                return textarea.value.split('\n').map(l => l.trim()).filter(l => l.length > 0);
            }

            // Contador de códigos
            textarea.addEventListener('input', () => {
                const n = getCodes().length;
                countEl.textContent = n + (n === 1 ? ' código' : ' códigos');
            });

            // Abrir modal
            btnOpen.addEventListener('click', (e) => {
                e.preventDefault();
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                textarea.focus();
            });

            // Cerrar modal
            function closeModal() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
            btnClose.addEventListener('click', closeModal);
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
            });

            // Volver a input
            btnBack.addEventListener('click', () => {
                resultsSection.style.display = 'none';
                inputSection.style.display = '';
            });

            // Buscar lote
            btnSearch.addEventListener('click', () => {
                const codes = getCodes();
                if (codes.length === 0) {
                    textarea.focus();
                    return;
                }

                inputSection.style.display = 'none';
                loadingSection.style.display = '';
                resultsSection.style.display = 'none';

                fetch('/api/batch-search', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ codes })
                })
                    .then(r => r.json())
                    .then(data => {
                        loadingSection.style.display = 'none';
                        const results = data.results || {};
                        let found = 0, notFound = 0;
                        resultsGrid.innerHTML = '';

                        codes.forEach(code => {
                            const item = results[code];
                            const card = document.createElement('div');

                            if (item && item.sku) {
                                found++;
                                card.className = 'batch-result-card found';
                                const imgHtml = item.image
                                    ? `<img src="${item.image}" alt="${item.sku}" loading="lazy" onerror="this.outerHTML='<div class=\\'batch-no-img\\'>📷</div>'">`
                                    : '<div class="batch-no-img">📷</div>';
                                card.innerHTML = `
                            <a href="/producto/${item.sku}" class="batch-result-link" target="_blank">
                                <div class="batch-result-img">${imgHtml}</div>
                                <div class="batch-result-info">
                                    <span class="batch-result-sku">${item.sku}</span>
                                    <span class="batch-result-name">${item.name || ''}</span>
                                </div>
                            </a>`;
                            } else {
                                notFound++;
                                card.className = 'batch-result-card not-found';
                                card.innerHTML = `
                            <div class="batch-result-img"><div class="batch-no-img">❌</div></div>
                            <div class="batch-result-info">
                                <span class="batch-result-sku">${code}</span>
                                <span class="batch-result-status">No encontrado</span>
                            </div>`;
                            }
                            resultsGrid.appendChild(card);
                        });

                        summaryEl.innerHTML = `<strong>${found}</strong> encontrado${found !== 1 ? 's' : ''} · <strong>${notFound}</strong> no encontrado${notFound !== 1 ? 's' : ''}`;
                        resultsSection.style.display = '';
                    })
                    .catch(err => {
                        loadingSection.style.display = 'none';
                        inputSection.style.display = '';
                        alert('Error al buscar. Intenta de nuevo.');
                        console.error(err);
                    });
            });
        })();
    </script>

    <!-- Autocomplete JS -->
    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
</body>

</html>