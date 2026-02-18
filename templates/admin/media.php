<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media · Viewfinder Admin</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .drive-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
        }

        .drive-status.connected {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid rgba(46, 204, 113, 0.3);
        }

        .drive-status.disconnected {
            background: rgba(231, 76, 60, 0.1);
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .media-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .media-stat {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius);
            padding: 1.2rem;
            text-align: center;
        }

        .media-stat .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-gold);
        }

        .media-stat .label {
            font-size: 0.8rem;
            color: var(--color-text-muted);
            margin-top: 0.25rem;
        }

        .upload-zone {
            border: 2px dashed var(--color-border);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            margin-bottom: 2rem;
        }

        .upload-zone:hover,
        .upload-zone.dragover {
            border-color: var(--color-gold);
            background: rgba(201, 168, 76, 0.05);
        }

        .upload-zone .icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }

        .upload-zone input[type="file"] {
            display: none;
        }

        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .file-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: transform 0.2s;
        }

        .file-card:hover {
            transform: translateY(-2px);
        }

        .file-card .thumbnail {
            width: 100%;
            height: 150px;
            object-fit: cover;
            background: var(--color-bg);
        }

        .file-card .video-thumb {
            width: 100%;
            height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--color-bg);
            font-size: 3rem;
        }

        .file-card .info {
            padding: 0.75rem;
        }

        .file-card .name {
            font-size: 0.8rem;
            font-weight: 600;
            word-break: break-all;
            margin-bottom: 0.25rem;
        }

        .file-card .meta {
            font-size: 0.7rem;
            color: var(--color-text-muted);
        }

        .file-card .sku-match {
            font-size: 0.7rem;
            color: var(--color-gold);
            font-weight: 600;
        }

        .file-card .actions {
            padding: 0.5rem 0.75rem;
            border-top: 1px solid var(--color-border);
            display: flex;
            gap: 0.5rem;
        }

        .file-card .actions a,
        .file-card .actions button {
            font-size: 0.7rem;
            padding: 0.3rem 0.6rem;
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .btn-google {
            background: #4285f4;
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-google:hover {
            background: #3367d6;
        }

        .btn-set-cover {
            background: rgba(201, 168, 76, 0.15);
            color: var(--color-gold);
            border: 1px solid var(--color-gold);
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            font-size: 0.7rem;
            padding: 0.3rem 0.6rem;
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

        /* Breadcrumb navigation */
        .folder-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.2rem;
            background: linear-gradient(135deg, rgba(30, 30, 50, 0.9), rgba(20, 20, 40, 0.95));
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: var(--radius);
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .bc-item {
            color: var(--color-text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .bc-item:hover:not(.active) {
            background: rgba(255, 255, 255, 0.1);
            color: var(--color-gold);
        }

        .bc-item.active {
            color: var(--color-gold);
            font-weight: 700;
        }

        .bc-sep {
            color: var(--color-text-muted);
            font-size: 1.2rem;
        }

        .bc-back {
            margin-left: auto;
            background: linear-gradient(135deg, var(--color-gold), #e6a800);
            color: #000;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .bc-back:hover {
            transform: scale(1.05);
            box-shadow: 0 0 15px rgba(212, 175, 55, 0.4);
        }

        /* Folder grid */
        .folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .folder-card-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.5rem 1rem;
            background: linear-gradient(145deg, rgba(30, 35, 60, 0.8), rgba(20, 25, 45, 0.9));
            border: 1px solid rgba(212, 175, 55, 0.15);
            border-radius: var(--radius);
            text-decoration: none;
            transition: all 0.3s;
            cursor: pointer;
        }

        .folder-card-item:hover {
            border-color: var(--color-gold);
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(212, 175, 55, 0.15);
            background: linear-gradient(145deg, rgba(40, 45, 70, 0.9), rgba(25, 30, 55, 0.95));
        }

        .folder-icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
            transition: transform 0.3s;
        }

        .folder-card-item:hover .folder-icon {
            transform: scale(1.15);
        }

        .folder-name {
            color: var(--color-gold);
            font-weight: 700;
            font-size: 0.95rem;
            text-align: center;
            margin-bottom: 0.3rem;
        }

        .folder-hint {
            color: var(--color-text-muted);
            font-size: 0.75rem;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .folder-card-item:hover .folder-hint {
            opacity: 1;
        }

        /* Lightbox */
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            cursor: zoom-out;
        }

        .lightbox.active {
            display: flex;
        }

        .lightbox img {
            max-width: 90vw;
            max-height: 90vh;
            border-radius: 8px;
            box-shadow: 0 0 40px rgba(0, 0, 0, 0.5);
        }

        .lightbox .lb-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: #fff;
            font-size: 2rem;
            cursor: pointer;
            background: rgba(0, 0, 0, 0.5);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .lightbox .lb-name {
            position: absolute;
            bottom: 20px;
            color: #fff;
            font-size: 0.9rem;
            background: rgba(0, 0, 0, 0.6);
            padding: 0.5rem 1.5rem;
            border-radius: 20px;
        }

        .thumbnail {
            cursor: zoom-in;
        }

        /* Toast */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .toast {
            padding: 0.8rem 1.5rem;
            border-radius: var(--radius);
            color: #fff;
            font-weight: 600;
            font-size: 0.85rem;
            animation: toast-in 0.3s ease, toast-out 0.3s ease 3.7s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            max-width: 350px;
        }

        .toast.success {
            background: #27ae60;
        }

        .toast.error {
            background: #e74c3c;
        }

        .toast.info {
            background: #2980b9;
        }

        @keyframes toast-in {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes toast-out {
            from {
                opacity: 1;
            }

            to {
                opacity: 0;
            }
        }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">VF</span> Viewfinder Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container fade-in" style="padding: 2rem 0;">

        <!-- Flash Messages -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success">
                <?= $_SESSION['flash_success'] ?>
            </div>
            <?php unset($_SESSION['flash_success']); endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-error">
                <?= $_SESSION['flash_error'] ?>
            </div>
            <?php unset($_SESSION['flash_error']); endif; ?>

        <!-- Nav Admin -->
        <div style="display:flex; gap:1rem; margin-bottom:2rem; flex-wrap:wrap;">
            <a href="/admin" class="btn btn-sm btn-secondary">📊 Dashboard</a>
            <a href="/admin/products" class="btn btn-sm btn-secondary">📦 Productos</a>
            <a href="/admin/import" class="btn btn-sm btn-secondary">📥 Importar Excel</a>
            <a href="/admin/media" class="btn btn-sm btn-primary">🖼️ Media</a>
        </div>

        <h1>🖼️ Gestión de Media</h1>
        <p style="color:var(--color-text-muted); margin-bottom:2rem;">
            Sube fotos y videos a Google Drive. Los archivos se enlazan con productos por SKU en el nombre.
        </p>

        <!-- Estado de conexión -->
        <?php if ($isConnected): ?>
            <div class="drive-status connected">
                <span>🟢</span>
                <span>Google Drive <strong>conectado</strong></span>
            </div>
        <?php else: ?>
            <div class="drive-status disconnected">
                <span>🔴</span>
                <span>Google Drive <strong>no conectado</strong></span>
                <a href="/admin/google/auth" class="btn-google" style="margin-left:auto;">
                    🔗 Conectar Google Drive
                </a>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="media-stats">
            <div class="media-stat">
                <div class="number">
                    <?= count($driveFiles) ?>
                </div>
                <div class="label">Archivos en Drive</div>
            </div>
            <div class="media-stat">
                <div class="number">
                    <?= $totalProducts ?>
                </div>
                <div class="label">Productos</div>
            </div>
            <div class="media-stat">
                <div class="number">
                    <?= $linkedCount ?>
                </div>
                <div class="label">Enlazados por SKU</div>
            </div>
        </div>

        <?php if ($isConnected): ?>

            <!-- Botón sincronizar portadas -->
            <?php if ($withoutCover > 0): ?>
                <div style="margin: 1.5rem 0; text-align:center;">
                    <button class="btn btn-primary" id="syncCoversBtn" onclick="syncCovers()"
                        style="font-size:1rem; padding:0.8rem 2rem;">
                        ⭐ Auto-asignar portadas (<?= $withoutCover ?> sin imagen)
                    </button>
                    <p style="font-size:0.8rem; color:var(--color-text-muted); margin-top:0.5rem;">
                        Busca en Drive (incluyendo subcarpetas) la primera imagen para cada producto sin portada.
                    </p>
                </div>
            <?php endif; ?>

            <!-- Subir archivos -->
            <div class="section-title">
                <h2>📤 Subir archivos</h2>
            </div>

            <form action="/admin/media/upload" method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="upload-zone" id="dropZone">
                    <div class="icon">📁</div>
                    <p><strong>Arrastra archivos aquí</strong> o haz click para seleccionar</p>
                    <p style="font-size:0.8rem; color:var(--color-text-muted);">
                        JPG, PNG, WEBP, MP4 · Nombra los archivos con el SKU (ej: <code>SKU123_foto1.jpg</code>)
                    </p>
                    <input type="file" name="media_files[]" id="fileInput" multiple
                        accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm">
                </div>
                <div id="fileList" style="margin-bottom:1rem; display:none;">
                    <p style="font-weight:600;">Archivos seleccionados:</p>
                    <ul id="selectedFiles" style="font-size:0.85rem; color:var(--color-text-muted);"></ul>
                </div>
                <button type="submit" class="btn btn-primary" id="uploadBtn" style="display:none;">
                    📤 Subir a Google Drive
                </button>
            </form>

            <!-- Navegación de carpetas - Breadcrumb -->
            <div class="folder-breadcrumb">
                <a href="/admin/media" class="bc-item <?= $isRoot ? 'active' : '' ?>">
                    🏠 Raíz
                </a>
                <?php if (!$isRoot): ?>
                    <span class="bc-sep">›</span>
                    <span class="bc-item active">📁 <?= e($folderLabel ?: 'Subcarpeta') ?></span>
                <?php endif; ?>
                <?php if (!$isRoot): ?>
                    <a href="/admin/media" class="bc-back">⬅️ Volver</a>
                <?php endif; ?>
            </div>

            <!-- Subcarpetas -->
            <?php if (!empty($subfolders)): ?>
                <div class="section-title" style="margin-top:1.5rem;">
                    <h2>📁 Carpetas (<?= count($subfolders) ?>)</h2>
                </div>
                <div class="folder-grid">
                    <?php foreach ($subfolders as $folder): ?>
                        <a href="/admin/media?folder=<?= urlencode($folder['id']) ?>&name=<?= urlencode($folder['name']) ?>"
                            class="folder-card-item">
                            <div class="folder-icon">📁</div>
                            <div class="folder-name"><?= e($folder['name']) ?></div>
                            <div class="folder-hint">Click para explorar →</div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Archivos en Drive -->
            <div class="section-title" style="margin-top:2rem;">
                <h2>📂 Archivos (<?= count($driveFiles) ?>)</h2>
            </div>

            <?php if (empty($driveFiles) && empty($subfolders)): ?>
                <div style="text-align:center; padding:3rem; color:var(--color-text-muted);">
                    <div style="font-size:4rem; margin-bottom:1rem;">📭</div>
                    <p>No hay archivos en esta carpeta.</p>
                    <p style="font-size:0.85rem;">Sube archivos usando el formulario de arriba.</p>
                </div>
            <?php elseif (empty($driveFiles)): ?>
                <div style="text-align:center; padding:2rem; color:var(--color-text-muted);">
                    <p>Solo subcarpetas en esta ubicación. Entra a una carpeta para ver archivos.</p>
                </div>
            <?php else: ?>
                <div class="file-grid">
                    <?php foreach ($driveFiles as $file):
                        $isVideo = str_starts_with($file['mimeType'] ?? '', 'video/');
                        $isImage = str_starts_with($file['mimeType'] ?? '', 'image/');
                        $thumbUrl = !empty($file['thumbnailLink']) ? $file['thumbnailLink'] : '';
                        $sizeKB = round(($file['size'] ?? 0) / 1024);
                        $sizeMB = round($sizeKB / 1024, 1);
                        $sizeLabel = $sizeKB > 1024 ? "{$sizeMB} MB" : "{$sizeKB} KB";

                        // Buscar SKU match usando el diccionario precargado
                        $skuMatch = '';
                        $matchedProductId = 0;
                        $matchedCover = '';
                        foreach ($productsBySku as $sku => $prod) {
                            if (stripos($file['name'], $sku) !== false) {
                                $skuMatch = $sku;
                                $matchedProductId = $prod['id'];
                                $matchedCover = $prod['cover_image_url'];
                                break;
                            }
                        }

                        $driveImageUrl = "https://lh3.googleusercontent.com/d/{$file['id']}";
                        $isCover = ($isImage && $skuMatch && $matchedCover === $driveImageUrl);
                        ?>
                        <div class="file-card">
                            <?php if ($isImage && $thumbUrl): ?>
                                <img src="<?= e($thumbUrl) ?>" alt="<?= e($file['name']) ?>" class="thumbnail" loading="lazy"
                                    onclick="openLightbox('https://lh3.googleusercontent.com/d/<?= e($file['id']) ?>=s1200', '<?= e(addslashes($file['name'])) ?>')">
                            <?php elseif ($isVideo): ?>
                                <div class="video-thumb">🎬</div>
                            <?php else: ?>
                                <div class="video-thumb">📄</div>
                            <?php endif; ?>

                            <div class="info">
                                <div class="name">
                                    <?= e($file['name']) ?>
                                </div>
                                <div class="meta">
                                    <?= $sizeLabel ?>
                                </div>
                                <?php if ($skuMatch): ?>
                                    <div class="sku-match">🔗
                                        <?= e($skuMatch) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="actions">
                                <a href="<?= e($file['webViewLink'] ?? '#') ?>" target="_blank"
                                    class="btn btn-sm btn-secondary">Ver</a>
                                <?php if ($isImage && $skuMatch): ?>
                                    <?php if ($isCover): ?>
                                        <button class="btn btn-sm btn-set-cover current" disabled>⭐ Principal</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-set-cover"
                                            onclick="setCover(<?= $matchedProductId ?>, '<?= e($file['id']) ?>', this)">
                                            ⭐ Principal
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <form action="/admin/media/delete" method="POST" style="margin:0;"
                                    onsubmit="return confirm('¿Eliminar este archivo?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="file_id" value="<?= e($file['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Viewfinder Admin Panel · v1.0</p>
        </div>
    </footer>

    <script>
        // Drag & Drop + File Select
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const selectedFiles = document.getElementById('selectedFiles');
        const uploadBtn = document.getElementById('uploadBtn');

        if (dropZone) {
            dropZone.addEventListener('click', () => fileInput.click());
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
            dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                fileInput.files = e.dataTransfer.files;
                showFiles(e.dataTransfer.files);
            });

            fileInput.addEventListener('change', () => showFiles(fileInput.files));
        }

        function showFiles(files) {
            if (files.length === 0) return;
            fileList.style.display = 'block';
            uploadBtn.style.display = 'inline-flex';
            selectedFiles.innerHTML = '';
            for (const f of files) {
                const li = document.createElement('li');
                const sizeMB = (f.size / 1024 / 1024).toFixed(1);
                li.textContent = `${f.name} (${sizeMB} MB)`;
                selectedFiles.appendChild(li);
            }
        }

        async function setCover(productId, fileId, btn) {
            if (btn.classList.contains('current')) return;
            const originalText = btn.textContent;
            btn.textContent = '⏳...';
            btn.disabled = true;

            const imageUrl = `https://lh3.googleusercontent.com/d/${fileId}`;
            const form = new FormData();
            form.append('product_id', productId);
            form.append('image_url', imageUrl);

            try {
                const resp = await fetch('/admin/product/set-cover', { method: 'POST', body: form });
                const data = await resp.json();
                if (data.ok) {
                    // Reset all cover buttons for the same product
                    document.querySelectorAll('.btn-set-cover').forEach(b => {
                        b.classList.remove('current');
                        b.disabled = false;
                        b.textContent = '⭐ Principal';
                    });
                    btn.classList.add('current');
                    btn.disabled = true;
                    btn.textContent = '⭐ Principal';
                    showToast('✅ Imagen principal actualizada.');
                } else {
                    showToast(data.error || 'Error al cambiar imagen.', 'error');
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            } catch (e) {
                showToast('Error de conexión.', 'error');
                btn.textContent = originalText;
                btn.disabled = false;
            }
        }

        async function syncCovers() {
            const btn = document.getElementById('syncCoversBtn');
            const originalText = btn.textContent;
            btn.textContent = '⏳ Buscando imágenes en Drive...';
            btn.disabled = true;

            try {
                const resp = await fetch('/admin/media/sync-covers', { method: 'POST' });
                const data = await resp.json();
                if (data.ok) {
                    showToast(data.message);
                    if (data.assigned > 0) location.reload();
                    else {
                        btn.textContent = originalText;
                        btn.disabled = false;
                    }
                } else {
                    showToast(data.error || 'Error al sincronizar.', 'error');
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            } catch (e) {
                showToast('Error de conexión.', 'error');
                btn.textContent = originalText;
                btn.disabled = false;
            }
        }
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

        function showToast(msg, type = 'success') {
            const container = document.getElementById('toast-container');
            const t = document.createElement('div');
            t.className = `toast ${type}`;
            t.textContent = msg;
            container.appendChild(t);
            setTimeout(() => t.remove(), 4000);
        }
    </script>

    <!-- Lightbox -->
    <div class="lightbox" id="lightbox" onclick="closeLightbox()">
        <button class="lb-close" onclick="closeLightbox()">✕</button>
        <img id="lb-img" src="" alt="Preview">
        <div class="lb-name" id="lb-name"></div>
    </div>

    <!-- Toast container -->
    <div class="toast-container" id="toast-container"></div>
</body>

</html>