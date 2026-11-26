<?php
/**
 * Catálogo en PDF (/catalogo-pdf, plan docs/PLAN_PDF.md etapa P1): las fotos marcadas (Mi selección, localStorage
 * kinoWaSel) con su código y un texto opcional por código (precio u otra información) que escribe cada usuario.
 * El PDF se arma en el dispositivo (assets/js/catalog_pdf.js + jsPDF en assets/vendor): textos, logo y precios
 * nunca se envían al servidor. Las fotos salen de /enviar-catalogo/foto/{id}.
 * «Armar con filtros» (?desde=filtro + los mismos filtros que /buscar): la lista sale de /catalogo-pdf/lista.
 */
$db = null;
try { $db = getDB(); } catch (Throwable $e) { $db = null; }
$fromFilter = ($_GET['desde'] ?? '') === 'filtro';
$q = $fromFilter && is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$filters = $fromFilter ? catalogFiltersFromRequest($_GET) : catalogFiltersFromRequest([]);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Catálogo en PDF · Viewfinder</title>
    <style>body{background:#161618;color:#f5f5f7}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body class="cp-page">
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <div style="display:flex; gap:0.4rem; align-items:center;">
                <?= pedidosHeaderBtn() ?>
                <a href="/mi-seleccion" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Mi selección</a>
            </div>
        </div>
    </header>

    <main class="container cp-main">
        <?= breadcrumbs([['Mi selección', '/mi-seleccion'], ['Catálogo en PDF', null]], 'cpCrumbs') ?>
        <h1 class="ms-title">📄 Catálogo en PDF <span id="cpCount"></span></h1>
        <p class="ms-help">Elige colores y textos, revisa el precio de cada código (o quítalos) y, si quieres, escribe otra
            información debajo: si la dejas vacía, no sale nada. Todo se arma <strong>en este dispositivo</strong> y lo que escribes se
            recuerda aquí para la próxima vez.</p>

        <?php if ($db): ?>
            <details class="ms-look cp-filter<?= $fromFilter ? ' on' : '' ?>" id="cpFilter"<?= $fromFilter ? ' open' : '' ?>>
                <summary><h2>🔎 Armar con filtros</h2><span class="cp-filter-hint">Para armar el catálogo con otros productos</span></summary>
                <p class="cp-filter-help">Elige marcas, carpetas, categorías, precio o un texto (puedes marcar varias) y toca
                    «📄 Armar catálogo»: entran las fotos de todos los productos que cumplan (hasta 300).</p>
                <?php $filterTarget = '/catalogo-pdf'; include __DIR__ . '/../partials/filter_bar.php'; ?>
                <p class="cp-filter-st" id="cpFilterSt" hidden></p>
            </details>
        <?php endif; ?>

        <div class="ms-empty" id="cpEmpty" hidden>
            <p id="cpEmptyMsg">No hay fotos marcadas.</p>
            <p>Arma el catálogo con los filtros de arriba, o marca fotos en <a href="/buscar">Buscar</a>, en una carpeta o en
                <a href="/enviar-catalogo">Enviar fotos con lista</a> y vuelve con «📄 PDF».</p>
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
                    <div class="cp-prev-grid"><span><em></em><q>Reloj dama acero dorado</q><b>M:8363-3</b><i></i><small>$120.000</small><u>Abrir foto</u></span><span><em></em><q>Reloj caballero cuero</q><b>M:8291-1</b><i></i><small>$95.000</small><u>Abrir foto</u></span><span><em></em><q>Cadena plata 50 cm</q><b>M:1401-5</b><i></i><small></small><u>Abrir foto</u></span></div>
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
                    <label><input type="radio" name="cpPer" value="1"> 1 <small>(grande)</small></label>
                    <label><input type="radio" name="cpPer" value="2"> 2</label>
                    <label><input type="radio" name="cpPer" value="4"> 4</label>
                    <label><input type="radio" name="cpPer" value="6"> 6</label>
                    <label><input type="radio" name="cpPer" value="9"> 9</label>
                </div>
                <label class="cp-check"><input type="checkbox" id="cpNames"> Nombre del producto encima del código
                    <small>(hasta 2 líneas; si es más largo se corta con «…»)</small></label>
                <label class="cp-check"><input type="checkbox" id="cpOpen"> Botón «Abrir foto» debajo de cada foto
                    <small>(al tocarlo abre la foto en Google Drive; el enlace no se ve en el PDF)</small></label>
                <small class="cp-tip">Para separar por secciones (p. ej. «Dama», «Caballero»), toca «＋ Subtítulo» en la foto donde empieza cada una.</small>
            </section>

            <section class="ms-look cp-money" id="cpMoney">
                <h2>🧮 Precios y total</h2>
                <label class="cp-check"><input type="checkbox" id="cpPrice"> Con precio
                    <small>(quítala para un catálogo sin precios; los precios que cambies se recuerdan)</small></label>
                <div class="cp-money-on" id="cpMoneyOn">
                    <label class="cp-check"><input type="checkbox" id="cpVisor"> Usar el precio del visor
                        <small>(el que se ve en el catálogo; quítala y solo sale el que escribas en cada foto)</small></label>
                    <p class="cp-total" id="cpTotal" aria-live="polite"></p>
                    <p class="cp-noprice" id="cpNoPrice" hidden></p>
                    <label class="cp-check"><input type="checkbox" id="cpTotalPdf"> Página final con total, cantidades y resumen por marcas</label>
                    <div class="cp-adjust">
                        <span>Ajustar todos los precios</span>
                        <span class="cp-pct"><input type="number" id="cpPct" step="1" min="-90" max="500" placeholder="10" aria-label="Porcentaje"> %</span>
                        <button type="button" class="btn btn-sm btn-secondary" id="cpPctApply">Aplicar</button>
                        <button type="button" class="btn btn-sm btn-secondary" id="cpPriceReset">↩ Volver a precios del visor</button>
                    </div>
                    <small class="cp-tip">Cambia el precio en la casilla de cada foto (ej.: 120000 o 120.000). Usa −10 para bajar 10 %. El precio de un código vale para todas sus fotos.</small>
                </div>
            </section>

            <section class="ms-look cp-bulk">
                <h2>✏️ Texto rápido</h2>
                <div class="cp-bulk-row">
                    <input type="text" id="cpBulkText" maxlength="120" placeholder="Ej.: Disponible en 3 colores">
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
        <label class="cp-name" id="cpNameRow" hidden>📝 Nombre del archivo
            <span class="cp-name-in"><input type="text" id="cpName" maxlength="120" autocomplete="off" spellcheck="false" aria-label="Nombre del archivo PDF"><span>.pdf</span></span>
        </label>
        <div class="cp-bar-row">
            <button type="button" class="btn btn-primary" id="cpMake">📄 Generar PDF</button>
            <button type="button" class="btn cp-share" id="cpShare" hidden>📲 Compartir</button>
            <button type="button" class="btn cp-preview" id="cpPreview" hidden>👁 Previo</button>
            <button type="button" class="btn btn-secondary" id="cpDown" hidden>⬇️ Descargar</button>
        </div>
        <div class="cp-msg" id="cpMsg" aria-live="polite"></div>
    </div>

    <div class="ol-pv" id="cpPv" hidden role="dialog" aria-modal="true" aria-label="Previo del PDF">
        <div class="ol-pv-head">
            <strong id="cpPvTitle">👁 Previo</strong>
            <label class="cp-name ol-pv-name">📝 <span class="cp-name-in"><input type="text" id="cpPvName" maxlength="120" autocomplete="off" spellcheck="false" aria-label="Nombre del archivo PDF"><span>.pdf</span></span></label>
            <button type="button" class="btn cp-share" id="cpPvShare" hidden>📲 Compartir</button>
            <button type="button" class="btn btn-primary" id="cpPvDown">⬇️ Descargar</button>
            <button type="button" class="btn btn-secondary" id="cpPvClose">✕ Cerrar</button>
        </div>
        <div class="ol-pv-pages" id="cpPvPages"></div>
    </div>

    <script src="/assets/vendor/jspdf-4.2.1.umd.min.js" defer></script>
    <script src="/assets/js/pdf_shared.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="/assets/js/catalog_pdf.js?v=<?= APP_VERSION ?>" defer></script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
