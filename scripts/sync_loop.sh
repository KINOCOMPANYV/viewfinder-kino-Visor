#!/bin/bash
# Sincronización automática: hoja maestra y luego portadas (Drive + enlace foto ↔ producto).
# Corre al arrancar el contenedor (tras el deploy) y cada SYNC_INTERVAL_MINUTES (60 por defecto).
# AUTO_SYNC=0 en Railway la apaga. Los resultados salen en los logs del deploy.
[ "${AUTO_SYNC:-1}" = "0" ] && exit 0
cd /var/www/html || exit 0
sleep 20 # dejar que Apache arranque primero
# Búsqueda por foto: su propio ciclo cada 5 min (no espera la hora ni a la hoja/Drive). Prepara las portadas
# nuevas (la primera vez, todo el catálogo en varias vueltas); sin pendientes termina en un segundo.
( while true; do
    php scripts/cli_sync.php imagenes 2>&1 | cut -c1-2000
    sleep 300
done ) &
while true; do
    php scripts/cli_sync.php carpetas 2>&1 | cut -c1-2000
    php scripts/cli_sync.php hoja 2>&1 | cut -c1-2000
    php scripts/cli_sync.php portadas 2>&1 | cut -c1-2000
    sleep $(( ${SYNC_INTERVAL_MINUTES:-60} * 60 ))
done
