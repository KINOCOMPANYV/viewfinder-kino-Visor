<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Álbumes · Viewfinder Admin</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        body {
            background: #0a0a0f;
            color: #e8e8f0;
        }

        .album-list-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .album-item {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 1.5rem;
        }

        .album-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .album-icon-preview {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            background: #1a1a24;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 1px solid var(--color-border);
        }

        .album-icon-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .album-info h3 {
            margin: 0;
            font-size: 1.1rem;
        }

        .album-info span {
            font-size: 0.8rem;
            color: var(--color-text-muted);
        }

        .album-form {
            display: grid;
            gap: 0.75rem;
        }

        .form-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        .flash-msg {
            padding: 1rem;
            background: rgba(74, 222, 128, 0.1);
            color: #4ade80;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(74, 222, 128, 0.2);
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

    <main class="container" style="padding-top:2rem;">
        <nav class="admin-nav">
            <a href="/admin">📊 Dashboard</a>
            <a href="/admin/products">📦 Productos</a>
            <a href="/admin/import">📥 Importar</a>
            <a href="/admin/albums" class="active">🗂️ Álbumes</a>
            <a href="/admin/media">🖼️ Media</a>
        </nav>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
            <h1>🗂️ Gestión de Álbumes (Carpetas de Drive)</h1>
            <div style="display:flex; gap:0.75rem;">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" name="action" value="sync_covers" class="btn btn-primary"
                        style="background:linear-gradient(135deg, #c9a84c, #b8963f); white-space:nowrap;"
                        title="Busca dentro de cada carpeta una foto con el mismo nombre de la carpeta y la asigna como portada">
                        🖼️ Auto-asignar Portadas
                    </button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" name="action" value="sync_folders" class="btn btn-primary">🔄 Sincronizar Carpetas
                        de Drive</button>
                </form>
            </div>
        </div>

        <?php if ($flash = $_SESSION['flash'] ?? null):
            unset($_SESSION['flash']); ?>
            <div class="flash-msg">
                <?= e($flash) ?>
            </div>
        <?php endif; ?>

        <p style="color:var(--color-text-muted); margin-bottom:2rem;">
            Los álbumes se crean automáticamente al sincronizar fotos. Aquí puedes activar cuáles aparecen en la inicio
            y subir sus iconos.
        </p>

        <div class="album-list-grid">
            <?php foreach ($albums as $album): ?>
                <div class="album-item">
                    <div class="album-header">
                        <div class="album-icon-preview">
                            <?php if ($album['icon_url']): ?>
                                <img src="<?= e($album['icon_url']) ?>" alt="Icon">
                            <?php else: ?>
                                📁
                            <?php endif; ?>
                        </div>
                        <div class="album-info">
                            <h3>
                                <?= e($album['name']) ?>
                            </h3>
                            <span>ID:
                                <?= e($album['drive_id']) ?>
                            </span>
                        </div>
                    </div>

                    <form method="POST" class="album-form">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="drive_id" value="<?= e($album['drive_id']) ?>">
                        <input type="hidden" name="action" value="update_album">

                        <div class="form-group">
                            <label>URL del Icono (Link de Drive o Directo):</label>
                            <input type="text" name="icon_url" value="<?= e($album['icon_url']) ?>" class="form-input"
                                placeholder="https://...">
                        </div>

                        <div class="form-row">
                            <div class="form-group" style="flex:1">
                                <label>Orden de prioridad:</label>
                                <input type="number" name="order_priority" value="<?= (int) $album['order_priority'] ?>"
                                    class="form-input">
                            </div>
                            <div class="form-group" style="padding-bottom:10px;">
                                <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                                    <input type="checkbox" name="is_active" <?= $album['is_active'] ? 'checked' : '' ?>>
                                    Activo
                                </label>
                            </div>
                            <button type="submit" class="btn btn-secondary">Guardar</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($albums)): ?>
            <div class="empty-state">
                <p>No se han encontrado álbumes. Haz clic en "Sincronizar Carpetas" para empezar.</p>
            </div>
        <?php endif; ?>
    </main>
</body>

</html>