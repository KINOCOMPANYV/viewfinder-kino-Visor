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
    echo json_encode([
        'env_method' => [
            'getenv_DB_HOST' => getenv('DB_HOST') ?: '(empty)',
            'getenv_MYSQLHOST' => getenv('MYSQLHOST') ?: '(empty)',
            'getenv_MYSQL_HOST' => getenv('MYSQL_HOST') ?: '(empty)',
            'getenv_MYSQL_URL' => getenv('MYSQL_URL') ? '(set)' : '(empty)',
            'getenv_DATABASE_URL' => getenv('DATABASE_URL') ? '(set)' : '(empty)',
            '$_ENV_DB_HOST' => $_ENV['DB_HOST'] ?? '(empty)',
            '$_ENV_MYSQLHOST' => $_ENV['MYSQLHOST'] ?? '(empty)',
            '$_SERVER_DB_HOST' => $_SERVER['DB_HOST'] ?? '(empty)',
            '$_SERVER_MYSQLHOST' => $_SERVER['MYSQLHOST'] ?? '(empty)',
        ],
        'resolved' => [
            'host' => env('DB_HOST', env('MYSQLHOST', env('MYSQL_HOST', '127.0.0.1'))),
            'port' => env('DB_PORT', env('MYSQLPORT', env('MYSQL_PORT', '3306'))),
            'database' => env('DB_DATABASE', env('MYSQLDATABASE', env('MYSQL_DATABASE', 'viewfinder_kino'))),
            'user' => env('DB_USERNAME', env('MYSQLUSER', env('MYSQL_USER', 'root'))),
        ]
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
