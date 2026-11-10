<?php
/**
 * Ficha de producto — detalle completo por SKU.
 * Soporta búsqueda bidireccional: si el SKU es una variante (839-5V1),
 * busca primero el producto exacto y si no existe, busca el padre (839-5).
 */
$sku = $_GET['sku'] ?? '';
$db = getDB();

// Limpieza inicial: quitar sufijos de duplicados de Drive " (1)", " (2)", etc.
$sku = preg_replace('/\s*\(\d+\)\s*$/', '', $sku);
// Quitar extensiones de archivo comunes
$sku = preg_replace('/\.(jpg|jpeg|png|webp|mp4|gif)$/i', '', $sku);
$sku = trim($sku);

$stmt = $db->prepare("SELECT * FROM products WHERE sku = ?");
$stmt->execute([$sku]);
$product = $stmt->fetch();

// Si no encontró producto exacto, intentar estrategias de fallback
$originalSku = $sku;
$isVariant = false;
if (!$product) {
    // 0) Mismo código con otro separador: "/producto/M-8464-2" o "M8464-2" → "M:8464-2"
    $variants = skuNameVariants($sku);
    $stmt = $db->prepare("SELECT * FROM products WHERE sku IN (" . implode(',', array_fill(0, count($variants), '?')) . ") ORDER BY archived ASC LIMIT 1");
    $stmt->execute($variants);
    $product = $stmt->fetch();
}
if (!$product) {
    // 1) Intentar con el SKU raíz (padre)
    $rootSku = extractRootSku($sku);
    if ($rootSku !== $sku) {
        $stmt = $db->prepare("SELECT * FROM products WHERE sku = ?");
        $stmt->execute([$rootSku]);
        $product = $stmt->fetch();
        $isVariant = true;
    }
}

if (!$product) {
    // 2) Intentar con extensiones comunes (archivo de Drive sin extensión → DB con extensión)
    $extensions = ['.jpg', '.JPG', '.jpeg', '.png', '.mp4', '.webp'];
    foreach ($extensions as $ext) {
        $stmt = $db->prepare("SELECT * FROM products WHERE sku = ?");
        $stmt->execute([$sku . $ext]);
        $product = $stmt->fetch();
        if ($product) break;
    }
}

if (!$product) {
    // 3) Intentar LIKE match flexible (quitar guiones/espacios para comparar)
    //    Ej: Drive filename "M-8464" → DB tiene "M8464-1"
    $skuDigits = preg_replace('/[^a-zA-Z0-9]/', '', $sku);
    if (strlen($skuDigits) >= 3) {
        $stmt = $db->prepare(
            "SELECT * FROM products WHERE archived = 0 AND REPLACE(REPLACE(sku, '-', ''), '.', '') LIKE ? ORDER BY sku ASC LIMIT 1"
        );
        $stmt->execute([$skuDigits . '%']);
        $product = $stmt->fetch();
    }
}

if (!$product) {
    // 4) Último intento: búsqueda LIKE directa por SKU
    $stmt = $db->prepare(
        "SELECT * FROM products WHERE archived = 0 AND sku LIKE ? ORDER BY sku ASC LIMIT 1"
    );
    $stmt->execute([$sku . '%']);
    $product = $stmt->fetch();
}

if (!$product) {
    // 5) Buscar con guion (root sin producto propio, ej: 1555 → 1555-1)
    $stmt = $db->prepare(
        "SELECT * FROM products WHERE archived = 0 AND sku LIKE ? ORDER BY sku ASC LIMIT 1"
    );
    $stmt->execute([$sku . '-%']);
    $product = $stmt->fetch();
}

// Desactivado en el admin o fuera de la hoja maestra: no se muestra al público
if ($product && !empty($product['archived']) && !isAdminLoggedIn()) {
    $product = null;
}

if (!$product) {
    // Enlace viejo a un archivo sin código (/producto/IMG_20260530_102318.jpg): llevar a su carpeta.
    try {
        $raw = trim(rawurldecode($_GET['sku'] ?? ''));
        $stmt = $db->prepare(
            "SELECT parent_id FROM drive_index WHERE is_folder = 0 AND is_hidden = 0 AND parent_id IS NOT NULL
               AND (name = ? OR name LIKE ?) LIMIT 1"
        );
        $stmt->execute([$raw, str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $sku) . '.%']);
        $parentId = $stmt->fetchColumn();
        if ($parentId) {
            redirect('/carpeta/' . rawurlencode($parentId));
        }
    } catch (\PDOException $e) {
        // índice aún no existe: 404 normal
    }
    http_response_code(404);
    include __DIR__ . '/../../templates/404.php';
    exit;
}

// ── Cache: ETag + Cache-Control (antes de cualquier consulta extra, así el 304 sale rápido) ──
$productEtag = '"p-' . md5($product['sku'] . ($product['updated_at'] ?? '') . APP_VERSION) . '"';
header("ETag: {$productEtag}");
header('Cache-Control: private, max-age=60');
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $productEtag) {
    http_response_code(304);
    exit;
}

// Precargar portada desde caché del server (evita esperar JS)
// Upscale a =s800 para la vista de detalle (en catálogo se usa =s400)
$storedCover = parseStoredCover($product['cover_image_url'] ?? '', 800);
$serverCover = $storedCover['video'] ? ($product['cover_image_url'] ?? '') : $storedCover['url'];
if (empty($serverCover)) {
    // Archivos del SKU: índice local de Drive o, si aún no existe, la caché de búsquedas
    require_once __DIR__ . '/../services/DriveIndex.php';
    $knownFiles = null;
    if (DriveIndex::isReady($db)) {
        $knownFiles = DriveIndex::filesForSku($db, $originalSku)['files'];
    } else {
        try {
            $cacheStmt = $db->prepare(
                "SELECT files_json FROM media_search_cache
                 WHERE sku = ? AND " . freshCoverCacheSql()
            );
            $cacheStmt->execute([$originalSku]);
            $cacheRow = $cacheStmt->fetch();
            if ($cacheRow) {
                $knownFiles = json_decode($cacheRow['files_json'], true) ?: [];
            }
        } catch (Exception $e) {
            // tabla no existe aún, ignorar
        }
    }
    if ($knownFiles) {
        // Misma elección que las tarjetas (mejor imagen, sin archivos ocultos)
        $cover = extractCoverFromFiles(filterHiddenFiles($db, $knownFiles));
        if ($cover && !$cover['video']) {
            $serverCover = coverUrlAtSize($cover['url'], 800);
        }
    }
}

// ── Buscar Variantes (familia) ──
// Se busca por el dato guardado (parent_sku) Y por el código base, así la familia sale aunque
// parent_sku esté viejo: "1416-1" → 1416, 1416-1, 1416-2… (misma regla que computeParentSkuMap).
$variants = [];
$familyParent = !empty($product['parent_sku']) ? $product['parent_sku'] : extractRootSku($product['sku']);
$numericBase = null;
foreach ([$familyParent, $product['sku']] as $code) {
    if (preg_match('/^(.+)[-_]\d+$/', $code, $m) && !preg_match('/^\d{1,2}$/', $m[1])) {
        $numericBase = $m[1];
        break;
    }
}
$likeEsc = fn(string $s) => addcslashes($s, '%_\\');
$conds = ['parent_sku = ?', 'sku = ?', 'sku LIKE ?'];
$params = [$familyParent, $familyParent, $likeEsc($familyParent) . '-%'];
if ($numericBase !== null) {
    $conds[] = 'sku = ?';
    $conds[] = 'sku LIKE ?';
    $params[] = $numericBase;
    $params[] = $likeEsc($numericBase) . '-%';
}
try {
    $varStmt = $db->prepare(
        "SELECT id, sku, name, color, parent_sku, cover_image_url
         FROM products
         WHERE archived = 0 AND (" . implode(' OR ', $conds) . ")
         ORDER BY sku ASC LIMIT 200"
    );
    $varStmt->execute($params);
    $variants = $varStmt->fetchAll();
} catch (\PDOException $e) {
    error_log('Variantes de ' . $product['sku'] . ': ' . $e->getMessage());
}
// "1416-1-%" o "1416-%" también traen códigos de otra familia ("1416-1A", "1416-10B"): quedarse con los de esta
$variants = array_values(array_filter($variants, function ($v) use ($product, $familyParent, $numericBase) {
    $sku = $v['sku'];
    if ($sku === $product['sku'] || $sku === $familyParent || ($v['parent_sku'] ?? '') === $familyParent) return true;
    if ($numericBase !== null && ($sku === $numericBase || preg_match('/^' . preg_quote($numericBase, '/') . '[-_]\d+$/', $sku))) return true;
    return str_starts_with($sku, $familyParent . '-') && extractRootSku($sku) === $familyParent;
}));
// Orden natural: 1416-2 antes que 1416-10
usort($variants, fn($a, $b) => strnatcasecmp($a['sku'], $b['sku']));
require_once __DIR__ . '/../services/DriveIndex.php';
// Miniatura de cada variante: su portada (la pone DriveIndex::relink: foto propia > foto genérica
// de la familia "M8363.jpg"); si aún no tiene, la misma elección desde el índice. Nunca la de un hermano.
$indexReady = count($variants) > 1 && DriveIndex::isReady($db);
foreach ($variants as &$v) {
    $v['thumb'] = '';
    if (!empty($v['cover_image_url'])) {
        $v['thumb'] = parseStoredCover($v['cover_image_url'], 200)['url'];
    } elseif ($indexReady) {
        $imgs = array_filter(DriveIndex::filesForSku($db, $v['sku'])['files'], fn($f) => str_starts_with($f['mimeType'], 'image/'));
        if ($best = pickBestCoverFile($imgs)) $v['thumb'] = lh3Url($best['id'], 200);
    }
}
unset($v);
?>
<?php $serverCoverId = driveIdFromUrl($serverCover); ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>
        <?= e($product['sku']) ?> —
        <?= e($product['name']) ?> · Viewfinder
    </title>
    <?php
    // Vista previa al compartir el enlace (WhatsApp): foto, código y nombre
    $shareTitle = $product['sku'] . ' — ' . $product['name'];
    $shareDesc = implode(' · ', array_filter([
        $product['brand'] ?? '', $product['category'] ?? '', $product['color'] ?? '',
        !empty($product['gender']) ? ucfirst($product['gender']) : '',
    ])) ?: 'Fotos y videos del producto en el catálogo Kino';
    $shareUrl = publicBaseUrl() . '/producto/' . str_replace('%3A', ':', rawurlencode($product['sku']));
    echo shareMetaTags([
        'title' => $shareTitle,
        'description' => $shareDesc,
        'url' => $shareUrl,
        'type' => 'product',
        'image_id' => $serverCoverId ?? driveIdFromUrl($product['cover_image_url'] ?? ''),
    ]);
    ?>
    <style>body{background:#0a0a0f;color:#e8e8f0}img{max-width:100%;height:auto}</style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <?php if ($serverCover): ?>
        <link rel="preload" as="image" href="<?= e($serverCover) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="/assets/css/product.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="/assets/css/gallery-slider.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/enviar-catalogo" class="btn btn-sm btn-primary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">📤 Enviar catálogo</a>
        </div>
    </header>

    <section class="product-detail">
        <div class="container">
            <div class="breadcrumb-bar">
                <a href="/buscar" class="btn-back-catalog" id="btnBackCatalog">⬅️ Volver al catálogo</a>
                <div class="breadcrumb-path">
                    <a href="/">Inicio</a> ›
                    <a href="/buscar">Catálogo</a> ›
                    <span style="color:var(--color-text)">
                        <?= e($product['sku']) ?>
                    </span>
                </div>
            </div>

            <div class="detail-grid">
                <!-- Image -->
                <div>
                    <div class="main-image" id="mainCover" data-sku="<?= e($product['sku']) ?>" <?php if ($serverCover): ?>
                            data-cover="<?= e($serverCover) ?>" <?php endif; ?>>
                        <?php if ($serverCover): ?>
                            <img src="<?= e($serverCover) ?>" alt="<?= e($product['name']) ?>" fetchpriority="high"
                                decoding="async" style="cursor:zoom-in;" onclick="openMainLightbox()"
                                onerror="this.remove()">
                        <?php else: ?>
                            <div class="cover-skeleton"
                                style="display:flex;align-items:center;justify-content:center;height:100%;min-height:250px;background:var(--color-card-bg);border-radius:var(--radius);">
                                <div style="text-align:center;color:var(--color-text-muted);">
                                    <div class="spinner" style="display:inline-block;"></div>
                                    <div style="font-size:0.8rem;margin-top:0.5rem;">Cargando imagen…</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="main-image-info" id="mainImageInfo">
                        <span class="main-image-name" id="mainImageName"></span>
                        <button class="main-image-download" id="mainImageDownload" onclick="downloadMainImage()"<?= $serverCoverId ? '' : ' style="display:none;"' ?>>
                            ⬇️ Descargar imagen
                        </button>
                        <button class="main-image-wa" id="mainImageWaShare" onclick="shareMainImageWa()">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            WhatsApp
                        </button>
                    </div>
                    <!-- Thumbnail strip -->
                    <div class="thumb-strip" id="thumbStrip"></div>
                </div>

                <!-- Info -->
                <div class="info">
                    <span class="sku-badge">
                        <?= e($product['sku']) ?>
                    </span>
                    <h1>
                        <?= e($product['name']) ?>
                    </h1>

                    <?php include __DIR__ . '/../../templates/partials/share_whatsapp.php'; ?>

                    <!-- Meta info -->
                    <ul class="meta-list">
                        <?php if ($product['category']): ?>
                            <li><span>Categoría</span><span>
                                    <?= e($product['category']) ?>
                                </span></li>
                        <?php endif; ?>
                        <?php if ($product['gender']): ?>
                            <li><span>Género</span><span>
                                    <?= e(ucfirst($product['gender'])) ?>
                                </span></li>
                        <?php endif; ?>
                        <?php if ($product['movement']): ?>
                            <li><span>Movimiento</span><span>
                                    <?= e($product['movement']) ?>
                                </span></li>
                        <?php endif; ?>
                        <?php foreach (['brand' => 'Marca', 'color' => 'Color', 'case_material' => 'Caja', 'strap_material' => 'Pulsera'] as $col => $label): ?>
                            <?php if (!empty($product[$col])): ?>
                                <li><span><?= $label ?></span><span><?= e($product[$col]) ?></span></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>

                    <!-- Description -->
                    <?php if ($product['description']): ?>
                        <h3 style="font-size:0.9rem; color:var(--color-text-muted); margin-bottom:0.5rem;">Descripción</h3>
                        <div class="description-box" id="descriptionBox">
                            <button class="btn-copy" onclick="copyDescription()" id="copyBtn" title="Copiar descripción">
                                📋 Copiar
                            </button>
                            <p id="descriptionText">
                                <?= e($product['description']) ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <!-- Variants Section -->
                    <?php if (count($variants) > 1): ?>
                        <div class="product-variants-section">
                            <h3>Variantes Disponibles (<?= count($variants) ?>)</h3>
                            <div class="variant-strip">
                                <?php foreach ($variants as $v):
                                    $isActive = ($v['sku'] === $product['sku']);
                                    $vLabel = trim($v['color'] ?? '');
                                ?>
                                    <a href="/producto/<?= urlencode($v['sku']) ?>"
                                       class="variant-chip-link <?= $isActive ? 'active' : '' ?>"
                                       <?= $isActive ? 'aria-current="page"' : '' ?>
                                       title="<?= e($v['sku']) ?> - <?= e($v['name']) ?>">
                                        <span class="variant-thumb">
                                            <?php if ($v['thumb']): ?>
                                                <img src="<?= e($v['thumb']) ?>" alt="<?= e($v['sku']) ?>" loading="lazy"
                                                     onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'variant-noimg',textContent:'📷'}))">
                                            <?php else: ?>
                                                <span class="variant-noimg">📷</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="variant-sku"><?= e($v['sku']) ?></span>
                                        <?php if ($vLabel !== ''): ?><span class="variant-color"><?= e($vLabel) ?></span><?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Download buttons -->
                    <div class="download-section" id="downloadSection">
                        <button class="btn btn-primary" id="btnPhotos" onclick="downloadMedia('image')" disabled
                            style="opacity:0.6;">
                            📸 Cargando fotos...
                        </button>
                        <button class="btn btn-secondary" id="btnVideos" onclick="downloadMedia('video')" disabled
                            style="opacity:0.6;">
                            🎥 Cargando videos...
                        </button>
                    </div>
                    <div class="media-count" id="mediaCount"></div>
                </div>
            </div>

            <!-- Media Gallery -->
            <div class="media-gallery" id="mediaGallery" style="display:none;">
                <h2>📂 Archivos multimedia</h2>
                <div class="gallery-grid" id="galleryGrid"></div>
            </div>
        </div>

        <!-- Video Modal -->
        <div class="video-modal-overlay" id="videoModal" onclick="closeVideoModal(event)">
            <div class="video-modal-content">
                <button class="video-modal-close" onclick="closeVideoModal(event, true)">✕</button>
                <div id="videoModalPlayer"></div>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem 0.25rem;">
                    <div class="video-modal-title" id="videoModalTitle"></div>
                    <button id="videoModalDownload" onclick="downloadModalVideo(event)" style="color:var(--color-primary);font-size:0.85rem;background:none;border:1px solid var(--color-primary);border-radius:6px;padding:0.3rem 0.6rem;cursor:pointer;white-space:nowrap;">⬇️ Descargar</button>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <p>Esta es una app desarrollada por <strong>K GENIUS</strong> · Más información
                <a href="https://wa.me/573146116450" target="_blank" rel="noopener" style="color:var(--color-gold);text-decoration:underline;">escríbanos</a>
            </p>
        </div>
    </footer>

    <script src="/assets/js/lightbox.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/media_actions.js?v=<?= APP_VERSION ?>"></script>
    <script>
        const SKU = '<?= e($product['sku']) ?>';
        const SEARCH_SKU = '<?= e($originalSku) ?>'; // SKU original para búsqueda bidireccional
        const PRODUCT_NAME = '<?= e(addslashes($product['name'])) ?>';
        const SHARE_URL = <?= json_encode($shareUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
        // Botones WhatsApp/Descargar del visor grande
        window.LIGHTBOX_SHARE = { title: <?= json_encode($shareTitle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, url: SHARE_URL };
        const PRODUCT_ID = <?= intval($product['id']) ?>;
        const IS_ADMIN = <?= isAdminLoggedIn() ? 'true' : 'false' ?>;
        const IS_VARIANT = <?= $isVariant ? 'true' : 'false' ?>;
        let currentCover = '<?= e($product['cover_image_url']) ?>';
        let currentMainFileId = <?= json_encode($serverCoverId) ?>;
    </script>
    <script src="/assets/js/product_page.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/wa_batch.js?v=<?= APP_VERSION ?>"></script>

    <?php include __DIR__ . '/../../templates/partials/loading_overlay.php'; ?>
    <script src="/assets/js/gallery-slider.js?v=<?= APP_VERSION ?>"></script>
    <?php $mnavActive = ''; include __DIR__ . '/../../templates/partials/mobile_nav.php'; ?>
</body>

</html>