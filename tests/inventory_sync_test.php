<?php
/** Pruebas de comportamiento sin Google ni datos reales. Ejecutar: php tests/inventory_sync_test.php */
function env(string $key, string $default = ''): string { return $default; }
function getDB(): PDO { return $GLOBALS['inventoryTestDb']; }
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/services/InventorySync.php';

// SQLite permite comprobar transacciones reales localmente. Solo se adapta el operador null-safe de MySQL.
class InventoryTestPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace('<=>', 'IS', $query), $options);
    }
}
function fixture(): PDO
{
    $db = new InventoryTestPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $GLOBALS['inventoryTestDb'] = $db;
    $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT UNIQUE, sys_price NUMERIC, sys_stock INTEGER, sys_at TEXT,
        in_sheet INTEGER DEFAULT 1, stock INTEGER DEFAULT 1, price_wholesale NUMERIC DEFAULT 100, price_suggested NUMERIC DEFAULT 200,
        price_sheet NUMERIC DEFAULT 200, price_manual INTEGER DEFAULT 0)');
    $db->exec("CREATE TABLE app_settings (name TEXT PRIMARY KEY, value TEXT); INSERT INTO app_settings VALUES ('price_multiplier', '2')");
    $db->exec('CREATE TABLE inventory_imports (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT, source_hash TEXT, user_id INTEGER,
        user_name TEXT, status TEXT, sheet_status TEXT, applied_count INTEGER DEFAULT 0, result_json TEXT, sheet_columns_json TEXT,
        error_message TEXT DEFAULT "", created_at TEXT, completed_at TEXT)');
    $db->exec("INSERT INTO products (id, sku, sys_price, sys_stock) VALUES (1, 'M:8291-1', 100, 1), (2, 'NO:610-1', 100, 1), (3, 'NO610-1', 100, 1)");
    return $db;
}
function item(string $code, int $stock, ?float $price = 200): array
{
    return ['line' => 2, 'code' => $code, 'desc' => 'Reloj de prueba', 'stock' => $stock, 'price' => $price];
}
function sheet(): array
{
    return ['source' => 'Hoja de prueba', 'rows' => array_map(fn($s) => ['row' => 2, 'sku' => $s, 'name' => 'Reloj de prueba', 'stock' => 1, 'price' => 100.0], ['M:8291-1', 'NO:610-1', 'NO610-1'])];
}
function parsed(array $items): array { return ['items' => $items, 'columns' => ['code' => 'Artículo', 'stock' => 'Existencias', 'price' => 'Precio']]; }
$fails = 0; $checks = 0;
function check(bool $condition, string $message): void
{
    global $fails, $checks; $checks++;
    if (!$condition) $fails++;
    echo ($condition ? 'OK ' : 'FALLA ') . $message . "\n";
}
function expectError(callable $run, string $message): void
{
    try { $run(); check(false, $message); } catch (Throwable $e) { check(true, $message); }
}

$db = fixture();
$sync = new InventorySync($db, fn() => sheet(), fn($db, $columns) => ['celdas' => count($columns['STOCK']) + count($columns['PRICE'])]);
$id = $sync->import(parsed([item('M8291-1', 7, 300)]), 'SYS.xlsx', str_repeat('a', 64), true, true, ['id' => 4, 'name' => 'Operador']);
$p = $db->query('SELECT * FROM products WHERE id = 1')->fetch();
check((int) $p['sys_stock'] === 7 && (float) $p['sys_price'] === 300.0 && (float) $p['price_suggested'] === 600.0, 'Una carga publica stock, precio SYS y precio público juntos');
check($sync->load($id)['sheet_status'] === 'ok' && count($sync->history()) === 1, 'Resultado e historial quedan persistidos');
check((int) $db->query('SELECT sys_stock FROM products WHERE id = 2')->fetchColumn() === 1, 'Una referencia ausente conserva su stock');

$db = fixture();
$db->exec('UPDATE products SET price_manual = 1, price_suggested = 999 WHERE id = 1');
InventoryImport::applyToVisor($db, [item('M8291-1', 5, null)], true, 2);
$p = $db->query('SELECT * FROM products WHERE id = 1')->fetch();
check((float) $p['sys_price'] === 100.0 && (float) $p['price_suggested'] === 999.0, 'Precio ausente no borra SYS ni pisa una edición manual');
InventoryImport::applyToVisor($db, [item('NO:610-1', 8), item('NO610-1', 12)], true, 2);
check((int) $db->query('SELECT sys_stock FROM products WHERE id = 2')->fetchColumn() === 8 && (int) $db->query('SELECT sys_stock FROM products WHERE id = 3')->fetchColumn() === 12, 'Los códigos exactos parecidos se mantienen separados');

$match = InventoryImport::matchItems([item('NO-610-1', 99)], [['sku' => 'NO:610-1'], ['sku' => 'NO610-1']]);
check(!$match['assigned'] && count($match['ambiguous']) === 1, 'Una coincidencia normalizada ambigua no se adivina');
$report = InventoryImport::compare([item('NO-610-1', 99)], sheet()['rows']);
check($report['summary']['conflictos'] === 1 && !InventorySync::sheetColumns($report)['STOCK'], 'Una referencia ambigua tampoco se envía a la hoja');
$before = $db->query('SELECT sys_stock FROM products WHERE id = 1')->fetchColumn();
InventoryImport::applyToVisor($db, [item('M8291-1', 2, 100), item('M8291-1', 3, 500)], true, 2);
check($db->query('SELECT sys_stock FROM products WHERE id = 1')->fetchColumn() === $before, 'Precios conflictivos conservan stock y precio anteriores');
InventoryImport::applyToVisor($db, [item('M8291-1', 2, 100), item('M8291-1', 3, 100)], true, 2);
check((int) $db->query('SELECT sys_stock FROM products WHERE id = 1')->fetchColumn() === 5, 'Filas repetidas compatibles suman sus existencias');
InventoryImport::applyToVisor($db, [item('M8291-1', -3, 100)], true, 2);
check((int) $db->query('SELECT sys_stock FROM products WHERE id = 1')->fetchColumn() === 0, 'La opción negativos a cero se respeta');

$db = fixture(); $calls = 0; $reads = 0; $lastColumns = [];
$sync = new InventorySync($db, function () use (&$reads) { $reads++; return sheet(); }, function ($db, $columns) use (&$calls, &$lastColumns) {
    $calls++; $lastColumns = $columns;
    if ($calls === 1) throw new RuntimeException('Google no disponible');
    return ['celdas' => 2];
});
$id = $sync->import(parsed([item('M8291-1', 6, 100)]), 'SYS.csv', str_repeat('b', 64), true, true, ['username' => 'admin']);
check($sync->load($id)['status'] === 'partial' && (int) $db->query('SELECT sys_stock FROM products WHERE id = 1')->fetchColumn() === 6, 'Si Google falla, KINO actualizado y envío pendiente sobreviven');
$sync->retrySheet($id);
check($sync->load($id)['sheet_status'] === 'ok' && count($sync->history()) === 1 && $reads === 1, 'Reintentar envía la carga persistida sin releer archivo ni duplicar historial');
check(isset($lastColumns['PRICE']['M:8291-1']) && !isset($lastColumns['STOCK']['NO610-1']), 'El reintento usa todos los valores de SYS y no toca referencias ausentes');
$sync->import(parsed([item('M8291-1', 15)]), 'SYS_nuevo.csv', str_repeat('c', 64), true, false, ['username' => 'admin']);
expectError(fn() => $sync->retrySheet($id), 'Una carga antigua no sobrescribe la Hoja master tras otra actualización');

$db = fixture();
$db->exec("CREATE TRIGGER reject_price BEFORE UPDATE OF price_suggested ON products BEGIN SELECT RAISE(ABORT, 'fallo controlado'); END");
$sync = new InventorySync($db, fn() => sheet(), fn() => ['celdas' => 0]);
expectError(fn() => $sync->import(parsed([item('M8291-1', 30, 400)]), 'SYS.csv', str_repeat('d', 64), true, false, ['username' => 'admin']), 'Un fallo al recalcular precios rechaza la carga completa');
$p = $db->query('SELECT * FROM products WHERE id = 1')->fetch();
check((int) $p['sys_stock'] === 1 && (float) $p['sys_price'] === 100.0 && (float) $p['price_suggested'] === 200.0, 'Rollback real: no se publica stock sin su precio');
check($sync->load()['status'] === 'failed' && $sync->latestAppliedId() === 0, 'El fallo queda registrado sin sustituir el último inventario válido');

$parsed = InventoryImport::parseItems([['Artículo', 'Existencias', 'Precio'], ['M8291-1', '7', '$ 40.000']]);
check($parsed['items'][0]['stock'] === 7 && $parsed['items'][0]['price'] === 40000.0, 'El formato habitual de SYS se lee correctamente');
expectError(fn() => InventoryImport::parseItems([['Código', 'Stock'], ['M8291-1', 'sin dato']]), 'Una existencia ilegible no se convierte silenciosamente en cero');
expectError(fn() => InventoryImport::parseItems([['Código', 'Stock', 'Precio'], ['M8291-1', '3', '-100']]), 'Se rechaza un precio negativo');

$new = InventoryImport::compare([item('NUEVO-1', 10, 100), item('NUEVO-1', -3, 100)], sheet()['rows']);
check(count($new['crear']) === 1 && $new['crear'][0]['stock'] === 7, 'Referencias nuevas suman también ajustes negativos antes de crearse');
$new = InventoryImport::compare([item('NUEVO-1', 10, 100), item('NUEVO-1', 3, 500)], sheet()['rows']);
check(!$new['crear'] && $new['summary']['conflictos'] > 0, 'Una referencia nueva con precios contradictorios no se crea con un precio arbitrario');
require_once __DIR__ . '/../src/services/XlsxWriter.php';
$file = tempnam(sys_get_temp_dir(), 'kino_sys_test_');
try {
    file_put_contents($file, XlsxWriter::build(['SYS' => ['rows' => [['Artículo', 'Descripción', 'Existencias', 'Precio'], ['M8291-1', 'Reloj', 7, 45000]], 'widths' => [20, 20, 15, 15]]]));
    $xlsx = InventoryImport::parseItems(InventoryImport::readFile($file, 'SYS.xlsx'));
    check($xlsx['items'][0]['code'] === 'M8291-1' && $xlsx['items'][0]['stock'] === 7 && $xlsx['items'][0]['price'] === 45000.0, 'Importación real de un XLSX generado: código, existencias y precio correctos');
} finally { unlink($file); }

echo "$checks comprobaciones; $fails fallos.\n";
exit($fails ? 1 : 0);
