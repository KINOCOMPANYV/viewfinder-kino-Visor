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
| `/` | `templates/client/landing.php` | Álbumes + "Productos recientes" (10 familias/página) |
| `/buscar` (`?q=`, `?album=`, `?ajax=1`) | `src/controllers/search.php` | Grid de familias (20/página), multi-código, carpetas |
| `/producto/{sku}` | `src/controllers/product.php` | Ficha; galería se carga por JS desde `/api/media/{sku}` |
| `/carpeta/{id}` | `src/controllers/folder_view.php` | Lista una carpeta de Drive en vivo |
| `/api/search` | `src/controllers/api_search.php` | Autocomplete JSON |
| `/api/batch-search` POST | `src/controllers/api_batch_search.php` | Búsqueda por lote (modal) |
| `/api/covers/batch` POST | inline en `index.php` | Portadas para SKUs sin `cover_image_url` |
| `/api/media/{sku}` | inline en `index.php` | Archivos de Drive de un SKU (galería) |
| `/api/download/{fileId}` | inline en `index.php` | Proxy de descarga autenticado |
| `/api/drive-folders` | inline en `index.php` | Álbumes JSON |
| `/api/cron/sync-covers?token=` | `admin_sync_covers.php` | Escanea álbumes y asigna portada + álbum |
| `/api/cron/precache-drive?token=` | `api_precache_drive.php` | Calienta `media_search_cache` (30 SKUs/llamada) |
| `/api/webhook/sheets` POST | `webhook_sheets.php` | Sync incremental desde Apps Script |
| `/admin/*` | `src/controllers/admin_*.php` + `templates/admin/*` | Panel (login, productos, media, álbumes, import, sheets, caché) |

## Tablas
- `products`: `sku` (único), `parent_sku` (familia), `name`, `category`, `gender`, `cover_image_url`,
  `album_id` (→ `albums.drive_id`), `archived`, `sheet_row` (orden de la hoja), `sheet_hash`.
- `albums`: carpetas de primer nivel bajo la raíz (`drive_id`, `name`, `icon_url`, `is_active`, `order_priority`).
- `media_search_cache`: `sku` → `files_json` (resultado de buscar en Drive), `root_sku`, `cached_at`.
- `drive_cache`: hoy solo guarda la visibilidad por archivo (`visible_publico`) y subcarpetas para la búsqueda.
- `google_tokens`: OAuth (último registro = activo; `getValidToken()` renueva solo).
- Otras: `migrations`, `task_queue`, `media_assets` y `downloads_log` (heredadas, poco usadas).

## Flujo de portadas (cómo llega la foto a la tarjeta)
1. **Guardada en BD** (camino rápido): `products.cover_image_url` = `https://lh3.googleusercontent.com/d/{id}=s400`.
   Se pinta directo en el HTML (landing/search). Las 3 primeras con `loading=eager` y el resto `lazy`.
2. **Sin portada** → placeholder 📷 y el JS llama `POST /api/covers/batch` con los SKUs que faltan.
   Este endpoint lee `media_search_cache`; si no hay caché busca en Drive (`fetchAndCacheMediaForSku`) y,
   si encuentra imagen, **auto-asigna** `cover_image_url` para la próxima visita.
3. **Asignación masiva**: admin "Sincronizar portadas" o el cron `sync-covers` recorren cada álbum
   (`listAlbumMediaRecursive`) y asignan portada y álbum por coincidencia de SKU en el nombre del archivo.
   Puntaje: `scoreCoverCandidate()` (la imagen gana al video; "principal/cover/01/-1/f1" suman).
4. **Ficha de producto**: la portada sale en el HTML con `<link rel=preload>` (=s800). La galería completa
   llega por JS desde `/api/media/{sku}` (caché 30 min en `media_search_cache`).

Tamaños de lh3: `=s120` chips, `=s150/200` miniaturas, `=s400` tarjetas, `=s600/800` vista principal, `=s1200` compartir.

## Coincidencia SKU ↔ archivo
- `GoogleDriveService::findBySku()`: `name contains 'SKU'` en Drive + filtro estricto (después del SKU tiene que venir
  un separador, no una letra ni un número).
- `skuMatchesFilename()` / `matchFileToProduct()` (en sync-covers): prefijo; el siguiente carácter no puede ser un dígito.
- Variantes: `computeParentSkuMap()` agrupa por raíz candidata + mismo nombre → `parent_sku`.
  Un hijo ve sus archivos propios + los genéricos del padre (`isGenericMediaForSku`).

## Caché HTTP
- Assets (`.htaccess`): 1 año `immutable`; se invalida con `?v=APP_VERSION`.
- Landing `max-age=60`; ficha de producto: ETag + `private, max-age=60`; APIs con `jsonCachedResponse($data, ttl)`.

## Rendimiento
Ver `docs/RENDIMIENTO.md` (problemas conocidos y decisiones).
