<?php
/**
 * Enviar catálogo por lista (público, botón en el inicio y en el catálogo).
 *   POST /enviar-catalogo/extraer      body JSON {"text": "..."} → códigos visibles encontrados + sus fotos
 *   GET  /enviar-catalogo/foto/{id}    la foto (=s1600) servida desde este dominio, para poder
 *                                      compartirla/copiarla desde el navegador (WhatsApp).
 * Solo lee MySQL (products + drive_index.matched_sku, el mismo vínculo que usa el visor). No cambia nada.
 * Igual que el resto del portal: solo productos con archived = 0 y archivos no ocultos.
 * Lo incluye index.php con $catalogAction = 'extract' | 'photo' (y $catalogFileId para la foto).
 */
require_once __DIR__ . '/../services/DriveIndex.php';

$db = getDB();

if (($catalogAction ?? '') === 'photo') {
    catalogSendPhoto($db, (string) ($catalogFileId ?? ''));
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token inválido. Recarga la página.']);
    exit;
}
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
$text = (string) ($input['text'] ?? '');
if (mb_strlen($text) > 50000) {
    $text = mb_substr($text, 0, 50000);
}

echo json_encode(catalogSendExtract($db, $text), JSON_UNESCAPED_UNICODE);
exit;

/** Clave para comparar códigos: sin "-", espacios, ":" ni "." y en mayúsculas (como la búsqueda). */
function catalogSendKey(string $code): string
{
    return strtoupper(skuSearchNorm($code));
}

/**
 * Saca del texto los códigos que existen en products, en el orden en que aparecen y sin repetir.
 * @return array{items: array, not_found: string[], total_tokens: int}
 */
function catalogSendExtract(PDO $db, string $text): array
{
    // Solo lo visible en el portal
    $rows = $db->query(
        "SELECT sku, name, price_suggested, cover_image_url FROM products WHERE archived = 0 ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $exact = [];
    $norm = [];
    foreach ($rows as $r) {
        $u = strtoupper($r['sku']);
        $exact[$u] ??= $r;
        $norm[catalogSendKey($r['sku'])] ??= $r;
    }
    $find = function (string $tok) use ($exact, $norm): ?array {
        if ($tok === '') return null;
        return $exact[strtoupper($tok)] ?? $norm[catalogSendKey($tok)] ?? null;
    };

    // Palabras: se corta por espacios, comas, punto y coma, barras y saltos de línea
    $tokens = preg_split('/[\s,;|\/\\\\]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $clean = [];
    foreach ($tokens as $t) {
        // Quita adornos alrededor: comillas, paréntesis, viñetas, "*", "#", punto final...
        $t = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $t);
        if ($t !== '') $clean[] = $t;
    }

    $found = [];
    $notFound = [];
    $n = count($clean);
    for ($i = 0; $i < $n; $i++) {
        $tok = $clean[$i];
        $p = $find($tok);
        $used = 1;
        // "M 8363-3" o "M: 8363-3" (prefijo de letras separado del número)
        // Solo si el número suelto no es ya un código ("y 1416-1" sigue siendo 1416-1)
        if (!$p && $i + 1 < $n && preg_match('/^\p{L}{1,4}$/u', $tok) && !$find($clean[$i + 1])) {
            $pair = $find($tok . $clean[$i + 1]);
            if ($pair) { $p = $pair; $used = 2; }
        }
        if ($p) {
            $found[$p['sku']] ??= $p;
            $i += $used - 1;
            continue;
        }
        // Parece un código (letras+números o 3+ cifras) pero no existe → avisar
        if (preg_match('/\d/', $tok) && mb_strlen($tok) >= 3 && !preg_match('/^\d{1,2}$/', $tok)
            && !preg_match('/^\$|^\d{1,3}([.,]\d{3})+$/', $tok)) {
            $notFound[strtoupper($tok)] = $tok;
        }
    }

    $indexReady = DriveIndex::isReady($db);
    $items = [];
    foreach ($found as $sku => $p) {
        $items[] = [
            'sku' => $sku,
            'name' => (string) ($p['name'] ?? ''),
            'price' => (float) ($p['price_suggested'] ?? 0),
            'photos' => catalogSendPhotos($db, $sku, (string) ($p['cover_image_url'] ?? ''), $indexReady),
        ];
    }

    return ['items' => $items, 'not_found' => array_values($notFound), 'total_tokens' => $n];
}

/**
 * Fotos de un código: la portada primero y luego las demás enlazadas (DriveIndex::filesForSku,
 * o sea drive_index.matched_sku). Solo imágenes; los videos no se envían.
 */
function catalogSendPhotos(PDO $db, string $sku, string $storedCover, bool $indexReady): array
{
    $coverId = null;
    $cover = parseStoredCover($storedCover, 400);
    if (!$cover['video'] && preg_match('#/d/([a-zA-Z0-9_-]+)#', $cover['url'], $m)) {
        $coverId = $m[1];
    }

    $photos = [];
    if ($indexReady) {
        foreach (DriveIndex::filesForSku($db, $sku)['files'] as $f) {
            if (!str_starts_with((string) ($f['mimeType'] ?? ''), 'image/')) continue;
            $photos[$f['id']] = ['id' => $f['id'], 'name' => (string) ($f['name'] ?? '')];
        }
    }
    if ($coverId) {
        $first = $photos[$coverId] ?? ['id' => $coverId, 'name' => $sku];
        unset($photos[$coverId]);
        $photos = [$coverId => $first] + $photos;
    }
    $out = [];
    foreach ($photos as $ph) {
        $ph['thumb'] = lh3Url($ph['id'], 200);
        $out[] = $ph;
    }
    return $out;
}

/** Sirve la foto (≈1600 px) desde este dominio. Solo imágenes del índice, visibles y enlazadas a un código. */
function catalogSendPhoto(PDO $db, string $fileId): void
{
    session_write_close();
    if (!preg_match('/^[a-zA-Z0-9_-]{10,}$/', $fileId)) {
        http_response_code(404);
        exit;
    }
    $known = true;
    if (DriveIndex::isReady($db)) {
        $stmt = $db->prepare(
            "SELECT 1 FROM drive_index WHERE file_id = ? AND is_folder = 0 AND is_hidden = 0
             AND mime_type LIKE 'image/%' AND matched_sku IS NOT NULL LIMIT 1"
        );
        $stmt->execute([$fileId]);
        $known = (bool) $stmt->fetchColumn();
    }
    if (!$known) {
        http_response_code(404);
        exit;
    }

    // lh3: la misma URL pública que ya usa el visor (archivo público, redimensionado)
    [$body, $type] = catalogSendFetch(lh3Url($fileId, 1600));

    if ($body === null) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo traer la foto de Drive';
        exit;
    }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: public, max-age=86400');
    echo $body;
}

/** @return array{0: ?string, 1: string} [contenido, content-type] o [null, ''] si falla o no es imagen. */
function catalogSendFetch(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if (!is_string($body) || $body === '' || $code !== 200 || !str_starts_with(strtolower($type), 'image/')) {
        return [null, ''];
    }
    return [$body, $type];
}
