<?php
/**
 * Escribe columnas en la «Hoja master» con la API de Google Sheets (decisión del dueño 2026-11-20: subir el archivo
 * KYTE o SYS actualiza también la hoja). Usa la conexión de Google del admin (google_tokens; el permiso de Drive basta
 * para Sheets). La cuenta conectada debe ser Editor de la hoja.
 *
 * Solo cambia celdas cuyo valor es distinto; busca cada código en la columna SKU de la hoja en vivo (no por número de
 * fila), así una fila movida no recibe el valor de otra. Nunca agrega filas ni columnas.
 */
require_once __DIR__ . '/GoogleDriveService.php';
require_once __DIR__ . '/InventoryImport.php';

/** Texto del resultado de escribir en la Hoja master (para meta['hoja']) */
function sheetWriteNote(callable $write): string
{
    try {
        $r = $write();
        $parts = [];
        foreach ($r['columnas'] as $col => $n) $parts[] = "{$n} en {$col}";
        return '✅ Hoja master actualizada: ' . ($r['celdas'] ? implode(', ', $parts) : 'ya estaba igual, nada que cambiar') . '.';
    } catch (\Throwable $e) {
        return '⚠️ No se pudo escribir en la Hoja master: ' . $e->getMessage() . '. El visor sí quedó actualizado; puedes pegar la columna a mano.';
    }
}

class SheetWriter
{
    private const API = 'https://sheets.googleapis.com/v4/spreadsheets/';

    /**
     * $columns: encabezado de la hoja (ej. 'PRICE_SUGGESTED') => [SKU de la hoja => valor nuevo].
     * @return array{celdas:int, columnas: array<string,int>} celdas cambiadas por columna
     * @throws RuntimeException con el motivo en español
     */
    public static function writeColumns(PDO $db, array $columns): array
    {
        $sheetId = env('GOOGLE_SHEET_ID', '');
        if ($sheetId === '') throw new RuntimeException('falta GOOGLE_SHEET_ID en Railway');
        $token = (new GoogleDriveService())->getValidToken($db);
        if (!$token) throw new RuntimeException('Google no está conectado: conéctalo en Admin → Media y vuelve a subir el archivo');

        // Pestaña: la de GOOGLE_SHEET_GID o la primera
        $meta = self::call('GET', self::API . rawurlencode($sheetId) . '?fields=sheets.properties(sheetId,title)', $token);
        $gid = env('GOOGLE_SHEET_GID', '');
        $title = null;
        foreach ($meta['sheets'] ?? [] as $s) {
            if ($title === null || ($gid !== '' && (string) $s['properties']['sheetId'] === $gid)) $title = $s['properties']['title'];
            if ($gid === '' || (string) $s['properties']['sheetId'] === $gid) break;
        }
        if ($title === null) throw new RuntimeException('no encontré la pestaña de la Hoja master');
        $tab = "'" . str_replace("'", "''", $title) . "'";

        $vals = self::call('GET', self::API . rawurlencode($sheetId) . '/values/' . rawurlencode($tab . '!A1:ZZ') . '?valueRenderOption=UNFORMATTED_VALUE', $token)['values'] ?? [];
        $head = array_map(fn($h) => strtoupper(trim((string) $h)), $vals[0] ?? []);
        $skuCol = array_search('SKU', $head, true);
        if ($skuCol === false) throw new RuntimeException('la Hoja master no tiene columna SKU');

        $data = [];
        $count = [];
        foreach ($columns as $header => $values) {
            $col = array_search(strtoupper($header), $head, true);
            if ($col === false) throw new RuntimeException("la Hoja master no tiene la columna {$header}");
            $count[$header] = 0;
            for ($i = 1; $i < count($vals); $i++) {
                $sku = trim((string) ($vals[$i][$skuCol] ?? ''));
                if ($sku === '' || !array_key_exists($sku, $values) || $values[$sku] === null) continue;
                $new = $values[$sku];
                $old = $vals[$i][$col] ?? '';
                if ($old !== '' && is_numeric(str_replace(',', '', (string) $old)) && abs((float) str_replace(',', '', (string) $old) - (float) $new) < 0.005) continue;
                $data[] = ['range' => $tab . '!' . self::colLetter($col) . ($i + 1), 'values' => [[$new + 0]]];
                $count[$header]++;
            }
        }
        foreach (array_chunk($data, 2000) as $chunk) {
            self::call('POST', self::API . rawurlencode($sheetId) . '/values:batchUpdate', $token,
                ['valueInputOption' => 'RAW', 'data' => $chunk]);
        }
        return ['celdas' => count($data), 'columnas' => $count];
    }

    /**
     * Agrega al final de la Hoja master filas nuevas [{code, desc, stock, price}] (SKU, NAME, STOCK, PRICE).
     * Solo se llama desde el botón «Crear» de Admin → Inventario. Omite códigos que ya existen (clave normalizada).
     * @return array{creados:int, existentes:int, con_familia:int, sin_familia:string[]}
     */
    public static function appendProducts(PDO $db, array $rows): array
    {
        $sheetId = env('GOOGLE_SHEET_ID', '');
        if ($sheetId === '') throw new RuntimeException('falta GOOGLE_SHEET_ID en Railway');
        $token = (new GoogleDriveService())->getValidToken($db);
        if (!$token) throw new RuntimeException('Google no está conectado: conéctalo en Admin → Media');
        $meta = self::call('GET', self::API . rawurlencode($sheetId) . '?fields=sheets.properties(sheetId,title)', $token);
        $gid = env('GOOGLE_SHEET_GID', '');
        $title = null;
        foreach ($meta['sheets'] ?? [] as $s) {
            if ($title === null || ($gid !== '' && (string) $s['properties']['sheetId'] === $gid)) $title = $s['properties']['title'];
            if ($gid === '' || (string) $s['properties']['sheetId'] === $gid) break;
        }
        if ($title === null) throw new RuntimeException('no encontré la pestaña de la Hoja master');
        $tab = "'" . str_replace("'", "''", $title) . "'";
        $vals = self::call('GET', self::API . rawurlencode($sheetId) . '/values/' . rawurlencode($tab . '!A1:ZZ') . '?valueRenderOption=UNFORMATTED_VALUE', $token)['values'] ?? [];
        $head = array_map(fn($h) => strtoupper(trim((string) $h)), $vals[0] ?? []);
        $idx = ['SKU' => array_search('SKU', $head, true), 'NAME' => array_search('NAME', $head, true),
            'PRICE' => array_search('PRICE', $head, true), 'STOCK' => array_search('STOCK', $head, true)];
        if ($idx['SKU'] === false) throw new RuntimeException('la Hoja master no tiene columna SKU');
        // Columnas que se copian de un código hermano (misma familia) de la Hoja master
        $copy = [];
        foreach (['CATEGORY', 'BRAND', 'GENDER', 'MOVEMENT', 'MATERIAL DE CAJA', 'MATERIAL PULSERA'] as $h) {
            $c = array_search($h, $head, true);
            if ($c !== false) $copy[$h] = $c;
        }
        $have = $fam = [];
        foreach (array_slice($vals, 1) as $r) {
            $k = trim((string) ($r[$idx['SKU']] ?? ''));
            if ($k === '') continue;
            $have[InventoryImport::looseKey($k)] = 1;
            $fk = InventoryImport::familyKey($k);
            // el hermano que sirve de modelo: el primero de la familia que tenga categoría
            if ($fk !== '' && (!isset($fam[$fk]) || (($copy['CATEGORY'] ?? false) !== false && ($fam[$fk][$copy['CATEGORY']] ?? '') === '' && ($r[$copy['CATEGORY']] ?? '') !== ''))) $fam[$fk] = $r;
        }
        $brandOf = [];
        if (isset($copy['CATEGORY'], $copy['BRAND'])) {
            foreach (array_slice($vals, 1) as $r) {
                $c = mb_strtoupper(trim((string) ($r[$copy['CATEGORY']] ?? ''))); $b = trim((string) ($r[$copy['BRAND']] ?? ''));
                if ($c !== '' && $b !== '') $brandOf[$c][$b] = ($brandOf[$c][$b] ?? 0) + 1;
            }
        }
        $drive = [];
        try { $drive = self::fromDrivePath($db, array_column($rows, 'code')); } catch (\Throwable $e) { /* sin índice de Drive: queda vacío */ }
        $width = count($head);
        $new = [];
        $exist = $conFam = $porCarpeta = 0;
        $sinFam = [];
        foreach ($rows as $r) {
            if (isset($have[InventoryImport::looseKey($r['code'])])) { $exist++; continue; }
            $line = array_fill(0, $width, '');
            $line[$idx['SKU']] = $r['code'];
            if ($idx['NAME'] !== false) $line[$idx['NAME']] = $r['desc'];
            if ($idx['STOCK'] !== false) $line[$idx['STOCK']] = (int) $r['stock'];
            if ($idx['PRICE'] !== false && ($r['price'] ?? 0) > 0) $line[$idx['PRICE']] = $r['price'] + 0;
            $fk = InventoryImport::familyKey($r['code']);
            if ($fk !== '' && isset($fam[$fk])) {
                foreach ($copy as $c) $line[$c] = $fam[$fk][$c] ?? '';
                $conFam++;
            } else {
                $d = $drive[InventoryImport::exactKey($r['code'])] ?? null;
                if ($d && ($d['cat'] !== '' || $d['gender'] !== '')) {
                    // sin hermano en la hoja: categoría y género según la carpeta de Drive de su foto
                    if (isset($copy['CATEGORY'])) $line[$copy['CATEGORY']] = $d['cat'];
                    if (isset($copy['GENDER'])) $line[$copy['GENDER']] = $d['gender'];
                    if (isset($copy['BRAND']) && $d['cat'] !== '' && !empty($brandOf[$d['cat']])) { arsort($brandOf[$d['cat']]); $line[$copy['BRAND']] = array_key_first($brandOf[$d['cat']]); }
                    $porCarpeta++;
                } else {
                    $sinFam[] = $r['code'];
                }
            }
            $new[] = $line;
        }
        foreach (array_chunk($new, 1000) as $chunk) {
            self::call('POST', self::API . rawurlencode($sheetId) . '/values/' . rawurlencode($tab . '!A1') . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', $token, ['values' => $chunk]);
        }
        return ['creados' => count($new), 'existentes' => $exist, 'con_familia' => $conFam, 'por_carpeta' => $porCarpeta, 'sin_familia' => $sinFam];
    }

    /**
     * Para códigos sin familia: categoría y género según la carpeta de Drive donde está su foto
     * (ej. 2.CURREN/DAMA/C9095L-1.jpg → CATEGORY «CURREN», GENDER «DAMA»). @return array<exactKey, array{cat:string, gender:string}>
     */
    private static function fromDrivePath(PDO $db, array $codes): array
    {
        $out = [];
        if (!$codes) return $out;
        $gmap = ['DAMA' => 'DAMA', 'DAMAS' => 'DAMA', 'MUJER' => 'DAMA', 'HOMBRE' => 'HOMBRE', 'HOMBRES' => 'HOMBRE', 'CABALLERO' => 'HOMBRE', 'CABALLEROS' => 'HOMBRE',
            'UNISEX' => 'UNISEX', 'INFANTIL' => 'INFANTIL', 'NINOS' => 'INFANTIL', 'NIÑOS' => 'INFANTIL', 'NINAS' => 'INFANTIL', 'NIÑAS' => 'INFANTIL'];
        $folders = [];
        foreach ($db->query("SELECT file_id, name, parent_id FROM drive_index WHERE is_folder = 1")->fetchAll() as $f) $folders[$f['file_id']] = $f;
        $want = array_flip(array_map([InventoryImport::class, 'exactKey'], $codes));
        $st = $db->query("SELECT name_stem, parent_id, album_id FROM drive_index WHERE is_folder = 0");
        while ($r = $st->fetch()) {
            $k = InventoryImport::exactKey((string) $r['name_stem']);
            if (!isset($want[$k]) || isset($out[$k])) continue;
            $gender = '';
            for ($id = $r['parent_id'], $n = 0; $id && isset($folders[$id]) && $n < 12; $id = $folders[$id]['parent_id'], $n++) {
                $g = $gmap[mb_strtoupper(trim(preg_replace('/^\d+\s*\.\s*/', '', $folders[$id]['name'])))] ?? '';
                if ($g !== '') { $gender = $g; break; }
            }
            $cat = isset($folders[$r['album_id']]) ? mb_strtoupper(trim(preg_replace('/^\d+\s*\.\s*/', '', $folders[$r['album_id']]['name']))) : '';
            $out[$k] = ['cat' => $cat, 'gender' => $gender];
        }
        return $out;
    }

    private static function colLetter(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function call(string $method, string $url, string $token, ?array $body = null): array
    {
        $ch = curl_init($url);
        $headers = ["Authorization: Bearer {$token}"];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
        ] + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = is_string($res) ? (json_decode($res, true) ?: []) : [];
        if ($res === false || $code >= 400) {
            $msg = $json['error']['message'] ?? ($err ?: "HTTP {$code}");
            if ($code === 403) $msg = 'la cuenta de Google conectada no puede editar la Hoja master (compártela como Editor con esa cuenta, o vuelve a conectar Google en Admin → Media). Detalle: ' . $msg;
            if ($code === 401) $msg = 'la conexión con Google venció: vuelve a conectarla en Admin → Media';
            throw new RuntimeException($msg);
        }
        return $json;
    }
}
