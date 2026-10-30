<?php
/**
 * GET /og/{fileId}.jpg — imagen de vista previa de WhatsApp (og:image).
 *
 * WhatsApp solo muestra la foto si la puede descargar rápido, en JPEG/PNG y liviana
 * (< ~300 KB). Las de Google (lh3) a veces redirigen o pesan demasiado, así que aquí se
 * baja la foto de lh3 una vez, se deja cuadrada de OG_IMAGE_SIZE px con fondo blanco,
 * se comprime y se guarda en disco. Las siguientes visitas salen del disco.
 *
 * Solo archivos del índice local que no estén ocultos (no es un proxy abierto).
 * No llama a la API de Drive: lh3 es el CDN público de las fotos.
 */
$fileId = $matches[1];
$cacheDir = BASE_DIR . '/storage/og_cache';
$cacheFile = $cacheDir . '/' . $fileId . '.jpg';

function ogServe(string $path): void
{
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=604800');
    readfile($path);
}

if (is_file($cacheFile)) {
    ogServe($cacheFile);
    exit;
}

checkRateLimit(60); // solo lo que hay que generar (lo que ya está en disco no cuenta)

$stmt = getDB()->prepare(
    "SELECT 1 FROM drive_index WHERE file_id = ? AND is_folder = 0 AND is_hidden = 0
     AND (mime_type LIKE 'image/%' OR mime_type LIKE 'video/%')"
);
$stmt->execute([$fileId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit;
}

// Foto un poco más grande que el destino para que se vea nítida al reducirla
$source = lh3Url($fileId, OG_IMAGE_SIZE * 2);
$ch = curl_init($source);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
]);
$bytes = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$img = ($bytes !== false && $status === 200) ? @imagecreatefromstring($bytes) : false;
if (!$img) {
    // Sin poder procesarla: que WhatsApp lo intente directo con Google
    header('Location: ' . lh3Url($fileId, OG_IMAGE_SIZE), true, 302);
    exit;
}

// Lienzo cuadrado blanco con la foto centrada (los PNG transparentes quedan sobre blanco)
$size = OG_IMAGE_SIZE;
$w = imagesx($img);
$h = imagesy($img);
$scale = min($size / $w, $size / $h);
$dw = max(1, (int) round($w * $scale));
$dh = max(1, (int) round($h * $scale));
$canvas = imagecreatetruecolor($size, $size);
imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
imagecopyresampled($canvas, $img, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);
imagedestroy($img);

foreach ([82, 70, 55] as $quality) {
    ob_start();
    imagejpeg($canvas, null, $quality);
    $jpeg = ob_get_clean();
    if (strlen($jpeg) <= 250 * 1024) break;
}
imagedestroy($canvas);

// Guardar en disco (si se puede) y responder
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmp, $jpeg) !== false) {
    @rename($tmp, $cacheFile);
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($jpeg));
header('Cache-Control: public, max-age=604800');
echo $jpeg;
