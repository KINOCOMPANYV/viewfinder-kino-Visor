# Avances — Viewfinder Kino Visor

Estado vivo del proyecto: decisiones vigentes y qué está hecho. Corto a propósito.
El "cómo funciona" está en `docs/ARQUITECTURA.md`; aquí solo el qué y el porqué.
**Regla: al terminar cada trabajo (PR fusionado), actualiza este archivo** (estado, decisiones, historial en 1 línea).

## Estado actual (2026-11-09)
- Producción: `viewfinder-k.up.railway.app` según los logs de Railway del 2026-11-10 (deploy automático desde `main`).
  `GOOGLE_REDIRECT_URI` debe usar ese mismo dominio.
  El entorno "PRUEBAS" (`…-pruebas.up.railway.app`) no cuenta: las variables se ponen en el entorno de producción.
- Drive raíz: env `GOOGLE_DRIVE_FOLDER_ID` = carpeta **PRODUCTOS**. Se indexan todos los niveles en `drive_index`.
- Hoja maestra: **"MAESTRA DE PRODUCTOS KINO"** (env `GOOGLE_SHEET_ID`), 3629 códigos. Revisión 2026-11-09: se lee
  completa (3629 de 3629, sin filas descartadas ni repetidas). En la hoja faltan datos: 124 filas sin PRICE_SUGGESTED (98 POEDAGAR)
  y 33 sin NAME; esas salen sin precio o con el código como nombre. Admin → Sincronizar muestra hoja vs "Solo Drive".
- Primer sync completo (2026-10-30): ~12 000 archivos, ~4400 portadas; ~600 productos sin foto (lista en Admin → Media).
- PRs #1–#41 fusionados. Plan de mejoras para distribuidores aprobado (2026-11-10, `docs/PLAN_MEJORAS.md`, etapas A-J).
- Plan de mejora UX/velocidad aprobado por el dueño (2026-11-09), un PR por etapa: 1 velocidad, 2 celular más claro,
  3 envío más rápido, 4 estética consistente, 5 app instalable, 6 búsqueda inteligente. Hechas: etapas 1 (#34), 2 (#35), 3 (#36) y 4 (#37).

## Decisiones vigentes (del dueño)
- **Versión 2 por módulos** (2026-11-26): rama larga `v2` desde `main`; producción no se toca; PRs hacia `v2`; plan en `docs/V2_PLAN.md` (módulos pendientes de OK). Servicio Railway aparte para v2.
- **Extraer una vez, armar cualquier documento** (2026-11-26): en el Extractor, «📄 Crear con esta lista» abre Lista Bodega, Cotización o Fotos/Catálogo PDF (se resalta el que corresponde al PDF). En Lista Bodega/Cotización, además de las columnas de siempre, **«Precio -X%» y «Total -X%»** con el % editable ahí mismo (viene del Extractor, 40 por defecto); con alguna marcada sale «Valor con X% desc.» en el encabezado y el recuadro «TOTAL CON X% DESC.» al final. Apagar «Valor total» quita también «TOTAL COTIZACIÓN». Precio, «Con descuento» y el % están en la misma tarjeta que los botones (un solo paso); escribir un % activa el descuento solo; con él marcado el documento abre con esas columnas.
- **Precio c/u y totales al día** (2026-11-26): Lista Bodega/Cotización llevan la columna «Precio c/u» (unitario; si el recibo trae el total de la línea se divide por la cantidad) junto a «Total», con la casilla de columnas de siempre. Si el Extractor edita la lista, los totales (referencias, unidades, valor total) se recalculan al pasar al documento. Catálogo PDF: «✕ Quitar» por producto antes de generar (conteo y total al instante).
- **Precio del visor opcional al editar PDFs** (2026-11-25): «precio del sistema» = el precio público del visor (SYS × multiplicador). Extractor → ✏️ Editar lista: botón para toda la lista y 💲 por línea (reversible); Catálogo PDF: casilla «Usar el precio del visor». Pedido bodega sigue con precios de solo lectura para el alistador.
- **Pulido de interfaz «impeccable»** (2026-11-25): paleta tranquila estilo macOS (grafito neutro #161618/#1c1c1e/#242426, texto #f5f5f7, grises #a1a1a6/#8e8e93, verde/rojo/amarillo tipo Apple) con dorado champaña #c4a46b (más sobrio que #c9a84c) como único acento, fuente del sistema (San Francisco en Mac/iPhone; sin Google Fonts), esquinas menos redondas (botones/chips 8 px vía `--radius-control`, sin pastillas), bordes de 1 px, títulos compactos, **iconos de línea SVG** (`icon('nombre')` en `helpers.php`, estilo Lucide, heredan el color; ya en barra inferior, encabezado, buscador, filtros, tarjetas, ficha y migas; emojis restantes en admin y mensajes JS se pasan por etapas); **modo claro estilo Apple** (2026-11-25, decisión del dueño): interruptor sol/luna en el encabezado (`assets/js/theme.js`, se recuerda en el navegador, oscuro por defecto); colores en `:root[data-theme="light"]` de `style.css`, los estilos en línea usan `var(--color-*, respaldo)` y `--rgb-*` para transparencias; Catálogo PDF y el canvas de fotos no cambian; colores solo con variables de `:root` en `style.css` (no hex sueltos nuevos), texto tenue con contraste ≥ 4.5, foco dorado visible con teclado, casillas a 16 px y botones de 44 px en celular, sin CDN ni frameworks nuevos. **2.ª pasada de contraste** (2026-11-25): un escáner (Playwright) mide el contraste de todo el texto en 25 pantallas, claro/oscuro, y ya no queda ninguno bajo 4.5 (3 si es grande); dorado del modo claro #80612a, botones dorados con `--color-on-primary`, WhatsApp verde hondo `--color-wa-btn` (#128040), colores fijos (#f87171, #fbbf24…) pasan a `--color-danger/warning/success`; resaltados más fuertes: precio en etiqueta verde, «In Stock/Out stock» con punto, código exacto y tarjetas seleccionadas con **naranja intenso** `--color-orange` (#ff9f0a oscuro / #b34700 claro) como acento secundario; iconos de línea con color por función (`.ico-<nombre>`: azul/morado/rosa/verde/naranja con `--ic-*`; en botones dorados o verdes heredan el texto).
- Stock público (2026-11-20): debajo de la foto, al lado del precio, «In Stock» en verde y «Out stock» en rojo (0 o negativo), sin la cantidad por ahora (decisión del dueño, mismo día), en tarjetas y ficha. Fuente: Stock SYS (📦 Inventario) y si no hay, STOCK de la Hoja master; producto Solo Drive sin dato SYS = no se muestra nada (`stockSql()`/`stockTag()` en helpers). Filtro «Stock: todos / Con stock / Sin stock» en todas las pantallas públicas, Catálogo PDF y Admin → Productos (sin dato de stock solo sale en «todos»).
- **Medir antes de agregar** (2026-11-23, decisión del dueño tras la auditoría): registro propio de errores (PHP y JS) y de búsquedas sin resultado en MySQL, visible en Admin → 🧾 Registro (sin Sentry: gratis y simple). Respaldo nocturno de TODA la base en Drive (`.sql.gz`) con prueba de restauración documentada (`docs/RESPALDOS.md`). Metas: 0 fatales en 7 días, menos búsquedas sin resultado.
- **Búsqueda por foto con IA de imágenes** (2026-11-10): se eligió comparar "huellas" (jina-clip-v2, servicio de pago por uso,
  centavos) sobre la huella gratis (solo reconoce la misma foto) y sobre describir la foto con IA (lenta y cara).
- **Foto ↔ producto**: el nombre del archivo es el código (o código + separador + lo que sea). "M:", "M" y "M-" son el mismo
  código. El nombre de la carpeta no cuenta. Lo que no coincide se lista para renombrar (Admin → Media, CSV).
- **Códigos combinados con «+»** (2026-11-23, decisión del dueño; reemplaza la del 2026-11-21): «S-640+S-647-3.jpg» es
  de su **propio código** «S-640+S-647-3», no del padre S-640. Un «+» seguido de otro código es parte del código
  (`isSkuBoundary()`); si no está en la hoja sale como «Solo Drive», familia «S-640+S-647» (-1 = padre).
  «X6071+caja.jpg» (texto, no código) sigue siendo de X6071. Pegar el nombre completo de la foto sigue funcionando.
- **Búsqueda por nombre exacto de archivo en todas partes** (2026-11-21, decisión del dueño): además de las búsquedas
  de siempre, cualquier buscador o lista acepta el nombre exacto de una foto. Sobre todo para **crear PDF**: hace falta
  la imagen exacta del producto, así que va esa foto y no la portada. Gana el nombre idéntico («1971-1.jpg», no «1971-1-2.jpg»).
- **Un solo flujo de enlace**: `DriveIndex::relink()` → `drive_index.matched_sku`. Portada: propia > familia > video. Todo lo
  público (galería, tarjetas, ficha, envíos) lee ese enlace. Nada de Drive en vivo al navegar.
- **Productos "Solo Drive"**: una foto cuyo nombre es un código que no está en la hoja crea el producto (nombre = código,
  marca = carpeta, sin precio). Excluidos: `IMG_`/WhatsApp, `.psd`, nombres sin dígitos, carpetas DESACTIVADOS.
  `DRIVE_PRODUCTS=0` los apaga. Vale también para un código que salió de la hoja o quedó oculto de antes (2026-11-13,
  caso M8324-1: salía la carpeta y no el producto); solo lo apagado en el admin sigue oculto. "M8324-4..jpg" = "M8324-4".
- **Activación**: solo el switch del admin (`is_active`). La hoja nunca reactiva. No se borra nada, solo se desactiva.
- **Carpetas**: se navegan como en Drive (ya no se muestran "álbumes"). Cada carpeta tiene switch mostrar/ocultar y portada al azar.
- **Carpeta fuera de la lista** (2026-11-13): en Admin → Carpetas cada carpeta tiene, aparte de mostrar/ocultar, el interruptor
  «Como carpeta». Apagado (p. ej. ECONOMICOS): la carpeta no sale en la lista de inicio ni en la de su carpeta superior, pero
  sus fotos y productos se siguen viendo y la carpeta sigue saliendo al buscar y por enlace.
- **Filtro «Carpetas» con 1 nivel** (2026-11-20, primero se probaron 2): la lista del filtro muestra solo las carpetas de
  1.er nivel (ACCESORIOS, AUTOMATICOS, CURREN…), ya no sus subcarpetas (› 427, › 427-1). Cada una trae también sus subcarpetas.
- **Carpetas "archivado" y "desactivados"** (2026-11-09): toda carpeta cuyo nombre diga archivado(s)/desactivado(s) (sin importar
  mayúsculas ni tildes) queda oculta por defecto con todo su contenido, ya indexada o nueva. Si el admin la muestra, el
  sync no la vuelve a ocultar. Mientras está oculta tampoco crea productos "Solo Drive".
- **Sync automático** en cada deploy y cada hora (`scripts/sync_loop.sh`); `AUTO_SYNC=0` lo apaga.
  Si Drive falla al listar, se reintenta y el error dice el código HTTP (401 = reconectar en Admin → Media).
- **WhatsApp = imágenes** (2026-11-09): los envíos mandan solo fotos, nunca la lista en texto. Una foto sola lleva su código
  como pie; en tandas no va texto (WhatsApp no deja un pie distinto por foto).
- **Una tarjeta por referencia** (2026-11-10): inicio, búsqueda y autocompletar muestran cada código por separado, sin la
  tira de variantes; las variantes se ven solo al abrir la ficha. Al buscar un código, el exacto sale primero marcado
  "✓ Código exacto" (con o sin "M"), y debajo las carpetas que coinciden.
- **Sin foto no se muestra** (2026-11-10): en lo público (inicio, búsqueda, sugerencias, ficha, variantes, Enviar catálogo,
  catálogo por enlace) solo salen productos con portada (foto o video de PRODUCTOS). No se desactivan: siguen en el admin y
  en 🩺 Problemas → sin foto, y aparecen solos cuando llega su foto. SQL: `archived = 0 AND cover_image_url > ''`.
- **Precio visible** (2026-11-14, reemplaza «fotos sin precio» del 2026-11-10): toda tarjeta de producto y la ficha muestran el
  PRICE_SUGGESTED de la Hoja master; sin precio sale «Sin precio en tabla». En Admin → Productos el precio se edita en la fila;
  el cambio vale solo en el visor (la Hoja master no se toca) y el sync ya no lo pisa («✏️ Editado», «↩ Hoja» lo devuelve).
  Dashboard: tarjetas «Sin precio en tabla» y «Precio editado en admin».
- **Precio público = Precio SYS × 2** (decisión del dueño 2026-11-24, reemplaza lo de KYTE de abajo): SYS es la **única fuente**.
  El Precio SYS (Admin → 📦 Inventario, columna PRICE de la Hoja master) es el **mayorista** y no se muestra, salvo que el admin
  active «Mostrar el precio mayorista» (solo en la ficha). El público ve `sys_price` (o PRICE de la hoja) × multiplicador
  (por defecto 2, configurable en Admin → 💲 Precios) en tarjetas, búsqueda, ficha y filtros (`recomputePublicPrices()` escribe
  `price_suggested`; corre al subir SYS, guardar el ajuste y en cada sync de la hoja). **KYTE ya no pone precio**: su archivo
  solo da un reporte. PRICE_SUGGESTED de la hoja se ignora. El precio editado a mano en el admin sigue mandando.
  Sin Precio SYS = «Sin precio en tabla» (se acabó el precio de la familia). Extractor: «Mostrar precio» sale marcado; Catálogo PDF:
  «Poner el precio del visor en cada foto» marcado por defecto (un texto escrito a mano en la foto lo reemplaza). Ajustes en tabla `app_settings` (migración 038).
- **Precios: SYS y KYTE (histórico, vale lo de arriba)** (decisión del dueño 2026-11-20, reemplaza «PRECIO KYTE» del 2026-11-14):
  - **KYTE** → columna **PRICE_SUGGESTED** de la Hoja master = precio que ve el público. Admin → 💲 Precios: al subir el
    Excel **se aplica solo en el visor** (precio editado; el sync suelta la marca cuando la Hoja master trae ese precio) y da
    la columna PRICE_SUGGESTED para pegar. No se cambian precios repetidos distintos, cambios de más del doble/menos de la
    mitad, ni 0. También pone precio a «Solo Drive». Código vacío en KYTE → primera palabra del Nombre.
  - **SYS** (INVENTARIO GENERAL) → columna **PRICE** (no se muestra por ahora; se usará más adelante) y STOCK.
    Admin → 📦 Inventario: al subir **se guardan al momento** Precio SYS y existencias en el visor (`sys_price`, `sys_stock`)
    y da las columnas STOCK y PRICE para pegar. Si se sube el archivo SYS en Precios, avisa que va en Inventario.
  - La columna «PRECIO KYTE» ya no se usa (si sigue en la hoja, se ignora). Fila sin PRICE_SUGGESTED → el de su familia
    (si es uno solo) → «Sin precio en tabla». El precio editado en el admin sigue ganando.
  - **Subir = escribir en la Hoja master** (decisión del dueño 2026-11-20, reemplaza «pegar a mano» del 2026-11-13): con la
    casilla «También escribir en la Hoja master» (marcada por defecto), KYTE escribe PRICE_SUGGESTED y SYS escribe PRICE y
    STOCK, solo celdas que cambian, buscando cada código en la columna SKU (`SheetWriter`). Usa la conexión de Google del
    admin; la cuenta conectada debe ser Editor de la hoja. Si falla, el visor igual queda actualizado y avisa el motivo.
- **Nombres** (2026-11-13): la hoja maestra se llama siempre **«Hoja master»**; el sistema contable es **«SYS»** (sus datos: «Stock SYS»,
  «Precio SYS»). Otro sistema, **«KYTE»**, traerá sus datos aparte más adelante.
- **Inventario SYS → Hoja master** (2026-11-13): el stock de SYS («INVENTARIO GENERAL.xlsx») se carga en la columna
  STOCK de la hoja **pegándola a mano**; el visor no escribe en la hoja. Admin → 📦 Inventario arma la columna en el orden de
  filas de la hoja y el reporte de diferencias. Cruce: código exacto primero (NO:610-1 y NO610-1 son filas distintas en ambos
  archivos), luego "M:", "M", "M-" iguales. Negativos → 0. Códigos del inventario que no están en la hoja no se agregan.
- **Códigos condenados** (2026-11-21): Admin → 🚫 Condenados, lista manual. Un código condenado no se tiene en cuenta en la
  Hoja master, «Solo Drive», enlace de fotos ni búsqueda; si estaba en el visor **se borra** (única excepción a «no se borran
  productos»). «M:», «M», «M-» = mismo código. Primero: M:8325-1. Quitar de la lista = vuelve en el próximo sync.
- **Precios del pedido** (2026-11-23): en Pedido de bodega el precio es **por unidad, en pesos colombianos** (sin decimales).
  «$130,000.00» = $130.000. Si el recibo trae el total de la línea, se divide por la cantidad.
  **El alistador no cambia precios** (2026-11-23): se ven como texto «$X c/u»; el servidor ignora cualquier precio distinto
  (conserva el guardado; código cambiado/agregado = precio del visor; «Deshacer» = precio original del pedido).
- **Resumen de cambios al alistar** (2026-11-23): al quedar listo (verde), Pedido de bodega muestra «📝 Cambios en el alistado»
  (quitados, cambiados código viejo → nuevo, menos/más cantidad, agregados, notas, unidades y totales), «📋 Copiar resumen» y
  «⬆ Volver al pedido». Sin cambios → «✅ Se alistó igual al pedido».
- **Respaldo de Pedidos bodega** (2026-11-23): siguen en MySQL de Railway (persistente, sobrevive a los deploys); **no Supabase**.
  Además: Admin → 🗄 Pedidos baja Excel/JSON y cada noche (desde las 22 h Colombia) se sube sola una copia a Drive.
- **Pedidos bodega: «Facturado»** (2026-11-24): un pedido listo (verde) tiene «🧾 Marcar facturado»; el usuario lo toca a mano
  cuando ya facturó en la otra aplicación y pasa a naranja 🟠 (pestaña «🟠 Facturados»). «↩ Quitar facturado» lo devuelve a verde.
  Se guarda en MySQL (`warehouse_orders.invoiced_at`, migración 037); sale también en el Excel de respaldo.
- **«Remisión» ahora se llama «Cotización»** (2026-11-23): título del PDF, «TOTAL COTIZACIÓN», archivo `Cotizacion_N.pdf`,
  botones, selector Tipo y Admin → 🚚 Cotización. Rutas (`/admin/remision`), tabla `doc_templates` y código interno `remision` siguen igual.
  Un título guardado «Remisión» en la plantilla se muestra como «Cotización».
- **Envío automático a un número** por WhatsApp no es posible desde la web: requeriría la API oficial de Meta (de pago).

## Funciones clave para el usuario
- Admin → Dashboard: cada tarjeta (total, visibles, desactivados, con/sin portada, actualizados hoy, fuera de la Hoja master),
  cada categoría del top y cada género abre Admin → Productos con exactamente esos productos (mismo conteo).
  Cada tarjeta tiene «⬇ Excel» y la lista «⬇ Descargar Excel»: toda la lista del filtro (no solo la página), con motivo.
- Catálogo público en grilla densa (60 por página, **una tarjeta por referencia**; contador "N referencias").
- Celular (2026-11-20): filtros plegados tras «⚙️ Filtros y orden» (los activos siguen como chips), barra de abajo con
  «⭐ Selección» y el número de fotos marcadas, ficha sin el botón repetido «Volver al catálogo», y carpetas grandes y
  Novedades muestran 60 fotos con «Cargar más». Las migas de pan (Inicio › Carpeta › …) son botones dorados que se ven y se tocan, y la flecha del encabezado dice «← Atrás».
- Ficha de producto con variantes, galería, visor grande; WhatsApp + Descargar en cada foto.
- Botón WhatsApp de cada foto: manda solo la imagen liviana (800 px) con el código como pie, sin enlace. En el computador
  copia la foto para pegarla en WhatsApp Web (Ctrl+V).
- Inicio y resultados de búsqueda/filtro: "Seleccionar" en cada tarjeta → barra abajo con "Marcar todas" (lo visible con el
  filtro), "⬇️ Descargar" y "📲 Enviar" por tandas de 10 (mismo sistema que Enviar catálogo, solo imágenes). Lo marcado se
  mantiene al hacer otra búsqueda y al pasar a otra carpeta, al inicio o a una ficha (en la misma pestaña), así se juntan
  fotos de varias páginas en un solo envío. Desde la etapa C se guarda en el dispositivo (sigue al cerrar la página) y
  "👁 Ver" abre **Mi selección** (`/mi-seleccion`): ordenar, quitar, reintentar las que no bajaron, repetir la última tanda.
  Enviar catálogo tiene "📌 Pasar a Mi selección". "📄 PDF" (barra) / "📄 Crear catálogo PDF" (Mi selección) abre
  `/catalogo-pdf`: texto opcional bajo cada código, título, contacto, logo propio, 4/6/9 por página → Compartir o Descargar.
  También «📄 Crear catálogo PDF» en el enlace `/c/…`, «📄 Crear PDF» en Enviar catálogo y «📄 PDF» en cada catálogo guardado.
  Diseño (arriba en esa pantalla): colores primario y secundario (6 combinaciones listas + selector, vista previa en
  pequeño), subtítulo, pie de catálogo para contacto y subtítulos de sección («＋ Subtítulo» en la foto donde empieza). Las fotos empiezan a bajar apenas se marcan. La galería de la ficha usa la misma
  barra (solo fotos; ya no manda pie de foto ni archivos originales pesados).
- Carpetas: cada foto es una tarjeta en la grilla (ya no se agrupan como miniaturas de variantes), con su código, WhatsApp,
  Descargar y "Seleccionar" para mandar varias (misma barra). Una carpeta que solo tiene subcarpetas (p. ej. una línea con
  14 modelos) muestra debajo las fotos de todas sus subcarpetas (hasta 300) para marcarlas sin entrar a cada una; no en la raíz.
- Compartir por WhatsApp con vista previa (foto, título, enlace) vía Open Graph (`/og/{id}.jpg`).
- "📤 Enviar fotos con lista" (antes «Enviar catálogo»; público, `/enviar-catalogo`): pegar texto (o «📷 Leer lista de una foto»: el texto de la foto se lee
  en el celular con Tesseract y se pone en la caja para revisarlo; decisión del dueño 2026-11-11: lector gratis en el
  celular, no IA de pago; si falla mucho con letra a mano, la opción es IA de imágenes) → códigos. Si un código no existe
  tal cual, se prueba con las confusiones del lector (B↔8, O/D↔0, I/L↔1, S↔5, Z↔2, G↔6) y, si da un solo código, se usa
  y se avisa «🔤 Corregidos: M:B363-3 → M:8363-3» (pedido del dueño 2026-11-11: confundía la B con el 8) → galería de fotos (tocar = marcar/desmarcar).
  "⬇️ Descargar N fotos" (Android/PC: descargas; iPhone: Compartir → Guardar imágenes) y luego se mandan desde
  WhatsApp (📎 → Galería, hasta 100). "📲 Enviar por tandas": las fotos bajan en segundo plano; el botón verde se activa cuando la
  tanda siguiente (10) está completa ("Preparando tanda 2 de 3: 6/10" mientras tanto). 📲 en cada foto = solo esa.
  Si una foto no baja (3 intentos), se desmarca, se avisa cuál y se sigue con las demás. Fotos a 800 px con copia en disco
  (`storage/send_cache`).
  Decisión del dueño 2026-11-09: ofrecer las dos vías; ir foto por foto era lento y el lote de 30+ fallaba.
- "🧾 Extractor" (`/extractor`, en el inicio del computador y en Enviar catálogo): subir un recibo o pedido PDF → código,
  cantidad y precio; «Revisión» (✓ si cuadra con Total, artículos y unidades del PDF, o la lista de lo que no cuadra), datos
  del pedido, Mapa del PDF; mostrar precio y precio con descuento (40 % por defecto), Organizar, «✏️ Editar lista» (código, cantidad, precio, ✕ quitar, ➕ agregar; «↩ Volver a lo del PDF»), Copiar para Excel. «📷 Ver
  fotos en el visor» abre Enviar catálogo con esos códigos (encontrados/no encontrados, PDF, WhatsApp, Mi selección).
  «🧾 Lista pedido» (al lado) abre `/lista-pedido`: PDF del pedido con cliente, fecha, total de ítems, unidades y valor
  total tal como vienen en el PDF, y cada línea con foto, código y cantidad (no editables); sin foto → «Foto no disponible».
- Descarga original (`/api/download`): si el token de Google falta, baja el archivo público de Drive.
  "Drive not connected" = reconectar en Admin → Media (caducó y se reconectó el 2026-11-09).
- Búsqueda: el código exacto primero ("8363-3" = "M:8363-3"), luego los que empiezan por él y el resto; carpetas cuyo
  nombre coincide (también como código), arriba o, si el código existe, justo debajo de su tarjeta. Filtros (2026-11-20) en inicio, búsqueda, carpetas y Novedades: varias marcas, categorías y carpetas a la vez + precio, todo combinable y en la URL; el texto busca también por marca, color y nombre de carpeta; si no encuentra nada,
  "¿Quisiste decir…?" con los códigos y marcas más parecidos.
- Nombre exacto de un archivo de Drive, en todas partes (2026-11-21): si lo pegado no es ningún código pero es el nombre
  de una foto («S-640+S-647-3.jpg»), sale el producto de esa foto **con esa misma foto**: buscador y autocompletar,
  **Catálogo PDF** («🔎 Armar con filtros»), Enviar catálogo y Lista pedido (esa foto va primera), lista de varios
  códigos, la ficha (`/producto/…`), Admin → Productos y «¿Por qué no sale la foto?». Además de las búsquedas de siempre.
- Navegación (2026-11-11): en el celular, botón **←** junto al logo en todas las páginas menos el inicio (vuelve a la
  pantalla anterior; si se llegó por un enlace de fuera, va a la página de arriba). **Migas de pan** «Inicio › Sección ›
  Página» en Mi selección, Catálogo en PDF (la del medio dice de dónde vino la lista), Enviar catálogo y Novedades;
  carpetas y ficha ya las tenían (carpetas ahora con ›).
- Todo el visor y el admin se revisan a 320/360/375 px (celular): sin scroll lateral, letra legible, botones de dedo.
- Celular (etapa 2): barra abajo tipo app (Inicio, Buscar, Carpetas, Enviar); buscador pegado arriba en una fila con ✕
  para borrar y 📷 al lado; carpetas del inicio de 3 en 3; "Seleccionar" es un círculo sobre la foto. El computador no cambia
  (solo gana la ✕ del buscador).
- Admin: Productos (activar/desactivar), Carpetas (ocultar), Sincronizar (hoja + Drive, "¿Por qué no sale la foto?"), Media,
  Problemas (todo lo que impide que salga una foto, con motivo y ruta).
  Sincronizar muestra hoja y Drive por separado (último éxito con qué cambió, último error y "🔁 Reintentar"); solo corre
  una sincronización a la vez.
  Admin → 📦 Inventario: subir el Excel SYS (guarda Precio SYS y existencias en el visor), ver diferencias y bajar STOCK y PRICE para pegar.
  Admin → 💲 Precios: Excel de KYTE → PRICE_SUGGESTED, se aplica en el visor al subirlo. SYS (precio → PRICE) va en Inventario.

## Pendiente / ideas
- **Catálogo en PDF** (`docs/PLAN_PDF.md`, aprobado 2026-11-11): P1 (#52) y P2 (#53) hechas. Probar en Railway con fotos
  reales y compartir a WhatsApp (Android/iPhone). P3 (pegar «código precio») opcional, sin pedir.
- **Búsqueda por foto** (#48-#50): `JINA_API_KEY` puesta en Railway (producción) el 2026-11-10 y la búsqueda ya responde.
  La primera vez prepara ~4400 portadas (ciclo propio cada 5 min, varias horas según el límite de la clave); el avance
  sale en la ventana de resultados y en Admin → Sincronizar. Confirmar que termine y que los parecidos sean buenos.
- Probar en Railway la vista previa de WhatsApp con enlaces nunca compartidos (WhatsApp cachea por URL).
- Ideas técnicas en `docs/RENDIMIENTO.md` → "Pendiente / ideas".
- Plan de mejoras para distribuidores (`docs/PLAN_MEJORAS.md`): etapas A-C hechas, D-I en el PR #42 (el dueño pidió hacerlas seguidas y fusionar al final; respaldo del main en la rama `respaldo-2026-11-10`). J (app instalable) sigue pausada.
  Reemplaza las etapas 5 (app instalable, pausada) y 6 (búsqueda) del plan UX.

## Historial (1 línea por PR)
- 2026-11-24: precio público = Precio SYS × multiplicador (configurable); KYTE solo reporte; precio por defecto en Extractor y Catálogo PDF; mayorista opcional en ficha.
- #1–#7 (oct 2026): carpetas como en Drive (todos los niveles), códigos con prefijo M:/M/M-, búsqueda tolerante,
  hoja maestra como única fuente + activación solo en admin, familias bajo el código -1, visor responsive.
- #8: migración única que activa todos los productos; contador referencias/familias.
- #9: enlace único foto ↔ producto (`DriveIndex::relink`, `matched_sku`).
- #10: sync automático en deploy + cada hora.
- #11: productos "Solo Drive" desde archivos de Drive.
- #12: catálogo en grilla densa. #14: ficha en móvil sin bloque vacío.
- #13: compartir por WhatsApp con vista previa; WhatsApp + Descargar en cada foto.
- #15: módulo público "Enviar catálogo por lista".
- #16: descarga con respaldo público si Drive está desconectado.
- #17: contador de carpetas ocultas igual que la lista (no borra ajustes viejos).
- #18: este archivo de avances + regla de actualizarlo (CLAUDE.md).
- #19: Enviar catálogo: botón "Enviar todo el lote" (fotos marcadas juntas), marcar/desmarcar todos, "📲 Este".
- #20: repaso móvil: barra "Enviar por WhatsApp" ya no tapa la pantalla (y aparece en la galería de la ficha), encabezado sin
  choques, letra de tarjetas más grande, menú del admin completo en 2-3 filas, Enviar catálogo usable en el celular, error JS del inicio.
- #21: Enviar catálogo: botón 📲 por fila (comparte solo esa foto); se quitan los botones verdes de lote y "N de M".
- #22: Enviar catálogo en galería: guardar las marcadas en el celular + enviar por tandas de 20; fotos a 1200 px.
- #23: Enviar catálogo: tandas de 10 fotos por toque (antes 20), pedido del dueño.
- #24: Enviar catálogo: arreglo "Failed to fetch" y tandas sin esperas (comparte lo ya bajado), fotos a 800 px, caché en disco.
- #25: Enviar por tandas: el botón verde aparece solo cuando la siguiente tanda de 10 está lista (pedido del dueño).
- #26: ficha en el celular: la foto ya no sale corrida a la derecha con muchas fotos (la tira de miniaturas estiraba la columna);
  en el celular las miniaturas de la ficha van en cuadrícula (5 por fila, sin deslizar; tocar una sube a la foto grande).
  Enviar catálogo: las miniaturas muestran la foto entera (no recortada).
- #27: Admin → Sincronizar: última sincronización de la hoja con éxito (y último error) guardada en `sync_status`; conteo
  códigos de la hoja vs "Solo Drive", visibles, y filas de la hoja sin precio o sin nombre.
- #28: carpetas "archivado" ocultas por defecto (`isArchivedFolderName`, `DriveIndex::hideArchivedFolders` en cada sync); el
  mensaje de Sincronizar portadas dice cuántas hay y cuántas quedan ocultas.
- #29: la misma regla oculta por defecto las carpetas "DESACTIVADOS" (antes solo no creaban productos "Solo Drive").
- #30: la regla de carpetas archivado/desactivados se aplica al arrancar (paso `carpetas`, solo BD) y al inicio de
  Sincronizar portadas, sin esperar a la hoja ni a Drive (antes solo corría tras recorrer todo Drive).
- #31: compartir por WhatsApp desde el catálogo: marcar varias en inicio/búsqueda y enviar por tandas de 10 (`wa_batch.js`);
  botón WhatsApp de cada foto = imagen liviana + código; Enviar catálogo ya no manda la lista en texto en las tandas.
- #32: carpetas en grilla de fotos (una tarjeta por archivo, ordenadas por nombre) con marcar varias para WhatsApp.
- #33: carpeta con solo subcarpetas: grilla con las fotos de todas sus subcarpetas para marcar y compartir.
- #34: etapa 1 de velocidad: sin bcrypt en cada petición (servidor ~60 ms → 2-28 ms por página), fotos de tarjeta a
  200/400 px según pantalla y prioridad a las primeras, preconexión a Google arreglada, Atrás instantáneo en búsqueda y
  carpetas, JS de las páginas en archivos cacheables (ficha 57 KB → 20 KB). La búsqueda en BD ya tardaba ~2 ms: sin cambios.
- #35: etapa 2, celular más claro: barra inferior tipo app, buscador fijo arriba en una fila (✕ y 📷), encabezado sin
  botones repetidos, carpetas compactas (inicio y búsqueda), "Seleccionar" como círculo sobre la foto.
- #36: etapa 3, envío más rápido: lo marcado sigue al cambiar de página (sessionStorage), la galería de la ficha usa la
  misma barra y fotos livianas (antes bajaba los originales al tocar Enviar, máx. 10), precarga de 6 en 6, la barra de envío
  queda encima de la barra inferior del celular.
- #37: etapa 4, estética: se quitan del CSS 76 reglas muertas (~10 KB), un solo verde WhatsApp y un solo dorado (tokens
  `--color-wa*`, `--color-primary-light`), avisos con la misma paleta, brillo de "cargando" en los recuadros de foto, y en el
  computador Buscar + "📷 Escanear" en una fila. Capturas de 28 páginas (375/1280 px) antes y después para no romper nada.
- #38: `docs/PLAN_MEJORAS.md`: plan de mejoras para distribuidores (qué ya existe, qué quitar, etapas A-J). Solo documento.
- #39: etapa A, sync seguro: un solo sync a la vez (hoja, Drive, cron, webhook; `acquireSyncLock`), Drive guarda último
  éxito/error en `sync_status` con archivos nuevos/quitados y portadas, Admin → Sincronizar los muestra por separado con Reintentar.
- #40: etapa B, búsqueda y filtros: filtros en la BD (marca, categoría, precio, con foto) con chips ✕, "¿Quisiste decir…?"
  para códigos mal escritos; se quitan los chips de categoría client-side (`category-filters.js`).
- #41: etapa C, Mi selección: lo marcado se guarda en el dispositivo (localStorage), pantalla `/mi-seleccion` (orden, quitar,
  1 foto por código, estado por foto, reintentar, repetir última tanda), "Marcar todas (esta vista)", Enviar catálogo → Mi selección.
- #42: etapa D, limpieza: fuera el modal "Buscar lote" (no tenía botón; lo hace Enviar catálogo), `/api/batch-search`,
  `/api/drive-folders` (sin uso, llamaba a Drive en vivo), Admin → Álbumes (sin enlace), el código viejo de precaché (el cron
  `precache-drive` sigue y hace lo mismo que `sync-covers`) y restos de "álbum" en JS (~700 líneas, 317 de CSS). Arreglo: el
  cron de Drive ya no corre sin `?token=` (una sesión sin token pasaba el control).
  Se dejan: `media_search_cache`/`drive_cache` (los usa el camino de respaldo y la visibilidad) y las tablas viejas sin código.
- #42 (etapa E): catálogos guardados en el dispositivo dentro de Mi selección (guardar como, abrir, actualizar, duplicar,
  renombrar, borrar); avisa cuántas fotos de cada catálogo ya no están (`/api/fotos-vigentes`).
- #42 (etapa F): "Cómo salen las fotos" en Mi selección: foto sola o con su código abajo (se arma en el celular), con vista
  previa; vale para Guardar y Enviar por tandas. Precio: no por ahora (decisión del dueño 2026-11-10).
- #42 (etapa G): Admin → 🩺 Problemas: sin foto, foto en carpeta oculta, archivos sin código y datos incompletos de la hoja,
  con motivo, ruta en Drive y CSV; "¿Por qué?" abre el diagnóstico del código en Sincronizar.
- #42 (etapa H): Novedades (`/novedades`, 7/30/90 días) con `drive_index.first_seen_at` (migración 026; re-sincronizar no
  cuenta como nuevo; lo de antes queda sin fecha). Enlace en el inicio si hay fotos nuevas esta semana.
- #42 (etapa I): catálogo por enlace `/c/{código}` (migración 027 `shared_catalogs`): se crea desde Mi selección con nombre y
  contacto opcional; muestra siempre la foto vigente; actualizar/desactivar solo desde el dispositivo que lo creó.
- #43: una tarjeta por referencia (sin tira de variantes) en inicio, búsqueda y autocompletar; búsqueda con el código exacto
  primero (con o sin "M") y sus carpetas debajo.
- #44: fotos que se iban a un código oculto escrito igual al archivo (p. ej. "M8291-1-A.jpg" → producto viejo oculto "M8291-1"
  en vez del visible "M:8291-1"). Ahora en el enlace gana el producto visible. La regla de nombres no cambió.
- #45: en las carpetas, una foto sin código (IMG_…, WhatsApp…) llevaba a `/producto/IMG_…` y salía vacío. Ahora solo enlaza a
  la ficha si la foto tiene producto visible (`drive_index.matched_sku`); si no, abre la foto en grande. Enlaces viejos a un
  archivo sin producto redirigen a su carpeta. La regla de nombres no cambió.
- #46: lo público solo muestra productos con foto o video en PRODUCTOS (los demás quedan en el admin, sin desactivarlos).
- #48: búsqueda por foto. Ícono de cámara dentro de la caja de búsqueda (inicio y búsqueda): tomar o subir una foto muestra los
  30 productos visibles más parecidos. Huellas de las portadas en `image_embeddings`, preparadas por el sync. Requiere `JINA_API_KEY`.
- #49: búsqueda por foto: la ventana avisa "N de M fotos listas" mientras se preparan (sin ninguna lista no gasta tokens), la
  preparación corre en su propio ciclo cada 5 min (no espera la hora) y Admin → Sincronizar muestra la última vuelta y errores de Jina.
- #47: reconectar Google Drive más robusto: si la sesión del admin caducó, tras el login sigue con la vuelta de Google;
  los errores se ven en Admin → Media con la causa; se conserva el refresh_token si Google no manda uno nuevo. «Conectar» avisa si `GOOGLE_REDIRECT_URI`
  apunta a otro dominio (causa real del fallo del 2026-11-10: el sitio es `viewfinder-k.up.railway.app`).
- #50: búsqueda por foto: el 2026-11-11 salía "No se pudo analizar la foto" sin saber por qué. Ahora la ventana dice la causa
  (sin saldo en Jina, clave mala, muchas búsquedas) y `/buscar-foto/estado` muestra el avance y el estado sin entrar al admin.
- #51: `docs/PLAN_PDF.md`: plan del catálogo en PDF con textos del cliente (etapas P1-P3). Solo documento.
- #52: etapa P1 del PDF: `/catalogo-pdf` desde la selección (texto por código recordado en el dispositivo, logo propio, 4/6/9 por página, Compartir/Descargar; jsPDF 4.2.1 en `assets/vendor`).
- #53: etapa P2 del PDF: botón en el enlace `/c/…`, en Enviar catálogo (las marcadas) y en cada catálogo guardado; la lista va a `/catalogo-pdf?desde=lista` sin cambiar Mi selección. Además, diseño del PDF: franja de color con logo/título/subtítulo, colores primario y secundario, subtítulos de sección y pie de contacto (el campo «Contacto» pasó al pie).
- #54: navegación: botón ← Atrás en el encabezado del celular y migas de pan (`breadcrumbs()`) en Mi selección, Catálogo en PDF, Enviar catálogo y Novedades.
- #55: Enviar catálogo: «📷 Leer lista de una foto» (Tesseract.js 6 en `assets/vendor/tesseract`, en el celular, sin CDN ni servidor; ~6 MB la primera vez).
- #56: Enviar catálogo: un código que no existe tal cual se corrige con las confusiones típicas del lector de fotos (B↔8, O↔0, I↔1, S↔5…) si da un solo código; se muestra qué se corrigió.
- #57: PDF: botón «Abrir foto» (activado por defecto) bajo cada foto con enlace oculto a la foto en Google Drive.
- #58: «🧾 Extractor» de pedidos PDF en `/extractor` (pdf.js en el dispositivo, port de la app Flask) y
  «📷 Ver fotos en el visor» → Enviar catálogo. Probado con 5 recibos reales: total, artículos y unidades cuadran.
- Rama `claude/project-thread-joh3t8`: el Extractor también lee la cotización en tabla (COTIZACION No …), detectada sola;
  probado con la 00106511 (CURREN COLOMBIA): 195 líneas, 310 unidades, $15,174,000 cuadra con el TOTAL. Además, en Lista
  pedido el dueño elige con casillas qué datos salen en el PDF (n.º, cliente, fecha, ítems, unidades, valor, recuadro
  «Revisar»; se recuerda en el dispositivo) y si usar lo contado en las líneas cuando el PDF no trae ítems/unidades
  (sale «195 (contado)»). También elige las columnas de la tabla: N.º, Foto, Código, Producto, Cantidad, Precio, Total
  (Producto/Precio/Total solo si el PDF los trae). Si caben en media hoja salen dos tablas lado a lado; si no, una a todo
  el ancho. Sin la columna Foto las filas son bajas (~23 por página).
- Rama `seguridad-y-fotos-jpeg` (auditoría 2026-11-12): `/api/download` solo baja archivos del índice no ocultos (antes, cualquier
  archivo del Drive del dueño); login del admin con límite (10 fallos/15 min por IP) y cookie HttpOnly/Secure/SameSite;
  fotos grandes (≥ 600 px) pedidas en JPEG a lh3 (`-rj`): un PNG de 826 KB pasa a 147 KB.
- Rama `limpieza-codigo-viejo` (auditoría 2026-11-12): fuera Composer y phpspreadsheet (nada los usaba), extensión zip y la
  caché de ZIP del admin, `plantilla_catalogo.csv`, `desktop.ini`, `scripts/populate_parents.php` y las notas viejas.
- Rama `claude/project-thread-wdaaf8`: el dueño no encontraba cómo bajar las fotos: «💾 Guardar» pasa a «⬇️ Descargar» (destacado)
  en la barra de selección y en Enviar catálogo; «⬇️ Descargar todas (N)» arriba en Mi selección; en el celular la barra de
  Enviar catálogo ya no queda tapada por el menú de abajo. Usa `/enviar-catalogo/foto` (no `/api/download`).
- Rama `lista-pedido`: «🧾 Lista pedido» en el Extractor → `/lista-pedido` (PDF de pedido: encabezado con los datos del PDF,
  tabla con bordes foto | código | cantidad, 13 por página, como la proforma que mandó el dueño). Piezas comunes de PDF movidas a `assets/js/pdf_shared.js` (el Catálogo en PDF las usa
  igual). Probado con 5 recibos reales (#c-5845, #c-5856, #c-5865, #c-5867, #c-5868) contra las fotos de producción.
- Rama `lista-pedido-2-columnas`: Lista pedido en dos tablas lado a lado (~26 referencias por página; el pedido de 184 líneas
  pasa de 15 a 8 páginas), pedido del dueño para ahorrar páginas.
- Rama `claude/project-thread-joh3t8` (2.ª parte): Remisión (factura en tabla, azul) separada de Lista pedido (recibo, negro y
  dorado); NIT, dirección, ciudad, teléfono y vendedor leídos de la factura. Probado con la 00106511 y el recibo #c-5872.
- Rama `claude/project-thread-4us9xl`: un código con foto fuera de la hoja (fila vieja oculta, p. ej. M8324-1) vuelve a salir
  como «Solo Drive» en la búsqueda; el punto final del nombre ya no crea duplicados («M8324-4.», «M8324-4-A.»).
- Rama `claude/project-thread-shhu3r`: interruptor «Como carpeta» en Admin → Carpetas (saca la carpeta de inicio y de su carpeta
  superior sin ocultar su contenido ni quitarla de la búsqueda). Migración 029.
- Rama `claude/project-thread-joh3t8` (3.ª parte, 2026-11-13): «Lista pedido» → «Lista Bodega», total de la Remisión también al
  final y botón «👁 Previo» (pdf.js pinta el PDF en la página). Probado con la 00106511 y el recibo #c-5872.
- Rama `claude/project-thread-0x6ypn` (2026-11-13): el botón «📤 Enviar catálogo» pasa a «📤 Enviar fotos con lista» (también título,
  migas y enlaces de esa página; la ruta `/enviar-catalogo` no cambia).
- Rama `claude/project-thread-joh3t8` (4.ª parte, 2026-11-13): en Lista Bodega y Remisión, cada foto del PDF es un enlace a la
  foto en Drive (`drive.google.com/file/d/{id}/view`), igual que «Abrir foto» del Catálogo en PDF.
- Rama `claude/project-thread-fyz6a4` (2026-11-13): se quita el botón «📷 Escanear» (QR) del buscador del inicio. Solo se abría
  desde ahí, así que también se borra `assets/js/qr-scanner.js` y sus estilos. La 📷 de búsqueda por foto sigue.
- Misma rama (2026-11-13): las carpetas se muestran sin su número de orden («2.CURREN» → «CURREN») en el inicio, la búsqueda,
  la vista de carpeta y las migas, con `folderDisplayName()`. El orden sigue igual; el admin muestra el nombre completo.
- Rama `claude/project-thread-453d9a` (2026-11-13): Admin → 📦 Inventario (`/admin/inventario`): sube el Excel/CSV de existencias,
  lo cruza con la hoja y da la columna STOCK para pegar + reporte (duplicados, negativos, faltantes, precio distinto).
- Misma rama (2026-11-13): las descargas de Inventario salen en Excel real (.xlsx, `XlsxWriter.php`, sin librerías) porque el CSV
  se abría todo en la columna A; el reporte trae la pestaña «Léeme» (qué significa y qué hacer) y una pestaña por tipo, con títulos claros.
  Textos con los nombres del dueño: «Hoja master» y «SYS» (pantalla, Excel y nombres de archivo).
- Rama `claude/project-thread-705w0p` (2026-11-13): tarjetas del Dashboard clicables → listado filtrado; filtros nuevos
  `estado=conportada|sinportada|hoy` y `genero` en `adminProductFilter()`.
- Misma rama (2026-11-13): arreglo del Dashboard descuadrado tras #74 (una tarjeta quedó sin cerrar y los enlaces corridos).
- Rama `claude/project-thread-629dae` (2026-11-14): precio en tarjetas y ficha («Sin precio en tabla»), precio editable en
  Admin → Productos (migración 030: `price_sheet`, `price_manual`), tarjetas del Dashboard.
- Rama `claude/project-thread-sf5b5e` (2026-11-14): Admin → 💲 Precios (`/admin/precios`, `PriceImport.php`); el sync quita
  `price_manual` cuando la Hoja master ya trae el mismo precio. Primer archivo: «Productos 19.xlsx» de KYTE.
- Misma rama (2026-11-14): descarga «Columna nueva PRECIO KYTE» (precio tal cual de KYTE por fila de la Hoja master).
- Misma rama (2026-11-14): el visor muestra PRECIO KYTE (`MasterSheet::parse`, `price_column`); Admin → Precios compara contra esa columna.
- Misma rama (2026-11-14): rastreo de precios: todo lo público lee `price_suggested` (PRECIO KYTE o editado en admin); catálogo PDF (lo escribe el usuario) y Extractor / Lista Bodega / Remisión (precio del PDF del pedido) no usan el precio del visor, a propósito. Solo se corrigieron comentarios.
- Misma rama (2026-11-14): respaldo de precio por familia y PRICE_SUGGESTED (`MasterSheet::fillMissingPrices`); precios de KYTE para «Solo Drive».
- Rama `claude/project-thread-rd9wya` (2026-11-14): Remisión con plantilla editable (`/admin/remision`, `RemisionTemplate.php`,
  tabla `doc_templates`, migración 031) y diseño negro/rojo con logos según el modelo del dueño.
- Rama `claude/project-thread-tvhp25` (2026-11-20): fotos más grandes (tarjetas 190 px mínimo en PC; Admin → Productos 96 px) y filtros inteligentes en todas las pantallas públicas (`filter_bar.php`: varias marcas/categorías/carpetas, precio, texto por marca/color/carpeta) y en Admin → Productos (carpeta, precio, texto por palabras).
- Misma rama (2026-11-20): Catálogo PDF con fotos más grandes (opción 1 o 2 fotos por página, lista en pantalla 120 px) y catálogos filtrados: «🔎 Armar con filtros» en el generador y «📄 Catálogo PDF con esto» en la barra de filtros (`/catalogo-pdf/lista`).
- Rama `claude/project-thread-tvhp25` (2026-11-20, tras #82): filtros con «Ordenar» (código A→Z, precio mayor→menor y menor→mayor) y «Contiene la palabra» (nombre del producto o del archivo en Drive) en todas las pantallas, catálogo PDF y Admin → Productos.
- Rama `claude/project-thread-tvhp25` (2026-11-20, tras #83): búsqueda y filtros lentos (30+ s, la app se colgaba) → índice de búsqueda guardado por producto (migración 032, `SearchIndex`), rehecho en cada sincronización; ahora ~0,04 s.
- Misma rama (2026-11-20, tras #84): el deploy de #84 no pasó el healthcheck (la primera visita armaba el índice y el ALTER podía esperar sin fin a las consultas lentas del contenedor viejo). Ahora el índice lo arma solo el sync en segundo plano, las páginas nunca; migraciones con tope de espera de 20 s y reintento en el sync.
- Rama `claude/project-thread-tvhp25` (2026-11-20, tras #85): «Ordenar» separado en dos que se combinan: Precio (mayor/menor) y Código (A→Z / Z→A); con ambos, primero precio y a igual precio por código.
- Rama `claude/project-thread-24a45y` (2026-11-20, tras #86): optimización sin cambios visibles: inicio con 7 consultas en vez de 31 (portadas de carpetas en una sola, página cortada en MySQL), opciones de filtros guardadas 2 min, búsqueda por foto sin saldo reintenta cada hora (no cada 5 min), `.dockerignore` (sin `.git` ni docs en el contenedor), 4 funciones sin uso quitadas.
- Rama `claude/project-thread-xsk99w` (2026-11-20): Admin → 📋 Hoja master (`/admin/hoja`): previo de la tabla de la hoja (todas las columnas, qué campo del visor llena cada una, buscador) junto al nombre actual en el visor. El nombre sale de NAME (vacía o Solo Drive → el código).
- Rama `claude/project-thread-2f2p99` (2026-11-20): precios simplificados: KYTE → PRICE_SUGGESTED (público, se aplica al subir), SYS → PRICE (guardado, no público) + STOCK; fuera «PRECIO KYTE». Migración 033 (`sys_price`, `sys_stock`, `sys_at`).
- Misma rama (2026-11-20, tras #89): subir KYTE/SYS también escribe sus columnas en la Hoja master (`src/services/SheetWriter.php`, API de Sheets con la conexión de Google del admin).
- Rama `claude/project-thread-s1i6yo` (2026-11-20): los PDF (Catálogo, Lista Bodega, Remisión) se pueden renombrar antes de Descargar/Compartir: casilla «📝 Nombre del archivo» con el nombre sugerido; si se escribe uno propio se mantiene al rehacer el PDF (`KinoPdf.setName/named`).
- Rama `claude/project-thread-slbho8` (2026-11-20): celular más rápido de usar: filtros plegados, «⭐ Selección» con contador en la barra de abajo, ficha sin botón repetido, «Cargar más» (60 en 60) en carpetas y Novedades; migas de pan como botones dorados y botón «← Atrás» con texto.
- Rama `claude/proyecto-ce9a49` (2026-11-20): filtro «Carpetas» solo con 2 niveles (fuera las subcarpetas por referencia como 427 › 427-1); `SearchIndex::rebuild()`.
- Misma rama (2026-11-20, tras #93): el filtro «Carpetas» queda con 1 solo nivel (decisión del dueño).
- Rama `claude/project-thread-n7lzzo` (2026-11-20): «Drive no respondió al listar carpetas»: el listado del sync reintenta
  (2/4/8 s, 60 s de espera, renueva el token si vence a mitad), parte la consulta en 4 si sigue fallando y el error muestra el motivo (HTTP/Google).
- Rama `claude/project-thread-7o7t14` (2026-11-20): stock al lado del precio (verde con cantidad, rojo en 0) en inicio, búsqueda, carpetas, Novedades y ficha; sin consultas extra. Filtro Con stock / Sin stock / Todos.
- Misma rama (2026-11-20, tras #96): el stock público ya no muestra la cantidad, solo «In Stock» (verde) / «Out stock» (rojo); el filtro sigue igual.
- Rama `claude/search-file-s640-s647-5c99af` (2026-11-21): el buscador encuentra una foto por su nombre completo de archivo
  («S-640+S-647-3.jpg», dos códigos unidos con «+», que no es ningún código): `skusByFileName()` busca el archivo en
  `drive_index` y muestra el producto al que está enlazado, con esa foto en la tarjeta. Vale en `/buscar`, en el
  autocomplete y en una lista de varios códigos.
- Misma rama y PR #98 (2026-11-21): búsqueda por nombre exacto de archivo en todas partes, con la foto exacta
  (`photosByFileName()`): Catálogo PDF, Enviar catálogo, Lista pedido, lista de códigos, ficha, Admin → Productos y
  «¿Por qué no sale la foto?». Gana el nombre idéntico sobre «nombre + sufijo».
- Rama `claude/ficha-galeria-nombre-archivo` (2026-11-21, tras #98, probado en producción): la ficha abierta por nombre
  de archivo (`/producto/S-640+S-647-3.jpg`) salía con la galería vacía (pedía `/api/media/S-640+S-647-3`); ahora la pide
  con el código del producto (S-640). Todo lo demás de #98 probado en producción y bien.
- Rama `claude/project-thread-7pcp5a` (2026-11-21): Admin → 🚫 Condenados (migración 034 `condemned_codes`, empieza con M:8325-1): la hoja, «Solo Drive», el enlace de fotos y la búsqueda de carpetas ignoran esos códigos y el producto se borra.
- Rama `claude/project-thread-8eqa8h` (2026-11-21): CLAUDE.md con encabezado estándar, comandos locales, orden del sync y reglas de migraciones/`env()`.
- Rama `claude/project-thread-9skz3c` (2026-11-21): plan del módulo «📦 Pedido de bodega» (`docs/PLAN_PEDIDO_BODEGA.md`: panel con checklist desde Lista Bodega/Remisión, «Chequeado por», cambiar producto/cantidad con total final recalculado, PDF «Pedido finalizado» con nombre editable; etapas B1-B3). Aprobado por el dueño; B1+B2 construidas: `/pedido-bodega`, botón en Lista Bodega, PDF «Pedido finalizado».
- Rama `claude/project-thread-fgxt9p` (2026-11-21, etapa B3 hecha): «📋 Pedidos a alistar» (`/pedidos-alistar`, migración 035 `warehouse_orders`). Lista Bodega → «📦 Enviar a pedidos a alistar» guarda el pedido en el servidor (no duplica el mismo). La lista muestra en rojo los pendientes (abren el checklist) y en verde los listos (abren para verificar y descargar el PDF con nombre editable). El avance del checklist se guarda en el servidor (otro celular lo continúa). Botón «📋 Pedidos a alistar» arriba en Extractor, Lista Bodega y el panel. Sin clave y sin borrar pedidos (por ahora). También: en celular la barra «Finalizar» ya no queda tapada por la barra inferior. Probado en local con MariaDB; confirmar en Railway.
- Rama `claude/project-thread-m63q6h` (2026-11-21): pedido verde/finalizado ya no genera el PDF por defecto: muestra «📋 Listado para copiar» (código, cantidad final, UNIDAD; tabulado como el Extractor) con botón «Copiar»; datos del pedido quedan arriba, fuera del listado. Sin botón de PDF (el dueño lo quitó).
- Rama `claude/project-thread-mpfw2i` (2026-11-23): en Lista Bodega/Remisión el **Cliente** se puede escribir a mano (si no se leyó del PDF o para corregirlo; vacío = vuelve al del PDF). Sale en los PDF, quita el aviso «Revisar» y se guarda en `kinoPedido` (lo usa «Enviar a pedidos a alistar»).
- Rama `claude/project-thread-8tw9h9` (2026-11-23): «📋 Pedidos a alistar» se llama ahora **«📋 Pedidos bodega»** (botón del encabezado, título, migas, «📦 Enviar a pedidos bodega»). Rutas `/pedidos-alistar` sin cambio.
- Rama `claude/project-thread-k7zn85` (2026-11-23): en «📋 Pedidos bodega» cada pedido tiene «🗑 Borrar»: pide confirmación y contraseña (3312, se cambia con la variable `PEDIDOS_DELETE_PASSWORD` en Railway); el servidor valida la clave.
- Rama `claude/project-thread-sj0lfb` (2026-11-23): en el **Extractor** («Datos del pedido») el **Cliente** también es una casilla editable (vacía = vuelve al del PDF; muestra «Del PDF: …»). El nombre corregido pasa a Lista Bodega/Remisión (y de ahí a los PDF y a Pedidos bodega); `kinoPedido.order.clientPdf` guarda lo leído para que allá siga saliendo «Del PDF».
- Rama `claude/project-thread-diucix` (2026-11-23): en «📦 Pedido de bodega», «🔄 Cambiar producto» y «➕ Agregar un producto» buscan **con fotos**: escribe código, nombre o carpeta y toca la foto (misma búsqueda del visor, `/api/search?limit=30`). Escribir el código y «Cambiar»/«Agregar» sigue funcionando.
- Rama `claude/project-thread-ur7req` (2026-11-23): en el **Extractor**, al adjuntar el PDF el cuadro se pone **verde** con 📁 y «✅ nombre.pdf adjunto»; «🔄 Limpiar» aparece al adjuntar y lo devuelve a normal.
- Rama `claude/project-thread-v3pzof` (2026-11-23): **Pedido de bodega** leía «$130,000.00» como 13.000.000 (tomaba «.00» como miles). Ahora el último «.xx»/«,xx» es decimal, el precio es por unidad (si el recibo trae total de línea se divide por la cantidad, deducido del Total del PDF) y los pedidos ya guardados se corrigen solos al abrirlos (solo si el precio es exactamente el mal leído; uno escrito a mano se respeta).
- Rama `claude/project-thread-u5d8a5` (2026-11-23): **Admin → 🗄 Pedidos**: descarga de todos los Pedidos bodega en Excel (pestañas Pedidos y Líneas) o JSON, botón «☁️ Copiar a Drive ahora» y copia automática diaria en Drive (`cli_sync.php pedidos`, carpeta «Respaldos pedidos bodega (visor)» en Mi unidad o `PEDIDOS_BACKUP_FOLDER_ID`; hora `PEDIDOS_BACKUP_HOUR`, 22).
- Rama `claude/project-thread-3jhsv8` (2026-11-23): **Pedido de bodega**: el precio unitario ya no se puede editar (texto «$X c/u»); el servidor lo bloquea también y recalcula el total final.
- Rama `claude/project-thread-lq8fs2` (2026-11-23): `docs/GUIA_VISOR.md`, guía simple del visor para personas no técnicas.
- Rama `claude/project-thread-0h596l` (2026-11-23): **Admin → 🧾 Registro** (errores PHP/JS y búsquedas sin resultado, migración 036) + **respaldo diario de toda la base MySQL** a Drive (`DbBackup`, `cli_sync.php respaldobd`, botón en Admin → Pedidos) + `scripts/restore_backup.php` y `docs/RESPALDOS.md`.
- Rama `claude/project-thread-w0pt9v` (2026-11-23): **Pedido de bodega** listo muestra «📝 Cambios en el alistado» vs. el pedido original (+ Copiar resumen para WhatsApp y «⬆ Volver al pedido»), calculado en el navegador con lo ya guardado; sin migración.
- Rama `claude/project-thread-xxn4tz` (2026-11-23): **Dashboard admin**: cada tarjeta tiene «⬇ Excel» (y la lista de Productos «⬇ Descargar Excel») con toda la lista del filtro: código, nombre, marca, categoría, color, carpeta, precio, stock, estado, origen, portada, motivo. Los números de las tarjetas salen del mismo filtro que la lista y el Excel.
- Rama `claude/project-thread-uvz4h6` (2026-11-23): **Códigos con «+»** («S-640+S-647-1…7») enlazan a su propia foto y crean su producto «Solo Drive», ya no se pegan al padre S-640 (`isSkuBoundary()`); prueba `php tests/sku_match_test.php`.
- Rama `claude/project-thread-noicpm` (2026-11-23): el documento **«Remisión» pasa a llamarse «Cotización»** en todo lo visible (PDF, total, archivo, botones, admin); rutas y tablas sin cambio.
- Rama `claude/project-thread-9rpcuq` (2026-11-23): **Extractor** lee códigos con «+» (`S-640+S-647`, estuche pareja); antes esa línea se perdía y el total no cuadraba. Prueba `node tests/extractor_test.mjs` con el texto de la cotización 106776 (cliente anonimizado).
- Rama `claude/project-thread-iu5rfh` (2026-11-23): **Extractor**: «✏️ Editar lista» en 📊 Resultado deja corregir código, cantidad y precio, quitar (✕) o agregar (➕) líneas; «↩ Volver a lo del PDF» deshace. Lo editado es lo que pasa a Copiar, Ver fotos, Lista Bodega / Cotización y Pedido de bodega (sin los avisos de lectura del PDF).
- Rama `claude/project-thread-ikuw7k` (2026-11-24): **Pedidos bodega → «🧾 Marcar facturado»** en los verdes: pasan a naranja 🟠 (pestaña Facturados, deshacer con «↩ Quitar facturado»); migración 037 `invoiced_at`; el panel del pedido también lo muestra.
- Rama `claude/project-thread-fl3zqs` (2026-11-24): **📦 Inventario**: el cruce al guardar usa el código exacto primero (NO610-1 ≠ NO:610-1, ya no se suman); códigos SYS con stock > 0 que faltan en la Hoja master salen en un aviso con botón «➕ Crear N en la Hoja master» (`SheetWriter::appendProducts`, solo agrega filas al final con SKU, NAME, STOCK, PRICE; omite condenados y los que ya existen; luego Sincronizar → hoja). Nada se crea sin pulsar el botón. Al crear cruza familia por código (`InventoryImport::familyKey`, raíz sin último `-N`): si un hermano ya está en la Hoja master copia CATEGORY, BRAND, GENDER, MOVEMENT y materiales; si no, toma CATEGORY (= marca, carpeta de primer nivel de Drive) y GENDER (carpeta DAMA/HOMBRE/…) de la carpeta de Drive de su foto y BRAND más común de esa categoría; si tampoco, queda vacío y se avisa cuáles.
- Rama `claude/project-thread-65k569` (2026-11-25): **Extractor** (recibo): si la descripción se parte en dos líneas y la cantidad «1x» cae en la segunda («1967G-4 (C18) … PLA-» / «1x TA/NEGRO …»), se une al código de arriba; antes se perdía el artículo (recibo c-5921: 164/165). Prueba en `node tests/extractor_test.mjs`.
- Rama `claude/project-thread-vy1aso` (2026-11-25): **Pulido de colores e interfaz (skill impeccable)**: gris tenue `--color-text-dim` #555570 → #80809a (contraste 2.6 → 4.8), placeholder de casillas blancas legible, ~35 colores sueltos de `style.css` pasan a variables (`--color-white`, `--color-black`, `--color-success-strong`, `--color-danger-strong`, `--color-text-on-light`…), foco dorado `:focus-visible`, casillas 16 px también en tablet (641–768 px) y menos animación con `prefers-reduced-motion`. Además paleta estilo macOS (grafito neutro sin tinte azul, sombras más suaves) también en los estilos en línea de plantillas.
- Rama `claude/project-thread-nzi856` (2026-11-25): **Contraste claro/oscuro**: texto sin fondo de su tono (logo VF, inputs blancos, WhatsApp 2:1, dorado 4:1), hex fijos → variables, resaltados y iconos de color con acento naranja (ver «Pulido de interfaz»).
- Rama `claude/project-thread-11kid2` (2026-11-25): **Catálogo PDF con precio opcional y total**: sección «🧮 Precios y total» (casilla «Con precio», total en vivo, aviso de códigos sin precio, «Poner el total al final del PDF», ajustar todos en %, «↩ Volver a precios del visor»); cada foto tiene su casilla Precio (empieza con el del visor, `localStorage kinoPdfPrices`, vale para todas las fotos del código) separada del texto libre; un texto viejo que era solo un precio pasa a esa casilla.

- Rama `claude/project-thread-6xxmlq` (2026-11-25): **Extractor: «📝 Pega un listado»** además de subir PDF: una línea por referencia «código cantidad precio» (Excel con tabs, «5x CÓDIGO», comas; sin cantidad = 1; precio opcional → si falta se trae el del visor = SYS × multiplicador); elige «Lista Bodega» o «Cotización/Remisión» y alimenta Editar lista, Copiar, fotos, Lista Bodega/Remisión y Pedido bodega. «Mostrar precio» apagado = el documento sale sin precios. Total/fecha/unidades se arman del listado. Arreglo: `hidden` de Volver/Agregar línea/Limpiar no se respetaba. `parsePastedList()` en `extractor_core.js` (con prueba).
- Rama `claude/project-thread-fapjry` (2026-11-25): **Precio del visor opcional al editar**: Extractor (💲 toda la lista / por línea, reversible) y Catálogo PDF (casilla «Usar el precio del visor»).
- Rama `claude/project-thread-snv6so` (2026-11-26): **Catálogo PDF: «👁 Previo»** del PDF armado con pdf.js (se abre solo al generar; botón «👁 Previo» para reabrirlo, con Descargar/Compartir y nombre editable). Reglas `.ol-pv*` movidas a `style.css` (las usa también Lista Bodega/Cotización). Lista Bodega/Cotización ya funcionaban (probado en producción).
- Catálogo PDF: nombre del producto encima del código (hasta 2 líneas con «…», altura reservada para no encimarse con foto/precio; casilla «Nombre del producto encima del código», activada por defecto; `/api/nombres`).
- Catálogo PDF (mismo PR): el total pasa a una **página final «Resumen»** (total grande, N referencias · N fotos, tabla por marcas con cantidad y subtotal; marca de `/api/nombres`, que ahora devuelve `{n,m}`). Casilla «Página final con total, cantidades y resumen por marcas».
- Rama `claude/project-thread-bs9ork` (2026-11-25): **Lista Bodega y Cotización/Remisión: nombre del producto (NAME de la Hoja master) encima del código** en el PDF, el «👁 Previo» y la lista en pantalla; hasta 2 líneas con «…» (1 sin foto), centrado sin encimarse. Casilla «Nombre del producto encima del código» (activada, se recuerda por tipo). Usa `/api/nombres` con el código del visor; sin nombre sale solo el código.
- Rama `claude/project-thread-pmcu5s` (2026-11-26): **Notas más largas**. Pedido de bodega: la nota es un cuadro de varias líneas (hasta 1000 letras, el servidor también la corta en 1000) y en el PDF «Pedido finalizado» sale completa en una franja bajo el producto. Cotización: «📝 Notas de la cotización» (general, hasta 2000) salen completas al final del PDF bajo el total; y nota por producto en Extractor → ✏ Editar lista (hasta 1000) que sale completa bajo su fila en Cotización y Lista Bodega. No pasa al Pedido de bodega.
- Rama `claude/project-thread-k6rzxj` (2026-11-26): **Catálogo PDF: «👁 Previo» destacado** en naranja intenso (`--color-orange`, texto oscuro en modo oscuro / blanco en claro), un poco más ancho y con halo suave; Lista Bodega/Cotización ya lo tenían llamativo.
- Rama `claude/project-thread-4bv0fr` (2026-11-26): **Catálogo PDF: «🔎 Armar con filtros» plegado** (`<details>`) con la nota «Para armar el catálogo con otros productos»; se abre al tocarlo, y solo viene abierto con `?desde=filtro` o si no hay fotos marcadas.
- 2026-11-26: rama `v2` creada y `docs/V2_PLAN.md` (plan por módulos, pendiente de OK).
- 2026-11-26 (v2): módulo 1 Usuarios y permisos (Admin → 👥 Usuarios, tabla `users`, roles, `/panel`). Sin probar con MySQL real: probar en el servicio v2.
- 2026-11-26 (v2, pausa): hechos #138 (plan) y #139 (módulo 1); módulo 2 sin empezar; pendiente del dueño: servicio Railway v2, cuentas de prueba, reglas del módulo 3. Detalle y próximos pasos en `docs/V2_PLAN.md`.
- Rama `claude/project-thread-3w1ojl` (2026-11-26): **Columnas con descuento** en Lista Bodega/Cotización (Precio/Total -X%, valor y recuadro con descuento, % editable) y botones Lista Bodega / Cotización / Fotos en el Extractor.
- 2026-11-26 (v2): main traído a v2 (#143); módulo 2 Productos estilo KYTE en `/admin/productos` (controller `admin_productos.php`, template `productos.php`). Sin probar con MySQL real.
- 2026-11-26 (v2): `/admin/restaurar` (solo con `ALLOW_DB_RESTORE=1`) baja el respaldo `.sql.gz` de Drive y lo restaura en la base de v2 (conserva `google_tokens` y `users`). Servicio v2: sistemakino.up.railway.app, MySQL propia, `AUTO_SYNC=0`.
