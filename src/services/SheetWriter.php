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
