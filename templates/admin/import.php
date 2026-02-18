<?php $results = $_SESSION['import_results'] ?? null;
unset($_SESSION['import_results']);
$hasSheetId = !empty(env('GOOGLE_SHEET_ID', ''));
?>
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

        <!-- ========== GOOGLE SHEETS SYNC ========== -->
        <?php if ($hasSheetId): ?>
            <div
                style="margin-bottom:2rem; padding:1.5rem; background:linear-gradient(135deg, rgba(52,168,83,0.08), rgba(66,133,244,0.08)); border:1px solid rgba(52,168,83,0.25); border-radius:var(--radius-sm);">
                <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.75rem;">
                    <span style="font-size:1.5rem;">📊</span>
                    <div>
                        <h3 style="font-size:1rem; margin:0; color:var(--color-text);">Sincronizar desde Google Sheets</h3>
                        <p style="font-size:0.8rem; color:var(--color-text-muted); margin:0.25rem 0 0;">
                            Importa directamente desde tu hoja de cálculo master sin descargar archivos.
                        </p>
                    </div>
                </div>
                <button type="button" id="syncSheetsBtn" onclick="syncFromSheets()"
                    style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.6rem 1.25rem; background:#34A853; color:#fff; border:none; border-radius:var(--radius-sm); cursor:pointer; font-size:0.9rem; font-weight:600; transition:all 0.2s;">
                    🔄 Sincronizar Ahora
                </button>
                <div id="syncStatus" style="display:none; margin-top:1rem;"></div>
            </div>
        <?php endif; ?>

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

        // ========== GOOGLE SHEETS SYNC ==========
        async function syncFromSheets() {
            const btn = document.getElementById('syncSheetsBtn');
            const status = document.getElementById('syncStatus');

            btn.disabled = true;
            btn.innerHTML = '⏳ Sincronizando...';
            btn.style.opacity = '0.7';
            status.style.display = 'block';
            status.innerHTML = '<div style="padding:0.75rem; background:var(--color-surface); border-radius:var(--radius-sm); font-size:0.85rem; color:var(--color-text-muted);">⏳ Descargando datos de Google Sheets...</div>';

            try {
                const res = await fetch('/admin/sync-sheets', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': '<?= csrfToken() ?>',
                        'Content-Type': 'application/json',
                    },
                });
                const data = await res.json();

                if (!res.ok || data.error) {
                    status.innerHTML = `<div style="padding:0.75rem; background:rgba(220,53,69,0.1); border:1px solid rgba(220,53,69,0.3); border-radius:var(--radius-sm); font-size:0.85rem; color:#dc3545;">❌ ${data.error || 'Error desconocido'}</div>`;
                } else {
                    let errHtml = '';
                    if (data.errors && data.errors.length > 0) {
                        errHtml = `<div style="margin-top:0.5rem; font-size:0.8rem; color:var(--color-text-muted);">⚠️ ${data.errors.length} advertencia(s): ${data.errors.slice(0, 5).join(', ')}</div>`;
                    }
                    status.innerHTML = `
                        <div style="padding:1rem; background:rgba(52,168,83,0.1); border:1px solid rgba(52,168,83,0.3); border-radius:var(--radius-sm);">
                            <div style="font-size:0.95rem; font-weight:600; color:#34A853; margin-bottom:0.5rem;">✅ Sincronización completada</div>
                            <div style="display:flex; gap:1.5rem; font-size:0.85rem; color:var(--color-text);">
                                <span>🆕 <strong>${data.inserted}</strong> nuevos</span>
                                <span>🔄 <strong>${data.updated}</strong> actualizados</span>
                                <span>📄 <strong>${data.total}</strong> filas</span>
                                ${data.covers_assigned > 0 ? '<span>🖼️ <strong>' + data.covers_assigned + '</strong> portadas asignadas</span>' : ''}
                            </div>
                            ${errHtml}
                        </div>`;
                }
            } catch (err) {
                status.innerHTML = `<div style="padding:0.75rem; background:rgba(220,53,69,0.1); border:1px solid rgba(220,53,69,0.3); border-radius:var(--radius-sm); font-size:0.85rem; color:#dc3545;">❌ Error de conexión: ${err.message}</div>`;
            }

            btn.disabled = false;
            btn.innerHTML = '🔄 Sincronizar Ahora';
            btn.style.opacity = '1';
        }
    </script>
</body>

</html>