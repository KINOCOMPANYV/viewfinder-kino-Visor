<?php
/** Render real de la vista con datos ficticios. Sin conexión a MySQL/Google. */
define('BASE_DIR', dirname(__DIR__));
define('APP_VERSION', 'inventory-test');
define('ADMIN_USER', 'prueba');
function env(string $key, string $default = ''): string { return $default; }
function getDB(): PDO
{
    static $db;
    return $db ??= new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
require BASE_DIR . '/src/helpers.php';
require BASE_DIR . '/src/services/InventoryImport.php';
$_SESSION = ['admin_logged_in' => true, 'csrf_token' => str_repeat('a', 64)];
function renderInventory(bool $older = false, bool $empty = false): string
{
    $ready = true; $error = $flash = null; $legacy = false;
    $catalog = ['total' => 3629, 'con_stock' => 2486, 'con_sys' => 3502];
    $syncDates = ['drive' => ['last_ok_at' => '2026-11-28 14:00:00']];
    $latestId = 42;
    $latest = ['id' => 42, 'sheet_status' => 'failed', 'created_at' => '2026-11-28 14:00:00'];
    $summary = ['articulos' => 3610, 'conflictos' => 2];
    $shown = [];
    foreach (range(1, 5) as $i) $shown[] = ['tipo' => 'stock_diferente', 'codigo_inventario' => 'M8291-' . $i, 'codigo_hoja' => 'M:8291-' . $i,
        'descripcion' => 'Reloj de prueba ' . $i, 'stock_inventario' => 20 + $i, 'stock_hoja' => 10, 'precio_inventario' => 45000, 'nota' => 'Existencias actualizadas desde SYS.'];
    $result = ['summary' => $summary, 'meta' => ['archivo' => 'INVENTARIO GENERAL.xlsx', 'aplicado' => 3502, 'filas_no_aplicadas' => 108,
        'hoja' => 'KINO actualizado. Pendiente Hoja master: Google no estuvo disponible. Puedes reintentar el envío.'],
        'crear' => [['code' => 'NUEVO-1']], 'report' => $shown];
    $batch = ['id' => $older ? 41 : 42, 'created_at' => '2026-11-28 14:00:00', 'user_name' => 'Administración', 'status' => 'partial', 'sheet_status' => 'failed'];
    $history = [$batch + ['filename' => 'INVENTARIO GENERAL.xlsx', 'applied_count' => 3502]];
    $counts = ['stock_diferente' => 25, 'codigo_ambiguo' => 2]; $type = 'stock_diferente'; $query = ''; $page = $pages = 1; $filtered = $shown;
    $number = fn($n) => number_format((float) ($n ?? 0), 0, ',', '.');
    $date = fn($v) => $v ? '28/11/2026 · 9:00 a. m.' : 'Sin actualizar';
    $link = fn($o = []) => '/admin/inventario?' . http_build_query($o + ['carga' => $batch['id'], 'tipo' => $type]);
    if ($empty) { $result = $batch = $latest = null; $latestId = 0; $history = $counts = []; }
    ob_start();
    include BASE_DIR . '/templates/admin/inventory.php';
    return ob_get_clean();
}
function xpath(string $html): DOMXPath
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    return new DOMXPath($dom);
}
$html = renderInventory(); $xpath = xpath($html); $fails = 0; $checks = 0;
function viewCheck(bool $ok, string $message): void
{
    global $fails, $checks; $checks++; if (!$ok) $fails++;
    echo ($ok ? 'OK ' : 'FALLA ') . $message . "\n";
}
viewCheck($xpath->query('//form[@enctype="multipart/form-data"]//input[@name="csrf_token" and string-length(@value)=64]')->length === 1, 'La carga tiene protección CSRF y envío de archivo');
viewCheck($xpath->query('//button[contains(.,"Reintentar envío")]')->length === 1, 'El envío fallido de la última carga ofrece recuperación');
viewCheck(xpath(renderInventory(true))->query('//button[contains(.,"Reintentar envío")]')->length === 0, 'Una carga antigua no ofrece reenviar información obsoleta');
viewCheck($xpath->query('//link[contains(@href,"/assets/css/inventory.css?v=")]')->length === 1 && !str_contains($html, '?v=/assets/'), 'Los recursos usan URLs y versiones correctas');
viewCheck($xpath->query('//table[contains(@class,"inv-table")]//th[@scope="col"]')->length === 6 && $xpath->query('//td[@data-label]')->length === 30, 'La tabla conserva encabezados accesibles y etiquetas para celular');
viewCheck(str_contains(renderInventory(false, true), 'Tu primera carga empieza aquí.'), 'El estado inicial permite entender cómo empezar');
viewCheck($xpath->query('//input[@id="invFile"][@aria-describedby="invFileHelp"]')->length === 1, 'El selector de archivo tiene instrucciones asociadas');
if (!empty($argv[1])) file_put_contents($argv[1], $html);
echo "$checks comprobaciones de vista; $fails fallos.\n";
exit($fails ? 1 : 0);
