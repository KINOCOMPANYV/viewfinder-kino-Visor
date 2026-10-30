<?php
/**
 * Hoja MAESTRA de productos — única fuente de los datos de cada producto.
 * Env: GOOGLE_SHEET_ID (la hoja, compartida como "cualquiera con el enlace") y,
 * opcional, GOOGLE_SHEET_GID (pestaña; sin él se usa la primera, "Master").
 *
 * La hoja manda en los datos (nombre, color, marca, precios, stock, materiales…).
 * Nunca toca is_active (solo el admin activa/desactiva) ni la portada.
 * Lo usan admin_sync_sheets.php (sync completo) y webhook_sheets.php (por SKU).
 */
class MasterSheet
{
    /** Encabezado de la hoja (normalizado por headerKey) → columna de products */
    private const COLUMNS = [
        'sku' => 'sku', 'codigo' => 'sku',
        'name' => 'name', 'nombre' => 'name',
        'color' => 'color',
        'category' => 'category', 'categoria' => 'category',
        'gender' => 'gender', 'genero' => 'gender',
        'movement' => 'movement', 'movimiento' => 'movement',
        'price_suggested' => 'price_suggested', 'precio_sugerido' => 'price_suggested',
        'price' => 'price_wholesale', 'precio' => 'price_wholesale',
        'stock' => 'stock',
        'description' => 'description', 'descripcion' => 'description',
        'brand' => 'brand', 'marca' => 'brand',
        'material_de_caja' => 'case_material', 'material_caja' => 'case_material',
        'material_pulsera' => 'strap_material', 'material_de_pulsera' => 'strap_material',
        'status' => 'sheet_status', 'estado' => 'sheet_status',
    ];

    /** Columnas de products que escribe la hoja (además de sku, sheet_row, sheet_hash, in_sheet) */
    public const FIELDS = [
        'name', 'color', 'category', 'gender', 'movement', 'price_suggested', 'price_wholesale',
        'stock', 'description', 'brand', 'case_material', 'strap_material', 'sheet_status',
    ];

    /** Descarga la hoja como CSV. @throws RuntimeException */
    public static function fetchCsv(int $timeout = 30): string
    {
        $sheetId = env('GOOGLE_SHEET_ID', '');
        if ($sheetId === '') {
            throw new RuntimeException('Variable GOOGLE_SHEET_ID no configurada en Railway.');
        }
        $url = "https://docs.google.com/spreadsheets/d/" . rawurlencode($sheetId) . "/export?format=csv";
        $gid = env('GOOGLE_SHEET_GID', '');
        if ($gid !== '') {
            $url .= '&gid=' . rawurlencode($gid);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'ViewfinderKino/1.0',
        ]);
        $csv = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($csv) || $csv === '') {
            $msg = $curlError ?: "Google Sheets respondió con código HTTP {$httpCode}";
            throw new RuntimeException("No se pudo descargar la hoja: {$msg}. Verifica que esté compartida como 'Cualquier persona con el enlace'.");
        }
        return $csv;
    }

    /**
     * CSV → filas por SKU (si un SKU se repite, gana la última fila).
     * @return array{rows: array<string, array>, duplicates: array<string, int>, columns: string[], skipped: int}
     * @throws RuntimeException si falta la columna SKU
     */
    public static function parse(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle) ?: [];

        $colMap = []; // índice → columna de products
        foreach ($header as $idx => $h) {
            $key = self::headerKey((string) $h);
            if (isset(self::COLUMNS[$key])) {
                $colMap[$idx] = self::COLUMNS[$key];
            }
        }
        if (!in_array('sku', $colMap, true)) {
            fclose($handle);
            throw new RuntimeException('No se encontró la columna "SKU" en la hoja. Columnas: ' . implode(', ', $header));
        }

        $rows = [];
        $duplicates = [];
        $skipped = 0;
        for ($line = 2; ($r = fgetcsv($handle)) !== false; $line++) {
            $raw = [];
            foreach ($colMap as $idx => $col) {
                $value = trim((string) ($r[$idx] ?? ''));
                $prev = $raw[$col] ?? '';
                // STATUS y estado van a la misma columna: se juntan los dos textos
                $raw[$col] = ($prev !== '' && $value !== '' && $value !== $prev) ? "{$prev} / {$value}" : ($value !== '' ? $value : $prev);
            }
            $sku = $raw['sku'] ?? '';
            if ($sku === '') {
                if (array_filter($raw)) $skipped++;
                continue;
            }
            if (isset($rows[$sku])) {
                $duplicates[$sku] = ($duplicates[$sku] ?? 1) + 1;
            }
            $rows[$sku] = self::normalizeRow($raw, $line);
        }
        fclose($handle);

        return ['rows' => $rows, 'duplicates' => $duplicates, 'columns' => array_values(array_unique($colMap)), 'skipped' => $skipped];
    }

    /**
     * Guarda filas de la hoja en products (insert o update). Solo escribe las que
     * cambiaron (sheet_hash). Marca in_sheet = 1. No toca is_active ni la portada.
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    public static function upsert(PDO $db, array $rows, bool $dryRun = false): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        $existing = [];
        foreach (array_chunk(array_keys($rows), 500) as $chunk) {
            $stmt = $db->prepare("SELECT sku, sheet_hash, in_sheet FROM products WHERE sku IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $r) {
                $existing[$r['sku']] = $r;
            }
        }

        $changed = [];
        foreach ($rows as $sku => $row) {
            $hash = self::rowHash($row);
            if (isset($existing[$sku])) {
                // La posición en la hoja no cuenta como cambio, pero un código que vuelve a la hoja sí
                if ($existing[$sku]['sheet_hash'] === $hash && (int) $existing[$sku]['in_sheet'] === 1) {
                    $stats['unchanged']++;
                    continue;
                }
                $stats['updated']++;
            } else {
                $stats['inserted']++;
            }
            $changed[] = $row + ['_hash' => $hash];
        }
        if ($dryRun || !$changed) return $stats;

        $cols = array_merge(['sku'], self::FIELDS, ['sheet_row', 'sheet_hash']);
        $updates = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", array_merge(self::FIELDS, ['sheet_row', 'sheet_hash'])));
        $rowPh = '(' . implode(',', array_fill(0, count($cols), '?')) . ', 1)';
        $db->beginTransaction();
        try {
            foreach (array_chunk($changed, 300) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    $params[] = $row['sku'];
                    foreach (self::FIELDS as $f) $params[] = $row[$f];
                    $params[] = $row['sheet_row'];
                    $params[] = $row['_hash'];
                }
                $db->prepare(
                    "INSERT INTO products (" . implode(', ', $cols) . ", in_sheet)
                     VALUES " . implode(', ', array_fill(0, count($chunk), $rowPh)) . "
                     ON DUPLICATE KEY UPDATE {$updates}, in_sheet = 1, updated_at = NOW()"
                )->execute($params);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $stats;
    }

    /** "MATERIAL DE CAJA" → "material_de_caja", "Categoría" → "categoria" */
    private static function headerKey(string $h): string
    {
        $h = trim(str_replace(["\xEF\xBB\xBF", '"', "'"], '', $h));
        $h = strtr(mb_strtolower($h), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        return trim(preg_replace('/[^a-z0-9]+/', '_', $h), '_');
    }

    private static function normalizeRow(array $raw, int $line): array
    {
        $sku = $raw['sku'];
        return [
            'sku' => $sku,
            'name' => mb_substr(($raw['name'] ?? '') !== '' ? $raw['name'] : $sku, 0, 255),
            'color' => mb_substr($raw['color'] ?? '', 0, 100),
            'category' => mb_substr($raw['category'] ?? '', 0, 100),
            'gender' => self::normalizeGender($raw['gender'] ?? ''),
            'movement' => mb_substr($raw['movement'] ?? '', 0, 100),
            'price_suggested' => self::parseNumber($raw['price_suggested'] ?? ''),
            'price_wholesale' => self::parseNumber($raw['price_wholesale'] ?? ''),
            'stock' => (int) self::parseNumber($raw['stock'] ?? ''),
            'description' => $raw['description'] ?? '',
            'brand' => mb_substr($raw['brand'] ?? '', 0, 100),
            'case_material' => mb_substr($raw['case_material'] ?? '', 0, 150),
            'strap_material' => mb_substr($raw['strap_material'] ?? '', 0, 150),
            'sheet_status' => mb_substr($raw['sheet_status'] ?? '', 0, 100),
            'sheet_row' => $line,
        ];
    }

    private static function rowHash(array $row): string
    {
        unset($row['sheet_row'], $row['_hash']);
        return md5(json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeGender(string $g): string
    {
        $g = mb_strtolower(trim($g));
        if (in_array($g, ['hombre', 'caballero', 'masculino', 'men', 'man'], true)) return 'hombre';
        if (in_array($g, ['mujer', 'dama', 'femenino', 'women', 'woman'], true)) return 'mujer';
        return 'unisex';
    }

    /** "60000", "$60.000", "60,000", "1.234,50" → número */
    public static function parseNumber(string $v): float
    {
        $v = preg_replace('/[^0-9.,-]/', '', $v);
        if ($v === '' || $v === '-') return 0.0;
        if (str_contains($v, '.') && str_contains($v, ',')) {
            // El último separador es el decimal
            $v = strrpos($v, ',') > strrpos($v, '.')
                ? str_replace(['.', ','], ['', '.'], $v)
                : str_replace(',', '', $v);
        } elseif (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $v)) {
            $v = str_replace(['.', ','], '', $v); // separador de miles
        } else {
            $v = str_replace(',', '.', $v);
        }
        return (float) $v;
    }
}
