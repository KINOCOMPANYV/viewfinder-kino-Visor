<?php
/**
 * Marco v2 (menú lateral oscuro + barra superior). Antes del include:
 * $v2 = ['nav' => 'productos', 'title' => 'Productos', 'subtitle' => '…' (opcional), 'sub' => clave de Configuraciones (opcional)]. Cerrar con partials/v2_end.php.
 * Los módulos que aún no existen salen como «Pronto»; los que ya están en v1 enlazan a su pantalla actual.
 */
$v2 = ($v2 ?? []) + ['nav' => '', 'sub' => '', 'title' => 'Admin', 'subtitle' => ''];
?><!DOCTYPE html>
<html lang="es" data-default-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fbfbfd">
    <title><?= e($v2['title']) ?> · Kino Company</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="/assets/css/v2.css?v=<?= @filemtime(BASE_DIR . '/assets/css/v2.css') ?>">
</head>
<body class="v2">
<div class="v2-shell">
    <?php include __DIR__ . '/v2_side.php'; ?>
    <main class="v2-main">
        <header class="v2-top">
            <button class="v2-burger" type="button" aria-label="Menú" onclick="document.body.classList.toggle('v2-open')"><span></span></button>
            <h1><?= e($v2['title']) ?></h1>
            <?php if ($v2['subtitle'] !== ''): ?><p class="v2-sub"><?= e($v2['subtitle']) ?></p><?php endif; ?>
            <div class="v2-top-actions">
                <div class="v2-user"><span class="v2-avatar"><?= e($v2Initials) ?></span>
                    <span class="v2-user-txt"><b><?= e($v2Name) ?></b><small><?= e(Users::label($v2Role)) ?></small></span></div>
            </div>
        </header>
