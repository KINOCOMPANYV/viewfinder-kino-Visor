<?php
/** Admin → 🚚 Remisión: editor de la plantilla del PDF. Variables: $tpl (RemisionTemplate::load). JS: assets/js/remision_editor.js */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plantilla Cotización · Viewfinder Admin</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .re-card { background:var(--color-surface,#1c1c1e); border:1px solid rgb(var(--rgb-fg,255 255 255) / .08); border-radius:12px; padding:1rem 1.1rem; margin-bottom:1rem; }
        .re-card h2 { font-size:1.05rem; margin:0 0 .7rem; }
        .re-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:.7rem 1rem; }
        .re-grid label, .re-foot label { display:block; font-size:.82rem; color:var(--color-text-muted,#a0a0b0); }
        .re-grid input[type=text], .re-foot input { width:100%; margin-top:.25rem; padding:.5rem .6rem; border-radius:8px; border:1px solid #333; background:var(--color-bg,#161618); color:var(--color-text); font-size:.95rem; }
        .re-grid input[type=color] { display:block; margin-top:.25rem; width:64px; height:36px; border:0; background:none; padding:0; }
        .re-foot label { margin-bottom:.6rem; }
        .re-logos { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:.8rem; }
        .re-logo { border:1px solid #2a2a36; border-radius:10px; padding:.6rem; display:flex; flex-direction:column; gap:.45rem; }
        .re-logo .pic { height:64px; border-radius:6px; display:flex; align-items:center; justify-content:center; padding:6px; }
        .re-logo .pic img { max-height:100%; max-width:100%; }
        .re-logo input[type=text] { padding:.35rem .5rem; border-radius:6px; border:1px solid #333; background:var(--color-bg,#161618); color:var(--color-text); }
        .re-logo .row { display:flex; flex-wrap:wrap; gap:.35rem; align-items:center; font-size:.85rem; }
        .re-logo .row .btn { padding:.3rem .55rem; font-size:.8rem; }
        .re-pv { background:#fff; color:#222; border-radius:6px; overflow:hidden; max-width:820px; font-family:Helvetica,Arial,sans-serif; }
        .re-pv-head { display:flex; align-items:center; gap:14px; padding:10px 16px; min-height:64px; }
        .re-pv-head img { max-height:44px; max-width:150px; }
        .re-pv-head .sep { width:1px; align-self:stretch; background:rgba(255,255,255,.45); margin:6px 0; }
        .re-pv-head .tt b { display:block; color:#fff; font-size:22px; line-height:1.1; }
        .re-pv-head .tt span { color:#fff; font-size:14px; }
        .re-pv-head .rt { margin-left:auto; display:flex; align-items:center; gap:14px; }
        .re-pv-body { padding:16px; color:#6b6b70; font-size:13px; text-align:center; }
        .re-pv-foot { margin:0 16px; padding:8px 0 12px; font-size:12px; line-height:1.45; position:relative; }
        .re-pv-foot .b { font-weight:bold; }
        .re-pv-foot .m { color:#6b6b70; }
        .re-pv-foot .pg { position:absolute; right:0; bottom:12px; }
        .re-bar { position:sticky; bottom:0; background:#161618ee; padding:.8rem 0; display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; }
        #reMsg { font-size:.9rem; }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">VF</span> Viewfinder Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container pb-main">
        <?php $adminNav = 'remision'; include __DIR__ . '/partials/nav.php'; ?>
        <h1 class="pb-title">🚚 Plantilla de la Cotización</h1>
        <p class="pb-help">Lo que pongas aquí sale en todas las Cotizaciones (Extractor → 🚚 Cotización). Cliente, NIT, productos y valores
            siguen saliendo del PDF de la cotización. Revisa el previo y toca <b>Guardar</b>.</p>

        <section class="re-card">
            <h2>Previo (encabezado y pie)</h2>
            <div class="re-pv" id="rePv"></div>
        </section>

        <section class="re-card">
            <h2>Encabezado</h2>
            <div class="re-grid">
                <label>Título <input type="text" id="reTitle" maxlength="40"></label>
                <label>Antes del número <input type="text" id="reNum" maxlength="20" placeholder="Nº"></label>
                <label>Color de la franja <input type="color" id="reHead"></label>
                <label>Color de las líneas <input type="color" id="reAccent"></label>
            </div>
        </section>

        <section class="re-card">
            <h2>Logos <small class="pb-help">(izquierda = antes del título; derecha = después, en este orden)</small></h2>
            <div class="re-logos" id="reLogos"></div>
            <div class="row" style="margin-top:.8rem">
                <label class="btn btn-sm btn-secondary" style="cursor:pointer">➕ Agregar logo <input type="file" id="reAdd" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden></label>
                <small class="pb-help">PNG con fondo transparente se ve mejor. Si el logo es negro, usa «⚪ Pasar a blanco» para que se vea sobre la franja oscura.</small>
            </div>
        </section>

        <section class="re-card re-foot">
            <h2>Pie de página</h2>
            <label>Línea 1 (en negrita) <input type="text" id="reF0" maxlength="160"></label>
            <label>Línea 2 <input type="text" id="reF1" maxlength="160"></label>
            <label>Línea 3 <input type="text" id="reF2" maxlength="160"></label>
            <label>Marcas (sepáralas con « · ») <input type="text" id="reBrands" maxlength="300"></label>
        </section>

        <div class="re-bar">
            <button type="button" class="btn btn-primary" id="reSave">💾 Guardar</button>
            <button type="button" class="btn btn-secondary" id="reReset">↩️ Volver a la de fábrica</button>
            <span id="reMsg" class="pb-help"></span>
        </div>
    </main>

    <script>
        window.CS_CSRF = '<?= csrfToken() ?>';
        window.RE_TPL = <?= json_encode($tpl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
    <script src="/assets/js/remision_editor.js?v=<?= APP_VERSION . filemtime(BASE_DIR . '/assets/js/remision_editor.js') ?>"></script>
</body>

</html>
