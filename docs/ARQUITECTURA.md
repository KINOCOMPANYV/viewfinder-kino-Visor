# Arquitectura — Viewfinder Kino Visor

Resumen pensado para orientarse rápido (humanos y agentes) sin leer todo el código.

## Stack y despliegue
- PHP 8.2 + Apache (`Dockerfile`), MySQL de Railway (`MYSQL_URL`). Deploy automático desde `main`.
- Al arrancar el contenedor: `php migrate.php` aplica `migrations/*.sql` pendientes (tabla `migrations`).
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
| `/api/webhook/sheets` POST | `webhook_sheets.php` | Sync incremental desde Apps Script |
| `/admin/folders` (`?id=`, `?q=`, `?ver=ocultas`) | `admin_folders.php` + `templates/admin/folders.php` | Mostrar/ocultar carpetas (cualquier nivel). Cambio: `POST /admin/folders/toggle` |
| `/admin/*` | `src/controllers/admin_*.php` + `templates/admin/*` | Panel (login, productos, media, álbumes, import, sheets, caché). Menú común: `templates/admin/partials/nav.php` |

## Tablas
- `products`: `sku` (único), `parent_sku` (familia), `name`, `category`, `gender`, `cover_image_url`,
  `album_id` (→ `albums.drive_id`), `archived`, `sheet_row` (orden de la hoja), `sheet_hash`.
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
- `DriveIndex::filesForSku()` = misma regla que la búsqueda en Drive (`fileNameStartsWithSku` + familia con
  `combineFamilyFiles`), pero en MySQL. Devuelve el formato de la API de Drive con `thumbnailLink` = lh3 por ID.
- `DriveIndex::isReady()`: si la tabla está vacía (antes del primer sync) todo cae al camino viejo (Drive en vivo + caché).

## Flujo de portadas (cómo llega la foto a la tarjeta)
1. **Guardada en BD** (camino rápido): `products.cover_image_url` = `lh3Url($id)` (`…/d/{id}=s400`).
   Se pinta directo en el HTML (landing/search). Las 3 primeras con `loading=eager` y el resto `lazy`.
2. **Sin portada** → placeholder 📷 y el JS llama `POST /api/covers/batch` con los SKUs que faltan.
   Con índice: sale de `drive_index` y se **auto-asigna** (`autoAssignCover`), así la próxima visita ya viene en el HTML.
3. **Asignación masiva**: `admin_sync_covers.php` → sync del índice + match por SKU (`matchFileToProduct`, ver "Coincidencia SKU ↔ archivo"). Puntaje: `scoreCoverCandidate()` (la imagen gana al video).
   Solo álbumes activos + archivos sueltos en la raíz.
4. **Ficha de producto**: la portada sale en el HTML con `<link rel=preload>` (=s800). La galería completa
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

## Carpetas: ocultar y portada
- El admin apaga una carpeta en **Admin → Carpetas** → `folder_settings.hidden = 1` (en una de primer nivel también `albums.is_active = 0`).
- `DriveIndex::refreshFolderState()` (tras cada sync y cada cambio) marca `drive_index.is_hidden = 1` en la carpeta y en todo
  lo que tiene dentro, y elige para cada carpeta una foto **al azar** (pública, no oculta) de todo su contenido → `cover_file_id`.
  También vacía las portadas de productos que eran una foto oculta (el visor elige otra visible).
- Todo lo público filtra `is_hidden = 0`: `/carpeta/{id}`, carpetas de la búsqueda, galería (`filesForSku`) y el sync de portadas.

## Familias (padre ↔ variantes)
- **Una sola regla**: `computeParentSkuMap()` (raíz candidata + mismo nombre exacto, con contexto de hermanos).
  `recomputeParentSkus($db)` la aplica a todo el catálogo; la llaman el webhook de Sheets y la importación Excel.
  El sync completo de Sheets la aplica sobre la hoja.
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
- Lo que no cumple queda en `drive_index.matched_sku = NULL` (migración 020) y se descarga para renombrar:
  `/admin/media/sin-codigo.csv?tipo=archivos` y `?tipo=productos` (botones en Admin → Media).
- "Reasignar TODAS" (forzado) quita portadas guardadas que son una foto del índice de OTRO código.
- Variantes: `computeParentSkuMap()` agrupa por raíz candidata + mismo nombre → `parent_sku`.
  Un hijo ve sus archivos propios + los genéricos del padre (`isGenericMediaForSku`).

## Caché HTTP
- Assets (`.htaccess`): 1 año `immutable`; se invalida con `?v=APP_VERSION`.
- Landing `max-age=60`; ficha de producto: ETag + `private, max-age=60`; APIs con `jsonCachedResponse($data, ttl)`.

## Rendimiento
Ver `docs/RENDIMIENTO.md` (problemas conocidos y decisiones).
