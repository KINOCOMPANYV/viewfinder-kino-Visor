<?php
/**
 * Vista de Carpeta de Drive — muestra el contenido real de una carpeta.
 * GET /carpeta/{folderId}
 */
require_once __DIR__ . '/../services/DriveIndex.php';

$folderId = $_GET['folder_id'] ?? '';
if (!$folderId) {
    redirect('/');
}

$db = getDB();

$folderName = 'Carpeta';
$files = [];
$subfolders = [];
$driveAvailable = true; // false solo si hubo que ir a Drive y no hay conexión
$folderPath = []; // ruta desde la raíz, como la barra de Drive

// 1) Con índice: solo BD (una carpeta fuera del índice se ve vacía, no se consulta Drive).
// 2) Sin índice todavía (antes del primer sync): Drive en vivo como antes.
if (DriveIndex::isReady($db)) {
    $indexed = DriveIndex::folderContents($db, $folderId, env('GOOGLE_DRIVE_FOLDER_ID', ''));
    if ($indexed !== null) {
        $folderName = $indexed['name'];
        $subfolders = $indexed['subfolders'];
        $files = $indexed['files'];
        $folderPath = DriveIndex::folderPath($db, $folderId, env('GOOGLE_DRIVE_FOLDER_ID', ''));
    }
} else {
    $drive = new GoogleDriveService();
    $driveAvailable = (bool) $drive->getValidToken($db);
    if ($driveAvailable) {
        $meta = $drive->getFileMetadata($folderId, 'name');
        if (!empty($meta['name'])) {
            $folderName = $meta['name'];
        }

        foreach ($drive->listFiles($folderId)['files'] ?? [] as $item) {
            $mime = $item['mimeType'] ?? '';
            if ($mime === 'application/vnd.google-apps.folder') {
                $subfolders[] = $item;
            } elseif (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
                $files[] = $item;
            }
        }

        // Hacer públicos los archivos en paralelo
        if (!empty($files)) {
            $drive->makePublicBatch(array_column($files, 'id'));
        }
    }
}

// Carpeta que solo tiene subcarpetas (p. ej. una línea con 14 modelos): se muestran juntas las fotos
// de todas sus subcarpetas, para verlas y marcarlas sin entrar a cada una. No en la raíz (sería todo el catálogo).
$fromSubfolders = false;
$moreInSubfolders = false;
if (empty($files) && !empty($subfolders) && $folderId !== env('GOOGLE_DRIVE_FOLDER_ID', '') && DriveIndex::isReady($db)) {
    $desc = DriveIndex::descendantImages($db, $folderId, 300);
    $files = $desc['files'];
    $moreInSubfolders = $desc['more'];
    $fromSubfolders = (bool) $files;
}

// Solo se enlaza a /producto/ el archivo que tiene un producto visible (matched_sku). Un archivo sin
// código (IMG_…, WhatsApp…) abre la foto en grande: antes iba a /producto/IMG_… y salía "no encontrado".
$visibleSkus = [];
$matched = array_values(array_unique(array_filter(array_column($files, 'matchedSku'))));
if ($matched) {
    $stmt = $db->prepare("SELECT sku FROM products WHERE archived = 0 AND cover_image_url > '' AND sku IN (" . implode(',', array_fill(0, count($matched), '?')) . ")");
    $stmt->execute($matched);
    $visibleSkus = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

foreach ($files as &$item) {
    $sku = $item['matchedSku'] ?? null;
    $item['productUrl'] = ($sku !== null && isset($visibleSkus[$sku])) ? '/producto/' . rawurlencode($sku) : null;
    $item['thumb'] = lh3Url($item['id']);
    $item['isVideo'] = str_starts_with($item['mimeType'] ?? '', 'video/');
}
unset($item);

// Vista previa al compartir el enlace (WhatsApp): portada de la carpeta o su primera foto
$shareTitle = $folderName . ' — Catálogo Kino';
$shareUrl = publicBaseUrl() . '/carpeta/' . rawurlencode($folderId);
$shareImageId = null;
try {
    $stmt = $db->prepare("SELECT cover_file_id FROM drive_index WHERE file_id = ? AND is_folder = 1");
    $stmt->execute([$folderId]);
    $shareImageId = $stmt->fetchColumn() ?: null;
} catch (Exception $e) {
    // índice aún sin la columna: se usa la primera foto
}
if (!$shareImageId) {
    foreach ($files as $item) {
        if (!$item['isVideo']) { $shareImageId = $item['id']; break; }
    }
}
$shareDesc = implode(' · ', array_filter([
    $subfolders ? count($subfolders) . ' carpeta' . (count($subfolders) !== 1 ? 's' : '') : '',
    $files ? (count($files) === 1 ? '1 foto o video' : count($files) . ' fotos y videos') : '',
])) ?: 'Fotos y videos del catálogo Kino';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($folderName) ?> — Viewfinder</title>
    <?= shareMetaTags(['title' => $shareTitle, 'description' => $shareDesc, 'url' => $shareUrl, 'image_id' => $shareImageId]) ?>
    <style>body{background:#0a0a0f;color:#e8e8f0}img{max-width:100%;height:auto}</style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <a href="/enviar-catalogo" class="btn btn-sm btn-primary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">📤 Enviar fotos con lista</a>
                <a href="/" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Inicio</a>
            </div>
        </div>
    </header>

    <!-- Breadcrumb + Folder Title -->
    <div style="background:var(--color-surface); border-bottom:1px solid var(--color-border); padding: 1.5rem 0;">
        <div class="container">
            <div class="folder-breadcrumb" style="font-size:0.85rem; color:var(--color-text-muted); margin-bottom:0.5rem;">
                <a href="/" style="color:var(--color-primary);">Inicio</a>
                <?php foreach (array_slice($folderPath, 0, -1) as $crumb): ?>
                    › <a href="/carpeta/<?= urlencode($crumb['id']) ?>" style="color:var(--color-primary);"><?= e($crumb['name']) ?></a>
                <?php endforeach; ?>
                › <span><?= e($folderName) ?></span>
            </div>
            <h1 style="font-size:1.5rem; margin:0;">📂 <?= e($folderName) ?></h1>
            <p style="color:var(--color-text-muted); font-size:0.9rem; margin-top:0.25rem;">
                <?= count($subfolders) ?> subcarpeta<?= count($subfolders) !== 1 ? 's' : '' ?> · 
                <?= $fromSubfolders ? 0 : count($files) ?> archivo<?= (!$fromSubfolders && count($files) === 1) ? '' : 's' ?>
            </p>
            <div style="margin-top:0.75rem;">
                <?php include __DIR__ . '/../../templates/partials/share_whatsapp.php'; ?>
            </div>
        </div>
    </div>

    <section class="container" style="padding-top:1.5rem;">

        <!-- Subcarpetas -->
        <?php if (!empty($subfolders)): ?>
            <div class="drive-folders-section">
                <div class="section-header-landing">
                    <h2>📂 Subcarpetas</h2>
                    <span class="product-count"><?= count($subfolders) ?></span>
                </div>
                <div class="drive-folders-scroll">
                    <div class="drive-folders-grid">
                        <?php foreach ($subfolders as $sub): ?>
                            <a href="/carpeta/<?= urlencode($sub['id']) ?>" class="drive-folder-chip<?= !empty($sub['cover']) ? ' has-cover' : '' ?>" title="<?= e($sub['name']) ?>">
                                <?php if (!empty($sub['cover'])): ?>
                                    <div class="folder-cover">
                                        <img src="<?= e($sub['cover']) ?>" alt="" loading="lazy"
                                             onerror="this.parentNode.outerHTML='<div class=\'folder-icon\'>📁</div>'">
                                        <span class="folder-cover-badge">📁</span>
                                    </div>
                                <?php else: ?>
                                    <div class="folder-icon">📁</div>
                                <?php endif; ?>
                                <div class="folder-name"><?= e($sub['name']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Archivos (fotos/videos): una tarjeta por foto, en grilla, para ver y marcar cada una -->
        <?php if (!empty($files)):
            // Orden por nombre para que las variantes de una referencia queden juntas (1409-C1, 1409-C2…)
            if (!$fromSubfolders) usort($files, fn($x, $y) => strnatcasecmp($x['name'] ?? '', $y['name'] ?? ''));
            $refCount = count(array_unique(array_map(fn($f) => extractRootSku(pathinfo($f['name'] ?? '', PATHINFO_FILENAME)), $files)));
        ?>
            <div class="section-header-landing" style="margin-top:1rem;">
                <h2>🖼️ <?= $fromSubfolders ? 'Fotos de las subcarpetas' : 'Fotos' ?></h2>
                <span class="product-count"><?= count($files) ?><?= $moreInSubfolders ? '+' : '' ?> archivo<?= count($files) !== 1 ? 's' : '' ?> · <?= $refCount ?> referencia<?= $refCount !== 1 ? 's' : '' ?></span>
            </div>
            <?php if ($moreInSubfolders): ?>
                <p style="color:var(--color-text-muted); font-size:0.85rem;">Se muestran las primeras <?= count($files) ?> fotos. Para ver el resto, entra a cada subcarpeta.</p>
            <?php endif; ?>
            <div class="parent-grid" style="padding-top:1rem;">
                <?php foreach ($files as $idx => $file):
                    $code = pathinfo($file['name'] ?? '', PATHINFO_FILENAME);
                    $href = $file['productUrl'] ?? lh3Url($file['id'], 1600);
                ?>
                    <div class="parent-card search-selectable-card">
                        <a href="<?= e($href) ?>"<?= empty($file['productUrl']) ? ' target="_blank" rel="noopener"' : '' ?> style="display:block;color:inherit;text-decoration:none;">
                            <div class="card-image" style="position:relative;">
                                <?php if ($file['isVideo']): ?>
                                    <div style="position:absolute;top:8px;left:8px;background:rgba(0,0,0,0.7);color:#fff;padding:2px 8px;border-radius:4px;font-size:0.7rem;z-index:2;">🎬 Video</div>
                                <?php endif; ?>
                                <img src="<?= e($file['thumb']) ?>"
                                     alt="<?= e($code) ?>"
                                     <?= cardImgAttrs($file['thumb'], $idx < 6) ?>
                                     class="img-fade-in"
                                     onload="this.classList.add('loaded')"
                                     onerror="this.outerHTML='<div class=\'cover-placeholder\'>📷</div>'">
                            </div>
                            <div class="card-body">
                                <div class="card-sku"><?= e($code) ?></div>
                                <?php if (!empty($file['folder'])): ?>
                                    <div class="card-name" style="font-size:0.7rem; color:var(--color-text-muted);">📁 <?= e($file['folder']) ?></div>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?= mediaActionButtons($file['id'], $file['name'] ?? '', $code, $file['productUrl'] ?? '/carpeta/' . rawurlencode($folderId)) ?>
                        <?php if (!$file['isVideo']): ?>
                        <label class="search-check-footer" style="border-top: 1px solid var(--color-border); margin-top: auto;">
                            <input type="checkbox" class="search-card-check"
                                   data-sku="<?= e($code) ?>"
                                   data-file-id="<?= e($file['id']) ?>"
                                   data-is-video="0">
                            <span class="search-check-icon">✓</span>
                            <span class="search-check-text">Seleccionar</span>
                        </label>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($files) && empty($subfolders)): ?>
            <div class="empty-state fade-in" style="padding:3rem 0; text-align:center;">
                <div class="empty-icon" style="font-size:3rem;">📂</div>
                <h3 style="margin-top:1rem;">Carpeta vacía</h3>
                <p style="color:var(--color-text-muted);">Esta carpeta no contiene archivos de imagen o video.</p>
            </div>
        <?php endif; ?>

        <?php if (!$driveAvailable): ?>
            <div class="empty-state fade-in" style="padding:3rem 0; text-align:center;">
                <div class="empty-icon" style="font-size:3rem;">⚠️</div>
                <h3 style="margin-top:1rem;">Drive no conectado</h3>
                <p style="color:var(--color-text-muted);">El administrador necesita conectar Google Drive.</p>
            </div>
        <?php endif; ?>
    </section>

    <footer class="footer">
        <div class="container">
            <p>Esta es una app desarrollada por <strong>K GENIUS</strong> · Más información
                <a href="https://wa.me/573146116450" target="_blank" rel="noopener" style="color:var(--color-gold);text-decoration:underline;">escríbanos</a>
            </p>
        </div>
    </footer>

    <!-- WhatsApp Share Modal -->
    <script src="/assets/js/whatsapp_share.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/media_actions.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/wa_batch.js?v=<?= APP_VERSION ?>"></script>
    <?php include __DIR__ . '/../../templates/partials/loading_overlay.php'; ?>
    <?php $mnavActive = 'carpetas'; include __DIR__ . '/../../templates/partials/mobile_nav.php'; ?>
</body>

</html>
