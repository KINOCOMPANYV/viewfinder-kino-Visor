<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carpetas · Viewfinder Admin</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">VF</span> Viewfinder Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem; padding-bottom:3rem;">
        <?php $adminNav = 'folders'; include __DIR__ . '/partials/nav.php'; ?>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:1rem;">
            <h1 style="font-size:1.5rem;">Carpetas</h1>
            <form action="/admin/folders" method="GET" style="display:flex; gap:0.5rem;">
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar carpeta..." class="form-input"
                    style="width:220px; padding:0.5rem 0.75rem; font-size:0.85rem;">
                <button type="submit" class="btn btn-sm btn-primary">Buscar</button>
                <a href="/admin/folders?ver=ocultas" class="btn btn-sm btn-secondary">🙈 Ocultas (<?= $hiddenCount ?>)</a>
            </form>
        </div>

        <p style="color:var(--color-text-muted); font-size:0.85rem; margin-bottom:1rem;">
            Apaga una carpeta para ocultarla en el visor con todo lo que tiene dentro (subcarpetas, fotos y videos).
            La foto de portada de cada carpeta se elige al azar de su contenido en cada sincronización.
        </p>

        <?php if (!$indexReady): ?>
            <div class="alert alert-error">Aún no hay copia de Drive. Ve a Media y pulsa "Sincronizar portadas".</div>
        <?php else: ?>

            <div style="font-size:0.9rem; margin-bottom:1rem; color:var(--color-text-muted);">
                <?php if ($listMode): ?>
                    <a href="/admin/folders" style="color:var(--color-primary);">Todas las carpetas</a>
                    / <?= $onlyHidden ? 'Ocultas por el admin' : 'Búsqueda: «' . e($q) . '»' ?> (<?= count($folders) ?>)
                <?php else: ?>
                    <a href="/admin/folders" style="color:var(--color-primary);">Inicio</a>
                    <?php foreach ($path as $i => $crumb): ?>
                        / <?php if ($i < count($path) - 1): ?>
                            <a href="/admin/folders?id=<?= urlencode($crumb['id']) ?>" style="color:var(--color-primary);"><?= e($crumb['name']) ?></a>
                        <?php else: ?>
                            <strong style="color:var(--color-text);"><?= e($crumb['name']) ?></strong>
                            · <a href="/carpeta/<?= urlencode($crumb['id']) ?>" target="_blank" style="color:var(--color-primary);">ver en el portal ↗</a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($currentHidden): ?>
                <div class="alert alert-error">Esta carpeta está oculta (por ella o por una carpeta superior), así que todo lo de aquí abajo tampoco se ve.</div>
            <?php endif; ?>

            <?php if (empty($folders)): ?>
                <p style="color:var(--color-text-muted);">No hay subcarpetas aquí.</p>
            <?php endif; ?>

            <div class="folder-admin-list">
                <?php foreach ($folders as $f):
                    $own = (bool) $f['own_hidden'];
                    $inherited = $f['is_hidden'] && !$own;
                ?>
                    <div class="folder-admin-row<?= $f['is_hidden'] ? ' is-hidden' : '' ?>" data-id="<?= e($f['file_id']) ?>">
                        <div class="folder-admin-thumb">
                            <?php if ($f['cover_file_id']): ?>
                                <img src="<?= e(lh3Url($f['cover_file_id'], 120)) ?>" alt="" loading="lazy" onerror="this.outerHTML='📁'">
                            <?php else: ?>📁<?php endif; ?>
                        </div>
                        <div class="folder-admin-info">
                            <a class="folder-admin-name" href="/admin/folders?id=<?= urlencode($f['file_id']) ?>" title="<?= e($f['name']) ?>"><?= e($f['name']) ?></a>
                            <div class="folder-admin-meta">
                                <?php if (!empty($f['path'])): ?><?= e($f['path']) ?> · <?php endif; ?>
                                <?= (int) $f['n_folders'] ?> subcarpeta<?= $f['n_folders'] == 1 ? '' : 's' ?> ·
                                <?= (int) $f['n_files'] ?> archivo<?= $f['n_files'] == 1 ? '' : 's' ?>
                            </div>
                        </div>
                        <div class="folder-admin-state"><?= $inherited ? 'Oculta por carpeta superior' : ($own ? 'Oculta' : 'Visible') ?></div>
                        <label class="toggle-switch" title="Mostrar / ocultar">
                            <input type="checkbox" <?= $f['is_hidden'] ? '' : 'checked' ?> <?= $inherited ? 'disabled' : '' ?>
                                onchange="toggleFolder(this)">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <div class="toast-container" id="toast-container"></div>

    <script>
        const CSRF = '<?= csrfToken() ?>';

        function showToast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `toast ${type}`;
            t.textContent = msg;
            document.getElementById('toast-container').appendChild(t);
            setTimeout(() => t.remove(), 4000);
        }

        async function toggleFolder(checkbox) {
            const row = checkbox.closest('.folder-admin-row');
            const visible = checkbox.checked;
            checkbox.disabled = true;

            const form = new FormData();
            form.append('folder_id', row.dataset.id);
            form.append('visible', visible ? '1' : '0');
            try {
                const resp = await fetch('/admin/folders/toggle', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF },
                    body: form
                });
                const data = await resp.json();
                if (!data.ok) throw new Error(data.error || 'Error al guardar');
                row.classList.toggle('is-hidden', !visible);
                row.querySelector('.folder-admin-state').textContent = visible ? 'Visible' : 'Oculta';
                showToast(data.message);
            } catch (e) {
                checkbox.checked = !visible;
                showToast(e.message || 'Error de conexión', 'error');
            }
            checkbox.disabled = false;
        }
    </script>
</body>

</html>
