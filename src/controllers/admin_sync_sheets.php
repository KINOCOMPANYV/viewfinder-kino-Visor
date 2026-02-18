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
// Auto-asignar portadas a TODOS los productos (después de sincronizar)
// OPTIMIZADO: Una sola búsqueda masiva en Drive, luego match local
// ============================================================
$coversAssigned = 0;
try {
    require_once __DIR__ . '/../services/GoogleDriveService.php';
    $drive = new GoogleDriveService();
    $rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $token = $drive->getValidToken($db);

    if ($token && $rootFolderId) {
        // 1) UNA SOLA BÚSQUEDA: traer TODAS las imágenes de Drive de una vez
        $allImagesQuery = "mimeType contains 'image/' and trashed = false";
        $allImages = [];
        $nextPage = '';
        do {
            $params = [
                'q' => $allImagesQuery,
                'fields' => 'nextPageToken,files(id,name,mimeType)',
                'pageSize' => 1000,
            ];
            if ($nextPage)
                $params['pageToken'] = $nextPage;

            $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($resp, true) ?: [];
            $allImages = array_merge($allImages, $data['files'] ?? []);
            $nextPage = $data['nextPageToken'] ?? '';
        } while (!empty($nextPage));

        // 2) Match local: para cada producto, buscar su mejor imagen en el array local
        $allProducts = $db->query("SELECT id, sku, cover_image_url FROM products")->fetchAll(PDO::FETCH_ASSOC);
        $coverKeywords = ['principal', 'cover', 'portada', 'front', 'frente'];
        $numericPriority = ['01', '_1', '-1', 'f1'];
        $updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");

        foreach ($allProducts as $prod) {
            // Filtrar imágenes que coincidan con el SKU (usando skuMatchesFilename)
            $matched = array_filter($allImages, function ($f) use ($prod) {
                return skuMatchesFilename($prod['sku'], $f['name'] ?? '');
            });
            if (empty($matched))
                continue;

            // Priorizar por keywords
            $matched = array_values($matched);
            usort($matched, function ($a, $b) use ($coverKeywords, $numericPriority) {
                $nameA = strtolower($a['name'] ?? '');
                $nameB = strtolower($b['name'] ?? '');
                $scoreA = 0;
                $scoreB = 0;
                foreach ($coverKeywords as $kw) {
                    if (str_contains($nameA, $kw))
                        $scoreA += 10;
                    if (str_contains($nameB, $kw))
                        $scoreB += 10;
                }
                foreach ($numericPriority as $np) {
                    if (str_contains($nameA, $np))
                        $scoreA += 5;
                    if (str_contains($nameB, $np))
                        $scoreB += 5;
                }
                return $scoreB - $scoreA;
            });

            $bestImage = $matched[0];
            $coverUrl = "https://lh3.googleusercontent.com/d/{$bestImage['id']}";

            if (empty($prod['cover_image_url']) || $prod['cover_image_url'] !== $coverUrl) {
                // Hacer público el archivo para que la URL lh3 funcione
                $drive->makePublic($bestImage['id']);
                $updateStmt->execute([$coverUrl, $prod['id']]);
                $coversAssigned++;
            }
        }
    }
} catch (Exception $e) {
    // No romper la respuesta principal por un error en covers
}


jsonResponse([
    'success' => true,
    'inserted' => $inserted,
    'updated' => $updated,
    'errors' => $errors,
    'total' => $rowNum,
    'covers_assigned' => $coversAssigned,
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
