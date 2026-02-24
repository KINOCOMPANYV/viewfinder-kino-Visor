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
$expectedColumns = ['sku', 'name', 'category', 'gender', 'movement', 'price_suggested', 'status', 'description', 'cover_image_url'];

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
    $data['_sheet_row'] = $i; // posición real en la hoja
    processRow($db, $data, $rowNum, $inserted, $updated, $errors);
}

// ============================================================
// Auto-vincular portadas: SOLO match exacto por nombre de archivo.
// El nombre del archivo en Drive (sin extensión) debe ser EXACTO al SKU.
// Ejemplo: archivo "839-5.jpg" → SKU "839-5" ✅
//          archivo "839-5F1.jpg" → SKU "839-5" ❌ (no es match exacto)
// ============================================================
$coversAssigned = 0;
$coverErrors = '';
try {
    set_time_limit(300);
    require_once __DIR__ . '/../services/GoogleDriveService.php';
    $drive = new GoogleDriveService();
    $rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $token = $drive->getValidToken($db);

    if ($token && $rootFolderId) {
        $noCover = $db->query(
            "SELECT id, sku FROM products WHERE (cover_image_url IS NULL OR cover_image_url = '') AND archived = 0"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($noCover)) {
            $updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");

            foreach ($noCover as $prod) {
                $cleanSku = preg_replace('/\.\w{2,4}$/i', '', $prod['sku']);

                try {
                    $allFiles = $drive->findBySku($rootFolderId, $cleanSku);

                    // SOLO match exacto: nombre sin extensión === SKU limpio
                    $exactImages = [];
                    $exactVideos = [];
                    foreach ($allFiles as $f) {
                        $nameOnly = pathinfo($f['name'] ?? '', PATHINFO_FILENAME);
                        if (strcasecmp($nameOnly, $cleanSku) !== 0)
                            continue; // skip non-exact

                        $mime = $f['mimeType'] ?? '';
                        if (str_starts_with($mime, 'image/'))
                            $exactImages[] = $f;
                        elseif (str_starts_with($mime, 'video/'))
                            $exactVideos[] = $f;
                    }

                    if (!empty($exactImages)) {
                        $drive->makePublic($exactImages[0]['id']);
                        $updateStmt->execute([
                            "https://lh3.googleusercontent.com/d/{$exactImages[0]['id']}",
                            $prod['id']
                        ]);
                        $coversAssigned++;
                    } elseif (!empty($exactVideos)) {
                        $drive->makePublic($exactVideos[0]['id']);
                        $updateStmt->execute([
                            "[VIDEO]https://drive.google.com/thumbnail?id={$exactVideos[0]['id']}&sz=w400",
                            $prod['id']
                        ]);
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
