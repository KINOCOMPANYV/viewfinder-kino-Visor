<?php
/**
 * Admin — Importar Excel (.xlsx / .csv)
 * Hace UPSERT por SKU.
 */
if (!verifyCsrf()) {
    $_SESSION['flash_error'] = 'Token CSRF inválido. Recarga la página.';
    redirect('/admin/import');
}

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash_error'] = 'Error al subir el archivo. Verifica que sea .xlsx o .csv.';
    redirect('/admin/import');
}

$file = $_FILES['excel_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'csv', 'xls'])) {
    $_SESSION['flash_error'] = 'Formato no soportado. Usa .xlsx o .csv';
    redirect('/admin/import');
}

// Columnas esperadas (en orden del template)
$expectedColumns = ['sku', 'name', 'category', 'gender', 'movement', 'price_suggested', 'status', 'description'];

$db = getDB();
$inserted = 0;
$updated = 0;
$errors = [];
$rowNum = 0;

try {
    if ($ext === 'csv') {
        // Procesar CSV
        $handle = fopen($file['tmp_name'], 'r');

        // Detectar delimitador
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        // Leer header
        $header = fgetcsv($handle, 0, $delimiter);
        $header = array_map(function ($h) {
            return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"', "'"], '', $h)));
        }, $header);

        // Mapear columnas
        $colMap = [];
        foreach ($expectedColumns as $col) {
            $idx = array_search($col, $header);
            if ($idx !== false) {
                $colMap[$col] = $idx;
            }
        }

        if (!isset($colMap['sku'])) {
            $_SESSION['flash_error'] = 'No se encontró la columna "sku" en el archivo.';
            redirect('/admin/import');
        }

        // Procesar filas
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            $data = [];
            foreach ($colMap as $col => $idx) {
                $data[$col] = isset($row[$idx]) ? trim($row[$idx]) : '';
            }
            processRow($db, $data, $rowNum, $inserted, $updated, $errors);
        }
        fclose($handle);

    } else {
        // Procesar XLSX con PhpSpreadsheet
        // Verificar que la librería existe
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (!file_exists($autoload)) {
            $_SESSION['flash_error'] = 'Dependencias no instaladas. Ejecuta: composer install';
            redirect('/admin/import');
        }
        require_once $autoload;

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (empty($rows)) {
            $_SESSION['flash_error'] = 'El archivo está vacío.';
            redirect('/admin/import');
        }

        // Primera fila = header
        $header = array_map(function ($h) {
            return strtolower(trim($h ?? ''));
        }, array_shift($rows));

        // Mapear columnas por nombre
        $colMap = [];
        foreach ($header as $colLetter => $colName) {
            if (in_array($colName, $expectedColumns)) {
                $colMap[$colName] = $colLetter;
            }
        }

        if (!isset($colMap['sku'])) {
            $_SESSION['flash_error'] = 'No se encontró la columna "sku" en el archivo. Columnas encontradas: ' . implode(', ', array_values($header));
            redirect('/admin/import');
        }

        // Procesar filas
        foreach ($rows as $row) {
            $rowNum++;
            $data = [];
            foreach ($colMap as $col => $letter) {
                $data[$col] = trim($row[$letter] ?? '');
            }
            processRow($db, $data, $rowNum, $inserted, $updated, $errors);
        }
    }

} catch (\Exception $e) {
    $_SESSION['flash_error'] = 'Error al procesar el archivo: ' . $e->getMessage();
    redirect('/admin/import');
}

// Guardar resultados en sesión
$_SESSION['import_results'] = [
    'inserted' => $inserted,
    'updated' => $updated,
    'errors' => $errors,
    'total' => $rowNum,
];

redirect('/admin/import');

// ============================================================
// Helper: procesar una fila de datos
// ============================================================
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
