<?php
/**
 * Hacer pedido (/hacer-pedido, cliente) y Vender (/vender = /pedidos/nuevo, vendedor: $moStaff = true). v2 módulos 3-4.
 * Explorador visual: carpetas con foto → fotos de productos → tocar para agregar (con cantidad); buscador del visor;
 * carrito siempre visible (columna a la derecha, o barra abajo en el celular). Carrito en localStorage; el envío
 * crea el pedido núcleo «recibido». Sin cuenta: nombre + WhatsApp.
 * Vendedor: dentro del marco v2 (menú lateral «Vender»), buscador en el centro y pedido a la derecha.
 */
$moStaff = !empty($moStaff);
$cartKey = $moStaff ? 'kinoCarritoVendedor' : 'kinoCarrito';
?>
<?php if ($moStaff):
    $v2 = ['nav' => 'vender', 'title' => 'Vender', 'subtitle' => 'Busca con fotos y arma el pedido del cliente'];
    include __DIR__ . '/../admin/partials/v2_start.php'; ?>
<link rel="preconnect" href="https://lh3.googleusercontent.com">
<link rel="stylesheet" href="/assets/css/make_order.css?v=<?= @filemtime(BASE_DIR . '/assets/css/make_order.css') ?>">
<script>window.KINO_CART_KEY = '<?= $cartKey ?>';</script>
<?php else: ?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Hacer pedido · Viewfinder</title>
<style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
<link rel="preconnect" href="https://lh3.googleusercontent.com">
<script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="/assets/css/make_order.css?v=<?= @filemtime(BASE_DIR . '/assets/css/make_order.css') ?>">
<script>window.KINO_CART_KEY = '<?= $cartKey ?>';</script>
</head><body>
<header class="header"><div class="container header-inner">
    <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
    <div style="display:flex;gap:.4rem;align-items:center">
        <?= pedidosHeaderBtn() ?>
    </div>
</div></header>
<?php endif; ?>
<<?= $moStaff ? 'div class="mo-wrap mo-v2"' : 'main class="mo-wrap"' ?>>
    <section class="mo-card" id="moDone" hidden>
        <h2>✅ Pedido enviado</h2>
        <p class="mo-help">Recibimos el pedido <b id="moDoneId"></b>. <?= $moStaff ? 'Ya está en «Recibido».' : 'Te contactaremos por WhatsApp para confirmarlo.' ?></p>
        <a class="btn btn-primary" href="<?= $moStaff ? '/pedidos' : '/buscar' ?>"><?= $moStaff ? 'Ver pedidos' : 'Seguir viendo el catálogo' ?></a>
        <button type="button" class="btn btn-secondary" id="moEditLast" hidden>✏️ Editar este pedido</button>
        <a class="btn btn-secondary" href="" onclick="location.reload();return false"><?= $moStaff ? 'Hacer otro' : 'Hacer otro pedido' ?></a>
    </section>

    <div class="mo-banner" id="moMine" hidden></div>
    <div class="mo-grid" id="moWork">
        <div class="mo-browse">
            <?php if (!$moStaff): ?><h1 class="mo-title">🛒 Hacer pedido</h1><?php endif; ?>
            <div class="mo-searchbar"><input type="search" id="moQ" class="mo-input" placeholder="Buscar por código o nombre (ej. M8363-3)" autocomplete="off"></div>
            <div class="mo-filters" id="moFilters" aria-label="Filtros">
                <select id="moMarca" class="mo-sel" aria-label="Categoría"><option value="">Todas las categorías</option></select>
                <span class="mo-chipset" id="moGenero">
                    <button type="button" class="mo-chip" data-g="hombre">Hombre</button>
                    <button type="button" class="mo-chip" data-g="mujer">Mujer</button>
                    <button type="button" class="mo-chip" data-g="unisex">Unisex</button>
                </span>
                <select id="moPrecio" class="mo-sel" aria-label="Precio">
                    <option value="">Cualquier precio</option><option value="0-50000">Hasta $50.000</option><option value="50000-100000">$50.000 – $100.000</option>
                    <option value="100000-200000">$100.000 – $200.000</option><option value="200000-">Más de $200.000</option>
                </select>
                <button type="button" class="mo-chip" id="moStock">Solo con stock</button>
                <select id="moOrden" class="mo-sel" aria-label="Ordenar">
                    <option value="">Ordenar: Código A-Z</option><option value="codigo-desc">Código Z-A</option><option value="precio-asc">Precio: menor a mayor</option><option value="precio-desc">Precio: mayor a menor</option>
                </select>
                <button type="button" class="mo-chip mo-clear" id="moClear" hidden>✕ Limpiar filtros</button>
            </div>
            <nav class="mo-crumbs" id="moCrumbs"></nav>
            <div class="mo-folders" id="moFolders"></div>
            <p class="mo-help" id="moHint"></p>
            <div class="mo-items" id="moItems"></div>
            <div class="mo-more" id="moMore" hidden><button type="button" class="btn btn-secondary" id="moMoreBtn">Ver más</button></div>
        </div>

        <aside class="mo-cartbox" id="moCartBox">
            <div class="mo-sheet-head" id="moSheetHead"><b>Tu pedido <span id="moCount"></span></b><span id="moHeadTotal"></span><button type="button" class="mo-close" id="moClose" aria-label="Cerrar">✕</button></div>
            <div class="mo-sheet-body">
                <p class="mo-help" id="moEmpty">Toca una foto para agregarla al pedido.</p>
                <ul class="mo-cart" id="moCart"></ul>
                <div class="mo-total" id="moTotal" hidden>Total: <b id="moTotalV"></b></div>
                <div id="moForm">
                    <label class="mo-lbl"><?= $moStaff ? 'Nombre del cliente' : 'Nombre' ?> <input class="mo-input" id="moName" maxlength="120" autocomplete="<?= $moStaff ? 'off' : 'name' ?>"<?= $moStaff ? ' list="moCust"' : '' ?>><?php if ($moStaff): ?><datalist id="moCust"></datalist><?php endif; ?></label>
                    <label class="mo-lbl">WhatsApp / teléfono <input class="mo-input" id="moPhone" maxlength="40" inputmode="tel" autocomplete="tel"></label>
                    <label class="mo-lbl">Nota (opcional) <textarea class="mo-input" id="moNote" maxlength="500" rows="2"></textarea></label>
                    <input type="text" id="moWeb" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
                    <p class="mo-err" id="moErr" role="alert" hidden></p>
                    <button class="btn btn-primary mo-send" id="moSend" type="button" disabled>Enviar pedido</button>
                </div>
            </div>
        </aside>
    </div>
    <button type="button" class="mo-bar" id="moBar" hidden><span id="moBarN">0</span> ref. · <b id="moBarT"></b> <span class="mo-bar-go">Ver pedido ›</span></button>
</<?= $moStaff ? 'div' : 'main' ?>>
<script>window.MO_CSRF = '<?= csrfToken() ?>'; window.MO_STAFF = <?= $moStaff ? 'true' : 'false' ?>;</script>
<script src="/assets/js/cart_badge.js?v=<?= APP_VERSION ?>" defer></script>
<script src="/assets/js/make_order.js?v=<?= @filemtime(BASE_DIR . '/assets/js/make_order.js') ?>" defer></script>
<?php if ($moStaff): include __DIR__ . '/../admin/partials/v2_end.php'; else: ?>
</body></html>
<?php endif; ?>
