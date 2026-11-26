<?php
/**
 * Herramientas de v1 que viven en «Configuraciones» (v2). Una sola lista para la página
 * /admin/configuraciones y para la barra de pestañas de las pantallas v1 (partials/nav.php).
 * [clave => [ruta, texto, descripción, grupo]]
 */
$configTools = [
    'dashboard' => ['/admin/dashboard', '📊 Dashboard', 'Tarjetas con conteos, listas y Excel de cada una; caché.', 'Panel y listas'],
    'products' => ['/admin/products', '📦 Lista detallada', 'Productos v1: todos los filtros, motivo y Excel completo.', 'Panel y listas'],
    'folders' => ['/admin/folders', '📂 Carpetas', 'Mostrar u ocultar carpetas de Drive y su portada.', 'Catálogo y fotos'],
    'media' => ['/admin/media', '🖼️ Media', 'Fotos y videos de Drive, portadas, conexión con Google.', 'Catálogo y fotos'],
    'sync' => ['/admin/sync', '🔄 Sincronizar', 'Índice de Drive, Hoja master, portadas y «¿Por qué no sale la foto?».', 'Catálogo y fotos'],
    'hoja' => ['/admin/hoja', '📋 Hoja master', 'Vista previa de la Hoja master.', 'Datos'],
    'problemas' => ['/admin/problemas', '🩺 Problemas', 'Productos y fotos con algo por corregir.', 'Datos'],
    'inventario' => ['/admin/inventario', '📦 Inventario', 'Subir archivo SYS y comparar stock.', 'Datos'],
    'precios' => ['/admin/precios', '💲 Precios', 'Precio SYS, multiplicador y precio público.', 'Datos'],
    'condenados' => ['/admin/condenados', '🚫 Condenados', 'Códigos que se ignoran en todo el sistema.', 'Datos'],
    'remision' => ['/admin/remision', '🚚 Cotización', 'Plantilla de la Remisión / Cotización en PDF.', 'Documentos'],
    'pedidos' => ['/admin/pedidos', '🗄 Pedidos v1', 'Respaldo de los pedidos de bodega de v1.', 'Respaldo y registro'],
    'registro' => ['/admin/registro', '🧾 Registro', 'Errores y eventos del sistema; respaldos nocturnos.', 'Respaldo y registro'],
];
if (env('ALLOW_DB_RESTORE') === '1') {
    $configTools['restaurar'] = ['/admin/restaurar', '♻️ Restaurar respaldo', 'Cargar un respaldo de la base de datos (solo en el servicio v2).', 'Respaldo y registro'];
}
// Herramientas de pedido de v1 (siguen funcionando; el pedido nuevo está en «Pedidos»)
$configLinks = [
    ['/extractor', '📄 Extractor', 'Leer una cotización o lista y armar documentos.'],
    ['/pedido-bodega', '📦 Pedido de bodega', 'Checklist de alistado v1.'],
    ['/pedidos-alistar', '🗂 Pedidos bodega', 'Pedidos de bodega guardados.'],
    ['/enviar-catalogo', '📤 Enviar fotos con lista', 'Pegar una lista y mandar las fotos.'],
    ['/catalogo-pdf', '📕 Catálogo PDF', 'Armar un catálogo en PDF.'],
];
