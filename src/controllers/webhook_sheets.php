<?php
/**
 * Webhook Sheets — sincronización incremental disparada por Google Apps Script.
 * POST /api/webhook/sheets
 * Header: X-Webhook-Secret: <WEBHOOK_SHEETS_SECRET>
 * Body JSON: {"skus": ["SKU1", "SKU2", ...]}   ← SKUs modificados en la hoja maestra
 *
 * Misma regla que el sync completo (MasterSheet): la hoja manda en los datos, el admin en activar/desactivar.
 * Un SKU enviado que ya no está en la hoja queda fuera del portal (in_sheet = 0).
 */
require_once __DIR__ . '/../services/MasterSheet.php';

header('Content-Type: application/json; charset=utf-8');

$secret = env('WEBHOOK_SHEETS_SECRET', '');
$provided = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Configura WEBHOOK_SHEETS_SECRET en las variables de entorno.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON inválido.']);
    exit;
}

$skus = $payload['skus'] ?? [];
if (!is_array($skus) || empty($skus)) {
    echo json_encode(['status' => 'ok', 'updated' => 0, 'message' => 'No skus provided (ping OK)']);
    exit;
}
$skus = array_slice(array_values(array_unique(array_filter(array_map('trim', $skus)))), 0, 100);

try {
    $sheet = MasterSheet::parse(MasterSheet::fetchCsv(20));
} catch (RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$rows = array_intersect_key($sheet['rows'], array_flip($skus));
$notInSheet = array_values(array_diff($skus, array_keys($rows)));

$db = getDB();
try {
    $stats = MasterSheet::upsert($db, $rows);
    if ($notInSheet) {
        $db->prepare("UPDATE products SET in_sheet = 0, updated_at = NOW() WHERE sku IN (" . implode(',', array_fill(0, count($notInSheet), '?')) . ")")
           ->execute($notInSheet);
    }
    refreshProductVisibility($db);
    // Un SKU nuevo de una familia también cambia a sus hermanos
    recomputeParentSkus($db);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'updated' => $stats['inserted'] + $stats['updated'],
    'skus' => array_keys($rows),
    'not_found_in_sheet' => $notInSheet,
]);
