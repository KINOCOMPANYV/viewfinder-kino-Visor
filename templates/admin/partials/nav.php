<?php
/** Menú del admin. Antes del include: $adminNav = 'products' | 'folders' | ... */
$adminNav = $adminNav ?? '';
$adminNavItems = [
    'dashboard' => ['/admin', '📊 Dashboard'],
    'products' => ['/admin/products', '📦 Productos'],
    'productos' => ['/admin/productos', '🛍 Productos v2'],
    'folders' => ['/admin/folders', '📂 Carpetas'],
    'media' => ['/admin/media', '🖼️ Media'],
    'sync' => ['/admin/sync', '🔄 Sincronizar'],
    'hoja' => ['/admin/hoja', '📋 Hoja master'],
    'problemas' => ['/admin/problemas', '🩺 Problemas'],
    'inventario' => ['/admin/inventario', '📦 Inventario'],
    'precios' => ['/admin/precios', '💲 Precios'],
    'remision' => ['/admin/remision', '🚚 Cotización'],
    'condenados' => ['/admin/condenados', '🚫 Condenados'],
    'pedidos' => ['/admin/pedidos', '🗄 Pedidos'],
    'usuarios' => ['/admin/usuarios', '👥 Usuarios'],
    'registro' => ['/admin/registro', '🧾 Registro'],
];
?>
<nav class="admin-nav">
    <?php foreach ($adminNavItems as $key => [$href, $label]): ?>
        <a href="<?= $href ?>"<?= $adminNav === $key ? ' class="active"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
    <a href="/" target="_blank">🌐 Ver Portal</a>
</nav>
