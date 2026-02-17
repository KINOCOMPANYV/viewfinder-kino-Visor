<?php
/**
 * Ficha de producto — detalle completo por SKU.
 */
$sku = $_GET['sku'] ?? '';
$db = getDB();

$stmt = $db->prepare("SELECT * FROM products WHERE sku = ?");
$stmt->execute([$sku]);
$product = $stmt->fetch();

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
        }

        .gallery-item .item-actions a {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
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
            <div class="breadcrumb">
                <a href="/">Inicio</a> ›
                <a href="/buscar">Catálogo</a> ›
                <span style="color:var(--color-text)">
                    <?= e($product['sku']) ?>
                </span>
            </div>

            <div class="detail-grid">
                <!-- Image -->
                <div class="main-image">
                    <?php if ($product['cover_image_url']): ?>
                        <img src="<?= e($product['cover_image_url']) ?>" alt="<?= e($product['name']) ?>">
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
        const PRODUCT_NAME = '<?= e(addslashes($product['name'])) ?>';
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

        // Load media from Drive API
        async function loadMedia() {
            try {
                const resp = await fetch('/api/media/' + encodeURIComponent(SKU));
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
                    mediaHtml = `<img src="${thumbUrl}" alt="${f.name}" loading="lazy">`;
                } else if (isVideo) {
                    mediaHtml = `<div class="video-placeholder">🎬</div>`;
                } else {
                    mediaHtml = `<div class="video-placeholder">📄</div>`;
                }

                return `
                    <div class="gallery-item">
                        ${mediaHtml}
                        <div class="item-actions">
                            <a href="${viewUrl}" target="_blank" class="btn btn-sm btn-secondary">👁️ Ver</a>
                            <a href="${downloadUrl}" target="_blank" class="btn btn-sm btn-primary">⬇️</a>
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

        // Init
        loadMedia();
    </script>
</body>

</html>