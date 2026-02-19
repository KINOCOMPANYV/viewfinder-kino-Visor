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
            <a href="/admin/import" class="active">📥 Importar</a>
            <a href="/admin/media">🖼️ Media</a>
            <a href="/" target="_blank">🌐 Ver Portal</a>
        </nav>

        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-error"><?= e($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <h1 style="font-size:1.5rem; margin-bottom:0.5rem;">Importar Catálogo</h1>

        <!-- ===== TIP: cover_image_url ===== -->
        <div style="margin-bottom:1.5rem; padding:1rem 1.25rem; background:rgba(201,168,76,0.06); border:1px solid rgba(201,168,76,0.25); border-radius:var(--radius-sm);">
            <p style="font-size:0.88rem; color:var(--color-text); margin:0 0 0.4rem; font-weight:600;">💡 Tip: columna <code style="color:var(--color-primary);">cover_image_url</code></p>
            <p style="font-size:0.82rem; color:var(--color-text-muted); margin:0;">
                Agrega esta columna en tu Excel o Google Sheets con el <strong>link de la imagen</strong> de Google Drive para sincronizar portadas automáticamente. Acepta cualquiera de estos formatos:
            </p>
            <ul style="font-size:0.8rem; color:var(--color-text-muted); margin:0.5rem 0 0 1rem; padding:0;">
                <li><code>https://drive.google.com/file/d/<strong>FILE_ID</strong>/view</code></li>
                <li><code>https://drive.google.com/uc?id=<strong>FILE_ID</strong></code></li>
                <li><code>https://lh3.googleusercontent.com/d/<strong>FILE_ID</strong></code></li>
                <li>Solo el <strong>ID del archivo</strong> de Drive (ej: <code>1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs</code>)</li>
            </ul>
        </div>

        <p style="color:var(--color-text-muted); margin-bottom:1.5rem; font-size:0.9rem;">
            Columnas soportadas: <code style="color:var(--color-primary);">sku, name, category, gender, movement, price_suggested, status, description, cover_image_url</code>
        </p>

        <!-- ========== GOOGLE SHEETS SYNC ========== -->
        <?php if ($hasSheetId): ?>
            <div style="margin-bottom:2rem; padding:1.5rem; background:linear-gradient(135deg, rgba(52,168,83,0.08), rgba(66,133,244,0.08)); border:1px solid rgba(52,168,83,0.25); border-radius:var(--radius-sm);">
                <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.75rem;">
                    <span style="font-size:1.5rem;">📊</span>
                    <div>
                        <h3 style="font-size:1rem; margin:0; color:var(--color-text);">Sincronizar desde Google Sheets</h3>
                        <p style="font-size:0.8rem; color:var(--color-text-muted); margin:0.25rem 0 0;">
                            Importa directamente tu hoja master. Si tiene la columna <code>cover_image_url</code>, las portadas se asignan automáticamente.
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
                    <span class="stat-number" style="color:var(--color-success);">✅ <?= $results['inserted'] ?></span>
                    <span class="stat-label">Productos nuevos insertados</span>
                </div>
                <div class="import-stat">
                    <span class="stat-number" style="color:var(--color-accent);">🔄 <?= $results['updated'] ?></span>
                    <span class="stat-label">Productos actualizados</span>
                </div>
                <div class="import-stat">
                    <span class="stat-number" style="color:var(--color-text-dim);">📄 <?= $results['total'] ?></span>
                    <span class="stat-label">Filas procesadas</span>
                </div>
                <?php if (!empty($results['errors'])): ?>
                    <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid var(--color-border);">
                        <h4 style="color:var(--color-danger); font-size:0.9rem; margin-bottom:0.5rem;">⚠️ Errores (<?= count($results['errors']) ?>)</h4>
                        <ul style="list-style:none; font-size:0.8rem; color:var(--color-text-muted);">
                            <?php foreach (array_slice($results['errors'], 0, 20) as $err): ?>
                                <li style="padding:0.25rem 0;"><?= e($err) ?></li>
                            <?php endforeach; ?>
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
                <div class="upload-hint">Formatos: .xlsx, .csv — Incluye columna <strong>cover_image_url</strong> para importar portadas</div>
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
        document.getElementById('fileInput').addEventListener('change', function(e) {
            const name = e.target.files[0]?.name;
            if (name) document.getElementById('uploadText').textContent = '📎 ' + name;
        });

        const zone = document.getElementById('dropZone');
        ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('dragover'); }));
        ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('dragover'); }));
        document.querySelector('form').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.textContent = '⏳ Importando...';
            btn.disabled = true;
        });

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
                    headers: { 'X-CSRF-Token': '<?= csrfToken() ?>', 'Content-Type': 'application/json' },
                });
                const data = await res.json();

                if (!res.ok || data.error) {
                    status.innerHTML = `<div style="padding:0.75rem; background:rgba(220,53,69,0.1); border:1px solid rgba(220,53,69,0.3); border-radius:var(--radius-sm); font-size:0.85rem; color:#dc3545;">❌ ${data.error || 'Error desconocido'}</div>`;
                } else {
                    const coversTotal = (data.covers_from_sheets || 0) + (data.covers_from_drive || 0);
                    const coverInfo = data.has_cover_column
                        ? `<span>🖼️ <strong>${data.covers_from_sheets}</strong> portadas desde Sheets</span>`
                        : (data.covers_from_drive > 0 ? `<span>🖼️ <strong>${data.covers_from_drive}</strong> portadas desde Drive</span>` : '');

                    const noCoverTip = !data.has_cover_column
                        ? `<div style="margin-top:0.75rem; padding:0.6rem 0.8rem; background:rgba(201,168,76,0.08); border:1px solid rgba(201,168,76,0.2); border-radius:6px; font-size:0.8rem; color:var(--color-primary);">
                            💡 <strong>Tip:</strong> Agrega la columna <code>cover_image_url</code> en tu Google Sheets con el link de Drive de la foto principal. Así se sincronizan automáticamente.
                           </div>`
                        : '';

                    let errHtml = '';
                    if (data.errors && data.errors.length > 0) {
                        errHtml = `<div style="margin-top:0.5rem; font-size:0.8rem; color:var(--color-text-muted);">⚠️ ${data.errors.length} advertencia(s): ${data.errors.slice(0, 5).join(', ')}</div>`;
                    }

                    status.innerHTML = `
                        <div style="padding:1rem; background:rgba(52,168,83,0.1); border:1px solid rgba(52,168,83,0.3); border-radius:var(--radius-sm);">
                            <div style="font-size:0.95rem; font-weight:600; color:#34A853; margin-bottom:0.5rem;">✅ Sincronización completada</div>
                            <div style="display:flex; gap:1.5rem; font-size:0.85rem; color:var(--color-text); flex-wrap:wrap;">
                                <span>🆕 <strong>${data.inserted}</strong> nuevos</span>
                                <span>🔄 <strong>${data.updated}</strong> actualizados</span>
                                <span>📄 <strong>${data.total}</strong> filas</span>
                                ${coverInfo}
                            </div>
                            ${noCoverTip}
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
