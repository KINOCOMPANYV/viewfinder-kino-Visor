<?php
/**
 * Hacer pedido (/hacer-pedido, v2 módulo 4): el cliente busca productos, los agrega al carrito (guardado en su dispositivo,
 * localStorage kinoCarrito), ajusta cantidades y envía el pedido (queda «recibido»). Sin cuenta: nombre + WhatsApp.
 */
?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Hacer pedido · Viewfinder</title>
<style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
<link rel="preconnect" href="https://lh3.googleusercontent.com">
<script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="/assets/css/make_order.css?v=<?= APP_VERSION ?>">
</head><body>
<header class="header"><div class="container header-inner">
    <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
    <div style="display:flex;gap:.4rem;align-items:center">
        <?= pedidosHeaderBtn() ?>
        <a href="/buscar" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:.8rem;padding:.4rem .8rem">← Seguir buscando</a>
    </div>
</div></header>
<main class="container mo-main">
    <?= breadcrumbs([['Hacer pedido', null]]) ?>
    <h1 class="mo-title">🛒 Hacer pedido</h1>

    <section class="mo-card" id="moDone" hidden>
        <h2>✅ Pedido enviado</h2>
        <p class="mo-help">Recibimos tu pedido <b id="moDoneId"></b>. Te contactaremos por WhatsApp para confirmarlo.</p>
        <a class="btn btn-primary" href="/buscar">Seguir viendo el catálogo</a>
    </section>

    <div id="moWork">
        <section class="mo-card">
            <h2>1. Agrega productos</h2>
            <input type="search" id="moQ" class="mo-input" placeholder="Código o nombre (ej. M8363-3)" autocomplete="off">
            <ul class="mo-results" id="moResults"></ul>
        </section>

        <section class="mo-card">
            <h2>2. Tu pedido <span id="moCount"></span></h2>
            <p class="mo-help" id="moEmpty">Aún no agregaste productos. También puedes tocar «🛒 Agregar al pedido» en la ficha de cada producto.</p>
            <ul class="mo-cart" id="moCart"></ul>
            <div class="mo-total" id="moTotal" hidden>Total: <b id="moTotalV"></b></div>
        </section>

        <section class="mo-card" id="moForm">
            <h2>3. Tus datos y envío</h2>
            <label class="mo-lbl">Nombre <input class="mo-input" id="moName" maxlength="120" autocomplete="name"></label>
            <label class="mo-lbl">WhatsApp / teléfono <input class="mo-input" id="moPhone" maxlength="40" inputmode="tel" autocomplete="tel"></label>
            <label class="mo-lbl">Nota (opcional) <textarea class="mo-input" id="moNote" maxlength="500" rows="2"></textarea></label>
            <input type="text" id="moWeb" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
            <p class="mo-err" id="moErr" role="alert" hidden></p>
            <button class="btn btn-primary" id="moSend" type="button" disabled>Enviar pedido</button>
        </section>
    </div>
</main>
<script>window.MO_CSRF = '<?= csrfToken() ?>';</script>
<script src="/assets/js/make_order.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
