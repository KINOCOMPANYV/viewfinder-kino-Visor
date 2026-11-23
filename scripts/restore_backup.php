<?php
/**
 * Prueba de restauración del respaldo de MySQL (nunca toca la base de producción).
 *   php scripts/restore_backup.php archivo.sql.gz
 * La base destino se da con RESTORE_DB_HOST, RESTORE_DB_PORT, RESTORE_DB_NAME, RESTORE_DB_USER, RESTORE_DB_PASSWORD.
 * Se niega si destino = la base que usa el visor (MYSQL_URL / DB_*). Al final compara filas por tabla con el respaldo.
 * Guía paso a paso: docs/RESPALDOS.md
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../src/services/OrdersBackup.php';
require_once __DIR__ . '/../src/services/DbBackup.php';

$file = $argv[1] ?? '';
$name = env('RESTORE_DB_NAME');
if (!is_file($file) || $name === '') {
    fwrite(STDERR, "Uso: RESTORE_DB_NAME=... [RESTORE_DB_HOST/PORT/USER/PASSWORD] php scripts/restore_backup.php archivo.sql.gz\n");
    exit(2);
}
$host = env('RESTORE_DB_HOST', '127.0.0.1');
$port = env('RESTORE_DB_PORT', '3306');
// Seguro: comparar con la base de producción (si hay conexión configurada)
try {
    $prod = getDB();
    $pname = (string) $prod->query('SELECT DATABASE()')->fetchColumn();
    $phost = (string) $prod->query('SELECT @@hostname')->fetchColumn();
    $pport = (string) $prod->query('SELECT @@port')->fetchColumn();
    if ($pname === $name && ($phost === $host || $host === '127.0.0.1' || $host === 'localhost') && $pport === $port) {
        fwrite(STDERR, "❌ Esa es la base que usa el visor ({$pname}). Usa otra base para la prueba.\n");
        exit(2);
    }
} catch (\Throwable $e) { /* sin base configurada: nada con qué chocar */ }

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", env('RESTORE_DB_USER', 'root'), env('RESTORE_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]);
$t0 = microtime(true);
$r = DbBackup::restore($pdo, $file);
printf("%s: %d tablas, %d sentencias en %.1f s\n", $r['ok'] ? '✅ Restauración correcta' : '❌ Restauración con diferencias', $r['tables'], $r['statements'], microtime(true) - $t0);
foreach ($r['mismatches'] as $t => $why) {
    echo "  - {$t}: {$why}\n";
}
exit($r['ok'] ? 0 : 1);
