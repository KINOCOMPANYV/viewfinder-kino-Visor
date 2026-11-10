<?php
/**
 * Catálogo por enlace (etapa I). Rutas (index.php):
 *   POST /c/crear                {name, items:[{id,sku}], contact?}       → {code, url, token}
 *   POST /c/{code}/actualizar    X-Manage-Token + {items?, name?, contact?} → {ok}
 *   POST /c/{code}/desactivar    X-Manage-Token                             → {ok}  (?activar=1 lo vuelve a abrir)
 *   GET  /c/{code}               página liviana con las fotos en orden
 * La página muestra la foto vigente: si un archivo ya no está (borrado u oculto), usa la portada actual de su código.
 * El token de gestión solo lo tiene el dispositivo que creó el enlace (Mi selección); en la BD va su sha256.
 */
$db = getDB();
$action = $sharedAction ?? 'view';
$code = $sharedCode ?? '';

$cleanItems = function ($raw): array {
    $out = [];
    $seen = [];
    foreach (is_array($raw) ? $raw : [] as $it) {
        $id = is_array($it) ? (string) ($it['id'] ?? '') : '';
        if (!preg_match('/^[A-Za-z0-9_-]{10,100}$/', $id) || isset($seen[$id])) continue;
        $seen[$id] = true;
        $out[] = ['id' => $id, 'sku' => mb_substr(trim((string) ($it['sku'] ?? '')), 0, 60)];
        if (count($out) >= 300) break;
    }
    return $out;
};
$clean = fn($v, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, $max);

if ($action === 'create') {
    checkRateLimit(10);
    $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $items = $cleanItems($in['items'] ?? []);
    if (!$items) jsonResponse(['error' => 'No hay fotos para compartir.'], 400);
    $name = $clean($in['name'] ?? '', 80) ?: 'Catálogo';
    $contact = $clean($in['contact'] ?? '', 120) ?: null;
    $token = bin2hex(random_bytes(16));
    for ($try = 0; $try < 5; $try++) {
        $code = substr(strtr(base64_encode(random_bytes(9)), '+/=', 'xyz'), 0, 10);
        try {
            $db->prepare("INSERT INTO shared_catalogs (code, name, items, contact, manage_hash) VALUES (?, ?, ?, ?, ?)")
               ->execute([$code, $name, json_encode($items, JSON_UNESCAPED_UNICODE), $contact, hash('sha256', $token)]);
            jsonResponse(['code' => $code, 'url' => publicBaseUrl() . '/c/' . $code, 'token' => $token]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') jsonResponse(['error' => 'No se pudo crear el enlace.'], 500);
        }
    }
    jsonResponse(['error' => 'No se pudo crear el enlace.'], 500);
}

$st = $db->prepare("SELECT * FROM shared_catalogs WHERE code = ?");
$st->execute([$code]);
$cat = $st->fetch(PDO::FETCH_ASSOC) ?: null;

if ($action === 'update' || $action === 'toggle') {
    checkRateLimit(30);
    $token = (string) ($_SERVER['HTTP_X_MANAGE_TOKEN'] ?? '');
    if (!$cat || $token === '' || !hash_equals($cat['manage_hash'], hash('sha256', $token))) {
        jsonResponse(['error' => 'Este dispositivo no puede cambiar ese enlace.'], 403);
    }
    if ($action === 'toggle') {
        $db->prepare("UPDATE shared_catalogs SET is_active = ? WHERE id = ?")->execute([($_GET['activar'] ?? '') === '1' ? 1 : 0, $cat['id']]);
        jsonResponse(['ok' => true]);
    }
    $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $items = array_key_exists('items', $in) ? $cleanItems($in['items']) : json_decode($cat['items'], true);
    if (!$items) jsonResponse(['error' => 'No hay fotos para compartir.'], 400);
    $db->prepare("UPDATE shared_catalogs SET items = ?, name = ?, contact = ? WHERE id = ?")->execute([
        json_encode($items, JSON_UNESCAPED_UNICODE),
        array_key_exists('name', $in) ? ($clean($in['name'], 80) ?: 'Catálogo') : $cat['name'],
        array_key_exists('contact', $in) ? ($clean($in['contact'], 120) ?: null) : $cat['contact'],
        $cat['id'],
    ]);
    jsonResponse(['ok' => true]);
}

// ---- Página pública ----
header('Cache-Control: private, no-cache');
if (!$cat || !$cat['is_active']) {
    http_response_code($cat ? 410 : 404);
    $photos = [];
} else {
    $db->prepare("UPDATE shared_catalogs SET views = views + 1 WHERE id = ?")->execute([$cat['id']]);
    $items = json_decode($cat['items'], true) ?: [];
    $ids = array_column($items, 'id');
    $alive = [];
    if ($ids) {
        $q = $db->prepare("SELECT file_id FROM drive_index WHERE is_folder = 0 AND is_hidden = 0 AND file_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ")");
        $q->execute($ids);
        $alive = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    }
    // Foto que ya no está → portada actual de su código (si el código sigue visible)
    $coverOf = $db->prepare("SELECT cover_image_url FROM products WHERE sku = ? AND archived = 0");
    $photos = [];
    foreach ($items as $it) {
        $fid = $it['id'];
        if (!isset($alive[$fid])) {
            $fid = null;
            if ($it['sku'] !== '') {
                $coverOf->execute([$it['sku']]);
                $cv = parseStoredCover($coverOf->fetchColumn() ?: '', 400);
                if ($cv['url'] && !$cv['video']) $fid = driveIdFromUrl($cv['url']);
            }
        }
        if ($fid) $photos[] = ['id' => $fid, 'sku' => $it['sku']];
    }
}
$title = $cat && $cat['is_active'] ? $cat['name'] : 'Catálogo no disponible';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · Catálogo Kino</title>
    <?php if ($photos): ?>
        <?= shareMetaTags(['title' => $cat['name'], 'description' => count($photos) . ' referencias · Catálogo Kino',
            'url' => publicBaseUrl() . '/c/' . $cat['code'], 'image_id' => $photos[0]['id']]) ?>
    <?php endif; ?>
    <style>body{background:#0a0a0f;color:#e8e8f0}img{max-width:100%;height:auto}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body class="sc-page">
    <main class="container sc-main">
        <?php if (!$photos): ?>
            <h1 class="sc-title">Catálogo no disponible</h1>
            <p class="sc-sub">Este enlace se desactivó o ya no tiene fotos. Pide uno nuevo a quien te lo envió.</p>
        <?php else: ?>
            <h1 class="sc-title"><?= e($cat['name']) ?></h1>
            <p class="sc-sub"><?= count($photos) ?> referencia<?= count($photos) === 1 ? '' : 's' ?></p>
            <?php if ($cat['contact']): ?><p class="sc-contact">📞 <?= e($cat['contact']) ?></p><?php endif; ?>
            <div class="sc-grid">
                <?php foreach ($photos as $i => $ph): ?>
                    <a class="sc-item" href="<?= e(lh3Url($ph['id'], 1600)) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(lh3Url($ph['id'], 400)) ?>" alt="<?= e($ph['sku']) ?>" loading="<?= $i < 6 ? 'eager' : 'lazy' ?>">
                        <?php if ($ph['sku'] !== ''): ?><span><?= e(cleanSkuDisplay($ph['sku'])) ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>

</html>
