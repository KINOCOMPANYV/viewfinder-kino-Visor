<?php
/**
 * Webhook Sheets — sincronización incremental disparada por Google Apps Script.
 * POST /api/webhook/sheets
 * Header: X-Webhook-Secret: <WEBHOOK_SHEETS_SECRET>
 * Body JSON: {"skus": ["SKU1", "SKU2", ...]}   ← SKUs modificados en la hoja
 *
 * Responde en < 2s con {status: "ok", updated: N} o {error: "..."}
 */

header('Content-Type: application/json; charset=utf-8');

// ── Autenticación por header secreto ─────────────────────────────────────────
$secret   = env('WEBHOOK_SHEETS_SECRET', '');
$provided = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Configura WEBHOOK_SHEETS_SECRET en las variables de entorno.']);
    exit;
}

// ── Solo acepta POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ── Leer payload ─────────────────────────────────────────────────────────────
$body = file_get_contents('php://input');
$payload = json_decode($body, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON inválido.']);
    exit;
}

$skusRaw = $payload['skus'] ?? [];

if (!is_array($skusRaw) || empty($skusRaw)) {
    // Sin SKUs específicos → responder OK sin hacer nada (ping de configuración)
    echo json_encode(['status' => 'ok', 'updated' => 0, 'message' => 'No skus provided (ping OK)']);
    exit;
}

// Limpiar y limitar
$skus = array_values(array_unique(array_filter(array_map('trim', $skusRaw))));
$skus = array_slice($skus, 0, 100); // máximo 100 SKUs por webhook

// ── Obtener datos frescos de Google Sheets solo para estos SKUs ──────────────
$sheetId = env('GOOGLE_SHEET_ID', '');
if (empty($sheetId)) {
    http_response_code(503);
    echo json_encode(['error' => 'GOOGLE_SHEET_ID no configurado.']);
    exit;
}

// Descargar CSV completo (es la única forma con Sheets públicos sin autenticación por fila)
$url = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv";
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERAGENT      => 'ViewfinderKino-Webhook/1.0',
]);
$csvContent = curl_exec($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($csvContent)) {
    http_response_code(502);
    echo json_encode(['error' => "No se pudo descargar el Sheet (HTTP {$httpCode})."]);
    exit;
}

// ── Parsear CSV ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/../import_helpers.php';

$lines = str_getcsv_multiline($csvContent);
if (count($lines) < 2) {
    echo json_encode(['status' => 'ok', 'updated' => 0, 'message' => 'Sheet vacío']);
    exit;
}

// Mapa de columnas soportadas
$expectedColumns = ['sku', 'name', 'category', 'gender', 'movement', 'price_suggested', 'status', 'description', 'cover_image_url'];
$header = array_map(function ($h) {
    return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"', "'", "\r", "\n"], '', $h)));
}, $lines[0]);

$colMap = [];
foreach ($expectedColumns as $col) {
    $idx = array_search($col, $header);
    if ($idx !== false) $colMap[$col] = $idx;
}

if (!isset($colMap['sku'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Columna "sku" no encontrada en la hoja.']);
    exit;
}

$hasCoverCol = isset($colMap['cover_image_url']);

// Construir mapa sku => name de TODA la hoja (para calcular parent_sku con
// contexto de hermanos, igual que en el sync completo), aunque solo se
// actualicen en BD los SKUs que llegaron en el webhook.
$allSkuNames = [];
for ($j = 1; $j < count($lines); $j++) {
    $r = $lines[$j];
    if (empty(array_filter($r))) continue;
    $s = trim($r[$colMap['sku']] ?? '');
    if ($s === '') continue;
    $rName = isset($colMap['name']) ? trim($r[$colMap['name']] ?? '') : '';
    $allSkuNames[$s] = ['sku' => $s, 'name' => ($rName !== '' ? $rName : $s)];
}
$parentSkuMap = computeParentSkuMap($allSkuNames);

// Buscar SOLO las filas de los SKUs que llegaron en el webhook
$skuSet = array_flip($skus); // Para O(1) lookup
$rowsToUpdate = [];

for ($i = 1; $i < count($lines); $i++) {
    $row = $lines[$i];
    if (empty(array_filter($row))) continue;

    $sku = trim($row[$colMap['sku']] ?? '');
    if ($sku === '' || !isset($skuSet[$sku])) continue;

    $data = [];
    foreach ($colMap as $col => $idx) {
        $data[$col] = isset($row[$idx]) ? trim($row[$idx]) : '';
    }

    $gender = strtolower($data['gender'] ?? 'unisex');
    if (!in_array($gender, ['hombre', 'mujer', 'unisex'])) $gender = 'unisex';

    $status = strtolower($data['status'] ?? 'active');
    if (!in_array($status, ['active', 'discontinued'])) $status = 'active';

    $price   = floatval(str_replace([',', '$', ' '], ['', '', ''], $data['price_suggested'] ?? '0'));

    $coverUrl = '';
    if ($hasCoverCol && !empty($data['cover_image_url'])) {
        $coverUrl = normalizeDriveUrl($data['cover_image_url']);
    }

    $rowsToUpdate[$sku] = [
        'sku'            => $sku,
        'parent_sku'     => $parentSkuMap[$sku] ?? null,
        'name'           => $data['name']        ?? $sku,
        'category'       => $data['category']    ?? '',
        'gender'         => $gender,
        'movement'       => $data['movement']    ?? '',
        'price'          => $price,
        'status'         => $status,
        'archived'       => ($status === 'discontinued') ? 1 : 0,
        'description'    => $data['description'] ?? '',
        'cover_image_url' => $coverUrl,
        'sheet_row'      => $i,
    ];
}

// ── Upsert en BD ─────────────────────────────────────────────────────────────
$db      = getDB();
$updated = 0;

if (!empty($rowsToUpdate)) {
    try {
        $stmt = $db->prepare(
            "INSERT INTO products (sku, parent_sku, name, category, gender, movement,
              price_suggested, status, archived, description, cover_image_url, sheet_row)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               parent_sku      = VALUES(parent_sku),
               name            = COALESCE(NULLIF(VALUES(name), ''), name),
               category        = COALESCE(NULLIF(VALUES(category), ''), category),
               gender          = VALUES(gender),
               movement        = COALESCE(NULLIF(VALUES(movement), ''), movement),
               price_suggested = IF(VALUES(price_suggested) > 0, VALUES(price_suggested), price_suggested),
               status          = VALUES(status),
               archived        = VALUES(archived),
               description     = COALESCE(NULLIF(VALUES(description), ''), description),
               cover_image_url = IF(? = '', cover_image_url, VALUES(cover_image_url)),
               sheet_row       = VALUES(sheet_row),
               updated_at      = NOW()"
        );

        foreach ($rowsToUpdate as $row) {
            $stmt->execute([
                $row['sku'],
                $row['parent_sku'],
                $row['name'],
                $row['category'],
                $row['gender'],
                $row['movement'],
                $row['price'],
                $row['status'],
                $row['archived'],
                $row['description'],
                $row['cover_image_url'] ?: null,
                $row['sheet_row'],
                $row['cover_image_url'], // para el IF() en el UPDATE
            ]);
            $updated++;
        }

        // Invalidar caché de media_search_cache para estos SKUs
        if (!empty($skus)) {
            $ph = implode(',', array_fill(0, count($skus), '?'));
            try {
                $db->prepare("DELETE FROM media_search_cache WHERE sku IN ($ph)")->execute($skus);
            } catch (\PDOException $e) {
                // tabla puede no existir aún
            }
        }

    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode([
    'status'  => 'ok',
    'updated' => $updated,
    'skus'    => array_keys($rowsToUpdate),
    'not_found_in_sheet' => array_values(array_diff($skus, array_keys($rowsToUpdate))),
]);
exit;
