<?php
/**
 * Admin — Sincronizar: hoja maestra (datos de productos) y Drive (fotos, carpetas y portadas).
 */
$db = getDB();
$lastSheetSync = $db->query("SELECT MAX(updated_at) FROM products")->fetchColumn();
$lastDriveSync = null;
try {
    $lastDriveSync = $db->query("SELECT MAX(synced_at) FROM drive_index")->fetchColumn();
} catch (\PDOException $e) { /* índice aún no creado */ }
$sheetId = env('GOOGLE_SHEET_ID', '');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizar · Viewfinder Admin</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .sync-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.5rem; margin-bottom:1.5rem; }
        .sync-card h2 { font-size:1.15rem; margin-bottom:0.35rem; }
        .sync-card p { color:var(--color-text-muted); font-size:0.85rem; margin-bottom:1rem; }
        .sync-actions { display:flex; gap:0.75rem; flex-wrap:wrap; }
        .sync-result { margin-top:1rem; font-size:0.85rem; display:none; padding:0.9rem; border-radius:var(--radius-sm); background:var(--color-surface-2); }
        .sync-result.ok { border:1px solid rgba(52,168,83,0.35); }
        .sync-result.warn { border:1px solid rgba(251,191,36,0.4); }
        .sync-result.error { border:1px solid rgba(220,53,69,0.4); color:#f87171; }
        .sync-result .skus { max-height:120px; overflow-y:auto; margin-top:0.5rem; padding:0.5rem; background:rgba(0,0,0,0.25); border-radius:4px; font-family:monospace; font-size:0.78rem; }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">VF</span> Viewfinder Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem; padding-bottom:3rem; max-width:860px;">
        <?php $adminNav = 'sync'; include __DIR__ . '/partials/nav.php'; ?>
        <h1 style="font-size:1.5rem; margin-bottom:1.5rem;">Sincronizar</h1>

        <section class="sync-card">
            <h2>📋 Hoja maestra de productos</h2>
            <p>
                Trae nombre, color, marca, categoría, precios, stock y materiales tal cual están en la hoja.
                Los códigos que ya no estén en la hoja se ocultan (no se borran). Activar o desactivar se hace solo en
                <a href="/admin/products" style="color:var(--color-primary);">Productos</a>; la hoja no lo cambia.
                <?php if ($sheetId): ?>
                    · <a href="https://docs.google.com/spreadsheets/d/<?= e($sheetId) ?>/edit" target="_blank" rel="noopener" style="color:var(--color-primary);">Abrir la hoja ↗</a>
                <?php else: ?>
                    <br><strong style="color:#f87171;">Falta la variable GOOGLE_SHEET_ID en Railway.</strong>
                <?php endif; ?>
                <br>Última actualización de productos: <?= $lastSheetSync ? date('d/m/Y H:i', strtotime($lastSheetSync)) : '—' ?>
            </p>
            <div class="sync-actions">
                <button class="btn btn-secondary" onclick="syncSheet(true, this)">👁️ Vista previa</button>
                <button class="btn btn-primary" onclick="syncSheet(false, this)">🔄 Sincronizar hoja</button>
            </div>
            <div class="sync-result" id="sheetResult"></div>
        </section>

        <section class="sync-card">
            <h2>🖼️ Drive: fotos, carpetas y portadas</h2>
            <p>
                Copia el árbol de Drive, elige una foto al azar para cada carpeta y asigna la portada de los productos
                que no tienen (la foto se llama como el código). "Reasignar todas" vuelve a elegir la portada de todos.
                <br>Última copia de Drive: <?= $lastDriveSync ? date('d/m/Y H:i', strtotime($lastDriveSync)) : '—' ?>
            </p>
            <div class="sync-actions">
                <button class="btn btn-primary" onclick="syncDrive(false, this)">🔄 Sincronizar portadas</button>
                <button class="btn btn-secondary" onclick="if (confirm('¿Reasignar la portada de TODOS los productos?')) syncDrive(true, this)">♻️ Reasignar todas</button>
            </div>
            <div class="sync-result" id="driveResult"></div>
        </section>

        <section class="sync-card">
            <h2>🔎 ¿Por qué no sale la foto?</h2>
            <p>Escribe un código y verás sus fotos en Drive, dónde están y qué las oculta.</p>
            <form class="sync-actions" onsubmit="checkPhoto(event)">
                <input type="text" id="photoSku" class="form-input" placeholder="Ej: 1514G-12" required
                       style="flex:1; min-width:160px; padding:0.55rem 0.75rem; border-radius:8px; border:1px solid var(--color-border); background:var(--color-surface-2, #16161f); color:inherit;">
                <button class="btn btn-primary" type="submit">Revisar</button>
            </form>
            <div class="sync-result" id="photoResult"></div>
        </section>
    </main>

    <script>
        const CSRF = '<?= csrfToken() ?>';

        function showResult(id, cls, html) {
            const box = document.getElementById(id);
            box.className = 'sync-result ' + cls;
            box.style.display = 'block';
            box.innerHTML = html;
        }

        function esc(s) {
            return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        async function postJson(url) {
            const res = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF } });
            const text = await res.text();
            try {
                return { status: res.status, data: JSON.parse(text) };
            } catch (_) {
                throw new Error(`Respuesta inesperada del servidor (HTTP ${res.status}). Recarga la página e intenta de nuevo.`);
            }
        }

        async function syncSheet(dryRun, btn, force = false) {
            const label = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳ ' + (dryRun ? 'Calculando...' : 'Sincronizando...');
            showResult('sheetResult', '', '⏳ Descargando la hoja maestra...');
            try {
                const q = dryRun ? '?dry_run=1' : (force ? '?force=1' : '');
                const { status, data } = await postJson('/admin/sync-sheets' + q);
                if (status === 409) {
                    showResult('sheetResult', 'warn', '⚠️ ' + esc(data.error) +
                        ` <button class="btn btn-sm btn-secondary" style="margin-left:0.5rem;" onclick="syncSheet(false, this, true)">Sincronizar igual</button>`);
                } else if (data.error) {
                    showResult('sheetResult', 'error', '❌ ' + esc(data.error));
                } else {
                    let html = `<strong>${esc(data.message)}</strong><div style="margin-top:0.4rem; color:var(--color-text-muted);">📄 ${data.total} códigos en la hoja · columnas leídas: ${esc(data.columns.join(', '))}</div>`;
                    if (data.removed_skus_preview.length) {
                        html += `<div class="skus">${data.removed_from_sheet > data.removed_skus_preview.length ? 'Primeros ' + data.removed_skus_preview.length + ' de ' + data.removed_from_sheet + ' que no están en la hoja: ' : 'No están en la hoja: '}${esc(data.removed_skus_preview.join(', '))}</div>`;
                    }
                    if (data.duplicate_skus.length) {
                        html += `<div class="skus">Repetidos en la hoja: ${esc(data.duplicate_skus.join(', '))}</div>`;
                    }
                    if (data.dry_run) {
                        html += `<button class="btn btn-sm btn-primary" style="margin-top:0.75rem;" onclick="syncSheet(false, this)">✅ Sincronizar de verdad</button>`;
                    }
                    showResult('sheetResult', data.dry_run ? 'warn' : 'ok', html);
                }
            } catch (e) {
                showResult('sheetResult', 'error', '❌ ' + esc(e.message));
            }
            btn.disabled = false;
            btn.innerHTML = label;
        }

        async function checkPhoto(ev) {
            ev.preventDefault();
            const sku = document.getElementById('photoSku').value.trim();
            if (!sku) return;
            showResult('photoResult', '', '⏳ Revisando...');
            try {
                const res = await fetch('/admin/photo-check?sku=' + encodeURIComponent(sku));
                const data = await res.json();
                if (data.error) return showResult('photoResult', 'error', '❌ ' + esc(data.error));
                const p = data.product;
                let html = p
                    ? `<strong>${esc(data.sku)}</strong> · ${esc(p.name || '')}<div style="margin-top:0.3rem; color:var(--color-text-muted);">`
                      + `${p.active ? '✅ Activo' : '⏸️ Desactivado'} · ${p.in_sheet ? 'en la hoja' : 'no está en la hoja'} · familia: ${esc(p.parent_sku || '—')} · `
                      + `${p.has_cover ? 'tiene portada' : 'sin portada'} · la ficha muestra <strong>${data.visible_count}</strong> archivo(s)</div>`
                    : `<strong>${esc(data.sku)}</strong>`;
                if (data.problem) html += `<div style="margin-top:0.5rem;">⚠️ ${esc(data.problem)}</div>`;
                if (data.files.length) {
                    html += '<ul style="margin:0.6rem 0 0; padding-left:1.1rem;">' + data.files.map(f =>
                        `<li style="margin-bottom:0.35rem;">${f.image ? '🖼️' : '🎥'} <a href="https://drive.google.com/file/d/${esc(f.id)}/view" target="_blank" rel="noopener" style="color:var(--color-primary);">${esc(f.name)}</a>`
                        + `<div style="font-size:0.8rem; color:var(--color-text-muted);">${esc(f.path || '(raíz)')}</div>`
                        + (f.problem ? `<div style="font-size:0.85rem; color:#fbbf24;">🚫 ${esc(f.problem)}`
                            + (f.switch_folder_id ? ` · <a href="/admin/folders?id=${encodeURIComponent(f.switch_folder_id)}" style="color:var(--color-primary);">ir a la carpeta</a>` : '') + '</div>'
                          : '<div style="font-size:0.85rem; color:#4ade80;">✅ Se muestra</div>')
                        + '</li>').join('') + '</ul>';
                }
                if (data.last_drive_sync) html += `<div style="margin-top:0.5rem; font-size:0.8rem; color:var(--color-text-muted);">Copia de Drive: ${esc(data.last_drive_sync)}</div>`;
                showResult('photoResult', data.problem ? 'warn' : 'ok', html);
            } catch (e) {
                showResult('photoResult', 'error', '❌ ' + esc(e.message));
            }
        }

        async function syncDrive(force, btn) {
            const label = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳ Sincronizando...';
            showResult('driveResult', '', '⏳ Recorriendo Drive (puede tardar uno o dos minutos)...');
            try {
                const { data } = await postJson('/admin/media/sync-covers' + (force ? '?force=1' : ''));
                if (!data.ok) {
                    showResult('driveResult', 'error', '❌ ' + esc(data.error || data.message || 'Error al sincronizar'));
                } else {
                    let html = `<strong>${esc(data.message || 'Listo')}</strong>`;
                    if (data.folders) {
                        html += `<div style="margin-top:0.4rem; color:var(--color-text-muted);">📂 ${data.folders.covers} carpetas con portada · ${data.folders.hidden} elementos ocultos</div>`;
                    }
                    showResult('driveResult', 'ok', html);
                }
            } catch (e) {
                showResult('driveResult', 'error', '❌ ' + esc(e.message));
            }
            btn.disabled = false;
            btn.innerHTML = label;
        }
    </script>
</body>

</html>
