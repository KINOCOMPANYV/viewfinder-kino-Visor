# Sistema KINO conectado

Fecha: 2026-11-28. Fuente actual de inventario: **archivos Excel/CSV exportados de SYS**, confirmado por Kino.

## Objetivo

Un solo inventario para Productos, Vender, tienda y consultas del catálogo. Interfaz clara y responsive, con estados visibles y recuperación cuando una conexión falla.

## Bloque implementado: inventario SYS

- Pantalla `/admin/inventario` dentro del marco v2: actualización, conexiones, historial, reportes y acciones de recuperación.
- La carga conserva su archivo de origen (nombre y huella SHA-256), usuario, fecha y resultado en MySQL, mediante migración 047. No conserva el archivo original subido.
- Stock SYS, precio SYS, precio público e historial de aplicación se confirman juntos. Si falla la publicación local, se deshacen sus cambios.
- Actualización de productos en tandas de 250, sin una consulta de escritura por cada referencia.
- La misma resolución de referencias se reutiliza en aplicación y reporte. Gana la coincidencia exacta; una coincidencia normalizada ambigua se conserva para revisión.
- Los precios contradictorios no se eligen arbitrariamente. Un precio ausente no borra el precio SYS existente. Se respetan los precios públicos editados manualmente.
- La Hoja master se envía después de publicar KINO. Su intención y valores quedan persistidos antes de llamar a Google.
- Un fallo de Google deja KINO actualizado y un envío pendiente. El reintento no requiere subir de nuevo el archivo y no permite enviar una carga superada por otra.
- Las referencias nuevas se agregan solo mediante la acción explícita existente. Al agregarlas, se intenta actualizar el catálogo y enlazar las fotografías disponibles. Si esa parte falla, se informa el pendiente para Sincronizar hoja.
- El menú móvil compartido incorpora estado accesible, foco de teclado, cierre con Escape y restauración de foco.

## Fuentes y consumidores

| Dato | Fuente | Consumidores |
|---|---|---|
| Existencias | Último Excel/CSV de SYS aplicado a `sys_stock`; fallback de Hoja master cuando no hay dato SYS. | Productos, buscadores, Vender, tienda y filtros que usan `stockSql()`. |
| Precio SYS | Archivo de SYS, con preservación del valor anterior si no se suministra uno. | Cálculo común del precio público y Hoja master. |
| Precio público | Precio SYS × multiplicador, salvo edición manual autorizada. | Catálogo, tienda, Vender y documentos que consultan el precio del visor. |
| Ficha y referencias | Hoja master. | Productos, clasificación y familias. |
| Fotografías | Índice de Google Drive en MySQL. | Tarjetas, fichas y documentos. |

## Reglas conservadas

- Un código ausente del archivo SYS conserva sus existencias. No se interpreta automáticamente como agotado.
- La opción de convertir stock negativo a cero sigue siendo configurable en cada carga.
- Los pedidos todavía no reservan ni descuentan inventario.
- No se activa ni desactiva automáticamente un producto por importar stock.
- La navegación GET de inventario no consulta en vivo a Google: lee el historial y los datos locales.
- Solo Administración/Desarrollo acceden al inventario, como en la ruta actual.

## Lo que no está conectado automáticamente

La descarga del archivo desde SYS sigue siendo manual. No se ha implementado un robot ni una API de SYS. El intervalo entre exportaciones determina la antigüedad del stock publicado.

KYTE, el gestor de manifiestos y el sistema de agotados de Google Fotos requieren contratos e integraciones propios; este cambio no afirma que estén conectados. Antes de enlazarlos deben fijarse sus fuentes autorizadas, referencias, permisos y reglas de reintento para no duplicar inventario ni pedidos.

## Verificación

Ejecutar sin datos reales ni Google:

    php tests/inventory_sync_test.php
    php tests/inventory_view_test.php
    node tests/v2_shell_test.mjs
    php tests/sku_match_test.php
    node tests/extractor_test.mjs

Las pruebas de importación usan SQLite en memoria y adaptan únicamente el operador null-safe de MySQL. Comprobación real de XLSX, rollback, precios protegidos, códigos similares, referencias nuevas, fallo/reintento de Google e intento de reenviar una carga antigua.

Pendiente en el entorno v2: aplicar migración 047, comprobar con MySQL y una copia representativa de Excel SYS, probar escritura real en una Hoja master de pruebas y revisar visualmente en 360, 768 y 1440 px, claro/oscuro. Docker no estaba iniciado y el navegador de comprobación no pudo acceder al servidor local durante el desarrollo.

## Siguientes bloques

1. Cerrar los controles de pedidos y permisos documentados en los análisis, con pruebas por vendedor/rol.
2. Unificar transiciones de pedido y facturación y eliminar las rutas alternativas que no respetan la cartera.
3. Acordar cuándo reservar inventario; implementarlo únicamente cuando SYS y KINO puedan conciliar los movimientos.
4. Integrar las aplicaciones externas con adaptadores y estados verificables, sin mezclar sus credenciales con el frontend.
5. Medir consultas lentas, tiempo de importación y errores de sincronización antes de ampliar cachés o infraestructura.
