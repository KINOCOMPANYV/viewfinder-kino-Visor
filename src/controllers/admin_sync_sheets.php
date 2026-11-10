<?php
/**
 * Admin — Sincronizar productos desde la hoja MAESTRA (POST /admin/sync-sheets, ?dry_run=1 = vista previa).
 *
 * - La hoja es la única fuente de los datos: cada código se crea o se actualiza tal cual está en la hoja
 *   (solo las filas que cambiaron, por sheet_hash).
 * - Un código que ya no está en la hoja queda in_sheet = 0 (oculto, no se borra). Si vuelve, reaparece.
 * - Activar/desactivar es solo del admin (is_active): la hoja no lo cambia.
 * - Las portadas las pone "Sincronizar portadas" (Drive), no esta sincronización.
 */
require_once __DIR__ . '/../services/MasterSheet.php';
require_once __DIR__ . '/../services/DriveIndex.php';

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    jsonResponse(['error' => 'Token CSRF inválido.'], 403);
}

$dryRun = (($_GET['dry_run'] ?? '') === '1');
set_time_limit(120);
$db = getDB();

// Error: se guarda con fecha (Admin → Sincronizar lo muestra) y se responde
$fail = function (string $msg, int $code) use ($db, $dryRun): void {
    if (!$dryRun) recordSyncStatus($db, 'hoja', false, $msg);
    jsonResponse(['error' => $msg], $code);
};

// Un solo sync a la vez (la vista previa no escribe, no lo necesita). Ocupado no es un error: no se guarda.
if (!$dryRun && !acquireSyncLock($db)) {
    jsonResponse(['error' => SYNC_BUSY_MESSAGE, 'busy' => true], 423);
}

try {
    $sheet = MasterSheet::parse(MasterSheet::fetchCsv());
} catch (RuntimeException $e) {
    $fail($e->getMessage(), 502);
}
$rows = $sheet['rows'];
if (!$rows) {
    $fail('La hoja no tiene productos (¿pestaña equivocada? revisa GOOGLE_SHEET_GID).', 400);
}

// Seguro: si la hoja trae muchos menos códigos de los que hay (pestaña equivocada, filtro puesto),
// no ocultar medio catálogo sin confirmar
$inDb = $db->query("SELECT sku FROM products WHERE in_sheet = 1")->fetchAll(PDO::FETCH_COLUMN);
$removed = array_values(array_diff($inDb, array_keys($rows)));
if (!$dryRun && count($removed) > 200 && count($removed) > count($inDb) / 2 && ($_GET['force'] ?? '') !== '1') {
    $fail('La hoja trae ' . count($rows) . ' códigos y se ocultarían ' . count($removed)
        . ' de ' . count($inDb) . '. Revisa la hoja (¿pestaña o filtro?) o haz la vista previa.', 409);
}

try {
    $stats = MasterSheet::upsert($db, $rows, $dryRun);
} catch (\Throwable $e) {
    $fail('Error guardando productos: ' . $e->getMessage(), 500);
}

// Códigos que ya no están en la hoja → fuera del portal (no se borran)
if (!$dryRun) {
    foreach (array_chunk($removed, 500) as $chunk) {
        $db->prepare("UPDATE products SET in_sheet = 0, updated_at = NOW() WHERE sku IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")")
           ->execute($chunk);
    }
}

$visibilityChanged = 0;
if (!$dryRun) {
    $visibilityChanged = refreshProductVisibility($db);
    recomputeParentSkus($db); // siempre: aplica también cambios de la regla de familias
    DriveIndex::relink($db);  // códigos nuevos o familias nuevas: fotos y portadas al momento
}
$inactive = (int) $db->query("SELECT COUNT(*) FROM products WHERE is_active = 0")->fetchColumn();

$parts = [];
if ($stats['inserted']) $parts[] = "🆕 {$stats['inserted']} nuevos";
if ($stats['updated']) $parts[] = "🔄 {$stats['updated']} actualizados";
if ($stats['unchanged']) $parts[] = "✅ {$stats['unchanged']} sin cambios";
if ($removed) $parts[] = '📦 ' . count($removed) . ' ya no están en la hoja (se ocultan)';
if ($sheet['duplicates']) $parts[] = '⚠️ ' . count($sheet['duplicates']) . ' códigos repetidos en la hoja (se usó la última fila)';
if ($sheet['skipped']) $parts[] = '⚠️ ' . $sheet['skipped'] . ' filas sin código (no se cargan)';
if ($inactive) $parts[] = "⏸️ {$inactive} desactivados por el admin";

$message = implode(' · ', $parts) ?: 'Sin cambios';
if (!$dryRun) recordSyncStatus($db, 'hoja', true, count($rows) . " códigos leídos · {$message}");

jsonResponse([
    'success' => true,
    'dry_run' => $dryRun,
    'total' => count($rows),
    'inserted' => $stats['inserted'],
    'updated' => $stats['updated'],
    'unchanged' => $stats['unchanged'],
    'removed_from_sheet' => count($removed),
    'removed_skus_preview' => array_slice($removed, 0, 30),
    'duplicate_skus' => array_keys($sheet['duplicates']),
    'columns' => $sheet['columns'],
    'visibility_changed' => $visibilityChanged,
    'message' => ($dryRun ? '👁️ VISTA PREVIA (nada se guardó) — ' : '') . $message,
]);
