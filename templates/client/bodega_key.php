<?php
/** Pantalla «Clave de bodega» (requireBodega): se escribe una vez por equipo; $bodegaNext = a dónde volver. */
$bodegaErr = $_SESSION['bodega_error'] ?? '';
unset($_SESSION['bodega_error']);
$bodegaOn = (string) env('PEDIDOS_CLAVE', '') !== '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Pedidos bodega · Viewfinder</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .bk { max-width: 360px; margin: 12vh auto 0; padding: 1.6rem; border-radius: 16px; border: 1px solid #444; background: var(--color-surface,#1c1c1e); }
        .bk h1 { font-size: 1.3rem; margin: 0 0 .6rem; }
        .bk p { color: var(--color-text-muted,#c8c8d0); font-size: .92rem; }
        .bk input { width: 100%; min-height: 46px; margin: .4rem 0 .8rem; padding: 0 .8rem; border-radius: 10px; border: 1px solid #555; background: transparent; color: inherit; font-size: 1.05rem; box-sizing: border-box; }
        .bk button { width: 100%; min-height: 46px; border: 0; border-radius: 99px; background: #c4a46b; color: #111; font-weight: 800; font-size: 1rem; cursor: pointer; }
        .bk .err { color: #ff7b72; font-weight: 700; }
    </style>
</head>
<body>
<main class="container">
    <form class="bk" method="post" action="/pedidos-alistar/entrar">
        <h1>📋 Pedidos bodega</h1>
        <?php if ($bodegaOn): ?>
            <p>Escribe la clave de bodega. Este equipo la recuerda; no hay que ponerla cada vez.</p>
            <?php if ($bodegaErr !== ''): ?><p class="err"><?= e($bodegaErr) ?></p><?php endif; ?>
            <input type="password" name="clave" autocomplete="current-password" required autofocus aria-label="Clave de bodega">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="next" value="<?= e($bodegaNext ?? '/pedidos-alistar') ?>">
            <button type="submit">Entrar</button>
        <?php else: ?>
            <p>Para pedir clave a bodega define la variable
                <b>PEDIDOS_CLAVE</b> en Railway.</p>
        <?php endif; ?>
        <p><a href="/admin/login">Entrar como administrador</a></p>
    </form>
</main>
</body>
</html>
