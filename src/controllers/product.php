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

        .media-count {
            font-size: 0.8rem;
            color: var(--color-text-muted);
            margin-top: 0.25rem;
        }

        /* Lightbox */
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.92);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            cursor: zoom-out;
        }

        .lightbox.active {
            display: flex;
        }

        .lightbox img {
            max-width: 92vw;
            max-height: 90vh;
            border-radius: 8px;
            box-shadow: 0 0 40px rgba(0, 0, 0, 0.5);
            object-fit: contain;
        }

        .lightbox .lb-close {
            position: absolute;
            top: 15px;
            right: 20px;
            color: #fff;
            font-size: 2rem;
            cursor: pointer;
            background: rgba(0, 0, 0, 0.5);
            border: none;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .lightbox .lb-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .lightbox .lb-name {
            position: absolute;
            bottom: 20px;
            color: #fff;
            font-size: 0.85rem;
            background: rgba(0, 0, 0, 0.6);
            padding: 0.5rem 1.5rem;
            border-radius: 20px;
        }

        .main-image img {
            cursor: zoom-in;
            transition: transform 0.2s;
        }

        .main-image img:hover {
            transform: scale(1.02);
        }

        .gallery-item img {
            cursor: zoom-in;
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
            .lightbox img {
                max-width: 96vw;
                max-height: 80vh;
            }

            .lightbox .lb-close {
                top: 10px;
                right: 10px;
                width: 36px;
                height: 36px;
                font-size: 1.4rem;
            }

            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)) !important;
                gap: 0.75rem !important;
            }

            .media-gallery h2 {
                font-size: 1rem;
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
                <div class="main-image">
                    <?php if ($product['cover_image_url']): ?>
                        <img src="<?= e($product['cover_image_url']) ?>" alt="<?= e($product['name']) ?>"
                            onclick="openLightbox(this.src, '<?= e(addslashes($product['name'])) ?>')">
                    <?php else: ?>
                        ⌚
                    <?php endif; ?>
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
                    <button class="btn-whatsapp" style="margin-top:0.75rem;" onclick="shareWhatsApp()">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                            <path
                                d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                        </svg>
                        Enviar por WhatsApp
                    </button>
                    <div class="media-count" id="mediaCount"></div>
                </div>
            </div>

            <!-- Media Gallery -->
            <div class="media-gallery" id="mediaGallery" style="display:none;">
                <h2>📂 Archivos multimedia</h2>
                <div class="gallery-grid" id="galleryGrid"></div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <p>Solo para distribuidores autorizados · Viewfinder Kino Visor ©
                <?= date('Y') ?>
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

        function shareWhatsApp() {
            const url = window.location.href;
            const text = `📦 *${PRODUCT_NAME}*\n🔗 SKU: ${SKU}\n\n📸 Ver fotos y videos:\n${url}`;
            window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
        }

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

                updateButtons();
                renderGallery(files);
            } catch (e) {
                console.error('Error cargando media:', e);
                document.getElementById('btnPhotos').textContent = '📸 Sin conexión';
                document.getElementById('btnVideos').textContent = '🎥 Sin conexión';
            }
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

            grid.innerHTML = files.map(f => {
                const isImage = (f.mimeType || '').startsWith('image/');
                const isVideo = (f.mimeType || '').startsWith('video/');
                const thumbUrl = f.thumbnailLink || '';
                const viewUrl = f.webViewLink || '#';
                const downloadUrl = f.webContentLink || viewUrl;

                let mediaHtml;
                if (isImage && thumbUrl) {
                    const fullUrl = `https://lh3.googleusercontent.com/d/${f.id}=s1200`;
                    mediaHtml = `<img src="${thumbUrl}" alt="${f.name}" loading="lazy" onclick="openLightbox('${fullUrl}', '${f.name.replace(/'/g, '')}')">`;
                } else if (isVideo) {
                    // Streaming: usar reproductor embebido de Google Drive
                    mediaHtml = `<div class="video-embed" style="width:100%;height:150px;position:relative;background:#000;cursor:pointer;" onclick="this.innerHTML='<iframe src=\\'https://drive.google.com/file/d/${f.id}/preview\\' width=\\'100%\\' height=\\'150\\' frameborder=\\'0\\' allow=\\'autoplay\\' allowfullscreen></iframe>'">
                        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#fff;">
                            <span style="font-size:2.5rem;">▶️</span>
                            <span style="font-size:0.7rem;margin-top:0.3rem;opacity:0.7;">Click para reproducir</span>
                        </div>
                    </div>`;
                } else {
                    mediaHtml = `<div class="video-placeholder">📄</div>`;
                }

                // Build set-cover button for images (admin only)
                let coverBtn = '';
                if (IS_ADMIN && isImage) {
                    const driveUrl = `https://lh3.googleusercontent.com/d/${f.id}`;
                    const isCurrent = driveUrl === currentCover;
                    if (isCurrent) {
                        coverBtn = `<button class="btn-set-cover current" disabled>⭐ Principal</button>`;
                    } else {
                        coverBtn = `<button class="btn-set-cover" onclick="setCover('${f.id}', this)">⭐ Hacer principal</button>`;
                    }
                }

                return `
                    <div class="gallery-item" data-file-id="${f.id}">
                        ${mediaHtml}
                        <div class="item-actions">
                            <a href="${viewUrl}" target="_blank" class="btn btn-sm btn-secondary">👁️ Ver</a>
                            <a href="${downloadUrl}" target="_blank" class="btn btn-sm btn-primary">⬇️</a>
                            ${coverBtn}
                        </div>
                    </div>
                `;
            }).join('');

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
                    mainImg.innerHTML = `<img src="${imageUrl}" alt="${PRODUCT_NAME}">`;

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
        function openLightbox(url, name) {
            const lb = document.getElementById('lightbox');
            document.getElementById('lb-img').src = url;
            document.getElementById('lb-name').textContent = name;
            lb.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeLightbox() {
            const lb = document.getElementById('lightbox');
            lb.classList.remove('active');
            document.getElementById('lb-img').src = '';
            document.body.style.overflow = '';
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });
    </script>

    <!-- Lightbox -->
    <div class="lightbox" id="lightbox" onclick="closeLightbox()">
        <button class="lb-close" onclick="closeLightbox()">✕</button>
        <img id="lb-img" src="" alt="Preview">
        <div class="lb-name" id="lb-name"></div>
    </div>

</body>

</html>