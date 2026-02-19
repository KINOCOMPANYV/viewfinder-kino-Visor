<?php
/**
 * Funciones compartidas para importación de productos.
 * Usadas por admin_import.php y admin_sync_sheets.php.
 * 
 * Soporte para columna cover_image_url (opcional):
 *   - Acepta URLs de Drive, IDs de archivo, o cualquier URL pública de imagen
 *   - Se normaliza automáticamente al formato lh3.googleusercontent.com
 */

/**
 * Normaliza una URL/ID de Google Drive al formato lh3.googleusercontent.com
 * Acepta:
 *   - https://drive.google.com/file/d/FILE_ID/view
 *   - https://drive.google.com/uc?id=FILE_ID
 *   - https://lh3.googleusercontent.com/d/FILE_ID (sin cambios)
 *   - FILE_ID puro (cadena alfanumérica de 20-44 chars)
 *   - Cualquier otra URL pública (sin cambios)
 */
function normalizeCoverUrl(string $url): string
{
    $url = trim($url);
    if (empty($url)) return '';

    // Ya está en formato correcto
    if (str_starts_with($url, 'https://lh3.googleusercontent.com/')) {
        return $url;
    }

    // drive.google.com/file/d/FILE_ID/...
    if (preg_match('#drive\.google\.com/file/d/([a-zA-Z0-9_-]{20,})#', $url, $m)) {
        return "https://lh3.googleusercontent.com/d/{$m[1]}";
    }

    // ?id=FILE_ID o &id=FILE_ID
    if (preg_match('#[?&]id=([a-zA-Z0-9_-]{20,})#', $url, $m)) {
        return "https://lh3.googleusercontent.com/d/{$m[1]}";
    }

    // Solo el ID de Drive (20-44 chars alfanuméricos y guiones/subrayados)
    if (preg_match('/^[a-zA-Z0-9_-]{20,44}$/', $url)) {
        return "https://lh3.googleusercontent.com/d/{$url}";
    }

    // Cualquier otra URL pública (imgur, cloudinary, etc.) → dejar como está
    return $url;
}

/**
 * Procesa una fila de datos CSV/Excel y hace UPSERT por SKU.
 * Soporta la columna opcional cover_image_url.
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
    if (!in_array($gender, ['hombre', 'mujer', 'unisex'])) $gender = 'unisex';

    // Sanitizar status
    $status = strtolower($data['status'] ?? 'active');
    if (!in_array($status, ['active', 'discontinued'])) $status = 'active';

    // Sanitizar price
    $price = floatval(str_replace([',', '$', ' '], ['', '', ''], $data['price_suggested'] ?? '0'));

    // Normalizar cover_image_url si viene
    $coverUrl = '';
    if (!empty($data['cover_image_url'])) {
        $coverUrl = normalizeCoverUrl($data['cover_image_url']);
    }

    try {
        $exists = $db->prepare("SELECT id, cover_image_url FROM products WHERE sku = ?");
        $exists->execute([$sku]);
        $existing = $exists->fetch();

        if ($existing) {
            // UPDATE — cover solo si viene nuevo y no está vacío
            if (!empty($coverUrl)) {
                $stmt = $db->prepare(
                    "UPDATE products SET 
                        name = COALESCE(NULLIF(?, ''), name),
                        category = COALESCE(NULLIF(?, ''), category),
                        gender = ?,
                        movement = COALESCE(NULLIF(?, ''), movement),
                        price_suggested = IF(? > 0, ?, price_suggested),
                        status = ?,
                        description = COALESCE(NULLIF(?, ''), description),
                        cover_image_url = ?,
                        updated_at = NOW()
                     WHERE sku = ?"
                );
                $stmt->execute([
                    $data['name'] ?? '',
                    $data['category'] ?? '',
                    $gender,
                    $data['movement'] ?? '',
                    $price, $price,
                    $status,
                    $data['description'] ?? '',
                    $coverUrl,
                    $sku,
                ]);
            } else {
                $stmt = $db->prepare(
                    "UPDATE products SET 
                        name = COALESCE(NULLIF(?, ''), name),
                        category = COALESCE(NULLIF(?, ''), category),
                        gender = ?,
                        movement = COALESCE(NULLIF(?, ''), movement),
                        price_suggested = IF(? > 0, ?, price_suggested),
                        status = ?,
                        description = COALESCE(NULLIF(?, ''), description),
                        updated_at = NOW()
                     WHERE sku = ?"
                );
                $stmt->execute([
                    $data['name'] ?? '',
                    $data['category'] ?? '',
                    $gender,
                    $data['movement'] ?? '',
                    $price, $price,
                    $status,
                    $data['description'] ?? '',
                    $sku,
                ]);
            }
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
                $coverUrl,
            ]);
            $inserted++;
        }
    } catch (\Exception $e) {
        $errors[] = "Fila {$rowNum} (SKU: {$sku}): " . $e->getMessage();
    }
}
