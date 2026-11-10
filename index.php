<?php
/**
 * Viewfinder Kino Visor — Router principal.
 * Todas las peticiones pasan por aquí gracias a .htaccess
 * @deploy 2026-02-18
 */
// Páginas públicas (GET fuera de /admin): sin "no-store" de la sesión, para que el botón Atrás
// del celular vuelva al instante a la búsqueda o carpeta (caché de ida y vuelta) en vez de recargarla.
// "no-cache" obliga a pedirla de nuevo al navegar normal; cada controlador puede cambiarlo.
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && strpos($reqPath, '/admin') !== 0) {
    session_cache_limiter('');
    header('Cache-Control: private, no-cache');
}
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/src/helpers.php';

// ============================================================
// CORS — permitir llamadas desde orígenes configurados
// Configurar CORS_ALLOWED_ORIGINS en Railway para restringir
// Ejemplo: "https://poedagar.com,https://tu-dominio.com"
// ============================================================
$allowedOrigins = env('CORS_ALLOWED_ORIGINS', '*');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($allowedOrigins === '*') {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin) {
    $allowed = array_map('trim', explode(',', $allowedOrigins));
    if (in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Vary: Origin');
    }
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================
// Rate Limiting — protección básica contra abuso en APIs públicas
// Máximo N peticiones por minuto por IP (configurable)
// ============================================================
function checkRateLimit(int $maxPerMinute = 60): void
{
    $ip = getClientIP();
    $window = intdiv(time(), 60); // ventana fija de 60s

    // Contador por IP en disco: compartido entre peticiones aunque el cliente
    // no mande cookies (el contador en $_SESSION era evadible descartándolas).
    $dir = __DIR__ . '/storage/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $count = 1;
    $file = $dir . '/' . md5($ip) . '.json';
    $fh = @fopen($file, 'c+');
    if ($fh !== false && flock($fh, LOCK_EX)) {
        $data = json_decode(stream_get_contents($fh), true) ?: [];
        if (($data['window'] ?? 0) === $window) {
            $count = ($data['count'] ?? 0) + 1;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode(['window' => $window, 'count' => $count]));
        flock($fh, LOCK_UN);
        fclose($fh);

        // Limpieza ocasional de contadores viejos (~1% de las peticiones)
        if (random_int(1, 100) === 1) {
            foreach ((glob($dir . '/*.json') ?: []) as $old) {
                if (@filemtime($old) < time() - 600) {
                    @unlink($old);
                }
            }
        }
    } else {
        // Fallback si el disco no es escribible: contador en sesión
        $key = 'rl_' . md5($ip);
        $sess = $_SESSION[$key] ?? ['count' => 0, 'window' => 0];
        if (($sess['window'] ?? 0) !== $window) {
            $sess = ['count' => 0, 'window' => $window];
        }
        $sess['count']++;
        $_SESSION[$key] = $sess;
        $count = $sess['count'];
    }

    if ($count > $maxPerMinute) {
        http_response_code(429);
        header('Retry-After: ' . (60 - (time() % 60)));
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Demasiadas peticiones. Intenta en un momento.']);
        exit;
    }
}

// Obtener la ruta antes de auto-migrar
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Health debug — funciona SIN conexión a DB
// Solo accesible en desarrollo, o en producción con ?token=CRON_SECRET
if ($uri === '/health/debug') {
    header('Content-Type: application/json');
    $debugSecret = env('CRON_SECRET', '');
    $debugAllowed = (env('APP_ENV', 'development') !== 'production')
        || ($debugSecret !== '' && hash_equals($debugSecret, $_GET['token'] ?? ''));
    if (!$debugAllowed) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }
    $url = env('MYSQL_URL', env('MYSQL_PUBLIC_URL', env('DATABASE_URL', '')));
    $resolved = ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'viewfinder_kino', 'user' => 'root'];
    if ($url !== '') {
        $p = parse_url($url);
        $resolved = ['host' => $p['host'] ?? '?', 'port' => $p['port'] ?? '?', 'database' => ltrim($p['path'] ?? '', '/'), 'user' => $p['user'] ?? '?'];
    }
    echo json_encode([
        'mysql_url' => $url !== '' ? '✅ SET (' . strlen($url) . ' chars)' : '❌ NOT SET',
        'mysql_public_url' => env('MYSQL_PUBLIC_URL') !== '' ? '✅ SET' : '❌ NOT SET',
        'getenv_works' => getenv('PATH') !== false ? 'yes' : 'no',
        'individual_vars' => [
            'DB_HOST' => env('DB_HOST') ?: '(empty)',
            'MYSQLHOST' => env('MYSQLHOST') ?: '(empty)',
        ],
        'resolved' => $resolved,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Ruta ya obtenida arriba

// ============================================================
// RUTAS PÚBLICAS (Portal del Distribuidor)
// ============================================================

if ($uri === '/' || $uri === '') {
    // Landing page con buscador
    session_write_close();
    include __DIR__ . '/templates/client/landing.php';
    exit;
}

if ($uri === '/buscar' || $uri === '/search') {
    // Búsqueda de productos
    session_write_close();
    include __DIR__ . '/src/controllers/search.php';
    exit;
}

if (preg_match('#^/producto/([^/]+)$#', $uri, $matches)) {
    // Ficha del producto por SKU
    session_write_close();
    $_GET['sku'] = urldecode($matches[1]);
    include __DIR__ . '/src/controllers/product.php';
    exit;
}

if (preg_match('#^/carpeta/([^/]+)$#', $uri, $matches)) {
    // Vista de carpeta de Drive — contenido real
    session_write_close();
    $_GET['folder_id'] = urldecode($matches[1]);
    include __DIR__ . '/src/controllers/folder_view.php';
    exit;
}

if (preg_match('#^/og/([A-Za-z0-9_-]+)\.jpg$#', $uri, $matches)) {
    // Imagen de vista previa para WhatsApp (etiqueta og:image) servida desde el propio dominio
    session_write_close();
    include __DIR__ . '/src/controllers/og_image.php';
    exit;
}

if ($uri === '/api/search') {
    // API de búsqueda (para autocomplete AJAX)
    checkRateLimit(120); // 120 req/min — autocomplete es frecuente
    session_write_close();
    include __DIR__ . '/src/controllers/api_search.php';
    exit;
}

// Enviar catálogo por lista (público): pegar texto → códigos → fotos → WhatsApp. Solo lee la BD.
if ($uri === '/novedades') {
    include __DIR__ . '/src/controllers/news.php';
    exit;
}

if ($uri === '/mi-seleccion') {
    include __DIR__ . '/templates/client/my_selection.php';
    exit;
}

if ($uri === '/enviar-catalogo') {
    include __DIR__ . '/templates/client/catalog_send.php';
    exit;
}

if ($uri === '/enviar-catalogo/extraer' && $method === 'POST') {
    checkRateLimit(30);
    $catalogAction = 'extract';
    include __DIR__ . '/src/controllers/catalog_send.php';
    exit;
}

if (preg_match('#^/enviar-catalogo/foto/([a-zA-Z0-9_-]+)$#', $uri, $matches)) {
    checkRateLimit(300); // fotos: precarga + reintentos de una lista larga
    $catalogAction = 'photo';
    $catalogFileId = $matches[1];
    include __DIR__ . '/src/controllers/catalog_send.php';
    exit;
}



// ============================================================
// RUTAS ADMIN
// ============================================================

if ($uri === '/admin' || $uri === '/admin/') {
    requireAdmin();
    include __DIR__ . '/templates/admin/dashboard.php';
    exit;
}

if ($uri === '/admin/login') {
    if ($method === 'POST') {
        include __DIR__ . '/src/controllers/admin_login.php';
    } else {
        include __DIR__ . '/templates/admin/login.php';
    }
    exit;
}

if ($uri === '/admin/logout') {
    session_destroy();
    redirect('/admin/login');
}

if ($uri === '/admin/products') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_products.php';
    exit;
}

if ($uri === '/admin/problemas') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_problems.php';
    exit;
}

if ($uri === '/admin/sync') {
    requireAdmin();
    include __DIR__ . '/templates/admin/sync.php';
    exit;
}

if ($uri === '/admin/photo-check') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_photo_check.php';
    exit;
}

// La importación por Excel se quitó: la hoja maestra es la única fuente
if ($uri === '/admin/import') {
    redirect('/admin/sync');
}

if ($uri === '/admin/products/active' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_products_active.php';
    exit;
}

if ($uri === '/admin/product/set-cover' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_set_cover.php';
    exit;
}

// ============================================================
// RUTAS GOOGLE DRIVE / MEDIA
// ============================================================

if ($uri === '/admin/media') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_media.php';
    exit;
}

if ($uri === '/admin/google/auth') {
    requireAdmin();
    require_once __DIR__ . '/src/services/GoogleDriveService.php';
    $drive = new GoogleDriveService();
    header('Location: ' . $drive->getAuthUrl());
    exit;
}

if ($uri === '/admin/google/callback') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_google_callback.php';
    exit;
}

if ($uri === '/admin/media/upload' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_media_upload.php';
    exit;
}

if ($uri === '/admin/media/delete' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_media_delete.php';
    exit;
}

if ($uri === '/admin/media/sync-covers' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_sync_covers.php';
    exit;
}

if ($uri === '/admin/media/sin-codigo.csv') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_media_report.php';
    exit;
}

if ($uri === '/admin/media/visibility' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_media_visibility.php';
    exit;
}

if ($uri === '/admin/folders') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_folders.php';
    exit;
}

if ($uri === '/admin/folders/toggle' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_folder_toggle.php';
    exit;
}


if ($uri === '/admin/cache/clear' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_cache_clear.php';
    exit;
}

if ($uri === '/admin/sync-sheets' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_sync_sheets.php';
    exit;
}

if ($uri === '/api/cron/sync-covers') {
    session_write_close();
    include __DIR__ . '/src/controllers/admin_sync_covers.php';
    exit;
}

if (preg_match('#^/api/media/([^/]+)$#', $uri, $matches)) {
    // API para obtener media de un producto por SKU
    // Soporta búsqueda bidireccional (padre↔hijo) + caché de 5 min
    session_write_close();
    require_once __DIR__ . '/src/services/GoogleDriveService.php';
    $sku = urldecode($matches[1]);
    $folderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $db = getDB();
    $freshRequested = !empty($_GET['fresh']);

    // === Índice local de Drive (sin llamadas a Drive; las portadas las asigna el sync) ===
    require_once __DIR__ . '/src/services/DriveIndex.php';
    if (DriveIndex::isReady($db)) {
        $result = DriveIndex::filesForSku($db, $sku);
        $files = filterHiddenFiles($db, $result['files']);
        header('Cache-Control: public, max-age=60');
        jsonResponse([
            'files' => $files,
            'sku' => $sku,
            'root_sku' => $result['root_sku'],
            'is_variant' => ($sku !== $result['root_sku']),
            'cached' => true,
        ]);
    }

    // Sin índice todavía (antes del primer sync): caché + búsqueda en Drive

    // === CACHÉ: verificar si hay respuesta en caché (TTL 30 min) ===
    if (!$freshRequested) {
        try {
            $cacheStmt = $db->prepare(
                "SELECT files_json, root_sku FROM media_search_cache 
                 WHERE sku = ? AND cached_at > NOW() - INTERVAL 30 MINUTE"
            );
            $cacheStmt->execute([$sku]);
            $cached = $cacheStmt->fetch();
            if ($cached) {
                $files = json_decode($cached['files_json'], true) ?: [];
                $files = filterHiddenFiles($db, $files);
                jsonResponse([
                    'files' => $files,
                    'sku' => $sku,
                    'root_sku' => $cached['root_sku'],
                    'is_variant' => ($sku !== $cached['root_sku']),
                    'cached' => true,
                ]);
            }
        } catch (Exception $e) {
            // Si la tabla no existe aún, ignorar y seguir sin caché
        }
    }

    // === Búsqueda en Drive API (lógica compartida en fetchAndCacheMediaForSku) ===
    $drive = new GoogleDriveService();
    $token = $drive->getValidToken($db);

    if ($token && $folderId) {
        $result = fetchAndCacheMediaForSku($db, $drive, $folderId, $sku);
        $files = filterHiddenFiles($db, $result['files']);

        jsonResponse([
            'files' => $files,
            'sku' => $sku,
            'root_sku' => $result['root_sku'],
            'is_variant' => ($sku !== $result['root_sku']),
            'cached' => false,
        ]);
    } else {
        jsonResponse(['files' => [], 'error' => 'Drive not connected'], 503);
    }
}

/**
 * Descarga un archivo público de Drive sin token (respaldo de /api/download). Solo archivos del índice
 * que no estén ocultos. Original desde drive.usercontent.google.com; si Drive responde una página en vez
 * del archivo y es una imagen, la imagen a tamaño completo de lh3. Devuelve false si no pudo.
 */
function downloadPublicDriveFile(PDO $db, string $fileId): bool
{
    try {
        $stmt = $db->prepare("SELECT name, mime_type FROM drive_index WHERE file_id = ? AND is_folder = 0 AND is_hidden = 0 LIMIT 1");
        $stmt->execute([$fileId]);
        $row = $stmt->fetch();
    } catch (\PDOException $e) {
        $row = false;
    }
    if (!$row) return false;

    $name = (string) $row['name'];
    $mime = (string) ($row['mime_type'] ?: 'application/octet-stream');
    $urls = ["https://drive.usercontent.google.com/download?id={$fileId}&export=download&confirm=t"];
    if (str_starts_with($mime, 'image/')) $urls[] = "https://lh3.googleusercontent.com/d/{$fileId}=s0";

    set_time_limit(0);
    foreach ($urls as $url) {
        $status = 0;
        $type = '';
        $started = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status, &$type) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int) $m[1]; $type = ''; }
                elseif (stripos($line, 'content-type:') === 0) $type = strtolower(trim(substr($line, 13)));
                return strlen($line);
            },
            // Solo empieza a enviar si Drive mandó el archivo (no una página HTML de aviso)
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$status, &$type, &$started, $name, $mime) {
                if (!$started) {
                    if ($status !== 200 || str_starts_with($type, 'text/html')) return 0; // corta y prueba la siguiente
                    $started = true;
                    while (ob_get_level() > 0) ob_end_clean();
                    header('Content-Type: ' . ($type && $type !== 'application/octet-stream' ? $type : $mime));
                    header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
                    header('Cache-Control: private, max-age=0, no-cache');
                }
                echo $data;
                flush();
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
        if ($started) return true;
    }
    return false;
}

// ============================================================
// DOWNLOAD PROXY — descarga directa de archivos de Drive
// GET /api/download/{fileId}
// Hace proxy autenticado con Drive API para que el usuario
// reciba el archivo sin pasar por la página de confirmación.
// ============================================================

if (preg_match('#^/api/download/([a-zA-Z0-9_-]+)$#', $uri, $matches)) {
    checkRateLimit(60); // 60 descargas/min por IP — evita uso como proxy de ancho de banda
    session_write_close();
    require_once __DIR__ . '/src/services/GoogleDriveService.php';

    $fileId = $matches[1];
    $db = getDB();
    $drive = new GoogleDriveService();
    $token = $drive->getValidToken($db);

    if (!$token) {
        // Sin conexión OAuth (token vencido o revocado): los archivos del índice son públicos,
        // así que se descargan por la URL pública de Drive en vez de mostrar un error.
        if (downloadPublicDriveFile($db, $fileId)) exit;
        http_response_code(503);
        echo 'Drive not connected';
        exit;
    }

    // 1) Get file metadata (name + mimeType)
    $meta = $drive->getFileMetadata($fileId, 'name,mimeType,size');

    if (empty($meta['name'])) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    $fileName = $meta['name'];
    $mimeType = $meta['mimeType'] ?? 'application/octet-stream';
    $fileSize = $meta['size'] ?? 0;

    // 2) Stream file content via Drive API alt=media
    $downloadUrl = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media";

    // Optimizar para archivos grandes: sin límite de tiempo y sin buffering
    set_time_limit(0);
    ignore_user_abort(true);
    while (ob_get_level() > 0)
        ob_end_flush();

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
    if ($fileSize) {
        header('Content-Length: ' . $fileSize);
    }
    header('Cache-Control: private, max-age=0, no-cache');
    header('Pragma: no-cache');

    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) {
            echo $data;
            flush();
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
    exit;
}

// ============================================================
// BATCH COVERS API — una sola llamada para N portadas
// POST /api/covers/batch   body: {"skus": ["sku1","sku2",...]}
// Responde: {"covers": {"sku1": {"url":"...","video":false}, ...}}
// ============================================================

// Qué fotos de una lista siguen visibles (catálogos guardados y Mi selección marcan las que ya no están). Solo BD.
if ($uri === '/api/fotos-vigentes' && $method === 'POST') {
    session_write_close();
    checkRateLimit(60);
    $ids = json_decode(file_get_contents('php://input') ?: '[]', true);
    $ids = array_values(array_unique(array_filter(is_array($ids) ? $ids : [], fn($v) => is_string($v) && preg_match('/^[A-Za-z0-9_-]{10,100}$/', $v))));
    $ids = array_slice($ids, 0, 500);
    $ok = [];
    if ($ids) {
        $st = getDB()->prepare("SELECT file_id FROM drive_index WHERE is_folder = 0 AND is_hidden = 0 AND file_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute($ids);
        $ok = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    jsonResponse(['vigentes' => $ok]);
}

if ($uri === '/api/covers/batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkRateLimit(60); // 60 req/min
    session_write_close(); // Unlock session to prevent blocking concurrent searches
    require_once __DIR__ . '/src/services/GoogleDriveService.php';

    $input = json_decode(file_get_contents('php://input'), true);
    $skus = array_slice(array_unique(array_map('strval', (array) ($input['skus'] ?? []))), 0, 50); // max 50

    if (empty($skus)) {
        jsonCachedResponse(['covers' => new \stdClass()], 300);
    }

    $db = getDB();
    $folderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $covers = [];
    $uncachedSkus = [];

    // Con índice local: todas las portadas salen de la BD, sin límite ni Drive.
    // Además se auto-asignan, así la próxima visita ni siquiera llama a este endpoint.
    require_once __DIR__ . '/src/services/DriveIndex.php';
    if (DriveIndex::isReady($db)) {
        // Una sola consulta para saber qué productos aún no tienen portada guardada
        $in = implode(',', array_fill(0, count($skus), '?'));
        $coverStmt = $db->prepare("SELECT sku, cover_image_url FROM products WHERE sku IN ($in)");
        $coverStmt->execute($skus);
        $storedCovers = $coverStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($skus as $sku) {
            $files = filterHiddenFiles($db, DriveIndex::filesForSku($db, $sku)['files']);
            if (array_key_exists($sku, $storedCovers) && empty($storedCovers[$sku])) {
                autoAssignCover($db, $sku, $files);
            }
            $covers[$sku] = extractCoverFromFiles($files);
        }
        jsonCachedResponse(['covers' => $covers], 300);
    }

    // 1) Buscar en caché primero
    try {
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $cacheStmt = $db->prepare(
            "SELECT sku, files_json FROM media_search_cache 
             WHERE sku IN ($placeholders) AND " . freshCoverCacheSql()
        );
        $cacheStmt->execute($skus);
        $cached = $cacheStmt->fetchAll();

        foreach ($cached as $row) {
            $files = json_decode($row['files_json'], true) ?: [];
            $covers[$row['sku']] = extractCoverFromFiles($files);
        }

        $cachedSkus = array_column($cached, 'sku');
        $uncachedSkus = array_diff($skus, $cachedSkus);
    } catch (Exception $e) {
        $uncachedSkus = $skus;
    }

    // 2) Búsqueda en Drive en vivo ACOTADA para SKUs sin caché.
    // La versión sin límite se deshabilitó por rendimiento (bloqueos de 15+ seg
    // cuando faltaban muchas portadas). Ahora se procesan máximo 5 SKUs por
    // petición: cada búsqueda se cachea y auto-asigna portada, así el catálogo
    // se va auto-sanando con cada visita sin bloquear la página.
    if (!empty($uncachedSkus)) {
        $drive = new GoogleDriveService();
        $token = $drive->getValidToken($db);
        if ($token && $folderId) {
            foreach (array_slice(array_values($uncachedSkus), 0, 5) as $liveSku) {
                try {
                    $result = fetchAndCacheMediaForSku($db, $drive, $folderId, $liveSku);
                    if (!empty($result['files'])) {
                        $covers[$liveSku] = extractCoverFromFiles($result['files']);
                    }
                } catch (Exception $e) {
                    // seguir con el resto de SKUs
                }
            }
        }
    }

    // 3) SKUs sin resultado → null
    foreach ($skus as $sku) {
        if (!isset($covers[$sku])) {
            $covers[$sku] = null;
        }
    }

    jsonCachedResponse(['covers' => $covers], 300);
}

// extractCoverFromFiles() ahora definida en src/helpers.php

// ============================================================
// WEBHOOK: Google Sheets → sincronización incremental automática
// POST /api/webhook/sheets   Header: X-Webhook-Secret: VALOR
// ============================================================

if ($uri === '/api/webhook/sheets' && $method === 'POST') {
    session_write_close();
    include __DIR__ . '/src/controllers/webhook_sheets.php';
    exit;
}

// ============================================================
// CRON: Pre-caché Drive — calienta media_search_cache
// GET /api/cron/precache-drive?token=CRON_SECRET
// ============================================================

if ($uri === '/api/cron/precache-drive') {
    // Nombre viejo del cron: hoy es lo mismo que /api/cron/sync-covers (índice de Drive + portadas)
    session_write_close();
    include __DIR__ . '/src/controllers/admin_sync_covers.php';
    exit;
}

// ============================================================
// HEALTH CHECK
// ============================================================

if ($uri === '/health') {
    try {
        $db = getDB();
        $db->query("SELECT 1");
        jsonCachedResponse(['status' => 'ok', 'db' => 'connected'], 10);
    } catch (\Exception $e) {
        jsonResponse(['status' => 'error', 'db' => $e->getMessage()], 500);
    }
}

// ============================================================
// 404
// ============================================================
http_response_code(404);
include __DIR__ . '/templates/404.php';
