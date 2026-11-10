<?php
/**
 * Mi selección (/mi-seleccion, etapa C): las fotos marcadas en el inicio, búsqueda, carpetas, fichas o Enviar catálogo,
 * en una sola pantalla para revisarlas, ordenarlas, quitar, reintentar las que fallaron y repetir la última tanda.
 * Todo vive en el dispositivo (localStorage de wa_batch.js); la página no lee la BD. Guardar / Enviar = la misma barra.
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Mi selección · Viewfinder</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body class="ms-page">
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/buscar" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">← Seguir buscando</a>
        </div>
    </header>

    <main class="container ms-main">
        <h1 class="ms-title">📌 Mi selección <span id="msCount"></span></h1>
        <p class="ms-help">Las fotos que marcaste en el catálogo, en el orden en que se envían. Se guardan <strong>en este
            dispositivo</strong> (siguen aquí si cierras la página), no en otros celulares.</p>


        <section class="ms-look" id="msLook" hidden>
            <h2>🖼️ Cómo salen las fotos</h2>
            <div class="ms-look-opts" role="radiogroup">
                <label><input type="radio" name="msLabel" value="none"> Foto sola</label>
                <label><input type="radio" name="msLabel" value="code"> Foto con su código abajo</label>
            </div>
            <div class="ms-look-prev"><img id="msPrev" alt="Vista previa"><span id="msPrevMsg">Preparando vista previa…</span></div>
            <small>Se aplica al 💾 Guardar y al 📲 Enviar por tandas. Se arma en este celular.</small>
        </section>

        <div class="ms-last" id="msLast" hidden></div>

        <div class="ms-tools" id="msTools" hidden>
            <button type="button" class="btn btn-sm btn-secondary" id="msOne">Dejar 1 foto por código</button>
            <button type="button" class="btn btn-sm btn-secondary" id="msEmpty">Vaciar</button>
        </div>
        <ol class="ms-list" id="msList"></ol>
        <div class="ms-empty" id="msEmptyMsg" hidden>
            <p>No hay fotos marcadas.</p>
            <p>Toca el círculo de una foto en <a href="/buscar">Buscar</a>, en una carpeta o en una ficha, o usa
                <a href="/enviar-catalogo">Enviar catálogo</a>.</p>
        </div>

        <section class="ms-cats ms-cats-bottom" id="msLinks">
            <div class="ms-cats-head">
                <h2>🔗 Enlaces para compartir</h2>
                <button type="button" class="btn btn-sm btn-primary" id="msMakeLink">🔗 Crear enlace</button>
            </div>
            <ul class="ms-cat-list" id="msLinkList"></ul>
            <p class="ms-cat-empty" id="msLinkEmpty">Crea un enlace con las fotos marcadas: el cliente lo abre sin instalar nada y ve siempre la foto vigente. Puedes actualizarlo o desactivarlo desde este dispositivo.</p>
        </section>

        <section class="ms-cats ms-cats-bottom" id="msCats">
            <div class="ms-cats-head">
                <h2>📚 Catálogos guardados <small>(en este dispositivo)</small></h2>
                <button type="button" class="btn btn-sm btn-primary" id="msSaveAs">💾 Guardar como…</button>
            </div>
            <ul class="ms-cat-list" id="msCatList"></ul>
            <p class="ms-cat-empty" id="msCatEmpty">Guarda la selección con un nombre («Poedagar dorados», «Catálogo de noviembre») para volver a abrirla.</p>
        </section>
    </main>

    <script src="/assets/js/wa_batch.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/my_selection.js?v=<?= APP_VERSION ?>"></script>
    <?php $mnavActive = 'enviar'; include __DIR__ . '/../partials/mobile_nav.php'; ?>
</body>

</html>
