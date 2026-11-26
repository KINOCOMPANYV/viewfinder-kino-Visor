<?php
/** Pestañas de las pantallas v1 (ahora dentro de «Configuraciones»). Antes del include: $adminNav = 'products' | 'folders' | ... */
$adminNav = $adminNav ?? '';
include __DIR__ . '/config_tools.php';
?>
<nav class="admin-nav">
    <a href="/admin/configuraciones">⬅ Sistema · Configuraciones</a>
    <?php foreach ($configTools as $key => [$href, $label]): ?>
        <a href="<?= $href ?>"<?= $adminNav === $key ? ' class="active"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
    <a href="/" target="_blank">🌐 Ver Portal</a>
</nav>
