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

// Auto-migrar: ejecutar migraciones pendientes en cada deploy
try {
    $db = getDB();
    // Verificar si hay migraciones pendientes
    $db->exec("CREATE TABLE IF NOT EXISTS migrations (id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL, executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $executed = $db->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(__DIR__ . '/migrations/*.sql');
    sort($files);
    foreach ($files as $file) {
        $filename = basename($file);
        if ($filename === '000_create_migrations.sql')
            continue;
        if (in_array($filename, $executed))
            continue;
        $sql = file_get_contents($file);
        $db->exec($sql);
        $stmt = $db->prepare("INSERT INTO migrations (filename) VALUES (?)");
        $stmt->execute([$filename]);
    }
} catch (\Exception $e) {
    // Silenciar errores de migración en producción
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

if (preg_match('#^/producto/([^/]+)$#', $uri, $matches)) {
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

if ($uri === '/admin/product/update' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_product_update.php';
    exit;
}

if ($uri === '/admin/sync-sheets' && $method === 'POST') {
    requireAdmin();
    include __DIR__ . '/src/controllers/admin_sync_sheets.php';
    exit;
}

if (preg_match('#^/api/media/([^/]+)$#', $uri, $matches)) {
    // API para obtener media de un producto por SKU
    // Soporta búsqueda bidireccional (padre↔hijo)
    require_once __DIR__ . '/src/services/GoogleDriveService.php';
    $sku = urldecode($matches[1]);
    $rootSku = extractRootSku($sku);
    $folderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $drive = new GoogleDriveService();
    $token = $drive->getValidToken(getDB());

    if ($token && $folderId) {
        // 1) Buscar por el SKU raíz (trae padre + todos los hijos)
        $files = $drive->findBySku($folderId, $rootSku);

        // 2) Si el input es diferente al root (es un hijo), también buscar específicamente por el input
        //    para asegurar que aparezca aunque el nombre no empiece por el root
        if ($sku !== $rootSku) {
            $childFiles = $drive->findBySku($folderId, $sku);
            $existingIds = array_column($files, 'id');
            foreach ($childFiles as $cf) {
                if (!in_array($cf['id'], $existingIds)) {
                    $files[] = $cf;
                }
            }
        }

        // Hacer públicos todos los archivos para que las URLs lh3 funcionen
        foreach ($files as $f) {
            $drive->makePublic($f['id']);
        }

        jsonResponse([
            'files' => $files,
            'sku' => $sku,
            'root_sku' => $rootSku,
            'is_variant' => ($sku !== $rootSku),
        ]);
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
