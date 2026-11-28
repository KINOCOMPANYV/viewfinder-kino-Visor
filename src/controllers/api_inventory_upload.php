<?php
/**
 * POST /api/inventario/subir — el agente sube el Excel de SYS (campo «archivo»).
 * Clave en la cabecera X-Inventory-Token = INVENTORY_UPLOAD_TOKEN (Railway). Sin clave configurada la ruta no existe.
 * Misma carga que la pantalla Admin → Inventario (cruce, historial, bloqueo), con dos defensas extra:
 * rechaza archivos con menos de INVENTORY_MIN_ROWS filas (9000 por defecto) y no reprocesa el mismo archivo dos veces,
 * para que la alerta de «sin actualizar» no se apague si nadie volvió a exportar de SYS.
 */
require_once __DIR__ . '/../services/InventorySync.php';

header('Cache-Control: no-store');
$token = env('INVENTORY_UPLOAD_TOKEN', '');
if ($token === '') jsonResponse(['ok' => false, 'error' => 'No disponible.'], 404);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonResponse(['ok' => false, 'error' => 'Usa POST.'], 405);
$given = (string) ($_SERVER['HTTP_X_INVENTORY_TOKEN'] ?? '');
if ($given === '' || !hash_equals($token, $given)) jsonResponse(['ok' => false, 'error' => 'Acceso denegado.'], 403);

$up = $_FILES['archivo'] ?? null;
if (!$up || $up['error'] !== UPLOAD_ERR_OK) jsonResponse(['ok' => false, 'error' => 'Falta el archivo (campo «archivo»).'], 400);
if ((int) $up['size'] > 20 * 1024 * 1024) jsonResponse(['ok' => false, 'error' => 'El archivo supera los 20 MB.'], 413);
if (!in_array(strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)), ['xlsx', 'csv', 'txt'], true)) jsonResponse(['ok' => false, 'error' => 'Usa .xlsx o .csv.'], 400);

try {
    set_time_limit(180);
    $db = getDB();
    $sync = new InventorySync($db);
    if (!$sync->ready()) jsonResponse(['ok' => false, 'error' => 'Falta aplicar la migración 047.'], 503);
    $hash = hash_file('sha256', $up['tmp_name']);
    if ($hash === $sync->latestAppliedHash()) {
        jsonResponse(['ok' => true, 'duplicado' => true, 'mensaje' => 'Es el mismo archivo de la última carga: no se procesó. Falta exportar uno nuevo de SYS.']);
    }
    $parsed = InventoryImport::parseItems(InventoryImport::readFile($up['tmp_name'], $up['name']));
    $min = max(1, (int) env('INVENTORY_MIN_ROWS', '9000'));
    if (count($parsed['items']) < $min) {
        jsonResponse(['ok' => false, 'error' => 'El archivo trae ' . count($parsed['items']) . " filas; se esperan al menos $min. No se cargó (¿exportación con filtro o incompleta?)."], 422);
    }
    $id = $sync->import($parsed, $up['name'], $hash, true, !empty($_GET['hoja']), ['username' => 'Agente SYS', 'name' => 'Agente SYS']);
    $row = $sync->load($id);
    jsonResponse(['ok' => true, 'carga' => $id, 'estado' => $row['status'] ?? '', 'aplicados' => (int) ($row['applied_count'] ?? 0), 'filas' => count($parsed['items'])]);
} catch (RuntimeException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage(), 'ocupado' => $e->getMessage() === SYNC_BUSY_MESSAGE], $e->getMessage() === SYNC_BUSY_MESSAGE ? 423 : 400);
} catch (Throwable $e) {
    if (class_exists('ErrorLog')) ErrorLog::record('php', 'inventario_agente', $e->getMessage(), __FILE__, __LINE__);
    jsonResponse(['ok' => false, 'error' => 'No se pudo procesar el archivo. Revisa el Registro.'], 500);
}
