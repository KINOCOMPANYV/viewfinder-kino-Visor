<?php
/** Panel interno provisional (v2 módulo 1): destino de Vendedor, Facturación y Bodega hasta que tengan sus bandejas. */
$u = sessionUser();
if (!$u) {
    redirect('/admin/login');
}
if (in_array($u['role'], ['admin', 'dev'], true)) {
    redirect('/admin');
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel · Viewfinder</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>
    <main class="container" style="padding-top:2rem;">
        <h1 style="font-size:1.5rem;">Hola, <?= e($u['name'] !== '' ? $u['name'] : $u['username']) ?></h1>
        <p style="color:var(--color-text-muted); margin-top:0.5rem;">
            Perfil: <b><?= e(Users::label($u['role'])) ?></b>. Tus pantallas de trabajo llegan en los próximos módulos de la versión 2.
        </p>
        <p style="margin-top:1rem;"><a class="btn btn-secondary" href="/">Ir al catálogo</a></p>
    </main>
</body>

</html>
