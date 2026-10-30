#!/bin/bash
# Sincronización automática: hoja maestra y luego portadas (Drive + enlace foto ↔ producto).
# Corre al arrancar el contenedor (tras el deploy) y cada SYNC_INTERVAL_MINUTES (60 por defecto).
# AUTO_SYNC=0 en Railway la apaga. Los resultados salen en los logs del deploy.
[ "${AUTO_SYNC:-1}" = "0" ] && exit 0
cd /var/www/html || exit 0
sleep 20 # dejar que Apache arranque primero
while true; do
    php scripts/cli_sync.php hoja 2>&1 | cut -c1-2000
    php scripts/cli_sync.php portadas 2>&1 | cut -c1-2000
    sleep $(( ${SYNC_INTERVAL_MINUTES:-60} * 60 ))
done
