# CLAUDE.md — Viewfinder Kino Visor

Catálogo para distribuidores: buscar por SKU y ver/descargar fotos y videos de Google Drive.
PHP 8.2 puro (sin framework) + MySQL, Docker en Railway. Idioma del código y la UI: español.

**Lee primero `docs/AVANCES.md`** (estado actual, decisiones del dueño, historial de PRs) y luego
`docs/ARQUITECTURA.md` (mapa de archivos, rutas, tablas y flujo de portadas). Así no hace falta abrir los archivos grandes.

**Al terminar cada trabajo, actualiza `docs/AVANCES.md`** (estado, decisión nueva, 1 línea en el historial) en el mismo PR.
Si cambias cómo funciona algo, actualiza también `docs/ARQUITECTURA.md`. Mantén ambos cortos: viñetas, sin relleno.

## Reglas rápidas
- Router único: `index.php` (if/preg_match por ruta → `include` de un controller). No hay autoload.
- Helpers compartidos: `src/helpers.php`. Drive (REST + OAuth sin SDK): `src/services/GoogleDriveService.php`.
- Cambios de esquema SOLO en `migrations/NNN_nombre.sql` (se ejecutan al arrancar el contenedor con `migrate.php`).
  Nunca `ALTER TABLE` en una petición web.
- Las páginas públicas NO deben llamar a la API de Drive en vivo. Leen MySQL: `products.cover_image_url` y
  `drive_index` (copia local del árbol de Drive, `src/services/DriveIndex.php`). Solo el sync (admin/cron) recorre Drive.
- Portada guardada = `lh3Url($fileId)` → `https://lh3.googleusercontent.com/d/{fileId}=s400` (estable). Prefijo `[VIDEO]` = video;
  decodifica con `parseStoredCover()`.
  No guardes `thumbnailLink` de la API de Drive en `products`: caduca.
- Carpeta raíz de Drive: env `GOOGLE_DRIVE_FOLDER_ID` (todos los niveles; se muestran como carpetas, no álbumes).
  Foto ↔ producto: el nombre del archivo es el código; enlace único `DriveIndex::relink()` → `drive_index.matched_sku`.
- Variantes: `products.parent_sku` (familia). Raíz: `getFamilyRootSku()` → `extractRootSku()`.
- Datos de productos: hoja maestra (`MasterSheet`) + productos "Solo Drive" (`from_drive`, `DRIVE_PRODUCTS=0` los apaga).
  Activar/desactivar: SOLO el admin (`is_active`).
  Público = `archived = 0 AND cover_image_url > ''` (archived lo calcula `refreshProductVisibility()`; sin portada no se muestra). No se borran productos.
- Carpetas ocultas: `folder_settings` → `drive_index.is_hidden` (y `cover_file_id`) vía `DriveIndex::refreshFolderState()`.

## Archivos grandes (no leer enteros si no hace falta)
`assets/css/style.css` (~3000 líneas), `templates/admin/media.php` (~1200), `src/controllers/product.php` (~1000),
`templates/client/landing.php` (~950), `src/controllers/search.php` (~900). Usa `grep -n` y lee rangos.
Las notas antiguas (`Auditoria_Tecnica_ViewfinderKino.docx`, `VISOR KINO.txt`) se quitaron el 2026-11-12; siguen en el historial de git.

## Verificar
- Sintaxis: `find . -name '*.php' -not -path './vendor/*' -exec php -l {} \; | grep -v 'No syntax errors'`
- No hay tests automáticos ni CI. Probar en Railway tras el merge (deploy automático desde `main`).
