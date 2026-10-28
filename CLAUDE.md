# CLAUDE.md — Viewfinder Kino Visor

Catálogo para distribuidores: buscar por SKU y ver/descargar fotos y videos de Google Drive.
PHP 8.2 puro (sin framework) + MySQL, Docker en Railway. Idioma del código y la UI: español.

**Lee primero `docs/ARQUITECTURA.md`** (mapa de archivos, rutas, tablas y flujo de portadas).
Así no hace falta abrir los archivos grandes para orientarte.

## Reglas rápidas
- Router único: `index.php` (if/preg_match por ruta → `include` de un controller). No hay autoload.
- Helpers compartidos: `src/helpers.php`. Drive (REST + OAuth sin SDK): `src/services/GoogleDriveService.php`.
- Cambios de esquema SOLO en `migrations/NNN_nombre.sql` (se ejecutan al arrancar el contenedor con `migrate.php`).
  Nunca `ALTER TABLE` en una petición web.
- Las páginas públicas NO deben llamar a la API de Drive en vivo. Leen MySQL (`products.cover_image_url`,
  `media_search_cache`). Las llamadas a Drive van en admin/cron.
- Portada guardada = `lh3Url($fileId)` → `https://lh3.googleusercontent.com/d/{fileId}=s400` (estable). Prefijo `[VIDEO]` = video;
  decodifica con `parseStoredCover()`.
  No guardes `thumbnailLink` de la API de Drive en `products`: caduca.
- Carpeta raíz de Drive: env `GOOGLE_DRIVE_FOLDER_ID`. Subcarpetas de primer nivel = álbumes (marcas).
  Foto ↔ producto: el nombre del archivo empieza por el SKU (`skuMatchesFilename`, `findBySku`).
- Variantes: `products.parent_sku` (familia). Raíz: `getFamilyRootSku()` → `extractRootSku()`.

## Archivos grandes (no leer enteros si no hace falta)
`assets/css/style.css` (~3000 líneas), `templates/admin/media.php` (~1200), `src/controllers/product.php` (~1000),
`templates/client/landing.php` (~950), `src/controllers/search.php` (~900). Usa `grep -n` y lee rangos.
`Auditoria_Tecnica_ViewfinderKino.docx` y `VISOR KINO.txt` son notas antiguas, no código.

## Verificar
- Sintaxis: `find . -name '*.php' -not -path './vendor/*' -exec php -l {} \; | grep -v 'No syntax errors'`
- No hay tests automáticos ni CI. Probar en Railway tras el merge (deploy automático desde `main`).
