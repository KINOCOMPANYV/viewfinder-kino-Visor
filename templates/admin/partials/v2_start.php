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
    <?php foreach (($v2['styles'] ?? []) as $sheet): ?><link rel="stylesheet" href="<?= e(assetVersion($sheet)) ?>"><?php endforeach; ?>
</head>
<body class="v2">
<div class="v2-shell">
    <?php include __DIR__ . '/v2_side.php'; ?>
    <main class="v2-main">
        <header class="v2-top">
            <button class="v2-burger" type="button" aria-label="Abrir menú" aria-controls="v2Side" aria-expanded="false" data-v2-menu-toggle><span></span></button>
            <h1><?= e($v2['title']) ?></h1>
            <?php if ($v2['subtitle'] !== ''): ?><p class="v2-sub"><?= e($v2['subtitle']) ?></p><?php endif; ?>
            <div class="v2-top-actions">
                <div class="v2-user"><span class="v2-avatar"><?= e($v2Initials) ?></span>
                    <span class="v2-user-txt"><b><?= e($v2Name) ?></b><small><?= e(Users::label($v2Role)) ?></small></span></div>
            </div>
        </header>
        <?php
        // Inventario SYS desactualizado (solo quienes pueden cargarlo). Lee la última carga aplicada en inventory_imports.
        $v2Inv = null;
        if (in_array(currentRole(), ['admin', 'dev', 'facturacion'], true)) {
            try { require_once __DIR__ . '/../../../src/services/InventorySync.php'; $v2Inv = (new InventorySync(getDB()))->freshness(); } catch (Throwable $e) { $v2Inv = null; }
        }
        if ($v2Inv && $v2Inv['stale']): ?>
        <a class="v2-cobro v2-cobro--40" href="/admin/inventario">
            ‼ <b>Inventario SYS sin actualizar:</b> <?= $v2Inv['minutes'] === null ? 'todavía no hay ninguna carga.' : 'la última carga fue hace ' . e(InventorySync::ageText($v2Inv['minutes'])) . '.' ?> <u>Subir archivo</u></a>
        <?php endif; ?>
        <?php if ($v2Cobro[0] && in_array(currentRole(), ['vendedor', 'facturacion', 'admin', 'dev'], true)): ?>
        <a class="v2-cobro<?= $v2Cobro[1] ? ' v2-cobro--40' : '' ?>" href="/clientes?cartera=<?= $v2Cobro[1] ? '40' : '20' ?>">
            <?= $v2Cobro[1] ? '‼' : '⚠' ?> <b>Por cobrar:</b> <?= currentRole() === 'vendedor' ? 'tienes ' : '' ?><?= $v2Cobro[0] ?> cliente<?= $v2Cobro[0] > 1 ? 's' : '' ?> con deuda de más de 20 días<?= $v2Cobro[1] ? ' (' . $v2Cobro[1] . ' de más de 40)' : '' ?>. <u>Ver lista</u></a>
        <?php endif; ?>
