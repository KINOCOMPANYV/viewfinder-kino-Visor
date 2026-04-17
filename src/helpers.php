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
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
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
    // Eliminar sufijo del tipo -XX o _XX (letras o números) al final del string
    // Ej: "839_1" -> "839", "839-A" -> "839", "839-5" -> "839"
    if (preg_match('/^(.+)([-_][a-zA-Z0-9]+)$/', $code, $matches)) {
        return $matches[1];
    }
    return $code;
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
 * Extrae la portada (primer imagen o video) de un array de archivos Drive.
 */
function extractCoverFromFiles(array $files): ?array
{
    // Buscar primera imagen
    foreach ($files as $f) {
        if (str_starts_with($f['mimeType'] ?? '', 'image/')) {
            $thumb = $f['thumbnailLink'] ?? '';
            $url = $thumb
                ? preg_replace('/=s\d+/', '=s400', $thumb)
                : "https://lh3.googleusercontent.com/d/{$f['id']}=s400";
            return ['url' => $url, 'video' => false];
        }
    }
    // Fallback: primer video
    foreach ($files as $f) {
        if (str_starts_with($f['mimeType'] ?? '', 'video/') && !empty($f['thumbnailLink'])) {
            return ['url' => preg_replace('/=s\d+/', '=s400', $f['thumbnailLink']), 'video' => true];
        }
    }
    return null;
}

/**
 * Limpia un SKU para display: quita extensiones de archivo (.jpg, .png, etc.)
 * Centralizado aquí para evitar duplicados en landing.php y search.php.
 */
function cleanSkuDisplay(string $sku): string
{
    return preg_replace('/\.\w{2,4}$/i', '', $sku);
}
