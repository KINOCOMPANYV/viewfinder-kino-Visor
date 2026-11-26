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
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script>window.CS_CSRF = '<?= csrfToken() ?>';</script>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .ex-card { background:var(--color-surface, var(--color-surface,#1c1c1e)); border:1px solid rgb(var(--rgb-fg,255 255 255) / 0.08); border-radius:12px; padding:1.1rem; margin-bottom:1rem; }
        .ex-card h2 { font-size:1.05rem; margin:0 0 0.6rem; }
        .ex-help { color:var(--color-text-muted,#a0a0b0); font-size:0.88rem; margin:0 0 0.8rem; }
        .ex-drop { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.35rem; min-height:120px;
            border:2px dashed rgba(196, 164, 107,0.45); border-radius:12px; cursor:pointer; text-align:center; padding:1rem; transition:background .15s; }
        .ex-drop.on, .ex-drop:hover { background:rgba(196, 164, 107,0.08); }
        .ex-drop input { display:none; }
        .ex-drop b { font-size:0.95rem; }
        .ex-drop small { color:var(--color-text-muted,#a0a0b0); }
        .ex-drop.ok { border-color:#2ecc71; background:rgba(46,204,113,0.10); }
        .ex-drop.ok b { color:#2ecc71; }
        .ex-drop.ok small { color:#7fd8a3; }
        .ex-card [hidden], .ex-actions [hidden] { display:none !important; }
        .ex-or { text-align:center; color:var(--color-text-muted,#a0a0b0); font-size:0.85rem; margin:0.9rem 0; }
        .ex-paste { width:100%; box-sizing:border-box; min-height:130px; padding:0.6rem 0.7rem; background:var(--color-bg,#161618); color:var(--color-text,#f0f0f0); border:1px solid rgb(var(--rgb-fg,255 255 255) / 0.18); border-radius:10px; font:0.9rem/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; resize:vertical; }
        .ex-actions { display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.8rem; align-items:center; }
        .ex-review { border-radius:10px; padding:0.75rem 0.9rem; margin-bottom:1rem; font-size:0.9rem; }
        .ex-review.ok { background:rgba(37,211,102,0.1); border:1px solid rgba(37,211,102,0.45); }
        .ex-review.bad { background:rgba(255,80,80,0.08); border:1px solid rgba(255,80,80,0.5); }
        .ex-review ul { margin:0.35rem 0 0; padding-left:1.1rem; }
        .ex-review li { margin:0.15rem 0; }
        .ex-count { font-size:0.95rem; margin-bottom:0.6rem; }
        .ex-count b { font-size:1.3rem; color:var(--color-primary-light, #c4a46b); margin-left:0.3rem; }
        .ex-order { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:0.4rem 1rem; font-size:0.88rem; }
        .ex-order div span { color:var(--color-text-muted,#a0a0b0); display:block; font-size:0.78rem; }
        .ex-edit { width:100%; box-sizing:border-box; padding:0.3rem 0.5rem; margin:0.1rem 0; background:var(--color-surface,#1c1c1e); color:var(--color-text,#f0f0f0); border:1px solid #c4a46b; border-radius:6px; font:inherit; }
        .ex-note { display:block; color:var(--color-text-muted,#a0a0b0); font-size:0.75rem; }
        .ex-ok { color:#25d366; } .ex-bad { color:#ff6b6b; }
        .ex-map { margin-top:0.8rem; font-size:0.82rem; }
        .ex-map summary { cursor:pointer; color:var(--color-primary-light, #c4a46b); }
        .ex-map ol { list-style:none; padding:0; margin:0.5rem 0 0; max-height:420px; overflow:auto; }
        .ex-map li { display:grid; grid-template-columns:2.2rem 11rem 1fr; gap:0.4rem; padding:0.15rem 0; border-bottom:1px solid rgb(var(--rgb-fg,255 255 255) / 0.04); }
        .ex-map li.pb { border-top:1px dashed rgba(196, 164, 107,0.6); }
        .ex-map li .w { color:#ff9f43; }
        .ex-map li i { font-style:normal; color:var(--color-text-muted,#a0a0b0); }
        .ex-opts { display:flex; flex-wrap:wrap; gap:0.5rem 1.2rem; align-items:center; font-size:0.9rem; margin-bottom:0.7rem; }
        .ex-opts label { display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer; }
        .ex-opts input[type=number] { width:4.2rem; padding:0.25rem 0.4rem; background:var(--color-bg,#161618); color:inherit; border:1px solid rgb(var(--rgb-fg,255 255 255) / 0.15); border-radius:6px; }
        .ex-table-wrap { overflow:auto; max-height:70vh; border:1px solid rgb(var(--rgb-fg,255 255 255) / 0.08); border-radius:10px; }
        .ex-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
        .ex-table th, .ex-table td { padding:0.4rem 0.6rem; text-align:left; white-space:nowrap; border-bottom:1px solid rgb(var(--rgb-fg,255 255 255) / 0.05); }
        .ex-table th { position:sticky; top:0; background:#1b1b25; font-weight:600; }
        .ex-table td.num, .ex-table th.num { text-align:right; }
        .ex-table .sep { width:1.2rem; padding:0; }
        .ex-hide-price .c-price, .ex-hide-disc .c-disc { display:none; }
        .ex-table .c-x { display:none; width:2rem; padding:0 0.2rem; }
        .ex-editing .c-x { display:table-cell; }
        .ex-cell { width:100%; min-width:3.2rem; box-sizing:border-box; padding:0.25rem 0.4rem; background:var(--color-surface,#1c1c1e); color:var(--color-text,#f0f0f0); border:1px solid #c4a46b; border-radius:6px; font:inherit; }
        .ex-cell.num { text-align:right; max-width:7rem; }
        .ex-note-row td { border-top:0; padding-top:0; }
        .ex-cell.ex-linenote { min-height:2rem; resize:vertical; line-height:1.35; font-size:0.85rem; }
        .ex-note-txt { white-space:pre-wrap; color:var(--color-text-muted,#a0a0b0); font-size:0.85rem; }
        .ex-vp-bar { display:inline-flex; gap:0.4rem; flex-wrap:wrap; }
        .ex-cell[data-f="code"] { min-width:7.5rem; }
        .ex-cell[data-f="qty"] { max-width:4rem; }
        .ex-vp { margin-left:0.3rem; background:none; border:1px solid rgb(var(--rgb-fg,255 255 255) / 0.25); border-radius:6px; padding:0.15rem 0.4rem; cursor:pointer; opacity:0.6; }
        .ex-vp.on { opacity:1; border-color:#c4a46b; background:rgb(196 164 107 / 0.2); }
        .ex-del { background:none; border:1px solid #c0392b; color:#e74c3c; border-radius:6px; padding:0.15rem 0.45rem; cursor:pointer; }
        .ex-send { display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; }
        @media (max-width: 640px) {
            .ex-actions .btn, .ex-send .btn { flex:1 1 100%; }
            .ex-map li { grid-template-columns:1.8rem 1fr; }
            .ex-map li span:last-child { grid-column:1 / -1; }
            .ex-editing .c-u, .ex-editing .sep { display:none; }
        }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <div style="display:flex; gap:0.4rem; align-items:center;">
                <a href="/enviar-catalogo" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Enviar fotos con lista</a>
                <?= pedidosHeaderBtn() ?>
            </div>
        </div>
    </header>

    <main class="container" style="padding-top:2rem; padding-bottom:3rem; max-width:900px;">
        <?= breadcrumbs([['Enviar fotos con lista', '/enviar-catalogo'], ['Extractor', null]]) ?>
        <h1 style="font-size:1.5rem; margin-bottom:1.25rem;">🧾 Extractor de pedidos PDF</h1>

        <section class="ex-card">
            <p class="ex-help">Sube un recibo o pedido en PDF (o pega un listado más abajo): saca <strong>código, cantidad y precio</strong>, revisa que cuadre con
                el Total del PDF y lo deja listo para pegar en Excel o para ver las fotos en el visor. El PDF se lee
                <strong>en este dispositivo</strong>; no se sube a ningún lado.</p>
            <label class="ex-drop" id="exDrop">
                <input type="file" id="exFile" accept="application/pdf,.pdf">
                <span id="exIcon" style="font-size:1.6rem">📎</span>
                <b id="exFileName">Arrastra tu PDF aquí o toca para elegirlo</b>
                <small id="exHint">Recibos de KINO («5x 674B-1 … $430,000.00») o cotizaciones en tabla (CODIGO · CANT · PRECIO · TOTAL); se detecta solo</small>
            </label>
            <div class="ex-actions">
                <button type="button" id="exRun" class="btn btn-primary" disabled>🚀 Procesar PDF</button>
                <button type="button" id="exClear" class="btn btn-secondary btn-sm" hidden>🔄 Limpiar</button>
                <span id="exStatus" class="ex-help" style="margin:0" aria-live="polite"></span>
            </div>
            <div class="ex-or">— o —</div>
            <h2>📝 Pega un listado</h2>
            <p class="ex-help">Una línea por referencia: <strong>código, cantidad y precio</strong> (el precio es opcional; sin precio se usa el del visor).
                Sirve pegar desde Excel o escribir «5x CÓDIGO». Sin cantidad cuenta 1.</p>
            <textarea id="exPaste" class="ex-paste" spellcheck="false" placeholder="M8291-1  3  45000&#10;5x 674B-1&#10;S-640+S-647  10  22000"></textarea>
            <div class="ex-opts" style="margin:0.6rem 0 0">
                <label><input type="radio" name="exPasteType" value="pedido" checked> 🧾 Lista Bodega</label>
                <label><input type="radio" name="exPasteType" value="remision"> 🚚 Cotización / Remisión</label>
            </div>
            <div class="ex-actions">
                <button type="button" id="exPasteRun" class="btn btn-primary" disabled>🚀 Procesar listado</button>
                <span id="exPasteStatus" class="ex-help" style="margin:0" aria-live="polite"></span>
            </div>
        </section>

        <div id="exResult" hidden>
            <div class="ex-review" id="exReview" hidden></div>

            <section class="ex-card">
                <div class="ex-count">✅ Códigos encontrados <b id="exTotal">0</b></div>
                <h2>Datos del pedido <small class="ex-help">(solo para verificar; el cliente se puede corregir)</small></h2>
                <div class="ex-order" id="exOrder"></div>
                <details class="ex-map">
                    <summary>🗺 Mapa del PDF (qué se entendió de cada línea)</summary>
                    <ol id="exMap"></ol>
                </details>
            </section>

            <section class="ex-card">
                <h2>📄 Crear con esta lista</h2>
                <p class="ex-help">Elige si lleva precio y si lleva <strong>descuento</strong> (precio y total con descuento, con sus totales) y toca el documento:
                    ahí solo ajustas las columnas si quieres.</p>
                <div class="ex-opts">
                    <label><input type="checkbox" id="exShowPrice" checked> Mostrar precio</label>
                    <label><input type="checkbox" id="exShowDisc"> Con descuento</label>
                    <label>de <input type="number" id="exPct" min="0" max="100" step="1" value="40"> %</label>
                </div>
                <div class="ex-send">
                    <button type="button" id="exToOrder" data-doc="pedido" class="btn btn-secondary">🧾 Lista Bodega</button>
                    <button type="button" id="exToQuote" data-doc="remision" class="btn btn-secondary">🚚 Cotización</button>
                    <button type="button" id="exToVisor" class="btn btn-secondary">📷 Fotos / Catálogo PDF</button>
                </div>
                <p class="ex-help" style="margin:0.5rem 0 0">El resaltado es el que corresponde al PDF (recibo → Lista Bodega; factura en tabla →
                    Cotización); puedes abrir cualquiera. Con «Con descuento» el documento abre ya con esas columnas y el mismo %.</p>
            </section>

            <section class="ex-card">
                <h2>📊 Resultado</h2>
                <div class="ex-actions" style="margin:0 0 0.8rem">
                    <button type="button" id="exSort" class="btn btn-secondary btn-sm">🔤 Organizar listado</button>
                    <button type="button" id="exEdit" class="btn btn-secondary btn-sm">✏️ Editar lista</button>
                    <span id="exVpBar" class="ex-vp-bar" hidden>
                        <button type="button" id="exVisorAll" class="btn btn-secondary btn-sm" title="Pone en cada línea el precio público del visor">💲 Precio del visor en toda la lista</button>
                        <button type="button" id="exPdfAll" class="btn btn-secondary btn-sm">↩ Precios anteriores</button>
                    </span>
                    <button type="button" id="exReset" class="btn btn-secondary btn-sm" hidden>↩ Volver a lo del PDF</button>
                    <button type="button" id="exCopy" class="btn btn-primary btn-sm">📋 Copiar resultado</button>
                    <span id="exVpMsg" class="ex-help" style="margin:0" aria-live="polite"></span>
                    <span id="exCopyMsg" class="ex-help" style="margin:0" aria-live="polite"></span>
                </div>
                <div class="ex-table-wrap">
                    <table class="ex-table ex-hide-disc" id="exTable">
                        <thead><tr>
                            <th>codigo</th><th class="sep"></th><th class="num">cantidad</th><th class="c-u">UNIDAD</th><th class="sep"></th>
                            <th class="num c-price">PRECIO</th><th class="num c-disc" id="exDiscHead">PRECIO -40%</th><th class="c-x"></th>
                        </tr></thead>
                        <tbody id="exRows"></tbody>
                    </table>
                </div>
                <button type="button" id="exAddRow" class="btn btn-secondary btn-sm" style="margin-top:0.6rem" hidden>➕ Agregar línea</button>
                <p class="ex-review ok" id="exEditSum" style="margin-top:0.8rem" hidden></p>
            </section>
        </div>
    </main>

    <script type="module" src="/assets/js/extractor.js?v=<?= APP_VERSION . filemtime(BASE_DIR . '/assets/js/extractor.js') ?>"></script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
