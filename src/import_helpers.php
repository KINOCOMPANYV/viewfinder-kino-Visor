<?php
/**
 * Funciones compartidas para importación de productos.
 * Usadas por admin_import.php y admin_sync_sheets.php.
 */

/**
 * Procesa una fila de datos CSV/Sheets y hace UPSERT por SKU.
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
                $sku,
            ]);
            $updated++;
        } else {
            // INSERT
            $stmt = $db->prepare(
                "INSERT INTO products (sku, name, category, gender, movement, price_suggested, status, description) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
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
            ]);
            $inserted++;
        }
    } catch (\Exception $e) {
        $errors[] = "Fila {$rowNum} (SKU: {$sku}): " . $e->getMessage();
    }
}
