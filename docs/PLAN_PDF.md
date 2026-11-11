# Plan — Catálogo en PDF con textos del cliente

Propuesta para aprobar (2026-11-11). Solo documento: no se programa nada hasta que el dueño lo apruebe.
Un PR por etapa; cada PR actualiza `docs/AVANCES.md` (y `docs/ARQUITECTURA.md` si cambia cómo funciona algo).

## 1. Qué pidió el dueño
1. Crear un PDF con todas las referencias de un catálogo: imagen + código.
2. Debajo de cada referencia, un texto editable (precio u otra información).
3. Cada cliente personaliza esos textos antes de generar. Opcional: vacío = no sale nada.
4. El PDF se descarga o se comparte (p. ej. WhatsApp).
5. Se puede crear desde: una lista de productos, los resultados de la búsqueda por lista y el catálogo de la sección «Catálogo».

## 2. Qué ya existe y se reutiliza
| Pieza | Dónde | Uso en el PDF |
|---|---|---|
| Lista de fotos marcadas (`{id, sku}` en orden) | `wa_batch.js` → `window.KinoWaBatch`, `localStorage kinoWaSel` | Es la lista que entra al PDF. Inicio, búsqueda, carpetas, Novedades y ficha ya llenan esta lista. |
| Mi selección (ordenar, quitar, 1 foto por código) | `/mi-seleccion`, `my_selection.js` | Desde aquí se abre el armado del PDF. |
| Catálogos guardados | `localStorage kinoCatalogs` (etapa E) | Abrir un catálogo = cargarlo en Mi selección → PDF. Los textos se guardan con el catálogo. |
| Enviar catálogo por lista | `/enviar-catalogo`, `catalog_send.js`, botón "📌 Pasar a Mi selección" | Nuevo botón "📄 Crear PDF" con las marcadas. |
| Catálogo por enlace | `/c/{código}`, `shared_catalog.php` | Nuevo botón "📄 Crear PDF" con las fotos del enlace. |
| Fotos livianas 800 px con caché | `/enviar-catalogo/foto/{id}`, `fetchPhoto()` (3 intentos) | Las fotos del PDF salen de aquí (mismo dominio, sin problemas de permisos). |
| Compartir archivos | Web Share (`navigator.share({files})`) ya usado en las tandas | Mismo mecanismo para compartir el PDF a WhatsApp. |

Dónde sale «📄 Crear catálogo PDF» (decisión del dueño 2026-11-11): al tener una selección lista para enviar (barra de
envío de inicio, búsqueda, carpetas, Novedades y ficha, y en Mi selección junto a Enviar), en Enviar catálogo por lista y al
abrir un enlace creado desde la selección (`/c/…`). También en cada catálogo guardado.

## 3. Cómo se vería (para el cliente)
1. Con fotos marcadas (barra de envío o Mi selección), en Enviar catálogo o en un enlace `/c/…`, toca **📄 Crear catálogo PDF**.
2. Se abre la pantalla **Armar PDF** (`/catalogo-pdf`): una fila por referencia con su foto chica, el código y una caja
   de texto «Precio u otra información (opcional)». Arriba, opciones del documento:
   - Título (p. ej. «Relojes noviembre») y contacto (nombre/teléfono), ambos opcionales.
   - **Espacio para el logo del usuario** (opcional): «Subir mi logo» desde el celular. Si no sube nada, no sale logo
     (el PDF no lleva logo de Kino). El logo se guarda en el dispositivo para la próxima vez.
   - Fotos por página: 4, 6 (por defecto) o 9.
   - «Escribir lo mismo en todas» y «Solo en las vacías» (para poner un precio o texto común rápido).
3. Toca **Generar PDF**: barra de avance «Armando página 3 de 12». Al terminar, dos botones:
   **📲 Compartir** (abre WhatsApp y demás apps del celular) y **⬇️ Descargar**.
4. Los textos escritos se recuerdan en el dispositivo: la próxima vez, el mismo código ya trae su precio.

Diseño de cada página (A4 vertical, fondo blanco): cuadrícula de fotos; bajo cada foto, el código en negrita y, si hay,
el texto del cliente (hasta 3 líneas; si es más largo se corta con «…» y se avisa antes de generar).
Encabezado con logo (si lo subió), título y contacto en la primera página; pie con «Página N de M». Nombre del archivo: título + fecha.

## 4. Decisiones técnicas (recomendadas)
- **El PDF se arma en el celular/computador, no en el servidor.** Se usa la librería jsPDF (MIT, ~360 KB) guardada en
  `assets/vendor/` (sin CDN) y cargada solo en `/catalogo-pdf`. Motivos: reutiliza las fotos que ya bajan para WhatsApp,
  no carga al servidor de Railway ni tiene límites de tiempo, y **los precios del cliente nunca salen de su dispositivo**
  (cada distribuidor tiene los suyos). Alternativa descartada: PDF en PHP (Dompdf/TCPDF) — nueva dependencia de composer,
  el servidor tendría que bajar cientos de fotos por PDF y recibiría los precios de cada cliente.
- **Tamaño**: cada foto se reduce a ~600 px JPEG antes de entrar al PDF (~60-90 KB). 100 referencias ≈ 6-9 MB.
  Máximo 300 referencias por PDF (mismo límite que el catálogo por enlace). Se arma página por página para no llenar la memoria.
- **Logo**: se reduce a ~300 px en el dispositivo y se guarda en `localStorage kinoPdfLogo`; nunca se sube al servidor.
- **Textos**: `localStorage kinoPdfTexts` = `{código: texto}` (se recuerda por código) + opciones `kinoPdfOpts`.
  Al guardar un catálogo (etapa E) se guardan también sus textos. Nada se envía al servidor.
- **Letras**: la fuente estándar del PDF tiene tildes y ñ, pero no emojis; si el texto trae emojis se quitan y se avisa.
- **Videos**: no entran al PDF (igual que en las tandas de WhatsApp). Se avisa «N videos no se incluyen».
- **Fotos que no bajan**: 3 intentos (como hoy); si una falla, el PDF sale sin ella y se dice cuál.
- **Compartir**: Android y iPhone (Safari 15+) comparten el PDF directo a WhatsApp como documento. En el computador se
  descarga y se adjunta en WhatsApp Web. Si el celular no deja compartir archivos, queda solo Descargar.
- **Precio de la hoja**: no se rellena solo. Cada usuario escribe su precio al armar el PDF (decisión del dueño 2026-11-11).

## 5. Etapas (un PR cada una)
| Etapa | Qué incluye | Archivos principales |
|---|---|---|
| **P1. Armar PDF desde la selección** | Pantalla `/catalogo-pdf` (filas con foto, código, texto), opciones (título, contacto, logo opcional, 4/6/9 por página), «lo mismo en todas / solo vacías», generar con avance, Compartir y Descargar, textos recordados por código. Botón «📄 Crear catálogo PDF» en la barra de envío y en Mi selección. | `index.php` (ruta), `templates/client/catalog_pdf.php`, `assets/js/catalog_pdf.js`, `assets/vendor/jspdf.umd.min.js`, `wa_batch.js`, `my_selection.js`, CSS |
| **P2. Más entradas** | «📄 Crear catálogo PDF» al abrir un enlace `/c/…`, en Enviar catálogo (con las marcadas) y en cada catálogo guardado (abre con sus textos). La lista se pasa a `/catalogo-pdf` en el dispositivo sin tocar Mi selección. | `shared_catalog.php`, `catalog_send.js`, `my_selection.js` |
| **P3. Precios rápidos (opcional)** | Pegar una lista «código precio» (como en Enviar catálogo) y rellenar los textos de golpe; vista previa de la 1.ª página antes de generar. | `catalog_pdf.js` |

Pruebas de cada etapa: Android (Chrome), iPhone (Safari) y computador; catálogos de 1, 30 y 300 referencias; texto vacío
(no sale), texto largo, tildes/ñ; compartir a WhatsApp; celular a 320-375 px sin scroll lateral.

## 6. Decisiones del dueño (2026-11-11)
1. **Dónde**: al tener la selección lista para enviar (barra de envío y Mi selección) y al abrir el enlace `/c/…` creado
   desde la selección; también Enviar catálogo por lista y catálogos guardados.
2. **Precio**: lo escribe cada usuario al crear el PDF. No se rellena con el precio de la hoja.
3. **Logo**: no se pone logo de Kino; hay un espacio opcional para que el usuario suba el suyo.
4. **Fotos**: entran todas las fotos marcadas, en el orden de la lista.
