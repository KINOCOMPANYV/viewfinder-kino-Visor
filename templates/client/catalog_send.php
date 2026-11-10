<?php
/**
 * Enviar catálogo por lista (público: /enviar-catalogo, botón en el inicio y en el catálogo).
 * Se pega un texto, se sacan los códigos (POST /enviar-catalogo/extraer), se muestra la lista con sus fotos
 * en una galería: tocar una foto la marca/desmarca. Las marcadas se guardan en el celular de un toque (para mandarlas
 * luego desde WhatsApp) o se comparten a WhatsApp por tandas de 10. El 📲 de cada foto la manda sola.
 * Las fotos salen de /enviar-catalogo/foto/{id}.
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enviar catálogo · Viewfinder</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .cs-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem; margin-bottom:1.25rem; }
        .cs-card h2 { font-size:1.1rem; margin-bottom:0.35rem; }
        .cs-card p.help { color:var(--color-text-muted); font-size:0.85rem; margin-bottom:0.9rem; }
        .cs-text { width:100%; min-height:160px; resize:vertical; background:var(--color-surface-2); color:inherit; border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:0.75rem; font:inherit; font-size:0.9rem; }
        .cs-options { display:flex; flex-wrap:wrap; gap:0.5rem 1.25rem; margin:0.8rem 0; font-size:0.88rem; color:var(--color-text-muted); }
        .cs-options label { display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer; }
        .cs-actions { display:flex; flex-wrap:wrap; gap:0.6rem; align-items:center; }
        .cs-summary { font-size:0.9rem; margin-bottom:0.75rem; }
        .cs-warn { font-size:0.82rem; color:#fbbf24; margin-top:0.5rem; word-break:break-word; }
        .cs-bulk { display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.6rem; }
        /* Galería: tocar una foto la marca o desmarca */
        .cs-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:0.5rem; }
        .cs-tile { position:relative; border-radius:10px; overflow:hidden; background:#111; border:3px solid transparent; cursor:pointer; user-select:none; -webkit-user-select:none; -webkit-touch-callout:none; }
        .cs-tile img { display:block; width:100%; aspect-ratio:1; object-fit:contain; background:#fff; pointer-events:none; }
        .cs-tile.on { border-color:#25D366; }
        .cs-tile:not(.on) img { opacity:0.4; }
        .cs-tile .chk { position:absolute; top:6px; left:6px; width:26px; height:26px; border-radius:50%; border:2px solid #fff; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; color:#062b16; }
        .cs-tile.on .chk { background:#25D366; border-color:#25D366; }
        .cs-tile.on .chk::after { content:'✓'; }
        .cs-tile .one { position:absolute; top:4px; right:4px; width:36px; height:36px; border-radius:50%; border:none; background:rgba(0,0,0,0.6); font-size:17px; cursor:pointer; }
        .cs-tile .lbl { position:absolute; left:0; right:0; bottom:0; padding:0.25rem 0.4rem; background:linear-gradient(transparent, rgba(0,0,0,0.85)); font-size:0.72rem; font-family:monospace; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .cs-tile.sent .lbl::before { content:'✅ '; }
        .cs-nophoto { font-size:0.8rem; color:#fbbf24; margin-top:0.6rem; }
        .cs-bar { position:sticky; bottom:0; z-index:5; background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:0.8rem; margin-top:1rem; box-shadow:0 -6px 20px rgba(0,0,0,0.35); }
        .cs-bar .cs-actions .btn { flex:1 1 220px; }
        .cs-big { font-size:1rem; padding:0.75rem 1.1rem; }
        .btn-wa { background:#25D366; color:#062b16; border:none; font-weight:700; }
        .btn-wa:disabled { opacity:0.6; }
        #csBatch:disabled { background:var(--color-surface-2); color:var(--color-text-muted); border:1px solid var(--color-border); opacity:1; }
        #csBatch.pop { animation:csPop 0.6s ease; }
        @keyframes csPop { 0% { transform:scale(1); } 40% { transform:scale(1.05); box-shadow:0 0 0 6px rgba(37,211,102,0.35); } 100% { transform:scale(1); } }
        .cs-more { margin-top:1rem; font-size:0.85rem; }
        .cs-more summary { cursor:pointer; color:var(--color-text-muted); }
        .cs-row { display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; margin-top:0.6rem; font-size:0.85rem; }
        .cs-row input[type=tel] { background:var(--color-surface-2); color:inherit; border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:0.5rem 0.6rem; width:170px; }
        .cs-msg { font-size:0.82rem; color:var(--color-text-muted); margin-top:0.5rem; min-height:1.2em; }
        @media (max-width:640px) {
            .cs-grid { grid-template-columns:repeat(3, 1fr); gap:0.35rem; }
            .cs-options { flex-direction:column; gap:0.15rem; }
            .cs-options label { min-height:40px; font-size:0.92rem; }
            .cs-options input { width:20px; height:20px; }
            .cs-card { padding:0.85rem 0.6rem; }
            .cs-bulk .btn { flex:1; }
            .cs-bar .cs-actions .btn { flex:1 1 100%; }
            .cs-row { flex-direction:column; align-items:stretch; }
            .cs-row input[type=tel] { width:100%; }
        }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Catálogo</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem; padding-bottom:3rem; max-width:860px;">
        <h1 style="font-size:1.5rem; margin-bottom:1.25rem;">📤 Enviar catálogo por lista</h1>

        <section class="cs-card">
            <h2>1. Pega el texto</h2>
            <p class="help">Pega cualquier texto: un chat, un pedido o una lista. El sistema saca los códigos que existen
                en el catálogo (acepta M8363-3, M:8363-3, M-8363-3…) y arma la lista con sus fotos.</p>
            <textarea id="csText" class="cs-text" placeholder="Ej.: Hola, me mandas fotos de M8363-3, X6071 y 1416-1 por favor"></textarea>
            <div class="cs-options">
                <label><input type="radio" name="csMode" value="cover" checked> Solo la foto principal</label>
                <label><input type="radio" name="csMode" value="all"> Todas las fotos de cada código</label>
                <label><input type="checkbox" id="csPrice"> Poner el precio en el pie de foto (📲 de una foto)</label>
            </div>
            <div class="cs-actions">
                <button type="button" id="csGenerate" class="btn btn-primary">⚡ Generar lista</button>
                <button type="button" id="csClear" class="btn btn-secondary btn-sm">Limpiar</button>
            </div>
        </section>

        <section class="cs-card" id="csResult" hidden>
            <h2>2. Elige las fotos</h2>
            <div class="cs-summary" id="csSummary"></div>
            <p class="help">Toca una foto para marcarla o desmarcarla (verde = va). Luego, abajo:
                <strong>💾 Guardar en el celular</strong> y las mandas desde WhatsApp (📎 → Galería, hasta 100 juntas), o
                <strong>📲 Enviar por tandas</strong> directo a WhatsApp, 10 por toque (solo las fotos, sin texto). El 📲 de cada foto la manda sola con su código.</p>
            <div class="cs-bulk">
                <button type="button" id="csAll" class="btn btn-secondary btn-sm">Marcar todas</button>
                <button type="button" id="csNone" class="btn btn-secondary btn-sm">Desmarcar todas</button>
                <button type="button" id="csToSel" class="btn btn-secondary btn-sm" title="Suma las marcadas a lo que marcaste en el catálogo">📌 Pasar a Mi selección</button>
            </div>
            <div class="cs-grid" id="csGrid"></div>
            <div class="cs-nophoto" id="csNoPhoto" hidden></div>
            <div class="cs-warn" id="csNotFound" hidden></div>

            <div class="cs-bar" id="csBar">
                <div class="cs-actions">
                    <button type="button" id="csSave" class="btn btn-secondary cs-big">💾 Guardar en el celular</button>
                    <button type="button" id="csBatch" class="btn btn-wa cs-big">📲 Enviar por tandas</button>
                </div>
                <div class="cs-msg" id="csProgress"></div>
                <div class="cs-msg" id="csMsg"></div>
            </div>

            <details class="cs-more">
                <summary>Más opciones (lista en texto, abrir chat)</summary>
                <div class="cs-row">
                    <span>Abrir un chat:</span>
                    <input type="tel" id="csPhone" placeholder="Número con indicativo, ej. 573001234567" inputmode="tel">
                    <button type="button" id="csOpenChat" class="btn btn-secondary btn-sm">💬 Abrir chat con la lista</button>
                    <button type="button" id="csCopyList" class="btn btn-secondary btn-sm">Copiar lista en texto</button>
                </div>
            </details>
        </section>
    </main>

    <script>window.CS_CSRF = '<?= csrfToken() ?>';</script>
    <script src="/assets/js/catalog_send.js?v=<?= APP_VERSION ?>"></script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
