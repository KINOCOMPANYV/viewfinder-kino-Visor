<?php
/**
 * Funciones compartidas para importación de productos.
 * Usadas por admin_import.php y admin_sync_sheets.php.
 */

/**
 * Normaliza cualquier formato de URL de Google Drive a URL embebible.
 * Acepta:
 *   - https://drive.google.com/file/d/FILE_ID/view
 *   - https://drive.google.com/open?id=FILE_ID
 *   - https://drive.google.com/uc?id=FILE_ID
 *   - Solo el FILE_ID (alfanumérico con guiones/guiones bajos)
 * Retorna: https://lh3.googleusercontent.com/d/FILE_ID
 */
function normalizeDriveUrl(string $url): string
{
    $url = trim($url);
    if (empty($url))
        return '';

    $fileId = '';

    // Formato: /file/d/FILE_ID/...
    if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        $fileId = $m[1];
    }
    // Formato: ?id=FILE_ID o &id=FILE_ID
    elseif (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) {
        $fileId = $m[1];
    }
    // Ya es una URL de lh3, devolver tal cual
    elseif (str_contains($url, 'lh3.googleusercontent.com')) {
        return $url;
    }
    // Solo el ID (sin slashes ni protocolos)
    elseif (preg_match('#^[a-zA-Z0-9_-]{10,}$#', $url)) {
        $fileId = $url;
    }
    // URL no reconocida, devolver tal cual (podría ser de otro CDN)
    else {
        return $url;
    }

    return "https://lh3.googleusercontent.com/d/{$fileId}";
}

/**
 * Procesa una fila de datos CSV/Sheets y hace UPSERT por SKU.
 * Soporta columna opcional 'cover_image_url' que normaliza automáticamente.
 */
function processRow(PDO $db, array $data, int $rowNum, int &$inserted, int &$updated, array &$errors): void
{
    $sku = $data['sku'] ?? '';

    if (empty($sku)) {
        $errors[] = "Fila {$rowNum}: SKU vacío, se omitió.";
        return;
    }

    // Sanitizar gender
    $gender = strtolower($data['gender'] ?? 'unisex');
    if (!in_array($gender, ['hombre', 'mujer', 'unisex'])) {
        $gender = 'unisex';
    }

    // Sanitizar status
    $status = strtolower($data['status'] ?? 'active');
    if (!in_array($status, ['active', 'discontinued'])) {
        $status = 'active';
    }

    // Sanitizar price
    $price = floatval(str_replace([',', '$', ' '], ['', '', ''], $data['price_suggested'] ?? '0'));

    // Normalizar cover_image_url (si viene del Sheets)
    $coverUrl = normalizeDriveUrl($data['cover_image_url'] ?? '');

    try {
        // Verificar si existe
        $exists = $db->prepare("SELECT id FROM products WHERE sku = ?");
        $exists->execute([$sku]);

        if ($exists->fetch()) {
            // UPDATE
            $stmt = $db->prepare(
                "UPDATE products SET 
                    name = COALESCE(NULLIF(?, ''), name),
                    category = COALESCE(NULLIF(?, ''), category),
                    gender = ?,
                    movement = COALESCE(NULLIF(?, ''), movement),
                    price_suggested = IF(? > 0, ?, price_suggested),
                    status = ?,
                    description = COALESCE(NULLIF(?, ''), description),
                    cover_image_url = COALESCE(NULLIF(?, ''), cover_image_url),
                    updated_at = NOW()
                 WHERE sku = ?"
            );
            $stmt->execute([
                $data['name'] ?? '',
                $data['category'] ?? '',
                $gender,
                $data['movement'] ?? '',
                $price,
                $price,
                $status,
                $data['description'] ?? '',
                $coverUrl,
                $sku,
            ]);
            $updated++;
        } else {
            // INSERT
            $stmt = $db->prepare(
                "INSERT INTO products (sku, name, category, gender, movement, price_suggested, status, description, cover_image_url) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $sku,
                $data['name'] ?? $sku,
                $data['category'] ?? '',
                $gender,
                $data['movement'] ?? '',
                $price,
                $status,
                $data['description'] ?? '',
                $coverUrl ?: null,
            ]);
            $inserted++;
        }
    } catch (\Exception $e) {
        $errors[] = "Fila {$rowNum} (SKU: {$sku}): " . $e->getMessage();
    }
}
