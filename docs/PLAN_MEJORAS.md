# Plan de mejoras — Viewfinder para distribuidores

Propuesta para aprobar (2026-11-10). Objetivo: que el distribuidor arme catálogos y material de venta de relojes
con menos pasos, rápido y en el celular. Un PR por etapa; cada PR actualiza `docs/AVANCES.md`.
Este plan reemplaza las etapas 5 y 6 del plan UX anterior (se incluyen aquí: etapas B y J).

## 1. Qué ya está hecho (no se repite)

| # | Idea | Estado | Qué hay hoy / qué falta |
|---|---|---|---|
| 1 | Rapidez y continuidad | ✅ casi todo | #34: páginas 2-28 ms, fotos 200/400 px, Atrás instantáneo (bfcache). Falta solo medir en Railway. |
| 2 | Búsqueda precisa | 🟡 parcial | Código exacto primero y luego "empieza por" (ya ordena así), ignora `- : .` y espacios, ✕ para borrar (#35), resultados al escribir. Falta: sugerencias por error de escritura. |
| 3 | Filtros | 🟡 parcial | Solo chips de categoría en la búsqueda. Faltan marca, precio y "con foto". |
| 4 | Selección unificada | 🟡 casi | Inicio, búsqueda, carpetas y ficha usan la misma barra (#31-#36). "Enviar catálogo" tiene su propia selección. Falta aclarar "Marcar todas (esta vista)". |
| 5 | Vista "Mi selección" | ❌ | No existe. |
| 6 | Descarga y envío confiables | ✅ | Tandas de 10, precarga al marcar, 3 reintentos solo de las que fallan, botón verde cuando la tanda está lista, guardar en el celular (#22-#25, #36). |
| 7 | Recuperar trabajo interrumpido | 🟡 parcial | Lo marcado vive en la pestaña (`sessionStorage`): se pierde al cerrarla. Falta guardarlo en el dispositivo. |
| 8 | Variantes fáciles de identificar | ✅ base | Familias, variantes en la ficha, código en cada foto. Revisar solo si el uso real muestra fallas. |
| 9 | Catálogos guardados | ❌ | No existe. |
| 10 | Información del producto | 🟡 parcial | Ficha con datos de la hoja; Admin → Sincronizar lista filas sin precio/nombre (#27). Stock: no hay fuente confiable → no se muestra. |
| 11 | Material personalizado | ❌ | No existe. |
| 12 | Catálogo por enlace | ❌ | No existe. |
| 13 | Novedades | ❌ | El índice no guarda cuándo llegó cada archivo. |
| 14 | Referencias relacionadas | ❌ | No existe (prioridad baja). |
| 15 | Panel de problemas de fotos | 🟡 parcial | "¿Por qué no sale la foto?", CSV de archivos sin código y productos sin foto (Admin → Media). Están repartidos. |
| 16 | Control de sincronización | ✅ etapa A (#39) | Antes: Drive no guardaba último éxito/error y solo la copia del índice tenía candado (hoja, enlace y portadas podían correr a la vez). |
| 17 | App instalable | ⏸ pausada | Era la etapa 5 del plan anterior; sigue pausada hasta que digas "continuamos". |

## 2. Qué propongo quitar (no aporta o está repetido)
- Modal "Buscar lote" del inicio (`/api/batch-search`, `landing.js`): hace lo mismo que "📤 Enviar catálogo".
- Restos del sistema viejo de Drive en vivo: `media_search_cache`, `drive_cache`, `task_queue`, `downloads_log`,
  `media_assets`, cron `precache-drive` (alias), Admin → Álbumes y Caché, `sessionStorage` de "último video/álbum" en la ficha.
  Se quita solo lo que se compruebe sin uso (con `grep`), sin borrar tablas con datos hasta confirmar.
- Chips de categoría client-side (`category-filters.js`): los reemplazan los filtros nuevos (etapa B).

## 3. Etapas (en orden)

**Ruta de prueba común** (antes y después de cada etapa, en el celular): buscar una referencia → abrir su ficha y marcar
2 variantes → ir a 2 carpetas distintas y marcar fotos → revisar lo marcado → preparar y mandar una tanda.

### A. Sincronización segura (idea 16) — ✅ hecha (#39) — primero, porque afecta las fotos que recibe el distribuidor
- Candado (`GET_LOCK` de MySQL) para hoja y Drive: si ya hay un sync corriendo, el otro espera o avisa.
- Guardar último éxito/error de Drive igual que la hoja; Admin → Sincronizar muestra los dos por separado,
  con lo que cambió (archivos nuevos/borrados, portadas) y un botón "Reintentar" en el que falló.
- Prueba: Admin → Sincronizar, pulsar dos veces seguidas → el segundo dice "ya está corriendo"; ver fecha de Drive.

### B. Búsqueda y filtros (ideas 2 y 3; = etapa 6 anterior) — ✅ hecha (#40)
- Si no hay resultados: "¿Quisiste decir…?" con los códigos más parecidos (sin servicio externo).
- Filtros en servidor, combinables y en la URL (Atrás los conserva): marca, rango de precio, "solo con foto",
  y categoría/género/movimiento solo si tienen datos suficientes. Chips con ✕ y "Quitar filtros".
- Prueba: escribir "8363" mal escrito → sugerencia; filtrar POEDAGAR + con foto + precio → abrir ficha → Atrás conserva filtros.

### C. "Mi selección" y no perder el trabajo (ideas 4, 5, 7) — ✅ hecha (#41)
- Lo marcado pasa a `localStorage`: sobrevive al cerrar la página o quedarse sin señal.
- Pantalla `/mi-seleccion`: fotos marcadas con su código, quitar, reordenar (arrastrar / ↑↓), sin duplicados,
  estado por foto (lista / falló). Desde ahí: Guardar y Enviar por tandas (mismo `wa_batch.js`).
- "Enviar catálogo" usa la misma selección. Botón dice "Marcar todas (esta vista)".
- Recordar la última tanda abierta y permitir repetirla ("tanda preparada", no "entregada": la web no sabe si WhatsApp la envió).
- Prueba: ruta común, cerrar el navegador, volver → todo sigue marcado; reordenar y mandar desde Mi selección.

### D. Limpieza de código (sección 2) — ✅ hecha (#42)
- Quitar lo de la sección 2 tras comprobar que no se usa. Sin cambios visibles.
- Prueba: ruta común completa + admin (Sincronizar, Media, Carpetas) siguen igual.

### E. Catálogos guardados (idea 9) — ✅ hecha (#42)
- En Mi selección: "Guardar como…" («Poedagar dorados»), lista para abrir, duplicar, renombrar, actualizar y borrar.
- Se guardan **en este dispositivo** (se dice claro en pantalla). Si un código ya no está visible, se marca.
- Prueba: guardar 2 catálogos, cerrar, abrir uno, quitarle una foto y actualizarlo.

### F. Fotos con código (idea 11) — ✅ hecha (#42), sin precio por decisión del dueño
- Antes de guardar/enviar, elegir: foto sola / con código / con código + precio sugerido / con precio propio.
- Vista previa de 1 foto; se generan en el celular (canvas), con un diseño legible y uniforme.
- Opcional en una segunda parte: nombre, logo y contacto del distribuidor (guardados en el dispositivo).
- Prueba: 5 fotos con código + precio → vista previa → guardar en el celular → revisar en la galería.

### G. Panel de problemas de fotos + datos incompletos (ideas 15 y 10) — ✅ hecha (#42; "asociaciones incorrectas" no se detectan solas: se revisan con ¿Por qué?)
- Una página admin "Problemas": productos sin foto, archivos sin código, fotos ocultas por carpeta y filas de la hoja
  sin precio/nombre, con motivo, ruta en Drive y CSV. Reutiliza `photo-check` y los CSV actuales (no se duplica lógica).
- Prueba: abrir el panel, buscar un código sin foto, ver el motivo y la ruta.

### H. Novedades (idea 13) — ✅ hecha (#42)
- Migración: `drive_index.first_seen_at` (cuando el sync ve el archivo por primera vez; re-sincronizar no lo cambia).
- Sección "Nuevo" (últimos 7/30 días) con "Marcar todas" para sumarlas a la selección.
- Prueba: subir una foto a Drive, esperar el sync → sale en Nuevo; re-sincronizar → no se duplica.

### I. Catálogo por enlace (idea 12)
- Desde Mi selección: "Crear enlace" → página ligera `/c/{código}` con las fotos en orden (tabla nueva por migración).
- Se puede desactivar. Muestra siempre la foto vigente del código (si cambia en Drive, se actualiza sola).
  Contacto solo si el distribuidor lo escribe.
- Prueba: crear enlace, abrirlo en otro celular, desactivarlo → deja de abrir.

### J. App instalable (idea 17; = etapa 5 pausada)
- Solo cuando digas "continuamos". Icono, abre como app, aviso "sin conexión: datos de la última visita".

## 4. Lo que no haría por ahora
- Stock en pantalla (idea 10): no hay una fuente actualizada y confiable.
- Referencias relacionadas (idea 14): poco valor frente al resto; se puede sumar a la ficha al final si se pide.
- Cambios a variantes (idea 8): solo si la ruta de prueba muestra un caso real mal agrupado.
- Envío automático por WhatsApp: necesita la API de pago de Meta (decisión ya tomada).
