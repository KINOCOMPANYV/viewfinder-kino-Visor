<?php
/**
 * Lista Bodega o Remisión (/lista-pedido): PDF del documento leído en el Extractor (/extractor, botón «🧾 Lista Bodega» o «🚚 Remisión»).
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
    <title>Lista Bodega · Viewfinder</title>
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
        .ol-type { display:flex; flex-wrap:wrap; align-items:center; gap:0.4rem 1.2rem; margin:0 0 1rem; padding:0.7rem 1rem; border-radius:10px;
            border:1px solid #c9a84c55; background:#c9a84c14; font-size:0.92rem; }
        .ol-type label { cursor:pointer; }
        .ol-type input { accent-color:#c9a84c; vertical-align:middle; }
        .ol-type small { flex-basis:100%; color:#a0a0b0; }
        body.ol-remision .ol-type { border-color:#6ea8dc66; background:#6ea8dc14; }
        body.ol-remision .ol-type input, body.ol-remision .ol-field input, body.ol-remision .ol-opts input { accent-color:#6ea8dc; }
        body.ol-remision #olTitle { color:#8cc0ee; }
        /* Botón «Previo»: llamativo, es el paso antes de descargar o enviar */
        .ol-preview-btn { background:linear-gradient(135deg,#ff8a00,#ffc93c); color:#1a1a1a; font-weight:800; font-size:1.05rem; border:none;
            box-shadow:0 0 0 0 rgba(255,170,40,.7); animation:olPulse 1.8s infinite; }
        .ol-preview-btn:disabled { animation:none; box-shadow:none; }
        body.ol-remision .ol-preview-btn { background:linear-gradient(135deg,#1f6fd1,#6ec3ff); color:#fff; box-shadow:0 0 0 0 rgba(110,195,255,.7); }
        @keyframes olPulse { 0% { box-shadow:0 0 0 0 rgba(255,170,40,.6); } 70% { box-shadow:0 0 0 12px rgba(255,170,40,0); } 100% { box-shadow:0 0 0 0 rgba(255,170,40,0); } }
        @media (prefers-reduced-motion: reduce) { .ol-preview-btn { animation:none; } }
        .ol-pv { position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,.85); display:flex; flex-direction:column; }
        .ol-pv[hidden] { display:none; }
        .ol-pv-head { display:flex; flex-wrap:wrap; align-items:center; gap:0.5rem; padding:0.7rem 1rem; background:#15151c; border-bottom:1px solid #2a2a35; }
        .ol-pv-head strong { flex:1; min-width:10rem; font-size:1rem; }
        .ol-pv-head .btn { min-height:40px; }
        .ol-pv-pages { flex:1; overflow:auto; padding:1rem; display:flex; flex-direction:column; align-items:center; gap:1rem; }
        .ol-pv-pages canvas { width:100%; max-width:820px; height:auto; background:#fff; box-shadow:0 4px 18px rgba(0,0,0,.5); border-radius:2px; }
        .ol-pv-pages p { color:#c8c8d0; margin:0; }
        .ol-field.off { opacity:0.45; text-decoration:line-through; }
        .ol-field input, .ol-opts input { accent-color:#c9a84c; vertical-align:middle; }
        .ol-opts { display:flex; flex-wrap:wrap; gap:0.4rem 1.2rem; margin-top:0.8rem; font-size:0.85rem; color:#c8c8d0; }
        .ol-opts label { cursor:pointer; }
        .ol-cols { flex-basis:100%; display:flex; flex-wrap:wrap; gap:0.3rem 1rem; padding:0.6rem 0.7rem; border:1px solid #2a2a35; border-radius:8px; }
        .ol-cols b { color:#e8e8ee; font-weight:600; }
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
        <?= breadcrumbs([['Extractor', '/extractor'], ['Lista Bodega', null]]) ?>
        <h1 id="olTitle" style="font-size:1.5rem; margin-bottom:1rem;">🧾 Lista Bodega</h1>

        <div class="ol-card" id="olEmpty" hidden>
            <p>No hay un pedido para mostrar.</p>
            <p class="ol-help" style="margin:0">Abre el <a href="/extractor">Extractor</a>, procesa el PDF del pedido y toca «🧾 Lista Bodega» o «🚚 Remisión».</p>
        </div>

        <div id="olBody" hidden>
            <div class="ol-type" id="olType"></div>
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
            <button type="button" class="btn ol-preview-btn" id="olMake" disabled>⏳ Buscando fotos…</button>
            <button type="button" class="btn cp-share" id="olShare" hidden>📲 Compartir</button>
            <button type="button" class="btn btn-secondary" id="olDown" hidden>⬇️ Descargar</button>
        </div>
        <div class="cp-msg" id="olMsg" aria-live="polite"></div>
    </div>

    <div class="ol-pv" id="olPv" hidden role="dialog" aria-modal="true" aria-label="Previo del PDF">
        <div class="ol-pv-head">
            <strong id="olPvTitle">👁 Previo</strong>
            <button type="button" class="btn cp-share" id="olPvShare" hidden>📲 Enviar</button>
            <button type="button" class="btn btn-primary" id="olPvDown">⬇️ Descargar</button>
            <button type="button" class="btn btn-secondary" id="olPvClose">✕ Cerrar</button>
        </div>
        <div class="ol-pv-pages" id="olPvPages"></div>
    </div>

    <script>window.CS_CSRF = '<?= csrfToken() ?>';</script>
    <script src="/assets/vendor/jspdf-4.2.1.umd.min.js" defer></script>
    <script src="/assets/js/pdf_shared.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="/assets/js/order_list.js?v=<?= APP_VERSION ?>" defer></script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
