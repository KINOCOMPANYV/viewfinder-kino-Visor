<?php
/**
 * Importar inventario (Admin → Inventario). Nombres del dueño: «Hoja master» = hoja maestra; «SYS» = sistema contable
 * de donde sale este archivo (otro sistema, «KYTE», traerá sus datos aparte). Lee el Excel/CSV de SYS
 * ("INVENTARIO GENERAL.xlsx": Artículo, Descripción, …, Exist. unidades, Precio),
 * lo cruza con la hoja maestra y arma:
 *   - el reporte de diferencias (duplicados, stock distinto, negativos, faltantes, precio distinto)
 *   - la columna STOCK nueva en el orden de filas de la hoja, para pegarla en la hoja.
 * compare() no escribe nada; applyToVisor() guarda sys_price/sys_stock y SheetWriter escribe la hoja (desde el controller).
 *
 * Cruce de códigos: primero el código exacto (sin espacios, mayúsculas), luego la clave
 * normalizada (sin espacios, el separador tras las letras iniciales no cuenta: M:8291-1 = M8291-1 = M-8291-1,
 * punto final fuera). Así NO:610-1 y NO610-1, que existen las dos en la hoja, no se mezclan.
 */
class InventoryImport
{
    /** Encabezados que se reconocen (en minúsculas, sin tildes) → campo */
    /** Tipos del reporte: nombre para la persona, qué significa y qué hacer (pantalla y Excel) */
    public const TYPES = [
        'stock_diferente' => ['Stock cambia', 'El stock SYS es distinto al que tiene la Hoja master.', 'Normal. Se corrige solo al pegar la columna STOCK en la Hoja master.'],
        'stock_negativo' => ['Stock negativo', 'SYS tiene menos de 0 unidades.', 'Se publica como 0 si marcaste esa opción. Revisa ese código en SYS.'],
        'duplicado_inventario' => ['Repetido en SYS', 'El mismo código está 2 veces en el archivo SYS, escrito distinto (espacio, guion o «:»).', 'Dejar uno solo en SYS. Para la Hoja master se usa el que se escribe igual que en ella.'],
        'duplicado_hoja' => ['Repetido en Hoja master', 'El mismo código está 2 veces en la Hoja master, escrito distinto (por ejemplo NO610-1 y NO:610-1).', 'Decidir qué fila queda. Cada fila recibe el stock del código escrito igual en SYS.'],
        'varias_filas_inventario' => ['Varias filas SYS → un código', 'Varias filas SYS caen en el mismo código de la Hoja master.', 'Se suman. Revisar si alguna sobra en SYS.'],
        'solo_hoja' => ['Falta en SYS', 'Está en la Hoja master pero no en el archivo SYS.', 'Se deja el stock que tenía. Revisar si el código está bien escrito en alguno de los dos.'],
        'solo_inventario' => ['Falta en Hoja master', 'Tiene existencias en SYS pero no existe en la Hoja master.', 'Si se vende, agregarlo a la Hoja master. Si no, ignorarlo.'],
        'precio_diferente' => ['Precio distinto', 'El Precio SYS no coincide con la columna PRICE de la Hoja master.', 'Al importar se actualiza KINO y, si se solicita, la Hoja master.'],
        'codigo_ambiguo' => ['Código por revisar', 'El código puede corresponder a varios productos y no hay coincidencia exacta.', 'No se actualiza ese código. Corrige su referencia en SYS o en la Hoja master.'],
        'precio_conflictivo' => ['Precios en conflicto', 'Varias filas del mismo producto tienen precios SYS distintos.', 'Ese producto conserva sus datos. Corrige los precios y vuelve a importar.'],
    ];

    /** Columnas del reporte con títulos claros */
    public const REPORT_COLUMNS = [
        'codigo_inventario' => 'Código SYS', 'codigo_hoja' => 'Código Hoja master', 'descripcion' => 'Descripción',
        'stock_inventario' => 'Stock SYS', 'stock_hoja' => 'Stock Hoja master (antes)', 'diferencia' => 'Diferencia',
        'precio_inventario' => 'Precio SYS', 'PRICE_hoja' => 'PRICE Hoja master', 'nota' => 'Nota',
    ];

    /** Reporte → .xlsx: pestaña «Léeme» con el resumen y una pestaña por tipo */
    public static function reportXlsx(array $result): string
    {
        $byType = [];
        foreach ($result['report'] as $r) $byType[$r['tipo']][] = $r;
        $meta = $result['meta'] ?? [];
        $readme = [['Tipo (pestaña)', 'Cantidad', 'Qué significa', 'Qué hacer']];
        foreach (self::TYPES as $k => [$label, $what, $todo]) {
            if (!empty($byType[$k])) $readme[] = [$label, count($byType[$k]), $what, $todo];
        }
        $readme[] = [];
        $readme[] = ['Archivo SYS', '', $meta['archivo'] ?? ''];
        $readme[] = ['Fecha', '', $meta['fecha'] ?? ''];
        $readme[] = ['Cómo se cruzan', '', 'Primero el código exacto; si no está, el mismo código con o sin «M:», «M», «M-».'];
        $readme[] = ['Resultado KINO', '', isset($meta['aplicado']) ? $meta['aplicado'] . ' productos actualizados' : 'Consulta el resultado de la carga'];
        $readme[] = ['Hoja master', '', $meta['hoja'] ?? 'Consulta el estado del envío en Inventario'];
        $readme[] = ['Si falta enviar a Google', '', 'Abre la última carga en Inventario y usa Reintentar envío. Las columnas para pegar quedan disponibles como alternativa manual.'];
        $sheets = ['Léeme' => ['rows' => $readme, 'widths' => [24, 10, 60, 60]]];
        $cols = self::REPORT_COLUMNS;
        foreach (self::TYPES as $k => [$label]) {
            if (empty($byType[$k])) continue;
            $keep = array_keys($cols);
            if (in_array($k, ['solo_inventario', 'duplicado_inventario'], true)) $keep = array_diff($keep, ['codigo_hoja', 'stock_hoja', 'diferencia', 'PRICE_hoja']);
            if (in_array($k, ['solo_hoja', 'duplicado_hoja'], true)) $keep = array_diff($keep, ['codigo_inventario', 'stock_inventario', 'diferencia', 'precio_inventario']);
            if ($k !== 'precio_diferente') $keep = array_diff($keep, $k === 'stock_diferente' ? ['precio_inventario', 'PRICE_hoja', 'nota'] : []);
            $rows = [array_values(array_map(fn($c) => $cols[$c], $keep))];
            foreach ($byType[$k] as $r) $rows[] = array_map(fn($c) => $r[$c], array_values($keep));
            $widths = array_map(fn($c) => $c === 'descripcion' ? 40 : ($c === 'nota' ? 60 : 16), array_values($keep));
            $sheets[$label] = ['rows' => $rows, 'widths' => $widths];
        }
        return XlsxWriter::build($sheets);
    }

    /** Columna STOCK → .xlsx (mismo orden de filas que la hoja) */
    public static function stockXlsx(array $result): string
    {
        $rows = [['Fila Hoja master', 'SKU', 'STOCK antes', 'STOCK nuevo (copiar esta columna)']];
        foreach ($result['paste'] as $p) $rows[] = [$p['fila_hoja'], $p['SKU'], $p['STOCK_actual'], $p['STOCK_nuevo']];
        return XlsxWriter::build(['STOCK' => ['rows' => $rows, 'widths' => [14, 22, 14, 34]]]);
    }

    /** Columna PRICE (Precio SYS) → .xlsx (mismo orden de filas que la hoja) */
    public static function priceXlsx(array $result): string
    {
        $rows = [['Fila Hoja master', 'SKU', 'PRICE antes', 'PRICE nuevo = Precio SYS (copiar esta columna)']];
        foreach ($result['paste'] as $p) $rows[] = [$p['fila_hoja'], $p['SKU'], $p['PRICE_actual'] ?? null, $p['PRICE_nuevo'] ?? ($p['PRICE_actual'] ?? null)];
        return XlsxWriter::build(['PRICE' => ['rows' => $rows, 'widths' => [14, 22, 14, 44]]]);
    }

    /**
     * Guarda en el visor el Precio SYS y las existencias SYS de cada producto (sys_price, sys_stock), al subir el archivo.
     * Cruce por clave normalizada con todos los productos (también «Solo Drive»); varias filas del mismo código se suman.
     * @return int productos actualizados
     */
    public static function applyToVisor(PDO $db, array $items, bool $negativeToZero = true, ?float $multiplier = null): int
    {
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        try {
            $products = $db->query('SELECT id, sku, sys_price FROM products')->fetchAll(PDO::FETCH_ASSOC);
            $matches = self::matchItems($items, $products);
            $updates = [];
            foreach ($matches['assigned'] as $i => $rows) {
                $value = self::itemTotals($rows);
                if ($value['conflict']) continue;
                $updates[] = [(int) $products[$i]['id'], $negativeToZero ? max(0, $value['stock']) : $value['stock'],
                    $value['price'] ?? $products[$i]['sys_price']];
            }
            // Una consulta por tanda: stock y precio SYS se publican junto con el precio público.
            $at = gmdate('Y-m-d H:i:s');
            foreach (array_chunk($updates, 250) as $chunk) {
                $case = str_repeat('WHEN ? THEN ? ', count($chunk));
                $params = [];
                foreach ([1, 2] as $col) foreach ($chunk as $row) array_push($params, $row[0], $row[$col]);
                $params[] = $at;
                foreach ($chunk as $row) $params[] = $row[0];
                $db->prepare("UPDATE products SET sys_stock = CASE id {$case}END, sys_price = CASE id {$case}END, sys_at = ? WHERE id IN ("
                    . implode(',', array_fill(0, count($chunk), '?')) . ')')->execute($params);
            }
            recomputePublicPrices($db, $multiplier);
            if ($ownTransaction) $db->commit();
            return count($updates);
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Resolver único para KINO y Hoja master. Una coincidencia normalizada ambigua nunca se adivina. */
    public static function matchItems(array $items, array $targets): array
    {
        $exact = $loose = [];
        foreach ($targets as $i => $target) {
            $sku = (string) $target['sku'];
            if ($sku === '') continue;
            $exact['s:' . self::exactKey($sku)] ??= $i;
            $loose['s:' . self::looseKey($sku)][] = $i;
        }
        $assigned = $missing = $ambiguous = [];
        foreach ($items as $item) {
            $i = $exact['s:' . self::exactKey($item['code'])] ?? null;
            $candidates = $loose['s:' . self::looseKey($item['code'])] ?? [];
            if ($i === null && count($candidates) === 1) $i = $candidates[0];
            if ($i !== null) $assigned[$i][] = $item;
            elseif ($candidates) $ambiguous[] = $item;
            else $missing[] = $item;
        }
        return ['assigned' => $assigned, 'missing' => $missing, 'ambiguous' => $ambiguous];
    }

    public static function itemTotals(array $items): array
    {
        $prices = [];
        foreach ($items as $item) if (($item['price'] ?? 0) > 0) $prices[number_format((float) $item['price'], 2, '.', '')] = (float) $item['price'];
        return ['stock' => (int) array_sum(array_column($items, 'stock')), 'price' => $prices ? reset($prices) : null, 'conflict' => count($prices) > 1];
    }

    private const HEADERS = [
        'code' => '/^(articulo|codigo|cod\.?|sku|referencia|ref\.?|item)$/',
        'stock' => '/(exist|stock|saldo|disponible|cantidad)/',
        'price' => '/^(precio|price|valor)/',
        'desc' => '/^(descripcion|nombre|producto|detalle)/',
    ];

    /** Archivo subido → filas (arrays de celdas como texto). @throws RuntimeException */
    public static function readFile(string $path, string $origName): array
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $head = (string) file_get_contents($path, false, null, 0, 4);
        if ($ext === 'xlsx' || $head === "PK\x03\x04") return self::readXlsx($path);
        if ($ext === 'xls') throw new RuntimeException('El formato .xls (Excel 97) no se puede leer: guárdalo como .xlsx o .csv.');
        return self::readCsv($path);
    }

    private static function readCsv(string $path): array
    {
        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        $first = strtok($text, "\n");
        $delims = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        arsort($delims);
        $d = array_key_first($delims);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $text);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $d)) !== false) $rows[] = array_map(fn($v) => (string) $v, $r);
        fclose($h);
        return $rows;
    }

    /** XLSX sin librerías: lee el ZIP a mano (zlib) y la primera hoja con SimpleXML */
    private static function readXlsx(string $path): array
    {
        $files = self::unzip($path, ['xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/sharedStrings.xml'], '#^xl/worksheets/sheet\d+\.xml$#');
        $sheetPath = null;
        if (isset($files['xl/workbook.xml'], $files['xl/_rels/workbook.xml.rels'])) {
            $wb = @simplexml_load_string($files['xl/workbook.xml']);
            $rels = @simplexml_load_string($files['xl/_rels/workbook.xml.rels']);
            if ($wb && $rels && isset($wb->sheets->sheet[0])) {
                $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($rels->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $t = ltrim((string) $rel['Target'], '/');
                        $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        if (!$sheetPath || !isset($files[$sheetPath])) {
            $sheets = array_values(array_filter(array_keys($files), fn($k) => str_starts_with($k, 'xl/worksheets/')));
            natsort($sheets);
            $sheetPath = reset($sheets) ?: null;
        }
        if (!$sheetPath) throw new RuntimeException('El Excel no tiene hojas legibles.');

        $shared = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            $sx = simplexml_load_string($files['xl/sharedStrings.xml']);
            foreach ($sx->si as $si) {
                if (isset($si->t)) { $shared[] = (string) $si->t; continue; }
                $s = '';
                foreach ($si->r as $r) $s .= (string) $r->t;
                $shared[] = $s;
            }
        }
        $sheet = simplexml_load_string($files[$sheetPath]);
        if (!$sheet) throw new RuntimeException('No se pudo leer la hoja del Excel.');
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = self::colIndex(preg_replace('/\d+/', '', (string) $c['r']));
                $type = (string) $c['t'];
                if ($type === 's') $v = $shared[(int) $c->v] ?? '';
                elseif ($type === 'inlineStr') $v = (string) $c->is->t;
                else $v = (string) $c->v;
                $cells[$col] = $v;
            }
            if (!$cells) continue;
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $v) $line[$i] = $v;
            $rows[] = $line;
        }
        return $rows;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return max(0, $n - 1);
    }

    /** Extrae del ZIP los archivos pedidos (por nombre o patrón). @throws RuntimeException */
    private static function unzip(string $path, array $names, string $pattern): array
    {
        $data = (string) file_get_contents($path);
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false) throw new RuntimeException('El archivo no es un Excel válido (.xlsx).');
        $e = unpack('vdisk/vcdisk/ventries/vtotal/Vsize/Voffset', substr($data, $eocd + 4, 16));
        $p = $e['offset'];
        $out = [];
        $expanded = 0;
        for ($i = 0; $i < $e['total']; $i++) {
            if (substr($data, $p, 4) !== "PK\x01\x02") break;
            $h = unpack('vmethod/Vtime/Vcrc/Vcsize/Vusize/vnlen/velen/vclen', substr($data, $p + 10, 24));
            $local = unpack('Voff', substr($data, $p + 42, 4))['off'];
            $name = substr($data, $p + 46, $h['nlen']);
            $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
            if (!in_array($name, $names, true) && !preg_match($pattern, $name)) continue;
            $expanded += (int) $h['usize'];
            if ($expanded > 64 * 1024 * 1024) throw new RuntimeException('El contenido del Excel es demasiado grande. Exporta únicamente el inventario, sin otras hojas ni contenido adicional.');
            $l = unpack('vnlen/velen', substr($data, $local + 26, 4));
            $raw = substr($data, $local + 30 + $l['nlen'] + $l['elen'], $h['csize']);
            $content = $h['method'] === 8 ? @gzinflate($raw, 64 * 1024 * 1024) : ($h['method'] === 0 ? $raw : false);
            if ($content === false) throw new RuntimeException("No se pudo descomprimir {$name} del Excel.");
            $out[$name] = $content;
        }
        return $out;
    }

    /**
     * Filas → artículos [{line, code, desc, stock, price}]. Busca la fila de encabezados en las primeras 20.
     * @return array{items: array, columns: array<string,string>} @throws RuntimeException
     */
    public static function parseItems(array $rows): array
    {
        $norm = fn($s) => trim(strtolower(strtr((string) $s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n'])));
        foreach (array_slice($rows, 0, 20, true) as $hi => $header) {
            $map = [];
            foreach ($header as $i => $h) {
                $h = $norm($h);
                foreach (self::HEADERS as $field => $re) {
                    if (!isset($map[$field]) && $h !== '' && preg_match($re, $h)) { $map[$field] = $i; break; }
                }
            }
            if (isset($map['code'], $map['stock'])) break;
            $map = [];
        }
        if (!$map) throw new RuntimeException('No encontré las columnas de código (Artículo/Código/SKU) y de existencias (Exist./Stock/Cantidad) en las primeras 20 filas.');

        $columns = [];
        foreach ($map as $field => $i) $columns[$field] = trim((string) $rows[$hi][$i]);
        $items = [];
        foreach (array_slice($rows, $hi + 1, null, true) as $ri => $r) {
            $code = trim(str_replace("\u{00A0}", ' ', (string) ($r[$map['code']] ?? '')));
            if ($code === '') continue;
            $stockRaw = trim((string) ($r[$map['stock']] ?? ''));
            $priceRaw = isset($map['price']) ? trim((string) ($r[$map['price']] ?? '')) : '';
            if ($stockRaw !== '' && !preg_match('/^-?\s*\d[\d\s.,]*$/uD', $stockRaw)) throw new RuntimeException('Existencias inválidas en la fila ' . ($ri + 1) . ' (' . $code . '). Corrige el archivo; no se aplicó ningún cambio.');
            $priceNumber = preg_replace('/(?:COP|\$|\s)/iu', '', $priceRaw);
            if ($priceRaw !== '' && (!preg_match('/^\d[\d.,]*$/D', $priceNumber) || MasterSheet::parseNumber($priceRaw) < 0)) throw new RuntimeException('Precio inválido en la fila ' . ($ri + 1) . ' (' . $code . '). Corrige el archivo.');
            $items[] = [
                'line' => $ri + 1,
                'code' => $code,
                'desc' => isset($map['desc']) ? trim((string) ($r[$map['desc']] ?? '')) : '',
                'stock' => $stockRaw === '' ? 0 : (int) round(MasterSheet::parseNumber($stockRaw)),
                'price' => $priceRaw === '' ? null : MasterSheet::parseNumber($priceRaw),
            ];
        }
        return ['items' => $items, 'columns' => $columns];
    }

    /** Clave de familia de un código: raíz sin el último segmento tras guion (992-1 → 992); '' si no tiene familia (raíz de 1-2 dígitos o sin guion) */
    public static function familyKey(string $code): string
    {
        $root = extractCandidateRootSku(trim($code));
        if ($root === trim($code) || preg_match('/^\d{1,2}$/', $root)) return '';
        return self::looseKey($root);
    }

    /** Clave exacta: sin espacios, mayúsculas */
    public static function exactKey(string $code): string
    {
        return strtoupper(preg_replace('/\s+/u', '', $code));
    }

    /** Clave normalizada: además el separador tras las letras iniciales no cuenta y sin punto final */
    public static function looseKey(string $code): string
    {
        $k = self::exactKey($code);
        if (preg_match('/^([A-Z]+)[:\-._]?(\d.*)$/', $k, $m)) $k = $m[1] . $m[2];
        return rtrim($k, '.');
    }

    /**
     * Filas de la hoja en su orden: [{row, sku, name, stock, price}]. Hoja en vivo; si no se puede, la BD.
     * @return array{rows: array, source: string}
     */
    public static function sheetRows(PDO $db): array
    {
        try {
            return ['rows' => self::parseSheetCsv(MasterSheet::fetchCsv()), 'source' => 'Hoja master en vivo'];
        } catch (\Throwable $e) {
            $rows = [];
            foreach ($db->query("SELECT sheet_row, sku, name, stock, price_wholesale FROM products WHERE in_sheet = 1 ORDER BY sheet_row") as $p) {
                $rows[] = ['row' => (int) $p['sheet_row'], 'sku' => $p['sku'], 'name' => (string) $p['name'],
                    'stock' => $p['stock'] === null ? null : (int) $p['stock'], 'price' => $p['price_wholesale'] === null ? null : (float) $p['price_wholesale']];
            }
            return ['rows' => $rows, 'source' => 'copia de la BD (no se pudo leer la Hoja master: ' . $e->getMessage() . ')'];
        }
    }

    /** CSV de la hoja → filas en orden. @throws RuntimeException */
    public static function parseSheetCsv(string $csv): array
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $header = array_map(fn($x) => strtoupper(trim((string) $x)), fgetcsv($h) ?: []);
        $col = array_flip($header);
        if (!isset($col['SKU'])) throw new RuntimeException('la Hoja master no tiene columna SKU');
        $rows = [];
        for ($line = 2; ($r = fgetcsv($h)) !== false; $line++) {
            $rows[] = [
                'row' => $line,
                'sku' => trim((string) ($r[$col['SKU']] ?? '')),
                'name' => trim((string) ($r[$col['NAME'] ?? -1] ?? '')),
                'stock' => isset($col['STOCK']) && trim((string) ($r[$col['STOCK']] ?? '')) !== '' ? (int) round(MasterSheet::parseNumber((string) $r[$col['STOCK']])) : null,
                'price' => isset($col['PRICE']) && trim((string) ($r[$col['PRICE']] ?? '')) !== '' ? MasterSheet::parseNumber((string) $r[$col['PRICE']]) : null,
            ];
        }
        fclose($h);
        return $rows;
    }

    /**
     * Cruza inventario y hoja.
     * @return array{summary: array, report: array, paste: array}
     */
    public static function compare(array $items, array $sheet, bool $negativeToZero = true): array
    {
        $exact = $loose = [];
        foreach ($sheet as $i => $s) {
            if ($s['sku'] === '') continue;
            $exact[self::exactKey($s['sku'])] ??= $i;
            $loose[self::looseKey($s['sku'])][] = $i;
        }
        $famIdx = [];
        foreach ($sheet as $i => $sr) { $fk = self::familyKey($sr['sku']); if ($fk !== '') $famIdx[$fk][] = $sr['sku']; }
        $report = [];
        $add = function (string $type, string $inv, string $sku, string $desc, ?int $si, ?int $ss, ?float $pi, ?float $ps, string $note) use (&$report) {
            $report[] = ['tipo' => $type, 'codigo_inventario' => $inv, 'codigo_hoja' => $sku, 'descripcion' => $desc,
                'stock_inventario' => $si, 'stock_hoja' => $ss, 'diferencia' => ($si !== null && $ss !== null) ? $si - $ss : null,
                'precio_inventario' => $pi, 'PRICE_hoja' => $ps, 'nota' => $note];
        };

        // Duplicados dentro del inventario (misma clave normalizada)
        $byLoose = [];
        foreach ($items as $it) $byLoose[self::looseKey($it['code'])][] = $it;
        $dupInv = 0;
        foreach ($byLoose as $group) {
            if (count($group) < 2) continue;
            $dupInv++;
            foreach ($group as $it) {
                $others = implode(', ', array_map(fn($o) => '«' . $o['code'] . '» (fila ' . $o['line'] . ')', array_filter($group, fn($o) => $o !== $it)));
                $add('duplicado_inventario', $it['code'], '', $it['desc'], $it['stock'], null, $it['price'], null, "Fila {$it['line']} del archivo SYS; es el mismo código que {$others}");
            }
        }
        // Duplicados en la hoja
        $dupSheet = 0;
        foreach ($loose as $idxs) {
            if (count($idxs) < 2) continue;
            $dupSheet++;
            foreach ($idxs as $i) $add('duplicado_hoja', '', $sheet[$i]['sku'], $sheet[$i]['name'], null, $sheet[$i]['stock'], null, $sheet[$i]['price'], "Fila {$sheet[$i]['row']} de la Hoja master; el mismo código está escrito de otra forma");
        }

        // Cada artículo → fila de la hoja (exacto, luego normalizado)
        $matches = self::matchItems($items, $sheet);
        $assign = $matches['assigned'];
        foreach ($matches['ambiguous'] as $it) $add('codigo_ambiguo', $it['code'], '', $it['desc'], $it['stock'], null, $it['price'], null, 'Hay varias coincidencias posibles: se conserva la información actual');
        $onlyInv = $onlyInvStock = 0;
        $crear = $newGroups = [];
        $newConflicts = 0;
        try { $cond = function_exists('condemnedIndex') ? condemnedIndex(getDB()) : []; } catch (\Throwable $e) { $cond = []; }
        foreach ($matches['missing'] as $it) {
            $onlyInv++;
            $newGroups['s:' . self::looseKey($it['code'])][] = $it;
            if ($it['stock'] != 0) {
                $onlyInvStock++;
                $add('solo_inventario', $it['code'], '', $it['desc'], $it['stock'], null, $it['price'], null, 'No está en la Hoja master' . ($it['stock'] < 0 ? ' (stock negativo)' : ''));
            }
        }
        foreach ($newGroups as $group) {
            $it = $group[0];
            $totals = self::itemTotals($group);
            $forms = array_unique(array_map(fn($r) => self::exactKey($r['code']), $group));
            if ($totals['conflict'] || count($forms) > 1) {
                $newConflicts++;
                $add($totals['conflict'] ? 'precio_conflictivo' : 'codigo_ambiguo', implode(' + ', array_column($group, 'code')), '', $it['desc'], $totals['stock'], null, null, null, 'Corrige esta referencia antes de crearla en la Hoja master');
                continue;
            }
            if ($totals['stock'] <= 0 || ($cond && isCondemnedCode($it['code'], $cond))) continue;
            $fk = self::familyKey($it['code']);
            $crear[] = ['code' => $it['code'], 'desc' => $it['desc'], 'stock' => $totals['stock'], 'price' => $totals['price'], 'familia' => $fk !== '' ? ($famIdx[$fk][0] ?? '') : ''];
        }

        $paste = [];
        $c = ['iguales' => 0, 'diferentes' => 0, 'negativos' => 0, 'solo_hoja' => 0, 'precio' => 0, 'varias' => 0, 'conflictos' => $newConflicts];
        foreach ($sheet as $i => $s) {
            $its = $assign[$i] ?? [];
            if (!$its) {
                if ($s['sku'] !== '') { $c['solo_hoja']++; $add('solo_hoja', '', $s['sku'], $s['name'], null, $s['stock'], null, $s['price'], 'No aparece en SYS: se deja el stock actual'); }
                $paste[] = ['fila_hoja' => $s['row'], 'SKU' => $s['sku'], 'STOCK_actual' => $s['stock'], 'STOCK_nuevo' => $s['stock'],
                    'PRICE_actual' => $s['price'], 'PRICE_nuevo' => $s['price']];
                continue;
            }
            $values = self::itemTotals($its);
            if ($values['conflict']) {
                $c['conflictos']++;
                $add('precio_conflictivo', implode(' + ', array_column($its, 'code')), $s['sku'], $s['name'], null, $s['stock'], null, $s['price'], 'No se actualiza este producto hasta corregir los precios distintos');
                $paste[] = ['fila_hoja' => $s['row'], 'SKU' => $s['sku'], 'STOCK_actual' => $s['stock'], 'STOCK_nuevo' => $s['stock'], 'PRICE_actual' => $s['price'], 'PRICE_nuevo' => $s['price']];
                continue;
            }
            $sum = $values['stock'];
            $codes = implode(' + ', array_column($its, 'code'));
            $price = $values['price'];
            if (count($its) > 1) { $c['varias']++; $add('varias_filas_inventario', $codes, $s['sku'], $s['name'], $sum, $s['stock'], $price, $s['price'], 'Varias filas SYS caen en este código: se suman'); }
            $new = $sum;
            if ($sum < 0) {
                $c['negativos']++;
                if ($negativeToZero) $new = 0;
                $add('stock_negativo', $codes, $s['sku'], $s['name'], $sum, $s['stock'], $price, $s['price'], $negativeToZero ? 'Stock SYS negativo: se pone 0' : 'Stock SYS negativo');
            }
            if ($new === $s['stock']) $c['iguales']++;
            else { $c['diferentes']++; $add('stock_diferente', $codes, $s['sku'], $s['name'], $new, $s['stock'], $price, $s['price'], ''); }
            if ($price !== null && $s['price'] !== null && abs($price - $s['price']) > 0.5) {
                $c['precio']++;
                $add('precio_diferente', $codes, $s['sku'], $s['name'], $sum, $s['stock'], $price, $s['price'], 'Precio SYS ≠ PRICE de la Hoja master (solo informativo)');
            }
            // PRICE de la Hoja master = Precio SYS (decisión del dueño 2026-11-20); sin precio SYS se deja el actual
            $paste[] = ['fila_hoja' => $s['row'], 'SKU' => $s['sku'], 'STOCK_actual' => $s['stock'], 'STOCK_nuevo' => $new,
                'PRICE_actual' => $s['price'], 'PRICE_nuevo' => $price !== null && $price > 0 ? $price : $s['price']];
        }

        $order = array_flip(['codigo_ambiguo', 'precio_conflictivo', 'duplicado_inventario', 'duplicado_hoja', 'varias_filas_inventario', 'stock_negativo', 'solo_hoja', 'precio_diferente', 'stock_diferente', 'solo_inventario']);
        usort($report, fn($a, $b) => [$order[$a['tipo']], $a['codigo_hoja'] ?: $a['codigo_inventario']] <=> [$order[$b['tipo']], $b['codigo_hoja'] ?: $b['codigo_inventario']]);

        return [
            'summary' => [
                'articulos' => count($items), 'filas_hoja' => count($sheet), 'encontrados' => count($assign),
                'iguales' => $c['iguales'], 'diferentes' => $c['diferentes'], 'negativos' => $c['negativos'],
                'solo_hoja' => $c['solo_hoja'], 'solo_inventario' => $onlyInv, 'solo_inventario_con_stock' => $onlyInvStock,
                'duplicados_inventario' => $dupInv, 'duplicados_hoja' => $dupSheet, 'varias_filas' => $c['varias'], 'precio_diferente' => $c['precio'],
                'conflictos' => $c['conflictos'] + count($matches['ambiguous']),
            ],
            'report' => $report,
            'paste' => $paste,
            'matched_skus' => array_values(array_map(fn($i) => $sheet[$i]['sku'], array_keys(array_filter($assign, fn($rows) => !self::itemTotals($rows)['conflict'])))),
            'crear' => array_values($crear), // códigos SYS con stock > 0 que faltan en la Hoja master (botón «Crear»)
        ];
    }
}
