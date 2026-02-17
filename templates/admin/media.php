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

            <!-- Archivos en Drive -->
            <div class="section-title" style="margin-top:3rem;">
                <h2>📂 Archivos en Drive (
                    <?= count($driveFiles) ?>)
                </h2>
            </div>

            <?php if (empty($driveFiles)): ?>
                <div style="text-align:center; padding:3rem; color:var(--color-text-muted);">
                    <div style="font-size:4rem; margin-bottom:1rem;">📭</div>
                    <p>No hay archivos en la carpeta de Drive.</p>
                    <p style="font-size:0.85rem;">Sube archivos usando el formulario de arriba.</p>
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

                        // Buscar SKU match
                        $skuMatch = '';
                        $skus = $db->query("SELECT sku FROM products")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($skus as $sku) {
                            if (stripos($file['name'], $sku) !== false) {
                                $skuMatch = $sku;
                                break;
                            }
                        }
                        ?>
                        <div class="file-card">
                            <?php if ($isImage && $thumbUrl): ?>
                                <img src="<?= e($thumbUrl) ?>" alt="<?= e($file['name']) ?>" class="thumbnail" loading="lazy">
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
    </script>
</body>

</html>