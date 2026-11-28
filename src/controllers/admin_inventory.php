<?php
/**
 * Inventario SYS: una carga publica stock y precios en KINO y registra su envío a la Hoja master.
 * GET solo lee MySQL. El archivo anterior en storage se conserva como consulta histórica.
 */
require_once __DIR__ . '/../services/InventorySync.php';
require_once __DIR__ . '/../services/XlsxWriter.php';

header('Cache-Control: no-store');
$db = getDB();
$sync = new InventorySync($db);
$ready = $sync->ready();
$error = null;
$flash = $_SESSION['inventory_flash'] ?? null;
unset($_SESSION['inventory_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (empty($_SESSION['csrf_token']) || !verifyCsrf()) throw new RuntimeException('La sesión expiró. Recarga la página.');
        if (!$ready) throw new RuntimeException('Falta aplicar la migración 047. No se cambió el inventario.');
        $action = (string) ($_POST['accion'] ?? 'importar');
        $id = (int) ($_POST['carga'] ?? 0);
        if ($action === 'reintentar') {
            $sync->retrySheet($id);
        } elseif ($action === 'crear') {
            $sync->locked(function () use ($sync, $db, $id) {
                $row = $sync->load($id);
                if (!$row || $id !== $sync->latestAppliedId() || empty($row['result']['crear'])) throw new RuntimeException('Abre la última carga con códigos pendientes de crear.');
                $result = $row['result'];
                $newItems = $result['crear'];
                $created = SheetWriter::appendProducts($db, $newItems);
                $result['crear'] = [];
                $result['meta']['crear_nota'] = $created['creados'] . ' códigos agregados a la Hoja master.';
                $sync->rememberResult($id, $result);
                try {
                    require_once __DIR__ . '/../services/DriveIndex.php';
                    $sheet = MasterSheet::parse(MasterSheet::fetchCsv());
                    if (!$sheet['rows']) throw new RuntimeException('La Hoja master no devolvió productos.');
                    MasterSheet::upsert($db, $sheet['rows']);
                    $items = array_map(fn($r) => ['line' => 0, 'code' => $r['code'], 'desc' => $r['desc'], 'stock' => $r['stock'], 'price' => $r['price']], $newItems);
                    InventoryImport::applyToVisor($db, $items);
                    refreshProductVisibility($db);
                    recomputeParentSkus($db);
                    DriveIndex::relink($db);
                    $result['meta']['crear_nota'] .= ' Catálogo y fotografías actualizados en KINO.';
                } catch (Throwable $e) {
                    $result['meta']['crear_nota'] .= ' Pendiente actualizar el catálogo: usa Sincronizar hoja para completar el enlace.';
                    if (class_exists('ErrorLog')) ErrorLog::record('php', 'inventario_nuevos', $e->getMessage(), __FILE__, __LINE__);
                }
                $sync->rememberResult($id, $result);
            });
        } elseif ($action === 'importar') {
            $up = $_FILES['archivo'] ?? null;
            if (!$up || $up['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Selecciona un archivo SYS válido (.xlsx o .csv).');
            if ((int) $up['size'] > 20 * 1024 * 1024) throw new RuntimeException('El archivo supera los 20 MB. Exporta únicamente el inventario de SYS.');
            if (!in_array(strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)), ['xlsx', 'csv', 'txt'], true)) throw new RuntimeException('Usa un archivo .xlsx o .csv exportado de SYS.');
            set_time_limit(180);
            $parsed = InventoryImport::parseItems(InventoryImport::readFile($up['tmp_name'], $up['name']));
            $id = $sync->import($parsed, $up['name'], hash_file('sha256', $up['tmp_name']), !empty($_POST['neg_cero']), !empty($_POST['hoja']),
                sessionUser() ?: ['username' => ADMIN_USER]);
        } else {
            throw new RuntimeException('La acción solicitada no está disponible.');
        }
        $_SESSION['inventory_flash'] = 'Proceso terminado. Revisa el resultado de KINO y de la Hoja master.';
        redirect('/admin/inventario?carga=' . $id);
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo completar el proceso. Revisa el Registro e intenta de nuevo.';
        if (!($e instanceof RuntimeException) && class_exists('ErrorLog')) ErrorLog::record('php', 'inventario', $e->getMessage(), __FILE__, __LINE__);
    }
}

$history = $ready ? $sync->history() : [];
$requestedId = max(0, (int) ($_GET['carga'] ?? 0));
$batch = $ready ? $sync->load($requestedId) : null;
$result = $batch['result'] ?? null;
$legacy = false;
if (!$result && !$requestedId) {
    $old = BASE_DIR . '/storage/inventario/ultimo.json';
    if (is_file($old)) {
        $result = json_decode((string) file_get_contents($old), true);
        $legacy = (bool) $result;
    }
}
if ($requestedId && !$batch) {
    http_response_code(404);
    $error = 'No encontramos esa carga. Selecciona otra en el historial.';
}

$download = $_GET['descargar'] ?? $_GET['csv'] ?? null;
if ($download && $result) {
    [$binary, $name] = match ($download) {
        'stock' => [InventoryImport::stockXlsx($result), 'STOCK_Hoja_master'],
        'precio' => [InventoryImport::priceXlsx($result), 'PRICE_SYS_Hoja_master'],
        default => [InventoryImport::reportXlsx($result), 'Reporte_inventario_SYS'],
    };
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . ($batch ? '_' . (int) $batch['id'] : '') . '.xlsx"');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}

$counts = [];
foreach ($result['report'] ?? [] as $r) $counts[$r['tipo']] = ($counts[$r['tipo']] ?? 0) + 1;
$type = (string) ($_GET['tipo'] ?? '');
if (!isset($counts[$type])) $type = array_key_first($counts) ?? '';
$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$filtered = array_values(array_filter($result['report'] ?? [], fn($r) => $r['tipo'] === $type &&
    ($query === '' || mb_stripos($r['codigo_inventario'] . ' ' . $r['codigo_hoja'] . ' ' . $r['descripcion'], $query) !== false)));
$pages = max(1, (int) ceil(count($filtered) / 30));
$page = min($pages, max(1, (int) ($_GET['pagina'] ?? 1)));
$shown = array_slice($filtered, ($page - 1) * 30, 30);
$summary = $result['summary'] ?? [];
$latest = $ready ? $sync->latestAppliedSummary() : null;
$latestId = (int) ($latest['id'] ?? 0);
$catalog = $db->query('SELECT COUNT(*) AS total, SUM(' . stockSql() . ' > 0) AS con_stock, COUNT(sys_at) AS con_sys FROM products')->fetch(PDO::FETCH_ASSOC);
$syncDates = [];
try { foreach ($db->query("SELECT name, last_ok_at, last_error_at FROM sync_status WHERE name IN ('hoja', 'drive')") as $r) $syncDates[$r['name']] = $r; }
catch (PDOException $e) {}
$number = fn($n) => number_format((float) ($n ?? 0), 0, ',', '.');
$date = function (?string $v): string {
    if (!$v) return 'Sin actualizar';
    try { return (new DateTimeImmutable($v, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Bogota'))->format('d/m/Y · g:i a'); }
    catch (Throwable $e) { return $v; }
};
$link = fn(array $overrides = []) => '/admin/inventario?' . http_build_query($overrides + ['carga' => (int) ($batch['id'] ?? 0), 'tipo' => $type, 'q' => $query]);
include __DIR__ . '/../../templates/admin/inventory.php';
