<?php
/**
 * Pedido de bodega (/pedido-bodega): panel para alistar y chequear un pedido. Lee sessionStorage kinoPedido (el mismo
 * pedido que arma Lista Bodega / Remisión), deja cambiar producto y cantidad, recalcula el total y genera el PDF
 * «Pedido finalizado». Con ?id=N el pedido viene de Pedidos bodega y el avance se guarda en el servidor. PDF en el dispositivo (assets/js/warehouse_order.js + pdf_shared.js); plan: docs/PLAN_PEDIDO_BODEGA.md.
 */
$wbJs = APP_VERSION . filemtime(BASE_DIR . '/assets/js/warehouse_order.js');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Pedido de bodega · Viewfinder</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .pb-card { background:var(--color-surface, var(--color-surface,#1c1c1e)); border:1px solid rgb(var(--rgb-fg,255 255 255) / 0.08); border-radius:12px; padding:1.1rem; margin-bottom:1rem; }
        .pb-card h2 { font-size:1.05rem; margin:0 0 0.6rem; }
        .pb-help { color:var(--color-text-muted,#a0a0b0); font-size:0.85rem; }
        .pb-order { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:0.5rem 1rem; font-size:0.95rem; }
        .pb-order > * > span { color:var(--color-text-muted,#a0a0b0); display:block; font-size:0.78rem; }
        .pb-prog { display:flex; flex-wrap:wrap; gap:0.5rem 1rem; align-items:center; position:sticky; top:0; z-index:5; background:var(--color-bg,#161618);
            padding:0.6rem 0; border-bottom:1px solid var(--color-surface-3,#2c2c2e); margin-bottom:0.8rem; font-size:0.95rem; }
        .pb-prog label { cursor:pointer; font-size:0.85rem; color:var(--color-text-muted,#c8c8d0); }
        .pb-item { display:grid; grid-template-columns:72px 1fr; gap:0.4rem 0.8rem; padding:0.8rem; margin-bottom:0.7rem; border-radius:12px;
            border:1px solid rgb(var(--rgb-fg,255 255 255) / 0.1); background:var(--color-surface, var(--color-surface,#1c1c1e)); }
        .pb-item.done { border-color:#3fb950aa; background:#3fb95010; }
        .pb-item.gone { opacity:.6; }
        .pb-ph { width:72px; height:72px; border-radius:8px; background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .pb-ph img { max-width:100%; max-height:100%; object-fit:contain; }
        .pb-ph.none { background:var(--color-surface-2,#242426); color:var(--color-text-muted,#a0a0b0); font-size:0.62rem; text-align:center; padding:0.2rem; }
        .pb-code { font-weight:700; word-break:break-all; }
        .pb-name { color:var(--color-text-muted,#c8c8d0); font-size:0.85rem; }
        .pb-tag { display:inline-block; font-size:0.7rem; padding:0.05rem 0.45rem; border-radius:99px; margin-left:0.3rem; background:#c4a46b33; color:#e6c76a; }
        .pb-tag.red { background:#e0505a33; color:var(--color-danger); }
        .pb-tag.green { background:#3fb95033; color:var(--color-success); }
        .pb-ctl { grid-column:1 / -1; display:flex; flex-wrap:wrap; gap:0.5rem 0.8rem; align-items:center; }
        .pb-chk { display:flex; align-items:center; gap:0.4rem; font-weight:700; cursor:pointer; min-height:44px; }
        .pb-chk input { width:26px; height:26px; accent-color:#3fb950; }
        .pb-qty { display:inline-flex; align-items:center; gap:0.3rem; }
        .pb-qty button { width:40px; height:40px; font-size:1.3rem; border-radius:10px; border:1px solid #444; background:var(--color-surface-2,#242426); color:var(--color-text); cursor:pointer; }
        .pb-qty input { width:56px; height:40px; text-align:center; font-size:1.05rem; border-radius:8px; border:1px solid #444; background:var(--color-bg,#161618); color:var(--color-text); }
        .pb-price { color:#bbb; font-size:0.9rem; white-space:nowrap; }
        .pb-sub { margin-left:auto; font-weight:700; color:var(--color-primary-light, #c4a46b); }
        .pb-note, .pb-in { height:38px; border-radius:8px; border:1px solid #444; background:var(--color-bg,#161618); color:var(--color-text); padding:0 0.6rem; font-size:0.9rem; }
        .pb-note { flex:1; min-width:9rem; }
        .pb-sw { grid-column:1 / -1; display:flex; flex-wrap:wrap; gap:0.4rem; align-items:center; }
        .pb-sw .pb-in { flex:1; min-width:12rem; }
        .pb-pick { flex-basis:100%; display:grid; grid-template-columns:repeat(auto-fill, minmax(104px, 1fr)); gap:0.5rem; max-height:360px; overflow-y:auto; }
        .pb-pick:empty { display:none; }
        .pb-pick button { display:flex; flex-direction:column; gap:0.2rem; padding:0.3rem; border:1px solid #333; border-radius:10px; background:var(--color-bg,#161618); color:#fff; cursor:pointer; text-align:left; font-size:0.75rem; }
        .pb-pick button:hover, .pb-pick button:focus { border-color:var(--color-primary-light, #c4a46b); }
        .pb-pick img { width:100%; aspect-ratio:1; object-fit:cover; border-radius:8px; background:var(--color-surface-2,#242426); }
        .pb-pick b { font-size:0.8rem; word-break:break-all; }
        .pb-pick small { color:var(--color-text-muted,#a0a0b0); overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .pb-total { font-size:1.05rem; display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:0.4rem 1rem; }
        .pb-total span { display:block; color:var(--color-text-muted,#a0a0b0); font-size:0.78rem; }
        .pb-total .big { font-size:1.35rem; font-weight:800; color:var(--color-primary-light, #c4a46b); }
        .pb-total .up { color:#ff9f43; } .pb-total .down { color:var(--color-success); }
        .pb-fin { position:fixed; left:0; right:0; bottom:0; z-index:20; background:var(--color-surface,#1c1c1e); border-top:1px solid var(--color-surface-3,#2c2c2e); padding:0.6rem 1rem calc(0.6rem + env(safe-area-inset-bottom)); }
        .pb-fin-row { display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; max-width:900px; margin:0 auto; }
        .pb-fin input[type=text] { flex:1; min-width:10rem; height:44px; border-radius:10px; border:1px solid #555; background:var(--color-bg,#161618); color:var(--color-text); padding:0 0.7rem; font-size:1rem; }
        .pb-fin .btn { min-height:44px; font-weight:800; }
        .pb-fin .btn:disabled { opacity:.45; }
        .pb-msg { max-width:900px; margin:0.3rem auto 0; font-size:0.82rem; color:var(--color-text-muted,#c8c8d0); min-height:1rem; }
        .pb-pv { position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,.85); display:flex; flex-direction:column; }
        .pb-pv[hidden], #pbBody[hidden], #pbEmpty[hidden], .pb-fin[hidden] { display:none !important; }
        .pb-pv-head { display:flex; flex-wrap:wrap; align-items:center; gap:0.5rem; padding:0.7rem 1rem; background:var(--color-surface,#1c1c1e); border-bottom:1px solid var(--color-surface-3,#2c2c2e); }
        .pb-pv-head strong { flex:1; min-width:9rem; }
        .pb-pv-pages { flex:1; overflow:auto; padding:1rem; display:flex; flex-direction:column; align-items:center; gap:1rem; }
        .pb-pv-pages canvas { width:100%; max-width:820px; height:auto; background:#fff; border-radius:2px; }
        .pb-chg { border-color:#c4a46b66; }
        .pb-chg h3 { font-size:0.9rem; margin:0.9rem 0 0.35rem; }
        .pb-chg ul { list-style:none; margin:0; padding:0; }
        .pb-chg li { display:flex; flex-wrap:wrap; gap:0.2rem 0.6rem; align-items:baseline; padding:0.45rem 0.6rem; border-radius:8px; background:var(--color-bg,#161618); margin-bottom:0.3rem; font-size:0.9rem; }
        .pb-chg li .q { margin-left:auto; color:var(--color-text-muted,#c8c8d0); white-space:nowrap; }
        .pb-chg li .n { flex-basis:100%; color:var(--color-text-muted,#a0a0b0); font-size:0.8rem; }
        .pb-chg .arrow { color:#e6c76a; }
        .pb-chg .rm b { text-decoration:line-through; color:var(--color-danger); }
        .pb-chg .sum { margin-top:0.8rem; padding-top:0.6rem; border-top:1px solid var(--color-surface-3,#2c2c2e); display:flex; flex-wrap:wrap; gap:0.3rem 1.2rem; font-size:0.9rem; }
        .pb-chg .sum span { color:var(--color-text-muted,#a0a0b0); } .pb-chg .up { color:#ff9f43; } .pb-chg .down { color:var(--color-success); }
        .pb-state { border-radius:12px; padding:0.8rem 1rem; margin-bottom:1rem; border:1px solid; border-left-width:6px; }
        .pb-state.red { border-color:#e0505a88; border-left-color:#e0505a; background:#e0505a14; }
        .pb-state.green { border-color:#3fb95088; border-left-color:#3fb950; background:#3fb95012; }
        .pb-state.orange { border-color:#f0883e88; border-left-color:#f0883e; background:#f0883e14; }
        .pb-state[hidden] { display:none; }
        @media (max-width:640px) { body:has(.mnav) .pb-fin { bottom:calc(68px + env(safe-area-inset-bottom, 0px)); padding-bottom:0.6rem; } }
        main.pb-main { padding-bottom:11rem !important; }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <div style="display:flex; gap:0.4rem; align-items:center;">
                <a href="/lista-pedido" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Lista Bodega</a>
                <?= pedidosHeaderBtn() ?>
            </div>
        </div>
    </header>

    <main class="container pb-main" style="padding-top:2rem; max-width:900px;">
        <?= breadcrumbs([['Extractor', '/extractor'], ['Pedidos bodega', '/pedidos-alistar'], ['Pedido de bodega', null]]) ?>
        <h1 style="font-size:1.5rem; margin-bottom:1rem;">📦 Pedido de bodega</h1>

        <div class="pb-card" id="pbEmpty" hidden>
            <p>No hay un pedido para alistar.</p>
            <p class="pb-help">Abre los <a href="/pedidos-alistar">📋 Pedidos bodega</a>, o en el <a href="/extractor">Extractor</a> procesa el PDF del pedido, toca «🧾 Lista Bodega» y luego «📦 Enviar a pedidos bodega».</p>
        </div>

        <div id="pbBody" hidden>
            <div class="pb-state" id="pbState" hidden></div>
            <section class="pb-card">
                <h2>Datos del pedido</h2>
                <div class="pb-order" id="pbOrder"></div>
            </section>
            <div class="pb-prog" id="pbProg"></div>
            <div id="pbList"></div>
            <section class="pb-card">
                <h2>➕ Agregar un producto</h2>
                <div class="pb-sw">
                    <input class="pb-in" id="pbAddCode" placeholder="Código, nombre o carpeta" autocomplete="off" autocapitalize="characters" aria-label="Buscar producto por código, nombre o carpeta">
                    <button type="button" class="btn btn-secondary" id="pbAddBtn">Agregar</button>
                    <span class="pb-help" id="pbAddMsg"></span>
                    <div class="pb-pick" id="pbAddPick"></div>
                </div>
            </section>
            <section class="pb-card pb-chg" id="pbChgBox" hidden>
                <h2>📝 Cambios en el alistado</h2>
                <p class="pb-help" id="pbChgInfo" style="margin:0">Lo que quedó distinto al pedido original del cliente.</p>
                <div id="pbChg"></div>
                <div class="pb-sw" style="margin-top:0.8rem;">
                    <button type="button" class="btn btn-secondary" id="pbChgCopy">📋 Copiar resumen</button>
                    <button type="button" class="btn btn-secondary" id="pbChgBack">⬆ Volver al pedido</button>
                    <span class="pb-help" id="pbChgMsg" aria-live="polite"></span>
                </div>
            </section>
            <section class="pb-card" id="pbCopyBox" hidden>
                <h2>📋 Listado para copiar</h2>
                <p class="pb-help">Código, cantidad final y UNIDAD, separados por tabulación (como el que pega el Extractor). Los datos del pedido están arriba.</p>
                <textarea id="pbCopyText" readonly rows="10" style="width:100%; font-family:monospace; font-size:0.85rem; background:var(--color-bg,#161618); color:var(--color-text); border:1px solid #444; border-radius:8px; padding:0.6rem; white-space:pre;" aria-label="Listado de códigos y cantidades"></textarea>
                <div class="pb-sw" style="margin-top:0.6rem;">
                    <button type="button" class="btn btn-primary" id="pbCopyBtn">📋 Copiar</button>
                    <span class="pb-help" id="pbCopyInfo"></span> <span class="pb-help" id="pbCopyMsg" aria-live="polite"></span>
                </div>
            </section>
            <section class="pb-card">
                <h2>Total</h2>
                <div class="pb-total" id="pbTotal"></div>
            </section>
        </div>
    </main>

    <div class="pb-fin" id="pbFin" hidden>
        <div class="pb-fin-row">
            <input type="text" id="pbWho" maxlength="80" placeholder="Chequeado por (nombre)" autocomplete="name" aria-label="Nombre de quien chequea">
            <button type="button" class="btn btn-primary" id="pbDone" disabled>✅ Finalizar pedido</button>
        </div>
        <div class="pb-msg" id="pbMsg" aria-live="polite"></div>
    </div>

    <div class="pb-pv" id="pbPv" hidden role="dialog" aria-modal="true" aria-label="Previo del pedido finalizado">
        <div class="pb-pv-head">
            <strong>👁 Pedido finalizado</strong>
            <label class="cp-name">📝 <span class="cp-name-in"><input type="text" id="pbName" maxlength="120" autocomplete="off" spellcheck="false" aria-label="Nombre del archivo PDF"><span>.pdf</span></span></label>
            <button type="button" class="btn cp-share" id="pbShare" hidden>📲 Enviar</button>
            <button type="button" class="btn btn-primary" id="pbDown">⬇️ Descargar</button>
            <button type="button" class="btn btn-secondary" id="pbClose">✕ Cerrar</button>
        </div>
        <div class="pb-pv-pages" id="pbPages"></div>
    </div>

    <script>window.CS_CSRF = '<?= csrfToken() ?>';</script>
    <script src="/assets/vendor/jspdf-4.2.1.umd.min.js" defer></script>
    <script src="/assets/js/pdf_shared.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="/assets/js/warehouse_order.js?v=<?= $wbJs ?>" defer></script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
