<?php header('Cache-Control: public, max-age=60'); ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Viewfinder — Centro de Contenido</title>
    <?= shareMetaTags([
        'title' => 'Catálogo Kino — Viewfinder',
        'description' => 'Centro de contenido exclusivo para distribuidores. Busca por SKU y descarga fotos y videos — Viewfinder Visor.',
        'url' => publicBaseUrl() . '/',
    ]) ?>
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
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
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
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <a href="/enviar-catalogo" class="btn btn-sm btn-primary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">📤 Enviar catálogo</a>
                <a href="/admin/login" class="btn btn-sm btn-secondary"
                    style="font-size:0.75rem; padding:0.4rem 0.8rem; opacity:0.4;">Admin</a>
            </div>
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
            // Carpetas de primer nivel de PRODUCTOS (las mantiene el sync de Drive), en el orden
            // de Drive: "1.POEDAGAR", "2.CURREN", ..., "17.NEPIC". Se abren como carpeta.
            $albums = $db->query("SELECT * FROM albums WHERE is_active = 1 ORDER BY CAST(name AS UNSIGNED) = 0, CAST(name AS UNSIGNED), name ASC")->fetchAll();
            // Sin ícono propio: foto al azar de la carpeta (copia local de Drive)
            if ($albums) {
                try {
                    // Foto al azar del contenido (la elige DriveIndex::refreshFolderState en cada sync)
                    $thumbStmt = $db->prepare("SELECT cover_file_id FROM drive_index WHERE file_id = ? AND is_folder = 1");
                    foreach ($albums as &$al) {
                        if (empty($al['icon_url'])) {
                            $thumbStmt->execute([$al['drive_id']]);
                            $tid = $thumbStmt->fetchColumn();
                            if ($tid) $al['icon_url'] = lh3Url($tid, 200);
                        }
                    }
                    unset($al);
                } catch (\PDOException $e) { /* drive_index aún no existe */ }
            }
        } catch (\PDOException $e) {
            // tabla albums aún no existe o DB apagada
        }
        ?>
        <!-- ===== MAIN: Hero (Search) ===== -->
        <div class="search-main" style="width: 100%; max-width: 100%;">
            <!-- Hero Integrado -->
            <div class="hero" style="padding: 2.5rem 0; text-align: center;">
                <h1 style="font-size: 3rem; margin-bottom: 0.5rem; letter-spacing: -0.5px;">Centro de Contenido</h1>
                <p style="margin: 0 auto 2rem; font-size: 1.1rem; color: var(--color-text-muted);">Busca por referencia o SKU para acceder a fotos y videos de alta calidad.</p>

                <!-- Search Box -->
                <div class="search-box" style="max-width: 600px; margin: 0 auto;">
                    <span class="search-icon">🔍</span>
                    <form action="/buscar" method="GET" id="searchForm" style="width:100%;">
                        <span class="search-field">
                            <textarea name="q" id="searchInput" rows="1"
                                placeholder="Buscar por SKU, referencia o nombre..." data-ph-short="Código o nombre…"
                                autocomplete="off" autofocus></textarea>
                            <button type="button" class="search-clear" aria-label="Borrar búsqueda">✕</button>
                        </span>
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

            <!-- ===== Carpetas de primer nivel (se navegan como en Drive) ===== -->
            <?php if (!empty($albums)): ?>
            <div id="carpetas" style="margin-bottom: 3rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                    <h2 style="font-size: 1.5rem; margin:0;">📂 Carpetas</h2>
                    <span style="color:var(--color-text-muted); font-size:0.9rem;"><?= $totalAlbums ?> carpetas</span>
                </div>
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem;" id="horizontalAlbums">
                    <?php foreach ($albums as $idx => $sa):
                        $href = '/carpeta/' . urlencode($sa['drive_id']);
                        $hiddenClass = $idx >= 10 ? ' album-hidden' : '';
                    ?>
                    <a href="<?= $href ?>" class="sidebar-album-item<?= $hiddenClass ?>" title="<?= e($sa['name']) ?>" style="background:var(--color-surface); padding:0.75rem; border-radius:var(--radius); border:1px solid var(--color-border); flex-direction:column; text-align:center; transition: transform 0.2s; <?php if($idx>=10) echo 'display:none;'; ?>">
                        <div class="sidebar-album-thumb" style="width:60px; height:60px; margin:0 auto 0.75rem auto; border-radius:50%; background:var(--color-surface-2);">
                            <?php if ($sa['icon_url']): ?>
                                <img src="<?= e($sa['icon_url']) ?>" alt="<?= e($sa['name']) ?>"
                                     loading="lazy"
                                     style="width:100%; height:100%; object-fit:cover; border-radius:50%;"
                                     onerror="this.outerHTML='<span style=\'font-size:1.8rem; line-height:60px;\'>📁</span>'">
                            <?php else: ?>
                                <span style="font-size:1.8rem; line-height:60px;">📁</span>
                            <?php endif; ?>
                        </div>
                        <span class="sidebar-album-name" style="font-weight:600; font-size:0.95rem; text-wrap: balance;"><?= e($sa['name']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($totalAlbums > 10): ?>
                <div style="text-align:center; margin-top:1.5rem;">
                    <button class="btn btn-secondary" id="btnVerTodas" onclick="toggleAllAlbums()">
                        📂 Mostrar más carpetas
                    </button>
                </div>
                <script>
                function toggleAllAlbums() {
                    const btn = document.getElementById('btnVerTodas');
                    const hidden = document.querySelectorAll('.album-hidden');
                    const showing = hidden.length > 0 && hidden[0].style.display !== 'none';
                    
                    if (!showing && hidden[0]) {
                        // Mostrar todas
                        hidden.forEach(el => { el.style.display = 'flex'; });
                        btn.innerHTML = '📁 Mostrar menos';
                    } else {
                        // Ocultar extras
                        hidden.forEach(el => { el.style.display = 'none'; });
                        btn.innerHTML = '📂 Mostrar más carpetas';
                    }
                }
                </script>
                <?php endif; ?>
            </div>
            <?php endif; ?>



        <!-- Recent / Featured Products -->
        <div id="searchResultsContainer">
        <?php
        $perPage = 60;
        $currentPage = max(1, intval($_GET['page'] ?? 1));
        
        $db = null;
        $pageRoots = [];
        $totalParents = 0;
        $totalSkus = 0;
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
                "SELECT sku, parent_sku, {$orderCol} AS sheet_row" . ($albumCol ? ', album_id' : '') . " 
                 FROM products 
                 WHERE archived = 0 
                 {$orderClause}"
            )->fetchAll();
            $totalSkus = count($skuRows); // códigos visibles (cada variante cuenta)

            // Agrupar SKUs por familia
            $familyMap = [];      // family -> [skus]
            $familyMaxRow = [];   // family -> max sheet_row
            foreach ($skuRows as $rowIdx => $row) {
                $family = familyKey($row); // misma regla de familia que la búsqueda
            
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
                    ? 'id, sku, parent_sku, name, category, gender, price_suggested, cover_image_url, sheet_row'
                    : 'id, sku, parent_sku, name, category, gender, price_suggested, cover_image_url, id AS sheet_row';
                
                $stmt = $db->prepare(
                    "SELECT {$selectCols} 
                     FROM products 
                     WHERE sku IN ({$placeholders})
                     ORDER BY {$orderCol} DESC"
                );
                $stmt->execute($pageSkus);
                $pageProducts = $stmt->fetchAll();

                // Agrupar por familia (sin duplicados por SKU)
                $members = [];
                foreach ($pageProducts as $p) {
                    $members[familyKey($p)][$p['sku']] = $p;
                }
                // Principal de la tarjeta: el SKU que ES la raíz de la familia (el padre
                // real, igual que en la búsqueda); si no existe, el más reciente.
                foreach ($members as $family => $bySku) {
                    $parent = $bySku[$family] ?? reset($bySku);
                    unset($bySku[$parent['sku']]);
                    $grouped[$family] = ['parent' => $parent, 'children' => array_values($bySku)];
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
                <span class="product-count" title="Cada tarjeta agrupa una familia (el código -1 y sus variantes)"><?= number_format($totalSkus, 0, ',', '.') ?> referencias · <?= number_format($totalParents, 0, ',', '.') ?> familias</span>
            </div>

            <div class="parent-grid">
                <?php $cardIndex = 0; ?>
                <?php foreach ($pageRoots as $root):
                    $group = $grouped[$root];
                    $parent = $group['parent'];
                    $children = $group['children'];
                    $childCount = count($children);
                    // Igual que la búsqueda: si el padre no tiene foto, la tarjeta usa la de una variante
                    $coverUrl = $parent['cover_image_url'] ?? '';
                    foreach ($children as $c) {
                        if ($coverUrl) break;
                        $coverUrl = $c['cover_image_url'] ?? '';
                    }
                    $isVideo = str_starts_with($coverUrl, '[VIDEO]');
                    if ($isVideo) $coverUrl = substr($coverUrl, 7);
                ?>
                    <div class="parent-card search-selectable-card">
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
                                            <?= cardImgAttrs($coverUrl, $loadMode === 'eager') ?> class="img-fade-in"
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
                                                $childCover = parseStoredCover($child['cover_image_url'] ?? '', 150)['url'];
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
                        <!-- WhatsApp + Descargar (fuera del enlace para no abrir la ficha) -->
                        <?= mediaActionButtons((string) driveIdFromUrl($coverUrl), $parent['sku'], $parent['sku'] . ' — ' . $parent['name'], '/producto/' . rawurlencode($parent['sku'])) ?>
                        <label class="search-check-footer" style="border-top: 1px solid var(--color-border); margin-top: auto;">
                            <input type="checkbox" class="search-card-check"
                                   data-sku="<?= e($parent['sku']) ?>"
                                   data-file-id="<?= e((string) driveIdFromUrl($coverUrl)) ?>"
                                   data-is-video="<?= $isVideo ? '1' : '0' ?>">
                            <span class="search-check-icon">✓</span>
                            <span class="search-check-text">Seleccionar</span>
                        </label>
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
        </div> <!-- Fin de searchResultsContainer -->
        </div> <!-- Fin de search-main -->

        <!-- Fin de Álbumes extraídos -->
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
    <script src="/assets/js/media_actions.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/wa_batch.js?v=<?= APP_VERSION ?>"></script>
    <script>
        // Folders now use parent-grid layout (no horizontal scroll)
    </script>

    <!-- Batch Search JS -->
    <script src="/assets/js/landing.js?v=<?= APP_VERSION ?>"></script>

    <!-- Autocomplete JS -->
    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
    <!-- QR Scanner: qr-scanner.js descarga html5-qrcode solo al abrir el escáner -->
    <script src="/assets/js/qr-scanner.js?v=<?= APP_VERSION ?>" defer></script>
    <?php include __DIR__ . '/../partials/loading_overlay.php'; ?>
    <?php $mnavActive = 'inicio'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>