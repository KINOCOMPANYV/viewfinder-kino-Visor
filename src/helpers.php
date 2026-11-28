<?php
/**
 * Funciones helper reutilizables.
 */

/**
 * Guarda el resultado de una sincronización (tabla sync_status): éxito o error, con fecha.
 * Nunca rompe la sincronización (si la tabla aún no existe, no hace nada).
 */
function recordSyncStatus(PDO $db, string $name, bool $ok, string $message): void
{
    try {
        $sql = $ok
            ? "INSERT INTO sync_status (name, last_ok_at, last_ok_message) VALUES (?, NOW(), ?)
               ON DUPLICATE KEY UPDATE last_ok_at = NOW(), last_ok_message = VALUES(last_ok_message)"
            : "INSERT INTO sync_status (name, last_error_at, last_error) VALUES (?, NOW(), ?)
               ON DUPLICATE KEY UPDATE last_error_at = NOW(), last_error = VALUES(last_error)";
        $db->prepare($sql)->execute([$name, mb_substr($message, 0, 2000)]);
    } catch (\Throwable $e) { /* migración 025 pendiente */ }
}

/**
 * Candado único de sincronización (hoja, Drive y webhook): evita que dos procesos recalculen productos,
 * familias y portadas a la vez. GET_LOCK de MySQL: se suelta solo cuando termina la petición o el proceso.
 * $waitSeconds = 0 → no espera (el botón avisa "ya está corriendo").
 */
function acquireSyncLock(PDO $db, int $waitSeconds = 0): bool
{
    try {
        $st = $db->prepare("SELECT GET_LOCK('vf_sync', ?)");
        $st->execute([$waitSeconds]);
        return (bool) $st->fetchColumn();
    } catch (\Throwable $e) {
        return true; // sin candado antes que sin sincronización
    }
}

const SYNC_BUSY_MESSAGE = 'Ya hay una sincronización corriendo (hoja o Drive: automática, cron o desde otro equipo). Espera uno o dos minutos y vuelve a intentar.';

/**
 * Devuelve JSON y termina la ejecución.
 */
function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Devuelve JSON con cache headers y termina la ejecución.
 * @param int $ttl Segundos de cache (max-age). 0 = sin cache.
 */
function jsonCachedResponse(array $data, int $ttl = 60, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    if ($ttl > 0) {
        header("Cache-Control: public, max-age={$ttl}");
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Cache-busting: devuelve ruta de asset con ?v=filemtime.
 * Ejemplo: assetVersion('/assets/css/style.css') → '/assets/css/style.css?v=1708732800'
 */
function assetVersion(string $path): string
{
    $fullPath = __DIR__ . '/../' . ltrim($path, '/');
    $mtime = @filemtime($fullPath);
    return $path . ($mtime ? '?v=' . $mtime : '');
}

/**
 * Redirige a una URL.
 */
function redirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

/**
 * Escapa HTML para prevenir XSS.
 */
function e(?string $text): string
{
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Migas de pan: "Inicio › Sección › Página". $items = [[texto, href|null], ...]; el último es la página actual.
 * Inicio va siempre primero. Estilos: .crumbs (style.css).
 */
function breadcrumbs(array $items, string $id = ''): string
{
    $parts = ['<a href="/">Inicio</a>'];
    $last = count($items) - 1;
    foreach (array_values($items) as $i => [$text, $href]) {
        $parts[] = ($i === $last || !$href)
            ? '<span' . ($i === $last ? ' aria-current="page"' : '') . '>' . e($text) . '</span>'
            : '<a href="' . e($href) . '">' . e($text) . '</a>';
    }
    return '<nav class="crumbs"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . ' aria-label="Estás aquí">'
        . implode('<span class="crumbs-sep" aria-hidden="true">›</span>', $parts) . '</nav>';
}

/**
 * Obtiene la IP real del cliente (detrás de proxy).
 */
function getClientIP(): string
{
    // X-Forwarded-For puede traer una cadena "ip-cliente-falsa, ip-real".
    // El proxy de confianza (Railway/Cloudflare) SIEMPRE agrega la IP real al final,
    // así que usamos la última entrada — las anteriores son falsificables por el cliente.
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $parts = array_map('trim', explode(',', $xff));
        $last = end($parts);
        if ($last !== '' && filter_var($last, FILTER_VALIDATE_IP)) {
            return $last;
        }
    }
    return $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}

/**
 * Verifica si el admin está autenticado.
 */
function isAdminLoggedIn(): bool
{
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Requiere autenticación de admin.
 * - Si la petición es AJAX/fetch → responde JSON 401.
 * - Si es petición normal    → redirige al login.
 */
function requireAdmin(): void
{
    if (!isAdminLoggedIn()) {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
            || (($_SERVER['HTTP_ACCEPT'] ?? '') !== ''
                && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

        if ($isAjax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Sesión expirada. Por favor, recarga la página e inicia sesión de nuevo.']);
            exit;
        }

        // Recordar a dónde iba (p. ej. la vuelta de Google con ?code=...) para
        // continuar ahí después del login en vez de perder la petición.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/admin';
        }
        redirect('/admin/login');
    }
}

/**
 * Pedidos bodega (/pedidos-alistar, su API y /pedido-bodega?id=N): solo el admin logueado o un equipo
 * que ya escribió la clave de bodega (env PEDIDOS_CLAVE). La clave se recuerda 180 días en una cookie
 * firmada; cambiar PEDIDOS_CLAVE en Railway cierra todos los equipos. Sin PEDIDOS_CLAVE: solo el admin.
 */
function bodegaCookieValue(): string
{
    $key = (string) env('PEDIDOS_CLAVE', '');
    return $key === '' ? '' : hash_hmac('sha256', 'kino-bodega-v1', $key);
}

function isBodegaAllowed(): bool
{
    if (isAdminLoggedIn()) {
        return true;
    }
    $want = bodegaCookieValue();
    return $want !== '' && hash_equals($want, (string) ($_COOKIE['kino_bodega'] ?? ''));
}

function requireBodega(): void
{
    if (isBodegaAllowed()) {
        return;
    }
    $isAjax = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Falta la clave de bodega: abre 📋 Pedidos bodega en este equipo y escríbela.']);
        exit;
    }
    http_response_code(401);
    $bodegaNext = $_SERVER['REQUEST_URI'] ?? '/pedidos-alistar';
    include __DIR__ . '/../templates/client/bodega_key.php';
    exit;
}

/**
 * Genera un token CSRF y lo guarda en sesión.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica el token CSRF del formulario.
 */
function verifyCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/** Ajuste del admin (tabla app_settings, migración 038). Sin tabla o sin valor → $default. */
function appSetting(string $name, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach (getDB()->query("SELECT name, value FROM app_settings")->fetchAll() as $r) $cache[$r['name']] = $r['value']; }
        catch (\Throwable $e) { /* migración 038 pendiente */ }
    }
    return $cache[$name] ?? $default;
}

function setAppSetting(PDO $db, string $name, string $value): void
{
    $db->prepare("INSERT INTO app_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$name, $value]);
}

/** Precio público = precio SYS × este número (decisión del dueño 2026-11-24; por defecto 2, configurable en Admin → Precios). */
function priceMultiplier(): float
{
    $m = (float) str_replace(',', '.', appSetting('price_multiplier', '2'));
    return $m > 0 && $m <= 20 ? $m : 2.0;
}

/** ¿Se muestra el precio mayorista (SYS) al público? Apagado por defecto; lo enciende el admin. */
function showWholesalePublic(): bool
{
    return appSetting('show_wholesale', '0') === '1';
}

/**
 * Precio que ve el público = precio mayorista (Precio SYS; si falta, la columna PRICE de la Hoja master) × multiplicador.
 * No toca los precios editados a mano en el admin (price_manual = 1). Sin precio mayorista → 0 («Sin precio en tabla»).
 * @return int productos cuyo precio cambió
 */
function recomputePublicPrices(PDO $db, ?float $mult = null): int
{
    $mult = $mult ?? priceMultiplier();
    $w = "COALESCE(NULLIF(sys_price, 0), NULLIF(price_wholesale, 0))";
    $st = $db->prepare(
        "UPDATE products SET price_suggested = COALESCE(ROUND({$w} * ?), 0), price_sheet = COALESCE(ROUND({$w} * ?), 0)
         WHERE price_manual = 0 AND (NOT (price_suggested <=> COALESCE(ROUND({$w} * ?), 0)) OR NOT (price_sheet <=> COALESCE(ROUND({$w} * ?), 0)))"
    );
    $st->execute([$mult, $mult, $mult, $mult]);
    return $st->rowCount();
}

/**
 * Formatea un precio.
 */
/** Precio público: "$12.345" o "Sin precio en tabla" (sin PRICE_SUGGESTED en la Hoja master, ni de su familia, ni precio del admin). */
function priceText($price): string
{
    $p = (float) ($price ?? 0);
    return $p > 0 ? '$' . number_format($p, 0, ',', '.') : 'Sin precio en tabla';
}

/**
 * SQL de las existencias que ve el público: Stock SYS (última subida en 📦 Inventario) y, si no hay,
 * la columna STOCK de la Hoja master. NULL = sin dato (producto Solo Drive sin SYS) → no se muestra nada.
 */
function stockSql(string $alias = ''): string
{
    $a = $alias !== '' ? $alias . '.' : '';
    return "COALESCE({$a}sys_stock, CASE WHEN {$a}in_sheet = 1 THEN {$a}stock END)";
}

/** «In Stock» en verde (más de 0), «Out stock» en rojo (0 o negativo); sin dato = nada. Sin cantidad por ahora (decisión del dueño 2026-11-20). */
function stockTag($stock): string
{
    if ($stock === null || $stock === '' || $stock === false) return '';
    return (int) $stock > 0 ? '<span class="stock-tag in">In Stock</span>' : '<span class="stock-tag out">Out stock</span>';
}

/** Línea de precio para tarjetas y ficha; con $stock (ver stockSql) pone al lado «In Stock» / «Out stock». */
function priceTag($price, string $class = 'card-price', $stock = null): string
{
    $none = !((float) ($price ?? 0) > 0);
    $st = stockTag($stock);
    return '<div class="' . $class . ($none ? ' no-price' : '') . ($st ? ' has-stock' : '') . '"><span class="price-val">' . e(priceText($price)) . '</span>' . $st . '</div>';
}

/**
 * Regla de coincidencia foto ↔ SKU usada al buscar media de un producto:
 * el nombre (sin extensión) empieza con el SKU y lo que sigue es un separador
 * (guion, punto, espacio, guion bajo) o nada. Evita que "1948" coincida con
 * "1948O", "JR1948O" o "1948DG".
 */
/**
 * SKU sin los signos que no se usan en los nombres de archivo: "M:8392-1" → "m8392-1",
 * "NO.2305B-1" → "no2305b-1", "ETN56L -1" → "etn56l-1". Solo para comparar con nombres.
 */
function skuFileKey(string $sku): string
{
    return preg_replace('/[^a-z0-9-]/', '', strtolower($sku));
}

/**
 * Búsqueda de códigos sin importar "-", espacios, ":" ni ".": "M8363-3", "M:8363-3" y
 * "m 8363 3" encuentran lo mismo. skuSearchSql() normaliza la columna en SQL y
 * skuSearchNorm() el texto buscado, con la misma regla.
 */
function skuSearchSql(string $column): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE($column, '-', ''), ' ', ''), ':', ''), '.', '')";
}

function skuSearchNorm(string $text): string
{
    return str_replace(['-', ' ', ':', '.'], '', $text);
}

/**
 * Formas normalizadas (skuSearchNorm) con las que un código buscado cuenta como EXACTO.
 * "M", "M:" y "M-" son el mismo código: "8363-3" también es exacto para "M:8363-3" y al revés.
 * @return string[]
 */
function skuExactForms(string $q): array
{
    $n = skuSearchNorm(preg_replace('/\.\w{2,4}$/i', '', trim($q)));
    if ($n === '') return [];
    $forms = [$n];
    if (preg_match('/^m(\d.*)$/i', $n, $m)) {
        $forms[] = $m[1];
    } elseif (preg_match('/^\d/', $n)) {
        $forms[] = 'M' . $n;
    }
    return $forms;
}

/**
 * Filtros del catálogo público desde la URL (/buscar y la barra de filtros de todas las pantallas).
 * marca, cat y carpeta admiten varios valores (marca[]=A&marca[]=B) o uno solo (marca=A).
 * @return array{marca: string[], cat: string[], carpeta: string[], pmin: ?float, pmax: ?float, foto: bool}
 */
function catalogFiltersFromRequest(array $get): array
{
    $list = function ($v): array {
        $v = is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v]);
        $v = array_map(fn($x) => trim((string) $x), array_filter($v, 'is_scalar'));
        return array_values(array_slice(array_unique(array_filter($v, fn($x) => $x !== '')), 0, 30));
    };
    $price = function ($v): ?float {
        if (!is_scalar($v)) return null;
        $d = preg_replace('/\D/', '', (string) $v); // «120.000», «$120000» → 120000
        return $d === '' ? null : (float) $d;
    };
    return [
        'marca' => $list($get['marca'] ?? null),
        'cat' => $list($get['cat'] ?? null),
        'carpeta' => $list($get['carpeta'] ?? null),
        'pmin' => $price($get['pmin'] ?? null),
        'pmax' => $price($get['pmax'] ?? null),
        'foto' => ($get['foto'] ?? '') === '1',
        // Existencias (Stock SYS o STOCK de la Hoja master): con = más de 0, sin = 0 o menos; '' = todos.
        // Los que no tienen dato de stock (Solo Drive sin SYS) solo salen en «todos».
        'stock' => in_array($get['stock'] ?? '', ['con', 'sin'], true) ? $get['stock'] : '',
        // «Contiene la palabra»: en el nombre del producto o en el nombre de su archivo en Drive
        'contiene' => is_string($get['contiene'] ?? null) ? mb_substr(trim($get['contiene']), 0, 60) : '',
        // Orden: codigo (A→Z alfanumérico), precio_desc (mayor a menor), precio_asc (menor a mayor); '' = el de siempre
        // Dos órdenes que se combinan: precio (desc|asc) primero y código alfanumérico (asc|desc) después
        'oprecio' => in_array($get['oprecio'] ?? '', ['desc', 'asc'], true) ? $get['oprecio']
            : (in_array($get['orden'] ?? '', ['precio_desc', 'precio_asc'], true) ? substr($get['orden'], 7) : ''), // ?orden= viejo
        'ocodigo' => in_array($get['ocodigo'] ?? '', ['asc', 'desc'], true) ? $get['ocodigo'] : (($get['orden'] ?? '') === 'codigo' ? 'asc' : ''),
    ];
}

/**
 * Ordena filas de productos (claves sku, price_suggested). Se combinan: primero por precio ($precio desc|asc; los sin
 * precio al final) y, a igual precio, por código ($codigo asc|desc, alfanumérico «natural»: M:8-1 antes que M:10-1).
 * Solo código: ordena por código. Ninguno: deja el orden de la consulta.
 */
function catalogSortRows(array &$rows, string $precio, string $codigo = ''): void
{
    if ($precio === '' && $codigo === '') return;
    $code = fn($r) => skuSearchNorm(preg_replace('/^M[:\-]?/i', '', (string) $r['sku']));
    $cdir = $codigo === 'desc' ? -1 : 1;
    $pdir = $precio === 'desc' ? -1 : 1;
    usort($rows, function ($a, $b) use ($precio, $codigo, $code, $cdir, $pdir) {
        if ($precio !== '') {
            $pa = (float) ($a['price_suggested'] ?? 0); $pb = (float) ($b['price_suggested'] ?? 0);
            if (($pa > 0) !== ($pb > 0)) return $pa > 0 ? -1 : 1;
            if ($pa != $pb) return ($pa <=> $pb) * $pdir;
        }
        return strnatcasecmp($code($a), $code($b)) * $cdir;
    });
}

/**
 * SQL de búsqueda por palabras (alias p = products): cada palabra debe aparecer, en cualquier orden, en el código
 * ("M8363-3" = "M:8363-3" = "8363-3", vía skuExactForms), nombre, categoría, marca, color o en el nombre de la
 * carpeta de Drive donde está su foto. «poedagar dama dorado» = las tres palabras.
 * @return array{0: string[], 1: array} partes (unir con AND) y parámetros
 */
function catalogWordsSql(array $words): array
{
    $parts = [];
    $params = [];
    foreach (array_slice(array_values(array_filter($words, fn($w) => $w !== '')), 0, 8) as $word) {
        $wordLike = '%' . addcslashes($word, '%_') . '%';
        $skuOr = [];
        foreach (skuExactForms($word) as $form) {
            $skuOr[] = skuSearchSql('p.sku') . ' LIKE ?';
            $params[] = '%' . addcslashes($form, '%_') . '%';
        }
        if (SearchIndex::ready(getDB())) {
            // Índice ya armado (SearchIndex): nombre, marca, categoría, color y carpetas en una sola columna
            $parts[] = '(' . implode(' OR ', $skuOr ?: ['0']) . ' OR p.search_text LIKE ?)';
            $params[] = $wordLike;
        } else {
            // Sin índice todavía (recién desplegado): solo columnas de products, nunca drive_index por fila
            $parts[] = '(' . implode(' OR ', $skuOr ?: ['0']) . ' OR p.name LIKE ? OR p.category LIKE ? OR p.brand LIKE ? OR p.color LIKE ?)';
            array_push($params, $wordLike, $wordLike, $wordLike, $wordLike);
        }
    }
    return [$parts, $params];
}

/**
 * Fotos para un catálogo PDF filtrado (/catalogo-pdf/lista): la portada de cada producto visible (variantes incluidas)
 * que cumple el texto y los filtros, ordenadas por marca y código. Sin videos (no van en el PDF).
 * @return array{items: array<int, array{id: string, sku: string}>, total: int}
 */
function catalogPdfItems(PDO $db, string $q, array $filters, int $limit = 300): array
{
    [$parts, $params] = catalogWordsSql(preg_split('/\s+/u', trim(preg_replace('/\.\w{2,4}$/i', '', $q))) ?: []);
    [$fSql, $fParams] = catalogFilterSql($db, $filters);
    $where = "p.archived = 0 AND p.cover_image_url > '' AND p.cover_image_url NOT LIKE '[VIDEO]%'"
        . ($parts ? ' AND ' . implode(' AND ', $parts) : '') . $fSql;
    $stmt = $db->prepare("SELECT p.sku, p.cover_image_url, p.price_suggested FROM products p WHERE $where ORDER BY p.brand, p.sku");
    $stmt->execute(array_merge($params, $fParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Nombre exacto de archivo en el texto ("S-640+S-647-3.jpg"): va al PDF **esa** foto, no la
    // portada del producto (es para lo que se pega el nombre). Sin videos: no van en el PDF.
    if (!$rows && $q !== '') {
        $photos = array_filter(photosByFileName($db, $q, max(1, $limit)), fn($ph) => !$ph['video']);
        if ($photos) {
            $stmt = $db->prepare(
                "SELECT p.sku, p.price_suggested FROM products p
                 WHERE p.archived = 0 AND p.cover_image_url > ''
                   AND p.sku IN (" . implode(',', array_fill(0, count($photos), '?')) . ")" . $fSql . "
                 ORDER BY p.brand, p.sku"
            );
            $stmt->execute(array_merge(array_keys($photos), $fParams));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['cover_image_url'] = coverStringForFile($photos[$r['sku']]['id'], false);
                $rows[] = $r;
            }
        }
    }
    catalogSortRows($rows, $filters['oprecio'] ?? '', $filters['ocodigo'] ?? '');
    $items = [];
    $total = 0;
    foreach ($rows as $r) {
        $id = driveIdFromUrl($r['cover_image_url']);
        if (!$id) continue;
        $total++;
        if (count($items) < $limit) $items[] = ['id' => $id, 'sku' => $r['sku']];
    }
    return ['items' => $items, 'total' => $total];
}

/** ¿Hay algún filtro puesto? */
function catalogFiltersActive(array $f): bool
{
    return $f['marca'] || $f['cat'] || $f['carpeta'] || $f['pmin'] !== null || $f['pmax'] !== null || ($f['contiene'] ?? '') !== '' || ($f['stock'] ?? '') !== '';
}

/**
 * SQL (" AND …", alias p = products) de los filtros del catálogo. Todos se combinan entre sí;
 * dentro de marca/categoría/carpeta, varios valores = cualquiera de ellos.
 * Carpeta: productos con una foto visible en esa carpeta o en cualquiera de sus subcarpetas.
 * @return array{0: string, 1: array}
 */
function catalogFilterSql(PDO $db, array $f): array
{
    $sql = '';
    $params = [];
    $in = fn(array $v) => implode(',', array_fill(0, count($v), '?'));
    if ($f['marca']) { $sql .= ' AND p.brand IN (' . $in($f['marca']) . ')'; array_push($params, ...$f['marca']); }
    if ($f['cat']) { $sql .= ' AND p.category IN (' . $in($f['cat']) . ')'; array_push($params, ...$f['cat']); }
    if ($f['pmin'] !== null) { $sql .= ' AND p.price_suggested >= ?'; $params[] = $f['pmin']; }
    if ($f['pmax'] !== null) { $sql .= ' AND p.price_suggested > 0 AND p.price_suggested <= ?'; $params[] = $f['pmax']; }
    if ($f['foto']) { $sql .= " AND p.cover_image_url IS NOT NULL AND p.cover_image_url <> ''"; }
    if (($f['stock'] ?? '') !== '') $sql .= ' AND ' . stockSql('p') . ($f['stock'] === 'con' ? ' > 0' : ' <= 0');
    foreach (array_slice(preg_split('/\s+/u', $f['contiene'] ?? '', -1, PREG_SPLIT_NO_EMPTY), 0, 5) as $w) {
        $like = '%' . addcslashes($w, '%_\\') . '%';
        $sql .= SearchIndex::ready($db) ? ' AND p.contains_text LIKE ?' : ' AND p.name LIKE ?';
        $params[] = $like;
    }
    if ($f['carpeta']) {
        if (SearchIndex::ready($db)) {
            // folder_ids ya trae cada carpeta y todas sus superiores: elegir una carpeta incluye sus subcarpetas
            $sql .= ' AND (' . implode(' OR ', array_fill(0, count($f['carpeta']), 'p.folder_ids LIKE ?')) . ')';
            foreach ($f['carpeta'] as $id) $params[] = '% ' . addcslashes($id, '%_\\') . ' %';
        } else {
            // Sin índice: solo fotos directamente en la carpeta (usa el índice de matched_sku)
            $sql .= ' AND EXISTS (SELECT 1 FROM drive_index fc WHERE fc.matched_sku = p.sku AND fc.is_folder = 0 AND fc.is_hidden = 0'
                . ' AND fc.parent_id IN (' . $in($f['carpeta']) . '))';
            array_push($params, ...$f['carpeta']);
        }
    }
    return [$sql, $params];
}

/**
 * Carpetas visibles de Drive (id → [name, parent_id]), de la copia local. Una sola lectura por petición.
 * @return array<string, array{0: string, 1: string}>
 */
function visibleDriveFolders(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach ($db->query("SELECT file_id, name, parent_id FROM drive_index WHERE is_folder = 1 AND is_hidden = 0")->fetchAll(PDO::FETCH_NUM) as [$id, $name, $parent]) {
            $cache[$id] = [(string) $name, (string) $parent];
        }
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/** Nombre de carpeta con su ruta («CURREN › DAMA»), sin la raíz ni los números de orden. */
function folderFilterLabel(PDO $db, string $folderId): string
{
    $folders = visibleDriveFolders($db);
    $root = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $parts = [];
    for ($id = $folderId, $i = 0; isset($folders[$id]) && $id !== $root && $i < 8; $i++) {
        array_unshift($parts, folderDisplayName($folders[$id][0]));
        $id = $folders[$id][1];
    }
    return implode(' › ', $parts);
}

/**
 * Carpetas para el filtro: las que tienen fotos de productos visibles (directas) y las de primer nivel,
 * ordenadas por su ruta. @return array<int, array{id: string, label: string}>
 */
function catalogFolderOptions(PDO $db): array
{
    return SearchIndex::folderOptions($db); // se arman con el índice (tras cada sincronización)
}

/**
 * Opciones de los filtros de la búsqueda: solo valores con datos suficientes (5+ productos visibles).
 * @return array{brands: string[], categories: string[], prices: bool}
 */
function searchFilterOptions(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    // Marcas, categorías y "hay precios" cambian solo con el sync o el admin: se guardan 2 min en disco para no
    // recorrer todos los productos en cada página que muestra la barra de filtros. Las carpetas ya vienen del índice.
    $file = BASE_DIR . '/storage/cache/filter_options.json';
    $saved = @filemtime($file) > time() - 120 ? json_decode((string) @file_get_contents($file), true) : null;
    if (is_array($saved) && isset($saved['brands'], $saved['categories'], $saved['prices'])) {
        return $cache = $saved + ['folders' => catalogFolderOptions($db)];
    }
    $pick = fn(string $col) => $db->query(
        "SELECT $col FROM products WHERE archived = 0 AND cover_image_url > '' AND $col IS NOT NULL AND TRIM($col) <> ''
         GROUP BY $col HAVING COUNT(*) >= 5 ORDER BY $col"
    )->fetchAll(PDO::FETCH_COLUMN);
    $categories = $pick('category');
    $opts = [
        'brands' => $pick('brand'),
        'categories' => count($categories) >= 2 ? $categories : [],
        'prices' => (int) $db->query("SELECT COUNT(*) FROM products WHERE archived = 0 AND cover_image_url > '' AND price_suggested > 0")->fetchColumn() >= 20,
    ];
    @mkdir(dirname($file), 0775, true);
    @file_put_contents($file . '.tmp', json_encode($opts, JSON_UNESCAPED_UNICODE)) && @rename($file . '.tmp', $file);
    return $cache = $opts + ['folders' => catalogFolderOptions($db)];
}

/**
 * "¿Quisiste decir…?" cuando una búsqueda no encuentra nada: códigos parecidos (letras de más, de menos,
 * cambiadas o invertidas, comparando sin "-", espacios, ":" ni "."), uno por familia, y marcas parecidas.
 * Todo en PHP sobre los códigos visibles (~6000, unos ms). Sin servicios externos.
 * @return array{codes: array<int, array{sku: string, name: string, cover: string}>, brands: string[]}
 */
function searchSuggestions(PDO $db, string $q, int $limit = 6): array
{
    $out = ['codes' => [], 'brands' => []];
    $n = strtolower(skuSearchNorm(preg_replace('/\.\w{2,4}$/i', '', trim($q))));
    $len = strlen($n);
    if ($len < 3 || $len > 40) return $out;
    $max = $len <= 4 ? 1 : 2;

    $best = []; // familia → [distancia, fila]
    $rows = $db->query("SELECT sku, name, parent_sku, cover_image_url FROM products WHERE archived = 0 AND cover_image_url > ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $c = strtolower(skuSearchNorm($r['sku']));
        if ($c === '' || abs(strlen($c) - $len) > 6) continue;
        // Código completo, o el inicio del código (escribió "M8336" buscando "M8363-1")
        // (a igual parecido gana el código completo: el inicio suma medio punto solo para ordenar)
        $d = abs(strlen($c) - $len) <= $max ? levenshtein($n, $c) : 99;
        if (strlen($c) > $len) {
            $dp = levenshtein($n, substr($c, 0, $len));
            if ($dp <= $max) $d = min($d, $dp + 0.5);
        }
        if ($d > $max + 0.5) continue;
        $fam = familyKey($r);
        // Por familia gana el más parecido; a igual distancia, el padre (código = familia) o el más corto
        $rank = [$d, $r['sku'] === $fam ? 0 : 1, strlen($c)];
        if (!isset($best[$fam]) || $rank < $best[$fam][0]) $best[$fam] = [$rank, $r];
    }
    uasort($best, fn($a, $b) => $a[0] <=> $b[0]);
    foreach (array_slice($best, 0, $limit) as [$rank, $r]) {
        $out['codes'][] = ['sku' => $r['sku'], 'name' => (string) $r['name'], 'cover' => (string) $r['cover_image_url']];
    }

    // Marcas: "poedagr" → POEDAGAR (se ofrece como filtro)
    $qWord = strtolower(trim($q));
    foreach (searchFilterOptions($db)['brands'] as $brand) {
        $b = strtolower($brand);
        if (strlen($qWord) >= 4 && levenshtein($qWord, $b) <= (strlen($b) <= 5 ? 1 : 2)) $out['brands'][] = $brand;
    }
    return $out;
}

/**
 * Formas en que se escribe el mismo código en un nombre de archivo (minúsculas).
 * El separador entre el prefijo de letras y el número es libre: "M:8464-2", "M8464-2",
 * "M-8464-2", "M 8464-2", "M.8464-2" y "M_8464-2" son el mismo código.
 * @return string[] la primera es el SKU tal cual
 */
function skuNameVariants(string $sku): array
{
    $lower = strtolower(trim($sku));
    $variants = [$lower, skuFileKey($lower)];
    if (preg_match('/^([a-z]+)[:\-. _]?(\d.*)$/', $lower, $m)) {
        foreach (['', '-', ':', ' ', '.', '_'] as $sep) {
            $variants[] = $m[1] . $sep . $m[2];
        }
    }
    return array_values(array_unique(array_filter($variants, 'strlen')));
}

/**
 * ¿El código termina en la posición $len del nombre (en minúsculas)? Sí si ahí acaba el nombre o sigue
 * un separador ("X6071-A", "X6071 (2)"). Un "+" seguido de otro código NO separa: "S-640+S-647-1" es un
 * código combinado propio, no una foto de "S-640" (decisión del dueño 2026-11-23). "X6071+caja" sí separa.
 */
function isSkuBoundary(string $name, int $len): bool
{
    if ($len >= strlen($name)) return true;
    if (ctype_alnum($name[$len])) return false;
    return !($name[$len] === '+' && preg_match('/^\+[a-z]{0,4}[:\-. _]?\d/', substr($name, $len)));
}

function fileNameStartsWithSku(string $filename, string $sku): bool
{
    $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    $skuLower = strtolower($sku);
    if (!str_starts_with($name, $skuLower)) return false;
    return isSkuBoundary($name, strlen($skuLower));
}

/**
 * BÚSQUEDA POR NOMBRE EXACTO DE ARCHIVO. Para pegar en cualquier buscador o lista el nombre de un
 * archivo de Drive ("S-640+S-647-3.jpg"): devuelve el código al que está enlazado **y esa foto**,
 * así se puede mostrar, mandar o imprimir la foto exacta y no la portada del producto.
 * (Desde 2026-11-23 "S-640+S-647-3" es un código combinado propio, ver isSkuBoundary(); esto sigue
 * sirviendo para nombres con sufijos o extensión pegados.)
 * Misma regla que las fotos de un producto (fileNameStartsWithSku): el nombre sin extensión es
 * igual a lo buscado o sigue con un separador ("S-640+S-647" encuentra "S-640+S-647-3.jpg").
 * Una foto por código (la primera por nombre). No mira si el producto es público: eso lo decide
 * quien llama (el catálogo público pide `archived = 0 AND cover_image_url > ''`).
 * $includeHidden = también las fotos de carpetas ocultas (solo para el admin «¿por qué no sale?»).
 * @return array<string, array{id: string, name: string, video: bool}> código => foto exacta
 */
function photosByFileName(PDO $db, string $q, int $limit = 20, bool $includeHidden = false): array
{
    $stem = trim(cleanSkuDisplay(trim($q)));
    if (strlen($stem) < 3 || !preg_match('/\d/', $stem)) return []; // muy corto o sin números: no es un nombre de foto
    try {
        $stmt = $db->prepare(
            "SELECT file_id, name, mime_type, matched_sku FROM drive_index
             WHERE is_folder = 0 " . ($includeHidden ? '' : 'AND is_hidden = 0 ') . "AND matched_sku IS NOT NULL AND name_stem LIKE ?
             ORDER BY name_stem = ? DESC, name ASC LIMIT 200"
        );
        // Primero el archivo que se llama EXACTAMENTE así: "1971-1.jpg" antes que "1971-1-2.jpg"
        // (por nombre, "-" va antes que ".", y saldría la foto 2 en vez de la pedida).
        $stmt->execute([addcslashes($stem, '%_\\') . '%', $stem]);
    } catch (\PDOException $e) {
        return []; // drive_index / matched_sku aún sin migrar
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!fileNameStartsWithSku((string) $r['name'], $stem)) continue;
        $sku = (string) $r['matched_sku'];
        if (isset($out[$sku])) continue;
        $out[$sku] = [
            'id' => (string) $r['file_id'],
            'name' => (string) $r['name'],
            'video' => str_starts_with((string) $r['mime_type'], 'video/'),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * ¿Lo escrito parece el nombre de un archivo y no un código? Lleva un signo que no va en un código
 * (el "+" de "S-640+S-647-3") o termina en una extensión de foto/video. Sirve para no ir a la BD por
 * cada palabra de una lista larga: solo estas se buscan con photosByFileName().
 */
function looksLikeFileName(string $text): bool
{
    $t = trim($text);
    if ($t === '' || !preg_match('/\d/', $t)) return false; // sin números no es el nombre de una foto
    return (bool) preg_match('/[^\w:.\- ]/u', $t)
        || (bool) preg_match('/\.(jpe?g|png|webp|gif|bmp|heic|mp4|mov|avi|mkv)$/i', $t);
}

/** Portada en el formato que guarda `products.cover_image_url`: `lh3Url()` y "[VIDEO]" si es video. */
function coverStringForFile(string $fileId, bool $isVideo): string
{
    return $isVideo ? '[VIDEO]https://lh3.googleusercontent.com/d/' . $fileId : lh3Url($fileId);
}

/**
 * Extrae el SKU raíz (padre) de un código de variante.
 *
 * REGLA: Solo se considera "variante" un sufijo que sea EXCLUSIVAMENTE letras
 * (colores, tallas, estilos: ROJO, A, B, GOLD). Los sufijos numéricos o
 * alfanuméricos como -5, -10, V1, F2 representan referencias DISTINTAS.
 *
 * Ejemplos:
 *   "839-5"      → "839-5"    (sufijo numérico = referencia propia, NO toca)
 *   "839-5V1"    → "839-5"    (quita sufijo de letra V1 pegado: letra+dígito)
 *   "839-5-ROJO" → "839-5"    (quita sufijo solo letras)
 *   "839-5-A"    → "839-5"    (quita sufijo 1 letra)
 *   "839-5-B"    → "839-5"    (quita sufijo 1 letra)
 *   "839-10"     → "839-10"   (sufijo numérico = referencia distinta, NO toca)
 *   "1234-12"    → "1234-12"  (ya es raíz)
 *   "1234-12v3"  → "1234-12"  (quita v3 pegado)
 *   "ABC-1F1"    → "ABC-1"    (quita F1 pegado)
 *   "M-8464"     → "M-8464"   (sufijo numérico largo = referencia propia)
 */
function extractRootSku(string $code): string
{
    $code = trim($code);
    // Eliminar sufijos que contengan al menos una letra (ej: -ROJO, -A, -V1).
    // NO eliminar sufijos puramente numéricos (ej: -5, -10) porque suelen ser modelos distintos.
    if (preg_match('/^(.+)[-_]([a-zA-Z0-9]*[a-zA-Z][a-zA-Z0-9]*)$/', $code, $matches)) {
        return $matches[1];
    }
    return $code;
}

/**
 * Extrae un SKU raíz CANDIDATO quitando el último segmento tras un guion/guion bajo,
 * sin importar si es letra o número (ej: "992-1" → "992", "839-10" → "839").
 *
 * A diferencia de extractRootSku(), esto SÍ considera sufijos numéricos como
 * posibles variantes. Se usa junto con groupSiblingsByNameAndRoot() para decidir
 * si de verdad son variantes (mismo nombre de producto) o referencias distintas
 * (nombre distinto), ya que el patrón del SKU solo no alcanza para diferenciarlos.
 */
function extractCandidateRootSku(string $code): string
{
    $code = trim($code);
    if (preg_match('/^(.+)[-_]([a-zA-Z0-9]+)$/', $code, $matches)) {
        return $matches[1];
    }
    return $code;
}

/**
 * Si una familia numérica no tiene código "-1", el padre es su número más bajo
 * (CHA-105-3 es el padre de CHA-105-4…). false = esas variantes quedan sueltas.
 */
const FAMILY_PARENT_FALLBACK_LOWEST = true;

/**
 * Recalcula parent_sku para un conjunto de filas (sku => ['sku'=>, 'name'=>, ...]).
 *
 * Regla (decidida por el dueño):
 * - Variantes numéricas "BASE-N": el padre es "BASE-1" (o el número más bajo si no hay -1,
 *   ver FAMILY_PARENT_FALLBACK_LOWEST), aunque los nombres cambien por el color.
 *   "M:8363-2", "M:8363-3"… → "M:8363-1". El padre lleva su propio código como parent_sku.
 *   Si "BASE" también es un producto, entra a la familia. No se agrupan bases que son
 *   solo un número de 1-2 cifras ("3-68", "7-152", "8-10"): son productos distintos.
 * - Sufijos con letras ("PZH002-B", "X6071-A"): raíz sin sufijo + mismo nombre exacto (regla anterior).
 * Una familia necesita 2+ códigos.
 *
 * @param array<string,array{sku:string,name:string}> $rowsBySku
 * @return array<string,?string> mapa sku => parent_sku (o null si no tiene familia)
 */
function computeParentSkuMap(array $rowsBySku): array
{
    $parentMap = array_fill_keys(array_map('strval', array_keys($rowsBySku)), null);

    // 1) Familias numéricas: BASE => [N => sku]
    $numeric = [];
    foreach ($parentMap as $sku => $_) {
        if (preg_match('/^(.+)[-_](\d+)$/', $sku, $m) && !preg_match('/^\d{1,2}$/', $m[1])) {
            $numeric[$m[1]][(int) $m[2]] = $sku;
        }
    }
    foreach ($numeric as $base => $members) {
        $baseIsProduct = array_key_exists($base, $parentMap);
        if (count($members) + ($baseIsProduct ? 1 : 0) < 2) continue;
        if (isset($members[1])) {
            $parent = $members[1];
        } elseif (FAMILY_PARENT_FALLBACK_LOWEST) {
            $parent = $members[min(array_keys($members))];
        } else {
            continue;
        }
        foreach ($members as $sku) {
            $parentMap[$sku] = $parent;
        }
        if ($baseIsProduct && $parentMap[$base] === null) {
            $parentMap[$base] = $parent;
        }
    }

    // 2) Sufijos con letras: raíz candidata + nombre exacto
    $groups = [];
    foreach ($rowsBySku as $sku => $row) {
        $sku = (string) $sku;
        if ($parentMap[$sku] !== null) continue;
        $root = extractCandidateRootSku($sku);
        if ($root === $sku || preg_match('/^\d+$/', substr($sku, strlen($root) + 1)))
            continue; // sin sufijo de letras
        $groups[$root][trim($row['name'] ?? '')][] = $sku;
    }
    foreach ($groups as $root => $byName) {
        foreach ($byName as $skus) {
            if (count($skus) < 2) continue;
            foreach ($skus as $sku) {
                $parentMap[$sku] = $root;
            }
        }
    }
    return $parentMap;
}

/**
 * Código base de una familia para sus fotos genéricas ("M8363.jpg" vale para todas las variantes).
 * Familia numérica (el padre "M:8363-1" es su propio parent_sku) → "M:8363"; en otro caso, el mismo padre.
 */
function familyMediaBase(PDO $db, string $parent): string
{
    if (!preg_match('/^(.+)[-_]\d+$/', $parent)) {
        return $parent;
    }
    try {
        $stmt = $db->prepare("SELECT parent_sku FROM products WHERE sku = ? LIMIT 1");
        $stmt->execute([$parent]);
        return familyMediaBaseFrom($parent, $stmt->fetchColumn() ?: null);
    } catch (\PDOException $e) {
        // columna parent_sku puede no existir aún
    }
    return $parent;
}

/** familyMediaBase() sin BD: $rootParent = parent_sku del padre. */
function familyMediaBaseFrom(string $root, ?string $rootParent): string
{
    return preg_match('/^(.+)[-_]\d+$/', $root, $m) && $rootParent === $root ? $m[1] : $root;
}

/**
 * Base de fotos genéricas de un producto, en memoria (misma regla que
 * getFamilyRootSku() + familyMediaBase()). $parentOf = mapa sku => parent_sku.
 */
function productMediaBase(string $sku, array $parentOf): string
{
    $root = trim((string) ($parentOf[$sku] ?? ''));
    if ($root === '') {
        $root = extractRootSku($sku);
    }
    return familyMediaBaseFrom($root, $parentOf[$root] ?? null);
}

/**
 * Índice para matchFileToProduct(): cada escritura del código (skuNameVariants) => SKU.
 * Si dos códigos chocan gana el primero de $skus (pasar los activos primero).
 * @param string[] $skus
 * @return array<string,string>
 */
function skuMatchIndex(array $skus): array
{
    $index = [];
    foreach ($skus as $sku) {
        $clean = cleanSkuDisplay(trim((string) $sku));
        if ($clean !== '') $index[strtolower($clean)] ??= (string) $sku;
    }
    foreach ($skus as $sku) {
        foreach (skuNameVariants(cleanSkuDisplay(trim((string) $sku))) as $variant) {
            $index[$variant] ??= (string) $sku;
        }
    }
    unset($index['']);
    return $index;
}

/**
 * REGLA ÚNICA foto ↔ código: el nombre del archivo es el código ("X6071.jpg") o el código
 * + separador + lo que sea ("X6071-A.jpg", "X6071 (2).jpg"); ver isSkuBoundary(). Gana el código más largo:
 * "839-5-A.jpg" es de "839-5" aunque exista "839". "993-3V1.mp4" no es de nadie.
 * @param array<string,string> $index de skuMatchIndex()
 * @return array{0:string,1:int}|null [SKU, calidad] (3 = nombre igual al código, 2 = código + sufijo)
 */
function matchFileToProduct(string $filename, array $index): ?array
{
    $name = strtolower(trim(pathinfo($filename, PATHINFO_FILENAME)));
    for ($len = strlen($name); $len > 0; $len--) {
        $prefix = substr($name, 0, $len);
        if (!isset($index[$prefix])) continue;
        if ($len === strlen($name)) return [$index[$prefix], 3];
        if (isSkuBoundary($name, $len)) return [$index[$prefix], 2];
    }
    return null;
}

/**
 * Código de producto que propone un nombre de archivo para los productos "solo Drive", o null.
 * "C9081BL-2.png" → "C9081BL-2"; "X-1 (2).jpg" → "X-1"; "503B-77-V1.mp4" → "503B-77";
 * combinados con "+": "S-640+S-647-1.jpg" → "S-640+S-647-1".
 * No valen: sin números, nombres de cámara/WhatsApp ("IMG_1234"), fechas, números de 1-2 cifras.
 */
function driveCodeFromName(string $filename): ?string
{
    $stem = genericMediaStem($filename);
    $stem = rtrim($stem, '.-_ '); // "M8324-4..jpg" (punto de más al final) = "M8324-4"
    $stem = preg_replace('/[-_ ][vf]\d{1,2}$/', '', $stem); // "-V1" video, "-F2" foto
    if (!preg_match('/^[a-z0-9][a-z0-9:.\-_+]{2,39}$/', $stem) || !preg_match('/\d/', $stem)) return null;
    if (preg_match('/^(img|dsc|dscn|vid|pxl|mvimg|screenshot|captura|photo|image|imagen|foto|video|copia|copy|whatsapp|wa\d)/', $stem)) return null;
    if (preg_match('/^\d{4}[-_.]?\d{2}[-_.]?\d{2}/', $stem) || preg_match('/^\d{1,2}$/', $stem)) return null;
    return strtoupper($stem);
}

/** Nombre de archivo para comparar con la base de una familia: sin extensión ni " (2)" de Drive. */
function genericMediaStem(string $filename): string
{
    return trim(preg_replace('/\s*\(\d+\)$/', '', strtolower(trim(pathinfo($filename, PATHINFO_FILENAME)))));
}

/**
 * Recalcula parent_sku de TODO el catálogo con computeParentSkuMap() y guarda
 * solo los que cambian. Es el paso final de TODA escritura de catálogo (sync de
 * Sheets, webhook e importación Excel), así las familias quedan iguales sin
 * importar cuál corrió de último, y los hermanos de un SKU nuevo se actualizan.
 * @return int productos cuyo parent_sku cambió
 */
function recomputeParentSkus(PDO $db): int
{
    // Solo productos activos: un archivado no debe juntar (ni partir) una familia visible
    $rows = $db->query("SELECT sku, name, parent_sku FROM products WHERE archived = 0")->fetchAll(PDO::FETCH_ASSOC);
    $bySku = [];
    foreach ($rows as $r) {
        $bySku[$r['sku']] = $r;
    }
    $changed = 0;
    $upd = $db->prepare("UPDATE products SET parent_sku = ? WHERE sku = ?");
    foreach (computeParentSkuMap($bySku) as $sku => $parent) {
        if (($bySku[$sku]['parent_sku'] ?: null) !== $parent) {
            $upd->execute([$parent, $sku]);
            $changed++;
        }
    }
    return $changed;
}

// ── Códigos condenados (Admin → 🚫 Condenados, migración 034) ──
// Un código condenado se ignora en todo el visor: Hoja master, «Solo Drive», enlace de fotos y búsqueda.
// Si el producto existía, se borra (purgeCondemnedProducts). "M:", "M" y "M-" cuentan como el mismo código.

/** Clave única de un código condenado: minúsculas, sin extensión ni puntos finales, "M:"/"M-"/"M " → "m". */
function condemnedKey(string $code): string
{
    $k = strtolower(rtrim(trim(cleanSkuDisplay(trim($code))), '. '));
    return preg_replace('/^m[:\-. _]?(?=\d)/', 'm', $k);
}

/**
 * Índice de los códigos condenados (skuMatchIndex: todas sus formas de escribirse). Vacío si no hay o si la
 * migración 034 aún no corrió. $fresh = volver a leer la tabla (tras agregar o quitar uno).
 * @return array<string,string>
 */
function condemnedIndex(PDO $db, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    try {
        $codes = $db->query("SELECT code FROM condemned_codes")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\PDOException $e) {
        $codes = [];
    }
    return $cache = $codes ? skuMatchIndex($codes) : [];
}

/** ¿El código (de la hoja, de un producto o buscado) es uno condenado, escrito de cualquier forma? */
function isCondemnedCode(string $code, array $index): bool
{
    if (!$index) return false;
    $name = strtolower(rtrim(trim($code), '. '));
    if ($name === '') return false;
    if (isset($index[$name])) return true;
    foreach (skuNameVariants($name) as $v) {
        if (isset($index[$v])) return true;
    }
    return false;
}

/**
 * ¿El archivo (o carpeta) es de un código condenado? Misma regla que matchFileToProduct: nombre = código,
 * o código + separador + lo que sea ("M8325-1-A.jpg", "M8325-1 (2).jpg"). "M8325-10.jpg" no cuenta.
 */
function isCondemnedFile(string $filename, array $index): bool
{
    if (!$index) return false;
    $name = rtrim(genericMediaStem($filename), '.-_ ');
    for ($len = strlen($name); $len > 0; $len--) {
        if (!isset($index[substr($name, 0, $len)])) continue;
        if (isSkuBoundary($name, $len)) return true;
    }
    return false;
}

/**
 * Borra del visor los productos con código condenado (decisión del dueño 2026-11-21: aquí sí se borra).
 * La llaman el sync de la hoja (MasterSheet::upsert), DriveIndex::relink y Admin → Condenados.
 * @return string[] códigos borrados
 */
function purgeCondemnedProducts(PDO $db): array
{
    $index = condemnedIndex($db);
    if (!$index) return [];
    $ids = [];
    foreach ($db->query("SELECT id, sku FROM products")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (isCondemnedCode((string) $p['sku'], $index)) $ids[(int) $p['id']] = $p['sku'];
    }
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $keys = array_keys($ids);
    try { $db->prepare("DELETE FROM downloads_log WHERE product_id IN ($ph)")->execute($keys); } catch (\PDOException $e) {}
    $db->prepare("DELETE FROM products WHERE id IN ($ph)")->execute($keys);
    recomputeParentSkus($db); // la familia del borrado se rehace sin él
    return array_values($ids);
}

/**
 * Recalcula products.archived (= oculto en el portal) desde sus dos causas:
 * el interruptor del admin (is_active) y estar en la hoja maestra (in_sheet) o tener foto en Drive
 * con su código aunque no esté en la hoja (from_drive, lo pone DriveIndex::relink).
 * Las páginas públicas siguen filtrando por archived = 0. Rehace las familias si algo cambió.
 * @return int productos cuyo estado visible cambió
 */
function refreshProductVisibility(PDO $db): int
{
    $changed = $db->exec(
        "UPDATE products SET archived = IF(is_active = 1 AND (in_sheet = 1 OR from_drive = 1), 0, 1)
         WHERE archived <> IF(is_active = 1 AND (in_sheet = 1 OR from_drive = 1), 0, 1)"
    );
    if ($changed) {
        recomputeParentSkus($db);
    }
    return (int) $changed;
}

/**
 * Filtros del listado de productos del admin → [WHERE sql, params].
 * Los usan el listado (admin_products.php) y la activación masiva "todos los del filtro".
 * $f: q (código o nombre), estado (activos|inactivos|fuera|sinfoto|conportada|sinportada|hoy), marca, categoria, genero.
 * conportada/sinportada/hoy = mismas cuentas que las tarjetas del Dashboard (enlazan aquí).
 */
function adminProductFilter(array $f): array
{
    $where = ['1 = 1'];
    $params = [];
    // Búsqueda inteligente: cada palabra debe aparecer (en cualquier orden) en el código, nombre, marca,
    // categoría, color o en el nombre de la carpeta de Drive donde está su foto. Ej.: «poedagar dama dorado».
    $q = trim((string) ($f['q'] ?? ''));
    // Nombre exacto de archivo pegado en el buscador («S-640+S-647-3.jpg», que no es ningún código):
    // se busca por el código al que está enlazada esa foto (aquí, así el listado y activar/desactivar
    // «todos los del filtro» ven exactamente lo mismo).
    if ($q !== '' && looksLikeFileName($q)) {
        $byFile = photosByFileName(getDB(), $q, 1);
        if ($byFile) $q = (string) array_key_first($byFile);
    }
    $words = $q === '' ? [] : array_slice(preg_split('/\s+/u', $q), 0, 6);
    foreach ($words as $w) {
        $like = '%' . addcslashes($w, '%_\\') . '%';
        $idx = SearchIndex::ready(getDB());
        $where[] = '(' . skuSearchSql('sku') . ' LIKE ? OR name LIKE ? OR ' . ($idx ? 'search_text' : 'brand') . ' LIKE ?)';
        $params[] = '%' . addcslashes(skuSearchNorm($w), '%_\\') . '%';
        array_push($params, $like, $like);
    }
    // Stock: con (> 0) / sin (0 o menos); sin dato de stock solo sale en «todos»
    if (in_array($f['stock'] ?? '', ['con', 'sin'], true)) $where[] = stockSql() . ($f['stock'] === 'con' ? ' > 0' : ' <= 0');
    // Rango de precio (el que ve el público: price_suggested). Acepta «120.000», «$120000», «120000».
    foreach (['pmin' => '>=', 'pmax' => '<='] as $k => $op) {
        $v = preg_replace('/\D/', '', (string) ($f[$k] ?? ''));
        if ($v !== '') {
            $where[] = "price_suggested {$op} ?";
            $params[] = (int) $v;
        }
    }
    // «Contiene la palabra»: en el nombre del producto o en el nombre de su archivo en Drive
    foreach (array_slice(preg_split('/\s+/u', is_string($f['contiene'] ?? null) ? $f['contiene'] : '', -1, PREG_SPLIT_NO_EMPTY), 0, 5) as $w) {
        $like = '%' . addcslashes($w, '%_\\') . '%';
        $where[] = SearchIndex::ready(getDB()) ? '(name LIKE ? OR contains_text LIKE ?)' : '(name LIKE ? OR name LIKE ?)';
        array_push($params, $like, $like);
    }
    // Carpeta de Drive: productos con una foto en ella o en cualquiera de sus subcarpetas
    if (is_string($f['carpeta'] ?? null) && $f['carpeta'] !== '') {
        if (SearchIndex::ready(getDB())) {
            $where[] = 'folder_ids LIKE ?'; // incluye subcarpetas (índice de búsqueda)
            $params[] = '% ' . addcslashes($f['carpeta'], '%_\\') . ' %';
        } else {
            $where[] = 'EXISTS (SELECT 1 FROM drive_index fc WHERE fc.matched_sku = products.sku AND fc.is_folder = 0 AND fc.parent_id = ?)';
            $params[] = $f['carpeta'];
        }
    }
    switch ($f['estado'] ?? '') {
        case 'activos':   $where[] = 'is_active = 1 AND (in_sheet = 1 OR from_drive = 1)'; break;
        case 'inactivos': $where[] = 'is_active = 0'; break;
        case 'fuera':     $where[] = 'in_sheet = 0'; break;
        case 'sinfoto':   $where[] = "(cover_image_url IS NULL OR cover_image_url = '')"; break;
        case 'conportada': $where[] = "archived = 0 AND cover_image_url IS NOT NULL AND cover_image_url <> ''"; break;
        case 'sinportada': $where[] = "archived = 0 AND (cover_image_url IS NULL OR cover_image_url = '')"; break;
        case 'hoy':       $where[] = 'DATE(updated_at) = CURDATE()'; break;
        case 'sinprecio': $where[] = '(price_suggested IS NULL OR price_suggested <= 0)'; break;
        case 'preciomanual': $where[] = 'price_manual = 1'; break;
    }
    if (($f['genero'] ?? '') !== '') {
        $where[] = 'gender = ?';
        $params[] = $f['genero'];
    }
    if (($f['marca'] ?? '') !== '') {
        $where[] = 'brand = ?';
        $params[] = $f['marca'];
    }
    if (($f['categoria'] ?? '') !== '') {
        $where[] = 'category = ?';
        $params[] = $f['categoria'];
    }
    return [implode(' AND ', $where), $params];
}

/**
 * Clave de familia de un producto para agrupar tarjetas (inicio y búsqueda):
 * parent_sku si lo tiene; si no, su raíz por patrón de SKU.
 */
function familyKey(array $product): string
{
    return !empty($product['parent_sku'])
        ? $product['parent_sku']
        : extractRootSku($product['sku']);
}

/**
 * ¿Una carpeta de primer nivel de Drive debe ser álbum (marca)?
 * No lo son las auxiliares: las que empiezan por "_" (ej. "_INDICE") ni "PORTADAS".
 */
function isAlbumFolderName(string $name): bool
{
    $name = trim($name);
    return $name !== '' && !str_starts_with($name, '_') && strcasecmp($name, 'PORTADAS') !== 0;
}

/**
 * Nombre de carpeta para mostrar al público: sin el número de orden del inicio ("2.CURREN" → "CURREN").
 * Solo para mostrar: el orden sigue usando el nombre completo. Decisión del dueño, 2026-11-13.
 */
function folderDisplayName(string $name): string
{
    $clean = trim(preg_replace('/^\d+\s*\.\s*/', '', $name));
    return $clean !== '' ? $clean : $name;
}

/**
 * ¿La carpeta es "archivada" o "desactivada"? (decisión del dueño, 2026-11-09: se oculta por defecto con todo su contenido).
 * Sin distinguir mayúsculas ni tildes: "ARCHIVADO", "archivados", "Archivado 2024", "DESACTIVADOS", "Desactivadas"…
 */
function isArchivedFolderName(string $name): bool
{
    $plain = strtr(mb_strtolower($name, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    return (bool) preg_match('/(archivad|desactivad)[oa]s?/', $plain);
}

/**
 * Verifica si un nombre de archivo es la portada GENÉRICA del código.
 * (Ej: archivo "839-5.jpg" es genérica para "839-5", pero "839-5-1.jpg" es específica).
 * 
 * Se usa para filtrar que un hijo NO vea las fotos de sus hermanos.
 */
function isGenericMediaForSku(string $sku, string $filename): bool
{
    // Sin " (1)", " (2)" de Drive. "M:8363" en la hoja = "M8363.jpg" / "M-8363.jpg" en Drive
    $nameOnly = genericMediaStem($filename);
    return $nameOnly === strtolower($sku) || in_array($nameOnly, skuNameVariants($sku), true);
}

/**
 * Filtra archivos ocultos según la tabla drive_cache.visible_publico.
 * Archivos con visible_publico = 0 no se muestran al público.
 */
function filterHiddenFiles(PDO $db, array $files): array
{
    if (empty($files))
        return $files;
    $ids = array_column($files, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $db->prepare("SELECT file_id FROM drive_cache WHERE file_id IN ({$placeholders}) AND visible_publico = 0");
        $stmt->execute($ids);
        $hiddenIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($hiddenIds)) {
            $hiddenSet = array_flip($hiddenIds);
            $files = array_values(array_filter($files, fn($f) => !isset($hiddenSet[$f['id']])));
        }
    } catch (Exception $e) {
        // drive_cache table might not exist yet, skip filtering
    }
    return $files;
}

/**
 * Puntúa un archivo de Drive como candidato a portada.
 * Las IMÁGENES siempre ganan a los videos (evita portadas con el primer
 * cuadro del video, que suele ser el logo de la marca). Entre imágenes,
 * se prefieren nombres tipo "principal", "cover", "01", "-1", "f1".
 */
function scoreCoverCandidate(array $file): int
{
    $name = strtolower($file['name'] ?? '');
    $mime = $file['mimeType'] ?? '';
    $score = 0;
    if (str_starts_with($mime, 'image/')) {
        $score += 100;
    } elseif (str_starts_with($mime, 'video/') && !empty($file['thumbnailLink'])) {
        $score += 1; // video con thumbnail es mejor que video sin thumbnail
    }
    foreach (['principal', 'cover', 'portada', 'front', 'frente'] as $kw) {
        if (str_contains($name, $kw)) $score += 10;
    }
    foreach (['01', '_1', '-1', 'f1'] as $np) {
        if (str_contains($name, $np)) $score += 5;
    }
    return $score;
}

/**
 * Elige el mejor archivo de portada (imagen preferida, video como fallback).
 */
function pickBestCoverFile(array $files): ?array
{
    $best = null;
    $bestScore = -1;
    foreach ($files as $f) {
        $mime = $f['mimeType'] ?? '';
        if (!str_starts_with($mime, 'image/') && !str_starts_with($mime, 'video/')) {
            continue;
        }
        $score = scoreCoverCandidate($f);
        if ($score > $bestScore) {
            $best = $f;
            $bestScore = $score;
        }
    }
    return $best;
}

/**
 * Resuelve el SKU raíz de familia para búsquedas de media.
 * Prioriza parent_sku de la BD (calculado con contexto de hermanos, que sí
 * agrupa sufijos numéricos como ETN54L-2/-4/-6 → ETN54L) y cae en
 * extractRootSku() si la columna no existe o el producto no tiene familia.
 */
function getFamilyRootSku(PDO $db, string $sku): string
{
    try {
        $stmt = $db->prepare("SELECT parent_sku FROM products WHERE sku = ? LIMIT 1");
        $stmt->execute([$sku]);
        $parent = $stmt->fetchColumn();
        if (is_string($parent) && trim($parent) !== '') {
            return trim($parent);
        }
    } catch (\PDOException $e) {
        // columna parent_sku puede no existir aún
    }
    return extractRootSku($sku);
}

/** Vigencia de media_search_cache para portadas (las URLs lh3 por ID no caducan). */
const COVER_CACHE_TTL_HOURS = 6;
/** Vigencia de un "sin foto" cacheado: corta, para que una foto recién subida aparezca pronto. */
const EMPTY_CACHE_TTL_MINUTES = 30;

/** Condición SQL de caché vigente en media_search_cache (portada con foto o "sin foto" reciente). */
function freshCoverCacheSql(): string
{
    return "cached_at > NOW() - INTERVAL " . COVER_CACHE_TTL_HOURS . " HOUR
            AND (files_json <> '[]' OR cached_at > NOW() - INTERVAL " . EMPTY_CACHE_TTL_MINUTES . " MINUTE)";
}

/**
 * Busca en Drive los archivos de un SKU (bidireccional padre↔hijo), los hace
 * públicos, guarda el resultado en media_search_cache (incluido "sin foto") y auto-asigna portada
 * (imagen-primero) si el producto no tiene. Lógica compartida entre
 * /api/media, /api/covers/batch y el precache cron.
 *
 * @return array{files: array, root_sku: string, failed: bool}
 */
function fetchAndCacheMediaForSku(PDO $db, GoogleDriveService $drive, string $folderId, string $sku): array
{
    $rootSku = getFamilyRootSku($db, $sku);
    $base = familyMediaBase($db, $rootSku); // "M:8363" para la familia de "M:8363-1"

    // 1) Buscar por la base de la familia (trae padre + todos los hijos)
    $rootFiles = $drive->findBySku($folderId, $base);
    $driveFailed = $drive->lastRequestFailed;

    $childFiles = [];
    if ($sku !== $base) {
        $childFiles = $drive->findBySku($folderId, $sku);
        $driveFailed = $driveFailed || $drive->lastRequestFailed;
    }
    $files = combineFamilyFiles($sku, $base, $rootFiles, $childFiles);

    // Si Drive falló y no hay archivos, no se cachea nada: sería un falso "sin foto"
    if (!$files && $driveFailed) {
        return ['files' => [], 'root_sku' => $rootSku, 'failed' => true];
    }

    if ($files) {
        // Antes de cachear: las URLs lh3 por ID solo cargan si el archivo es público
        $drive->makePublicBatch(array_column($files, 'id'));
    }

    // Cachear resultado. Los vacíos también se cachean ("sin foto"), así un SKU sin
    // fotos no vuelve a consultar Drive en cada visita.
    try {
        $saveStmt = $db->prepare(
            "INSERT INTO media_search_cache (sku, root_sku, files_json, cached_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE root_sku = VALUES(root_sku), files_json = VALUES(files_json), cached_at = NOW()"
        );
        $saveStmt->execute([$sku, $rootSku, json_encode($files)]);
    } catch (Exception $e) {
        // tabla puede no existir aún
    }

    autoAssignCover($db, $sku, $files);

    return ['files' => $files, 'root_sku' => $rootSku, 'failed' => false];
}

/**
 * Archivos que ve un SKU: si es variante, los suyos propios + los genéricos del
 * padre (p. ej. "839-5.jpg"), sin los de sus hermanos. Si es raíz, los de la raíz.
 */
function combineFamilyFiles(string $sku, string $rootSku, array $rootFiles, array $childFiles): array
{
    if ($sku === $rootSku) {
        return array_values($rootFiles);
    }
    $files = $childFiles;
    $seen = array_flip(array_column($childFiles, 'id'));
    foreach ($rootFiles as $rf) {
        if (!isset($seen[$rf['id']]) && isGenericMediaForSku($rootSku, $rf['name'])) {
            $files[] = $rf;
        }
    }
    return array_values($files);
}

/**
 * Si el producto aún no tiene portada, le asigna la mejor IMAGEN (nunca video)
 * de $files, para que la próxima visita la pinte directo desde la BD.
 */
function autoAssignCover(PDO $db, string $sku, array $files): void
{
    $imgOnly = array_filter($files, fn($f) => str_starts_with($f['mimeType'] ?? '', 'image/'));
    $bestImage = pickBestCoverFile($imgOnly);
    if (!$bestImage) return;
    try {
        $db->prepare("UPDATE products SET cover_image_url = ? WHERE sku = ? AND (cover_image_url IS NULL OR cover_image_url = '')")
           ->execute([lh3Url($bestImage['id']), $sku]);
    } catch (Exception $e) {
        // ignorar errores de auto-cover
    }
}

/**
 * Extrae la portada de un array de archivos Drive (imagen preferida sobre video).
 */
function extractCoverFromFiles(array $files): ?array
{
    $best = pickBestCoverFile($files);
    if (!$best) return null;

    // URL estable por ID (el archivo ya es público; para videos lh3 da el fotograma).
    // thumbnailLink de la API caduca en pocas horas: no sirve para respuestas cacheadas.
    return [
        'url' => lh3Url($best['id']),
        'video' => str_starts_with($best['mimeType'] ?? '', 'video/'),
    ];
}

/**
 * Cambia el tamaño de una URL de lh3.googleusercontent.com (sufijo =sNNN).
 * Ej: miniaturas de variantes a =s150 en vez de bajar la portada de 400px.
 */
function coverUrlAtSize(string $url, int $size): string
{
    if (!str_contains($url, 'googleusercontent.com/')) {
        return $url;
    }
    $resized = preg_replace('/=s\d+(-rj)?$/', "=s{$size}" . lh3JpegSuffix($size), $url, 1, $count);
    if ($count) {
        return $resized;
    }
    // Sin sufijo → se agrega; con otro sufijo (=w400, =s220-c…) se deja intacta
    return str_contains(basename($url), '=') ? $url : $url . "=s{$size}";
}

/**
 * URL estable de un archivo público de Drive a un tamaño dado (no caduca, a diferencia
 * del thumbnailLink de la API). Es el formato que se guarda en products.cover_image_url.
 */
function lh3Url(string $fileId, int $size = 400): string
{
    return "https://lh3.googleusercontent.com/d/{$fileId}=s{$size}" . lh3JpegSuffix($size);
}

/**
 * "-rj" (pedir JPEG) en tamaños grandes: si el original es PNG, lh3 lo entrega en PNG desde =s800
 * (826 KB vs 147 KB en JPEG; =s1600: 1,3 MB vs 192 KB). Las tarjetas (=s400) ya salen en JPEG.
 */
function lh3JpegSuffix(int $size): string
{
    return $size >= 600 ? '-rj' : '';
}

/**
 * Atributos de carga para la foto de una tarjeta de la grilla: el navegador elige 200 o 400 px
 * según la pantalla (un computador normal baja la de 200; el celular, la de 400), y las primeras
 * tarjetas cargan ya y con prioridad. Si la URL no es de lh3 solo pone el modo de carga.
 * Si un script cambia la foto (variantes), debe quitar el srcset.
 */
function cardImgAttrs(string $url, bool $eager): string
{
    $attrs = $eager ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"';
    if (preg_match('#^(https://lh3\.googleusercontent\.com/d/[^=]+)=s\d+$#', $url, $m)) {
        $attrs .= ' srcset="' . e($m[1]) . '=s200 200w, ' . e($m[1]) . '=s400 400w"'
            . ' sizes="(max-width: 640px) 50vw, 200px"';
    }
    return $attrs;
}

/**
 * Decodifica un cover_image_url guardado: quita el prefijo "[VIDEO]" y ajusta el tamaño.
 * @return array{url: string, video: bool}
 */
function parseStoredCover(?string $stored, int $size): array
{
    $stored = (string) $stored;
    $isVideo = str_starts_with($stored, '[VIDEO]');
    $url = $isVideo ? substr($stored, 7) : $stored;
    return ['url' => $url !== '' ? coverUrlAtSize($url, $size) : '', 'video' => $isVideo];
}

/**
 * Limpia un SKU para display: quita extensiones de archivo (.jpg, .png, etc.)
 * Centralizado aquí para evitar duplicados en landing.php y search.php.
 */
function cleanSkuDisplay(string $sku): string
{
    return preg_replace('/\.\w{2,4}$/i', '', $sku);
}

// ── Compartir por WhatsApp (vista previa del enlace) ──

/**
 * URL pública del sitio (https://dominio), para enlaces absolutos en las etiquetas Open Graph.
 * Usa APP_URL si está configurada (y no es localhost); si no, el host de la petición.
 */
function publicBaseUrl(): string
{
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    if ($appUrl !== '' && !preg_match('#//(localhost|127\.0\.0\.1)#', $appUrl)) {
        return $appUrl;
    }
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', explode(',', $host)[0]);
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
    $proto = trim(explode(',', $proto)[0]) === 'https' ? 'https' : 'http';
    return "{$proto}://{$host}";
}

/** ID de Drive dentro de una URL de lh3/Drive (".../d/{id}=s400", "?id={id}"); null si no hay. */
function driveIdFromUrl(?string $url): ?string
{
    if ($url && preg_match('#(?:/d/|[?&]id=)([A-Za-z0-9_-]{10,})#', $url, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Imagen para la vista previa de WhatsApp: se sirve desde el propio dominio (/og/{id}.jpg),
 * en JPEG liviano, porque WhatsApp no muestra imágenes pesadas ni algunas de Google.
 */
function shareImageUrl(string $fileId): string
{
    return publicBaseUrl() . '/og/' . rawurlencode($fileId) . '.jpg';
}

/**
 * Etiquetas Open Graph para que el enlace se vea con foto, título y descripción en WhatsApp.
 * @param array{title: string, description?: string, url: string, image_id?: ?string, type?: string} $m
 */
function shareMetaTags(array $m): string
{
    $tags = [
        'og:site_name' => 'Viewfinder Kino',
        'og:type' => $m['type'] ?? 'website',
        'og:title' => $m['title'],
        'og:description' => $m['description'] ?? '',
        'og:url' => $m['url'],
        'og:locale' => 'es_CO',
    ];
    if (!empty($m['image_id'])) {
        $tags['og:image'] = shareImageUrl($m['image_id']);
        $tags['og:image:type'] = 'image/jpeg';
        $tags['og:image:width'] = (string) OG_IMAGE_SIZE;
        $tags['og:image:height'] = (string) OG_IMAGE_SIZE;
        $tags['og:image:alt'] = $m['title'];
    }
    $html = '';
    foreach ($tags as $property => $content) {
        if ($content === '') continue;
        $html .= '<meta property="' . $property . '" content="' . e($content) . "\">\n    ";
    }
    $html .= '<meta name="twitter:card" content="' . (!empty($m['image_id']) ? 'summary_large_image' : 'summary') . "\">\n    ";
    if (($m['description'] ?? '') !== '') {
        $html .= '<meta name="description" content="' . e($m['description']) . "\">\n";
    }
    return $html;
}

/** Lado (px) de la imagen de vista previa: cuadrada, como las fotos de producto. */
const OG_IMAGE_SIZE = 600;

/** Enlace de WhatsApp que abre el chat con el texto listo: título en negrita + enlace (WhatsApp arma la vista previa). */
function whatsappShareHref(string $title, string $url): string
{
    return 'https://wa.me/?text=' . rawurlencode("*{$title}*\n{$url}");
}

/**
 * Botones "WhatsApp" + "Descargar" de un archivo (tarjetas y carpetas). Los maneja assets/js/media_actions.js.
 * $url = página a la que lleva el enlace compartido (ficha del producto). Al hacer clic se usa la foto que
 * muestra la tarjeta en ese momento (portada cargada después por JS, variante elegida); $fileId es el respaldo.
 */
function mediaActionButtons(string $fileId, string $fileName, string $title, string $url): string
{
    $data = 'data-file-id="' . e($fileId) . '" data-name="' . e($fileName) . '" data-title="' . e($title) . '" data-url="' . e($url) . '"';
    return '<div class="media-actions">'
        . '<button type="button" class="media-btn media-btn-wa" data-media-action="wa" ' . $data . ' title="Enviar por WhatsApp">'
        . '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21c5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2zm5.8 14.03c-.25.69-1.44 1.32-2.01 1.41-.51.08-1.16.11-1.87-.12-.43-.14-.98-.32-1.69-.63-2.98-1.29-4.93-4.29-5.08-4.49-.15-.2-1.21-1.61-1.21-3.07 0-1.46.77-2.18 1.04-2.48.27-.3.59-.37.79-.37h.57c.18.01.43-.07.67.51.25.6.84 2.06.92 2.21.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.17-.31.39-.45.52-.15.15-.3.31-.13.61.17.3.77 1.27 1.65 2.06 1.14 1.01 2.09 1.33 2.39 1.48.3.15.47.12.64-.07.17-.2.74-.87.94-1.16.2-.3.4-.25.67-.15.27.1 1.73.82 2.03.97.3.15.5.22.57.35.08.12.08.72-.17 1.41z"/></svg>'
        . '<span>WhatsApp</span></button>'
        . '<button type="button" class="media-btn media-btn-dl" data-media-action="dl" ' . $data . ' title="Descargar">' . icon('download') . '<span>Descargar</span></button>'
        . '</div>';
}

require_once __DIR__ . '/services/SearchIndex.php';

/** Tarjetas que se muestran de entrada en listas largas (carpetas, Novedades); el resto con «Cargar más». */
const LOAD_MORE_STEP = 60;

/** Clase para la tarjeta número $idx (desde 0) de una lista con «Cargar más»: las que pasan del primer tramo van ocultas. */
function loadMoreClass(int $idx): string
{
    return $idx >= LOAD_MORE_STEP ? ' lm-hide' : '';
}

/**
 * Botón «Cargar más» para la grilla justo anterior (las tarjetas con loadMoreClass()). Muestra de a LOAD_MORE_STEP.
 * Las fotos ocultas no se descargan (loading="lazy" + display:none), así la página abre más rápido en el celular.
 */
function loadMoreButton(int $total): string
{
    if ($total <= LOAD_MORE_STEP) return '';
    $left = $total - LOAD_MORE_STEP;
    return '<div class="lm-wrap"><button type="button" class="btn btn-secondary lm-btn" data-step="' . LOAD_MORE_STEP . '"'
        . ' onclick="(function(b){var g=b.parentNode.previousElementSibling,h=g?g.querySelectorAll(\'.lm-hide\'):[],n=+b.dataset.step;'
        . 'for(var i=0;i<h.length&&i<n;i++)h[i].classList.remove(\'lm-hide\');var r=h.length-Math.min(n,h.length);'
        . 'if(r>0){b.textContent=\'Cargar más · quedan \'+r;}else{b.parentNode.remove();}})(this)">'
        . 'Cargar más · quedan ' . $left . '</button></div>';
}


/** Icono de línea (SVG en línea, hereda el color del texto; estilo Lucide). Nombres: home, search, folder, star, send, clipboard, receipt, download, copy, sliders, chevron, image, back */
function icon(string $name, string $class = ''): string
{
    static $paths = [
        'home' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'star' => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
        'send' => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
        'clipboard' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'copy' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'sliders' => '<line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'image' => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
        'back' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg class="ico ico-' . $name . ($class !== '' ? ' ' . $class : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

/** Botón fijo «Pedidos bodega» del encabezado de todas las páginas públicas (enlace siempre /pedidos-alistar; visible también en celular). */
function pedidosHeaderBtn(): string
{
    return '<a href="/pedidos-alistar" class="btn btn-sm btn-primary hdr-pedidos" aria-label="Pedidos bodega" style="font-size:0.8rem; padding:0.4rem 0.8rem;" title="Pedidos por alistar (rojo) y listos (verde)">' . icon('clipboard') . ' Pedidos bodega</a>';
}
