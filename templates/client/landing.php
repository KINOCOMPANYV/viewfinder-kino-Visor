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
                <a href="/enviar-catalogo" class="btn btn-sm btn-primary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">📤 Enviar fotos con lista</a>
                <a href="/extractor" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;" title="Leer un recibo o pedido en PDF">🧾 Extractor</a>
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
            // Carpetas de primer nivel de PRODUCTOS (las mantiene el sync de Drive), en el orden
            // de Drive: "1.POEDAGAR", "2.CURREN", ..., "17.NEPIC". Se abren como carpeta.
            // Sin las que el admin sacó de las listas (siguen en búsqueda y su contenido se ve).
            // Foto al azar de cada carpeta (la elige DriveIndex::refreshFolderState en cada sync) en la misma consulta.
            $albumSql = "SELECT a.*, d.cover_file_id FROM albums a
                         LEFT JOIN drive_index d ON d.file_id = a.drive_id AND d.is_folder = 1
                         WHERE a.is_active = 1%s
                         ORDER BY CAST(a.name AS UNSIGNED) = 0, CAST(a.name AS UNSIGNED), a.name ASC";
            try {
                $albums = $db->query(sprintf($albumSql, " AND a.drive_id NOT IN (SELECT folder_id FROM folder_settings WHERE hide_in_lists = 1)"))->fetchAll();
            } catch (\PDOException $e) {
                $albums = $db->query(sprintf($albumSql, ''))->fetchAll(); // migración 029 pendiente
            }
            $totalAlbums = count($albums);
            foreach ($albums as &$al) {
                if (empty($al['icon_url']) && !empty($al['cover_file_id'])) $al['icon_url'] = lh3Url($al['cover_file_id'], 200);
            }
            unset($al);
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
                            <?php $imgSearchPart = 'button'; include __DIR__ . '/../partials/image_search.php'; ?>
                        </span>
                        <div class="search-actions">
                            <button type="submit" class="search-btn">Buscar</button>
                        </div>
                    </form>
                    <div class="autocomplete-dropdown" id="autocomplete"></div>
                </div>
                <?php if ($db): ?>
                    <div class="filter-wrap"><?php include __DIR__ . '/../partials/filter_bar.php'; ?></div>
                <?php endif; ?>
            </div>

            <?php
            // Novedades (etapa H): enlace solo si llegó algo en los últimos 7 días
            $newCount = 0;
            try { if ($db) $newCount = (int) $db->query("SELECT COUNT(*) FROM drive_index WHERE is_folder = 0 AND is_hidden = 0 AND first_seen_at >= NOW() - INTERVAL 7 DAY AND (mime_type LIKE 'image/%' OR mime_type LIKE 'video/%')")->fetchColumn(); } catch (\PDOException $e) {}
            ?>
            <?php if ($newCount): ?>
                <a href="/novedades" class="nw-link">🆕 <strong><?= number_format($newCount) ?></strong> foto<?= $newCount === 1 ? '' : 's' ?> nueva<?= $newCount === 1 ? '' : 's' ?> esta semana <span>Ver novedades →</span></a>
            <?php endif; ?>

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
                    <a href="<?= $href ?>" class="sidebar-album-item<?= $hiddenClass ?>" title="<?= e(folderDisplayName($sa['name'])) ?>" style="background:var(--color-surface); padding:0.75rem; border-radius:var(--radius); border:1px solid var(--color-border); flex-direction:column; text-align:center; transition: transform 0.2s; <?php if($idx>=10) echo 'display:none;'; ?>">
                        <div class="sidebar-album-thumb" style="width:60px; height:60px; margin:0 auto 0.75rem auto; border-radius:50%; background:var(--color-surface-2);">
                            <?php if ($sa['icon_url']): ?>
                                <img src="<?= e($sa['icon_url']) ?>" alt="<?= e(folderDisplayName($sa['name'])) ?>"
                                     loading="lazy"
                                     style="width:100%; height:100%; object-fit:cover; border-radius:50%;"
                                     onerror="this.outerHTML='<span style=\'font-size:1.8rem; line-height:60px;\'>📁</span>'">
                            <?php else: ?>
                                <span style="font-size:1.8rem; line-height:60px;">📁</span>
                            <?php endif; ?>
                        </div>
                        <span class="sidebar-album-name" style="font-weight:600; font-size:0.95rem; text-wrap: balance;"><?= e(folderDisplayName($sa['name'])) ?></span>
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
            // Productos visibles, los de la carpeta Poedagar primero y luego por fila de la hoja (más nuevos arriba).
            // Una tarjeta por referencia (código); las variantes se ven al abrir la ficha.
            // La página se corta en MySQL (LIMIT): no se cargan todos los códigos para mostrar 60.
            $visibleWhere = "archived = 0 AND cover_image_url > ''";
            $totalSkus = (int) $db->query("SELECT COUNT(*) FROM products WHERE $visibleWhere")->fetchColumn();
            $totalParents = $totalSkus;
            $totalPages = max(1, (int) ceil($totalParents / $perPage));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $perPage;

            $poedagarAlbumId = $db->query("SELECT drive_id FROM albums WHERE LOWER(name) LIKE '%poedagar%' AND is_active = 1 LIMIT 1")->fetchColumn();
            $orderClause = $poedagarAlbumId
                ? "ORDER BY CASE WHEN album_id = " . $db->quote($poedagarAlbumId) . " THEN 0 ELSE 1 END, sheet_row DESC, id DESC"
                : "ORDER BY sheet_row DESC, id DESC";
            $rows = $db->query(
                "SELECT id, sku, parent_sku, name, category, gender, price_suggested, " . stockSql() . " AS stock_now, cover_image_url
                 FROM products WHERE $visibleWhere $orderClause LIMIT $perPage OFFSET $offset"
            )->fetchAll();
            foreach ($rows as $p) {
                $grouped[$p['sku']] = ['parent' => $p, 'children' => []];
            }
            $pageRoots = array_map('strval', array_column($rows, 'sku'));
        } catch (\PDOException $e) {
            // failed to connect to db or execute query
        }

        ?>


        <?php if (!empty($pageRoots)): ?>
            <div class="section-header-landing">
                <h2>Productos recientes</h2>
                <span class="product-count" title="Una tarjeta por referencia; las variantes se ven al abrir el producto"><?= number_format($totalSkus, 0, ',', '.') ?> referencias</span>
            </div>

            <div class="parent-grid">
                <?php $cardIndex = 0; ?>
                <?php foreach ($pageRoots as $root):
                    $group = $grouped[$root];
                    $parent = $group['parent'];
                    $coverUrl = $parent['cover_image_url'] ?? '';
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
                                    <?= priceTag($parent['price_suggested'] ?? null, 'card-price', $parent['stock_now'] ?? null) ?>
                                </a>
                                <?php if ($parent['category']): ?>
                                    <div class="card-meta"><span><?= e($parent['category']) ?></span></div>
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

    <!-- WhatsApp Share Modal -->
    <script src="/assets/js/whatsapp_share.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/media_actions.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/wa_batch.js?v=<?= APP_VERSION ?>"></script>

    <script src="/assets/js/landing.js?v=<?= APP_VERSION ?>"></script>

    <!-- Autocomplete JS -->
    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
    <?php include __DIR__ . '/../partials/loading_overlay.php'; ?>
    <?php $imgSearchPart = 'modal'; include __DIR__ . '/../partials/image_search.php'; ?>
    <?php $mnavActive = 'inicio'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>