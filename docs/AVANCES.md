# Avances — Viewfinder Kino Visor

Estado vivo del proyecto: decisiones vigentes y qué está hecho. Corto a propósito.
El "cómo funciona" está en `docs/ARQUITECTURA.md`; aquí solo el qué y el porqué.
**Regla: al terminar cada trabajo (PR fusionado), actualiza este archivo** (estado, decisiones, historial en 1 línea).

## Estado actual (2026-11-09)
- Producción: `viewfinder-kino-visor.up.railway.app` (Railway, deploy automático desde `main`).
  El entorno "PRUEBAS" (`…-pruebas.up.railway.app`) no cuenta: las variables se ponen en el entorno de producción.
- Drive raíz: env `GOOGLE_DRIVE_FOLDER_ID` = carpeta **PRODUCTOS**. Se indexan todos los niveles en `drive_index`.
- Hoja maestra: **"MAESTRA DE PRODUCTOS KINO"** (env `GOOGLE_SHEET_ID`), 3629 códigos. Revisión 2026-11-09: se lee
  completa (3629 de 3629, sin filas descartadas ni repetidas). En la hoja faltan datos: 124 filas sin PRICE_SUGGESTED (98 POEDAGAR)
  y 33 sin NAME; esas salen sin precio o con el código como nombre. Admin → Sincronizar muestra hoja vs "Solo Drive".
- Primer sync completo (2026-10-30): ~12 000 archivos, ~4400 portadas; ~600 productos sin foto (lista en Admin → Media).
- PRs #1–#26 fusionados.

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
- **Carpetas "archivado"** (2026-11-09): toda carpeta cuyo nombre diga archivado/archivados/archivada (sin importar
  mayúsculas ni tildes) queda oculta por defecto con todo su contenido, ya indexada o nueva. Si el admin la muestra, el
  sync no la vuelve a ocultar. Mientras está oculta tampoco crea productos "Solo Drive".
- **Sync automático** en cada deploy y cada hora (`scripts/sync_loop.sh`); `AUTO_SYNC=0` lo apaga.
- **Envío automático a un número** por WhatsApp no es posible desde la web: requeriría la API oficial de Meta (de pago).

## Funciones clave para el usuario
- Catálogo público en grilla densa (60 por página, una tarjeta por familia; contador "N referencias · M familias").
- Ficha de producto con variantes, galería, visor grande; WhatsApp + Descargar en cada foto.
- Compartir por WhatsApp con vista previa (foto, título, enlace) vía Open Graph (`/og/{id}.jpg`).
- "📤 Enviar catálogo" (público, `/enviar-catalogo`): pegar texto → códigos → galería de fotos (tocar = marcar/desmarcar).
  "💾 Guardar N en el celular" (Android/PC: descargas; iPhone: Compartir → Guardar imágenes) y luego se mandan desde
  WhatsApp (📎 → Galería, hasta 100). "📲 Enviar por tandas": las fotos bajan en segundo plano; el botón verde se activa cuando la
  tanda siguiente (10) está completa ("Preparando tanda 2 de 3: 6/10" mientras tanto). 📲 en cada foto = solo esa.
  Si una foto no baja (3 intentos), se desmarca, se avisa cuál y se sigue con las demás. Fotos a 800 px con copia en disco
  (`storage/send_cache`).
  Decisión del dueño 2026-11-09: ofrecer las dos vías; ir foto por foto era lento y el lote de 30+ fallaba.
- Descarga original (`/api/download`): si el token de Google falta, baja el archivo público de Drive.
  "Drive not connected" = reconectar en Admin → Media (caducó y se reconectó el 2026-11-09).
- Todo el visor y el admin se revisan a 320/360/375 px (celular): sin scroll lateral, letra legible, botones de dedo.
- Admin: Productos (activar/desactivar), Carpetas (ocultar), Sincronizar (hoja + Drive, "¿Por qué no sale la foto?"), Media.

## Pendiente / ideas
- Probar en Railway la vista previa de WhatsApp con enlaces nunca compartidos (WhatsApp cachea por URL).
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
- #19: Enviar catálogo: botón "Enviar todo el lote" (fotos marcadas juntas), marcar/desmarcar todos, "📲 Este".
- #20: repaso móvil: barra "Enviar por WhatsApp" ya no tapa la pantalla (y aparece en la galería de la ficha), encabezado sin
  choques, letra de tarjetas más grande, menú del admin completo en 2-3 filas, Enviar catálogo usable en el celular, error JS del inicio.
- #21: Enviar catálogo: botón 📲 por fila (comparte solo esa foto); se quitan los botones verdes de lote y "N de M".
- #22: Enviar catálogo en galería: guardar las marcadas en el celular + enviar por tandas de 20; fotos a 1200 px.
- #23: Enviar catálogo: tandas de 10 fotos por toque (antes 20), pedido del dueño.
- #24: Enviar catálogo: arreglo "Failed to fetch" y tandas sin esperas (comparte lo ya bajado), fotos a 800 px, caché en disco.
- #25: Enviar por tandas: el botón verde aparece solo cuando la siguiente tanda de 10 está lista (pedido del dueño).
- #26: ficha en el celular: la foto ya no sale corrida a la derecha con muchas fotos (la tira de miniaturas estiraba la columna);
  en el celular las miniaturas de la ficha van en cuadrícula (5 por fila, sin deslizar; tocar una sube a la foto grande).
  Enviar catálogo: las miniaturas muestran la foto entera (no recortada).
- #27: Admin → Sincronizar: última sincronización de la hoja con éxito (y último error) guardada en `sync_status`; conteo
  códigos de la hoja vs "Solo Drive", visibles, y filas de la hoja sin precio o sin nombre.
- #28: carpetas "archivado" ocultas por defecto (`isArchivedFolderName`, `DriveIndex::hideArchivedFolders` en cada sync); el
  mensaje de Sincronizar portadas dice cuántas hay y cuántas quedan ocultas.
