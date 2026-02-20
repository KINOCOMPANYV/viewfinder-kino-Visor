<?php
/**
 * Admin — Sincronizar catálogo desde Google Sheets.
 * Lee la hoja pública como CSV y hace UPSERT por SKU.
 * 
 * COLUMNAS SOPORTADAS (en cualquier orden):
 *   sku, name, category, gender, movement, price_suggested, status, description, cover_image_url
 * 
 * La columna cover_image_url puede contener:
 *   - URL directa de imagen (Google Drive lh3.googleusercontent.com, Drive uc?export=view, cualquier URL pública)
 *   - ID de archivo de Drive (solo el ID, se convierte automáticamente a URL de imagen)
 *   - URL de carpeta de Drive compartida (se extrae el primer archivo de imagen)
 *   - Vacío (no se modifica el cover existente)
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

// Descargar CSV con cURL
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

// Columnas soportadas (ahora incluye cover_image_url)
$expectedColumns = ['sku', 'name', 'category', 'gender', 'movement', 'price_suggested', 'status', 'description', 'cover_image_url'];

// Header — normalizar eliminando BOM y espacios
$header = array_map(function ($h) {
    return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"', "'", "\r", "\n"], '', $h)));
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

$hasCoverColumn = isset($colMap['cover_image_url']);

// Procesar filas
$db = getDB();
$inserted = 0;
$updated = 0;
$coversUpdated = 0;
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

    // Normalizar cover_image_url si viene del Sheets
    if (!empty($data['cover_image_url'])) {
        $data['cover_image_url'] = normalizeDriveUrl($data['cover_image_url']);
    }

    processRowWithCover($db, $data, $rowNum, $inserted, $updated, $coversUpdated, $errors);
}

// ============================================================
// Auto-asignar portadas a productos SIN cover (después de sincronizar)
// Solo si NO hay columna cover_image_url en el sheet
// ============================================================
$coversDriveAssigned = 0;
$coverErrors = '';

if (!$hasCoverColumn) {
    // Solo intentar Drive si no hay columna en Sheets
    try {
        set_time_limit(180);
        require_once __DIR__ . '/../services/GoogleDriveService.php';
        $drive = new GoogleDriveService();
        $rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
        $token = $drive->getValidToken($db);

        if ($token && $rootFolderId) {
            $noCover = $db->query(
                "SELECT id, sku FROM products WHERE cover_image_url IS NULL OR cover_image_url = '' LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($noCover)) {
                $updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
                $coverStartTime = time();

                foreach ($noCover as $prod) {
                    if ((time() - $coverStartTime) >= 25) {
                        $coverErrors = "Tiempo límite alcanzado";
                        break;
                    }
                    try {
                        $allFiles = $drive->findBySku($rootFolderId, $prod['sku']);
                        $images = array_values(array_filter($allFiles, fn($f) => str_starts_with($f['mimeType'] ?? '', 'image/')));
                        if (!empty($images)) {
                            $drive->makePublic($images[0]['id']);
                            $updateStmt->execute(["https://lh3.googleusercontent.com/d/{$images[0]['id']}", $prod['id']]);
                            $coversDriveAssigned++;
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
}

jsonResponse([
    'success' => true,
    'inserted' => $inserted,
    'updated' => $updated,
    'covers_from_sheets' => $coversUpdated,
    'covers_from_drive' => $coversDriveAssigned,
    'has_cover_column' => $hasCoverColumn,
    'errors' => $errors,
    'total' => $rowNum,
    'cover_errors' => $coverErrors,
    'message' => buildMessage($inserted, $updated, $coversUpdated, $coversDriveAssigned, $hasCoverColumn),
]);

// ============================================================
// Helpers
// ============================================================

/**
 * Construye mensaje de resumen para el frontend.
 */
function buildMessage(int $inserted, int $updated, int $coversSheets, int $coversDrive, bool $hasCoverCol): string
{
    $parts = [];
    if ($inserted > 0)
        $parts[] = "🆕 {$inserted} nuevos";
    if ($updated > 0)
        $parts[] = "🔄 {$updated} actualizados";
    if ($coversSheets > 0)
        $parts[] = "🖼️ {$coversSheets} portadas desde Sheets";
    if ($coversDrive > 0)
        $parts[] = "🖼️ {$coversDrive} portadas desde Drive";
    if (!$hasCoverCol)
        $parts[] = "💡 Tip: Agrega columna 'cover_image_url' en tu Sheets para sincronizar portadas automáticamente";
    return implode(' · ', $parts) ?: 'Sin cambios';
}

/**
 * Normaliza una URL/ID de Drive o cualquier URL de imagen a formato lh3.googleusercontent.com
 * 
 * Acepta:
 *   - https://drive.google.com/file/d/FILE_ID/view → lh3.googleusercontent.com/d/FILE_ID
 *   - https://drive.google.com/open?id=FILE_ID    → lh3.googleusercontent.com/d/FILE_ID
 *   - https://drive.google.com/uc?id=FILE_ID      → lh3.googleusercontent.com/d/FILE_ID
 *   - https://lh3.googleusercontent.com/d/FILE_ID → sin cambios
 *   - FILE_ID (solo el ID de 28-33 chars alfanuméricos)  → lh3.googleusercontent.com/d/FILE_ID
 *   - Cualquier otra URL pública → sin cambios
 */
function normalizeDriveUrl(string $url): string
{
    $url = trim($url);
    if (empty($url))
        return '';

    // Ya está en formato correcto
    if (str_starts_with($url, 'https://lh3.googleusercontent.com/')) {
        return $url;
    }

    // drive.google.com/file/d/FILE_ID/view o /preview
    if (preg_match('#drive\.google\.com/file/d/([a-zA-Z0-9_-]{20,})#', $url, $m)) {
        return "https://lh3.googleusercontent.com/d/{$m[1]}";
    }

    // drive.google.com/uc?id=FILE_ID o open?id=FILE_ID o ?export=view&id=FILE_ID
    if (preg_match('#[?&]id=([a-zA-Z0-9_-]{20,})#', $url, $m)) {
        return "https://lh3.googleusercontent.com/d/{$m[1]}";
    }

    // Solo un ID de Drive (sin dominio) — 20 a 44 chars alfanuméricos/guiones
    if (preg_match('/^[a-zA-Z0-9_-]{20,44}$/', $url)) {
        return "https://lh3.googleusercontent.com/d/{$url}";
    }

    // Cualquier otra URL pública (imgur, cloudinary, etc.) → dejar como está
    return $url;
}

/**
 * Procesa una fila con soporte para cover_image_url.
 * Extiende processRow() de import_helpers.php.
 */
function processRowWithCover(PDO $db, array $data, int $rowNum, int &$inserted, int &$updated, int &$coversUpdated, array &$errors): void
{
    $sku = $data['sku'] ?? '';
    if (empty($sku)) {
        $errors[] = "Fila {$rowNum}: SKU vacío, se omitió.";
        return;
    }

    $gender = strtolower($data['gender'] ?? 'unisex');
    if (!in_array($gender, ['hombre', 'mujer', 'unisex']))
        $gender = 'unisex';

    $status = strtolower($data['status'] ?? 'active');
    if (!in_array($status, ['active', 'discontinued']))
        $status = 'active';
    $archived = ($status === 'discontinued') ? 1 : 0;

    $price = floatval(str_replace([',', '$', ' '], ['', '', ''], $data['price_suggested'] ?? '0'));
    $coverUrl = $data['cover_image_url'] ?? '';

    try {
        $exists = $db->prepare("SELECT id, cover_image_url FROM products WHERE sku = ?");
        $exists->execute([$sku]);
        $existing = $exists->fetch();

        if ($existing) {
            // UPDATE — solo actualizar cover si viene nuevo valor en el sheet
            $coverSql = '';
            $params = [
                $data['name'] ?? '',
                $data['category'] ?? '',
                $gender,
                $data['movement'] ?? '',
                $price,
                $price,
                $status,
                $data['description'] ?? '',
            ];

            if (!empty($coverUrl)) {
                $coverSql = ', cover_image_url = ?';
                $params[] = $coverUrl;
                if ($existing['cover_image_url'] !== $coverUrl) {
                    $coversUpdated++;
                }
            }

            $params[] = $sku;

            $params[] = $archived;
            $stmt = $db->prepare(
                "UPDATE products SET 
                    name = COALESCE(NULLIF(?, ''), name),
                    category = COALESCE(NULLIF(?, ''), category),
                    gender = ?,
                    movement = COALESCE(NULLIF(?, ''), movement),
                    price_suggested = IF(? > 0, ?, price_suggested),
                    status = ?,
                    description = COALESCE(NULLIF(?, ''), description)
                    {$coverSql},
                    archived = ?,
                    updated_at = NOW()
                 WHERE sku = ?"
            );
            $stmt->execute($params);
            $updated++;
        } else {
            // INSERT
            $stmt = $db->prepare(
                "INSERT INTO products (sku, name, category, gender, movement, price_suggested, status, archived, description, cover_image_url) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $sku,
                $data['name'] ?? $sku,
                $data['category'] ?? '',
                $gender,
                $data['movement'] ?? '',
                $price,
                $status,
                $archived,
                $data['description'] ?? '',
                $coverUrl,
            ]);
            $inserted++;
            if (!empty($coverUrl))
                $coversUpdated++;
        }
    } catch (\Exception $e) {
        $errors[] = "Fila {$rowNum} (SKU: {$sku}): " . $e->getMessage();
    }
}

/**
 * Parsear CSV string a array de arrays.
 */
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
