<?php
/**
 * Lista Bodega o Remisión (/lista-pedido): PDF del documento leído en el Extractor (/extractor, botón «🧾 Lista Bodega» o «🚚 Remisión»).
 * El pedido llega en sessionStorage kinoPedido ({order, items: [{code, qty}], warnings}); cliente, fecha, total de
 * ítems, unidades y valor total se muestran TAL CUAL vienen del PDF (no se recalculan), y las cantidades no se editan.
 * Las fotos se buscan una vez por código (POST /enviar-catalogo/extraer con {codes}); sin foto → «Foto no disponible».
 * El PDF se arma en el dispositivo con jsPDF (assets/js/order_list.js + pdf_shared.js).
 * Remisión: encabezado, logos y pie salen de la plantilla editable (Admin → 🚚 Remisión, RemisionTemplate).
 */
require_once BASE_DIR . '/src/services/RemisionTemplate.php';
$remTpl = RemisionTemplate::forPublic(RemisionTemplate::load(getDB()));
$olJs = APP_VERSION . filemtime(BASE_DIR . '/assets/js/order_list.js');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lista Bodega · Viewfinder</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .ol-card { background:var(--color-surface, var(--color-surface,#1c1c1e)); border:1px solid rgb(var(--rgb-fg,255 255 255) / 0.08); border-radius:12px; padding:1.1rem; margin-bottom:1rem; }
        .ol-card h2 { font-size:1.05rem; margin:0 0 0.6rem; }
        .ol-help { color:var(--color-text-muted,#a0a0b0); font-size:0.88rem; margin:0 0 0.8rem; }
        .ol-order { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:0.5rem 1rem; font-size:0.95rem; }
        .ol-order > * > span { color:var(--color-text-muted,#a0a0b0); display:block; font-size:0.78rem; }
        .ol-miss { color:#ff9f43; }
        .ol-edit { width:100%; box-sizing:border-box; padding:0.35rem 0.5rem; margin:0.15rem 0; background:var(--color-surface,#1c1c1e); color:var(--color-text,#f0f0f0); border:1px solid #c4a46b; border-radius:6px; font:inherit; }
        .ol-note { display:block; color:var(--color-text-muted,#a0a0b0); font-size:0.75rem; }
        .ol-field { cursor:pointer; }
        .ol-type { display:flex; flex-wrap:wrap; align-items:center; gap:0.4rem 1.2rem; margin:0 0 1rem; padding:0.7rem 1rem; border-radius:10px;
            border:1px solid #c4a46b55; background:#c4a46b14; font-size:0.92rem; }
        .ol-type label { cursor:pointer; }
        .ol-type input { accent-color:#c4a46b; vertical-align:middle; }
        .ol-type small { flex-basis:100%; color:var(--color-text-muted,#a0a0b0); }
        body.ol-remision .ol-type { border-color:#e0505a66; background:#e0505a14; }
        body.ol-remision .ol-type input, body.ol-remision .ol-field input, body.ol-remision .ol-opts input { accent-color:#e0505a; }
        body.ol-remision #olTitle { color:var(--color-danger); }
        .ol-tpl-link { display:none; }
        body.ol-remision .ol-tpl-link { display:block; margin-top:-0.6rem; }
        /* Botón «Previo»: llamativo, es el paso antes de descargar o enviar */
        .ol-preview-btn { background:linear-gradient(135deg,#ff8a00,#ffc93c); color:#1a1a1a; font-weight:800; font-size:1.05rem; border:none;
            box-shadow:0 0 0 0 rgba(255,170,40,.7); animation:olPulse 1.8s infinite; }
        .ol-preview-btn:disabled { animation:none; box-shadow:none; }
        body.ol-remision .ol-preview-btn { background:linear-gradient(135deg,#b3141e,#ff5a64); color:#fff; box-shadow:0 0 0 0 rgba(255,90,100,.7); }
        @keyframes olPulse { 0% { box-shadow:0 0 0 0 rgba(255,170,40,.6); } 70% { box-shadow:0 0 0 12px rgba(255,170,40,0); } 100% { box-shadow:0 0 0 0 rgba(255,170,40,0); } }
        @media (prefers-reduced-motion: reduce) { .ol-preview-btn { animation:none; } }
        .ol-pv { position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,.85); display:flex; flex-direction:column; }
        .ol-pv[hidden] { display:none; }
        .ol-pv-head { display:flex; flex-wrap:wrap; align-items:center; gap:0.5rem; padding:0.7rem 1rem; background:var(--color-surface,#1c1c1e); border-bottom:1px solid var(--color-surface-3,#2c2c2e); }
        .ol-pv-head strong { flex:1; min-width:10rem; font-size:1rem; }
        .ol-pv-head .btn { min-height:40px; }
        .ol-pv-pages { flex:1; overflow:auto; padding:1rem; display:flex; flex-direction:column; align-items:center; gap:1rem; }
        .ol-pv-pages canvas { width:100%; max-width:820px; height:auto; background:#fff; box-shadow:0 4px 18px rgba(0,0,0,.5); border-radius:2px; }
        .ol-pv-pages p { color:var(--color-text-muted,#c8c8d0); margin:0; }
        .ol-field.off { opacity:0.45; text-decoration:line-through; }
        .ol-field input, .ol-opts input { accent-color:#c4a46b; vertical-align:middle; }
        .ol-opts { display:flex; flex-wrap:wrap; gap:0.4rem 1.2rem; margin-top:0.8rem; font-size:0.85rem; color:var(--color-text-muted,#c8c8d0); }
        .ol-opts label { cursor:pointer; }
        .ol-cols { flex-basis:100%; display:flex; flex-wrap:wrap; gap:0.3rem 1rem; padding:0.6rem 0.7rem; border:1px solid var(--color-surface-3,#2c2c2e); border-radius:8px; }
        .ol-cols b { color:#e8e8ee; font-weight:600; }
        .ol-warn { background:rgba(255,159,67,0.08); border:1px solid rgba(255,159,67,0.5); border-radius:10px; padding:0.7rem 0.9rem; margin-bottom:1rem; font-size:0.9rem; }
        .ol-warn ul { margin:0.3rem 0 0; padding-left:1.1rem; }
        .ol-list { list-style:none; margin:0; padding:0; }
        .ol-list li { display:grid; grid-template-columns:2rem 64px 1fr auto; gap:0.75rem; align-items:center; padding:0.45rem 0; border-bottom:1px solid rgb(var(--rgb-fg,255 255 255) / 0.06); }
        .ol-list .n { color:var(--color-text-muted,#a0a0b0); font-size:0.8rem; text-align:right; }
        .ol-list .ph { width:64px; height:64px; border-radius:8px; background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .ol-list .ph img { max-width:100%; max-height:100%; object-fit:contain; }
        .ol-list .ph.none { background:var(--color-surface-2,#242426); color:var(--color-text-muted,#a0a0b0); font-size:0.62rem; text-align:center; line-height:1.15; padding:0.2rem; }
        .ol-list .cd { font-weight:600; word-break:break-all; }
        .ol-list .cd small { display:block; font-weight:400; color:var(--color-text-muted,#a0a0b0); font-size:0.75rem; }
        .ol-list .cd small.nm { font-size:0.78rem; word-break:normal; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .ol-list .q { font-size:1.05rem; font-weight:700; color:var(--color-primary-light, #c4a46b); white-space:nowrap; }
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
            <div style="display:flex; gap:0.4rem; align-items:center;">
                <a href="/extractor" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Extractor</a>
                <?= pedidosHeaderBtn() ?>
            </div>
        </div>
    </header>

    <main class="container ol-main" style="padding-top:2rem; padding-bottom:9rem; max-width:900px;">
        <?= breadcrumbs([['Extractor', '/extractor'], ['Lista Bodega', null]]) ?>
        <h1 id="olTitle" style="font-size:1.5rem; margin-bottom:1rem;">🧾 Lista Bodega</h1>
        <?php if (isAdminLoggedIn()): ?>
            <p class="ol-help ol-tpl-link"><a href="/admin/remision">✏️ Editar plantilla de la Cotización (logos, pie, colores)</a></p>
        <?php endif; ?>

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
        <label class="cp-name" id="olNameRow" hidden>📝 Nombre del archivo
            <span class="cp-name-in"><input type="text" id="olName" maxlength="120" autocomplete="off" spellcheck="false" aria-label="Nombre del archivo PDF"><span>.pdf</span></span>
        </label>
        <div class="cp-bar-row">
            <button type="button" class="btn ol-preview-btn" id="olMake" disabled>⏳ Buscando fotos…</button>
            <button type="button" class="btn cp-share" id="olShare" hidden>📲 Compartir</button>
            <button type="button" class="btn btn-secondary" id="olDown" hidden>⬇️ Descargar</button>
            <button type="button" class="btn btn-secondary" id="olToWh">📦 Enviar a pedidos bodega</button>
        </div>
        <div class="cp-msg" id="olMsg" aria-live="polite"></div>
    </div>

    <div class="ol-pv" id="olPv" hidden role="dialog" aria-modal="true" aria-label="Previo del PDF">
        <div class="ol-pv-head">
            <strong id="olPvTitle">👁 Previo</strong>
            <label class="cp-name ol-pv-name">📝 <span class="cp-name-in"><input type="text" id="olPvName" maxlength="120" autocomplete="off" spellcheck="false" aria-label="Nombre del archivo PDF"><span>.pdf</span></span></label>
            <button type="button" class="btn cp-share" id="olPvShare" hidden>📲 Enviar</button>
            <button type="button" class="btn btn-primary" id="olPvDown">⬇️ Descargar</button>
            <button type="button" class="btn btn-secondary" id="olPvClose">✕ Cerrar</button>
        </div>
        <div class="ol-pv-pages" id="olPvPages"></div>
    </div>

    <script>
        window.CS_CSRF = '<?= csrfToken() ?>';
        window.KINO_REMISION = <?= json_encode($remTpl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
    <script src="/assets/vendor/jspdf-4.2.1.umd.min.js" defer></script>
    <script src="/assets/js/pdf_shared.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="/assets/js/order_list.js?v=<?= $olJs ?>" defer></script>
    <script>
        // «📦 Enviar a pedidos bodega»: guarda el pedido (sessionStorage kinoPedido) en el servidor y abre la lista
        document.getElementById('olToWh').addEventListener('click', async (e) => {
            const b = e.currentTarget, m = document.getElementById('olMsg');
            let data = null;
            try { data = JSON.parse(sessionStorage.getItem('kinoPedido') || 'null'); } catch (x) { data = null; }
            if (!data || !Array.isArray(data.items) || !data.items.length) { m.textContent = '⚠️ No hay pedido para enviar. Ábrelo desde el Extractor.'; return; }
            b.disabled = true; m.textContent = 'Enviando a Pedidos bodega…';
            try {
                const r = await fetch('/pedidos-alistar/api', { method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CS_CSRF || '', Accept: 'application/json' },
                    body: JSON.stringify({ order: data }) });
                const j = await r.json().catch(() => ({}));
                if (!r.ok || !j.id) throw new Error(j.error || 'error ' + r.status);
                location.href = '/pedidos-alistar?nuevo=' + j.id + (j.existing ? '&ya=1' : '');
            } catch (x) { m.textContent = '⚠️ No se pudo enviar (' + (x && x.message ? x.message : x) + ').'; b.disabled = false; }
        });
    </script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
