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
$filters = [
    'marca' => trim((string) ($_GET['marca'] ?? '')),
    'cat' => trim((string) ($_GET['cat'] ?? '')),
    'pmin' => is_numeric($_GET['pmin'] ?? null) ? (float) $_GET['pmin'] : null,
    'pmax' => is_numeric($_GET['pmax'] ?? null) ? (float) $_GET['pmax'] : null,
    'foto' => ($_GET['foto'] ?? '') === '1',
];
$filterSql = '';
$filterParams = [];
if ($filters['marca'] !== '') { $filterSql .= ' AND p.brand = ?'; $filterParams[] = $filters['marca']; }
if ($filters['cat'] !== '') { $filterSql .= ' AND p.category = ?'; $filterParams[] = $filters['cat']; }
if ($filters['pmin'] !== null) { $filterSql .= ' AND p.price_suggested >= ?'; $filterParams[] = $filters['pmin']; }
if ($filters['pmax'] !== null) { $filterSql .= ' AND p.price_suggested > 0 AND p.price_suggested <= ?'; $filterParams[] = $filters['pmax']; }
if ($filters['foto']) { $filterSql .= " AND p.cover_image_url IS NOT NULL AND p.cover_image_url <> ''"; }
$hasFilters = $filterSql !== '';
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
    $where = "p.archived = 0" . $filterSql;
    $params = $filterParams;

    $countStmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT p.sku, p.name, p.category, p.gender, p.price_suggested, p.cover_image_url, p.parent_sku
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
    $whereMulti = "archived = 0 AND (" . implode(' OR ', $conditions) . ")" . $filterSql;
    $params = array_merge($params, $filterParams);
    
    $countStmt = $db->prepare(
        "SELECT COUNT(DISTINCT sku) FROM products p
         WHERE $whereMulti"
    );
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT sku, name, category, gender, price_suggested, cover_image_url, parent_sku 
         FROM products p
         WHERE $whereMulti
         GROUP BY sku
         ORDER BY sku ASC"
    );
    $stmt->execute($params);
    $allMatches = $stmt->fetchAll();
    
    // Sin paginación para multi-código
    $perPage = max($total, 1);
} else {
    // Con query simple: código (sin importar "-", " ", ":", "."), nombre o categoría; multipalabra
    $qCleanForSku = preg_replace('/\.\w{2,4}$/i', '', $q);
    $words = array_filter(preg_split('/\s+/', trim($qCleanForSku)));
    
    $whereSimpleParts = [];
    $paramsBase = [];
    
    if (empty($words)) {
        $whereSimple = "p.archived = 0";
    } else {
        foreach ($words as $word) {
            $wordEscaped = addcslashes($word, '%_');
            $wordLike = "%{$wordEscaped}%";
            
            // "M8363-3" encuentra "M:8363-3"
            $wordNoDash = skuSearchNorm($word);
            $wordNoDashEscaped = addcslashes($wordNoDash, '%_');
            $wordLikeNoDash = "%{$wordNoDashEscaped}%";

            // Para cada palabra, buscar en los distintos campos
            $whereSimpleParts[] = "(" . skuSearchSql('p.sku') . " LIKE ? OR p.name LIKE ? OR p.category LIKE ?)";
            $paramsBase[] = $wordLikeNoDash;
            $paramsBase[] = $wordLike;
            $paramsBase[] = $wordLike;
        }
        $whereSimple = "p.archived = 0 AND (" . implode(' AND ', $whereSimpleParts) . ")";
    }
    $whereSimple .= $filterSql;
    $paramsBase = array_merge($paramsBase, $filterParams);
    
    $countStmt = $db->prepare(
        "SELECT COUNT(DISTINCT p.sku) FROM products p WHERE $whereSimple"
    );
    $countStmt->execute($paramsBase);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT p.sku, p.name, p.category, p.gender, p.price_suggested, p.cover_image_url, p.parent_sku 
         FROM products p
         WHERE $whereSimple
         GROUP BY p.sku
         ORDER BY 
            CASE WHEN " . skuSearchSql('p.sku') . " = ? THEN 0
                 WHEN " . skuSearchSql('p.sku') . " LIKE ? THEN 1
                 ELSE 2 END,
            p.name ASC"
    );
    $qCleanEscaped = addcslashes(skuSearchNorm($qCleanForSku), '%_');
    $startsWith = "{$qCleanEscaped}%";
    $exactMatch = skuSearchNorm($qCleanForSku);
    
    $finalParams = array_merge($paramsBase, [$exactMatch, $startsWith]);
    $stmt->execute($finalParams);
    $allMatches = $stmt->fetchAll();
}

// Sin resultados por un código mal escrito: "¿Quisiste decir…?" (los más parecidos, sin filtros)
$suggestions = (empty($allMatches) && !$isMultiCode && $q !== '' && !$hasFilters) ? searchSuggestions($db, $q) : [];

// Carpetas de Drive cuyo nombre coincide (cualquier nivel), desde la copia local (drive_index).
// La miniatura es una foto al azar de su contenido (cover_file_id). Las ocultas no salen.
$matchedFolders = [];
if (!$isMultiCode && $q !== '') {
    try {
        $folderStmt = $db->prepare(
            "SELECT f.file_id AS id, f.name, f.cover_file_id AS thumb_id
             FROM drive_index f
             WHERE f.is_folder = 1 AND f.is_hidden = 0 AND f.name LIKE ?
             ORDER BY f.name ASC LIMIT 20"
        );
        $folderStmt->execute(['%' . addcslashes($q, '%_') . '%']);
        foreach ($folderStmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $f['icon_url'] = $f['thumb_id'] ? lh3Url($f['thumb_id'], 400) : '';
            $matchedFolders[] = $f;
        }
    } catch (\PDOException $e) { /* drive_index aún no existe */ }
}

// Identificar familias (parents) de todos los matches (familyKey: parent_sku o raíz del SKU)
$rootsFound = [];
foreach ($allMatches as $m) {
    $family = familyKey($m);
    $rootsFound[$family] = true;
}
$parentOrder = array_keys($rootsFound);
$totalParents = count($parentOrder);
$totalPages = max(1, ceil($totalParents / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pageRoots = array_slice($parentOrder, $offset, $perPage);

// ── Cargar familias completas para mostrar tarjetas íntegras ──
$grouped = [];
if (!empty($pageRoots)) {
    $placeholders = implode(',', array_fill(0, count($pageRoots), '?'));
    $fetchParams = $pageRoots;
    
    // Buscar productos que tengan este parent_sku O cuyo extractRootSku coincida (fallback)
    // Para el fallback en SQL usamos una aproximación: LIKE 'root%' 
    $whereOrConditions = ["p.parent_sku IN ($placeholders)"];
    foreach ($pageRoots as $root) {
        $whereOrConditions[] = "(p.sku = ? OR p.sku LIKE ?)";
        $fetchParams[] = $root;
        $fetchParams[] = addcslashes($root, '%_') . '-%';
    }
    $whereOr = implode(' OR ', $whereOrConditions);
    
    $stmt = $db->prepare(
        "SELECT p.sku, p.name, p.category, p.gender, p.price_suggested, p.cover_image_url, p.parent_sku 
         FROM products p
         WHERE p.archived = 0 AND ({$whereOr})
         ORDER BY p.sku ASC"
    );
    $stmt->execute($fetchParams);
    $familyProducts = $stmt->fetchAll();
    
    // Normaliza un código para comparar SKUs sin guiones/espacios/extensión.
    $normCode = fn($s) => strtolower(str_replace(['-', ' ', '.'], '', preg_replace('/\.\w{2,4}$/i', '', (string)$s)));

    // Código buscado (solo en búsquedas por un código puntual, no por nombre ni multi-código).
    // Si coincide con el SKU de una variante, esa variante será la PRINCIPAL de la tarjeta.
    $searchedNorm = (!$isMultiCode && $q !== '') ? $normCode($q) : '';

    foreach ($familyProducts as $p) {
        $family = familyKey($p);

        // Solo procesar si el producto pertenece a una de las familias de esta página
        if (!in_array($family, $pageRoots)) continue;

        if (!isset($grouped[$family])) {
            $grouped[$family] = ['parent' => null, 'children' => []];
        }

        $grouped[$family]['children'][] = $p;
    }

    foreach ($grouped as $family => &$group) {
        // Elegir el nodo PRINCIPAL de la tarjeta por prioridad:
        //   1) El código buscado (cuando se busca un código puntual) → sale ese código
        //   2) El SKU que coincide con la raíz de familia (el padre real)
        //   3) El primero disponible
        $chosen = null;
        if ($searchedNorm !== '') {
            foreach ($group['children'] as $c) {
                if ($normCode($c['sku']) === $searchedNorm) { $chosen = $c; break; }
            }
        }
        if (!$chosen) {
            foreach ($group['children'] as $c) {
                if ($c['sku'] === $family) { $chosen = $c; break; }
            }
        }
        $group['parent'] = $chosen ?: ($group['children'][0] ?? null);

        // Ordenar variantes: las coincidencias con la búsqueda primero
        usort($group['children'], function($a, $b) use ($q) {
            if (empty($q)) return strcmp($a['sku'], $b['sku']);
            $scoreA = (stripos($a['sku'], $q) !== false || stripos($a['name'], $q) !== false) ? 1 : 0;
            $scoreB = (stripos($b['sku'], $q) !== false || stripos($b['name'], $q) !== false) ? 1 : 0;
            if ($scoreA !== $scoreB) return $scoreB - $scoreA;
            return strcmp($a['sku'], $b['sku']);
        });
    }
    unset($group);
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
    <style>body{background:#0a0a0f;color:#e8e8f0}img{max-width:100%;height:auto}</style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/enviar-catalogo" class="btn btn-sm btn-primary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">📤 Enviar catálogo</a>
        </div>
    </header>

    <section class="container search-layout" style="padding-top:2rem;">

        <!-- ===== MAIN: Búsqueda + Resultados ===== -->
        <div class="search-main" style="width: 100%; max-width: 100%;">
        <!-- Search bar inline -->
        <div class="search-box" style="max-width:800px; margin: 0 auto 1.5rem auto;">
            <span class="search-icon">🔍</span>
            <form action="/buscar" method="GET" id="searchForm" style="width:100%;">
                <span class="search-field">
                    <textarea name="q" id="searchInput" rows="1"
                        placeholder="Buscar por SKU, referencia o nombre..." data-ph-short="Código o nombre…"
                        autocomplete="off"><?= e($q) ?></textarea>
                    <button type="button" class="search-clear" aria-label="Borrar búsqueda">✕</button>
                </span>
                <button type="submit" class="search-btn">Buscar</button>
            </form>
            <div class="autocomplete-dropdown" id="autocomplete"></div>
        </div>

        <?php
        // Filtros: un formulario GET (la URL los guarda, Atrás los conserva). Cambiar uno vuelve a buscar.
        $fo = $filterOptions;
        $filterUrl = function (array $drop) use ($q, $filters): string {
            $qs = ['q' => $q] + array_filter($filters, fn($v) => $v !== '' && $v !== null && $v !== false);
            if (!empty($qs['foto'])) $qs['foto'] = '1';
            foreach ($drop as $k) unset($qs[$k]);
            return '/buscar?' . http_build_query(array_filter($qs, fn($v) => $v !== ''));
        };
        $fmtPrice = fn($v) => '$' . number_format((float) $v, 0, ',', '.');
        $activeChips = [];
        if ($filters['marca'] !== '') $activeChips[] = [$filters['marca'], ['marca']];
        if ($filters['cat'] !== '') $activeChips[] = [ucfirst(strtolower($filters['cat'])), ['cat']];
        if ($filters['pmin'] !== null || $filters['pmax'] !== null) {
            $activeChips[] = [($filters['pmin'] !== null ? $fmtPrice($filters['pmin']) : '…') . ' – '
                . ($filters['pmax'] !== null ? $fmtPrice($filters['pmax']) : '…'), ['pmin', 'pmax']];
        }
        if ($filters['foto']) $activeChips[] = ['Con foto', ['foto']];
        ?>
        <?php if ($fo['brands'] || $fo['categories'] || $fo['prices']): ?>
        <form class="filter-bar" id="filterForm" action="/buscar" method="GET">
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <?php if ($fo['brands']): ?>
                <select name="marca" aria-label="Marca" class="filter-field<?= $filters['marca'] !== '' ? ' on' : '' ?>">
                    <option value="">Todas las marcas</option>
                    <?php foreach ($fo['brands'] as $b): ?>
                        <option value="<?= e($b) ?>" <?= $b === $filters['marca'] ? 'selected' : '' ?>><?= e($b) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if ($fo['categories']): ?>
                <select name="cat" aria-label="Categoría" class="filter-field<?= $filters['cat'] !== '' ? ' on' : '' ?>">
                    <option value="">Todas las categorías</option>
                    <?php foreach ($fo['categories'] as $c): ?>
                        <option value="<?= e($c) ?>" <?= $c === $filters['cat'] ? 'selected' : '' ?>><?= e(ucfirst(strtolower($c))) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if ($fo['prices']): ?>
                <span class="filter-price">
                    <input type="number" name="pmin" min="0" step="1000" inputmode="numeric" placeholder="Precio desde" aria-label="Precio desde"
                           class="filter-field<?= $filters['pmin'] !== null ? ' on' : '' ?>" value="<?= $filters['pmin'] !== null ? e((string) (int) $filters['pmin']) : '' ?>">
                    <input type="number" name="pmax" min="0" step="1000" inputmode="numeric" placeholder="hasta" aria-label="Precio hasta"
                           class="filter-field<?= $filters['pmax'] !== null ? ' on' : '' ?>" value="<?= $filters['pmax'] !== null ? e((string) (int) $filters['pmax']) : '' ?>">
                </span>
            <?php endif; ?>
            <label class="filter-field filter-check<?= $filters['foto'] ? ' on' : '' ?>">
                <input type="checkbox" name="foto" value="1" <?= $filters['foto'] ? 'checked' : '' ?>> Solo con foto
            </label>
            <noscript><button type="submit" class="btn btn-sm btn-secondary">Filtrar</button></noscript>
        </form>
        <?php if ($activeChips): ?>
            <div class="filter-active">
                <?php foreach ($activeChips as [$label, $drop]): ?>
                    <a class="filter-chip" href="<?= e($filterUrl($drop)) ?>" aria-label="Quitar filtro <?= e($label) ?>"><?= e($label) ?> <span>✕</span></a>
                <?php endforeach; ?>
                <?php if (count($activeChips) > 1): ?>
                    <a class="filter-clear" href="<?= e($filterUrl(['marca', 'cat', 'pmin', 'pmax', 'foto'])) ?>">Quitar filtros</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>

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
                    <br><span style="color:var(--color-warning, #fbbf24); font-size:0.85rem;">Sin resultados para: <?= e(implode(', ', $sinResultado)) ?></span>
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
                        <a href="<?= e($filterUrl(['marca', 'cat', 'pmin', 'pmax', 'foto'])) ?>">Quitar filtros</a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <?php if (!empty($matchedFolders)): ?>
            <!-- Matched Folders -->
            <div class="section-header-landing" style="margin-bottom:1rem; border-top: 1px solid var(--color-border); padding-top:1.5rem;">
                <h2 style="font-size:1.2rem;">📂 Carpetas encontradas</h2>
            </div>
            <div class="parent-grid folder-hits" style="margin-bottom:2rem;">
                <?php foreach ($matchedFolders as $idx => $folder): 
                    $fIcon = $folder['icon_url'] ?? '';
                ?>
                    <a href="/carpeta/<?= urlencode($folder['id']) ?>" class="parent-card" style="text-decoration:none; color:inherit; display:block;">
                        <div class="card-image">
                            <?php if ($fIcon): ?>
                                <img src="<?= e($fIcon) ?>" alt="<?= e($folder['name']) ?>"
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
                            <div class="card-name"><?= e($folder['name']) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($pageRoots)): ?>

            <div class="parent-grid" id="productGrid">
                <?php $cardIndex = 0; ?>
                <?php foreach ($pageRoots as $root):
                    $group = $grouped[$root];
                    $p = $group['parent'];
                    $children = $group['children'];
                    $childCount = count($children);

                    // Portada de familia: primera portada disponible entre padre e hijos.
                    // Sirve de fallback para que ni la tarjeta ni las variantes queden en 📷
                    // cuando algún miembro de la familia sí tiene foto.
                    $familyCover = $p['cover_image_url'] ?? '';
                    if (!$familyCover) {
                        foreach ($children as $c) {
                            if (!empty($c['cover_image_url'])) { $familyCover = $c['cover_image_url']; break; }
                        }
                    }

                    $coverUrl = ($p['cover_image_url'] ?? '') ?: $familyCover;
                    $isVideo = str_starts_with($coverUrl, '[VIDEO]');
                    if ($isVideo) $coverUrl = substr($coverUrl, 7);
                ?>
                    <div class="parent-card search-selectable-card" data-category="<?= e(strtolower($p['category'] ?? '')) ?>">
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
                                        <div class="cover-placeholder">📷</div>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <div class="card-body">
                                <a href="/producto/<?= rawurlencode($p['sku']) ?>" class="dynamic-card-link" style="text-decoration:none; color:inherit; display:block;">
                                    <div class="card-sku dynamic-card-sku"><?= e(cleanSkuDisplay($p['sku'])) ?></div>
                                    <div class="card-name"><?= e($p['name']) ?></div>
                                </a>
                                <?php if ($p['category']): ?>
                                    <div class="card-meta"><span><?= e($p['category']) ?></span></div>
                                <?php endif; ?>

                                <!-- Children thumbnails -->
                                <?php if ($childCount > 0): ?>
                                    <div class="children-scroll-wrapper">
                                        <button class="children-scroll-btn scroll-left hidden" onclick="scrollChildren(this,-1)" type="button">◀</button>
                                        <div class="children-row">
                                            <?php
                                            foreach ($children as $child):
                                                // Portada PROPIA de la variante. Si no tiene, se deja vacío
                                                // (placeholder) y el cargador la resuelve con la foto real de
                                                // ESTA variante vía /api/covers/batch.
                                                // Antes se usaba $familyCover como fallback, pero eso hacía que
                                                // varias variantes sin portada propia mostraran todas la MISMA
                                                // foto del padre (fotos repetidas). Igual que en landing.php.
                                                $childCover = parseStoredCover($child['cover_image_url'] ?? '', 150)['url'];
                                            ?>
                                                <div class="child-thumb" style="cursor:pointer;"
                                                    title="<?= e(cleanSkuDisplay($child['sku'])) ?>"
                                                    data-sku="<?= e($child['sku']) ?>"
                                                    data-fallback="<?= empty($child['cover_image_url']) ? '1' : '0' ?>"
                                                    onclick="previewVariant(this, '<?= rawurlencode($child['sku']) ?>', '<?= e(cleanSkuDisplay($child['sku'])) ?>')">
                                                    <?php if ($childCover): ?>
                                                        <img src="<?= e($childCover) ?>" alt="<?= e($child['sku']) ?>" loading="lazy" onerror="this.outerHTML='<span class=\'child-placeholder\'>📷</span>'">
                                                    <?php else: ?>
                                                        <span class="child-placeholder">📷</span>
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
    <?php $mnavActive = 'buscar'; include __DIR__ . '/../../templates/partials/mobile_nav.php'; ?>
</body>

</html>