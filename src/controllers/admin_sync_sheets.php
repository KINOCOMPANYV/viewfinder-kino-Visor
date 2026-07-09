<?php
/**
 * Admin — Sincronización INTELIGENTE desde Google Sheets.
 * 
 * Mejoras sobre la versión anterior:
 *   - Filtrado de duplicados: si un SKU aparece más de una vez, se usa la última fila
 *   - Detección de cambios por hash: solo se actualizan las filas que realmente cambiaron
 *   - Detección de eliminados: productos en DB pero no en Sheet → se archivan
 *   - Batch SQL: INSERT ON DUPLICATE KEY UPDATE en chunks de 500
 * 
 * COLUMNAS SOPORTADAS (en cualquier orden):
 *   sku, name, category, gender, movement, price_suggested, status, description, cover_image_url
 */

// Verificar CSRF via header (AJAX)
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    jsonResponse(['error' => 'Token CSRF inválido.'], 403);
}

require_once __DIR__ . '/../import_helpers.php';

// Modo vista previa: calcula el impacto sin escribir en la BD ni en Drive
$dryRun = (($_GET['dry_run'] ?? '') === '1');

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

// Columnas soportadas
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
// PASO 1: Recoger filas + filtrar duplicados (último gana)
// ============================================================
$db = getDB();

// Asegurar que la columna sheet_hash exista (self-healing)
try {
    $db->query("SELECT sheet_hash FROM products LIMIT 1");
} catch (\PDOException $e) {
    $db->exec("ALTER TABLE products ADD COLUMN sheet_hash VARCHAR(32) DEFAULT NULL");
}

$errors = [];
$rawRows = [];
$duplicateSkus = [];

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

    $coverUrl = '';
    if (!empty($data['cover_image_url'])) {
        $coverUrl = normalizeDriveUrl($data['cover_image_url']);
    }

    $rowData = [
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
        'sheet_row' => $i,
    ];

    // Filtrar duplicados: si el SKU ya existe, se marca y se sobreescribe
    if (isset($rawRows[$sku])) {
        $duplicateSkus[$sku] = ($duplicateSkus[$sku] ?? 1) + 1;
    }
    $rawRows[$sku] = $rowData; // último gana
}

// Calcular parent_sku con contexto de TODA la hoja: agrupa por SKU raíz candidato
// + nombre exacto, para distinguir variantes reales ("992-1/2/3/4") de
// referencias distintas que comparten el mismo patrón de SKU ("839-5" vs "839-10").
$parentSkuMap = computeParentSkuMap($rawRows);
foreach ($rawRows as $sku => &$rowData) {
    $rowData['parent_sku'] = $parentSkuMap[$sku] ?? null;

    // Hash de la fila para detectar cambios
    $hashData = $rowData;
    unset($hashData['sheet_row']); // la posición no cuenta como cambio
    if (!$hasCoverColumn)
        unset($hashData['cover_image_url']);
    $rowData['_hash'] = md5(json_encode($hashData));
}
unset($rowData);

$batchRows = array_values($rawRows);
$sheetSkus = array_keys($rawRows);
$totalDuplicates = count($duplicateSkus);

// ============================================================
// PASO 2: Comparar hashes — separar cambios de sin-cambios
// ============================================================
$inserted = 0;
$updated = 0;
$unchanged = 0;
$coversUpdated = 0;
$totalRows = count($batchRows);
$chunkSize = 500;

// Obtener productos existentes con su hash y cover
$existingProducts = [];
if (!empty($sheetSkus)) {
    foreach (array_chunk($sheetSkus, 500) as $skuChunk) {
        $ph = implode(',', array_fill(0, count($skuChunk), '?'));
        $stmt = $db->prepare("SELECT sku, sheet_hash, cover_image_url FROM products WHERE sku IN ($ph)");
        $stmt->execute($skuChunk);
        foreach ($stmt->fetchAll() as $r) {
            $existingProducts[$r['sku']] = [
                'hash' => $r['sheet_hash'] ?? '',
                'cover' => $r['cover_image_url'] ?? '',
            ];
        }
    }
}

// Separar filas que realmente cambiaron
$rowsToUpsert = [];
foreach ($batchRows as $row) {
    $sku = $row['sku'];
    $newHash = $row['_hash'];

    if (isset($existingProducts[$sku])) {
        if ($existingProducts[$sku]['hash'] === $newHash) {
            $unchanged++;
            continue; // Sin cambios → omitir
        }
        $updated++;
        if (!empty($row['cover_image_url']) && $existingProducts[$sku]['cover'] !== $row['cover_image_url']) {
            $coversUpdated++;
        }
    } else {
        $inserted++;
        if (!empty($row['cover_image_url']))
            $coversUpdated++;
    }
    $rowsToUpsert[] = $row;
}

// ============================================================
// PASO 3: Batch UPSERT solo las filas que cambiaron
// ============================================================
if (!$dryRun && !empty($rowsToUpsert)) {
    $db->beginTransaction();
    try {
        foreach (array_chunk($rowsToUpsert, $chunkSize) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params[] = $row['sku'];
                $params[] = $row['parent_sku'];
                $params[] = $row['name'];
                $params[] = $row['category'];
                $params[] = $row['gender'];
                $params[] = $row['movement'];
                $params[] = $row['price'];
                $params[] = $row['status'];
                $params[] = $row['archived'];
                $params[] = $row['description'];
                $params[] = $row['cover_image_url'] ?: null;
                $params[] = $row['sheet_row'];
                $params[] = $row['_hash'];
            }

            $sql = "INSERT INTO products (sku, parent_sku, name, category, gender, movement, price_suggested, status, archived, description, cover_image_url, sheet_row, sheet_hash) 
                    VALUES " . implode(', ', $placeholders) . "
                    ON DUPLICATE KEY UPDATE 
                        parent_sku = VALUES(parent_sku),
                        name = COALESCE(NULLIF(VALUES(name), ''), name),
                        category = COALESCE(NULLIF(VALUES(category), ''), category),
                        gender = VALUES(gender),
                        movement = COALESCE(NULLIF(VALUES(movement), ''), movement),
                        price_suggested = IF(VALUES(price_suggested) > 0, VALUES(price_suggested), price_suggested),
                        status = VALUES(status),
                        archived = VALUES(archived),
                        description = COALESCE(NULLIF(VALUES(description), ''), description),
                        cover_image_url = COALESCE(VALUES(cover_image_url), cover_image_url),
                        sheet_row = VALUES(sheet_row),
                        sheet_hash = VALUES(sheet_hash),
                        updated_at = NOW()";
            $db->prepare($sql)->execute($params);
        }
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        $errors[] = "Error en batch insert: " . $e->getMessage();
    }
}

// ============================================================
// PASO 4: Detectar eliminados — SKUs en DB que NO están en Sheet → archivar
// ============================================================
$archivedFromSheet = 0;
$removedSkus = [];
if (!empty($sheetSkus)) {
    $allDbSkus = $db->query("SELECT sku FROM products WHERE archived = 0")->fetchAll(PDO::FETCH_COLUMN);
    $removedSkus = array_diff($allDbSkus, $sheetSkus);

    if (!$dryRun && !empty($removedSkus)) {
        foreach (array_chunk($removedSkus, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $db->prepare("UPDATE products SET archived = 1, updated_at = NOW() WHERE sku IN ($ph) AND archived = 0")
                ->execute($chunk);
            $archivedFromSheet += count($chunk);
        }
    } elseif ($dryRun) {
        $archivedFromSheet = count($removedSkus);
    }
}

// ============================================================
// PASO 5: Auto-asignar portadas desde Drive (pre-index completo)
// ============================================================
$coversDriveAssigned = 0;
$coverErrors = '';

if (!$dryRun && !$hasCoverColumn) {
    try {
        set_time_limit(300);
        require_once __DIR__ . '/../services/GoogleDriveService.php';
        $drive = new GoogleDriveService();
        $rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
        $token = $drive->getValidToken($db);

        if ($token && $rootFolderId) {
            $noCover = $db->query(
                "SELECT id, sku FROM products WHERE (cover_image_url IS NULL OR cover_image_url = '') LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($noCover)) {
                $allDriveFiles = $drive->listAllMediaFiles($rootFolderId);
                $driveFiles = $allDriveFiles['files'] ?? [];

                $updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
                $fileIdsToPublish = [];
                $updatesQueue = [];

                foreach ($noCover as $prod) {
                    $cleanSku = $prod['sku'];
                    $rootSku = extractRootSku($cleanSku);
                    $noPrefixSku = extractSkuWithoutPrefix($cleanSku);
                    $noPrefixRoot = extractSkuWithoutPrefix($rootSku);
                    $skuVariations = array_unique(array_filter([$cleanSku, $rootSku, $noPrefixSku, $noPrefixRoot]));

                    $matched = [];
                    foreach ($driveFiles as $file) {
                        foreach ($skuVariations as $variation) {
                            if (skuMatchesFilename($variation, $file['name'])) {
                                $matched[] = $file;
                                break;
                            }
                        }
                    }

                    if (empty($matched))
                        continue;

                    $coverFile = null;
                    foreach ($matched as $f) {
                        if (str_starts_with($f['mimeType'] ?? '', 'image/')) {
                            $coverFile = $f;
                            break;
                        }
                    }
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
                        $coverUrl = "https://lh3.googleusercontent.com/d/{$coverFile['id']}=s400";
                        if ($isVideo)
                            $coverUrl = "[VIDEO]{$coverUrl}";
                        $updatesQueue[] = ['url' => $coverUrl, 'id' => $prod['id']];
                    }
                }

                if (!empty($fileIdsToPublish)) {
                    $drive->makePublicBatch($fileIdsToPublish);
                }

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

// ============================================================
// Respuesta con estadísticas completas
// ============================================================
jsonResponse([
    'success' => true,
    'dry_run' => $dryRun,
    'inserted' => $inserted,
    'updated' => $updated,
    'unchanged' => $unchanged,
    'archived_from_sheet' => $archivedFromSheet,
    'archived_skus_preview' => array_slice(array_values($removedSkus), 0, 30),
    'duplicates_in_sheet' => $totalDuplicates,
    'covers_from_sheets' => $coversUpdated,
    'covers_from_drive' => $coversDriveAssigned,
    'has_cover_column' => $hasCoverColumn,
    'errors' => $errors,
    'total' => $totalRows,
    'cover_errors' => $coverErrors,
    'duplicate_skus' => array_keys($duplicateSkus),
    'message' => ($dryRun ? '👁️ VISTA PREVIA (nada se guardó) — ' : '') . buildMessage($inserted, $updated, $unchanged, $archivedFromSheet, $totalDuplicates, $coversUpdated, $coversDriveAssigned, $hasCoverColumn),
]);

// ============================================================
// Helpers
// ============================================================

/**
 * Construye mensaje de resumen para el frontend.
 */
function buildMessage(int $inserted, int $updated, int $unchanged, int $archived, int $duplicates, int $coversSheets, int $coversDrive, bool $hasCoverCol): string
{
    $parts = [];
    if ($inserted > 0)
        $parts[] = "🆕 {$inserted} nuevos";
    if ($updated > 0)
        $parts[] = "🔄 {$updated} actualizados";
    if ($unchanged > 0)
        $parts[] = "✅ {$unchanged} sin cambios";
    if ($archived > 0)
        $parts[] = "📦 {$archived} archivados (eliminados del Sheet)";
    if ($duplicates > 0)
        $parts[] = "⚠️ {$duplicates} SKUs duplicados en Sheet (se usó última fila)";
    if ($coversSheets > 0)
        $parts[] = "🖼️ {$coversSheets} portadas desde Sheets";
    if ($coversDrive > 0)
        $parts[] = "🖼️ {$coversDrive} portadas desde Drive";
    if (!$hasCoverCol)
        $parts[] = "💡 Tip: Agrega columna 'cover_image_url' en tu Sheets para sincronizar portadas automáticamente";
    return implode(' · ', $parts) ?: 'Sin cambios';
}

