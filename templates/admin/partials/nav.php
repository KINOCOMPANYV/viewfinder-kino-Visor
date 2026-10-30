<?php
/** Menú del admin. Antes del include: $adminNav = 'products' | 'folders' | ... */
$adminNav = $adminNav ?? '';
$adminNavItems = [
    'dashboard' => ['/admin', '📊 Dashboard'],
    'products' => ['/admin/products', '📦 Productos'],
    'folders' => ['/admin/folders', '📂 Carpetas'],
    'media' => ['/admin/media', '🖼️ Media'],
    'sync' => ['/admin/sync', '🔄 Sincronizar'],
];
?>
<nav class="admin-nav">
    <?php foreach ($adminNavItems as $key => [$href, $label]): ?>
        <a href="<?= $href ?>"<?= $adminNav === $key ? ' class="active"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
    <a href="/" target="_blank">🌐 Ver Portal</a>
</nav>
