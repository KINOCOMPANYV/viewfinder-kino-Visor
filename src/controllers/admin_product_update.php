<?php
/**
 * Admin — Actualizar producto inline.
 * POST /admin/product/update
 * Actualiza la BD local y opcionalmente Google Sheets.
 */

header('Content-Type: application/json');

// CSRF
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    jsonResponse(['error' => 'Token CSRF inválido.'], 403);
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(['error' => 'ID de producto inválido.'], 400);
}

// Campos editables
$allowedFields = ['name', 'category', 'gender', 'movement', 'price_suggested', 'status', 'description'];
$field = $_POST['field'] ?? '';
$value = trim($_POST['value'] ?? '');

if (!in_array($field, $allowedFields)) {
    jsonResponse(['error' => "Campo no permitido: {$field}"], 400);
}

$db = getDB();

// Obtener producto actual (necesitamos el SKU para Sheets)
$stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    jsonResponse(['error' => 'Producto no encontrado.'], 404);
}

// Actualizar BD
$stmt = $db->prepare("UPDATE products SET {$field} = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$value, $id]);

// Intentar actualizar Google Sheets
$sheetsUpdated = false;
$sheetsError = '';

$sheetId = env('GOOGLE_SHEET_ID', '');
if (!empty($sheetId)) {
    try {
        $drive = new GoogleDriveService();
        $token = $drive->getValidToken($db);

        if ($token) {
            $sheetsUpdated = updateGoogleSheet($token, $sheetId, $product['sku'], $field, $value);
        } else {
            $sheetsError = 'Sin token de Google. Conecte Drive primero.';
        }
    } catch (Exception $e) {
        $sheetsError = $e->getMessage();
    }
}

jsonResponse([
    'ok' => true,
    'message' => 'Producto actualizado.',
    'sheets_updated' => $sheetsUpdated,
    'sheets_error' => $sheetsError,
]);

/**
 * Buscar la fila del SKU en Google Sheets y actualizar la celda correspondiente.
 */
function updateGoogleSheet(string $token, string $sheetId, string $sku, string $field, string $value): bool
{
    // Columnas esperadas en el Sheet (orden de A a H)
    $columns = ['sku', 'name', 'category', 'gender', 'movement', 'price_suggested', 'status', 'description'];
    $colIndex = array_search($field, $columns);
    if ($colIndex === false)
        return false;

    $colLetter = chr(65 + $colIndex); // A=0, B=1, etc.

    // 1) Leer columna A (SKUs) para encontrar la fila
    $range = urlencode('A:A');
    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/{$range}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    $values = $data['values'] ?? [];

    // Buscar fila del SKU
    $rowNumber = null;
    foreach ($values as $i => $row) {
        if (isset($row[0]) && trim($row[0]) === $sku) {
            $rowNumber = $i + 1; // Sheets usa 1-indexed
            break;
        }
    }

    if (!$rowNumber)
        return false;

    // 2) Escribir la celda
    $cellRange = urlencode("{$colLetter}{$rowNumber}");
    $updateUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/{$cellRange}?valueInputOption=USER_ENTERED";

    $body = json_encode([
        'values' => [[$value]]
    ]);

    $ch = curl_init($updateUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json",
        ],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}
