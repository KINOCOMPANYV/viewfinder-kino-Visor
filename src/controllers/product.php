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
        let mediaFiles = { images: [], videos: [] };

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