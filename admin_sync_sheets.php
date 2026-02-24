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

// ============================================================
// Procesar filas — recoger datos en memoria para batch SQL
// ============================================================
$db = getDB();
$errors = [];
$batchRows = [];

for ($i = 1; $i < count($lines); $i++) {
    $row = $lines[$i];
    if (empty(array_filter($row)))
        continue;

    $data = [];
    foreach ($colMap as $col => $idx) {
        $data[$col] = isset($row[$idx]) ? trim($row[$idx]) : '';
    }

    $sku = $data['sku'] ?? '';
    if (empty($sku)) {
        $errors[] = "Fila " . ($i + 1) . ": SKU vacío, se omitió.";
        continue;
    }

    // Sanitizar
    $gender = strtolower($data['gender'] ?? 'unisex');
    if (!in_array($gender, ['hombre', 'mujer', 'unisex']))
        $gender = 'unisex';

    $status = strtolower($data['status'] ?? 'active');
    if (!in_array($status, ['active', 'discontinued']))
        $status = 'active';

    $price = floatval(str_replace([',', '$', ' '], ['', '', ''], $data['price_suggested'] ?? '0'));

    // Normalizar cover_image_url
    $coverUrl = '';
    if (!empty($data['cover_image_url'])) {
        $coverUrl = normalizeDriveUrl($data['cover_image_url']);
    }

    $batchRows[] = [
        'sku' => $sku,
        'name' => $data['name'] ?? $sku,
        'category' => $data['category'] ?? '',
        'gender' => $gender,
        'movement' => $data['movement'] ?? '',
        'price' => $price,
        'status' => $status,
        'archived' => ($status === 'discontinued') ? 1 : 0,
        'description' => $data['description'] ?? '',
        'cover_image_url' => $coverUrl,
    ];
}

// ============================================================
// Batch INSERT ... ON DUPLICATE KEY UPDATE (chunks de 500)
// ============================================================
$inserted = 0;
$updated = 0;
$coversUpdated = 0;
$totalRows = count($batchRows);
$chunkSize = 500;

$db->beginTransaction();
try {
    // Obtener SKUs existentes de un solo golpe
    $allSkus = array_column($batchRows, 'sku');
    $existingSkus = [];
    if (!empty($allSkus)) {
        foreach (array_chunk($allSkus, 500) as $skuChunk) {
            $ph = implode(',', array_fill(0, count($skuChunk), '?'));
            $stmt = $db->prepare("SELECT sku, cover_image_url FROM products WHERE sku IN ($ph)");
            $stmt->execute($skuChunk);
            foreach ($stmt->fetchAll() as $r) {
                $existingSkus[$r['sku']] = $r['cover_image_url'] ?? '';
            }
        }
    }

    // Batch upsert en chunks
    foreach (array_chunk($batchRows, $chunkSize) as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $params[] = $row['sku'];
            $params[] = $row['name'];
            $params[] = $row['category'];
            $params[] = $row['gender'];
            $params[] = $row['movement'];
            $params[] = $row['price'];
            $params[] = $row['status'];
            $params[] = $row['archived'];
            $params[] = $row['description'];
            $params[] = $row['cover_image_url'] ?: null;

            // Contar inserts vs updates
            if (isset($existingSkus[$row['sku']])) {
                $updated++;
                if (!empty($row['cover_image_url']) && $existingSkus[$row['sku']] !== $row['cover_image_url']) {
                    $coversUpdated++;
                }
            } else {
                $inserted++;
                if (!empty($row['cover_image_url']))
                    $coversUpdated++;
            }
        }

        $sql = "INSERT INTO products (sku, name, category, gender, movement, price_suggested, status, archived, description, cover_image_url) 
                VALUES " . implode(', ', $placeholders) . "
                ON DUPLICATE KEY UPDATE 
                    name = COALESCE(NULLIF(VALUES(name), ''), name),
                    category = COALESCE(NULLIF(VALUES(category), ''), category),
                    gender = VALUES(gender),
                    movement = COALESCE(NULLIF(VALUES(movement), ''), movement),
                    price_suggested = IF(VALUES(price_suggested) > 0, VALUES(price_suggested), price_suggested),
                    status = VALUES(status),
                    archived = VALUES(archived),
                    description = COALESCE(NULLIF(VALUES(description), ''), description),
                    cover_image_url = COALESCE(VALUES(cover_image_url), cover_image_url),
                    updated_at = NOW()";
        $db->prepare($sql)->execute($params);
    }
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    $errors[] = "Error en batch insert: " . $e->getMessage();
}

// ============================================================
// Auto-asignar portadas desde Drive (pre-index completo)
// Solo si NO hay columna cover_image_url en el sheet
// ============================================================
$coversDriveAssigned = 0;
$coverErrors = '';

if (!$hasCoverColumn) {
    try {
        set_time_limit(300); // 5 minutos para procesar covers
        require_once __DIR__ . '/../services/GoogleDriveService.php';
        $drive = new GoogleDriveService();
        $rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
        $token = $drive->getValidToken($db);

        if ($token && $rootFolderId) {
            // 1) Productos sin cover (hasta 200)
            $noCover = $db->query(
                "SELECT id, sku FROM products WHERE (cover_image_url IS NULL OR cover_image_url = '') LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($noCover)) {
                // 2) Pre-index: listar TODOS los archivos de imagen/video en Drive
                $allDriveFiles = $drive->listAllMediaFiles();
                $driveFiles = $allDriveFiles['files'] ?? [];

                // 3) Construir mapa de SKU → archivos
                $skuFileMap = [];
                foreach ($driveFiles as $file) {
                    $fileName = pathinfo($file['name'], PATHINFO_FILENAME);
                    // El nombre de archivo ES el SKU o comienza con el SKU
                    $skuFileMap[] = $file;
                }

                // 4) Para cada producto sin cover, buscar match
                $updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
                $fileIdsToPublish = [];
                $updatesQueue = [];

                foreach ($noCover as $prod) {
                    $rootSku = extractRootSku($prod['sku']);
                    // Buscar en archivos pre-indexados
                    $matched = [];
                    foreach ($skuFileMap as $file) {
                        $fName = pathinfo($file['name'], PATHINFO_FILENAME);
                        if (skuMatchesFilename($rootSku, $file['name'])) {
                            $matched[] = $file;
                        }
                    }

                    if (empty($matched))
                        continue;

                    // Buscar primera imagen
                    $coverFile = null;
                    foreach ($matched as $f) {
                        if (str_starts_with($f['mimeType'] ?? '', 'image/')) {
                            $coverFile = $f;
                            break;
                        }
                    }
                    // Fallback: video
                    if (!$coverFile) {
                        foreach ($matched as $f) {
                            if (str_starts_with($f['mimeType'] ?? '', 'video/')) {
                                $coverFile = $f;
                                break;
                            }
                        }
                    }

                    if ($coverFile) {
                        $fileIdsToPublish[] = $coverFile['id'];
                        $isVideo = str_starts_with($coverFile['mimeType'] ?? '', 'video/');
                        $coverUrl = "https://lh3.googleusercontent.com/d/{$coverFile['id']}";
                        if ($isVideo)
                            $coverUrl = "[VIDEO]{$coverUrl}";
                        $updatesQueue[] = ['url' => $coverUrl, 'id' => $prod['id']];
                    }
                }

                // 5) Hacer públicos en batch (curl_multi)
                if (!empty($fileIdsToPublish)) {
                    $drive->makePublicBatch($fileIdsToPublish);
                }

                // 6) Actualizar DB en batch
                foreach ($updatesQueue as $upd) {
                    $updateStmt->execute([$upd['url'], $upd['id']]);
                    $coversDriveAssigned++;
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
    'total' => $totalRows,
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

            $params[] = $archived;
            $params[] = $sku;

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
