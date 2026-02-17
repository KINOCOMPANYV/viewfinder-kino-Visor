<?php $results = $_SESSION['import_results'] ?? null;
unset($_SESSION['import_results']); ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Excel · Viewfinder Admin</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">VF</span> Viewfinder Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem;">
        <nav class="admin-nav">
            <a href="/admin">📊 Dashboard</a>
            <a href="/admin/products">📦 Productos</a>
            <a href="/admin/import" class="active">📥 Importar Excel</a>
            <a href="/" target="_blank">🌐 Ver Portal</a>
        </nav>

        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-error">
                <?= e($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <h1 style="font-size:1.5rem; margin-bottom:0.5rem;">Importar Catálogo</h1>
        <p style="color:var(--color-text-muted); margin-bottom:2rem;">
            Sube un archivo Excel (.xlsx) o CSV con las columnas: <code
                style="color:var(--color-primary);">sku, name, category, gender, movement, price_suggested, status, description</code>
        </p>

        <!-- Import Results -->
        <?php if ($results): ?>
            <div class="import-results fade-in" style="margin-bottom:2rem;">
                <h3 style="margin-bottom:1rem; font-size:1.1rem;">📊 Resultado de la importación</h3>
                <div class="import-stat">
                    <span class="stat-number" style="color:var(--color-success);">✅
                        <?= $results['inserted'] ?>
                    </span>
                    <span class="stat-label">Productos nuevos insertados</span>
                </div>
                <div class="import-stat">
                    <span class="stat-number" style="color:var(--color-accent);">🔄
                        <?= $results['updated'] ?>
                    </span>
                    <span class="stat-label">Productos actualizados</span>
                </div>
                <div class="import-stat">
                    <span class="stat-number" style="color:var(--color-text-dim);">📄
                        <?= $results['total'] ?>
                    </span>
                    <span class="stat-label">Filas procesadas</span>
                </div>
                <?php if (!empty($results['errors'])): ?>
                    <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid var(--color-border);">
                        <h4 style="color:var(--color-danger); font-size:0.9rem; margin-bottom:0.5rem;">
                            ⚠️ Errores (
                            <?= count($results['errors']) ?>)
                        </h4>
                        <ul style="list-style:none; font-size:0.8rem; color:var(--color-text-muted);">
                            <?php foreach (array_slice($results['errors'], 0, 20) as $err): ?>
                                <li style="padding:0.25rem 0;">
                                    <?= e($err) ?>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($results['errors']) > 20): ?>
                                <li style="padding:0.25rem 0; color:var(--color-text-dim);">
                                    ... y
                                    <?= count($results['errors']) - 20 ?> errores más.
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <form method="POST" action="/admin/import" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <div class="file-upload" id="dropZone">
                <input type="file" name="excel_file" id="fileInput" accept=".xlsx,.csv,.xls" required>
                <div class="upload-icon">📄</div>
                <div class="upload-text" id="uploadText">
                    Arrastra tu archivo aquí o haz clic para seleccionar
                </div>
                <div class="upload-hint">Formatos aceptados: .xlsx, .csv (máx 100MB)</div>
            </div>

            <div
                style="margin-top:1rem; padding:1rem; background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-sm);">
                <h4 style="font-size:0.85rem; color:var(--color-text-muted); margin-bottom:0.5rem;">📋 Columnas
                    esperadas:</h4>
                <div
                    style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:0.5rem; font-size:0.8rem;">
                    <span><strong style="color:var(--color-success);">sku</strong> (requerido)</span>
                    <span><strong style="color:var(--color-success);">name</strong> (requerido)</span>
                    <span>category</span>
                    <span>gender</span>
                    <span>movement</span>
                    <span>price_suggested</span>
                    <span>status</span>
                    <span>description</span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top:1.5rem;" id="submitBtn">
                📥 Importar Catálogo
            </button>
        </form>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Viewfinder Admin Panel · v1.0</p>
        </div>
    </footer>

    <script>
        // File name display
        document.getElementById('fileInput').addEventListener('change', function (e) {
            const name = e.target.files[0]?.name;
            if (name) {
                document.getElementById('uploadText').textContent = '📎 ' + name;
            }
        });

        // Drag and drop visual
        const zone = document.getElementById('dropZone');
        ['dragenter', 'dragover'].forEach(event => {
            zone.addEventListener(event, (e) => { e.preventDefault(); zone.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(event => {
            zone.addEventListener(event, (e) => { e.preventDefault(); zone.classList.remove('dragover'); });
        });

        // Loading state on submit
        document.querySelector('form').addEventListener('submit', function () {
            const btn = document.getElementById('submitBtn');
            btn.textContent = '⏳ Importando...';
            btn.disabled = true;
        });
    </script>
</body>

</html>