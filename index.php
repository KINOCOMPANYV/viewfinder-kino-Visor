<?php
/**
 * Viewfinder Kino Visor — Router principal.
 * Todas las peticiones pasan por aquí gracias a .htaccess
 * @deploy 2026-02-18
 */
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
    $key = 'rl_' . md5($ip);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 1, 'reset' => time() + 60];
        return;
    }
    
    if (time() > $_SESSION[$key]['reset']) {
        $_SESSION[$key] = ['count' => 1, 'reset' => time() + 60];
        return;
    }
    
    $_SESSION[$key]['count']++;
    
    if ($_SESSION[$key]['count'] > $maxPerMinute) {
        http_response_code(429);
        header('Retry-After: ' . ($_SESSION[$key]['reset'] - time()));
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Demasiadas peticiones. Intenta en un momento.']);
        exit;
    }
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

if ($uri === '/api/search') {
    // API de búsqueda (para autocomplete AJAX)
    checkRateLimit(120); // 120 req/min — autocomplete es frecuente
    session_write_close();
    include __DIR__ . '/src/controllers/api_search.php';
    exit;
}

if ($uri === '/api/batch-search' && $method === 'POST') {
    // API de búsqueda por lote
    checkRateLimit(30); // 30 req/min — operación pesada
    session_write_close();
    include __DIR__ . '/src/controllers/api_batch_search.php';
    exit;
}

// ============================================================
// API: Drive Folders — lista carpetas para el visor de cliente
// ============================================================

if ($uri === '/api/drive-folders') {
    session_write_close();
    header('Content-Type: application/json');
    
    $db = getDB();
    $folders = [];
    
    // 1) Intentar leer de la tabla albums
    try {
        $stmt = $db->query("SELECT drive_id AS id, name, icon_url FROM albums WHERE is_active = 1 ORDER BY order_priority DESC, name ASC");
        $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        // tabla no existe aún
    }
    
    // 2) Si no hay álbumes en DB, intentar leer de Drive API
    if (empty($folders)) {
        $rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
        if ($rootFolderId) {
            require_once __DIR__ . '/src/services/GoogleDriveService.php';
            $drive = new GoogleDriveService();
            $token = $drive->getValidToken($db);
            
            if ($token) {
                $result = $drive->listFiles($rootFolderId);
                foreach ($result['files'] as $item) {
                    if (($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                        $folders[] = [
                            'id' => $item['id'],
                            'name' => $item['name'],
                            'icon_url' => null,
                        ];
                    }
                }
            }
        }
    }
    
    jsonCachedResponse(['folders' => $folders], 300);
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

    // filterHiddenFiles() ahora definida en src/helpers.php

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

        // Hacer públicos todos los archivos en PARALELO
        // (este bloque solo se ejecuta si NO hubo cache hit, así que son archivos frescos)
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

            // === AUTO-CACHE PORTADA ===
            // Si el producto no tiene cover_image_url, guardar la primera imagen automáticamente
            try {
                $coverCheck = $db->prepare("SELECT id, cover_image_url FROM products WHERE sku = ? LIMIT 1");
                $coverCheck->execute([$sku]);
                $prodRow = $coverCheck->fetch();
                if ($prodRow && empty($prodRow['cover_image_url'])) {
                    // Buscar la primera imagen
                    $firstImage = null;
                    foreach ($files as $f) {
                        if (str_starts_with($f['mimeType'] ?? '', 'image/')) {
                            $firstImage = $f;
                            break;
                        }
                    }
                    if ($firstImage) {
                        $coverUrl = "https://lh3.googleusercontent.com/d/{$firstImage['id']}=s400";
                        $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?")
                           ->execute([$coverUrl, $prodRow['id']]);
                    }
                }
            } catch (Exception $e) {
                // Ignorar errores de auto-cache
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
    checkRateLimit(60); // 60 req/min
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

// extractCoverFromFiles() ahora definida en src/helpers.php


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
