# Arquitectura — Viewfinder Kino Visor

Resumen pensado para orientarse rápido (humanos y agentes) sin leer todo el código.

## Stack y despliegue
- PHP 8.2 + Apache (`Dockerfile`), MySQL de Railway (`MYSQL_URL`). Deploy automático desde `main`.
- Al arrancar el contenedor: `php migrate.php` aplica `migrations/*.sql` pendientes (tabla `migrations`).
- Luego, en segundo plano, `scripts/sync_loop.sh`: **sincronización automática** al arrancar (cada deploy) y cada
  `SYNC_INTERVAL_MINUTES` (60): `php scripts/cli_sync.php hoja` y después `portadas` (los mismos controladores que los
  botones del admin). `AUTO_SYNC=0` la apaga. El resultado sale en los logs de Railway.
- Config: `config/database.php` (`env()`, `getDB()` singleton PDO), `config/app.php` (constantes, `APP_VERSION` para cache-busting).
- Variables clave: `GOOGLE_DRIVE_FOLDER_ID` (carpeta raíz de fotos), `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI`,
  `GOOGLE_SHEET_ID`, `CRON_SECRET`, `WEBHOOK_SHEETS_SECRET`, `ADMIN_USER/ADMIN_PASSWORD(_HASH)`.

## Rutas (`index.php`)
| Ruta | Archivo | Qué hace |
|---|---|---|
| `/` | `templates/client/landing.php` | Carpetas de primer nivel (abren `/carpeta/{id}`) + "Productos recientes" (10 familias/página) |
| `/buscar` (`?q=`, `?ajax=1`) | `src/controllers/search.php` | Grid de familias (20/página), multi-código, carpetas de `drive_index`. `?album=ID` (viejo) redirige a `/carpeta/ID` |
| `/producto/{sku}` | `src/controllers/product.php` | Ficha; galería se carga por JS desde `/api/media/{sku}` |
| `/carpeta/{id}` | `src/controllers/folder_view.php` | Lista una carpeta (índice local; Drive en vivo solo si no está indexada) |
| `/api/search` | `src/controllers/api_search.php` | Autocomplete JSON |
| `/api/batch-search` POST | `src/controllers/api_batch_search.php` | Búsqueda por lote (modal) |
| `/api/covers/batch` POST | inline en `index.php` | Portadas para SKUs sin `cover_image_url` (desde `drive_index`) |
| `/api/media/{sku}` | inline en `index.php` | Archivos de un SKU para la galería (desde `drive_index`) |
| `/api/download/{fileId}` | inline en `index.php` | Proxy de descarga autenticado |
| `/api/drive-folders` | inline en `index.php` | Álbumes JSON |
| `/api/cron/sync-covers?token=` | `admin_sync_covers.php` | **Actualiza el índice de Drive** y asigna portada + álbum |
| `/api/cron/precache-drive?token=` | `api_precache_drive.php` | Con índice activo = alias de `sync-covers` (compatibilidad con crons viejos) |
| `/api/webhook/sheets` POST | `webhook_sheets.php` | Sync incremental desde Apps Script (misma regla que el sync completo) |
| `/admin/products` (`?q=&estado=&marca=&categoria=`) | `admin_products.php` | Listado y activar/desactivar. Cambio: `POST /admin/products/active` (`ids` o `all=1` + filtros) |
| `/admin/sync` | `templates/admin/sync.php` | Botones: hoja maestra (`POST /admin/sync-sheets`, `?dry_run=1`) y Drive (`/admin/media/sync-covers`) |
| `/admin/photo-check?sku=` | `admin_photo_check.php` | "¿Por qué no sale la foto?" (en Sincronizar): fotos del código en `drive_index`, ruta y qué las oculta (JSON) |
| `/admin/folders` (`?id=`, `?q=`, `?ver=ocultas`) | `admin_folders.php` + `templates/admin/folders.php` | Mostrar/ocultar carpetas (cualquier nivel). Cambio: `POST /admin/folders/toggle` |
| `/admin/*` | `src/controllers/admin_*.php` + `templates/admin/*` | Panel (login, productos, carpetas, media, sincronizar, álbumes, caché). Menú común: `templates/admin/partials/nav.php` |

## Tablas
- `products`: `sku` (único), `parent_sku` (familia), datos de la hoja maestra (`name`, `color`, `category`, `gender`,
  `movement`, `price_suggested`, `price_wholesale`, `stock`, `description`, `brand`, `case_material`, `strap_material`,
  `sheet_status`), `cover_image_url`, `album_id` (→ `albums.drive_id`), `sheet_row` (orden de la hoja), `sheet_hash`,
  `is_active` (interruptor del admin), `in_sheet` (está en la hoja) y `archived` (= oculto en el portal, calculado).
  `status` es heredada y ya no se usa.
- `albums`: carpetas de primer nivel bajo la raíz (`drive_id`, `name`, `icon_url`, `is_active`, `order_priority`).
- `drive_index`: **copia local del árbol de la carpeta raíz** (archivos y carpetas): `file_id`, `name`, `name_stem`
  (sin extensión, indexado para `LIKE 'SKU%'`), `mime_type`, `is_folder`, `parent_id`, `album_id`, `is_public`, `synced_at`,
  `is_hidden` y `cover_file_id` (migración 021, ver "Carpetas: ocultar y portada").
  Lo llena `DriveIndex::sync()` (`src/services/DriveIndex.php`).
- `folder_settings`: `folder_id` → `hidden` (decisión del admin; sobrevive a los syncs).
- `media_search_cache`: `sku` → `files_json` (búsqueda en Drive). Solo se usa mientras `drive_index` está vacío.
- `drive_cache`: hoy solo guarda la visibilidad por archivo (`visible_publico`) y subcarpetas para la búsqueda.
- `google_tokens`: OAuth (último registro = activo; `getValidToken()` renueva solo).
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
   Se pinta directo en el HTML (landing/search). Las 3 primeras con `loading=eager` y el resto `lazy`.
2. **Sin portada** → placeholder 📷 y el JS llama `POST /api/covers/batch` con los SKUs que faltan.
   Con índice: sale de `drive_index` y se **auto-asigna** (`autoAssignCover`), así la próxima visita ya viene en el HTML.
3. **Asignación**: `DriveIndex::relink()` (ver "Enlace único foto ↔ producto") recalcula la portada de TODOS los productos.
4. **Ficha de producto**: la portada sale en el HTML con `<link rel=preload>` (=s800). Clic = foto en grande
   (`assets/js/lightbox.js`, =s1600, precargada en segundo plano: portada + 4 primeras fotos + vecinas). La galería completa
   llega por JS desde `/api/media/{sku}` (índice local; `Cache-Control` 60 s).

Tamaños de lh3: `=s120` chips, `=s150/200` miniaturas, `=s400` tarjetas, `=s600/800` vista principal, `=s1200` compartir.

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
- El sync completo marca `in_sheet = 0` lo que ya no está en la hoja (no borra). Si iba a ocultar más de la mitad, pide confirmar.
- **Activar/desactivar es solo del admin** (`is_active`); la hoja nunca lo cambia. No hay borrado de productos.
- `refreshProductVisibility()` (helpers) recalcula `archived = NOT (is_active AND in_sheet)`; todas las páginas públicas
  filtran por `archived = 0`. La ficha de un producto oculto da 404 (salvo al admin).

## Carpetas: ocultar y portada
- El admin apaga una carpeta en **Admin → Carpetas** → `folder_settings.hidden = 1` (en una de primer nivel también `albums.is_active = 0`).
- `DriveIndex::refreshFolderState()` (tras cada sync y cada cambio) marca `drive_index.is_hidden = 1` en la carpeta y en todo
  lo que tiene dentro, y elige para cada carpeta una foto **al azar** (pública, no oculta) de todo su contenido → `cover_file_id`.
  También vacía las portadas de productos que eran una foto oculta (el visor elige otra visible).
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
   para que su foto no se pegue a un código más corto). Si se llama como la base de una familia que no es producto
   ("993.jpg", "M8363 (2).jpg") → `matched_sku` = esa base (foto de toda la familia). Se guarda en `drive_index.matched_sku`.
2. Portada de cada producto: foto propia > foto de la familia > video propio > video de la familia > vacía.
   Se respeta una elegida a mano del mismo nivel; se quitan las viejas (otra raíz, otro código, ocultas, borradas).
3. Se llama tras: sync de portadas, ocultar/mostrar carpeta, subir/borrar en Media, sync de la hoja, webhook y activar/desactivar.
- Lo leen: galería (`filesForSku`), `/api/covers/batch`, miniaturas de la ficha, Admin → Media y "¿Por qué no sale la foto?".
- Tarjeta (inicio y búsqueda): si el padre no tiene foto, usa la portada de una variante.
- Variantes: `computeParentSkuMap()` agrupa por raíz candidata + mismo nombre → `parent_sku`.
  Un hijo ve sus archivos propios + los genéricos del padre (`isGenericMediaForSku`).

## Caché HTTP
- Assets (`.htaccess`): 1 año `immutable`; se invalida con `?v=APP_VERSION`.
- Landing `max-age=60`; ficha de producto: ETag + `private, max-age=60`; APIs con `jsonCachedResponse($data, ttl)`.

## Rendimiento
Ver `docs/RENDIMIENTO.md` (problemas conocidos y decisiones).

## Móvil (responsive)
- Reglas comunes al final de `assets/css/style.css` (bloque "Móvil"): sin scroll horizontal (`overflow-x: clip`),
  botones ≥ 40-44 px, campos a 16 px (iOS no hace zoom), menú del admin en una fila deslizable, 3 tarjetas por fila en tablet.
- Admin → Productos: bajo 900 px la tabla se ve como tarjetas (estilos en `templates/admin/products.php`). Media tiene su bloque en su `<style>`.
- Como hay mucho estilo en línea en las plantillas, algunas reglas móviles usan `!important` a propósito.
