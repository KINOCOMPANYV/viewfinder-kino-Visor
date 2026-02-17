<?php
/**
 * Viewfinder Kino Visor — Router principal.
 * Todas las peticiones pasan por aquí gracias a .htaccess
 */
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/helpers.php';

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

// Auto-migrar en primer uso
try {
    $db = getDB();
    $db->query("SELECT 1 FROM products LIMIT 1");
} catch (\Exception $e) {
    // Si la tabla no existe, ejecutar migraciones
    include __DIR__ . '/migrate.php';
    $db = getDB();
}

// Ruta ya obtenida arriba

// ============================================================
// RUTAS PÚBLICAS (Portal del Distribuidor)
// ============================================================

if ($uri === '/' || $uri === '') {
    // Landing page con buscador
    include __DIR__ . '/templates/client/landing.php';
    exit;
}

if ($uri === '/buscar' || $uri === '/search') {
    // Búsqueda de productos
    include __DIR__ . '/src/controllers/search.php';
    exit;
}

if (preg_match('#^/producto/([A-Za-z0-9\-_]+)$#', $uri, $matches)) {
    // Ficha del producto por SKU
    $_GET['sku'] = $matches[1];
    include __DIR__ . '/src/controllers/product.php';
    exit;
}

if ($uri === '/api/search') {
    // API de búsqueda (para autocomplete AJAX)
    include __DIR__ . '/src/controllers/api_search.php';
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

if (preg_match('#^/api/media/([A-Za-z0-9\-_]+)$#', $uri, $matches)) {
    // API para obtener media de un producto por SKU
    require_once __DIR__ . '/src/services/GoogleDriveService.php';
    $sku = $matches[1];
    $folderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $drive = new GoogleDriveService();
    $token = $drive->getValidToken(getDB());
    if ($token && $folderId) {
        $files = $drive->findBySku($folderId, $sku);
        jsonResponse(['files' => $files]);
    } else {
        jsonResponse(['files' => [], 'error' => 'Drive not connected'], 503);
    }
}

// ============================================================
// HEALTH CHECK
// ============================================================

if ($uri === '/health') {
    try {
        $db->query("SELECT 1");
        jsonResponse(['status' => 'ok', 'db' => 'connected']);
    } catch (\Exception $e) {
        jsonResponse(['status' => 'error', 'db' => $e->getMessage()], 500);
    }
}

// ============================================================
// 404
// ============================================================
http_response_code(404);
include __DIR__ . '/templates/404.php';
