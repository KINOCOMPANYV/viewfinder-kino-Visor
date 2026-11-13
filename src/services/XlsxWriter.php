<?php
/**
 * Excel (.xlsx) mínimo sin librerías: varias pestañas, primera fila en negrita y fija, filtro,
 * anchos de columna. Números como números, el resto como texto. ZIP armado a mano (zlib).
 * Se usa para descargas que Excel en español abre bien (un CSV con comas sale todo en la columna A).
 */
class XlsxWriter
{
    /**
     * @param array<string, array{rows: array<int, array>, widths?: int[], header?: bool}> $sheets nombre → filas
     * @return string contenido del .xlsx
     */
    public static function build(array $sheets): string
    {
        $files = [];
        $sheetXml = $rels = $ct = '';
        $i = 0;
        foreach ($sheets as $name => $sheet) {
            $i++;
            $safe = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/u', ' ', (string) $name), 0, 31);
            $sheetXml .= '<sheet name="' . self::esc($safe) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $files["xl/worksheets/sheet{$i}.xml"] = self::sheet($sheet['rows'], $sheet['widths'] ?? [], $sheet['header'] ?? true);
        }
        $n = $i + 1;
        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ct . '</Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheetXml . '</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        // Estilos: 0 normal, 1 negrita con fondo (encabezado), 2 texto con ajuste de línea, 3 número con miles
        $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        return self::zip($files);
    }

    private static function sheet(array $rows, array $widths, bool $header): string
    {
        $maxCol = 1;
        $data = '';
        foreach (array_values($rows) as $r => $row) {
            $row = array_values($row);
            $maxCol = max($maxCol, count($row));
            $data .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $v) {
                if ($v === null || $v === '') continue;
                $ref = self::col($c) . ($r + 1);
                $style = ($header && $r === 0) ? 1 : (is_int($v) || is_float($v) ? 3 : (mb_strlen((string) $v) > 60 ? 2 : 0));
                if ((is_int($v) || is_float($v)) && !($header && $r === 0)) {
                    $data .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
                } else {
                    $data .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
                }
            }
            $data .= '</row>';
        }
        $cols = '';
        foreach ($widths as $c => $w) $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (int) $w . '" customWidth="1"/>';
        $last = self::col($maxCol - 1) . max(1, count($rows));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . ($header ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '')
            . ($cols ? "<cols>{$cols}</cols>" : '') . "<sheetData>{$data}</sheetData>"
            . ($header && count($rows) > 1 ? '<autoFilter ref="A1:' . $last . '"/>' : '') . '</worksheet>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function zip(array $files): string
    {
        $out = $central = '';
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $deflated = gzdeflate($content, 6);
            $head = pack('vvvvvVVVvv', 20, 0x0800, 8, 0, 0x21, $crc, strlen($deflated), strlen($content), strlen($name), 0);
            $central .= "PK\x01\x02" . pack('v', 20) . $head . pack('vvvVV', 0, 0, 0, 0, strlen($out)) . $name;
            $out .= "PK\x03\x04" . $head . $name . $deflated;
        }
        return $out . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
    }
}
