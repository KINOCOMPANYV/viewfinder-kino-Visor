<?php
/**
 * Marco v2 (menú lateral oscuro + barra superior). Antes del include:
 * $v2 = ['nav' => 'productos', 'title' => 'Productos', 'subtitle' => '…' (opcional)]. Cerrar con partials/v2_end.php.
 * Los módulos que aún no existen salen como «Pronto»; los que ya están en v1 enlazan a su pantalla actual.
 */
$v2 = ($v2 ?? []) + ['nav' => '', 'title' => 'Admin', 'subtitle' => ''];
$v2User = sessionUser();
$v2Name = $v2User ? ($v2User['name'] !== '' ? $v2User['name'] : $v2User['username']) : (string) (env('ADMIN_USER') ?: 'Admin');
$v2Role = currentRole();
require_once __DIR__ . '/../../../src/services/Users.php';
$v2Initials = strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N} ]/u', '', $v2Name) ?: 'A', 0, 2));
$v2Pending = null;
try {
    $v2Pending = (int) getDB()->query("SELECT COUNT(*) FROM warehouse_orders WHERE status <> 'listo'")->fetchColumn();
} catch (Throwable $e) {
}
// [clave, ruta (null = pronto), texto, icono SVG]
$v2Menu = [
    ['vender', null, 'Vender', '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>'],
    ['pedidos', '/pedidos-alistar', 'Pedidos', '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>'],
    ['productos', '/admin/productos', 'Productos', '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'],
    ['catalogo', '/', 'Catálogo Online', '<circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6"/>'],
    ['clientes', null, 'Clientes', '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'],
    ['transacciones', null, 'Transacciones', '<path d="M12 2v20"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
    ['finanzas', null, 'Finanzas', '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 3h12"/>'],
    ['estadisticas', null, 'Estadísticas', '<path d="M5 21V12"/><path d="M12 21V4"/><path d="M19 21v-6"/>'],
    ['usuarios', '/admin/usuarios', 'Usuarios', '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18.5 14a6.5 6.5 0 0 1 3 6"/>'],
    ['gestion', '/admin', 'Configuraciones', '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>'],
];
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
    <aside class="v2-side" id="v2Side">
        <div class="v2-brand">
            <a href="/admin/productos">KINO COMPANY S.A.S</a>
            <span class="v2-badge">Sistema v2</span>
        </div>
        <nav class="v2-menu">
            <?php foreach ($v2Menu as [$key, $href, $label, $svg]): $on = $v2['nav'] === $key; ?>
                <?php if ($href === null): ?>
                    <span class="v2-item off" title="Módulo en construcción"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $svg ?></svg><?= e($label) ?><em>Pronto</em></span>
                <?php else: ?>
                    <a class="v2-item<?= $on ? ' on' : '' ?>" href="<?= $href ?>"<?= $key === 'catalogo' ? ' target="_blank"' : '' ?>>
                        <?php if ($key === 'pedidos' && $v2Pending): ?><b class="v2-count"><?= $v2Pending ?></b><?php else: ?><svg viewBox="0 0 24 24" aria-hidden="true"><?= $svg ?></svg><?php endif; ?><?= e($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <a class="v2-logout" href="/admin/logout">Cerrar sesión</a>
    </aside>
    <div class="v2-scrim" onclick="document.body.classList.remove('v2-open')"></div>
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
