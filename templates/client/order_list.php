<?php
/**
 * Lista pedido (/lista-pedido): PDF de un pedido leído en el Extractor (/extractor, botón «🧾 Lista pedido»).
 * El pedido llega en sessionStorage kinoPedido ({order, items: [{code, qty}], warnings}); cliente, fecha, total de
 * ítems, unidades y valor total se muestran TAL CUAL vienen del PDF (no se recalculan), y las cantidades no se editan.
 * Las fotos se buscan una vez por código (POST /enviar-catalogo/extraer con {codes}); sin foto → «Foto no disponible».
 * El PDF se arma en el dispositivo con jsPDF (assets/js/order_list.js + pdf_shared.js).
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lista pedido · Viewfinder</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .ol-card { background:var(--color-surface, #14141c); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:1.1rem; margin-bottom:1rem; }
        .ol-card h2 { font-size:1.05rem; margin:0 0 0.6rem; }
        .ol-help { color:#a0a0b0; font-size:0.88rem; margin:0 0 0.8rem; }
        .ol-order { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:0.5rem 1rem; font-size:0.95rem; }
        .ol-order > * > span { color:#a0a0b0; display:block; font-size:0.78rem; }
        .ol-miss { color:#ff9f43; }
        .ol-field { cursor:pointer; }
        .ol-field.off { opacity:0.45; text-decoration:line-through; }
        .ol-field input, .ol-opts input { accent-color:#c9a84c; vertical-align:middle; }
        .ol-opts { display:flex; flex-wrap:wrap; gap:0.4rem 1.2rem; margin-top:0.8rem; font-size:0.85rem; color:#c8c8d0; }
        .ol-opts label { cursor:pointer; }
        .ol-warn { background:rgba(255,159,67,0.08); border:1px solid rgba(255,159,67,0.5); border-radius:10px; padding:0.7rem 0.9rem; margin-bottom:1rem; font-size:0.9rem; }
        .ol-warn ul { margin:0.3rem 0 0; padding-left:1.1rem; }
        .ol-list { list-style:none; margin:0; padding:0; }
        .ol-list li { display:grid; grid-template-columns:2rem 64px 1fr auto; gap:0.75rem; align-items:center; padding:0.45rem 0; border-bottom:1px solid rgba(255,255,255,0.06); }
        .ol-list .n { color:#a0a0b0; font-size:0.8rem; text-align:right; }
        .ol-list .ph { width:64px; height:64px; border-radius:8px; background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .ol-list .ph img { max-width:100%; max-height:100%; object-fit:contain; }
        .ol-list .ph.none { background:#22222c; color:#a0a0b0; font-size:0.62rem; text-align:center; line-height:1.15; padding:0.2rem; }
        .ol-list .cd { font-weight:600; word-break:break-all; }
        .ol-list .cd small { display:block; font-weight:400; color:#a0a0b0; font-size:0.75rem; }
        .ol-list .q { font-size:1.05rem; font-weight:700; color:var(--color-primary-light, #c9a84c); white-space:nowrap; }
        .ol-bar [hidden], #olBody[hidden], #olEmpty[hidden] { display:none !important; }
        @media (max-width: 640px) {
            .ol-list li { grid-template-columns:1.4rem 56px 1fr auto; gap:0.5rem; }
            .ol-list .ph { width:56px; height:56px; }
            main.ol-main { padding-bottom:12rem !important; }
        }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/extractor" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Extractor</a>
        </div>
    </header>

    <main class="container ol-main" style="padding-top:2rem; padding-bottom:9rem; max-width:900px;">
        <?= breadcrumbs([['Extractor', '/extractor'], ['Lista pedido', null]]) ?>
        <h1 style="font-size:1.5rem; margin-bottom:1rem;">🧾 Lista pedido</h1>

        <div class="ol-card" id="olEmpty" hidden>
            <p>No hay un pedido para mostrar.</p>
            <p class="ol-help" style="margin:0">Abre el <a href="/extractor">Extractor</a>, procesa el PDF del pedido y toca «🧾 Lista pedido».</p>
        </div>

        <div id="olBody" hidden>
            <div class="ol-warn" id="olWarn" hidden></div>
            <section class="ol-card">
                <h2>Datos del pedido <small class="ol-help">(tal como vienen en el PDF · marca lo que sale en el PDF)</small></h2>
                <div class="ol-order" id="olOrder"></div>
                <div class="ol-opts" id="olOpts"></div>
            </section>
            <section class="ol-card">
                <h2>Productos <small class="ol-help">(las cantidades son las del PDF y no se pueden cambiar)</small></h2>
                <ol class="ol-list" id="olList"></ol>
            </section>
        </div>
    </main>

    <div class="cp-bar ol-bar" id="olBar" hidden>
        <div class="cp-bar-row">
            <button type="button" class="btn btn-primary" id="olMake" disabled>⏳ Buscando fotos…</button>
            <button type="button" class="btn cp-share" id="olShare" hidden>📲 Compartir</button>
            <button type="button" class="btn btn-secondary" id="olDown" hidden>⬇️ Descargar</button>
        </div>
        <div class="cp-msg" id="olMsg" aria-live="polite"></div>
    </div>

    <script>window.CS_CSRF = '<?= csrfToken() ?>';</script>
    <script src="/assets/vendor/jspdf-4.2.1.umd.min.js" defer></script>
    <script src="/assets/js/pdf_shared.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="/assets/js/order_list.js?v=<?= APP_VERSION ?>" defer></script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
