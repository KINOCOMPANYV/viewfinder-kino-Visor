# Plan: rediseño de la tienda `/tienda` (estilo Apple Mac Colombia)

Estado: **propuesta, falta la aprobación del dueño**. Rama `v2`. No se toca `main`.

## Qué se mantiene (no cambia)
- Datos y reglas: solo productos con «Catálogo» encendido (`is_active`) y foto; precio público = SYS × multiplicador; API `/api/pedido/explorar`.
- Flujo del pedido: carrito en `localStorage`, pasos Pedido → Entrega → Pago, envío por `/hacer-pedido/enviar` y botones de WhatsApp («Enviar pedido por WhatsApp» al 3002436120 y «Enviarme mi compra a mi WhatsApp»). Sin envío automático.
- Archivos: `templates/client/tienda.php`, `assets/js/tienda.js`, `assets/css/tienda.css`. Sin librerías nuevas ni fuentes externas.

## Dirección visual
- Modo: **Persuadir** en la portada, **Operar** en el catálogo y el pedido (claridad antes que adorno).
- Referencia: página Mac de Apple Colombia. Fondo blanco, mucho aire, titulares negros grandes, fotos de producto como protagonistas, sin sombras ni bordes de más.
- Color: neutros (blanco `#fff`, gris `#f5f5f7`, texto `#1d1d1f`). **Rojo KINO `#D80F17`** (tomado del logo) solo en la acción principal: «Agregar al pedido», «Continuar», contador de «Mi pedido». Texto blanco sobre ese rojo: contraste ≈ 5,6:1 (pasa AA). El dorado del visor no se usa en la tienda.
- Tipografía: la del sistema (SF en Apple). Titular 56–80 px en escritorio, 36–40 px en móvil, peso 600, interletrado apretado. Precios con cifras tabulares.
- Modo oscuro: se conserva (gris grafito), el rojo se mantiene y los logos usan su variante clara (ver «Logos»).

## 1. Encabezado y portada
- Encabezado fijo y delgado: **logo KINO COMPANY** a la izquierda (reloj + engranaje), a la derecha «**Mi pedido**» con contador de unidades (rojo).
- Portada sobre blanco: titular **«Relojes al por mayor para tu negocio»** en negro grande, una línea de apoyo (ciudades / entrega) y botón rojo «Ver catálogo».
- Escritorio: texto a la izquierda, a la derecha la **foto real** de un reloj del catálogo donde se vean esfera, bisel y brazalete. La foto la elige el admin (código guardado en Configuraciones → 🛍 Tienda, campo nuevo «Reloj de portada»); si está vacío, se toma uno de JOEFOX/POEDAGAR con foto. La foto oro rosa de POEDAGAR queda como alternativa para una franja oscura de marca debajo de la portada (no en la portada, que es blanca).
- Móvil: titular y, justo debajo, el buscador **«Buscar por referencia, marca o producto»**, visible en la primera pantalla. La foto pasa debajo, más pequeña.

## 2. Explorar en dos entradas
- **Por categoría**: fila horizontal de tarjetas (imagen + nombre corto) con las familias que hoy existen (carpetas con foto que ya entrega la API). Solo las que tienen productos visibles.
- **Por marca**: dos tarjetas del mismo tamaño y peso:
  - JOEFOX: logo original + «Marca propia de KINO COMPANY».
  - POEDAGAR: tarjeta oscura con la foto oro rosa de fondo, logo blanco + «Distribuidor exclusivo autorizado en Colombia». JOEFOX lleva una foto de un reloj JOEFOX del catálogo en tarjeta clara, mismo tamaño.
  - Cada tarjeta abre su colección (filtro `marca` ya existente). Debajo, enlace «**Ver todas las marcas**» que abre la lista completa de marcas (de `facets.brands`).

## 3. Catálogo fácil de comparar
- Cuadrícula de fotos **del mismo tamaño** (cuadro 1:1, fondo gris claro, `object-fit: contain`: no se recorta ni se deforma el reloj).
- Debajo de cada foto, en filas alineadas: **referencia** (código), **nombre** (máx. 2 líneas, altura fija) y **precio COP** (`$ 89.900`).
- Acciones: «Ver detalle» como enlace (abre la ficha rápida que ya existe) y «**Agregar al pedido**» como botón rojo.
- Tras agregar, el botón se convierte en un selector **− 2 +** con la cantidad elegida.
- Estados claros: «**Agotado**» (stock 0: foto atenuada, botón deshabilitado) y «**Consultar precio**» (sin precio: botón que abre WhatsApp con la referencia, no se puede agregar sin precio).
- Al buscar o filtrar, los **resultados aparecen justo debajo de los controles** y la página se desplaza ahí; la portada y las entradas de exploración se ocultan mientras hay búsqueda/filtro activos. Una referencia exacta sale primero.

## 4. El pedido siempre a mano
- «Mi pedido» en el encabezado con unidades totales.
- Móvil: al agregar el primer artículo aparece una **barra inferior compacta** («3 unidades · $ 269.700 · Revisar pedido»).
- El panel de pedido permite ajustar cantidades y eliminar. Al cerrarlo se vuelve **al mismo punto del catálogo** (se guarda la posición y los filtros; el botón atrás del celular cierra el panel en vez de salir).
- Desde ahí sigue el flujo actual de entrega, pago y WhatsApp sin cambios.

## Logos (guardados en `assets/img/marcas/`)
- `kino-company.jpeg` (3792×1566, fondo blanco): original. Al implementar se genera una versión web liviana con fondo transparente y otra para modo oscuro (letras negras → blancas, rojo igual).
- `joefox-negro.png` (1650×1275, transparente): se usa tal cual en claro; en oscuro, versión con letras blancas.
- `poedagar-blanco.png` / `poedagar-negro.png` (1200 px, transparentes): recortados de la foto que envió el dueño. Blanco para fondo oscuro, negro para fondo claro.
- `assets/img/tienda/poedagar-oro-rosa-2400.webp` y `-1200.webp` (212 KB / 80 KB): foto de producto POEDAGAR (oro rosa, esfera de nácar, malla tejida, fondo negro), optimizada desde el original de 11584×8688.

## Etapas de implementación (tras aprobar)
1. Encabezado, portada y buscador móvil; logos web; campo «Reloj de portada».
2. Entradas «Por categoría» y «Por marca» + «Ver todas las marcas».
3. Tarjetas de catálogo (foto uniforme, alineación, estados, selector de cantidad) y resultados bajo los controles.
4. «Mi pedido», barra inferior móvil, volver al mismo punto.
5. Verificación en celular (390 px) y escritorio (1440 px), claro y oscuro: buscar una referencia exacta, agregar varias unidades, ir a otra marca y revisar el pedido sin perder la selección. Capturas antes/ahora y revisión de contraste.

Todo en un PR hacia `v2`, con `docs/AVANCES.md` actualizado.
