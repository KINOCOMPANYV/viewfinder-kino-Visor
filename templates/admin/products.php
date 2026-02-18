<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos · Viewfinder Admin</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        /* Inline edit */
        .editable {
            cursor: pointer;
            padding: 0.25rem 0.4rem;
            border-radius: 4px;
            transition: all 0.2s;
            position: relative;
        }

        .editable:hover {
            background: rgba(201, 168, 76, 0.1);
            outline: 1px dashed rgba(201, 168, 76, 0.4);
        }

        .editable::after {
            content: '✏️';
            font-size: 0.6rem;
            position: absolute;
            top: -2px;
            right: -4px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .editable:hover::after {
            opacity: 1;
        }

        .edit-input {
            background: var(--color-surface-2);
            border: 1.5px solid var(--color-primary);
            color: var(--color-text);
            padding: 0.3rem 0.5rem;
            border-radius: 4px;
            font-size: 0.85rem;
            font-family: var(--font);
            width: 100%;
            outline: none;
            box-shadow: 0 0 0 3px rgba(201, 168, 76, 0.2);
        }

        .edit-select {
            background: var(--color-surface-2);
            border: 1.5px solid var(--color-primary);
            color: var(--color-text);
            padding: 0.3rem 0.5rem;
            border-radius: 4px;
            font-size: 0.85rem;
            font-family: var(--font);
            outline: none;
            box-shadow: 0 0 0 3px rgba(201, 168, 76, 0.2);
        }

        .saving {
            opacity: 0.5;
            pointer-events: none;
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
            padding: 0.7rem 1.2rem;
            border-radius: var(--radius-sm);
            color: #fff;
            font-weight: 600;
            font-size: 0.8rem;
            animation: toast-in 0.3s ease, toast-out 0.3s ease 3.7s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            max-width: 320px;
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

        .edit-hint {
            text-align: center;
            font-size: 0.75rem;
            color: var(--color-text-dim);
            margin-bottom: 1rem;
            padding: 0.5rem;
            background: rgba(201, 168, 76, 0.05);
            border-radius: var(--radius-sm);
            border: 1px dashed rgba(201, 168, 76, 0.2);
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
            <a href="/admin/products" class="active">📦 Productos</a>
            <a href="/admin/import">📥 Importar Excel</a>
            <a href="/" target="_blank">🌐 Ver Portal</a>
        </nav>

        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="alert alert-success">
                <?= e($_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-error">
                <?= e($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div
            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
            <h1 style="font-size:1.5rem;">Productos <span style="color:var(--color-text-dim); font-size:0.9rem;">(
                    <?= $total ?>)
                </span></h1>
            <form action="/admin/products" method="GET" style="display:flex; gap:0.5rem;">
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar SKU o nombre..." class="form-input"
                    style="width:250px; padding:0.5rem 0.75rem; font-size:0.85rem;">
                <button type="submit" class="btn btn-sm btn-primary">Buscar</button>
            </form>
        </div>

        <?php if (!empty($products)): ?>
            <div class="edit-hint">
                💡 Haz clic en cualquier campo para editarlo. Los cambios se guardan en la BD y en Google Sheets.
            </div>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Nombre</th>
                            <th>Categoría</th>
                            <th>Género</th>
                            <th>Precio</th>
                            <th>Estado</th>
                            <th>Actualizado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr data-id="<?= $p['id'] ?>">
                                <td><a href="/producto/<?= e($p['sku']) ?>" target="_blank" style="font-weight:600;">
                                        <?= e($p['sku']) ?>
                                    </a></td>
                                <td>
                                    <span class="editable" data-field="name" data-value="<?= e($p['name']) ?>"
                                        onclick="startEdit(this)">
                                        <?= e(truncate($p['name'], 40)) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="editable" data-field="category" data-value="<?= e($p['category']) ?>"
                                        onclick="startEdit(this)">
                                        <?= e($p['category']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="editable" data-field="gender" data-value="<?= e($p['gender']) ?>"
                                        data-type="select" data-options="Hombre,Mujer,Unisex" onclick="startEdit(this)">
                                        <?= e(ucfirst($p['gender'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="editable" data-field="price_suggested"
                                        data-value="<?= e($p['price_suggested']) ?>" data-type="number"
                                        onclick="startEdit(this)">
                                        <?= $p['price_suggested'] > 0 ? formatPrice($p['price_suggested']) : '—' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="editable" data-field="status" data-value="<?= e($p['status']) ?>"
                                        data-type="select" data-options="active,discontinued" onclick="startEdit(this)">
                                        <?php if ($p['status'] === 'active'): ?>
                                            <span class="badge badge-active">Activo</span>
                                        <?php else: ?>
                                            <span class="badge badge-discontinued">Descont.</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td style="color:var(--color-text-dim); font-size:0.8rem;">
                                    <?= date('d/m/y', strtotime($p['updated_at'])) ?>
                                </td>
                                <td>
                                    <form method="POST" action="/admin/product/delete"
                                        onsubmit="return confirm('¿Eliminar producto <?= e($p['sku']) ?>?')"
                                        style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"
                                            style="padding:0.3rem 0.6rem; font-size:0.7rem;">
                                            🗑
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= min($totalPages, 20); $i++): ?>
                        <?php $url = '/admin/products?' . http_build_query(['q' => $q, 'page' => $i]); ?>
                        <?php if ($i === $page): ?>
                            <span class="current">
                                <?= $i ?>
                            </span>
                        <?php else: ?>
                            <a href="<?= $url ?>">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">📦</div>
                <h3>Sin productos</h3>
                <p>Importa tu catálogo desde un archivo Excel.</p>
                <a href="/admin/import" class="btn btn-primary" style="margin-top:1rem;">📥 Importar Excel</a>
            </div>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Viewfinder Admin Panel · v1.0</p>
        </div>
    </footer>

    <div class="toast-container" id="toast-container"></div>

    <script>
        const CSRF = '<?= csrfToken() ?>';
        let activeEdit = null;

        function showToast(msg, type = 'success') {
            const container = document.getElementById('toast-container');
            const t = document.createElement('div');
            t.className = `toast ${type}`;
            t.textContent = msg;
            container.appendChild(t);
            setTimeout(() => t.remove(), 4000);
        }

        function startEdit(el) {
            if (activeEdit) cancelEdit();

            const field = el.dataset.field;
            const value = el.dataset.value;
            const type = el.dataset.type || 'text';
            const options = el.dataset.options || '';

            el.dataset.original = el.innerHTML;
            activeEdit = el;

            let input;
            if (type === 'select') {
                input = document.createElement('select');
                input.className = 'edit-select';
                options.split(',').forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt.trim();
                    o.textContent = opt.trim();
                    if (opt.trim().toLowerCase() === value.toLowerCase()) o.selected = true;
                    input.appendChild(o);
                });
                input.addEventListener('change', () => saveEdit(el, input.value));
            } else {
                input = document.createElement('input');
                input.className = 'edit-input';
                input.type = type === 'number' ? 'number' : 'text';
                input.value = value;
                if (type === 'number') input.step = '0.01';
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') saveEdit(el, input.value);
                    if (e.key === 'Escape') cancelEdit();
                });
            }

            el.innerHTML = '';
            el.appendChild(input);
            input.focus();
            if (input.select) input.select();

            // Close on outside click
            setTimeout(() => {
                document.addEventListener('click', outsideClick);
            }, 100);
        }

        function outsideClick(e) {
            if (activeEdit && !activeEdit.contains(e.target)) {
                const input = activeEdit.querySelector('input, select');
                if (input) saveEdit(activeEdit, input.value);
            }
        }

        function cancelEdit() {
            if (activeEdit) {
                activeEdit.innerHTML = activeEdit.dataset.original;
                activeEdit = null;
                document.removeEventListener('click', outsideClick);
            }
        }

        async function saveEdit(el, newValue) {
            document.removeEventListener('click', outsideClick);

            const field = el.dataset.field;
            const oldValue = el.dataset.value;
            const row = el.closest('tr');
            const id = row.dataset.id;

            if (newValue === oldValue) {
                cancelEdit();
                return;
            }

            el.classList.add('saving');
            el.innerHTML = '⏳';

            const form = new FormData();
            form.append('id', id);
            form.append('field', field);
            form.append('value', newValue);

            try {
                const resp = await fetch('/admin/product/update', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF },
                    body: form
                });
                const data = await resp.json();

                if (data.ok) {
                    el.dataset.value = newValue;

                    // Render display value
                    if (field === 'status') {
                        el.innerHTML = newValue === 'active'
                            ? '<span class="badge badge-active">Activo</span>'
                            : '<span class="badge badge-discontinued">Descont.</span>';
                    } else if (field === 'price_suggested') {
                        const num = parseFloat(newValue);
                        el.textContent = num > 0 ? '$' + num.toLocaleString() : '—';
                    } else if (field === 'gender') {
                        el.textContent = newValue.charAt(0).toUpperCase() + newValue.slice(1);
                    } else {
                        el.textContent = newValue;
                    }

                    let msg = '✅ Guardado';
                    if (data.sheets_updated) {
                        msg += ' + Google Sheets actualizado';
                    } else if (data.sheets_error) {
                        msg += ' (Sheets: ' + data.sheets_error + ')';
                    }
                    showToast(msg);
                } else {
                    el.innerHTML = el.dataset.original;
                    showToast(data.error || 'Error al guardar', 'error');
                }
            } catch (e) {
                el.innerHTML = el.dataset.original;
                showToast('Error de conexión', 'error');
            }

            el.classList.remove('saving');
            activeEdit = null;
        }
    </script>
</body>

</html>