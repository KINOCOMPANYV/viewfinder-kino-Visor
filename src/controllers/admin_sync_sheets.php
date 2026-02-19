<?php
/**
 * Admin — Sincronizar catálogo desde Google Sheets.
 * Lee la hoja pública como CSV y hace UPSERT por SKU.
 */

// Verificar CSRF via header (AJAX)
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    jsonResponse(['error' => 'Token CSRF inválido.'], 403);
}

require_once __DIR__ . '/../import_helpers.php';

$sheetId = env('GOOGLE_SHEET_ID', '');
if (empty($sheetId)) {
    jsonResponse(['error' => 'Variable GOOGLE_SHEET_ID no configurada en Railway.'], 400);
}

// URL pública para exportar como CSV
$url = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv";

// Descargar CSV con cURL (más robusto que file_get_contents)
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'ViewfinderKino/1.0',
]);
$csvContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || empty($csvContent)) {
    $msg = $curlError ?: "Google Sheets respondió con código HTTP {$httpCode}";
    jsonResponse(['error' => "No se pudo descargar la hoja: {$msg}. Verifica que esté compartida como 'Cualquier persona con el enlace'."], 502);
}

// Parsear CSV en memoria
$lines = str_getcsv_multiline($csvContent);

if (count($lines) < 2) {
    jsonResponse(['error' => 'La hoja está vacía o solo tiene encabezados.'], 400);
}

// Columnas esperadas
$expectedColumns = ['sku', 'name', 'category', 'gender', 'movement', 'price_suggested', 'status', 'description'];

// Header
$header = array_map(function ($h) {
    return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"', "'"], '', $h)));
}, $lines[0]);

// Mapear columnas
$colMap = [];
foreach ($expectedColumns as $col) {
    $idx = array_search($col, $header);
    if ($idx !== false) {
        $colMap[$col] = $idx;
    }
}

if (!isset($colMap['sku'])) {
    jsonResponse(['error' => 'No se encontró la columna "sku" en la hoja. Columnas encontradas: ' . implode(', ', $header)], 400);
}

// Procesar filas
$db = getDB();
$inserted = 0;
$updated = 0;
$errors = [];
$rowNum = 0;

for ($i = 1; $i < count($lines); $i++) {
    $row = $lines[$i];
    if (empty(array_filter($row)))
        continue; // omitir filas vacías

    $rowNum++;
    $data = [];
    foreach ($colMap as $col => $idx) {
        $data[$col] = isset($row[$idx]) ? trim($row[$idx]) : '';
    }
    processRow($db, $data, $rowNum, $inserted, $updated, $errors);
}

// ============================================================
// Auto-asignar portadas a productos SIN cover (después de sincronizar)
// Prefiere match exacto, luego prefijo+separador. LIMITADO a 5 + 30s.
// ============================================================
$coversAssigned = 0;
$coverErrors = '';
try {
    $coverStartTime = time();
    $coverTimeLimit = 30;
    set_time_limit(180);
    require_once __DIR__ . '/../services/GoogleDriveService.php';
    $drive = new GoogleDriveService();
    $rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $token = $drive->getValidToken($db);

    if ($token && $rootFolderId) {
        $noCover = $db->query("SELECT id, sku FROM products WHERE cover_image_url IS NULL OR cover_image_url = '' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($noCover)) {
            $updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");

            foreach ($noCover as $prod) {
                if ((time() - $coverStartTime) >= $coverTimeLimit) {
                    $coverErrors = "Tiempo límite alcanzado";
                    break;
                }

                try {
                    $allFiles = $drive->findBySku($rootFolderId, $prod['sku']);

                    // Clasificar: exactos vs compatibles (prefijo + separador)
                    $exact = $compatible = [];
                    foreach ($allFiles as $f) {
                        $nameOnly = pathinfo($f['name'] ?? '', PATHINFO_FILENAME);
                        if (strcasecmp($nameOnly, $prod['sku']) === 0) {
                            $exact[] = $f;
                        } elseif (
                            stripos($nameOnly, $prod['sku']) === 0
                            && strlen($nameOnly) > strlen($prod['sku'])
                            && !ctype_alnum($nameOnly[strlen($prod['sku'])])
                        ) {
                            $compatible[] = $f;
                        }
                    }

                    $candidates = !empty($exact) ? $exact : $compatible;
                    if (empty($candidates))
                        continue;

                    $images = array_values(array_filter($candidates, fn($f) => str_starts_with($f['mimeType'] ?? '', 'image/')));
                    $videos = array_values(array_filter($candidates, fn($f) => str_starts_with($f['mimeType'] ?? '', 'video/')));

                    if (!empty($images)) {
                        $drive->makePublic($images[0]['id']);
                        $updateStmt->execute(["https://lh3.googleusercontent.com/d/{$images[0]['id']}", $prod['id']]);
                        $coversAssigned++;
                    } elseif (!empty($videos)) {
                        $drive->makePublic($videos[0]['id']);
                        $updateStmt->execute(["[VIDEO]https://drive.google.com/thumbnail?id={$videos[0]['id']}&sz=w400", $prod['id']]);
                        $coversAssigned++;
                    }
                } catch (Exception $e) {
                    // Silenciar errores individuales
                }
            }
        }
    } else {
        $coverErrors = $token ? 'GOOGLE_DRIVE_FOLDER_ID no configurado' : 'Sin token de Google Drive';
    }
} catch (Exception $e) {
    $coverErrors = $e->getMessage();
}


jsonResponse([
    'success' => true,
    'inserted' => $inserted,
    'updated' => $updated,
    'errors' => $errors,
    'total' => $rowNum,
    'covers_assigned' => $coversAssigned,
    'cover_errors' => $coverErrors,
]);

// ============================================================
// Helper: parsear CSV string a array de arrays
// ============================================================
function str_getcsv_multiline(string $csv): array
{
    $rows = [];
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csv);
    rewind($handle);

    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}
