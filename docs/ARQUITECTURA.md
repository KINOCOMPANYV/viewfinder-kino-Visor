# Arquitectura — Viewfinder Kino Visor

Resumen pensado para orientarse rápido (humanos y agentes) sin leer todo el código.

## Stack y despliegue
- PHP 8.2 + Apache (`Dockerfile`), MySQL de Railway (`MYSQL_URL`). Deploy automático desde `main`.
- Al arrancar el contenedor: `php migrate.php` aplica `migrations/*.sql` pendientes (tabla `migrations`).
- Luego, en segundo plano, `scripts/sync_loop.sh`: **sincronización automática** al arrancar (cada deploy) y cada
  `SYNC_INTERVAL_MINUTES` (60): `php scripts/cli_sync.php carpetas` (solo BD, carpetas ocultas), `hoja` y después `portadas` (los mismos controladores que los
  botones del admin). Aparte, cada 5 min, `imagenes` (búsqueda por foto). `AUTO_SYNC=0` la apaga. El resultado sale en los logs de Railway.
- Config: `config/database.php` (`env()`, `getDB()` singleton PDO), `config/app.php` (constantes, `APP_VERSION` para cache-busting).
  La clave del admin se comprueba solo al entrar (`adminPasswordMatches`); nada de bcrypt en cada petición (costaba ~60 ms).
- Variables clave: `GOOGLE_DRIVE_FOLDER_ID` (carpeta raíz de fotos), `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI`,
  `GOOGLE_SHEET_ID`, `CRON_SECRET`, `WEBHOOK_SHEETS_SECRET`, `ADMIN_USER/ADMIN_PASSWORD(_HASH)`.

## Rutas (`index.php`)
| Ruta | Archivo | Qué hace |
|---|---|---|
| `/` | `templates/client/landing.php` | Carpetas de primer nivel (abren `/carpeta/{id}`) + "Productos recientes" (60 referencias/página, una tarjeta por código) |
| `/buscar` (`?q=`, `?ajax=1`) | `src/controllers/search.php` | Una tarjeta por código (60/página, sin variantes; orden: exacto con/sin "M" vía `skuExactForms()`, empieza por, resto), multi-código, carpetas de `drive_index` (por nombre o código; si el código existe, van tras su tarjeta). `?album=ID` (viejo) redirige a `/carpeta/ID` |
| `/producto/{sku}` | `src/controllers/product.php` | Ficha; galería se carga por JS desde `/api/media/{sku}`. Si no hay producto pero es el nombre de un archivo indexado, redirige a su carpeta |
| `/carpeta/{id}` | `src/controllers/folder_view.php` | Lista una carpeta (índice local; Drive en vivo solo si no está indexada). Cada foto enlaza a `/producto/{matched_sku}` solo si ese producto es visible; si no, a la foto en grande |
| `/og/{fileId}.jpg` | `src/controllers/og_image.php` | Foto de vista previa para WhatsApp (og:image), ver "Compartir por WhatsApp" |
| `/novedades` (`?dias=7\|30\|90`) | `src/controllers/news.php` | **Novedades** (etapa H): archivos visibles con `drive_index.first_seen_at` en el periodo, marcables para Mi selección. El inicio muestra un enlace si llegó algo en 7 días |
| `/c/{código}` (+ `POST /c/crear`, `/c/{código}/actualizar`, `/c/{código}/desactivar[?activar=1]`) | `src/controllers/shared_catalog.php` | **Catálogo por enlace** (etapa I): página pública liviana con las fotos en orden, código bajo cada una, contacto opcional y vista previa de WhatsApp. Si una foto se borró u ocultó, muestra la portada actual del código. Se crea/gestiona desde Mi selección; quien lo creó guarda el token en `localStorage` `kinoLinks` (cabecera `X-Manage-Token`). Desactivado = 410 |
| `/mi-seleccion` | `templates/client/my_selection.php` + `assets/js/my_selection.js` | **Mi selección**: lo marcado en todo el visor (y lo que se pasa desde Enviar catálogo) en una lista: orden, quitar, "dejar 1 foto por código", estado por foto (lista / bajando / en una tanda / no bajó + 🔁 reintentar) y repetir la última tanda. Todo en el dispositivo (`localStorage` `kinoWaSel` de `wa_batch.js`, API `window.KinoWaBatch`). **Catálogos guardados** (etapa E): `localStorage` `kinoCatalogs` (guardar como, abrir = reemplaza la selección, actualizar, duplicar, renombrar, borrar); `POST /api/fotos-vigentes` (ids → los que siguen visibles en `drive_index`) marca las fotos que ya no están. **Cómo salen las fotos** (etapa F): foto sola o con su código en una franja blanca abajo (`withCode()` en `wa_batch.js`, canvas en el celular, preferencia `kinoWaLabel`), con vista previa; aplica a ⬇️ Descargar (barra y botón «Descargar todas», `KinoWaBatch.download()`) y Enviar por tandas |
| `/catalogo-pdf` (`?desde=lista` = lista de `sessionStorage kinoPdfSrc`) | `templates/client/catalog_pdf.php` + `assets/js/catalog_pdf.js` + `assets/js/pdf_shared.js` (fotos, texto, compartir/descargar; también lo usa `/lista-pedido`) + `assets/vendor/jspdf-4.2.1.umd.min.js` | **Catálogo en PDF** (plan `docs/PLAN_PDF.md`, P1): lo marcado (`kinoWaSel`, máx. 300) con su código y un texto opcional por código (`localStorage kinoPdfTexts`); título, subtítulo, pie de catálogo, colores primario/secundario y 4/6/9 fotos por página (`kinoPdfOpts`), logo del usuario (`kinoPdfLogo`, PNG ≤ 400 px) y botón «Abrir foto» bajo cada foto con enlace a `drive.google.com/file/d/{id}/view` (`kinoPdfOpts.open`, activado por defecto; la dirección no se imprime) y subtítulos de sección antes de una foto (`kinoPdfHeads`, por id de foto; empiezan fila nueva y se reserva su alto en cada página). El PDF (A4) se arma en el dispositivo con jsPDF; fotos de `/enviar-catalogo/foto/{id}` reducidas a JPEG. Compartir (Web Share) o Descargar. Nada se envía al servidor. Entradas: «📄 PDF» en la barra de `wa_batch.js` y «📄 Crear catálogo PDF» en Mi selección (usan la selección); el enlace `/c/…`, Enviar catálogo y cada catálogo guardado dejan su lista en `kinoPdfSrc` y abren `?desde=lista` (no tocan la selección; el nombre del catálogo pasa a ser el título) |
| `/enviar-catalogo` | `templates/client/catalog_send.php` + `src/controllers/catalog_send.php` | **Enviar catálogo por lista** (botón en el encabezado público): se pega un texto (o «📷 Leer lista de una foto»: `assets/js/list_ocr.js` lee la foto en el dispositivo con Tesseract de `assets/vendor/tesseract`, idioma `eng`, worker sin blob para que el núcleo halle su `.wasm`; el `.htaccess` de esa carpeta da tipo a `.wasm` y sirve el `.gz` sin Content-Encoding), `POST /enviar-catalogo/extraer` saca los códigos visibles (si uno no existe tal cual, `catalogSendOcrKey()` prueba B→8, O/D/Q→0, I/L→1, S→5, Z→2, G→6 y lo usa solo si da un único código; la respuesta trae `corrected`) y sus fotos (`filesForSku`, portada primero) y se muestran en una galería (tocar = marcar). Las marcadas se guardan en el celular (descargas; iPhone vía Compartir) o se comparten por tandas de 10 (Web Share; el botón se activa cuando la tanda ya bajó); 📲 en cada foto = solo esa. Se precargan de 3 en 3. Fotos: `/enviar-catalogo/foto/{id}` (lh3 `=s800` servido desde el dominio, copia en `storage/send_cache`; solo imágenes del índice, visibles y enlazadas). Solo lee la BD |
| `/extractor` | `templates/client/extractor.php` + `assets/js/extractor.js` (pantalla) + `assets/js/extractor_core.js` (lógica pura, se prueba en Node) + `assets/vendor/pdfjs` (pdf.js 4.10.38 legacy, `.mjs` copiado como `.js`) | **Extractor de pedidos PDF** (port de la app Flask EXTRACTOR): lee el recibo en el dispositivo (nada va al servidor, no lee la BD). Junta todas las páginas en líneas (celdas por espacios grandes, negrita por fuente), busca artículos entre «N artículos» y «Total:»: cantidad (`5x`) + código + `$precio`, en una línea o partidos (código arriba y cantidad debajo, o entre páginas); tabla «código ‖ cantidad ‖ $» también. Revisión: suma de PRECIO vs Total (o cantidad × precio), artículos y unidades del PDF; datos del pedido (n.º, cliente, fecha); Mapa del PDF. Tabla código / cantidad / UNIDAD / PRECIO / PRECIO −N% (por defecto 40), Organizar (orden natural), Copiar (TSV de lo visible). Sin cantidades (p. ej. un catálogo PDF) lista solo los códigos. «📷 Ver fotos en el visor» deja los códigos en `sessionStorage kinoCsText` y abre `/enviar-catalogo`, que los genera solo. Botón «🧾 Extractor» en el encabezado del inicio (computador) y en Enviar catálogo. «🧾 Lista pedido» deja el pedido en `sessionStorage kinoPedido` ({order, items: [{code, qty}] en el orden mostrado, warnings}) y abre `/lista-pedido` |
| `/lista-pedido` | `templates/client/order_list.php` + `assets/js/order_list.js` + `assets/js/pdf_shared.js` + jsPDF | **Lista pedido**: lee `kinoPedido`; muestra y pone en el PDF (A4, encabezado + dos tablas con bordes lado a lado, N.º / Foto / Código / Cantidad como la proforma de muestra del dueño, 13 filas cada una: ~26 por página, 20 en la primera) cliente, fecha, total de ítems (`N artículos`), unidades (`Cant:`) y valor total tal cual del PDF; falta un dato → «No se pudo leer del PDF» y recuadro «Revisar» (también los avisos del Extractor). Fotos: `POST /enviar-catalogo/extraer` con `{codes}` → `catalogSendLookup()` (exacto o `skuExactForms`, sin corrección OCR) → `{photos: {código: {sku, id} \| null}}`; foto principal de cada código, sin foto → «Foto no disponible». Cantidades solo lectura |
| `/api/search` | `src/controllers/api_search.php` | Autocomplete JSON |
| `/api/covers/batch` POST | inline en `index.php` | Portadas para SKUs sin `cover_image_url` (desde `drive_index`) |
| `/api/media/{sku}` | inline en `index.php` | Archivos de un SKU para la galería (desde `drive_index`) |
| `/api/download/{fileId}` | inline en `index.php` | Proxy de descarga autenticado. Solo archivos de `drive_index` no ocultos (el admin, todo): el token tiene acceso a todo el Drive del dueño |
| `/api/cron/sync-covers?token=` | `admin_sync_covers.php` | **Actualiza el índice de Drive** y asigna portada + álbum |
| `/api/cron/precache-drive?token=` | `admin_sync_covers.php` | Nombre viejo del cron = `sync-covers` (compatibilidad) |
| `/api/webhook/sheets` POST | `webhook_sheets.php` | Sync incremental desde Apps Script (misma regla que el sync completo) |
| `/admin/products` (`?q=&estado=&marca=&categoria=`) | `admin_products.php` | Listado y activar/desactivar. Cambio: `POST /admin/products/active` (`ids` o `all=1` + filtros) |
| `/admin/sync` | `templates/admin/sync.php` | Botones: hoja maestra (`POST /admin/sync-sheets`, `?dry_run=1`) y Drive (`/admin/media/sync-covers`) |
| `/admin/photo-check?sku=` | `admin_photo_check.php` | "¿Por qué no sale la foto?" (en Sincronizar): fotos del código en `drive_index`, ruta y qué las oculta (JSON) |
| `/admin/problemas` (`?tab=sinfoto\|ocultas\|archivos\|hoja`, `&csv=1`) | `admin_problems.php` | **Problemas de fotos** (etapa G, solo BD): productos sin foto, foto en carpeta oculta, archivos sin código y filas de la hoja sin precio/nombre, con motivo y ruta en Drive; CSV completo; "¿Por qué?" abre Sincronizar con el código (`/admin/sync?sku=`) |
| `/admin/folders` (`?id=`, `?q=`, `?ver=ocultas`) | `admin_folders.php` + `templates/admin/folders.php` | Mostrar/ocultar carpetas (cualquier nivel). Cambio: `POST /admin/folders/toggle` |
| `/admin/*` | `src/controllers/admin_*.php` + `templates/admin/*` | Panel (login, productos, carpetas, media, sincronizar, caché). Menú común: `templates/admin/partials/nav.php` |

## Tablas
- `products`: `sku` (único), `parent_sku` (familia), datos de la hoja maestra (`name`, `color`, `category`, `gender`,
  `movement`, `price_suggested`, `price_wholesale`, `stock`, `description`, `brand`, `case_material`, `strap_material`,
  `sheet_status`), `cover_image_url`, `album_id` (→ `albums.drive_id`), `sheet_row` (orden de la hoja), `sheet_hash`,
  `is_active` (interruptor del admin), `in_sheet` (está en la hoja) y `archived` (= oculto en el portal, calculado).
  `status` es heredada y ya no se usa.
- `albums`: carpetas de primer nivel bajo la raíz (`drive_id`, `name`, `icon_url`, `is_active`, `order_priority`).
- `drive_index`: **copia local del árbol de la carpeta raíz** (archivos y carpetas): `file_id`, `name`, `name_stem`
  (sin extensión, indexado para `LIKE 'SKU%'`), `mime_type`, `is_folder`, `parent_id`, `album_id`, `is_public`, `synced_at`,
  `is_hidden` y `cover_file_id` (migración 021, ver "Carpetas: ocultar y portada"), `first_seen_at` (migración 026: cuándo
  lo vio el sync por primera vez; el UPSERT no lo cambia; lo anterior a la migración queda NULL).
  Lo llena `DriveIndex::sync()` (`src/services/DriveIndex.php`).
- `shared_catalogs` (migración 027): `code` único, `name`, `items` (JSON `[{id, sku}]`, máx. 300), `contact`, `manage_hash`
  (sha256 del token de gestión), `is_active`, `views`. No se borran: se desactivan.
- `image_embeddings` (migración 028): `file_id` (portada) → `embedding` (512 float32 empaquetados, jina-clip-v2; NULL = no
  bajó, se reintenta en 1 día). Búsqueda por foto.
- `folder_settings`: `folder_id` → `hidden` (decisión del admin; sobrevive a los syncs).
- `media_search_cache`: `sku` → `files_json` (búsqueda en Drive). Solo se usa mientras `drive_index` está vacío.
- `drive_cache`: hoy solo guarda la visibilidad por archivo (`visible_publico`) y subcarpetas para la búsqueda.
- `google_tokens`: OAuth (último registro = activo; `getValidToken()` renueva solo). Se escribe en `/admin/google/callback`
  (`admin_google_callback.php`); errores → flash en `/admin/media`.
- Otras: `migrations`, `task_queue`, `media_assets` y `downloads_log` (heredadas, poco usadas).

## Índice local de Drive (clave del rendimiento)
- `DriveIndex::sync()` recorre la carpeta raíz por niveles (40 carpetas por consulta, `listChildrenOfMany`),
  hace upsert en `drive_index`, borra lo que ya no existe y hace públicos solo los archivos nuevos (`is_public`).
  Si Drive falla a mitad, o la raíz sale vacía (ID o permisos mal), no toca el índice ni los álbumes.
- Se ejecuta con el botón admin **Sincronizar portadas** y con el cron `/api/cron/sync-covers?token=CRON_SECRET`
  (programarlo, p. ej. cada 30-60 min). Subir/borrar desde el admin actualiza el índice al momento.
- `DriveIndex::filesForSku()` = archivos con `matched_sku` = el código + los genéricos de su familia (`matched_sku` = base,
  nombre = base). Solo MySQL. Devuelve el formato de la API de Drive con `thumbnailLink` = lh3 por ID.
- `DriveIndex::isReady()`: si la tabla está vacía (antes del primer sync) todo cae al camino viejo (Drive en vivo + caché).

## Flujo de portadas (cómo llega la foto a la tarjeta)
1. **Guardada en BD** (camino rápido): `products.cover_image_url` = `lh3Url($id)` (`…/d/{id}=s400`).
   Se pinta directo en el HTML (landing/search/carpetas) con `cardImgAttrs()`: `srcset` 200/400 px (el navegador elige según
   la pantalla), las primeras `eager` + `fetchpriority=high`, el resto `lazy`. Si un script cambia la foto, quita el `srcset`.
2. **Sin portada** → placeholder 📷 y el JS llama `POST /api/covers/batch` con los SKUs que faltan.
   Con índice: sale de `drive_index` y se **auto-asigna** (`autoAssignCover`), así la próxima visita ya viene en el HTML.
3. **Asignación**: `DriveIndex::relink()` (ver "Enlace único foto ↔ producto") recalcula la portada de TODOS los productos.
4. **Ficha de producto**: la portada sale en el HTML con `<link rel=preload>` (=s800). Clic = foto en grande
   (`assets/js/lightbox.js`, =s1600, precargada en segundo plano: portada + 4 primeras fotos + vecinas). La galería completa
   llega por JS desde `/api/media/{sku}` (índice local; `Cache-Control` 60 s).

Tamaños de lh3: `=s120` chips, `=s150/200` miniaturas, `=s400` tarjetas, `=s600/800` vista principal, `=s1200` compartir.
Desde 600 px se agrega `-rj` (JPEG): si el original es PNG, lh3 lo da en PNG (5-7 veces más pesado). `lh3Url()` y
`coverUrlAtSize()` lo ponen solos (`lh3JpegSuffix()`); en JS va escrito (`=s600-rj`, `=s1200-rj`, lightbox `=s1600-rj`).

## Álbumes (marcas)
- En la interfaz ya no hay "álbumes": se muestran como **carpetas** y se navegan como en Drive. La tabla `albums` sigue
  existiendo como lista de carpetas de primer nivel (la mantiene el sync).
- Álbum = carpeta de primer nivel de la raíz, salvo auxiliares (`isAlbumFolderName`: empieza por "_" o es "PORTADAS").
- `DriveIndex::syncAlbums()` (en cada sync de portadas): crea las nuevas, renombra, y **desactiva** (no borra) las
  que ya no están bajo la raíz (`auto_disabled=1`, migración 019) y reactiva esas si vuelven. No reactiva lo que el admin desactivó.
- Álbum de un producto = álbum del mejor archivo que coincide con su SKU (archivos sueltos en la raíz no cuentan).
  Se recalcula si está vacío, si apunta a un álbum fuera de la raíz actual, o con "forzar".
  Desempate: calidad del match (exacto 3 > prefijo 2 > flojo 1) → `scoreCoverCandidate` → orden por nombre (estable).

## Productos: hoja maestra + activación del admin
- **La hoja MAESTRA es la única fuente de datos** (`src/services/MasterSheet.php`, env `GOOGLE_SHEET_ID` y opcional
  `GOOGLE_SHEET_GID`). Encabezados aceptados: SKU, NAME, COLOR, CATEGORY, GENDER, MOVEMENT, PRICE_SUGGESTED, PRICE, STOCK,
  DESCRIPTION, BRAND, MATERIAL DE CAJA, MATERIAL PULSERA, STATUS/estado (también en español). Se guarda tal cual.
- Cada sync de la hoja y de Drive (botón, cron o automático) guarda en `sync_status` (migración 025, `recordSyncStatus()`,
  filas `hoja` y `drive`) la fecha del último éxito con qué cambió y del último error; Admin → Sincronizar los muestra por
  separado, con "🔁 Reintentar" si el último intento falló, y el conteo hoja / Solo Drive / visibles.
- **Un solo sync a la vez**: `acquireSyncLock()` (helpers, `GET_LOCK('vf_sync')`, se suelta al terminar el proceso). Hoja y
  Drive no esperan: si está ocupado responden 423 `busy` ("ya hay una sincronización corriendo") y no lo guardan como error.
  El webhook de la hoja y el paso `carpetas` del sync automático sí esperan a que termine el otro.
- El sync completo marca `in_sheet = 0` lo que ya no está en la hoja (no borra). Si iba a ocultar más de la mitad, pide confirmar.
- **Activar/desactivar es solo del admin** (`is_active`); la hoja nunca lo cambia. No hay borrado de productos.
- `refreshProductVisibility()` (helpers) recalcula `archived = NOT (is_active AND (in_sheet OR from_drive))`; todas las páginas públicas
  filtran por `archived = 0 AND cover_image_url > ''` (sin foto/video no se muestra; 2026-11-10). La ficha de un producto oculto da 404 (salvo al admin).

## Carpetas: ocultar y portada
- El admin apaga una carpeta en **Admin → Carpetas** → `folder_settings.hidden = 1` (en una de primer nivel también `albums.is_active = 0`).
- `DriveIndex::refreshFolderState()` (tras cada sync y cada cambio) marca `drive_index.is_hidden = 1` en la carpeta y en todo
  lo que tiene dentro, y elige para cada carpeta una foto **al azar** (pública, no oculta) de todo su contenido → `cover_file_id`.
  También vacía las portadas de productos que eran una foto oculta (el visor elige otra visible).
- Carpetas "archivado" y "desactivados" (`isArchivedFolderName()`): `DriveIndex::hideArchivedFolders()` (al inicio de `refreshFolderState`) crea
  `folder_settings.hidden = 1` solo si la carpeta no tiene fila; si el admin la muestra (`hidden = 0`) se respeta.
- Todo lo público filtra `is_hidden = 0`: `/carpeta/{id}`, carpetas de la búsqueda, galería (`filesForSku`) y el sync de portadas.

## Familias (padre ↔ variantes)
- **Una sola regla**: `computeParentSkuMap()`. Variantes numéricas "BASE-N" → padre "BASE-1" aunque cambie el nombre
  (sin -1: el número más bajo, `FAMILY_PARENT_FALLBACK_LOWEST`). El padre tiene su propio código como `parent_sku`.
  Bases de 1-2 cifras ("3-68", "7-152") no se agrupan. Sufijos con letras ("PZH002-B"): raíz + mismo nombre exacto.
- Fotos genéricas de la familia = archivo con el nombre de la base ("M8363.jpg"): `familyMediaBase()` + `combineFamilyFiles()`.
  `recomputeParentSkus($db)` la aplica a todo el catálogo; la llaman el sync de la hoja maestra, el webhook y los cambios de activación.
  El sync completo de Sheets la aplica sobre la hoja.
- Ficha (`product.php`): "Variantes Disponibles" busca la familia por `parent_sku` Y por la base numérica
  (1416-1 → 1416-*), así sale aunque `parent_sku` esté viejo. Miniatura = portada, o lo mismo desde `filesForSku`
  (propia o genérica de la familia; nunca la de un hermano).
- Agrupar tarjetas: `familyKey($row)` = `parent_sku` o `extractRootSku()` (inicio y búsqueda usan lo mismo).

## Búsqueda
- Código sin importar "-", espacios, ":" ni "." (`skuSearchSql()` / `skuSearchNorm()`): "M8363-3" encuentra "M:8363-3".
- Resultados mientras se escribe (`assets/js/search.js`, `/buscar?ajax=1`); una búsqueda nueva cancela la anterior.
- Orden: código exacto, luego "empieza por", luego el resto (por nombre).
- **Filtros** (`/buscar?marca=&cat=&pmin=&pmax=&foto=1`): en la BD (`$filterSql` en `search.php`), combinables, en la URL
  (Atrás y la paginación los conservan) y viajan con la búsqueda en vivo. Solo se ofrecen marcas/categorías con 5+ productos
  visibles y precio si hay 20+ con precio (`searchFilterOptions()`). Chips con ✕ y "Quitar filtros".
- **Sin resultados** → "¿Quisiste decir…?" (`searchSuggestions()`, helpers): códigos parecidos por distancia de letras
  (completo o el inicio del código), uno por familia, y marcas parecidas (abren el filtro). Sin servicios externos. Con
  filtros puestos no sugiere: avisa que los filtros pueden ocultar resultados.
- **Por foto** (`src/services/ImageSearch.php`, env `JINA_API_KEY`; sin ella no sale el botón): ícono de cámara dentro de la caja
  de búsqueda, a la derecha (inicio y búsqueda; con texto el ✕ se corre a su izquierda; `templates/partials/image_search.php`). El navegador achica la foto a 512 px y la manda a `POST /buscar-foto`
  (`src/controllers/image_search.php`, 20/min por IP): 1 llamada a Jina para su vector, producto punto contra `image_embeddings`
  de las portadas visibles (foto, no video), 30 más parecidas en una ventana; mientras faltan portadas por preparar avisa
  "N de M listas" (sin ninguna lista no llama a Jina). `cli_sync.php imagenes` corre en su propio ciclo cada 5 min en
  `sync_loop.sh` (máx. `IMAGE_INDEX_MAX_SECONDS`=1500 por vuelta) y prepara las portadas nuevas (lh3 `=s512`, 8 por llamada).
  Última vuelta en `storage/image_index_status.json`; estado y errores de Jina en Admin → Sincronizar.
  Si Jina falla, la ventana dice la causa (`ImageSearch::classify`: saldo 402, clave 401/403, límite 429, otro) y el detalle va
  al log. `GET /buscar-foto/estado` (público, solo cuentas): fotos, listas, última vuelta y estado
  (listo/preparando/saldo/clave/limite/error/sin_datos).

## Coincidencia SKU ↔ archivo (regla única, decidida por el dueño)
- **La foto se llama como el código**: `X6071.jpg`, o el código + separador + lo que sea (`X6071-A.jpg`, `X6071 (2).jpg`).
  Nada de coincidencias flojas (sin prefijo de marca, raíz, "Copia de", `V1` pegado). Gana el código más largo.
- Misma regla en todos lados: `fileNameStartsWithSku()` (galería, `DriveIndex::findByPrefix`) y `matchFileToProduct()` (sync-covers).
  `skuNameVariants()`: el separador entre el prefijo de letras y el número es libre ("M:8464-2" = "M8464-2" = "M-8464-2").
  Se usa en el sync, en la galería y en `/producto/{sku}` ("/producto/M-8464-2" abre "M:8464-2").
- Implementación única: `skuMatchIndex()` + `matchFileToProduct()` (helpers). La usan `DriveIndex::relink()` y Admin → Media.
- Lo que no cumple queda en `drive_index.matched_sku = NULL` (migración 020) y se descarga para renombrar:
  `/admin/media/sin-codigo.csv?tipo=archivos` y `?tipo=productos` (botones en Admin → Media).
- "Reasignar TODAS" (forzado) además cambia las portadas elegidas a mano por la mejor.

## Enlace único foto ↔ producto (`DriveIndex::relink`, solo BD)
1. Cada archivo visible del índice → código con `matchFileToProduct()` contra TODOS los productos (también ocultos,
   para que su foto no se pegue a un código más corto). Si el mismo nombre sirve para un visible y un oculto escrito
   distinto ("M:8291-1" visible vs "M8291-1" oculto), gana el visible (índice de visibles + índice de ocultos). Si se llama como la base de una familia que no es producto
   ("993.jpg", "M8363 (2).jpg") → `matched_sku` = esa base (foto de toda la familia). Se guarda en `drive_index.matched_sku`.
2. Portada de cada producto: foto propia > foto de la familia > video propio > video de la familia > vacía.
   Se respeta una elegida a mano del mismo nivel; se quitan las viejas (otra raíz, otro código, ocultas, borradas).
   Antes del paso 1: **productos "solo Drive"** (migración 024, `from_drive`): una foto cuyo nombre es un código
   (`driveCodeFromName`) que no está en la hoja crea el producto (nombre = código, marca = carpeta de primer nivel).
   "X-1-A.jpg" va a "X-1" si también hay "X-1.jpg"; "-V1"/"-F2" se quitan. No cuentan `IMG_…`, WhatsApp, `.psd`
   ni fotos en carpetas "DESACTIVADOS". Sin foto → `from_drive = 0` (oculto, no se borra). `DRIVE_PRODUCTS=0` lo apaga.
3. Se llama tras: sync de portadas, ocultar/mostrar carpeta, subir/borrar en Media, sync de la hoja, webhook y activar/desactivar.
- Lo leen: galería (`filesForSku`), `/api/covers/batch`, miniaturas de la ficha, Admin → Media y "¿Por qué no sale la foto?".
- Tarjeta (inicio y búsqueda): si el padre no tiene foto, usa la portada de una variante.
- Variantes: `computeParentSkuMap()` agrupa por raíz candidata + mismo nombre → `parent_sku`.
  Un hijo ve sus archivos propios + los genéricos del padre (`isGenericMediaForSku`).

## Compartir por WhatsApp
- Ficha, carpeta, búsqueda e inicio llevan etiquetas Open Graph (`shareMetaTags()` en helpers): al pegar el enlace en
  WhatsApp sale la foto, el título y la descripción. URL absoluta con `publicBaseUrl()` (env `APP_URL` o el host de la petición).
- `og:image` = `/og/{fileId}.jpg` (propio dominio): baja la foto de lh3 una vez, la deja cuadrada de 600 px con fondo
  blanco, JPEG ≤ 250 KB, y la guarda en `storage/og_cache/`. Solo archivos de `drive_index` no ocultos. Si lh3 falla, redirige a lh3.
- Botón "Compartir por WhatsApp" (`templates/partials/share_whatsapp.php`) en ficha y carpeta: abre `wa.me` con título + enlace.
- **WhatsApp + Descargar siempre visibles** en cada foto: tarjetas de inicio y búsqueda, tarjetas de carpeta, foto principal
  de la ficha y visor grande. Marcado `data-media-action="wa|dl"` (`mediaActionButtons()` en helpers) y un solo script,
  `assets/js/media_actions.js`: usa la foto que muestra la tarjeta en ese momento. WhatsApp en el celular manda solo la imagen
  (`/enviar-catalogo/foto/{id}`, 800 px; si no sirve, `/api/download`) con el código como pie; en el computador copia la foto
  al portapapeles (si no se puede, abre `wa.me` con el enlace). Descargar = `/api/download/{id}`.
- **Marcar varias en el catálogo** (inicio, búsqueda, también tras la búsqueda AJAX, y carpetas: una tarjeta por foto; si la carpeta solo tiene subcarpetas, `DriveIndex::descendantImages()` trae las fotos de todas ellas, máx. 300): casilla `.search-card-check`
  (`data-sku`, `data-file-id`, `data-is-video`) en cada tarjeta y `assets/js/wa_batch.js`, que crea la barra `.wab-bar`
  (Marcar todas = visibles con foto, ⬇️ Descargar, 📲 Enviar por tandas de 10 sin texto). Mismo mecanismo que Enviar catálogo:
  fotos de `/enviar-catalogo/foto/{id}` (si da 404, foto de carpeta sin código, `/api/download`), precarga de 6 en 6 desde que se marca, 3 intentos.
  Lo marcado se guarda en `sessionStorage` (`kinoWaSel`): sigue al hacer otra búsqueda y al cambiar de página (carpetas,
  inicio, ficha; también al volver con Atrás, `pageshow`). En el celular la barra va encima de la barra inferior.
- Galería de la ficha: sus casillas son las mismas (`.search-card-check` dentro de `.gallery-item`, solo fotos; los videos
  se bajan con su botón) y `product_page.js` llama `KinoWaBatch.sync()` al dibujarla. Ya no hay barra propia ni pie de foto.

## Estética (etapa 4)
- Colores SOLO con los tokens de `:root` en `style.css` (`--color-*`; WhatsApp: `--color-wa`, `--color-wa-hover`; dorado
  claro: `--color-primary-light`). No escribas hex sueltos en CSS nuevo.
- Mientras una foto de tarjeta o galería no carga, su recuadro tiene un brillo animado (`kino-shimmer`, con `:has` sobre
  `.img-fade-in:not(.loaded)`); se apaga con `prefers-reduced-motion`.
- Limpieza 2026-11-10: se quitaron las reglas de clases que ningún PHP/JS usa (sidebar de álbumes, tarjetas viejas,
  importación de Excel). Antes de borrar CSS, comprueba la clase con grep en todo el repo (hay clases armadas por variable,
  p. ej. `cache-flash--<?= … ?>`).

## Caché HTTP
- Assets (`.htaccess`): 1 año `immutable`; se invalida con `?v=APP_VERSION`.
- Landing `max-age=60`; ficha de producto: ETag + `private, max-age=60`; APIs con `jsonCachedResponse($data, ttl)`.
- Páginas públicas (GET fuera de `/admin`): `index.php` quita el `no-store` de la sesión y pone `private, no-cache`, así el
  botón Atrás vuelve al instante (bfcache). El admin sigue con `no-store`.
- El JS de cada página va en archivos cacheables (`landing.js`, `search_page.js`, `product_page.js`, `catalog_send.js`);
  en la plantilla solo quedan las constantes con datos de PHP (SKU, CSRF…). Preconexión a `lh3` sin `crossorigin`
  (las `<img>` no usan CORS; con `crossorigin` la conexión previa no se aprovechaba).

## Rendimiento
Ver `docs/RENDIMIENTO.md` (problemas conocidos y decisiones).

## Móvil (responsive)
- Reglas comunes al final de `assets/css/style.css` (bloque "Móvil"): sin scroll horizontal (`overflow-x: clip`),
  botones ≥ 40-44 px, campos a 16 px (iOS no hace zoom), 3 tarjetas por fila en tablet.
- Admin → Productos: bajo 900 px la tabla se ve como tarjetas (estilos en `templates/admin/products.php`). Media tiene su bloque en su `<style>`.
- Bloque "Repaso móvil 2" (final de `style.css`): encabezado (bajo 370 px con dos botones queda solo el icono "VF"),
  menú del admin en cuadrícula de 3 (2 bajo 380 px) en vez de fila deslizable, letra de tarjetas ≥ 12 px en el celular.
- Barra "Seleccionar / Enviar por WhatsApp" (`.search-wa-toolbar`, catálogo y galería de la ficha): fija abajo; se muestra
  con la clase `visible` al marcar algo. Enviar catálogo: en el celular el panel de envío no es `sticky` (tapaba la lista).
- Ficha: las columnas de `.detail-grid` son `minmax(0, 1fr)` para que la tira de miniaturas (`#thumbStrip`, deslizable)
  no ensanche la columna; si no, la foto principal se sale de la pantalla. En el celular (≤ 640 px) la tira es una
  cuadrícula de 5 (4 bajo 340 px), sin deslizar (`gallery-slider.css`); tocar una miniatura sube a la foto (`mainIntoView`).
- Como hay mucho estilo en línea en las plantillas, algunas reglas móviles usan `!important` a propósito.
- Bloque "Etapa 2" (final de `style.css`, ≤ 640 px): barra inferior `templates/partials/mobile_nav.php` (`$mnavActive`;
  se esconde cuando está abierta la barra de WhatsApp), encabezado no fijo y sin botones `.hdr-hide-m`, `.hero` con
  `display: contents` para que `.search-box` quede `sticky` en toda la página, carpetas `#carpetas` de 3 en 3,
  `.folder-hits` (carpetas en la búsqueda) como filas, y la casilla `.search-check-footer` como círculo sobre la foto.
- Navegación: `mobile_nav.php` también agrega el botón `.hdr-back` (←, solo ≤ 640 px, no en `/`): `history.back()` si se llegó desde el sitio; si no, va al `href` del botón ← que el encabezado esconde en el celular, o a `/`. Migas de pan con `breadcrumbs([[texto, href], …])` de `helpers.php` (clase `.crumbs`); en `/catalogo-pdf` la del medio sale de `kinoPdfSrc.from`.
- Buscador: la ✕ (`.search-clear`, todas las pantallas) se ve solo con texto (`:placeholder-shown`); en el celular el texto
  de ayuda corto sale de `data-ph-short` (`search.js`). El overlay "cargando" ignora clics ya manejados (`defaultPrevented`).

## Extractor: formatos de PDF (`assets/js/extractor_core.js`)
- `parseOrder()` prueba primero `parseTable()`: cotización en tabla (títulos «CODIGO … CANT … PRECIO … TOTAL», filas
  «código producto cant unidad desc precio total», encabezado repetido por página). Datos: n.º «COTIZACION No», `CLIENTE:`,
  `FECHA:` (aaaa/mm/dd; no `VENCE:`), último «TOTAL : $» con valor. Precio = unitario; revisa cantidad × precio por línea y
  la suma contra el TOTAL. `order.format = 'tabla'`; `itemsPdf`/`unitsPdf` = null (el PDF no los trae).
- Lista pedido: casillas por dato (`localStorage kinoOlFields`: number, client, date, items, units, total, review, useCount)
  deciden qué sale en el PDF (encabezado, banda, pie y «Revisar»). Columnas `cN, cPhoto, cCode, cDesc, cQty, cPrice,
  cTotal` (desc/priceText/lineTotalText llegan en `kinoPedido.items`); dos tablas si la suma de anchos mínimos cabe en
  media hoja, si no una; fila 19 mm con foto, 9 mm sin foto. Sin ítems/unidades del PDF y `useCount` → «N (contado)».
- Tipo de documento: `kinoPedido.docType` = `remision` (formato tabla) o `pedido` (recibo). `/lista-pedido` cambia título,
  colores (`TYPES` en `order_list.js`), nombre del archivo y casillas guardadas (`kinoOlFields` / `kinoOlFieldsRemision`);
  selector «Tipo» para corregirlo. `tableOrderData()` también lee NIT, dirección, ciudad, teléfono y vendedor (`headValue()`).
- Si no hay esa tabla, sigue el recibo de siempre («N artículos» … «Total:», `5x código … $`).
