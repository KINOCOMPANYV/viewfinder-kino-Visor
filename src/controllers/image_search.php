<?php
/**
 * POST /buscar-foto (campo "foto"): devuelve en JSON los productos visibles más parecidos a la foto.
 * El navegador ya la achica a 512 px (1 "tile" del modelo = lo más barato). Solo lee la BD + 1 llamada a Jina.
 */
require_once __DIR__ . '/../services/ImageSearch.php';

if (!ImageSearch::enabled()) {
    jsonResponse(['error' => 'La búsqueda por foto no está activada.'], 503);
}
$f = $_FILES['foto'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    jsonResponse(['error' => 'No llegó la foto. Intenta de nuevo.'], 400);
}
if ($f['size'] > 5 * 1024 * 1024) {
    jsonResponse(['error' => 'La foto es muy pesada (máximo 5 MB).'], 413);
}
$bytes = (string) file_get_contents($f['tmp_name']);
$info = @getimagesizefromstring($bytes);
if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
    jsonResponse(['error' => 'El archivo no es una foto (JPG, PNG o WebP).'], 415);
}

try {
    $found = ImageSearch::search(getDB(), $bytes, 30);
} catch (\Throwable $e) {
    error_log('[buscar-foto] ' . $e->getMessage());
    jsonResponse(['error' => 'No se pudo analizar la foto ahora. Intenta en un momento.'], 502);
}
$p = $found['progress'];
$resp = ['results' => $found['results']];
// Mientras se preparan las fotos del catálogo (primera vez: unas horas) se avisa cuántas están listas
if ($p['indexadas'] < $p['fotos']) {
    $resp['progress'] = $p;
}
if (!$found['results']) {
    $fmt = fn(int $n) => number_format($n, 0, ',', '.');
    $resp['note'] = $p['indexadas'] === 0
        ? 'Estamos preparando las fotos del catálogo para comparar (0 de ' . $fmt($p['fotos']) . ' listas). Prueba de nuevo en unos minutos.'
        : 'No se encontraron productos parecidos.';
}
jsonResponse($resp);
