<?php
/**
 * Admin — ¿Por qué no sale la foto? (GET /admin/photo-check?sku=CODIGO)
 * Lee solo la BD: el producto, los archivos de drive_index cuyo nombre empieza por el código
 * (incluidos los ocultos) y, para cada uno, qué lo oculta. Responde JSON para Sincronizar.
 */
require_once __DIR__ . '/../services/DriveIndex.php';

$db = getDB();
$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
$sku = trim($_GET['sku'] ?? '');
if ($sku === '') {
    jsonResponse(['error' => 'Escribe un código.'], 400);
}
if (!DriveIndex::isReady($db)) {
    jsonResponse(['error' => 'Todavía no hay copia de Drive: pulsa "Sincronizar portadas".'], 409);
}

// Producto (acepta "M8363-2" para "M:8363-2")
$names = skuNameVariants($sku);
$stmt = $db->prepare("SELECT sku, name, is_active, in_sheet, archived, parent_sku, cover_image_url FROM products
                      WHERE sku IN (" . implode(',', array_fill(0, count($names), '?')) . ") LIMIT 1");
$stmt->execute($names);
$product = $stmt->fetch() ?: null;
$code = $product['sku'] ?? $sku;

// Carpetas apagadas a mano (admin Carpetas o álbum apagado)
$hiddenSeeds = array_flip($db->query("SELECT folder_id FROM folder_settings WHERE hidden = 1")->fetchAll(PDO::FETCH_COLUMN));
foreach ($db->query("SELECT drive_id FROM albums WHERE is_active = 0 AND auto_disabled = 0")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $hiddenSeeds[$id] = true;
}

// Archivos cuyo nombre es el código (o código + separador), con sus otras escrituras
$files = [];
$like = $db->prepare("SELECT file_id, name, mime_type, parent_id, is_hidden, is_public FROM drive_index
                      WHERE is_folder = 0 AND name_stem LIKE ? ORDER BY name ASC LIMIT 200");
foreach (skuNameVariants($code) as $variant) {
    $like->execute([addcslashes($variant, '%_\\') . '%']);
    foreach ($like->fetchAll() as $r) {
        if (fileNameStartsWithSku($r['name'], $variant)) $files[$r['file_id']] = $r;
    }
}

// Ocultos uno por uno en Admin → Media (tabla vieja drive_cache)
$fileHidden = [];
if ($files) {
    try {
        $ids = array_keys($files);
        $q = $db->prepare("SELECT file_id FROM drive_cache WHERE visible_publico = 0 AND file_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $q->execute($ids);
        $fileHidden = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    } catch (\PDOException $e) { /* tabla vieja ausente */ }
}

$out = [];
foreach ($files as $id => $f) {
    $path = DriveIndex::folderPath($db, (string) $f['parent_id'], $rootFolderId);
    $hiddenBy = null;
    $hiddenParent = $rootFolderId; // la carpeta donde está su interruptor
    foreach ($path as $i => $folder) {
        if (isset($hiddenSeeds[$folder['id']])) { $hiddenBy = $folder; $hiddenParent = $path[$i - 1]['id'] ?? $rootFolderId; break; }
    }
    $reason = null;
    if ($hiddenBy) $reason = 'Carpeta apagada en Admin → Carpetas: ' . $hiddenBy['name'];
    elseif ((int) $f['is_hidden']) $reason = 'Oculta por una carpeta superior (pulsa "Sincronizar portadas" para recalcular)';
    elseif (isset($fileHidden[$id])) $reason = 'Foto ocultada en Admin → Media';
    elseif (!(int) $f['is_public']) $reason = 'Drive no la dejó hacer pública (revisa permisos de la carpeta)';
    $out[] = [
        'id' => $id,
        'name' => $f['name'],
        'image' => str_starts_with($f['mime_type'], 'image/'),
        'path' => implode(' / ', array_column($path, 'name')),
        'switch_folder_id' => $hiddenBy ? $hiddenParent : null,
        'problem' => $reason,
    ];
}

// Lo que de verdad ve la ficha pública (misma lógica que /api/media)
$visible = filterHiddenFiles($db, DriveIndex::filesForSku($db, $code)['files']);

$lastSync = $db->query("SELECT MAX(synced_at) FROM drive_index")->fetchColumn();
$problem = null;
if (!$product) $problem = 'Ese código no está en la base (¿falta en la hoja maestra?).';
elseif ((int) $product['archived']) $problem = (int) $product['is_active'] ? 'El producto no está en la hoja maestra.' : 'El producto está desactivado en Admin → Productos.';
elseif (!$out) $problem = 'No hay ningún archivo en Drive que se llame como el código. Renombra la foto a "' . $code . '.jpg" y pulsa "Sincronizar portadas".';
elseif (!$visible) $problem = 'Hay fotos pero todas están ocultas (mira el motivo de cada una).';

jsonResponse([
    'sku' => $code,
    'product' => $product ? [
        'name' => $product['name'],
        'active' => (bool) $product['is_active'],
        'in_sheet' => (bool) $product['in_sheet'],
        'public' => !(int) $product['archived'],
        'parent_sku' => $product['parent_sku'],
        'has_cover' => !empty($product['cover_image_url']),
    ] : null,
    'files' => $out,
    'visible_count' => count($visible),
    'last_drive_sync' => $lastSync,
    'problem' => $problem,
]);
