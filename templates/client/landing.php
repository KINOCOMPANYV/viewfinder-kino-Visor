<?php header('Cache-Control: public, max-age=60'); ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Viewfinder — Centro de Contenido</title>
    <meta name="description"
        content="Centro de contenido exclusivo para distribuidores. Busca por SKU y descarga fotos y videos — Viewfinder Visor.">
    <style>
        body {
            background: #0a0a0f;
            color: #e8e8f0
        }

        img {
            max-width: 100%;
            height: auto
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://lh3.googleusercontent.com" crossorigin>
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" media="print"
        onload="this.media='all'">
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

    <!-- Layotu Principal a Dos Columnas -->
    <section class="container search-layout" style="padding-top:2rem;">
        <?php
        $db = null;
        $albums = [];
        $totalAlbums = 0;
        try {
            $db = getDB();
            $totalAlbums = (int)$db->query("SELECT COUNT(*) FROM albums WHERE is_active = 1")->fetchColumn();
            // Poedagar siempre primero, luego por prioridad y nombre
            $albums = $db->query("SELECT * FROM albums WHERE is_active = 1 ORDER BY CASE WHEN LOWER(name) LIKE '%poedagar%' THEN 0 ELSE 1 END, order_priority DESC, name ASC")->fetchAll();
        } catch (\PDOException $e) {
            // tabla albums aún no existe o DB apagada
        }
        ?>
        <!-- ===== MAIN: Hero (Search) ===== -->
        <div class="search-main">
            <!-- Hero Integrado -->
            <div class="hero" style="padding: 0 0 2.5rem; text-align: left;">
                <h1 style="font-size: 2.5rem;">Centro de Contenido</h1>
                <p style="margin: 0 0 1.5rem 0; font-size: 1rem;">Busca por referencia o SKU para acceder a fotos y videos.</p>

                <!-- Search Box -->
                <div class="search-box" style="max-width: 100%; margin: 0;">
                    <span class="search-icon">🔍</span>
                    <form action="/buscar" method="GET" id="searchForm">
                        <textarea name="q" id="searchInput" rows="1"
                            placeholder="Buscar por SKU o nombre..."
                            autocomplete="off" autofocus></textarea>
                        <button type="submit" class="search-btn">Buscar</button>
                    </form>
                    <!-- Botón escáner QR -->
                    <button type="button" id="btnQrScan" title="Escanear código QR"
                        style="background:none;border:none;cursor:pointer;padding:0.4rem 0.5rem;color:var(--color-text-muted);font-size:1.3rem;line-height:1;flex-shrink:0;transition:color 0.2s;"
                        onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='var(--color-text-muted)'">
                        📷
                    </button>
                    <div class="autocomplete-dropdown" id="autocomplete"></div>
                </div>
            </div>


        <!-- Recent / Featured Products -->
        <?php
        $perPage = 10;
        $currentPage = max(1, intval($_GET['page'] ?? 1));
        
        $db = null;
        $pageRoots = [];
        $totalParents = 0;
        $totalPages = 1;
        $grouped = [];

        try {
            $db = getDB();
            
            // Check if sheet_row column exists (migration may not have run yet)
            $hasSheetRow = false;
            try {
                $db->query("SELECT sheet_row FROM products LIMIT 1");
                $hasSheetRow = true;
            } catch (\PDOException $e) {
                // column doesn't exist yet
            }

            $orderCol = $hasSheetRow ? 'sheet_row' : 'id';
            
            // ── FASE 1: Cargar minimal data para paginación ──
            // Priorizar productos del álbum Poedagar
            $poedagarAlbumId = '';
            try {
                $poedagarRow = $db->query("SELECT drive_id FROM albums WHERE LOWER(name) LIKE '%poedagar%' AND is_active = 1 LIMIT 1")->fetch();
                if ($poedagarRow) $poedagarAlbumId = $poedagarRow['drive_id'];
            } catch (\PDOException $e) {}

            $albumCol = '';
            try {
                $db->query("SELECT album_id FROM products LIMIT 1");
                $albumCol = 'album_id';
            } catch (\PDOException $e) {}

            $orderClause = $albumCol && $poedagarAlbumId
                ? "ORDER BY CASE WHEN album_id = " . $db->quote($poedagarAlbumId) . " THEN 0 ELSE 1 END, {$orderCol} DESC"
                : "ORDER BY {$orderCol} DESC";

            $skuRows = $db->query(
                "SELECT sku, {$orderCol} AS sheet_row" . ($albumCol ? ', album_id' : '') . " 
                 FROM products 
                 WHERE archived = 0 
                 {$orderClause}"
            )->fetchAll();

            // Agrupar SKUs por familia
            $familyMap = [];      // family -> [skus]
            $familyMaxRow = [];   // family -> max sheet_row
            foreach ($skuRows as $rowIdx => $row) {
                $skuClean = cleanSkuDisplay($row['sku']);
                $family = extractRootSku($skuClean);
            
            // Usar posición invertida para que Poedagar (que viene primero en la query) tenga el número más alto
            $effectiveRow = count($skuRows) - $rowIdx;
            
            if (!isset($familyMap[$family])) {
                $familyMap[$family] = [];
                $familyMaxRow[$family] = $effectiveRow;
            }
            $familyMap[$family][] = $row['sku'];
            if ($effectiveRow > $familyMaxRow[$family]) {
                $familyMaxRow[$family] = $effectiveRow;
            }
        }
        unset($skuRows); // Liberar memoria

        // Ordenar familias: las más recientes primero
        arsort($familyMaxRow);
        $parentOrder = array_keys($familyMaxRow);

        $totalParents = count($parentOrder);
        $totalPages = max(1, ceil($totalParents / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $pageRoots = array_slice($parentOrder, $offset, $perPage);

        // ── FASE 2: Cargar datos completos SOLO para esta página ──
        $grouped = [];
        if (!empty($pageRoots)) {
            $pageSkus = [];
            foreach ($pageRoots as $family) {
                $pageSkus = array_merge($pageSkus, $familyMap[$family]);
            }
            unset($familyMap);

            if (!empty($pageSkus)) {
                $placeholders = implode(',', array_fill(0, count($pageSkus), '?'));
                $selectCols = $hasSheetRow
                    ? 'id, sku, name, category, gender, price_suggested, cover_image_url, sheet_row'
                    : 'id, sku, name, category, gender, price_suggested, cover_image_url, id AS sheet_row';
                
                $stmt = $db->prepare(
                    "SELECT {$selectCols} 
                     FROM products 
                     WHERE sku IN ({$placeholders})
                     ORDER BY {$orderCol} DESC"
                );
                $stmt->execute($pageSkus);
                $pageProducts = $stmt->fetchAll();

                foreach ($pageProducts as $p) {
                    $skuClean = cleanSkuDisplay($p['sku']);
                    $family = extractRootSku($skuClean);
                    
                    if (!isset($grouped[$family])) {
                        $grouped[$family] = ['parent' => $p, 'children' => []];
                    } else {
                        if ($p['sku'] !== $grouped[$family]['parent']['sku']) {
                            $exists = false;
                            foreach ($grouped[$family]['children'] as $c) {
                                if ($c['sku'] === $p['sku']) { $exists = true; break; }
                            }
                            if (!$exists) {
                                $grouped[$family]['children'][] = $p;
                            }
                        }
                    }
                }
            }
        }
        } catch (\PDOException $e) {
            // failed to connect to db or execute query
        }

        ?>


        <?php if (!empty($pageRoots)): ?>
            <div class="section-header-landing">
                <h2>Productos recientes</h2>
                <span class="product-count"><?= $totalParents ?> referencias</span>
            </div>

            <div class="parent-grid">
                <?php $cardIndex = 0; ?>
                <?php foreach ($pageRoots as $root):
                    $group = $grouped[$root];
                    $parent = $group['parent'];
                    $children = $group['children'];
                    $childCount = count($children);
                    $coverUrl = $parent['cover_image_url'] ?? '';
                    $isVideo = str_starts_with($coverUrl, '[VIDEO]');
                    if ($isVideo) $coverUrl = substr($coverUrl, 7);
                ?>
                    <div class="parent-card">
                        <div style="text-decoration:none; color:inherit; display:block;">
                            <!-- Parent image -->
                            <a href="/producto/<?= rawurlencode($parent['sku']) ?>" class="dynamic-card-link" style="display:block;">
                                <div class="card-image" id="cover-<?= e($parent['sku']) ?>"
                                    data-sku="<?= e($parent['sku']) ?>"
                                    <?php if ($coverUrl): ?>
                                        data-cover="<?= e($coverUrl) ?>"
                                        data-video="<?= $isVideo ? '1' : '0' ?>"
                                    <?php endif; ?>>
                                    <?php $loadMode = ($cardIndex < 3 && $currentPage === 1) ? 'eager' : 'lazy'; ?>
                                    <?php if ($coverUrl): ?>
                                        <img src="<?= e($coverUrl) ?>" alt="<?= e($parent['name']) ?>"
                                            loading="<?= $loadMode ?>" class="img-fade-in"
                                            onload="this.classList.add('loaded')"
                                            onerror="this.outerHTML='<div class=\'cover-placeholder\'>📷</div>'">
                                    <?php else: ?>
                                        <div class="cover-placeholder">📷</div>
                                    <?php endif; ?>
                                </div>
                            </a>

                            <!-- Card body -->
                            <div class="card-body">
                                <a href="/producto/<?= rawurlencode($parent['sku']) ?>" class="dynamic-card-link" style="text-decoration:none; color:inherit; display:block;">
                                    <div class="card-sku dynamic-card-sku"><?= e(cleanSkuDisplay($parent['sku'])) ?></div>
                                    <div class="card-name"><?= e($parent['name']) ?></div>
                                </a>
                                <?php if ($parent['category']): ?>
                                    <div class="card-meta"><span><?= e($parent['category']) ?></span></div>
                                <?php endif; ?>

                                <?php if ($childCount > 0): ?>
                                    <div class="children-scroll-wrapper">
                                        <button class="children-scroll-btn scroll-left hidden" onclick="scrollChildren(this,-1)" type="button">◀</button>
                                        <div class="children-row">
                                            <?php
                                            foreach ($children as $child):
                                                $childCover = $child['cover_image_url'] ?? '';
                                                $childIsVideo = str_starts_with($childCover, '[VIDEO]');
                                                if ($childIsVideo) $childCover = substr($childCover, 7);
                                            ?>
                                                <div class="child-thumb" style="cursor:pointer;"
                                                    title="<?= e(cleanSkuDisplay($child['sku'])) ?>"
                                                    data-sku="<?= e($child['sku']) ?>"
                                                    onclick="previewVariant(this, '<?= rawurlencode($child['sku']) ?>', '<?= e(cleanSkuDisplay($child['sku'])) ?>')"
                                                    <?php if ($childCover): ?>data-cover="<?= e($childCover) ?>"<?php endif; ?>>
                                                    <?php if ($childCover): ?>
                                                        <img src="<?= e($childCover) ?>" alt="<?= e($child['sku']) ?>"
                                                            loading="lazy"
                                                            onerror="this.outerHTML='<span class=\'child-placeholder\'>📷</span>'">
                                                    <?php else: ?>
                                                        <span class="child-placeholder" data-sku="<?= e($child['sku']) ?>">📷</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button class="children-scroll-btn scroll-right" onclick="scrollChildren(this,1)" type="button">▶</button>
                                    </div>
                                    <div class="children-info">
                                        <span class="children-label"><?= $childCount ?> variante<?= $childCount > 1 ? 's' : '' ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Actions row (outside link to avoid navigation conflict) -->
                        <div class="card-actions">
                            <button class="btn-whatsapp card-wa-btn" style="width:100%"
                                data-sku="<?= e($parent['sku']) ?>"
                                data-name="<?= e(addslashes($parent['name'])) ?>"
                                onclick="event.preventDefault(); event.stopPropagation(); openShareModal(this.dataset.sku, this.dataset.name);"
                                title="Enviar por WhatsApp">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                                </svg>
                                Enviar
                            </button>
                        </div>
                    </div>
                <?php $cardIndex++; ?>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="/?page=<?= $currentPage - 1 ?>" class="page-btn">« Anterior</a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $currentPage - 2);
                    $end = min($totalPages, $currentPage + 2);
                    if ($start > 1) echo '<span class="page-dots">…</span>';
                    for ($i = $start; $i <= $end; $i++): ?>
                        <a href="/?page=<?= $i ?>"
                            class="page-btn <?= $i === $currentPage ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor;
                    if ($end < $totalPages) echo '<span class="page-dots">…</span>';
                    ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="/?page=<?= $currentPage + 1 ?>" class="page-btn">Siguiente »</a>
                    <?php endif; ?>
                    <span class="page-info">Página <?= $currentPage ?> de <?= $totalPages ?></span>
                </nav>
            <?php endif; ?>  <!-- End of if ($totalPages > 1) -->

        <?php else: ?>  <!-- Else of if (!empty($pageRoots)) -->
            <div class="empty-state fade-in">
                <div class="empty-icon">📦</div>
                <h3>Aún no hay productos</h3>
                <p>El catálogo está vacío. El administrador puede importar productos desde Excel.</p>
            </div>
        <?php endif; ?>
        </div> <!-- Fin de search-main -->

        <!-- ===== Álbumes (Sidebar Derecho) ===== -->
        <?php if (!empty($albums)): ?>
        <aside class="search-sidebar">
            <div class="sidebar-header">
                <span class="sidebar-title">📁 Álbumes</span>
                <span class="sidebar-album-count"><?= $totalAlbums ?></span>
            </div>
            <div class="sidebar-albums" id="sidebarAlbums">
                <?php foreach ($albums as $idx => $sa):
                    $href = '/buscar?album=' . urlencode($sa['drive_id']);
                    $hiddenClass = $idx >= 20 ? ' album-hidden' : '';
                ?>
                <a href="<?= $href ?>" class="sidebar-album-item<?= $hiddenClass ?>" title="<?= e($sa['name']) ?>">
                    <div class="sidebar-album-thumb">
                        <?php if ($sa['icon_url']): ?>
                            <img src="<?= e($sa['icon_url']) ?>" alt="<?= e($sa['name']) ?>"
                                 loading="lazy"
                                 onerror="this.outerHTML='<span style=\'font-size:1.2rem;\'>📁</span>'">
                        <?php else: ?>
                            <span style="font-size:1.2rem;">📁</span>
                        <?php endif; ?>
                    </div>
                    <span class="sidebar-album-name"><?= e($sa['name']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php if ($totalAlbums > 20): ?>
            <button class="sidebar-ver-mas" id="btnVerTodas" onclick="toggleAllAlbums()">
                📂 Ver todas las carpetas (<?= $totalAlbums ?>)
            </button>
            <script>
            function toggleAllAlbums() {
                const btn = document.getElementById('btnVerTodas');
                const hidden = document.querySelectorAll('.album-hidden');
                const showing = hidden.length > 0 && hidden[0].style.display !== 'none';
                
                if (!showing && hidden[0] && hidden[0].style.display !== 'flex') {
                    // Mostrar todas
                    hidden.forEach(el => { el.style.display = 'flex'; el.classList.add('album-revealed'); });
                    btn.innerHTML = '📁 Mostrar solo las principales';
                } else {
                    // Ocultar extras
                    hidden.forEach(el => { el.style.display = ''; el.classList.remove('album-revealed'); });
                    btn.innerHTML = '📂 Ver todas las carpetas (<?= $totalAlbums ?>)';
                }
            }
            </script>
            <?php endif; ?>
        </aside>
        <?php endif; ?>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>Esta es una app desarrollada por <strong>K GENIUS</strong> · Más información
                <a href="https://wa.me/573146116450" target="_blank" rel="noopener" style="color:var(--color-gold);text-decoration:underline;">escríbanos</a>
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
                    <div class="batch-wa-footer" id="batchWaFooter" style="display:none;">
                        <div class="batch-wa-info">
                            <label class="batch-select-all-label">
                                <input type="checkbox" id="batchSelectAll"> Seleccionar todas
                            </label>
                            <span id="batchSelectedCount" class="batch-selected-count">0 seleccionadas</span>
                        </div>
                        <button class="batch-wa-send" id="btnBatchWaSend">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                                <path
                                    d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                            </svg>
                            📲 Enviar por WhatsApp
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- WhatsApp Share Modal -->
    <script src="/assets/js/whatsapp_share.js?v=<?= APP_VERSION ?>"></script>
    <script>
        // Folders now use parent-grid layout (no horizontal scroll)
    </script>
    <script>
        // Lazy-load covers from Drive for products without cover_image_url
        document.addEventListener('DOMContentLoaded', () => {
            const placeholders = document.querySelectorAll('.cover-placeholder');
            if (placeholders.length === 0) return;

            // Collect SKUs that need covers
            const skuElements = {};
            placeholders.forEach(ph => {
                const cardImage = ph.closest('.card-image');
                if (cardImage && cardImage.dataset.sku) {
                    const sku = cardImage.dataset.sku;
                    if (!skuElements[sku]) skuElements[sku] = [];
                    skuElements[sku].push(cardImage);
                }
                // Also check child thumbnails
                const childThumb = ph.closest('.child-thumb');
                if (childThumb && childThumb.dataset.sku) {
                    const sku = childThumb.dataset.sku;
                    if (!skuElements[sku]) skuElements[sku] = [];
                    skuElements[sku].push(childThumb);
                }
            });

            const allSkus = Object.keys(skuElements);
            if (allSkus.length === 0) return;

            // Fetch in batches of 50
            const batchSize = 50;
            for (let i = 0; i < allSkus.length; i += batchSize) {
                const batch = allSkus.slice(i, i + batchSize);
                fetch('/api/covers/batch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ skus: batch })
                })
                .then(r => r.json())
                .then(data => {
                    const covers = data.covers || {};
                    Object.entries(covers).forEach(([sku, cover]) => {
                        if (!cover || !cover.url) return;
                        const elements = skuElements[sku] || [];
                        elements.forEach(el => {
                            const placeholder = el.querySelector('.cover-placeholder, .child-placeholder');
                            if (placeholder) {
                                const img = document.createElement('img');
                                img.src = cover.url;
                                img.alt = sku;
                                img.loading = 'lazy';
                                img.className = 'img-fade-in';
                                img.onload = () => img.classList.add('loaded');
                                img.onerror = () => { img.outerHTML = '<div class="cover-placeholder">📷</div>'; };
                                placeholder.replaceWith(img);
                            }
                        });
                    });
                })
                .catch(() => {}); // Silent fail — placeholders remain
            }
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
            const waFooter = document.getElementById('batchWaFooter');
            const selectAllCb = document.getElementById('batchSelectAll');
            const selectedCountEl = document.getElementById('batchSelectedCount');
            const btnWaSend = document.getElementById('btnBatchWaSend');

            let batchFoundItems = [];

            function getCodes() {
                return textarea.value.split('\n').map(l => l.trim()).filter(l => l.length > 0);
            }

            textarea.addEventListener('input', () => {
                const n = getCodes().length;
                countEl.textContent = n + (n === 1 ? ' código' : ' códigos');
            });

            btnOpen.addEventListener('click', (e) => {
                e.preventDefault();
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                textarea.focus();
            });

            function closeModal() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
            btnClose.addEventListener('click', closeModal);
            modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
            });

            btnBack.addEventListener('click', () => {
                resultsSection.style.display = 'none';
                inputSection.style.display = '';
                waFooter.style.display = 'none';
            });

            // --- Conteo de seleccionadas ---
            function updateSelectedCount() {
                const checks = resultsGrid.querySelectorAll('.batch-card-check:checked');
                const total = resultsGrid.querySelectorAll('.batch-card-check').length;
                selectedCountEl.textContent = checks.length + ' seleccionada' + (checks.length !== 1 ? 's' : '');
                btnWaSend.disabled = checks.length === 0;
                selectAllCb.checked = checks.length === total && total > 0;
                selectAllCb.indeterminate = checks.length > 0 && checks.length < total;
            }

            selectAllCb.addEventListener('change', () => {
                const checked = selectAllCb.checked;
                resultsGrid.querySelectorAll('.batch-card-check').forEach(cb => cb.checked = checked);
                updateSelectedCount();
            });

            btnWaSend.addEventListener('click', async () => {
                const checks = resultsGrid.querySelectorAll('.batch-card-check:checked');
                if (checks.length === 0) return;
                if (checks.length > 20) {
                    alert('⚠️ Máximo 10 productos a la vez.\n\nDeselecciona algunos y haz otro envío.');
                    return;
                }

                const selected = [];
                checks.forEach(cb => {
                    const idx = parseInt(cb.dataset.index);
                    if (batchFoundItems[idx]) selected.push(batchFoundItems[idx]);
                });

                const images = selected.filter(s => !s.isVideo);
                const videos = selected.filter(s => s.isVideo);

                // Avisar si hay videos
                if (videos.length > 0) {
                    const videoSkus = videos.map(v => v.sku).join(', ');
                    if (images.length > 0) {
                        alert('🎬 Los siguientes productos son videos:\n' + videoSkus + '\n\nLos videos no se pueden enviar directamente por WhatsApp.\nDescargalos primero a tu dispositivo desde la página del producto y compártelos manualmente.\n\nSe enviarán solo las ' + images.length + ' imágen(es).');
                    } else {
                        alert('🎬 Los productos seleccionados son videos:\n' + videoSkus + '\n\nLos videos no se pueden enviar directamente por WhatsApp.\nDescargalos primero a tu dispositivo desde la página del producto y compártelos manualmente.');
                        return;
                    }
                }

                if (images.length === 0) return;

                // Web Share API (mobile)
                const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                if (isMobile && navigator.canShare && navigator.share) {
                    btnWaSend.disabled = true;
                    btnWaSend.innerHTML = '<div class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></div> Preparando imágenes...';
                    try {
                        const files = (await Promise.all(images.map(async (item, i) => {
                            try {
                                const driveIdMatch = item.image ? item.image.match(/\/d\/([^=]+)/) : null;
                                const fetchUrl = driveIdMatch ? `/api/download/${driveIdMatch[1]}` : (item.image || `https://lh3.googleusercontent.com/d/${item.driveId}=s800`);
                                const resp = await fetch(fetchUrl, driveIdMatch ? {} : { mode: 'cors' });
                                const blob = await resp.blob();
                                return new File([blob], `imagen_${i + 1}.jpg`, { type: blob.type || 'image/jpeg' });
                            } catch { return null; }
                        }))).filter(Boolean);
                        if (files.length > 0 && navigator.canShare({ files })) {
                            await navigator.share({ files });
                            resetWaBtn();
                            return;
                        }
                    } catch (err) {
                        if (err.name === 'AbortError') { resetWaBtn(); return; }
                    }
                    resetWaBtn();
                }

                // Fallback desktop: WhatsApp con links
                let text = '📦 *Catálogo - ' + images.length + ' producto(s)*\n\n';
                images.forEach((item, i) => {
                    const productUrl = window.location.origin + '/producto/' + item.sku;
                    text += (i + 1) + '. *' + item.sku + '* - ' + item.name + '\n';
                    if (item.image) text += '🖼️ ' + item.image + '\n';
                    text += '🔗 ' + productUrl + '\n\n';
                });
                window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
            });

            function resetWaBtn() {
                btnWaSend.disabled = false;
                btnWaSend.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> 📲 Enviar por WhatsApp';
            }

            // --- Buscar lote ---
            btnSearch.addEventListener('click', () => {
                const codes = getCodes();
                if (codes.length === 0) { textarea.focus(); return; }

                inputSection.style.display = 'none';
                loadingSection.style.display = '';
                resultsSection.style.display = 'none';
                waFooter.style.display = 'none';
                batchFoundItems = [];

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
                        const needsCover = [];
                        resultsGrid.innerHTML = '';
                        batchFoundItems = [];

                        codes.forEach(code => {
                            const item = results[code];
                            const card = document.createElement('div');

                            if (item && item.sku) {
                                const idx = batchFoundItems.length;
                                batchFoundItems.push({
                                    sku: item.sku,
                                    name: item.name || '',
                                    image: (item.image || '').replace(/^\[VIDEO\]/, ''),
                                    isVideo: (item.image || '').startsWith('[VIDEO]')
                                });
                                found++;
                                card.className = 'batch-result-card found';
                                const imgHtml = item.image
                                    ? `<img src="${item.image}" alt="${item.sku}" loading="lazy" onerror="this.outerHTML='<div class=\\'batch-no-img\\'>📷</div>'">`
                                    : '<div class="batch-no-img batch-loading-img" data-sku="' + item.sku + '">⏳</div>';
                                card.innerHTML = `
                                    <label class="batch-card-label">
                                        <input type="checkbox" class="batch-card-check" data-index="${idx}" checked>
                                        <div class="batch-card-check-mark">✓</div>
                                        <a href="/producto/${item.sku}" class="batch-result-link" target="_blank" onclick="event.stopPropagation();">
                                            <div class="batch-result-img">${imgHtml}</div>
                                            <div class="batch-result-info">
                                                <span class="batch-result-sku">${item.sku}</span>
                                                <span class="batch-result-name">${item.name || ''}</span>
                                            </div>
                                        </a>
                                    </label>`;
                                if (!item.image) needsCover.push(item.sku);
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

                        // Listeners de checkboxes
                        resultsGrid.querySelectorAll('.batch-card-check').forEach(cb => {
                            cb.addEventListener('change', updateSelectedCount);
                        });

                        summaryEl.innerHTML = `<strong>${found}</strong> encontrado${found !== 1 ? 's' : ''} · <strong>${notFound}</strong> no encontrado${notFound !== 1 ? 's' : ''}`;
                        resultsSection.style.display = '';

                        if (found > 0) {
                            waFooter.style.display = '';
                            selectAllCb.checked = true;
                            updateSelectedCount();
                        }

                        // Buscar covers de Drive
                        if (needsCover.length > 0) {
                            fetch('/api/covers/batch', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ skus: needsCover })
                            })
                                .then(r => r.json())
                                .then(coverData => {
                                    const covers = coverData.covers || {};
                                    document.querySelectorAll('.batch-loading-img').forEach(el => {
                                        const sku = el.dataset.sku;
                                        const cover = covers[sku];
                                        if (cover && cover.url) {
                                            const imgContainer = el.closest('.batch-result-img');
                                            imgContainer.innerHTML = `<img src="${cover.url}" alt="${sku}" loading="lazy" onerror="this.outerHTML='<div class=\\'batch-no-img\\'>📷</div>'">`;
                                            const item = batchFoundItems.find(f => f.sku === sku);
                                            if (item) item.image = cover.url;
                                        } else {
                                            el.textContent = '📷';
                                            el.classList.remove('batch-loading-img');
                                        }
                                    });
                                })
                                .catch(() => {
                                    document.querySelectorAll('.batch-loading-img').forEach(el => {
                                        el.textContent = '📷';
                                        el.classList.remove('batch-loading-img');
                                    });
                                });
                        }
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

    <script>
        function previewVariant(thumbEl, skuEnc, skuLabel) {
            const card = thumbEl.closest('.parent-card');
            if (!card) return;

            // 1. Obtener imagen del thumb
            const thumbImg = thumbEl.querySelector('img');
            const newSrc = thumbImg ? thumbImg.src : null;
            if (!newSrc) return;

            // 2. Actualizar imagen principal (agrandar si es de drive thumbnail=s120 -> s600)
            const mainImgContainer = card.querySelector('.card-image');
            const mainImg = mainImgContainer.querySelector('img');
            const hiresUrl = newSrc.replace(/=s\d+/, '=s600'); 
            
            if (mainImg) {
                mainImg.src = hiresUrl;
            } else {
                const ph = mainImgContainer.querySelector('.cover-placeholder');
                if (ph) {
                    ph.outerHTML = `<img src="${hiresUrl}" class="img-fade-in loaded" style="width:100%;height:100%;object-fit:cover;">`;
                }
            }

            // 3. Actualizar links de la tarjeta para apuntar al hijo seleccionado
            const childUrl = '/producto/' + skuEnc;
            card.querySelectorAll('.dynamic-card-link').forEach(link => {
                link.href = childUrl;
            });

            // 4. Actualizar SKU visual
            const skuText = card.querySelector('.dynamic-card-sku');
            if (skuText) skuText.textContent = skuLabel;

            // 5. Actualizar botón de WhatsApp para compartir el hijo
            const waBtn = card.querySelector('.card-wa-btn');
            if (waBtn) {
                waBtn.dataset.sku = decodeURIComponent(skuEnc);
                waBtn.dataset.name = skuLabel;
            }

            // 6. Destacar miniatura activa visualmente
            card.querySelectorAll('.child-thumb').forEach(t => t.style.boxShadow = 'none');
            thumbEl.style.boxShadow = 'var(--glow-accent)';
        }

        function scrollChildren(btn, direction) {
            const wrapper = btn.closest('.children-scroll-wrapper');
            const row = wrapper.querySelector('.children-row');
            const scrollAmount = 120;
            row.scrollBy({ left: direction * scrollAmount, behavior: 'smooth' });
            setTimeout(() => {
                const leftBtn = wrapper.querySelector('.scroll-left');
                const rightBtn = wrapper.querySelector('.scroll-right');
                if (leftBtn) leftBtn.classList.toggle('hidden', row.scrollLeft <= 0);
                if (rightBtn) rightBtn.classList.toggle('hidden', row.scrollLeft + row.clientWidth >= row.scrollWidth - 2);
            }, 350);
        }

        // Auto-hide arrows on page load
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.children-scroll-wrapper').forEach(wrapper => {
                const row = wrapper.querySelector('.children-row');
                const leftBtn = wrapper.querySelector('.scroll-left');
                const rightBtn = wrapper.querySelector('.scroll-right');
                if (!row) return;
                const needsScroll = row.scrollWidth > row.clientWidth + 2;
                if (leftBtn) leftBtn.classList.add('hidden');
                if (rightBtn) rightBtn.classList.toggle('hidden', !needsScroll);
            });
        });
    </script>

    <script>
        // Lazy-load covers for child thumbnails with placeholders
        document.addEventListener('DOMContentLoaded', () => {
            const skuElements = {};

            // Main card images with placeholders
            document.querySelectorAll('.card-image .cover-placeholder').forEach(ph => {
                const cardImage = ph.closest('.card-image');
                if (cardImage && cardImage.dataset.sku) {
                    const sku = cardImage.dataset.sku;
                    if (!skuElements[sku]) skuElements[sku] = [];
                    skuElements[sku].push({ el: cardImage, type: 'main' });
                }
            });

            // Child thumbnails with placeholders
            document.querySelectorAll('.child-thumb').forEach(thumb => {
                const ph = thumb.querySelector('.child-placeholder');
                if (ph && thumb.dataset.sku) {
                    const sku = thumb.dataset.sku;
                    if (!skuElements[sku]) skuElements[sku] = [];
                    skuElements[sku].push({ el: thumb, type: 'child' });
                }
            });

            const allSkus = Object.keys(skuElements);
            if (allSkus.length === 0) return;

            const batchSize = 50;
            for (let i = 0; i < allSkus.length; i += batchSize) {
                const batch = allSkus.slice(i, i + batchSize);
                fetch('/api/covers/batch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ skus: batch })
                })
                .then(r => r.json())
                .then(data => {
                    const covers = data.covers || {};
                    Object.entries(covers).forEach(([sku, cover]) => {
                        if (!cover || !cover.url) return;
                        const entries = skuElements[sku] || [];
                        entries.forEach(entry => {
                            if (entry.type === 'main') {
                                const placeholder = entry.el.querySelector('.cover-placeholder');
                                if (placeholder) {
                                    const img = document.createElement('img');
                                    img.src = cover.url;
                                    img.alt = sku;
                                    img.loading = 'lazy';
                                    img.className = 'img-fade-in';
                                    img.onload = () => img.classList.add('loaded');
                                    img.onerror = () => { img.outerHTML = '<div class="cover-placeholder">📷</div>'; };
                                    placeholder.replaceWith(img);
                                }
                            } else {
                                const placeholder = entry.el.querySelector('.child-placeholder');
                                if (placeholder) {
                                    const img = document.createElement('img');
                                    img.src = cover.url;
                                    img.alt = sku;
                                    img.loading = 'lazy';
                                    img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                                    img.onerror = () => { img.outerHTML = '<span class="child-placeholder">📷</span>'; };
                                    placeholder.replaceWith(img);
                                }
                            }
                        });
                    });
                })
                .catch(() => {});
            }
        });
    </script>

    <!-- Autocomplete JS -->
    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
    <!-- QR Scanner (html5-qrcode CDN, solo carga bajo demanda en qr-scanner.js) -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" defer></script>
    <script src="/assets/js/qr-scanner.js?v=<?= APP_VERSION ?>" defer></script>
    <?php include __DIR__ . '/../partials/loading_overlay.php'; ?>
</body>

</html>