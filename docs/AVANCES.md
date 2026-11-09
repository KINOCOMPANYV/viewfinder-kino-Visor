# Avances — Viewfinder Kino Visor

Estado vivo del proyecto: decisiones vigentes y qué está hecho. Corto a propósito.
El "cómo funciona" está en `docs/ARQUITECTURA.md`; aquí solo el qué y el porqué.
**Regla: al terminar cada trabajo (PR fusionado), actualiza este archivo** (estado, decisiones, historial en 1 línea).

## Estado actual (2026-11-09)
- Producción: `viewfinder-kino-visor.up.railway.app` (Railway, deploy automático desde `main`).
  El entorno "PRUEBAS" (`…-pruebas.up.railway.app`) no cuenta: las variables se ponen en el entorno de producción.
- Drive raíz: env `GOOGLE_DRIVE_FOLDER_ID` = carpeta **PRODUCTOS**. Se indexan todos los niveles en `drive_index`.
- Hoja maestra: **"MAESTRA DE PRODUCTOS KINO"** (env `GOOGLE_SHEET_ID`), ~3600 códigos.
- Primer sync completo (2026-10-30): ~12 000 archivos, ~4400 portadas; ~600 productos sin foto (lista en Admin → Media).
- PRs #1–#18 fusionados. No hay PRs abiertos.

## Decisiones vigentes (del dueño)
- **Foto ↔ producto**: el nombre del archivo es el código (o código + separador + lo que sea). "M:", "M" y "M-" son el mismo
  código. El nombre de la carpeta no cuenta. Lo que no coincide se lista para renombrar (Admin → Media, CSV).
- **Un solo flujo de enlace**: `DriveIndex::relink()` → `drive_index.matched_sku`. Portada: propia > familia > video. Todo lo
  público (galería, tarjetas, ficha, envíos) lee ese enlace. Nada de Drive en vivo al navegar.
- **Productos "Solo Drive"**: una foto cuyo nombre es un código que no está en la hoja crea el producto (nombre = código,
  marca = carpeta, sin precio). Excluidos: `IMG_`/WhatsApp, `.psd`, nombres sin dígitos, carpetas DESACTIVADOS.
  `DRIVE_PRODUCTS=0` los apaga.
- **Activación**: solo el switch del admin (`is_active`). La hoja nunca reactiva. No se borra nada, solo se desactiva.
- **Carpetas**: se navegan como en Drive (ya no se muestran "álbumes"). Cada carpeta tiene switch mostrar/ocultar y portada al azar.
- **Sync automático** en cada deploy y cada hora (`scripts/sync_loop.sh`); `AUTO_SYNC=0` lo apaga.
- **Envío automático a un número** por WhatsApp no es posible desde la web: requeriría la API oficial de Meta (de pago).

## Funciones clave para el usuario
- Catálogo público en grilla densa (60 por página, una tarjeta por familia; contador "N referencias · M familias").
- Ficha de producto con variantes, galería, visor grande; WhatsApp + Descargar en cada foto.
- Compartir por WhatsApp con vista previa (foto, título, enlace) vía Open Graph (`/og/{id}.jpg`).
- "📤 Enviar catálogo" (público, `/enviar-catalogo`): pegar texto → códigos → fotos → enviar una por una.
- Descarga original (`/api/download`): si el token de Google falta, baja el archivo público de Drive.
  "Drive not connected" = reconectar en Admin → Media (caducó y se reconectó el 2026-11-09).
- Admin: Productos (activar/desactivar), Carpetas (ocultar), Sincronizar (hoja + Drive, "¿Por qué no sale la foto?"), Media.

## Pendiente / ideas
- Probar en Railway la vista previa de WhatsApp con enlaces nunca compartidos (WhatsApp cachea por URL).
- En el inicio había un error JS ("Cannot read properties of null (reading 'addEventListener')"); revisar si sigue.
- Ideas técnicas en `docs/RENDIMIENTO.md` → "Pendiente / ideas".

## Historial (1 línea por PR)
- #1–#7 (oct 2026): carpetas como en Drive (todos los niveles), códigos con prefijo M:/M/M-, búsqueda tolerante,
  hoja maestra como única fuente + activación solo en admin, familias bajo el código -1, visor responsive.
- #8: migración única que activa todos los productos; contador referencias/familias.
- #9: enlace único foto ↔ producto (`DriveIndex::relink`, `matched_sku`).
- #10: sync automático en deploy + cada hora.
- #11: productos "Solo Drive" desde archivos de Drive.
- #12: catálogo en grilla densa. #14: ficha en móvil sin bloque vacío.
- #13: compartir por WhatsApp con vista previa; WhatsApp + Descargar en cada foto.
- #15: módulo público "Enviar catálogo por lista".
- #16: descarga con respaldo público si Drive está desconectado.
- #17: contador de carpetas ocultas igual que la lista (no borra ajustes viejos).
- #18: este archivo de avances + regla de actualizarlo (CLAUDE.md).
