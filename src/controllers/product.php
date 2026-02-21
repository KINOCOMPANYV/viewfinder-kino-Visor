<?php
/**
 * Ficha de producto — detalle completo por SKU.
 * Soporta búsqueda bidireccional: si el SKU es una variante (839-5V1),
 * busca primero el producto exacto y si no existe, busca el padre (839-5).
 */
$sku = $_GET['sku'] ?? '';
$db = getDB();

$stmt = $db->prepare("SELECT * FROM products WHERE sku = ?");
$stmt->execute([$sku]);
$product = $stmt->fetch();

// Si no encontró producto exacto, intentar con el SKU raíz (padre)
$originalSku = $sku;
$isVariant = false;
if (!$product) {
    $rootSku = extractRootSku($sku);
    if ($rootSku !== $sku) {
        $stmt = $db->prepare("SELECT * FROM products WHERE sku = ?");
        $stmt->execute([$rootSku]);
        $product = $stmt->fetch();
        $isVariant = true;
    }
}

if (!$product) {
    http_response_code(404);
    include __DIR__ . '/../../templates/404.php';
    exit;
}

// Precargar portada desde caché del server (evita esperar JS)
$serverCover = $product['cover_image_url'] ?? '';
if (empty($serverCover)) {
    try {
        $cacheStmt = $db->prepare(
            "SELECT files_json FROM media_search_cache 
             WHERE sku = ? AND cached_at > NOW() - INTERVAL 10 MINUTE"
        );
        $cacheStmt->execute([$originalSku]);
        $cacheRow = $cacheStmt->fetch();
        if ($cacheRow) {
            $cachedFiles = json_decode($cacheRow['files_json'], true) ?: [];
            foreach ($cachedFiles as $cf) {
                if (str_starts_with($cf['mimeType'] ?? '', 'image/')) {
                    $thumb = $cf['thumbnailLink'] ?? '';
                    $serverCover = $thumb
                        ? preg_replace('/=s\d+/', '=s800', $thumb)
                        : "https://lh3.googleusercontent.com/d/{$cf['id']}=s800";
                    break;
                }
            }
        }
    } catch (Exception $e) {
        // tabla no existe aún, ignorar
    }
}
?>
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
    <style>body{background:#0a0a0f;color:#e8e8f0}img{max-width:100%;height:auto}</style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://lh3.googleusercontent.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <?php if ($serverCover): ?>
        <link rel="preload" as="image" href="<?= e($serverCover) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .media-gallery {
            margin-top: 2rem;
        }

        .media-gallery h2 {
            font-size: 1.1rem;
            color: var(--color-text-muted);
            margin-bottom: 1rem;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
        }

        .gallery-item {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: transform 0.2s;
        }

        .gallery-item:hover {
            transform: translateY(-2px);
        }

        .gallery-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }

        .gallery-item .video-placeholder {
            width: 100%;
            height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--color-bg);
            font-size: 3rem;
        }

        /* Video thumbnail with real preview */
        .video-thumb-wrap {
            position: relative;
            width: 100%;
            height: 150px;
            overflow: hidden;
            background: #000;
            cursor: pointer;
        }

        .video-thumb-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease, filter 0.3s ease;
        }

        .video-thumb-wrap:hover img {
            transform: scale(1.05);
            filter: brightness(0.7);
        }

        .video-play-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.35);
            transition: background 0.3s ease;
        }

        .video-thumb-wrap:hover .video-play-overlay {
            background: rgba(0, 0, 0, 0.5);
        }

        .video-play-btn {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .video-thumb-wrap:hover .video-play-btn {
            transform: scale(1.15);
            box-shadow: 0 6px 30px rgba(0, 0, 0, 0.5);
        }

        .video-play-btn svg {
            width: 22px;
            height: 22px;
            fill: #000;
            margin-left: 3px;
        }

        .video-play-label {
            font-size: 0.68rem;
            color: rgba(255, 255, 255, 0.85);
            margin-top: 0.4rem;
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.7);
        }

        /* Video badge on gallery item */
        .video-badge {
            position: absolute;
            top: 6px;
            right: 6px;
            background: rgba(248, 113, 113, 0.9);
            color: #fff;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            backdrop-filter: blur(4px);
        }

        /* Video modal / lightbox */
        .video-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .video-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .video-modal-content {
            position: relative;
            width: 90vw;
            max-width: 900px;
            aspect-ratio: 16 / 9;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .video-modal-overlay.active .video-modal-content {
            transform: scale(1);
        }

        .video-modal-content iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .video-modal-close {
            position: absolute;
            top: -40px;
            right: 0;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.2s;
        }

        .video-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .video-modal-title {
            position: absolute;
            bottom: -36px;
            left: 0;
            right: 0;
            text-align: center;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
        }

        .gallery-item .item-actions {
            padding: 0.5rem;
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .gallery-item .item-actions a,
        .gallery-item .item-actions button {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
        }

        .btn-set-cover {
            background: rgba(201, 168, 76, 0.15);
            color: var(--color-gold);
            border: 1px solid var(--color-gold);
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-set-cover:hover {
            background: var(--color-gold);
            color: #000;
        }

        .btn-set-cover.current {
            background: var(--color-gold);
            color: #000;
            cursor: default;
            opacity: 0.7;
        }

        .download-section {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .download-section .btn {
            flex: 1;
            min-width: 200px;
            text-align: center;
        }

        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: 0.5rem;
            vertical-align: middle;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 0.4;
            }

            50% {
                opacity: 0.8;
            }
        }

        @keyframes fadeSlideIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .media-count {
            font-size: 0.8rem;
            color: var(--color-text-muted);
            margin-top: 0.25rem;
        }

        /* Main image info bar */
        .main-image-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            background: var(--color-card-bg);
            border-radius: 0 0 var(--radius) var(--radius);
            margin-top: -4px;
            flex-wrap: wrap;
        }

        .main-image-name {
            font-size: 0.8rem;
            color: var(--color-text-muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
            min-width: 0;
        }

        .main-image-download {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            background: #25D366;
            color: #fff;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 16px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            font-family: var(--font);
            white-space: nowrap;
        }

        .main-image-download:hover {
            background: #1fb855;
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(37, 211, 102, 0.4);
        }

        .main-image img {
            cursor: pointer;
            transition: transform 0.2s;
        }

        .main-image img:hover {
            transform: scale(1.02);
        }

        .gallery-item img {
            cursor: pointer;
        }

        /* Active thumbnail highlight */
        .gallery-item.gallery-active {
            outline: 3px solid var(--color-primary);
            outline-offset: -3px;
            border-radius: var(--radius);
        }

        /* Back to catalog button */
        .btn-back-catalog {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: linear-gradient(135deg, #c9a84c, #e6c040, #c9a84c);
            color: #000;
            padding: 0.45rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.8rem;
            transition: all 0.3s;
            box-shadow: 0 2px 10px rgba(201, 168, 76, 0.4);
            margin-right: auto;
        }

        .btn-back-catalog:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 18px rgba(201, 168, 76, 0.6);
        }

        .breadcrumb-bar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }

        .breadcrumb-path {
            color: var(--color-text-muted);
            font-size: 0.875rem;
        }

        .breadcrumb-path a {
            color: var(--color-text-muted);
        }

        .breadcrumb-path a:hover {
            color: var(--color-primary);
        }

        /* Mobile responsive for product page */
        @media (max-width: 768px) {
            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)) !important;
                gap: 0.75rem !important;
            }

            .media-gallery h2 {
                font-size: 1rem;
            }

            .video-modal-content {
                width: 95vw;
            }

            .video-modal-close {
                top: -36px;
                right: 4px;
            }

            .video-play-btn {
                width: 40px;
                height: 40px;
            }

            .video-play-btn svg {
                width: 18px;
                height: 18px;
            }
        }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
        </div>
    </header>

    <section class="product-detail">
        <div class="container">
            <div class="breadcrumb-bar">
                <a href="/" class="btn-back-catalog">⬅️ Volver al catálogo</a>
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
                                decoding="async" style="cursor:pointer;" onclick="downloadMainImage()">
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
                        <button class="main-image-download" id="mainImageDownload" onclick="downloadMainImage()" style="display:none;">
                            ⬇️ Descargar imagen
                        </button>
                    </div>
                </div>

                <!-- Info -->
                <div class="info">
                    <span class="sku-badge">
                        <?= e($product['sku']) ?>
                    </span>
                    <h1>
                        <?= e($product['name']) ?>
                    </h1>

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
                <!-- WhatsApp toolbar for gallery -->
                <div class="search-wa-toolbar" id="galleryWaBar" style="display:none; margin-bottom:1rem;">
                    <label class="wa-toolbar-select">
                        <input type="checkbox" id="gallerySelectAll">
                        <span>Seleccionar todas</span>
                    </label>
                    <span id="gallerySelectedCount" class="wa-toolbar-count">0</span>
                    <button class="wa-toolbar-send" id="btnGalleryWaSend" disabled>
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                            <path
                                d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                        </svg>
                        Enviar por WhatsApp
                    </button>
                </div>
                <div class="gallery-grid" id="galleryGrid"></div>
            </div>
        </div>

        <!-- Video Modal -->
        <div class="video-modal-overlay" id="videoModal" onclick="closeVideoModal(event)">
            <div class="video-modal-content">
                <button class="video-modal-close" onclick="closeVideoModal(event, true)">✕</button>
                <div id="videoModalPlayer"></div>
                <div class="video-modal-title" id="videoModalTitle"></div>
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

    <script>
        const SKU = '<?= e($product['sku']) ?>';
        const SEARCH_SKU = '<?= e($originalSku) ?>'; // SKU original para búsqueda bidireccional
        const PRODUCT_NAME = '<?= e(addslashes($product['name'])) ?>';
        const PRODUCT_ID = <?= intval($product['id']) ?>;
        const IS_ADMIN = <?= isAdminLoggedIn() ? 'true' : 'false' ?>;
        const IS_VARIANT = <?= $isVariant ? 'true' : 'false' ?>;
        let currentCover = '<?= e($product['cover_image_url']) ?>';
        let mediaFiles = { images: [], videos: [] };
        let allGalleryFiles = [];

        function copyDescription() {
            const text = document.getElementById('descriptionText').innerText;
            const btn = document.getElementById('copyBtn');

            navigator.clipboard.writeText(text).then(() => {
                btn.textContent = '✅ Copiado';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.textContent = '📋 Copiar';
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                btn.textContent = '✅ Copiado';
                setTimeout(() => btn.textContent = '📋 Copiar', 2000);
            });
        }

        // Load media from Drive API (búsqueda bidireccional)
        async function loadMedia() {
            try {
                const resp = await fetch('/api/media/' + encodeURIComponent(SEARCH_SKU));
                const data = await resp.json();
                const files = data.files || [];

                files.forEach(f => {
                    const mime = f.mimeType || '';
                    if (mime.startsWith('image/')) {
                        mediaFiles.images.push(f);
                    } else if (mime.startsWith('video/')) {
                        mediaFiles.videos.push(f);
                    }
                });

                // Cargar imagen principal dinámicamente
                loadMainCover(files);

                updateButtons();
                renderGallery(files);
            } catch (e) {
                console.error('Error cargando media:', e);
                document.getElementById('btnPhotos').textContent = '📸 Sin conexión';
                document.getElementById('btnVideos').textContent = '🎥 Sin conexión';
                // Mostrar placeholder si falla
                const main = document.getElementById('mainCover');
                if (main && !main.querySelector('img')) main.innerHTML = '📷';
            }
        }

        function loadMainCover(files) {
            const main = document.getElementById('mainCover');
            if (!main) return;

            // Helper: show video as main cover with play overlay
            function showVideoCover() {
                const vid = files.find(f => (f.mimeType || '').startsWith('video/'));
                if (vid) {
                    const thumbUrl = vid.thumbnailLink
                        ? vid.thumbnailLink.replace(/=s\d+/, '=s800')
                        : '';
                    const thumbImg = thumbUrl
                        ? `<img src="${thumbUrl}" alt="${vid.name}" style="width:100%;height:100%;object-fit:cover;">`
                        : `<div style="width:100%;height:100%;background:linear-gradient(135deg,#1a1a2e,#0a0a0f);display:flex;align-items:center;justify-content:center;"><span style='font-size:4rem;opacity:0.3'>🎬</span></div>`;
                    main.innerHTML = `
                        <div class="video-thumb-wrap" style="width:100%;height:100%;cursor:pointer;" onclick="openVideoModal('${vid.id}', '${vid.name.replace(/'/g, '')}')">
                            ${thumbImg}
                            <div class="video-play-overlay">
                                <div class="video-play-btn" style="width:64px;height:64px;">
                                    <svg viewBox="0 0 24 24" style="width:28px;height:28px;"><path d="M8 5v14l11-7z"/></svg>
                                </div>
                                <span class="video-play-label" style="font-size:0.85rem;">Reproducir video</span>
                            </div>
                            <span class="video-badge" style="font-size:0.75rem;padding:0.25rem 0.6rem;">VIDEO</span>
                        </div>`;
                    document.getElementById('mainImageName').textContent = vid.name;
                    return;
                }
                main.innerHTML = '📷';
            }

            // Helper: try Drive images, fallback to video
            function tryDriveImages() {
                const img = files.find(f => (f.mimeType || '').startsWith('image/'));
                if (img) {
                    const url = img.thumbnailLink
                        ? img.thumbnailLink.replace(/=s\d+/, '=s600')
                        : `https://lh3.googleusercontent.com/d/${img.id}=s600`;
                    setCoverImage(main, url, img.id, img.name, showVideoCover);
                    return;
                }
                showVideoCover();
            }

            // 1) Si ya tiene cover de BD, usarla (con fallback a Drive si falla)
            if (main.dataset.cover) {
                setCoverImage(main, main.dataset.cover, null, null, tryDriveImages);
                return;
            }

            // 2) Buscar primera imagen de Drive (con fallback a video)
            tryDriveImages();
        }

        function setCoverImage(container, url, fileId, fileName, onErrorFallback) {
            const imgEl = document.createElement('img');
            imgEl.alt = PRODUCT_NAME;
            imgEl.style.opacity = '0';
            imgEl.style.transition = 'opacity 0.4s ease';
            imgEl.onclick = () => downloadMainImage();
            imgEl.onload = () => {
                container.innerHTML = '';
                container.appendChild(imgEl);
                requestAnimationFrame(() => imgEl.style.opacity = '1');
                // Update tracking vars and info bar
                if (fileId) {
                    currentMainFileId = fileId;
                    currentMainFileName = fileName || '';
                    document.getElementById('mainImageName').textContent = fileName || '';
                    document.getElementById('mainImageDownload').style.display = '';
                }
            };
            imgEl.onerror = () => {
                if (typeof onErrorFallback === 'function') {
                    onErrorFallback();
                } else {
                    container.innerHTML = '📷';
                }
            };
            imgEl.src = url;
        }

        function updateButtons() {
            const btnP = document.getElementById('btnPhotos');
            const btnV = document.getElementById('btnVideos');
            const count = document.getElementById('mediaCount');

            const imgCount = mediaFiles.images.length;
            const vidCount = mediaFiles.videos.length;

            if (imgCount > 0) {
                btnP.textContent = `📸 Descargar Fotos (${imgCount})`;
                btnP.disabled = false;
                btnP.style.opacity = '1';
            } else {
                btnP.textContent = '📸 Sin fotos';
                btnP.disabled = true;
                btnP.style.opacity = '0.4';
            }

            if (vidCount > 0) {
                btnV.textContent = `🎥 Descargar Videos (${vidCount})`;
                btnV.disabled = false;
                btnV.style.opacity = '1';
            } else {
                btnV.textContent = '🎥 Sin videos';
                btnV.disabled = true;
                btnV.style.opacity = '0.4';
            }

            count.textContent = `${imgCount} foto(s) · ${vidCount} video(s) en Drive`;
        }

        function renderGallery(files) {
            if (files.length === 0) return;

            const gallery = document.getElementById('mediaGallery');
            const grid = document.getElementById('galleryGrid');
            gallery.style.display = 'block';
            allGalleryFiles = files;

            grid.innerHTML = files.map((f, idx) => {
                const isImage = (f.mimeType || '').startsWith('image/');
                const isVideo = (f.mimeType || '').startsWith('video/');
                const downloadUrl = f.webContentLink || f.webViewLink || '#';

                let mediaHtml;
                if (isImage) {
                    const thumbUrl = f.thumbnailLink
                        ? f.thumbnailLink.replace(/=s\d+/, '=s200')
                        : `https://lh3.googleusercontent.com/d/${f.id}=s200`;
                    mediaHtml = `<img data-src="${thumbUrl}" alt="${f.name}" class="img-fade-in gallery-lazy" style="cursor:pointer;" onerror="this.outerHTML='<div style=\\'display:flex;align-items:center;justify-content:center;height:150px;color:var(--color-text-muted);font-size:2rem;\\'>📷</div>'" onclick="showInMain(${idx})">`;
                } else if (isVideo) {
                    const videoThumb = f.thumbnailLink
                        ? f.thumbnailLink.replace(/=s\d+/, '=s300')
                        : '';
                    const thumbContent = videoThumb
                        ? `<img src="${videoThumb}" alt="${f.name}" loading="lazy">`
                        : `<div style="width:100%;height:100%;background:linear-gradient(135deg,#1a1a2e,#0a0a0f);display:flex;align-items:center;justify-content:center;"><span style='font-size:2.5rem;opacity:0.3'>🎬</span></div>`;
                    mediaHtml = `<div class="video-thumb-wrap" onclick="openVideoModal('${f.id}', '${f.name.replace(/'/g, '')}')">
                        ${thumbContent}
                        <div class="video-play-overlay">
                            <div class="video-play-btn">
                                <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            </div>
                            <span class="video-play-label">Reproducir</span>
                        </div>
                        <span class="video-badge">VIDEO</span>
                    </div>`;
                } else {
                    mediaHtml = `<div class="video-placeholder">📄</div>`;
                }

                // Checkbox for images only
                const checkHtml = isImage ? `
                    <label class="search-check-footer">
                        <input type="checkbox" class="gallery-check" data-index="${idx}" data-file-id="${f.id}" data-name="${f.name.replace(/"/g, '')}">
                        <span class="search-check-icon">✓</span>
                        <span class="search-check-text">Seleccionar</span>
                    </label>` : '';

                return `
                    <div class="gallery-item" data-file-id="${f.id}">
                        ${mediaHtml}
                        <div style="font-size:0.65rem; color:var(--color-text-muted); padding:0.3rem 0.4rem; text-align:center; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${f.name}">
                            ${f.name.length > 25 ? f.name.substring(0, 22) + '...' : f.name}
                        </div>
                        ${checkHtml}
                    </div>
                `;
            }).join('');

            // Lazy-load thumbnails
            const lazyObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            img.onload = () => img.classList.add('loaded');
                        }
                        lazyObserver.unobserve(img);
                    }
                });
            }, { rootMargin: '300px' });

            grid.querySelectorAll('img.gallery-lazy[data-src]').forEach(img => {
                lazyObserver.observe(img);
            });

            // --- Gallery WhatsApp selection ---
            const waBar = document.getElementById('galleryWaBar');
            const selectAllCb = document.getElementById('gallerySelectAll');
            const countEl = document.getElementById('gallerySelectedCount');
            const btnSend = document.getElementById('btnGalleryWaSend');
            const checks = grid.querySelectorAll('.gallery-check');

            if (checks.length > 0) waBar.style.display = '';

            function updateGalleryCount() {
                const sel = grid.querySelectorAll('.gallery-check:checked').length;
                countEl.textContent = sel;
                btnSend.disabled = sel === 0;
                selectAllCb.checked = sel === checks.length && checks.length > 0;
                selectAllCb.indeterminate = sel > 0 && sel < checks.length;
            }

            checks.forEach(cb => cb.addEventListener('change', updateGalleryCount));
            selectAllCb.addEventListener('change', () => {
                checks.forEach(cb => cb.checked = selectAllCb.checked);
                updateGalleryCount();
            });

            btnSend.addEventListener('click', async () => {
                const sel = [];
                grid.querySelectorAll('.gallery-check:checked').forEach(cb => {
                    const f = allGalleryFiles[parseInt(cb.dataset.index)];
                    if (f) sel.push(f);
                });
                if (sel.length === 0) return;
                if (sel.length > 10) {
                    alert('⚠️ Solo se pueden enviar 10 imágenes a la vez.\n\nPor favor deselecciona algunas.');
                    return;
                }

                const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                if (isMobile && navigator.canShare && navigator.share) {
                    btnSend.disabled = true;
                    btnSend.textContent = '⏳ Preparando...';
                    try {
                        const fileObjs = (await Promise.all(sel.map(async (f, i) => {
                            try {
                                const imgUrl = `https://lh3.googleusercontent.com/d/${f.id}=s800`;
                                const r = await fetch(imgUrl, { mode: 'cors' });
                                const b = await r.blob();
                                return new File([b], `imagen_${i + 1}.jpg`, { type: b.type || 'image/jpeg' });
                            } catch { return null; }
                        }))).filter(Boolean);
                        if (fileObjs.length > 0) {
                            const sd = { files: fileObjs };
                            if (navigator.canShare(sd)) { await navigator.share(sd); resetGalleryBtn(); return; }
                        }
                    } catch (e) { if (e.name === 'AbortError') { resetGalleryBtn(); return; } }
                    resetGalleryBtn();
                }

                // Fallback: links con URLs directas a las imágenes
                let text = '📦 *' + SKU + ' - ' + PRODUCT_NAME + '*\n\n';
                text += '🔗 Ver producto: ' + location.href + '\n\n';
                text += '📸 *Imágenes seleccionadas:*\n\n';
                sel.forEach((f, i) => {
                    const imgUrl = `https://drive.google.com/uc?export=view&id=${f.id}`;
                    text += (i + 1) + '. ' + imgUrl + '\n\n';
                });
                window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
            });

            function resetGalleryBtn() {
                btnSend.disabled = false;
                btnSend.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> Enviar por WhatsApp';
            }
        }

        function downloadMedia(type) {
            const files = type === 'image' ? mediaFiles.images : mediaFiles.videos;
            if (files.length === 0) return;

            // Open each file download link in a new tab
            files.forEach((f, i) => {
                const url = f.webContentLink || f.webViewLink || '#';
                setTimeout(() => {
                    window.open(url, '_blank');
                }, i * 300); // Stagger to avoid popup blocker
            });
        }

        async function setCover(fileId, btn) {
            if (btn.classList.contains('current')) return;
            btn.textContent = '⏳...';
            btn.disabled = true;

            const imageUrl = `https://lh3.googleusercontent.com/d/${fileId}`;
            const form = new FormData();
            form.append('product_id', PRODUCT_ID);
            form.append('image_url', imageUrl);

            try {
                const resp = await fetch('/admin/product/set-cover', { method: 'POST', body: form });
                const data = await resp.json();
                if (data.ok) {
                    currentCover = imageUrl;
                    // Update main image
                    const mainImg = document.querySelector('.main-image');
                    mainImg.innerHTML = `<img src="${imageUrl}" alt="${PRODUCT_NAME}" style="cursor:pointer;" onclick="downloadMainImage()">`;

                    // Reset all cover buttons
                    document.querySelectorAll('.btn-set-cover').forEach(b => {
                        b.classList.remove('current');
                        b.disabled = false;
                        b.textContent = '⭐ Hacer principal';
                    });
                    btn.classList.add('current');
                    btn.disabled = true;
                    btn.textContent = '⭐ Principal';
                } else {
                    alert(data.error || 'Error al cambiar imagen.');
                    btn.textContent = '⭐ Hacer principal';
                    btn.disabled = false;
                }
            } catch (e) {
                alert('Error de conexión.');
                btn.textContent = '⭐ Hacer principal';
                btn.disabled = false;
            }
        }

        // Init
        loadMedia();
        let currentMainFileId = null;
        let currentMainFileName = '';

        // Show thumbnail in main image area
        function showInMain(idx) {
            const f = allGalleryFiles[idx];
            if (!f) return;
            const isImage = (f.mimeType || '').startsWith('image/');
            const isVideo = (f.mimeType || '').startsWith('video/');

            if (isVideo) {
                openVideoModal(f.id, f.name);
                return;
            }
            if (!isImage) return;

            currentMainFileId = f.id;
            currentMainFileName = f.name;

            const fullUrl = `https://lh3.googleusercontent.com/d/${f.id}=s1200`;
            const main = document.getElementById('mainCover');
            
            // Swap main image with smooth transition
            const newImg = document.createElement('img');
            newImg.alt = f.name;
            newImg.style.opacity = '0';
            newImg.style.transition = 'opacity 0.3s ease';
            newImg.style.cursor = 'pointer';
            newImg.onclick = () => downloadMainImage();
            newImg.onload = () => {
                main.innerHTML = '';
                main.appendChild(newImg);
                requestAnimationFrame(() => newImg.style.opacity = '1');
            };
            newImg.src = fullUrl;

            // Update filename and download button
            document.getElementById('mainImageName').textContent = f.name;
            const dlBtn = document.getElementById('mainImageDownload');
            dlBtn.style.display = '';

            // Scroll main image into view
            main.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Highlight active thumbnail
            document.querySelectorAll('.gallery-item').forEach(gi => gi.classList.remove('gallery-active'));
            const activeItem = document.querySelector(`.gallery-item[data-file-id="${f.id}"]`);
            if (activeItem) activeItem.classList.add('gallery-active');
        }

        // Video modal player
        function openVideoModal(fileId, fileName) {
            const modal = document.getElementById('videoModal');
            const player = document.getElementById('videoModalPlayer');
            const title = document.getElementById('videoModalTitle');

            player.innerHTML = `<iframe src="https://drive.google.com/file/d/${fileId}/preview" allow="autoplay; encrypted-media" allowfullscreen></iframe>`;
            title.textContent = fileName;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeVideoModal(event, force) {
            if (!force && event.target !== event.currentTarget) return;
            const modal = document.getElementById('videoModal');
            const player = document.getElementById('videoModalPlayer');
            modal.classList.remove('active');
            document.body.style.overflow = '';
            // Stop video after transition
            setTimeout(() => { player.innerHTML = ''; }, 300);
        }

        // Close modal on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeVideoModal(e, true);
        });

        // Download the current main image
        function downloadMainImage() {
            if (!currentMainFileId) return;
            const url = `https://lh3.googleusercontent.com/d/${currentMainFileId}=s1200`;
            const a = document.createElement('a');
            a.href = url;
            a.download = currentMainFileName || 'image.jpg';
            a.target = '_blank';
            // Blob download for mobile support
            fetch(url, { mode: 'cors' })
                .then(r => r.blob())
                .then(blob => {
                    const blobUrl = URL.createObjectURL(blob);
                    a.href = blobUrl;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
                })
                .catch(() => {
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                });
        }
    </script>

</body>

</html>