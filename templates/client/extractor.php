<?php
/**
 * Extractor de pedidos PDF (/extractor): lee un recibo/pedido PDF EN EL DISPOSITIVO (pdf.js en assets/vendor/pdfjs) y
 * saca código, cantidad y precio, con Revisión contra el Total del PDF, datos del pedido y Mapa del PDF.
 * Port de la app Flask EXTRACTOR. Lógica en assets/js/extractor_core.js, pantalla en assets/js/extractor.js.
 * «📷 Ver fotos en el visor» pasa los códigos a Enviar catálogo (sessionStorage kinoCsText). La página no lee la BD
 * y el PDF nunca se sube al servidor.
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Extractor de pedidos PDF · Viewfinder</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .ex-card { background:var(--color-surface, #14141c); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:1.1rem; margin-bottom:1rem; }
        .ex-card h2 { font-size:1.05rem; margin:0 0 0.6rem; }
        .ex-help { color:#a0a0b0; font-size:0.88rem; margin:0 0 0.8rem; }
        .ex-drop { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.35rem; min-height:120px;
            border:2px dashed rgba(201,168,76,0.45); border-radius:12px; cursor:pointer; text-align:center; padding:1rem; transition:background .15s; }
        .ex-drop.on, .ex-drop:hover { background:rgba(201,168,76,0.08); }
        .ex-drop input { display:none; }
        .ex-drop b { font-size:0.95rem; }
        .ex-drop small { color:#a0a0b0; }
        .ex-actions { display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.8rem; align-items:center; }
        .ex-review { border-radius:10px; padding:0.75rem 0.9rem; margin-bottom:1rem; font-size:0.9rem; }
        .ex-review.ok { background:rgba(37,211,102,0.1); border:1px solid rgba(37,211,102,0.45); }
        .ex-review.bad { background:rgba(255,80,80,0.08); border:1px solid rgba(255,80,80,0.5); }
        .ex-review ul { margin:0.35rem 0 0; padding-left:1.1rem; }
        .ex-review li { margin:0.15rem 0; }
        .ex-count { font-size:0.95rem; margin-bottom:0.6rem; }
        .ex-count b { font-size:1.3rem; color:var(--color-primary-light, #c9a84c); margin-left:0.3rem; }
        .ex-order { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:0.4rem 1rem; font-size:0.88rem; }
        .ex-order div span { color:#a0a0b0; display:block; font-size:0.78rem; }
        .ex-ok { color:#25d366; } .ex-bad { color:#ff6b6b; }
        .ex-map { margin-top:0.8rem; font-size:0.82rem; }
        .ex-map summary { cursor:pointer; color:var(--color-primary-light, #c9a84c); }
        .ex-map ol { list-style:none; padding:0; margin:0.5rem 0 0; max-height:420px; overflow:auto; }
        .ex-map li { display:grid; grid-template-columns:2.2rem 11rem 1fr; gap:0.4rem; padding:0.15rem 0; border-bottom:1px solid rgba(255,255,255,0.04); }
        .ex-map li.pb { border-top:1px dashed rgba(201,168,76,0.6); }
        .ex-map li .w { color:#ff9f43; }
        .ex-map li i { font-style:normal; color:#a0a0b0; }
        .ex-opts { display:flex; flex-wrap:wrap; gap:0.5rem 1.2rem; align-items:center; font-size:0.9rem; margin-bottom:0.7rem; }
        .ex-opts label { display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer; }
        .ex-opts input[type=number] { width:4.2rem; padding:0.25rem 0.4rem; background:#0f0f16; color:inherit; border:1px solid rgba(255,255,255,0.15); border-radius:6px; }
        .ex-table-wrap { overflow:auto; max-height:70vh; border:1px solid rgba(255,255,255,0.08); border-radius:10px; }
        .ex-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
        .ex-table th, .ex-table td { padding:0.4rem 0.6rem; text-align:left; white-space:nowrap; border-bottom:1px solid rgba(255,255,255,0.05); }
        .ex-table th { position:sticky; top:0; background:#1b1b25; font-weight:600; }
        .ex-table td.num, .ex-table th.num { text-align:right; }
        .ex-table .sep { width:1.2rem; padding:0; }
        .ex-hide-price .c-price, .ex-hide-disc .c-disc { display:none; }
        .ex-send { display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; }
        @media (max-width: 640px) {
            .ex-actions .btn, .ex-send .btn { flex:1 1 100%; }
            .ex-map li { grid-template-columns:1.8rem 1fr; }
            .ex-map li span:last-child { grid-column:1 / -1; }
        }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/enviar-catalogo" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Enviar fotos con lista</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem; padding-bottom:3rem; max-width:900px;">
        <?= breadcrumbs([['Enviar fotos con lista', '/enviar-catalogo'], ['Extractor', null]]) ?>
        <h1 style="font-size:1.5rem; margin-bottom:1.25rem;">🧾 Extractor de pedidos PDF</h1>

        <section class="ex-card">
            <p class="ex-help">Sube un recibo o pedido en PDF: saca <strong>código, cantidad y precio</strong>, revisa que cuadre con
                el Total del PDF y lo deja listo para pegar en Excel o para ver las fotos en el visor. El PDF se lee
                <strong>en este dispositivo</strong>; no se sube a ningún lado.</p>
            <label class="ex-drop" id="exDrop">
                <input type="file" id="exFile" accept="application/pdf,.pdf">
                <span style="font-size:1.6rem">📎</span>
                <b id="exFileName">Arrastra tu PDF aquí o toca para elegirlo</b>
                <small>Recibos de KINO («5x 674B-1 … $430,000.00») o cotizaciones en tabla (CODIGO · CANT · PRECIO · TOTAL); se detecta solo</small>
            </label>
            <div class="ex-actions">
                <button type="button" id="exRun" class="btn btn-primary" disabled>🚀 Procesar PDF</button>
                <button type="button" id="exClear" class="btn btn-secondary btn-sm" hidden>🔄 Limpiar</button>
                <span id="exStatus" class="ex-help" style="margin:0" aria-live="polite"></span>
            </div>
        </section>

        <div id="exResult" hidden>
            <div class="ex-review" id="exReview" hidden></div>

            <section class="ex-card">
                <div class="ex-count">✅ Códigos encontrados <b id="exTotal">0</b></div>
                <h2>Datos del pedido <small class="ex-help">(solo para verificar; no se copian)</small></h2>
                <div class="ex-order" id="exOrder"></div>
                <details class="ex-map">
                    <summary>🗺 Mapa del PDF (qué se entendió de cada línea)</summary>
                    <ol id="exMap"></ol>
                </details>
            </section>

            <section class="ex-card">
                <h2>📷 Fotos de estos códigos</h2>
                <p class="ex-help">Abre <strong>Enviar fotos con lista</strong> con estos códigos: ahí ves cuáles tienen foto y cuáles no,
                    y puedes marcar, crear el PDF, guardarlas o mandarlas por WhatsApp.</p>
                <div class="ex-send">
                    <button type="button" id="exToVisor" class="btn btn-primary">📷 Ver fotos en el visor</button>
                    <button type="button" id="exToOrder" class="btn btn-secondary">🧾 Lista Bodega</button>
                </div>
                <p class="ex-help" style="margin:0.5rem 0 0">Arma un PDF con los datos del cliente, el total y cada referencia con su
                    foto y su cantidad, tal como vienen en el PDF: «🧾 Lista Bodega» si es un recibo (pedido) o «🚚 Remisión» si
                    es una factura en tabla.</p>
            </section>

            <section class="ex-card">
                <h2>📊 Resultado</h2>
                <div class="ex-opts">
                    <label><input type="checkbox" id="exShowPrice"> Mostrar precio</label>
                    <label><input type="checkbox" id="exShowDisc"> Mostrar precio con descuento</label>
                    <label>Descuento <input type="number" id="exPct" min="0" max="100" step="1" value="40"> %</label>
                </div>
                <div class="ex-actions" style="margin:0 0 0.8rem">
                    <button type="button" id="exSort" class="btn btn-secondary btn-sm">🔤 Organizar listado</button>
                    <button type="button" id="exCopy" class="btn btn-primary btn-sm">📋 Copiar resultado</button>
                    <span id="exCopyMsg" class="ex-help" style="margin:0" aria-live="polite"></span>
                </div>
                <div class="ex-table-wrap">
                    <table class="ex-table ex-hide-price ex-hide-disc" id="exTable">
                        <thead><tr>
                            <th>codigo</th><th class="sep"></th><th class="num">cantidad</th><th>UNIDAD</th><th class="sep"></th>
                            <th class="num c-price">PRECIO</th><th class="num c-disc" id="exDiscHead">PRECIO -40%</th>
                        </tr></thead>
                        <tbody id="exRows"></tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>

    <script type="module" src="/assets/js/extractor.js?v=<?= APP_VERSION ?>"></script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
