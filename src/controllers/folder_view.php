<?php
/**
 * Vista de Carpeta de Drive — muestra el contenido real de una carpeta.
 * GET /carpeta/{folderId}
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

$folderId = $_GET['folder_id'] ?? '';
if (!$folderId) {
    redirect('/');
}

$db = getDB();
$drive = new GoogleDriveService();
$token = $drive->getValidToken($db);

$folderName = 'Carpeta';
$files = [];
$subfolders = [];

if ($token) {
    // Obtener nombre de la carpeta
    $metaUrl = "https://www.googleapis.com/drive/v3/files/{$folderId}?fields=name&supportsAllDrives=true";
    $ch = curl_init($metaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
    ]);
    $metaResp = curl_exec($ch);
    curl_close($ch);
    $meta = json_decode($metaResp, true);
    if (!empty($meta['name'])) {
        $folderName = $meta['name'];
    }

    // Listar contenido de la carpeta
    $result = $drive->listFiles($folderId);
    $allItems = $result['files'] ?? [];

    foreach ($allItems as $item) {
        $mime = $item['mimeType'] ?? '';
        if ($mime === 'application/vnd.google-apps.folder') {
            $subfolders[] = $item;
        } elseif (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            // Generar thumbnail URL
            $item['thumb'] = "https://lh3.googleusercontent.com/d/{$item['id']}=s400";
            $item['isVideo'] = str_starts_with($mime, 'video/');
            $files[] = $item;
        }
    }

    // Hacer públicos los archivos en paralelo
    if (!empty($files)) {
        $drive->makePublicBatch(array_column($files, 'id'));
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($folderName) ?> — Viewfinder</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}img{max-width:100%;height:auto}</style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://lh3.googleusercontent.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/" class="btn btn-sm btn-secondary" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Inicio</a>
        </div>
    </header>

    <!-- Breadcrumb + Folder Title -->
    <div style="background:var(--color-surface); border-bottom:1px solid var(--color-border); padding: 1.5rem 0;">
        <div class="container">
            <div style="font-size:0.85rem; color:var(--color-text-muted); margin-bottom:0.5rem;">
                <a href="/" style="color:var(--color-primary);">Inicio</a> / <span><?= e($folderName) ?></span>
            </div>
            <h1 style="font-size:1.5rem; margin:0;">📂 <?= e($folderName) ?></h1>
            <p style="color:var(--color-text-muted); font-size:0.9rem; margin-top:0.25rem;">
                <?= count($subfolders) ?> subcarpeta<?= count($subfolders) !== 1 ? 's' : '' ?> · 
                <?= count($files) ?> archivo<?= count($files) !== 1 ? 's' : '' ?>
            </p>
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
                            <a href="/carpeta/<?= urlencode($sub['id']) ?>" class="drive-folder-chip" title="<?= e($sub['name']) ?>">
                                <div class="folder-icon">📁</div>
                                <div class="folder-name"><?= e($sub['name']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Archivos (fotos/videos) -->
        <?php if (!empty($files)): 
            // Agrupar archivos por SKU raíz para mostrar variantes juntas
            $groupedFiles = [];
            foreach ($files as $f) {
                $fileSku = pathinfo($f['name'] ?? '', PATHINFO_FILENAME);
                $rootSku = extractRootSku($fileSku);
                if (!isset($groupedFiles[$rootSku])) {
                    $groupedFiles[$rootSku] = ['parent' => $f, 'children' => []];
                } else {
                    $groupedFiles[$rootSku]['children'][] = $f;
                }
            }
        ?>
            <div class="section-header-landing" style="margin-top:1rem;">
                <h2>🖼️ Archivos</h2>
                <span class="product-count"><?= count($groupedFiles) ?> referencia<?= count($groupedFiles) !== 1 ? 's' : '' ?> (<?= count($files) ?> archivos)</span>
            </div>
            <div class="parent-grid" style="padding-top:1rem;">
                <?php $idx = 0; foreach ($groupedFiles as $rootSku => $group): 
                    $file = $group['parent'];
                    $children = $group['children'];
                    $childCount = count($children);
                ?>
                    <div class="parent-card">
                        <a href="/producto/<?= urlencode($rootSku) ?>" style="text-decoration:none;color:inherit;display:block;">
                            <div class="card-image" style="position:relative;">
                                <?php if ($file['isVideo']): ?>
                                    <div style="position:absolute;top:8px;left:8px;background:rgba(0,0,0,0.7);color:#fff;padding:2px 8px;border-radius:4px;font-size:0.7rem;z-index:2;">🎬 Video</div>
                                <?php endif; ?>
                                <img src="<?= e($file['thumb']) ?>" 
                                     alt="<?= e($file['name']) ?>" 
                                     loading="<?= $idx < 6 ? 'eager' : 'lazy' ?>"
                                     class="img-fade-in"
                                     onload="this.classList.add('loaded')"
                                     onerror="this.outerHTML='<div class=\'cover-placeholder\'>📷</div>'"
                                     style="width:100%;height:100%;object-fit:cover;">
                            </div>
                            <div class="card-body">
                                <div class="card-sku" style="font-size:0.75rem; font-weight:600;"><?= e($rootSku) ?></div>
                                <div class="card-name" style="font-size:0.7rem; word-break:break-all; color:var(--color-text-muted);"><?= e($file['name']) ?></div>
                                
                                <!-- Children thumbnails -->
                                <?php if ($childCount > 0): ?>
                                    <div class="children-row" style="margin-top:0.75rem;">
                                        <?php
                                        $showMax = 4;
                                        $visibleChildren = array_slice($children, 0, $showMax);
                                        foreach ($visibleChildren as $child):
                                        ?>
                                            <div class="child-thumb" title="<?= e(pathinfo($child['name'] ?? '', PATHINFO_FILENAME)) ?>">
                                                <img src="<?= e($child['thumb']) ?>" alt="<?= e($child['name']) ?>" loading="lazy" onerror="this.outerHTML='<span class=\'child-placeholder\'>📷</span>'">
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($childCount > $showMax): ?>
                                            <div class="child-more">+<?= $childCount - $showMax ?></div>
                                        <?php endif; ?>
                                        <span class="children-label"><?= $childCount ?> variante<?= $childCount > 1 ? 's' : '' ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
                        <div class="card-body" style="padding-top:0;">
                            <div class="card-actions" style="margin-top:0.5rem; justify-content:space-between;">
                                <a href="/api/download/<?= e($file['id']) ?>" class="btn-ver" style="flex:1;text-align:center;font-size:0.7rem;padding:0.4rem;">
                                    ⬇️ Original
                                </a>
                                <a href="<?= e($file['webViewLink'] ?? '#') ?>" target="_blank" rel="noopener" class="btn-ver" style="font-size:0.7rem;padding:0.4rem;" title="Abrir en Drive">
                                    🔗 Drive
                                </a>
                            </div>
                        </div>
                    </div>
                <?php $idx++; endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($files) && empty($subfolders)): ?>
            <div class="empty-state fade-in" style="padding:3rem 0; text-align:center;">
                <div class="empty-icon" style="font-size:3rem;">📂</div>
                <h3 style="margin-top:1rem;">Carpeta vacía</h3>
                <p style="color:var(--color-text-muted);">Esta carpeta no contiene archivos de imagen o video.</p>
            </div>
        <?php endif; ?>

        <?php if (!$token): ?>
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
    <?php include __DIR__ . '/../../templates/partials/loading_overlay.php'; ?>
</body>

</html>
