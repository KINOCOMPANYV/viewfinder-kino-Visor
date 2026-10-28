# Rendimiento — reglas y decisiones

Objetivo: que navegar por el catálogo (inicio, búsqueda, ficha) solo lea MySQL y sirva imágenes ligeras de lh3.

## Reglas
1. **Nada de Drive en vivo al navegar.** Las páginas y APIs públicas leen `drive_index` (ver ARQUITECTURA.md).
   El único que recorre Drive es el sync (`admin_sync_covers.php` → `DriveIndex::sync`). El camino viejo
   (Drive en vivo, máx. 5 SKUs por petición) solo corre si el índice está vacío.
2. **Caché negativa** (camino viejo): `fetchAndCacheMediaForSku()` guarda también el resultado vacío ("sin foto") para que ese SKU
   no vuelva a consultar Drive en cada visita. Si Drive falló (`GoogleDriveService::$lastRequestFailed`), no se guarda.
3. **URLs de imagen estables**: `https://lh3.googleusercontent.com/d/{id}=sNNN` (el archivo se hace público).
   No cachees `thumbnailLink` de la API de Drive: caduca en horas. Helpers: `lh3Url($id, $size)` construye,
   `coverUrlAtSize($url, $size)` cambia el tamaño y `parseStoredCover($stored, $size)` decodifica `[VIDEO]` + tamaño.
4. **Tamaño justo**: tarjetas `=s400`, chips de variantes `=s150`, vista principal `=s800`.
5. **Sin DDL en peticiones**: nada de `ALTER TABLE` / `CREATE INDEX` en controllers públicos; van en `migrations/`.
   `migrate.php` corre cada sentencia por separado y tolera "ya existe" (errores 1050/1060/1061/1091).
   MySQL 8 no soporta `ADD COLUMN IF NOT EXISTS`; no lo uses.
6. **Timeouts de cURL** en toda llamada a Google (connect 5 s, total 10-20 s). Para GETs usa los métodos de
   `GoogleDriveService` (`httpGet` ya los trae; `getFileMetadata()` para metadatos), no `curl_init` suelto.
7. **JS pesado bajo demanda**: html5-qrcode se descarga solo al abrir el escáner (`qr-scanner.js`).
8. **Un solo cargador de portadas por página**: el JS que llama a `/api/covers/batch` existe una vez por plantilla.

## Servidor
- OPcache activo (`Dockerfile`, `validate_timestamps=0`: el código solo cambia con un deploy nuevo).
- Assets con `?v=APP_VERSION` y caché de 1 año (`.htaccess`).

## Vigencias de caché
| Qué | Dónde | Vigencia |
|---|---|---|
| Índice de Drive | `drive_index` | hasta el próximo sync (cron `sync-covers`) |
| Galería de un SKU | `/api/media/{sku}` | HTTP 60 s (índice) · 30 min (camino viejo) |
| Portadas camino viejo | `/api/covers/batch`, `product.php` | `COVER_CACHE_TTL_HOURS` (6 h), "sin foto" 30 min |

## Pendiente / ideas
- `visible_publico` vive en `drive_cache` (tabla de caché): limpiar esa caché conserva las filas ocultas,
  pero lo ideal es una tabla propia de visibilidad.
- Unificar `drive_index` y `drive_cache` (hoy `filterHiddenFiles` hace una consulta aparte a `drive_cache`).
- `admin_media.php` y `admin_sync_sheets.php` aún usan `listAllMediaFiles` (recorrido en vivo); podrían leer `drive_index`.
- `/api/covers/batch` con índice hace ~4 consultas por SKU (máx. 50); se puede agrupar si hiciera falta.
- Una vez que el índice esté siempre lleno, borrar el camino viejo (`media_search_cache`, `fetchAndCacheMediaForSku`).
- Columna normalizada de SKU (sin guiones) indexada, para no usar `REPLACE(...) LIKE` (escaneo completo) en las búsquedas.
