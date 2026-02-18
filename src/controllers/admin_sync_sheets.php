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
// RÁPIDO: Una sola búsqueda paginada, match local, sin makePublic
// (makePublic se hace al momento de ver el producto via /api/media)
// ============================================================
$coversAssigned = 0;
$coverErrors = '';
try {
    set_time_limit(60); // Evitar timeout
    require_once __DIR__ . '/../services/GoogleDriveService.php';
    $drive = new GoogleDriveService();
    $rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
    $token = $drive->getValidToken($db);

    if ($token && $rootFolderId) {
        // Solo productos sin cover
        $noCover = $db->query("SELECT id, sku FROM products WHERE cover_image_url IS NULL OR cover_image_url = ''")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($noCover)) {
            // 1) UNA SOLA búsqueda: traer TODAS las imágenes de Drive (paginada)
            $allImages = [];
            $nextPage = '';
            $maxPages = 5; // máximo 5000 imágenes
            $pageCount = 0;
            do {
                $params = [
                    'q' => "mimeType contains 'image/' and trashed = false",
                    'fields' => 'nextPageToken,files(id,name,mimeType)',
                    'pageSize' => 1000,
                    'corpora' => 'user',
                ];
                if ($nextPage)
                    $params['pageToken'] = $nextPage;

                $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
                ]);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    $coverErrors = "Drive API respondió HTTP {$httpCode}";
                    break;
                }

                $data = json_decode($resp, true) ?: [];
                $allImages = array_merge($allImages, $data['files'] ?? []);
                $nextPage = $data['nextPageToken'] ?? '';
                $pageCount++;
            } while (!empty($nextPage) && $pageCount < $maxPages);

            // 2) Match local rápido
            if (!empty($allImages)) {
                $coverKeywords = ['principal', 'cover', 'portada', 'front', 'frente'];
                $numericPriority = ['01', '_1', '-1', 'f1'];
                $updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");

                foreach ($noCover as $prod) {
                    $matched = array_filter($allImages, function ($f) use ($prod) {
                        return skuMatchesFilename($prod['sku'], $f['name'] ?? '');
                    });
                    if (empty($matched))
                        continue;

                    $matched = array_values($matched);
                    usort($matched, function ($a, $b) use ($coverKeywords, $numericPriority) {
                        $nameA = strtolower($a['name'] ?? '');
                        $nameB = strtolower($b['name'] ?? '');
                        $scoreA = $scoreB = 0;
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
                    $updateStmt->execute([$coverUrl, $prod['id']]);
                    $coversAssigned++;
                }
            } else if (empty($coverErrors)) {
                $coverErrors = "Drive devolvió 0 imágenes — verificar permisos de carpeta";
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
