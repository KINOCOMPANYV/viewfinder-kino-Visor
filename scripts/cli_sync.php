<?php
/**
 * Sincronización sin botones (la usa scripts/sync_loop.sh al arrancar y cada hora).
 *   php scripts/cli_sync.php hoja       = Admin → "Sincronizar hoja" (hoja maestra → productos, familias, fotos)
 *   php scripts/cli_sync.php portadas   = Admin → "Sincronizar portadas" (índice de Drive, carpetas, enlace y portadas)
 *   php scripts/cli_sync.php carpetas   = solo BD: oculta carpetas "archivado"/"desactivados" y recalcula carpetas ocultas
 *                                         (rápido; corre primero para no esperar a la hoja ni a Drive)
 *   php scripts/cli_sync.php indice     = índice de búsqueda (SearchIndex::rebuild); también lo rehacen relink y carpetas
 *   php scripts/cli_sync.php imagenes   = búsqueda por foto: huella de las portadas nuevas (solo con JINA_API_KEY;
 *                                         sync_loop.sh la corre en su propio ciclo cada 5 min)
 *   php scripts/cli_sync.php pedidos    = copia de Pedidos bodega (Excel + JSON) en Google Drive, una vez al día desde
 *                                         las PEDIDOS_BACKUP_HOUR (22) hora Colombia; `pedidos ya` = ahora mismo
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
if ($step === 'carpetas') {
    require_once __DIR__ . '/../src/services/DriveIndex.php';
    $db = getDB();
    acquireSyncLock($db, 600); // si hay otro sync corriendo, esperar a que termine
    $s = DriveIndex::refreshFolderState($db);
    $link = $s['product_covers_cleared'] ? DriveIndex::relink($db) : null;
    echo '[' . date('Y-m-d H:i:s') . '] Sincronizar carpetas: ' . json_encode($s + ['relinked' => (bool) $link]) . "\n";
    exit(0);
}
if ($step === 'indice') {
    $r = SearchIndex::rebuild(getDB());
    echo '[' . date('Y-m-d H:i:s') . '] Índice de búsqueda: ' . json_encode($r) . "\n";
    exit(0);
}
if ($step === 'imagenes') {
    require_once __DIR__ . '/../src/services/ImageSearch.php';
    if (!ImageSearch::enabled()) {
        exit(0); // sin JINA_API_KEY la búsqueda por foto está apagada
    }
    // Sin saldo o clave mala: no reintentar cada 5 min (cada vuelta descarga fotos en vano); probar 1 vez por hora
    $last = ImageSearch::lastRun();
    if ($last && in_array($last['causa'] ?? '', ['saldo', 'clave'], true) && strtotime($last['at'] ?? '') > time() - 3600) {
        exit(0);
    }
    $r = ImageSearch::indexPending(getDB(), (int) env('IMAGE_INDEX_MAX_SECONDS', '1500'));
    if ($r['pendientes'] > 0 || $r['error']) { // sin nada pendiente no ensucia el log (corre cada 5 min)
        ImageSearch::saveLastRun($r);
        echo '[' . date('Y-m-d H:i:s') . '] Búsqueda por foto: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit(0);
}
if ($step === 'pedidos') {
    require_once __DIR__ . '/../src/services/OrdersBackup.php';
    $now = (bool) (($argv[2] ?? '') === 'ya');
    if (!$now && (int) OrdersBackup::now()->format('G') < (int) env('PEDIDOS_BACKUP_HOUR', '22')) {
        exit(0); // aún no es de noche (hora Colombia)
    }
    $db = getDB();
    if (!$now && !(int) $db->query('SELECT COUNT(*) FROM warehouse_orders')->fetchColumn()) {
        exit(0); // sin pedidos no hay nada que copiar
    }
    $r = OrdersBackup::toDrive($db, $now);
    if (empty($r['skipped'])) {
        echo '[' . date('Y-m-d H:i:s') . '] Copia de pedidos: ' . $r['msg'] . "\n";
    }
    exit($r['ok'] ? 0 : 1);
}
if (!isset($controllers[$step])) {
    fwrite(STDERR, "Uso: php scripts/cli_sync.php hoja|portadas|carpetas|indice|imagenes|pedidos\n");
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
