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
- **Búsqueda por foto con IA de imágenes** (2026-11-10): se eligió comparar "huellas" (jina-clip-v2, servicio de pago por uso,
  centavos) sobre la huella gratis (solo reconoce la misma foto) y sobre describir la foto con IA (lenta y cara).
- **Foto ↔ producto**: el nombre del archivo es el código (o código + separador + lo que sea). "M:", "M" y "M-" son el mismo
  código. El nombre de la carpeta no cuenta. Lo que no coincide se lista para renombrar (Admin → Media, CSV).
- **Un solo flujo de enlace**: `DriveIndex::relink()` → `drive_index.matched_sku`. Portada: propia > familia > video. Todo lo
  público (galería, tarjetas, ficha, envíos) lee ese enlace. Nada de Drive en vivo al navegar.
- **Productos "Solo Drive"**: una foto cuyo nombre es un código que no está en la hoja crea el producto (nombre = código,
  marca = carpeta, sin precio). Excluidos: `IMG_`/WhatsApp, `.psd`, nombres sin dígitos, carpetas DESACTIVADOS.
  `DRIVE_PRODUCTS=0` los apaga.
- **Activación**: solo el switch del admin (`is_active`). La hoja nunca reactiva. No se borra nada, solo se desactiva.
- **Carpetas**: se navegan como en Drive (ya no se muestran "álbumes"). Cada carpeta tiene switch mostrar/ocultar y portada al azar.
- **Carpetas "archivado" y "desactivados"** (2026-11-09): toda carpeta cuyo nombre diga archivado(s)/desactivado(s) (sin importar
  mayúsculas ni tildes) queda oculta por defecto con todo su contenido, ya indexada o nueva. Si el admin la muestra, el
  sync no la vuelve a ocultar. Mientras está oculta tampoco crea productos "Solo Drive".
- **Sync automático** en cada deploy y cada hora (`scripts/sync_loop.sh`); `AUTO_SYNC=0` lo apaga.
- **WhatsApp = imágenes** (2026-11-09): los envíos mandan solo fotos, nunca la lista en texto. Una foto sola lleva su código
  como pie; en tandas no va texto (WhatsApp no deja un pie distinto por foto).
- **Una tarjeta por referencia** (2026-11-10): inicio, búsqueda y autocompletar muestran cada código por separado, sin la
  tira de variantes; las variantes se ven solo al abrir la ficha. Al buscar un código, el exacto sale primero marcado
  "✓ Código exacto" (con o sin "M"), y debajo las carpetas que coinciden.
- **Sin foto no se muestra** (2026-11-10): en lo público (inicio, búsqueda, sugerencias, ficha, variantes, Enviar catálogo,
  catálogo por enlace) solo salen productos con portada (foto o video de PRODUCTOS). No se desactivan: siguen en el admin y
  en 🩺 Problemas → sin foto, y aparecen solos cuando llega su foto. SQL: `archived = 0 AND cover_image_url > ''`.
- **Fotos con código, sin precio** (2026-11-10): el material que se arma lleva el código; el precio no se pone por ahora.
- **Catálogo en PDF** (2026-11-11): foto + código + texto opcional que escribe cada usuario (precio u otra cosa; vacío = no
  sale). Sin logo de Kino: el usuario puede subir el suyo. Entran todas las fotos marcadas. Se arma en el dispositivo
  (precios, textos y logo no van al servidor). Botón al tener la selección lista para enviar y al abrir un enlace `/c/…`.
  Diseño sobrio y elegante con colores primario y secundario elegibles, subtítulo, secciones y pie de contacto (pedido 2026-11-11).
- **Envío automático a un número** por WhatsApp no es posible desde la web: requeriría la API oficial de Meta (de pago).

## Funciones clave para el usuario
- Catálogo público en grilla densa (60 por página, **una tarjeta por referencia**; contador "N referencias").
- Ficha de producto con variantes, galería, visor grande; WhatsApp + Descargar en cada foto.
- Botón WhatsApp de cada foto: manda solo la imagen liviana (800 px) con el código como pie, sin enlace. En el computador
  copia la foto para pegarla en WhatsApp Web (Ctrl+V).
- Inicio y resultados de búsqueda/filtro: "Seleccionar" en cada tarjeta → barra abajo con "Marcar todas" (lo visible con el
  filtro), "💾 Guardar" y "📲 Enviar" por tandas de 10 (mismo sistema que Enviar catálogo, solo imágenes). Lo marcado se
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
- "📤 Enviar catálogo" (público, `/enviar-catalogo`): pegar texto (o «📷 Leer lista de una foto»: el texto de la foto se lee
  en el celular con Tesseract y se pone en la caja para revisarlo; decisión del dueño 2026-11-11: lector gratis en el
  celular, no IA de pago; si falla mucho con letra a mano, la opción es IA de imágenes) → códigos. Si un código no existe
  tal cual, se prueba con las confusiones del lector (B↔8, O/D↔0, I/L↔1, S↔5, Z↔2, G↔6) y, si da un solo código, se usa
  y se avisa «🔤 Corregidos: M:B363-3 → M:8363-3» (pedido del dueño 2026-11-11: confundía la B con el 8) → galería de fotos (tocar = marcar/desmarcar).
  "💾 Guardar N en el celular" (Android/PC: descargas; iPhone: Compartir → Guardar imágenes) y luego se mandan desde
  WhatsApp (📎 → Galería, hasta 100). "📲 Enviar por tandas": las fotos bajan en segundo plano; el botón verde se activa cuando la
  tanda siguiente (10) está completa ("Preparando tanda 2 de 3: 6/10" mientras tanto). 📲 en cada foto = solo esa.
  Si una foto no baja (3 intentos), se desmarca, se avisa cuál y se sigue con las demás. Fotos a 800 px con copia en disco
  (`storage/send_cache`).
  Decisión del dueño 2026-11-09: ofrecer las dos vías; ir foto por foto era lento y el lote de 30+ fallaba.
- Descarga original (`/api/download`): si el token de Google falta, baja el archivo público de Drive.
  "Drive not connected" = reconectar en Admin → Media (caducó y se reconectó el 2026-11-09).
- Búsqueda: el código exacto primero ("8363-3" = "M:8363-3"), luego los que empiezan por él y el resto; carpetas cuyo
  nombre coincide (también como código), arriba o, si el código existe, justo debajo de su tarjeta. Filtros por marca, categoría, precio y "solo con foto" (combinables, en la URL); si no encuentra nada,
  "¿Quisiste decir…?" con los códigos y marcas más parecidos.
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
