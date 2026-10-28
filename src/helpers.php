<?php
/**
 * Funciones helper reutilizables.
 */

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

        redirect('/admin/login');
    }
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

/**
 * Formatea un precio.
 */
function formatPrice(float $price): string
{
    return '$' . number_format($price, 2, '.', ',');
}

/**
 * Trunca un texto a N caracteres.
 */
function truncate(string $text, int $length = 100): string
{
    if (mb_strlen($text) <= $length)
        return $text;
    return mb_substr($text, 0, $length) . '...';
}

/**
 * Verifica si un nombre de archivo coincide con un SKU usando regla de prefijo.
 * 
 * Regla: El nombre del archivo debe comenzar con el SKU y el siguiente carácter
 * (si existe) NO puede ser un dígito (evita que "123-1" coincida con "123-10").
 * 
 * Soporta variantes de SKU separadas por: guion bajo, punto, espacio o letra directa.
 * 
 * Ejemplos para SKU "123-1":
 *   ✅ 123-1.jpg        (SKU + extensión)
 *   ✅ 123-1f1.jpg      (SKU + letra + ...)
 *   ✅ 123-1v2.jpg      (SKU + letra + ...)
 *   ✅ 123-1_foto.jpg   (SKU + guion bajo + ...)
 *   ✅ 123-1_V1.jpg     (Variante V1)
 *   ✅ 123-1_ROJO.jpg   (Variante ROJO)
 *   ✅ 123-1.A.jpg      (Variante .A)
 *   ✅ 123-1 foto.jpg   (SKU + espacio + ...)
 *   ❌ 123-10.jpg       (SKU + dígito = otro producto)
 *   ❌ 123-12.jpg       (SKU + dígito = otro producto)
 *   ❌ 456-1.jpg        (SKU diferente)
 */
function skuMatchesFilename(string $sku, string $filename): bool
{
    // Quitar extensión del nombre para comparar
    $nameOnly = pathinfo($filename, PATHINFO_FILENAME);

    // Verificar que el nombre comience con el SKU (case-insensitive)
    if (stripos($nameOnly, $sku) !== 0) {
        return false;
    }

    // Si el nombre es exactamente el SKU, es match
    if (strlen($nameOnly) === strlen($sku)) {
        return true;
    }

    // El carácter siguiente al SKU determina si es match
    $nextChar = $nameOnly[strlen($sku)];

    // Si es dígito, NO es match (evita 123-1 → 123-10)
    if (ctype_digit($nextChar)) {
        return false;
    }

    // Cualquier otro carácter es válido: letra (f, v), guion bajo (_), 
    // punto (.), espacio ( ), guion (-) → soporta variantes y sufijos
    return true;
}

/**
 * Regla de coincidencia foto ↔ SKU usada al buscar media de un producto:
 * el nombre (sin extensión) empieza con el SKU y lo que sigue es un separador
 * (guion, punto, espacio, guion bajo) o nada. Evita que "1948" coincida con
 * "1948O", "JR1948O" o "1948DG".
 */
function fileNameStartsWithSku(string $filename, string $sku): bool
{
    $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    $skuLower = strtolower($sku);
    if (!str_starts_with($name, $skuLower)) return false;
    $len = strlen($skuLower);
    return strlen($name) === $len || !ctype_alnum($name[$len]);
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
 * Recalcula parent_sku para un conjunto de filas (sku => ['sku'=>, 'name'=>, ...]),
 * agrupando por SKU raíz candidato + nombre EXACTO. Solo se asigna parent_sku
 * cuando hay 2+ SKUs distintos que comparten raíz y nombre — así "992-1/2/3/4"
 * (mismo nombre) se agrupan, pero "839-5" y "839-10" (nombres distintos) no.
 *
 * @param array<string,array{sku:string,name:string}> $rowsBySku
 * @return array<string,?string> mapa sku => parent_sku (o null si no tiene familia)
 */
function computeParentSkuMap(array $rowsBySku): array
{
    $groups = [];
    foreach ($rowsBySku as $sku => $row) {
        $root = extractCandidateRootSku($sku);
        if ($root === $sku)
            continue; // sin sufijo separable, no puede tener padre
        $name = trim($row['name'] ?? '');
        $groups[$root][$name][] = $sku;
    }

    $parentMap = [];
    foreach ($rowsBySku as $sku => $row) {
        $root = extractCandidateRootSku($sku);
        $name = trim($row['name'] ?? '');
        $siblings = $groups[$root][$name] ?? [$sku];
        $parentMap[$sku] = (count($siblings) > 1) ? $root : null;
    }
    return $parentMap;
}

/**
 * Verifica si un nombre de archivo es la portada GENÉRICA del código.
 * (Ej: archivo "839-5.jpg" es genérica para "839-5", pero "839-5-1.jpg" es específica).
 * 
 * Se usa para filtrar que un hijo NO vea las fotos de sus hermanos.
 */
function isGenericMediaForSku(string $sku, string $filename): bool
{
    $nameOnly = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    $skuLower = strtolower($sku);
    
    // Quitar sufijos de duplicados de Drive " (1)", " (2)", etc.
    $nameOnly = preg_replace('/\s*\(\d+\)$/', '', $nameOnly);
    
    return $nameOnly === $skuLower;
}

/**
 * Extrae el SKU sin el prefijo de marca (letras antes del primer dígito).
 * 
 * Muchas veces los archivos en Drive se nombran sin el prefijo de marca
 * del catálogo. Esta función permite matchear ambos formatos.
 * 
 * Ejemplos:
 *   "KNM-8845-RG"  → "8845-RG"     (quita KNM-)
 *   "KNM-8845"     → "8845"         (quita KNM-)
 *   "ABC-1234-V1"  → "1234-V1"      (quita ABC-)
 *   "8845-RG"      → "8845-RG"      (ya no tiene prefijo, no cambia)
 *   "WATCH-99"     → "99"           (quita WATCH-)
 *   "123-456"      → "123-456"      (empieza con dígito, no cambia)
 */
function extractSkuWithoutPrefix(string $sku): string
{
    $sku = trim($sku);
    
    // Si ya empieza con dígito, no tiene prefijo
    if (preg_match('/^\d/', $sku)) {
        return $sku;
    }
    
    // Quitar prefijo de letras seguido opcionalmente de guion, punto, espacio o guion bajo:
    // "KNM-8845-RG" → "8845-RG", "NO 2218" → "2218", "NO.2405" → "2405"
    if (preg_match('/^[A-Za-z]+[-. _]?(\d.*)$/', $sku, $matches)) {
        return $matches[1];
    }
    
    return $sku;
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

    // 1) Buscar por el SKU raíz (trae padre + todos los hijos)
    $rootFiles = $drive->findBySku($folderId, $rootSku);
    $driveFailed = $drive->lastRequestFailed;

    $childFiles = [];
    if ($sku !== $rootSku) {
        $childFiles = $drive->findBySku($folderId, $sku);
        $driveFailed = $driveFailed || $drive->lastRequestFailed;
    }
    $files = combineFamilyFiles($sku, $rootSku, $rootFiles, $childFiles);

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
    $resized = preg_replace('/=s\d+$/', "=s{$size}", $url, 1, $count);
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
    return "https://lh3.googleusercontent.com/d/{$fileId}=s{$size}";
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
