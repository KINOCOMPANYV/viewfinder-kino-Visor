<?php
/**
 * Importar precios (Admin → 💲 Precios). Lee un Excel/CSV de precios (KYTE: ID, Nombre*, Código, Precio*, …;
 * o SYS: Artículo, Precio) y lo cruza con la columna PRICE_SUGGESTED de la «Hoja master».
 * Arma: lista de cambios, avisos (repetidos, faltantes) y la columna PRICE_SUGGESTED para pegar en la Hoja master.
 * «Aplicar en el visor» guarda los precios nuevos como precio editado (price_manual = 1); cuando la Hoja master
 * ya tiene ese mismo precio, el sync quita la marca sola (MasterSheet::sync) y la Hoja master vuelve a mandar.
 *
 * Código: columna Código; si está vacía (KYTE), la primera palabra del Nombre («K-001-4 RELOJ KARDOO…» → K-001-4).
 * Cruce: código exacto, luego clave normalizada («M:», «M», «M-» iguales) — InventoryImport::exactKey/looseKey.
 */
require_once __DIR__ . '/InventoryImport.php';

class PriceImport
{
    public const TYPES = [
        'cambia' => ['Precio cambia', 'El precio del archivo es distinto al PRICE_SUGGESTED de la Hoja master.', 'Se aplica al pegar la columna en la Hoja master (o con «Aplicar en el visor»).'],
        'nuevo' => ['Sin precio en Hoja master', 'La Hoja master no tenía PRICE_SUGGESTED y el archivo sí trae precio.', 'Igual que «Precio cambia».'],
        'conflicto' => ['Precios repetidos distintos', 'Varias filas del archivo caen en el mismo código con precios distintos.', 'No se cambia: corregir el archivo y volver a subirlo.'],
        'sospechoso' => ['Cambio grande', 'El precio nuevo es menos de la mitad o más del doble del actual.', 'No se cambia (puede ser un error de digitación): si es correcto, ponerlo a mano en la Hoja master.'],
        'falta_hoja' => ['Falta en Hoja master', 'El código del archivo no existe en la Hoja master.', 'Si se vende, agregarlo a la Hoja master.'],
        'falta_archivo' => ['Falta en el archivo', 'Está en la Hoja master pero no en el archivo.', 'Se deja el precio que tenía.'],
    ];

    private const HEADERS = [
        'code' => '/^(codigo|cod\.?|sku|referencia|ref\.?|articulo|item)\*?$/',
        'name' => '/^(nombre|descripcion|producto)\*?$/',
        'price' => '/^(precio|price|precio de venta|valor)\*?$/',
        'catalog' => '/^mostrar en el catalogo/',
    ];

    /** Filas del archivo → [{line, code, from_name, name, price, catalog}], origen (KYTE/SYS/otro). @throws RuntimeException */
    public static function parseItems(array $rows): array
    {
        $norm = fn($s) => trim(strtolower(strtr((string) $s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n'])));
        $map = [];
        foreach (array_slice($rows, 0, 20, true) as $hi => $header) {
            $map = [];
            foreach ($header as $i => $h) {
                $h = $norm($h);
                foreach (self::HEADERS as $field => $re) {
                    if (!isset($map[$field]) && $h !== '' && preg_match($re, $h)) { $map[$field] = $i; break; }
                }
            }
            if (isset($map['price']) && (isset($map['code']) || isset($map['name']))) break;
            $map = [];
        }
        if (!$map) throw new RuntimeException('No encontré las columnas de código (Código/SKU/Artículo) y de precio (Precio) en las primeras 20 filas.');
        $headers = array_map($norm, $rows[$hi]);
        $source = isset($map['catalog']) || in_array('nombre*', $headers, true) ? 'KYTE'
            : (preg_grep('/^exist/', $headers) || in_array('articulo', $headers, true) ? 'SYS' : 'archivo');

        $items = [];
        foreach (array_slice($rows, $hi + 1, null, true) as $ri => $r) {
            $name = isset($map['name']) ? trim((string) ($r[$map['name']] ?? '')) : '';
            $code = isset($map['code']) ? trim(str_replace("\u{00A0}", ' ', (string) ($r[$map['code']] ?? ''))) : '';
            $fromName = false;
            if ($code === '' && $name !== '') { $code = preg_split('/\s+/', $name)[0]; $fromName = true; }
            $raw = trim((string) ($r[$map['price']] ?? ''));
            if ($code === '' || $raw === '') continue;
            $items[] = ['line' => $ri + 1, 'code' => $code, 'from_name' => $fromName, 'name' => $name,
                'price' => MasterSheet::parseNumber($raw),
                'catalog' => isset($map['catalog']) ? strtoupper(trim((string) ($r[$map['catalog']] ?? ''))) : ''];
        }
        return ['items' => $items, 'source' => $source];
    }

    /** Filas de la Hoja master en orden: [{row, sku, name, price}] con PRICE_SUGGESTED. Hoja en vivo; si no, la BD. */
    public static function sheetRows(PDO $db): array
    {
        try {
            $csv = MasterSheet::fetchCsv();
            $h = fopen('php://temp', 'r+');
            fwrite($h, $csv);
            rewind($h);
            $col = array_flip(array_map(fn($x) => strtoupper(trim((string) $x)), fgetcsv($h) ?: []));
            if (!isset($col['SKU'], $col['PRICE_SUGGESTED'])) throw new RuntimeException('la Hoja master no tiene SKU / PRICE_SUGGESTED');
            $rows = [];
            for ($line = 2; ($r = fgetcsv($h)) !== false; $line++) {
                $p = trim((string) ($r[$col['PRICE_SUGGESTED']] ?? ''));
                $rows[] = ['row' => $line, 'sku' => trim((string) ($r[$col['SKU']] ?? '')), 'name' => trim((string) ($r[$col['NAME'] ?? -1] ?? '')),
                    'price' => $p === '' ? null : MasterSheet::parseNumber($p)];
            }
            fclose($h);
            return ['rows' => $rows, 'source' => 'Hoja master en vivo'];
        } catch (\Throwable $e) {
            $rows = [];
            foreach ($db->query("SELECT sheet_row, sku, name, price_sheet FROM products WHERE in_sheet = 1 ORDER BY sheet_row") as $p) {
                $rows[] = ['row' => (int) $p['sheet_row'], 'sku' => $p['sku'], 'name' => (string) $p['name'],
                    'price' => $p['price_sheet'] === null ? null : (float) $p['price_sheet']];
            }
            return ['rows' => $rows, 'source' => 'copia de la BD (no se pudo leer la Hoja master: ' . $e->getMessage() . ')'];
        }
    }

    /** Cruza archivo y Hoja master. @return array{summary, report, paste, changes} */
    public static function compare(array $items, array $sheet): array
    {
        $exact = $loose = [];
        foreach ($sheet as $i => $s) {
            if ($s['sku'] === '') continue;
            $exact[InventoryImport::exactKey($s['sku'])] ??= $i;
            $loose[InventoryImport::looseKey($s['sku'])][] = $i;
        }
        $report = [];
        $add = function (string $t, string $code, string $sku, string $name, ?float $old, ?float $new, string $note) use (&$report) {
            $report[] = ['tipo' => $t, 'codigo_archivo' => $code, 'codigo_hoja' => $sku, 'nombre' => $name,
                'precio_hoja' => $old, 'precio_archivo' => $new, 'nota' => $note];
        };

        $assign = [];
        $missing = [];
        foreach ($items as $it) {
            $hits = isset($exact[InventoryImport::exactKey($it['code'])]) ? [$exact[InventoryImport::exactKey($it['code'])]] : ($loose[InventoryImport::looseKey($it['code'])] ?? []);
            if (!$hits) { $missing[InventoryImport::looseKey($it['code'])] ??= $it; continue; }
            foreach ($hits as $i) $assign[$i][] = $it;
        }
        foreach ($missing as $it) {
            $add('falta_hoja', $it['code'], '', $it['name'], null, $it['price'], ($it['from_name'] ? 'Código tomado del nombre' : '') . ($it['catalog'] === 'S' ? ($it['from_name'] ? ' · ' : '') . 'Visible en el catálogo del archivo' : ''));
        }

        $paste = $changes = [];
        $c = ['iguales' => 0, 'cambian' => 0, 'nuevos' => 0, 'conflictos' => 0, 'falta_archivo' => 0, 'grandes' => 0];
        foreach ($sheet as $i => $s) {
            $its = $assign[$i] ?? [];
            $new = $s['price'];
            $filePrice = null; // precio tal cual viene en el archivo (columna «PRECIO KYTE»); vacío si falta o hay conflicto
            if (!$its) {
                if ($s['sku'] !== '') { $c['falta_archivo']++; $add('falta_archivo', '', $s['sku'], $s['name'], $s['price'], null, ''); }
            } else {
                // Varias filas: gana la que trae el código escrito igual en la columna Código
                $best = array_values(array_filter($its, fn($it) => !$it['from_name'] && InventoryImport::exactKey($it['code']) === InventoryImport::exactKey($s['sku'])));
                $pool = $best ?: $its;
                $prices = array_values(array_unique(array_map(fn($it) => (string) $it['price'], $pool)));
                $codes = implode(' + ', array_unique(array_column($pool, 'code')));
                if (count($prices) > 1) {
                    $c['conflictos']++;
                    $add('conflicto', $codes, $s['sku'], $s['name'], $s['price'], null, 'Precios en el archivo: ' . implode(' / ', array_map(fn($p) => priceText((float) $p), $prices)) . ' — no se cambia');
                } else {
                    $p = (float) $prices[0];
                    if ($p > 0) $filePrice = $p;
                    if ($p <= 0) {
                        // precio 0 en el archivo: no se toca
                    } elseif ($s['price'] !== null && abs($p - $s['price']) < 0.5) {
                        $c['iguales']++;
                    } else {
                        if ($s['price'] && ($p / $s['price'] > 2 || $p / $s['price'] < 0.5)) {
                            // Más del doble o menos de la mitad: probable error de digitación, no se cambia
                            $c['grandes']++;
                            $add('sospechoso', $codes, $s['sku'], $s['name'], $s['price'], $p, 'Cambio de ' . round(($p / $s['price'] - 1) * 100) . '% — no se cambia');
                        } else {
                            $type = $s['price'] === null ? 'nuevo' : 'cambia';
                            $c[$type === 'nuevo' ? 'nuevos' : 'cambian']++;
                            $add($type, $codes, $s['sku'], $s['name'], $s['price'], $p, '');
                            $new = $p;
                            $changes[$s['sku']] = $p;
                        }
                    }
                }
            }
            $paste[] = ['fila_hoja' => $s['row'], 'SKU' => $s['sku'], 'antes' => $s['price'], 'nuevo' => $new, 'archivo' => $filePrice];
        }
        $order = array_flip(array_keys(self::TYPES));
        usort($report, fn($a, $b) => [$order[$a['tipo']], $a['codigo_hoja'] ?: $a['codigo_archivo']] <=> [$order[$b['tipo']], $b['codigo_hoja'] ?: $b['codigo_archivo']]);
        return [
            'summary' => ['filas_archivo' => count($items), 'filas_hoja' => count($sheet), 'encontrados' => count($assign),
                'iguales' => $c['iguales'], 'cambian' => $c['cambian'], 'nuevos' => $c['nuevos'], 'conflictos' => $c['conflictos'],
                'grandes' => $c['grandes'], 'falta_archivo' => $c['falta_archivo'], 'falta_hoja' => count($missing)],
            'report' => $report, 'paste' => $paste, 'changes' => $changes,
        ];
    }

    /** Guarda los precios nuevos en el visor como precio editado. @return int productos actualizados */
    public static function applyToVisor(PDO $db, array $changes): int
    {
        $st = $db->prepare("UPDATE products SET price_suggested = ?, price_manual = 1, updated_at = NOW() WHERE sku = ? AND in_sheet = 1");
        $n = 0;
        $db->beginTransaction();
        foreach ($changes as $sku => $p) { $st->execute([$p, $sku]); $n += $st->rowCount(); }
        $db->commit();
        return $n;
    }

    public static function reportXlsx(array $result): string
    {
        $m = $result['meta'] ?? [];
        $readme = [['Tipo (pestaña)', 'Cantidad', 'Qué significa', 'Qué hacer']];
        $byType = [];
        foreach ($result['report'] as $r) $byType[$r['tipo']][] = $r;
        foreach (self::TYPES as $k => [$label, $what, $todo]) if (!empty($byType[$k])) $readme[] = [$label, count($byType[$k]), $what, $todo];
        $readme[] = [];
        $readme[] = ['Archivo', '', ($m['archivo'] ?? '') . ' (' . ($m['origen'] ?? '') . ')'];
        $readme[] = ['Fecha', '', $m['fecha'] ?? ''];
        $readme[] = ['Columna de la Hoja master', '', 'PRICE_SUGGESTED (precio que muestra el visor)'];
        $readme[] = ['Cómo se cruzan', '', 'Código exacto; si no está, el mismo código con o sin «M:», «M», «M-». Si la columna Código está vacía, se usa la primera palabra del Nombre.'];
        $readme[] = ['Para actualizar', '', 'Descarga «Columna PRICE_SUGGESTED para pegar» y pega «PRICE_SUGGESTED nuevo» en la columna PRICE_SUGGESTED de la Hoja master desde la fila 2.'];
        $sheets = ['Léeme' => ['rows' => $readme, 'widths' => [28, 10, 60, 60]]];
        foreach (self::TYPES as $k => [$label]) {
            if (empty($byType[$k])) continue;
            $rows = [['Código archivo', 'Código Hoja master', 'Nombre', 'PRICE_SUGGESTED antes', 'Precio archivo', 'Nota']];
            foreach ($byType[$k] as $r) $rows[] = [$r['codigo_archivo'], $r['codigo_hoja'], $r['nombre'], $r['precio_hoja'], $r['precio_archivo'], $r['nota']];
            $sheets[$label] = ['rows' => $rows, 'widths' => [18, 18, 44, 16, 16, 50]];
        }
        return XlsxWriter::build($sheets);
    }

    public static function pasteXlsx(array $result): string
    {
        $rows = [['Fila Hoja master', 'SKU', 'PRICE_SUGGESTED antes', 'PRICE_SUGGESTED nuevo (copiar esta columna)']];
        foreach ($result['paste'] as $p) $rows[] = [$p['fila_hoja'], $p['SKU'], $p['antes'], $p['nuevo']];
        return XlsxWriter::build(['PRICE_SUGGESTED' => ['rows' => $rows, 'widths' => [14, 22, 20, 40]]]);
    }

    /** Columna aparte con el precio del archivo («PRECIO KYTE»), en el orden de filas de la Hoja master; no toca PRICE_SUGGESTED */
    public static function sourceColumnXlsx(array $result): string
    {
        $title = 'PRECIO ' . strtoupper($result['meta']['origen'] ?? 'ARCHIVO');
        $rows = [['Fila Hoja master', 'SKU', 'PRICE_SUGGESTED (no cambia)', $title . ' (copiar esta columna)']];
        foreach ($result['paste'] as $p) $rows[] = [$p['fila_hoja'], $p['SKU'], $p['antes'], $p['archivo'] ?? null];
        return XlsxWriter::build([$title => ['rows' => $rows, 'widths' => [14, 22, 26, 36]]]);
    }
}
