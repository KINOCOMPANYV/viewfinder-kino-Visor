<?php
/**
 * Viewfinder Kino Visor — Router principal.
 * Todas las peticiones pasan por aquí gracias a .htaccess
 * @deploy 2026-02-18
 */
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/helpers.php';

// ============================================================
// CORS — permitir llamadas desde apps externas (ej. Poedagar SPA)
// ============================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Obtener la ruta antes de auto-migrar
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Health debug — funciona SIN conexión a DB
if ($uri === '/health/debug') {
    header('Content-Type: application/json');
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
    $_GET['sku'] = $matches[1];
    include __DIR__ . '/src/controllers/product.php';
    exit;
}

if ($uri === '/api/search') {
    // API de búsqueda (para autocomplete AJAX)
    session_write_close();
    include __DIR__ . '/src/controllers/api_search.php';
    exit;
}

if ($uri === '/api/batch-search' && $method === 'POST') {
    // API de búsqueda por lote
    session_write_close();
    include __DIR__ . '/src/controllers/api_batch_search.php';
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

if ($uri === '/admin/import') {
    requireAdmin();
    if ($method === 'POST') {
        include __DIR__ . '/src/controllers/admin_import.php';
    } else {
        include __DIR__ . '/templates/admin/import.php';
    }
    exit;
}

if ($uri === '/admin/product/delete' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_delete_product.php';
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

if ($uri === '/admin/media/visibility' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_media_visibility.php';
    exit;
}

if ($uri === '/admin/albums') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_albums.php';
    exit;
}

if ($uri === '/admin/product/update' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_product_update.php';
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

if (preg_match('#^/api/media/([^/]+)$#', $uri, $matches)) {
    // API para obtener media de un producto por SKU
    // Soporta búsqueda bidireccional (padre↔hijo) + caché de 5 min
    session_write_close();
    require_once __DIR__ . '/src/services/GoogleDriveService.php';
    $sku = urldecode($matches[1]);
    $rootSku = extractRootSku($sku);
    $folderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $db = getDB();
    $freshRequested = !empty($_GET['fresh']);

    // Helper: filter out files hidden via drive_cache.visible_publico
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

    // === CACHÉ: verificar si hay respuesta en caché (TTL 5 min) ===
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

    // === Búsqueda en Drive API ===
    $drive = new GoogleDriveService();
    $token = $drive->getValidToken($db);

    if ($token && $folderId) {
        // 1) Buscar por el SKU raíz (trae padre + todos los hijos)
        $files = $drive->findBySku($folderId, $rootSku);

        // 2) Si el input es diferente al root (es un hijo), también buscar específicamente por el input
        if ($sku !== $rootSku) {
            $childFiles = $drive->findBySku($folderId, $sku);
            $existingIds = array_column($files, 'id');
            foreach ($childFiles as $cf) {
                if (!in_array($cf['id'], $existingIds)) {
                    $files[] = $cf;
                }
            }
        }

        // Hacer públicos todos los archivos en PARALELO (evita bloqueo)
        $drive->makePublicBatch(array_column($files, 'id'));

        // === CACHÉ: guardar resultado SOLO si hay archivos ===
        // No cachear resultados vacíos para evitar envenenamiento del caché
        if (!empty($files)) {
            try {
                $saveStmt = $db->prepare(
                    "INSERT INTO media_search_cache (sku, root_sku, files_json, cached_at)
                     VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE root_sku = VALUES(root_sku), files_json = VALUES(files_json), cached_at = NOW()"
                );
                $saveStmt->execute([$sku, $rootSku, json_encode($files)]);
            } catch (Exception $e) {
                // Si la tabla no existe aún, ignorar
            }
        }

        // Filter hidden files before returning
        $files = filterHiddenFiles($db, $files);

        jsonResponse([
            'files' => $files,
            'sku' => $sku,
            'root_sku' => $rootSku,
            'is_variant' => ($sku !== $rootSku),
            'cached' => false,
        ]);
    } else {
        jsonResponse(['files' => [], 'error' => 'Drive not connected'], 503);
    }
}

// ============================================================
// DOWNLOAD PROXY — descarga directa de archivos de Drive
// GET /api/download/{fileId}
// Hace proxy autenticado con Drive API para que el usuario
// reciba el archivo sin pasar por la página de confirmación.
// ============================================================

if (preg_match('#^/api/download/([a-zA-Z0-9_-]+)$#', $uri, $matches)) {
    session_write_close();
    require_once __DIR__ . '/src/services/GoogleDriveService.php';

    $fileId = $matches[1];
    $db = getDB();
    $drive = new GoogleDriveService();
    $token = $drive->getValidToken($db);

    if (!$token) {
        http_response_code(503);
        echo 'Drive not connected';
        exit;
    }

    // 1) Get file metadata (name + mimeType)
    $metaUrl = "https://www.googleapis.com/drive/v3/files/{$fileId}?fields=name,mimeType,size";
    $ch = curl_init($metaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
    ]);
    $metaJson = curl_exec($ch);
    curl_close($ch);
    $meta = json_decode($metaJson, true);

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

if ($uri === '/api/covers/batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    session_write_close(); // Unlock session to prevent blocking concurrent searches
    require_once __DIR__ . '/src/services/GoogleDriveService.php';

    $input = json_decode(file_get_contents('php://input'), true);
    $skus = array_slice(array_unique($input['skus'] ?? []), 0, 50); // max 50

    if (empty($skus)) {
        jsonCachedResponse(['covers' => new \stdClass()], 300);
    }

    $db = getDB();
    $folderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $covers = [];
    $uncachedSkus = [];

    // 1) Buscar en caché primero
    try {
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $cacheStmt = $db->prepare(
            "SELECT sku, files_json FROM media_search_cache 
             WHERE sku IN ($placeholders) AND cached_at > NOW() - INTERVAL 10 MINUTE"
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

    // 2) Búsqueda en Drive en vivo DESHABILITADA POR RENDIMIENTO.
    // Hacer búsquedas de Drive en vivo desde la página pública era demasiado lento (1-2s por query)
    // originando bloqueos de 15+ segundos cuando faltaban muchas fotos.
    // La asignación de portadas debe hacerse en batch desde el panel de Admin -> Media -> Auto-asignar.

    // 3) SKUs sin resultado → null
    foreach ($skus as $sku) {
        if (!isset($covers[$sku])) {
            $covers[$sku] = null;
        }
    }

    jsonCachedResponse(['covers' => $covers], 300);
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


// ============================================================
// HEALTH CHECK
// ============================================================

if ($uri === '/health') {
    try {
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
