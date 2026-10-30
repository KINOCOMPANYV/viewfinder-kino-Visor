<?php
/**
 * Sincronización sin botones (la usa scripts/sync_loop.sh al arrancar y cada hora).
 *   php scripts/cli_sync.php hoja       = Admin → "Sincronizar hoja" (hoja maestra → productos, familias, fotos)
 *   php scripts/cli_sync.php portadas   = Admin → "Sincronizar portadas" (índice de Drive, carpetas, enlace y portadas)
 * Ejecuta el MISMO controlador que el botón del admin (con su seguro de "no ocultar medio catálogo").
 * Un proceso por paso: los controladores terminan con exit al responder.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../src/helpers.php';

$controllers = [
    'hoja' => __DIR__ . '/../src/controllers/admin_sync_sheets.php',
    'portadas' => __DIR__ . '/../src/controllers/admin_sync_covers.php',
];
$step = $argv[1] ?? '';
if (!isset($controllers[$step])) {
    fwrite(STDERR, "Uso: php scripts/cli_sync.php hoja|portadas\n");
    exit(1);
}

// Los controladores piden el token CSRF del admin: en la línea de comandos se da por bueno
$_SESSION = ['csrf_token' => bin2hex(random_bytes(16))];
$_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];
$_SERVER['REQUEST_METHOD'] = 'POST';

// Todo en un buffer (así header() no protesta) y una línea de log con fecha al terminar
$prefix = '[' . date('Y-m-d H:i:s') . "] Sincronizar {$step}: ";
ob_start(fn(string $out) => $out === '' ? '' : $prefix . $out . "\n");
include $controllers[$step];
