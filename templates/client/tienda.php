<?php
/**
 * Tienda web pública (/tienda): catálogo de venta de Kino Company. Mobile-first.
 * Explorar con fotos (categorías = carpetas de Drive), buscar, filtros, «+» para agregar, carrito, datos de entrega y pago.
 * Solo muestra productos activos para el catálogo (is_active) con foto. El pedido entra al sistema como «Recibido» (origen tienda).
 * Lee MySQL por /api/pedido/explorar; envía con /hacer-pedido/enviar (tienda=1).
 */
$tagline = appSetting('tienda_tagline', 'Medellín · Bogotá · Panamá');
$delivery = appSetting('tienda_delivery', '');
$bank = shopBankInfo();
// Fotos reales del catálogo para la portada y la tarjeta de JOEFOX (solo lee MySQL; sin foto no se muestra el bloque).
$pickPhoto = function (string $where, array $args = []) {
    try {
        $st = getDB()->prepare("SELECT sku, name, cover_image_url FROM products WHERE archived = 0 AND is_active = 1 AND cover_image_url > '' AND cover_image_url NOT LIKE '[VIDEO]%' $where LIMIT 1");
        $st->execute($args);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['url' => coverUrlAtSize($r['cover_image_url'], 900), 'alt' => trim((string) $r['name']) !== '' ? $r['name'] : 'Reloj ' . $r['sku']] : null;
    } catch (\Throwable $e) { return null; }
};
$heroSku = trim(appSetting('tienda_hero_sku', ''));
$hero = ($heroSku !== '' ? $pickPhoto('AND sku = ?', [$heroSku]) : null)
    ?? $pickPhoto("AND brand IN ('POEDAGAR','JOEFOX') AND price_suggested > 0 ORDER BY (brand = 'POEDAGAR') DESC, sku");
$joefoxPhoto = $pickPhoto("AND brand = 'JOEFOX' ORDER BY (price_suggested > 0) DESC, sku DESC");
$img = '/assets/img/marcas/';
$ver = APP_VERSION;
?><!DOCTYPE html>
<html lang="es" data-default-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title>Kino Company · Tienda de relojes</title>
<meta name="description" content="Relojes POEDAGAR, JOEFOX y más. Elige con fotos, agrega a tu pedido y recíbelo contra entrega o por transferencia.">
<meta property="og:title" content="Kino Company · Tienda de relojes"><meta property="og:type" content="website">
<style>body{background:var(--color-bg,#fbfbfd);color:var(--color-text,#1d1d1f)}</style>
<link rel="preconnect" href="https://lh3.googleusercontent.com">
<link rel="preload" as="image" href="<?= $img ?>kino-company.webp">
<script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="/assets/css/tienda.css?v=<?= @filemtime(BASE_DIR . '/assets/css/tienda.css') ?>">
</head>
<body class="tn">
<header class="tn-head"><div class="tn-head-in">
    <a class="tn-brand" href="/tienda" aria-label="KINO COMPANY, inicio">
        <img class="tn-logo tn-logo-l" src="<?= $img ?>kino-company.webp?v=<?= $ver ?>" alt="KINO COMPANY" width="720" height="274">
        <img class="tn-logo tn-logo-d" src="<?= $img ?>kino-company-oscuro.webp?v=<?= $ver ?>" alt="" width="720" height="274" aria-hidden="true">
    </a>
    <button class="tn-cartbtn" id="tnCartBtn" type="button" aria-label="Mi pedido"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7h12l-1 12H7L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg><span class="tn-cartlbl">Mi pedido</span><span class="tn-cartn" id="tnCartN" hidden>0</span></button>
</div></header>

<main class="tn-main" id="tnMain">
    <section class="tn-hero" id="tnHero">
        <div class="tn-hero-txt">
            <h1>Las mejores marcas y el mejor respaldo para tu negocio</h1>
            <p class="tn-sub"><?= e($tagline) ?><?= $delivery !== '' ? ' · ' . e($delivery) : '' ?></p>
            <div class="tn-bar" id="tnBarTop">
                <label class="tn-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4-4"/></svg>
                    <input type="search" id="tnQ" placeholder="Buscar por referencia, marca o producto" autocomplete="off" enterkeyhint="search" aria-label="Buscar por referencia, marca o producto"></label>
                <button class="tn-filterbtn" id="tnFilterBtn" type="button" aria-expanded="false" aria-controls="tnFilters"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/></svg><span>Filtros</span><b id="tnFilterN" hidden></b></button>
            </div>
            <div class="tn-filters" id="tnFilters" hidden>
                <select id="tnMarca" class="tn-sel" aria-label="Marca"><option value="">Todas las marcas</option></select>
                <span class="tn-seg" id="tnGenero" role="group" aria-label="Para">
                    <button type="button" data-g="hombre">Hombre</button><button type="button" data-g="mujer">Mujer</button><button type="button" data-g="unisex">Unisex</button>
                </span>
                <select id="tnPrecio" class="tn-sel" aria-label="Precio">
                    <option value="">Cualquier precio</option><option value="0-50000">Hasta $50.000</option><option value="50000-100000">$50.000 – $100.000</option>
                    <option value="100000-200000">$100.000 – $200.000</option><option value="200000-">Más de $200.000</option>
                </select>
                <button type="button" class="tn-pill" id="tnStock" aria-pressed="false">Solo con stock</button>
                <select id="tnOrden" class="tn-sel" aria-label="Ordenar">
                    <option value="">Ordenar: Código A-Z</option><option value="precio-asc">Precio: menor a mayor</option><option value="precio-desc">Precio: mayor a menor</option><option value="codigo-desc">Código Z-A</option>
                </select>
                <button type="button" class="tn-link" id="tnClear" hidden>Limpiar</button>
            </div>
            <a class="tn-cta" href="#tnCatalog" id="tnCta">Ver catálogo</a>
        </div>
        <?php if ($hero): ?><figure class="tn-hero-img"><img src="<?= e($hero['url']) ?>" alt="<?= e($hero['alt']) ?>" fetchpriority="high" decoding="async"></figure><?php endif; ?>
    </section>

    <div id="tnBrowse">
        <section class="tn-sec" aria-labelledby="tnCatH">
            <h2 id="tnCatH">Por categoría</h2>
            <nav class="tn-cats" id="tnCats" aria-label="Categorías"></nav>
        </section>
        <section class="tn-sec" aria-labelledby="tnBrandH">
            <h2 id="tnBrandH">Por marca</h2>
            <div class="tn-brands">
                <button type="button" class="tn-brand-card" data-marca="JOEFOX">
                    <span class="tn-bc-txt">
                        <img class="tn-bc-logo tn-logo-l" src="<?= $img ?>joefox.webp?v=<?= $ver ?>" alt="JOEFOX" width="640" height="281">
                        <img class="tn-bc-logo tn-logo-d" src="<?= $img ?>joefox-oscuro.webp?v=<?= $ver ?>" alt="" aria-hidden="true" width="640" height="281">
                        <span class="tn-bc-cap">Marca propia de KINO COMPANY</span>
                        <span class="tn-bc-go">Ver colección</span>
                    </span>
                    <?php if ($joefoxPhoto): ?><span class="tn-bc-ph"><img src="<?= e($joefoxPhoto['url']) ?>" alt="" loading="lazy" decoding="async"></span><?php endif; ?>
                </button>
                <button type="button" class="tn-brand-card tn-bc-dark" data-marca="POEDAGAR">
                    <span class="tn-bc-txt">
                        <img class="tn-bc-logo" src="<?= $img ?>poedagar-blanco.png?v=<?= $ver ?>" alt="POEDAGAR" width="1200" height="887">
                        <span class="tn-bc-cap">KINO es distribuidor exclusivo autorizado de POEDAGAR en Colombia</span>
                        <span class="tn-bc-go">Ver colección</span>
                    </span>
                    <span class="tn-bc-ph"><img src="/assets/img/tienda/poedagar-oro-rosa-card.webp?v=<?= $ver ?>" alt="" loading="lazy" decoding="async"></span>
                </button>
            </div>
            <p class="tn-allbrands"><button type="button" class="tn-link" id="tnAllBrands" aria-expanded="false" aria-controls="tnBrandList">Ver todas las marcas</button></p>
            <div class="tn-brandlist" id="tnBrandList" hidden></div>
        </section>
    </div>

    <section class="tn-results" id="tnCatalog" aria-labelledby="tnTitle">
        <nav class="tn-crumbs" id="tnCrumbs" aria-label="Ruta"></nav>
        <div class="tn-headrow"><h2 id="tnTitle">Todos los productos</h2><span id="tnCount"></span></div>
        <nav class="tn-subs" id="tnSubs" aria-label="Subcategorías" hidden></nav>
        <div class="tn-grid" id="tnGrid" aria-live="polite"></div>
        <p class="tn-empty" id="tnEmpty" hidden>No encontramos productos con esa búsqueda. Prueba con otra referencia o quita filtros.</p>
        <div class="tn-more"><button type="button" class="tn-btn tn-btn-ghost" id="tnMore" hidden>Ver más productos</button></div>
    </section>
</main>

<footer class="tn-foot">
    <b>Kino Company</b> · <?= e($tagline) ?>
    <span>Precios en pesos colombianos (COP). Pedidos por WhatsApp.</span>
</footer>

<button type="button" class="tn-bottombar" id="tnBar" hidden><span id="tnBarN"></span><b id="tnBarT"></b><span class="tn-bar-go">Revisar pedido</span></button>

<dialog class="tn-quick" id="tnQuick" aria-label="Detalle del producto"></dialog>

<div class="tn-scrim" id="tnScrim" hidden></div>
<aside class="tn-drawer" id="tnDrawer" aria-label="Mi pedido" aria-hidden="true" tabindex="-1">
    <div class="tn-dhead"><button type="button" class="tn-back" id="tnBack" aria-label="Volver" hidden><svg viewBox="0 0 24 24"><path d="m15 5-7 7 7 7"/></svg></button>
        <h2 id="tnDTitle">Mi pedido</h2><button type="button" class="tn-x" id="tnClose" aria-label="Cerrar"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>
    <ol class="tn-steps" id="tnSteps" aria-hidden="true"><li class="on">Pedido</li><li>Entrega</li><li>Pago</li></ol>

    <div class="tn-dbody">
        <section id="tnS1">
            <p class="tn-note" id="tnCartEmpty">Tu pedido está vacío. Toca «Agregar al pedido» en cualquier reloj.</p>
            <ul class="tn-lines" id="tnLines"></ul>
        </section>
        <section id="tnS2" hidden>
            <label class="tn-f">Nombre completo<input id="tnName" maxlength="120" autocomplete="name" required></label>
            <label class="tn-f">WhatsApp / celular<input id="tnPhone" maxlength="40" inputmode="tel" autocomplete="tel" required></label>
            <div class="tn-row2">
                <label class="tn-f">Ciudad<input id="tnCity" maxlength="80" autocomplete="address-level2" required></label>
                <label class="tn-f">Barrio (opcional)<input id="tnBarrio" maxlength="60" autocomplete="address-level3"></label>
            </div>
            <label class="tn-f">Dirección de entrega<input id="tnAddr" maxlength="170" autocomplete="street-address" required></label>
            <label class="tn-f">Nota (opcional)<textarea id="tnNote" maxlength="300" rows="2" placeholder="Ej. entregar en portería"></textarea></label>
            <input type="text" id="tnWeb" tabindex="-1" autocomplete="off" aria-hidden="true" class="tn-trap">
        </section>
        <section id="tnS3" hidden>
            <fieldset class="tn-pay"><legend>¿Cómo quieres pagar?</legend>
                <label class="tn-opt"><input type="radio" name="tnPay" value="contra_entrega" checked><span><b>Contra entrega</b><small>Pagas al recibir tu pedido.</small></span></label>
                <label class="tn-opt"><input type="radio" name="tnPay" value="transferencia"><span><b>Transferencia</b><small><?= $bank !== '' ? 'Pagas por banco o Nequi y nos envías el comprobante por WhatsApp.' : 'Te enviamos los datos de pago por WhatsApp.' ?></small></span></label>
            </fieldset>
            <?php if ($bank !== ''): ?><div class="tn-bank" id="tnBank" hidden><?= nl2br(e($bank)) ?></div><?php endif; ?>
            <div class="tn-sum" id="tnSum"></div>
        </section>
        <section id="tnDone" class="tn-done" hidden>
            <div class="tn-check" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></div>
            <h3>¡Pedido recibido!</h3>
            <p>Tu pedido <b id="tnDoneNo"></b> quedó registrado. Toca el botón verde para enviárnoslo por WhatsApp y confirmarlo.</p>
            <a class="tn-btn tn-btn-wa" id="tnDoneWa" target="_blank" rel="noopener">Enviar pedido por WhatsApp</a>
            <a class="tn-btn tn-btn-ghost" id="tnDoneMine" target="_blank" rel="noopener">Enviarme mi compra a mi WhatsApp</a>
            <a class="tn-btn tn-btn-ghost" id="tnDoneView">Ver mi pedido con fotos</a>
            <button type="button" class="tn-btn tn-btn-ghost" id="tnDoneAgain">Seguir comprando</button>
        </section>
    </div>

    <div class="tn-dfoot" id="tnFoot">
        <p class="tn-err" id="tnErr" role="alert" hidden></p>
        <div class="tn-totalrow"><span>Total</span><b id="tnTotal">$0</b></div>
        <button type="button" class="tn-btn tn-btn-primary" id="tnNext" disabled>Continuar</button>
    </div>
</aside>

<script>window.TN = {csrf: '<?= csrfToken() ?>', notify: '<?= e(shopNotifyPhone()) ?>', bank: <?= json_encode($bank !== '', JSON_UNESCAPED_UNICODE) ?>};</script>
<script src="/assets/js/tienda.js?v=<?= @filemtime(BASE_DIR . '/assets/js/tienda.js') ?>" defer></script>
</body></html>
