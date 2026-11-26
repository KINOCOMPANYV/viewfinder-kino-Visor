<?php
/**
 * Pantallas v1 dentro del marco v2: menú lateral (v2_side.php, Configuraciones desplegado) + barra superior.
 * Antes del include: $adminNav = 'products' | 'folders' | ... (clave de config_tools.php). La página lleva <body class="v2 v2-legacy">.
 */
$adminNav = $adminNav ?? '';
$v2 = ['nav' => 'gestion', 'sub' => $adminNav];
?>
<link rel="stylesheet" href="/assets/css/v2.css?v=<?= @filemtime(BASE_DIR . '/assets/css/v2.css') ?>">
<?php include __DIR__ . '/v2_side.php'; ?>
<header class="v2-top v2-legacy-top">
    <button class="v2-burger" type="button" aria-label="Menú" onclick="document.body.classList.toggle('v2-open')"><span></span></button>
    <nav class="v2-crumb"><a href="/admin/configuraciones">Configuraciones</a><?php if (isset($configTools[$adminNav])): ?> <span>›</span> <b><?= e($v2Plain($configTools[$adminNav][1])) ?></b><?php endif; ?></nav>
    <div class="v2-top-actions">
        <div class="v2-user"><span class="v2-avatar"><?= e($v2Initials) ?></span>
            <span class="v2-user-txt"><b><?= e($v2Name) ?></b><small><?= e(Users::label($v2Role)) ?></small></span></div>
    </div>
</header>
