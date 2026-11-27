<?php
/**
 * GET /api/pedido/explorar (público, solo lee MySQL): explorar el catálogo para armar un pedido, igual que el visor.
 *   sin parámetros   → carpetas de primer nivel (con foto) y nada más
 *   ?carpeta=ID      → subcarpetas + ruta; los productos salen cuando la carpeta no tiene subcarpetas o con &todo=1
 *   ?q=texto         → búsqueda: el código exacto primero, luego código/nombre/categoría (con ?carpeta= se limita a esa carpeta)
 *   &p=N             → página de productos (24 por página)
 * Respuesta: {path:[{id,name}], folders:[{id,name,cover,n?}], items:[{sku,name,cover,price,stock,variants}], total, page, pages}
 */
require_once __DIR__ . '/../services/DriveIndex.php';
require_once __DIR__ . '/../services/SearchIndex.php';
$db = getDB();
$root = env('GOOGLE_DRIVE_FOLDER_ID', '');
$folderId = preg_match('/^[A-Za-z0-9_-]{10,100}$/', (string) ($_GET['carpeta'] ?? '')) ? (string) $_GET['carpeta'] : '';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80, 'UTF-8');
$page = max(1, min(200, (int) ($_GET['p'] ?? 1)));
$per = 24;
$tienda = !empty($_GET['tienda']); // /tienda: solo con stock, marcas permitidas, categorías y promocionales primero
$catMode = $tienda || !empty($_GET['cats']); // navegar por categoría (tienda y Vender comparten esto); sin ?tienda no aplica stock ni marcas permitidas
if ($catMode) $folderId = '';

$mapRow = function (array $r): array {
    $cv = parseStoredCover($r['cover_image_url'], 400);
    $name = (string) $r['name'];
    return [
        'sku' => $r['sku'], 'name' => $name === $r['sku'] ? '' : $name, 'cover' => $cv['url'], 'video' => $cv['video'],
        'price' => (float) $r['price_suggested'] > 0 ? (int) round((float) $r['price_suggested']) : 0,
        'stock' => $r['stock'] === null ? null : (int) $r['stock'],
    ];
};
// ?skus=A,B → datos de esos códigos (para dibujar el carrito)
if (isset($_GET['skus'])) {
    $codes = array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $_GET['skus'])), 'strlen'))), 0, 100);
    $items = [];
    if ($codes) {
        [$tr, $tp] = $tienda ? tiendaRulesSql() : ['', []];
        $st = $db->prepare('SELECT p.sku, p.name, p.cover_image_url, p.price_suggested, ' . stockSql('p') . ' AS stock FROM products p WHERE p.archived = 0 AND p.is_active = 1 AND p.cover_image_url > \'\' AND p.sku IN (' . implode(',', array_fill(0, count($codes), '?')) . ')' . $tr);
        $st->execute(array_merge($codes, $tp));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = $mapRow($r);
    }
    header('Cache-Control: no-store');
    jsonResponse(['items' => $items]);
}

// Filtros inteligentes (los mismos del visor): marca[], genero, pmin/pmax, stock=con|sin, oprecio=asc|desc, ocodigo=asc|desc
$flt = catalogFiltersFromRequest($_GET);
$genero = in_array($_GET['genero'] ?? '', ['hombre', 'mujer', 'unisex'], true) ? $_GET['genero'] : '';
$filtersOn = $flt['marca'] || $flt['pmin'] !== null || $flt['pmax'] !== null || $flt['stock'] !== '' || $genero !== '';
$out = ['path' => [], 'folders' => [], 'items' => [], 'total' => 0, 'page' => $page, 'pages' => 1, 'folder' => $folderId, 'q' => $q, 'filters' => $filtersOn];
if (!empty($_GET['facets'])) { // opciones de los filtros (se piden una vez al abrir la página)
    $o = searchFilterOptions($db);
    $out['facets'] = ['brands' => $o['brands'] ?? [], 'prices' => !empty($o['prices'])];
    if ($catMode) {
        $allowed = $tienda ? tiendaBrands() : [];
        [$tr, $tp] = $tienda ? tiendaRulesSql() : [" AND p.archived = 0 AND p.is_active = 1 AND p.cover_image_url > ''", []];
        // Marcas = categorías (con productos que cumplen las reglas de la tienda), limitadas a las permitidas.
        $bs = $db->prepare("SELECT p.category FROM products p WHERE TRIM(p.category) <> ''" . $tr . ' GROUP BY p.category ORDER BY COUNT(*) DESC');
        $bs->execute($tp);
        $bn = array_column($bs->fetchAll(PDO::FETCH_NUM), 0);
        if ($allowed) $bn = array_values(array_filter($bn, fn($n) => in_array($n, $allowed, true)));
        $out['facets']['brands'] = $bn;
        $promo = promoCategories($db);
        $cs = $db->prepare("SELECT p.category AS name, COUNT(*) AS n, MIN(p.cover_image_url) AS cover FROM products p WHERE TRIM(p.category) <> ''" . $tr . ' GROUP BY p.category');
        $cs->execute($tp);
        $cats = [];
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cats[] = ['name' => $r['name'], 'n' => (int) $r['n'], 'cover' => coverUrlAtSize($r['cover'], 400), 'promo' => in_array($r['name'], $promo, true)];
        }
        usort($cats, fn($a, $b) => [$b['promo'], $b['n']] <=> [$a['promo'], $a['n']]);
        $out['facets']['cats'] = $cats;
    }
}
$wantProducts = $q !== '' || $filtersOn || !empty($_GET['todos']);
if ($folderId !== '' && $folderId !== $root) {
    $c = DriveIndex::folderContents($db, $folderId, $root);
    if ($c === null) jsonResponse($out);
    $out['path'] = DriveIndex::folderPath($db, $folderId, $root);
    $subs = $c['subfolders'];
    if ($q === '' && !$filtersOn) $wantProducts = !$subs || !empty($_GET['todo']);
} else {
    $c = DriveIndex::folderContents($db, $root, $root);
    $subs = $c['subfolders'] ?? [];
    $folderId = '';
}
if ($q === '' && !$filtersOn && !$catMode) {
    foreach ($subs as $s) {
        if (str_starts_with(ltrim($s['name']), '_')) continue; // carpetas de trabajo como «_INDICE» no son catálogo
        $out['folders'][] = ['id' => $s['id'], 'name' => folderDisplayName($s['name']), 'cover' => $s['cover']];
    }
}

if ($wantProducts) {
    $f = ['marca' => $catMode ? [] : $flt['marca'], 'cat' => $catMode ? ($flt['marca'] ?: $flt['cat']) : [], 'carpeta' => $folderId !== '' ? [$folderId] : [], 'pmin' => $flt['pmin'], 'pmax' => $flt['pmax'], 'foto' => true, 'contiene' => '', 'stock' => $flt['stock']];
    [$sql, $params] = catalogFilterSql($db, $f);
    if ($genero !== '') { $sql .= ' AND p.gender = ?'; $params[] = $genero; }
    if ($tienda) { [$tr, $tp] = tiendaRulesSql(); $sql .= $tr; $params = array_merge($params, $tp); }
    $order = $flt['oprecio'] === 'desc' ? 'p.price_suggested DESC, p.sku ASC'
        : ($flt['oprecio'] === 'asc' ? '(p.price_suggested IS NULL OR p.price_suggested = 0), p.price_suggested ASC, p.sku ASC'
        : ($flt['ocodigo'] === 'desc' ? 'p.sku DESC' : 'p.sku ASC'));
    $orderArgs = [];
    if ($catMode && $flt['oprecio'] === '' && $flt['ocodigo'] === '') { // categorías promocionales primero (el código exacto de una búsqueda sigue yendo antes)
        $promo = promoCategories($db);
        if ($promo) $order = '(p.category IN (' . implode(',', array_map([$db, 'quote'], $promo)) . ')) DESC, ' . $order;
    }
    if ($q !== '') {
        $clean = preg_replace('/\.\w{2,4}$/i', '', $q);
        $forms = skuExactForms($clean) ?: [''];
        $ph = implode(',', array_fill(0, count($forms), '?'));
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 5);
        $sql .= ' AND (' . skuSearchSql('p.sku') . " IN ($ph)";
        array_push($params, ...$forms);
        $wp = [];
        foreach ($words as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $wp[] = '(p.sku LIKE ? OR p.name LIKE ? OR p.category LIKE ? OR p.brand LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ($wp ? ' OR (' . implode(' AND ', $wp) . ')' : '') . ')';
        $order = 'CASE WHEN ' . skuSearchSql('p.sku') . " IN ($ph) THEN 0 ELSE 1 END, $order";
        $orderArgs = $forms;
    }
    $base = "FROM products p WHERE p.archived = 0 AND p.is_active = 1 AND p.cover_image_url > ''" . $sql;
    $cnt = $db->prepare("SELECT COUNT(*) $base");
    $cnt->execute($params);
    $out['total'] = (int) $cnt->fetchColumn();
    $out['pages'] = max(1, (int) ceil($out['total'] / $per));
    $st = $db->prepare('SELECT p.sku, p.name, p.cover_image_url, p.price_suggested, ' . stockSql('p') . " AS stock $base ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute(array_merge($params, $orderArgs));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out['items'][] = $mapRow($r);
    }
}
header('Cache-Control: no-store');
jsonResponse($out);
