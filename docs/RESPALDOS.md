# Respaldos de MySQL y prueba de restauración

## Qué se respalda
- Cada madrugada (desde las 3:00 Colombia, `BACKUP_DB_HOUR`) el sync sube `visor-mysql-AAAA-MM-DD.sql.gz` a la carpeta de Drive
  «Respaldos pedidos bodega (visor)» (o `PEDIDOS_BACKUP_FOLDER_ID`). Quedan los últimos 14 (`BACKUP_KEEP`).
- Ahora mismo: Admin → 🗄 Pedidos → «Respaldar toda la base ahora», o `php scripts/cli_sync.php respaldobd ya`.
- Trae todas las tablas con sus datos, salvo `google_tokens` (claves de Google: se reconecta en Admin → Media) y las cachés
  (`drive_cache`, `media_search_cache`). Un fallo sale en Admin → 🧾 Registro y en los logs de Railway.
- Formato: una sentencia por línea; al inicio `-- COUNT tabla n` (filas esperadas) y al final `-- FIN`.

## Probar la restauración (nunca en la base de producción)
1. Baja el `.sql.gz` más nuevo de Drive.
2. Crea una base vacía de pruebas (MySQL local, o un MySQL nuevo en Railway; NO la de producción).
3. Ejecuta:
   ```
   RESTORE_DB_HOST=127.0.0.1 RESTORE_DB_NAME=vk_restore RESTORE_DB_USER=root RESTORE_DB_PASSWORD=... \
   php scripts/restore_backup.php visor-mysql-2026-11-24.sql.gz
   ```
4. Debe decir «✅ Restauración correcta» (filas por tabla = las del archivo). El script se niega si la base destino es la del visor.
5. Prueba humana: `RESTORE_DB_NAME` como `DB_DATABASE` en un `php -S localhost:8080` y abre el visor; revisa un pedido y un precio editado.
Repetir la prueba una vez al mes y tras cambios grandes de tablas.

## Restaurar de verdad (emergencia)
Con el visor detenido (o `AUTO_SYNC=0`), restaura en una base nueva con el paso 3, apunta `MYSQL_URL` a ella y reconecta Google en Admin → Media.
Probado 2026-11-23 en local: tablas con BLOB, saltos de línea, comillas y `\` volvieron idénticos (misma suma MD5).
