<?php
/**
 * Catálogo en PDF (/catalogo-pdf, plan docs/PLAN_PDF.md etapa P1): las fotos marcadas (Mi selección, localStorage
 * kinoWaSel) con su código y un texto opcional por código (precio u otra información) que escribe cada usuario.
 * El PDF se arma en el dispositivo (assets/js/catalog_pdf.js + jsPDF en assets/vendor): textos, logo y precios
 * nunca se envían al servidor. La página no lee la BD; las fotos salen de /enviar-catalogo/foto/{id}.
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Catálogo en PDF · Viewfinder</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body class="cp-page">
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/mi-seleccion" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Mi selección</a>
        </div>
    </header>

    <main class="container cp-main">
        <?= breadcrumbs([['Mi selección', '/mi-seleccion'], ['Catálogo en PDF', null]], 'cpCrumbs') ?>
        <h1 class="ms-title">📄 Catálogo en PDF <span id="cpCount"></span></h1>
        <p class="ms-help">Elige colores y textos, y debajo de cada código escribe el precio u otra información:
            si lo dejas vacío, no sale nada. Todo se arma <strong>en este dispositivo</strong> y lo que escribes se
            recuerda aquí para la próxima vez.</p>

        <div class="ms-empty" id="cpEmpty" hidden>
            <p>No hay fotos marcadas.</p>
            <p>Marca fotos en <a href="/buscar">Buscar</a>, en una carpeta o en <a href="/enviar-catalogo">Enviar catálogo</a>
                y vuelve con «📄 PDF».</p>
        </div>

        <div id="cpBody" hidden>
            <section class="ms-look cp-design">
                <h2>🎨 Diseño</h2>
                <div class="cp-presets" id="cpPresets" aria-label="Combinaciones de colores"></div>
                <div class="cp-colors">
                    <label><input type="color" id="cpC1" value="#1c1c1e"> Color primario</label>
                    <label><input type="color" id="cpC2" value="#c9a84c"> Color secundario</label>
                </div>
                <div class="cp-prev" id="cpPrev" aria-hidden="true">
                    <div class="cp-prev-band"><span class="cp-prev-logo" id="cpPrevLogo"></span><span class="cp-prev-tt"><b id="cpPrevTitle">Catálogo</b><i id="cpPrevSub"></i></span></div>
                    <div class="cp-prev-line"></div>
                    <div class="cp-prev-sec"><b>Sección</b><span></span></div>
                    <div class="cp-prev-grid"><span><em></em><b>M:8363-3</b><i></i><small>$120.000</small><u>Abrir foto</u></span><span><em></em><b>M:8291-1</b><i></i><small>$95.000</small><u>Abrir foto</u></span><span><em></em><b>M:1401-5</b><i></i><small></small><u>Abrir foto</u></span></div>
                    <div class="cp-prev-foot"><span id="cpPrevFoot">Pie de catálogo</span><b>1 / 3</b></div>
                </div>
            </section>

            <section class="ms-look cp-doc">
                <h2>🗂️ Textos del catálogo <small>(opcionales)</small></h2>
                <div class="cp-fields">
                    <label>Título<input type="text" id="cpTitle" maxlength="60" placeholder="Ej.: Relojes noviembre"></label>
                    <label>Subtítulo<input type="text" id="cpSub" maxlength="80" placeholder="Ej.: Colección 2026 · Precios al por mayor"></label>
                    <label class="cp-wide">Pie de catálogo (contacto)<textarea id="cpFoot" rows="2" maxlength="160" placeholder="Ej.: Ana Peña · WhatsApp 300 123 4567 · Instagram @tienda"></textarea></label>
                </div>
                <div class="cp-logo">
                    <div class="cp-logo-box" id="cpLogoBox"><img id="cpLogoImg" alt="Tu logo" hidden><span id="cpLogoNone">Sin logo</span></div>
                    <div class="cp-logo-act">
                        <label class="btn btn-sm btn-secondary cp-file">🖼️ Subir mi logo<input type="file" id="cpLogoFile" accept="image/*"></label>
                        <button type="button" class="btn btn-sm btn-secondary" id="cpLogoDel" hidden>Quitar logo</button>
                        <small>Opcional. Sale arriba en cada página. Se guarda solo en este dispositivo.</small>
                    </div>
                </div>
                <div class="ms-look-opts cp-per" role="radiogroup" aria-label="Fotos por página">
                    <span>Fotos por página:</span>
                    <label><input type="radio" name="cpPer" value="4"> 4</label>
                    <label><input type="radio" name="cpPer" value="6"> 6</label>
                    <label><input type="radio" name="cpPer" value="9"> 9</label>
                </div>
                <label class="cp-check"><input type="checkbox" id="cpOpen"> Botón «Abrir foto» debajo de cada foto
                    <small>(al tocarlo abre la foto en Google Drive; el enlace no se ve en el PDF)</small></label>
                <small class="cp-tip">Para separar por secciones (p. ej. «Dama», «Caballero»), toca «＋ Subtítulo» en la foto donde empieza cada una.</small>
            </section>

            <section class="ms-look cp-bulk">
                <h2>✏️ Texto rápido</h2>
                <div class="cp-bulk-row">
                    <input type="text" id="cpBulkText" maxlength="120" placeholder="Ej.: Precio: $120.000">
                    <button type="button" class="btn btn-sm btn-secondary" id="cpBulkAll">Poner en todas</button>
                    <button type="button" class="btn btn-sm btn-secondary" id="cpBulkEmpty">Solo en las vacías</button>
                    <button type="button" class="btn btn-sm btn-secondary" id="cpBulkClear">Borrar textos</button>
                </div>
            </section>

            <p class="cp-warn" id="cpWarn" hidden></p>
            <ol class="ms-list cp-list" id="cpList"></ol>
        </div>
    </main>

    <div class="cp-bar" id="cpBar" hidden>
        <div class="cp-bar-row">
            <button type="button" class="btn btn-primary" id="cpMake">📄 Generar PDF</button>
            <button type="button" class="btn cp-share" id="cpShare" hidden>📲 Compartir</button>
            <button type="button" class="btn btn-secondary" id="cpDown" hidden>⬇️ Descargar</button>
        </div>
        <div class="cp-msg" id="cpMsg" aria-live="polite"></div>
    </div>

    <script src="/assets/vendor/jspdf-4.2.1.umd.min.js" defer></script>
    <script src="/assets/js/catalog_pdf.js?v=<?= APP_VERSION ?>" defer></script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
