<?php
/**
 * Búsqueda con resultados — muestra grid de productos.
 * Soporta búsqueda múltiple: SKUs separados por comas o saltos de línea.
 */
$q = trim($_GET['q'] ?? '');
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($isAjax) {
    ob_start();
}
// Los álbumes ya no se usan: un enlace viejo /buscar?album=ID abre esa carpeta
if (!empty($_GET['album'])) {
    redirect('/carpeta/' . rawurlencode($_GET['album']));
}
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 60;
$offset = ($page - 1) * $perPage;

$db = getDB();

// ── Filtros (etapa B): en la URL, combinables, se aplican en la BD a todas las búsquedas ──
$filters = catalogFiltersFromRequest($_GET);
[$filterSql, $filterParams] = catalogFilterSql($db, $filters);
$hasFilters = catalogFiltersActive($filters);
// Opciones: solo valores con datos suficientes (5+ productos visibles)
$filterOptions = searchFilterOptions($db);

// album_id y parent_sku están garantizadas por migrations/017_ensure_runtime_columns.sql

// Detectar si es búsqueda multi-código (comas o saltos de línea)
$isMultiCode = (strpos($q, ',') !== false || strpos($q, "\n") !== false || strpos($q, "\r") !== false);
$multiCodes = [];

if ($isMultiCode && $q !== '') {
    // Parsear: separar por comas, saltos de línea, o ambos
    $raw = preg_split('/[\r\n,]+/', $q);
    foreach ($raw as $code) {
        $code = trim($code);
        if ($code !== '') {
            $multiCodes[] = $code;
        }
    }
    $multiCodes = array_unique($multiCodes);
    // Si después del parse solo queda 1, tratar como búsqueda normal
    if (count($multiCodes) <= 1) {
        $isMultiCode = false;
        $q = !empty($multiCodes) ? $multiCodes[0] : $q;
    }
}

if ($q === '' && !$isMultiCode) {
    // Sin query: mostrar todos
    $where = "p.archived = 0 AND p.cover_image_url > ''" . $filterSql;
    $params = $filterParams;

    $countStmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT p.sku, p.name, p.category, p.gender, p.price_suggested, " . stockSql('p') . " AS stock_now, p.cover_image_url, p.parent_sku
         FROM products p WHERE $where
         ORDER BY p.name ASC"
    );
    $stmt->execute($params);
    $allMatches = $stmt->fetchAll();

} elseif ($isMultiCode) {
    // BÚSQUEDA MULTI-CÓDIGO: buscar cada SKU con LIKE para mayor flexibilidad
    // Esto permite encontrar "1971-1" aunque en la BD esté como "1971-1.JPG"
    $conditions = [];
    $params = [];
    foreach ($multiCodes as $code) {
        $codeClean = preg_replace('/\.\w{2,4}$/i', '', $code);
        $conditions[] = "sku LIKE ?";
        $params[] = $codeClean . '%';
    }
    // Nombres de archivo en la lista ("S-640+S-647-3.jpg"): "sku LIKE" no los encuentra, así que se
    // busca el archivo por su nombre exacto en drive_index. Solo los que no pueden ser un código.
    $filePhotos = [];
    foreach ($multiCodes as $code) {
        if (looksLikeFileName($code)) $filePhotos += photosByFileName($db, $code, 5);
    }
    if ($filePhotos) {
        $conditions[] = "sku IN (" . implode(',', array_fill(0, count($filePhotos), '?')) . ")";
        array_push($params, ...array_keys($filePhotos));
    }
    $whereMulti = "archived = 0 AND cover_image_url > '' AND (" . implode(' OR ', $conditions) . ")" . $filterSql;
    $params = array_merge($params, $filterParams);
    
    $countStmt = $db->prepare(
        "SELECT COUNT(DISTINCT sku) FROM products p
         WHERE $whereMulti"
    );
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT sku, name, category, gender, price_suggested, " . stockSql() . " AS stock_now, cover_image_url, parent_sku 
         FROM products p
         WHERE $whereMulti
         GROUP BY sku
         ORDER BY sku ASC"
    );
    $stmt->execute($params);
    $allMatches = $stmt->fetchAll();
    // De los que entraron por nombre de archivo, la tarjeta muestra esa foto (es la que se pegó)
    foreach ($allMatches as $i => $m) {
        if (isset($filePhotos[$m['sku']])) {
            $allMatches[$i]['cover_image_url'] = coverStringForFile($filePhotos[$m['sku']]['id'], $filePhotos[$m['sku']]['video']);
        }
    }
    
    // Sin paginación para multi-código
    $perPage = max($total, 1);
} else {
    // Con query simple: código (sin importar "-", " ", ":", "."), nombre o categoría; multipalabra
    $qCleanForSku = preg_replace('/\.\w{2,4}$/i', '', $q);
    $words = array_filter(preg_split('/\s+/', trim($qCleanForSku)));
    
    $whereSimpleParts = [];
    $paramsBase = [];
    
    if (empty($words)) {
        $whereSimple = "p.archived = 0 AND p.cover_image_url > ''";
    } else {
        // Cada palabra, en cualquier orden: código (con o sin "M"), nombre, categoría, marca, color o carpeta de su foto
        [$whereSimpleParts, $paramsBase] = catalogWordsSql($words);
        $whereSimple = "p.archived = 0 AND p.cover_image_url > '' AND (" . implode(' AND ', $whereSimpleParts) . ")";
    }
    $whereSimple .= $filterSql;
    $paramsBase = array_merge($paramsBase, $filterParams);
    
    $countStmt = $db->prepare(
        "SELECT COUNT(DISTINCT p.sku) FROM products p WHERE $whereSimple"
    );
    $countStmt->execute($paramsBase);
    $total = $countStmt->fetchColumn();

    // Orden: 1) el código exacto (con o sin "M"), 2) los que empiezan por él, 3) el resto.
    // Dentro de cada grupo, por código, así las variantes quedan juntas (1409-1, 1409-2…).
    $forms = skuExactForms($qCleanForSku) ?: [''];
    $exactPh = implode(',', array_fill(0, count($forms), '?'));
    $startsSql = implode(' OR ', array_fill(0, count($forms), skuSearchSql('p.sku') . ' LIKE ?'));
    $stmt = $db->prepare(
        "SELECT p.sku, p.name, p.category, p.gender, p.price_suggested, " . stockSql('p') . " AS stock_now, p.cover_image_url, p.parent_sku 
         FROM products p
         WHERE $whereSimple
         GROUP BY p.sku
         ORDER BY 
            CASE WHEN " . skuSearchSql('p.sku') . " IN ($exactPh) THEN 0
                 WHEN $startsSql THEN 1
                 ELSE 2 END,
            CASE WHEN " . skuSearchSql('p.sku') . " IN ($exactPh) OR $startsSql THEN p.sku END ASC,
            p.name ASC"
    );
    $startsParams = array_map(fn($f) => addcslashes($f, '%_') . '%', $forms);
    $finalParams = array_merge($paramsBase, $forms, $startsParams, $forms, $startsParams);
    $stmt->execute($finalParams);
    $allMatches = $stmt->fetchAll();
}

// Pegar el nombre completo de una foto de Drive ("S-640+S-647-3.jpg"): su producto, con esa misma
// foto en la tarjeta. Esos nombres con dos códigos unidos ("+") no son un código, así que se busca
// el archivo por nombre en drive_index (photosByFileName) y se muestra el producto al que está enlazado.
if (empty($allMatches) && !$isMultiCode && $q !== '') {
    $filePhotos = photosByFileName($db, $q);
    if ($filePhotos) {
        $stmt = $db->prepare(
            "SELECT p.sku, p.name, p.category, p.gender, p.price_suggested, " . stockSql('p') . " AS stock_now, p.cover_image_url, p.parent_sku
             FROM products p
             WHERE p.archived = 0 AND p.cover_image_url > ''
               AND p.sku IN (" . implode(',', array_fill(0, count($filePhotos), '?')) . ")" . $filterSql . "
             ORDER BY p.sku ASC"
        );
        $stmt->execute(array_merge(array_keys($filePhotos), $filterParams));
        $allMatches = $stmt->fetchAll();
        // La tarjeta muestra la foto buscada, no la portada del producto (es la que se pegó)
        foreach ($allMatches as $i => $m) {
            if (isset($filePhotos[$m['sku']])) {
                $allMatches[$i]['cover_image_url'] = coverStringForFile($filePhotos[$m['sku']]['id'], $filePhotos[$m['sku']]['video']);
            }
        }
    }
}

// Búsqueda sin resultados (ni productos ni carpetas, sin filtros): se anota para Admin → 🧾 Registro
$logMissAfterFolders = empty($allMatches) && !$isMultiCode && $q !== '' && !$hasFilters;

// Sin resultados por un código mal escrito: "¿Quisiste decir…?" (los más parecidos, sin filtros)
$suggestions = (empty($allMatches) && !$isMultiCode && $q !== '' && !$hasFilters) ? searchSuggestions($db, $q) : [];

// Carpetas de Drive cuyo nombre coincide (cualquier nivel), desde la copia local (drive_index).
// La miniatura es una foto al azar de su contenido (cover_file_id). Las ocultas no salen.
$matchedFolders = [];
if (!$isMultiCode && $q !== '') {
    try {
        // Por nombre tal cual o como código ("M8363" encuentra la carpeta "M:8363" o "8363").
        // Primero la carpeta que se llama exactamente como lo buscado.
        $fForms = skuExactForms($q);
        $fLike = implode(' OR ', array_fill(0, count($fForms), skuSearchSql('f.name') . ' LIKE ?'));
        $fExactPh = implode(',', array_fill(0, max(1, count($fForms)), '?'));
        $folderStmt = $db->prepare(
            "SELECT f.file_id AS id, f.name, f.cover_file_id AS thumb_id
             FROM drive_index f
             WHERE f.is_folder = 1 AND f.is_hidden = 0 AND (f.name LIKE ?" . ($fLike ? " OR $fLike" : '') . ")
             ORDER BY CASE WHEN " . skuSearchSql('f.name') . " IN ($fExactPh) THEN 0 ELSE 1 END, f.name ASC
             LIMIT 20"
        );
        $folderStmt->execute(array_merge(
            ['%' . addcslashes($q, '%_') . '%'],
            array_map(fn($f) => '%' . addcslashes($f, '%_') . '%', $fForms),
            $fForms ?: ['']
        ));
        $condemned = condemnedIndex($db);
        foreach ($folderStmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if (isCondemnedFile($f['name'], $condemned)) continue; // carpeta de un código condenado
            $f['icon_url'] = $f['thumb_id'] ? lh3Url($f['thumb_id'], 400) : '';
            $matchedFolders[] = $f;
        }
    } catch (\PDOException $e) { /* drive_index aún no existe */ }
}
if (!empty($logMissAfterFolders) && empty($matchedFolders)) {
    ErrorLog::searchMiss($db, $q);
}

// Orden elegido en los filtros (precio y luego código, combinables); si no, el de la consulta
catalogSortRows($allMatches, $filters['oprecio'], $filters['ocodigo']);

// Una tarjeta por referencia (código), sin agrupar variantes: las variantes se ven al abrir la ficha.
// El orden es el de la consulta (en una búsqueda por código, el exacto va primero).
$bySku = [];
foreach ($allMatches as $m) {
    $bySku[$m['sku']] = $m;
}
$parentOrder = array_keys($bySku);
$totalParents = count($parentOrder);
$totalPages = max(1, ceil($totalParents / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pageRoots = array_slice($parentOrder, $offset, $perPage);
$grouped = [];
foreach ($pageRoots as $sku) {
    $grouped[$sku] = ['parent' => $bySku[$sku], 'children' => []];
}
unset($bySku);

// ¿El código buscado existe tal cual? Entonces su tarjeta va arriba de todo (antes que las carpetas).
$exactHit = false;
if (!$isMultiCode && $q !== '' && $page === 1 && $pageRoots) {
    $exactHit = in_array(strtolower(skuSearchNorm($pageRoots[0])), array_map('strtolower', skuExactForms($q)), true);
}

// cleanSkuDisplay() ahora definida en src/helpers.php
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
    <?php
    // Vista previa al compartir la búsqueda por WhatsApp: foto del primer resultado
    $shareImageId = null;
    array_walk_recursive($grouped, function ($v, $k) use (&$shareImageId) {
        if (!$shareImageId && $k === 'cover_image_url' && $v) $shareImageId = driveIdFromUrl($v);
    });
    echo shareMetaTags([
        'title' => $q ? $q . ' — Catálogo Kino' : 'Catálogo Kino',
        'description' => $totalParents . ' producto' . ($totalParents !== 1 ? 's' : '') . ' · fotos y videos',
        'url' => publicBaseUrl() . '/buscar' . ($q !== '' ? '?q=' . rawurlencode($q) : ''),
        'image_id' => $shareImageId,
    ]);
    ?>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}img{max-width:100%;height:auto}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <div style="display:flex; gap:0.4rem; align-items:center;">
                <?= pedidosHeaderBtn() ?>
                <a href="/enviar-catalogo" class="btn btn-sm btn-primary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;"><?= icon('send') ?> Enviar fotos con lista</a>
            </div>
        </div>
    </header>

    <section class="container search-layout" style="padding-top:2rem;">

        <!-- ===== MAIN: Búsqueda + Resultados ===== -->
        <div class="search-main" style="width: 100%; max-width: 100%;">
        <!-- Search bar inline -->
        <div class="search-box" style="max-width:800px; margin: 0 auto 1.5rem auto;">
            <span class="search-icon"><?= icon('search') ?></span>
            <form action="/buscar" method="GET" id="searchForm" style="width:100%;">
                <span class="search-field">
                    <textarea name="q" id="searchInput" rows="1"
                        placeholder="Buscar por SKU, referencia o nombre..." data-ph-short="Código o nombre…"
                        autocomplete="off"><?= e($q) ?></textarea>
                    <button type="button" class="search-clear" aria-label="Borrar búsqueda">✕</button>
                    <?php $imgSearchPart = 'button'; include __DIR__ . '/../../templates/partials/image_search.php'; ?>
                </span>
                <button type="submit" class="search-btn">Buscar</button>
            </form>
            <div class="autocomplete-dropdown" id="autocomplete"></div>
        </div>

        <?php include __DIR__ . '/../../templates/partials/filter_bar.php'; ?>

        <!-- Results count -->
        <div id="searchResultsContainer">
            <?php if ($isAjax) ob_clean(); ?>
        <p style="color:var(--color-text-muted); font-size:0.9rem; margin-bottom:1rem;">
            <?php if ($isMultiCode): ?>
                <?= $totalParents ?> resultado<?= $totalParents !== 1 ? 's' : '' ?> para <?= count($multiCodes) ?> código<?= count($multiCodes) !== 1 ? 's' : '' ?>
                <?php
                    // Verificar cuáles códigos no tuvieron ningún resultado (ni exacto ni hijo)
                    $allSkus = array_column($allMatches, 'sku');
                    $sinResultado = [];
                    foreach ($multiCodes as $code) {
                        $found = false;
                        foreach ($allSkus as $sku) {
                            if (stripos($sku, $code) === 0) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) $sinResultado[] = $code;
                    }
                    if (!empty($sinResultado)): ?>
                    <br><span style="color:var(--color-warning); font-size:0.85rem;">Sin resultados para: <?= e(implode(', ', $sinResultado)) ?></span>
                <?php endif; ?>
            <?php elseif ($q): ?>
                <?= $totalParents ?> resultado<?= $totalParents !== 1 ? 's' : '' ?> para "<strong style="color:var(--color-text)">
                    <?= e($q) ?>
                </strong>"
            <?php else: ?>
                <?= $totalParents ?> colección(es)
            <?php endif; ?>
        </p>

        <?php if (!$totalParents && empty($matchedFolders) && ($q !== '' || $hasFilters)): ?>
            <div class="search-empty">
                <?php if (!empty($suggestions['codes']) || !empty($suggestions['brands'])): ?>
                    <p class="search-empty-title">¿Quisiste decir…?</p>
                    <div class="suggest-row">
                        <?php foreach ($suggestions['brands'] as $b): ?>
                            <a class="suggest-chip" href="/buscar?<?= e(http_build_query(['marca' => $b])) ?>">Marca <strong><?= e($b) ?></strong></a>
                        <?php endforeach; ?>
                        <?php foreach ($suggestions['codes'] as $sg): $sgCover = parseStoredCover($sg['cover'], 120); ?>
                            <a class="suggest-chip" href="/buscar?<?= e(http_build_query(['q' => cleanSkuDisplay($sg['sku'])])) ?>">
                                <?php if ($sgCover['url'] && !$sgCover['video']): ?><img src="<?= e($sgCover['url']) ?>" alt="" loading="lazy"><?php endif; ?>
                                <strong><?= e(cleanSkuDisplay($sg['sku'])) ?></strong>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($q !== '' && !$hasFilters): ?>
                    <p>No encontramos "<?= e($q) ?>". Revisa el código o busca por nombre o marca.</p>
                <?php endif; ?>
                <?php if ($hasFilters): ?>
                    <p>Puede que los filtros estén ocultando resultados.
                        <a href="/buscar<?= $q !== '' ? '?' . e(http_build_query(['q' => $q])) : '' ?>">Quitar filtros</a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <?php ob_start(); // carpetas: van antes de los productos, salvo que el código buscado exista tal cual ?>
        <?php if (!empty($matchedFolders)): ?>
            <!-- Matched Folders -->
            <div class="section-header-landing" style="margin-bottom:1rem; border-top: 1px solid var(--color-border); padding-top:1.5rem;">
                <h2 style="font-size:1.2rem;"><?= icon('folder') ?> Carpetas encontradas</h2>
            </div>
            <div class="parent-grid folder-hits" style="margin-bottom:2rem;">
                <?php foreach ($matchedFolders as $idx => $folder): 
                    $fIcon = $folder['icon_url'] ?? '';
                ?>
                    <a href="/carpeta/<?= urlencode($folder['id']) ?>" class="parent-card" style="text-decoration:none; color:inherit; display:block;">
                        <div class="card-image">
                            <?php if ($fIcon): ?>
                                <img src="<?= e($fIcon) ?>" alt="<?= e(folderDisplayName($folder['name'])) ?>"
                                     loading="lazy"
                                     class="img-fade-in loaded"
                                     onerror="this.outerHTML='<div class=\'cover-placeholder\' style=\'font-size:3rem;\'>📂</div>'"
                                     style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <div class="cover-placeholder" style="font-size:3rem;">📂</div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="card-sku" style="font-size:0.7rem;">Carpeta</div>
                            <div class="card-name"><?= e(folderDisplayName($folder['name'])) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php $foldersHtml = ob_get_clean(); if (!$exactHit) echo $foldersHtml; ?>

        <?php if (!empty($pageRoots)): ?>

            <div class="parent-grid" id="productGrid">
                <?php $cardIndex = 0; ?>
                <?php foreach ($pageRoots as $root):
                    $group = $grouped[$root];
                    $p = $group['parent'];
                    $coverUrl = $p['cover_image_url'] ?? '';
                    $isVideo = str_starts_with($coverUrl, '[VIDEO]');
                    if ($isVideo) $coverUrl = substr($coverUrl, 7);
                ?>
                    <div class="parent-card search-selectable-card<?= ($exactHit && $cardIndex === 0) ? ' exact-hit' : '' ?>" data-category="<?= e(strtolower($p['category'] ?? '')) ?>">
                        <div style="text-decoration:none; color:inherit; display:block;">
                            <a href="/producto/<?= rawurlencode($p['sku']) ?>" class="dynamic-card-link" style="display:block;">
                                <div class="card-image" data-sku="<?= e($p['sku']) ?>"
                                     <?php if ($coverUrl): ?>
                                        data-cover="<?= e($coverUrl) ?>"
                                        data-video="<?= $isVideo ? '1' : '0' ?>"
                                     <?php endif; ?>
                                >
                                    <?php $loadMode = ($cardIndex < 3 && $page === 1) ? 'eager' : 'lazy'; ?>
                                    <?php if ($coverUrl): ?>
                                        <img src="<?= e($coverUrl) ?>" alt="<?= e($p['name']) ?>"
                                             <?= cardImgAttrs($coverUrl, $loadMode === 'eager') ?> class="img-fade-in"
                                             onload="this.classList.add('loaded')"
                                             onerror="this.outerHTML='<div class=\'cover-placeholder\'>📷</div>'">
                                    <?php else: ?>
                                        <div class="cover-placeholder"><?= icon('image') ?></div>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <div class="card-body">
                                <a href="/producto/<?= rawurlencode($p['sku']) ?>" class="dynamic-card-link" style="text-decoration:none; color:inherit; display:block;">
                                    <?php if ($exactHit && $cardIndex === 0): ?><div class="exact-hit-label">✓ Código exacto</div><?php endif; ?>
                                    <div class="card-sku dynamic-card-sku"><?= e(cleanSkuDisplay($p['sku'])) ?></div>
                                    <div class="card-name"><?= e($p['name']) ?></div>
                                    <?= priceTag($p['price_suggested'] ?? null, 'card-price', $p['stock_now'] ?? null) ?>
                                </a>
                                <?php if ($p['category']): ?>
                                    <div class="card-meta"><span><?= e($p['category']) ?></span></div>
                                <?php endif; ?>

                            </div>
                        </div>
                        <?= mediaActionButtons((string) driveIdFromUrl($coverUrl), $p['sku'], $p['sku'] . ' — ' . $p['name'], '/producto/' . rawurlencode($p['sku'])) ?>
                        <!-- Checkbox FUERA de la imagen -->
                        <label class="search-check-footer" style="border-top: 1px solid var(--color-border); margin-top: auto; border-radius: 0 0 var(--radius) var(--radius);">
                            <input type="checkbox" class="search-card-check"
                                   data-sku="<?= e($p['sku']) ?>"
                                   data-file-id="<?= e((string) driveIdFromUrl($coverUrl)) ?>"
                                   data-is-video="<?= $isVideo ? '1' : '0' ?>">
                            <span class="search-check-icon">✓</span>
                            <span class="search-check-text">Seleccionar</span>
                        </label>
                    </div>
                <?php if ($exactHit && $cardIndex === 0 && trim($foldersHtml) !== ''): ?>
                    <div class="grid-span-all"><?= $foldersHtml ?></div>
                <?php endif; ?>
                <?php $cardIndex++; endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php
                        $params = ['q' => $q] + array_filter($filters, fn($v) => $v !== '' && $v !== null && $v !== false) + ['page' => $i];
                        if (!empty($params['foto'])) $params['foto'] = '1';
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
        <?php elseif (empty($matchedFolders) && $q === '' && !$hasFilters): ?>
            <div class="no-results fade-in">
                <h3>Sin resultados</h3>
                <p>No encontramos productos con "
                    <?= e($q) ?>". Intenta con otro SKU o nombre.
                </p>
            </div>
        <?php endif; ?>
        <?php if ($isAjax) { echo ob_get_clean(); exit; } ?>
        </div> <!-- Fin de searchResultsContainer -->
        </div> <!-- Fin de search-main -->

    </section>

    <footer class="footer">
        <div class="container">
            <p>Esta es una app desarrollada por <strong>K GENIUS</strong> · Más información
                <a href="https://wa.me/573146116450" target="_blank" rel="noopener" style="color:var(--color-gold);text-decoration:underline;">escríbanos</a>
            </p>
        </div>
    </footer>

    <script src="/assets/js/search_page.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/media_actions.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/wa_batch.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
    <?php include __DIR__ . '/../../templates/partials/loading_overlay.php'; ?>
    <?php $imgSearchPart = 'modal'; include __DIR__ . '/../../templates/partials/image_search.php'; ?>
    <?php $mnavActive = 'buscar'; include __DIR__ . '/../../templates/partials/mobile_nav.php'; ?>
</body>

</html>