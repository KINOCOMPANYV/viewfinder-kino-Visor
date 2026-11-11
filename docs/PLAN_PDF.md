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

Lectura que propongo de «la sección Catálogo»: los catálogos guardados de Mi selección y el catálogo por enlace (`/c/…`).
Lo que se marca en el catálogo público (inicio, búsqueda, carpetas) ya llega a Mi selección, así que también queda cubierto.

## 3. Cómo se vería (para el cliente)
1. En Mi selección, Enviar catálogo o un enlace `/c/…`, toca **📄 Crear PDF**.
2. Se abre la pantalla **Armar PDF** (`/catalogo-pdf`): una fila por referencia con su foto chica, el código y una caja
   de texto «Precio u otra información (opcional)». Arriba, opciones del documento:
   - Título (p. ej. «Relojes noviembre») y contacto (nombre/teléfono), ambos opcionales.
   - Fotos por página: 4, 6 (por defecto) o 9.
   - «Escribir lo mismo en todas» y «Solo en las vacías» (para poner un precio o texto común rápido).
3. Toca **Generar PDF**: barra de avance «Armando página 3 de 12». Al terminar, dos botones:
   **📲 Compartir** (abre WhatsApp y demás apps del celular) y **⬇️ Descargar**.
4. Los textos escritos se recuerdan en el dispositivo: la próxima vez, el mismo código ya trae su precio.

Diseño de cada página (A4 vertical, fondo blanco): cuadrícula de fotos; bajo cada foto, el código en negrita y, si hay,
el texto del cliente (hasta 3 líneas; si es más largo se corta con «…» y se avisa antes de generar).
Encabezado con título y contacto en la primera página; pie con «Página N de M». Nombre del archivo: título + fecha.

## 4. Decisiones técnicas (recomendadas)
- **El PDF se arma en el celular/computador, no en el servidor.** Se usa la librería jsPDF (MIT, ~360 KB) guardada en
  `assets/vendor/` (sin CDN) y cargada solo en `/catalogo-pdf`. Motivos: reutiliza las fotos que ya bajan para WhatsApp,
  no carga al servidor de Railway ni tiene límites de tiempo, y **los precios del cliente nunca salen de su dispositivo**
  (cada distribuidor tiene los suyos). Alternativa descartada: PDF en PHP (Dompdf/TCPDF) — nueva dependencia de composer,
  el servidor tendría que bajar cientos de fotos por PDF y recibiría los precios de cada cliente.
- **Tamaño**: cada foto se reduce a ~600 px JPEG antes de entrar al PDF (~60-90 KB). 100 referencias ≈ 6-9 MB.
  Máximo 300 referencias por PDF (mismo límite que el catálogo por enlace). Se arma página por página para no llenar la memoria.
- **Textos**: `localStorage kinoPdfTexts` = `{código: texto}` (se recuerda por código) + opciones `kinoPdfOpts`.
  Al guardar un catálogo (etapa E) se guardan también sus textos. Nada se envía al servidor.
- **Letras**: la fuente estándar del PDF tiene tildes y ñ, pero no emojis; si el texto trae emojis se quitan y se avisa.
- **Videos**: no entran al PDF (igual que en las tandas de WhatsApp). Se avisa «N videos no se incluyen».
- **Fotos que no bajan**: 3 intentos (como hoy); si una falla, el PDF sale sin ella y se dice cuál.
- **Compartir**: Android y iPhone (Safari 15+) comparten el PDF directo a WhatsApp como documento. En el computador se
  descarga y se adjunta en WhatsApp Web. Si el celular no deja compartir archivos, queda solo Descargar.
- **Precio de la hoja**: no se rellena solo. Sigue la decisión F (2026-11-10: el material no lleva precio por defecto);
  aquí el precio lo escribe el cliente. Ver pregunta 2.

## 5. Etapas (un PR cada una)
| Etapa | Qué incluye | Archivos principales |
|---|---|---|
| **P1. Armar PDF desde Mi selección** | Pantalla `/catalogo-pdf` (filas con foto, código, texto), opciones (título, contacto, 4/6/9 por página), «lo mismo en todas / solo vacías», generar con avance, Compartir y Descargar, textos recordados por código. Botón «📄 Crear PDF» en Mi selección. | `index.php` (ruta), `templates/client/catalog_pdf.php`, `assets/js/catalog_pdf.js`, `assets/vendor/jspdf.umd.min.js`, `my_selection.js`, CSS |
| **P2. Más entradas** | «📄 Crear PDF» en Enviar catálogo (con las marcadas), en cada catálogo guardado (abre con sus textos) y en el catálogo por enlace `/c/…`. La lista se pasa a `/catalogo-pdf` en el dispositivo sin tocar Mi selección. | `catalog_send.js`, `my_selection.js`, `shared_catalog.php` |
| **P3. Precios rápidos (opcional)** | Pegar una lista «código precio» (como en Enviar catálogo) y rellenar los textos de golpe; vista previa de la 1.ª página antes de generar. | `catalog_pdf.js` |

Pruebas de cada etapa: Android (Chrome), iPhone (Safari) y computador; catálogos de 1, 30 y 300 referencias; texto vacío
(no sale), texto largo, tildes/ñ; compartir a WhatsApp; celular a 320-375 px sin scroll lateral.

## 6. Preguntas para el dueño (con lo que haría si no hay respuesta)
1. ¿«Sección Catálogo» es lo que leí arriba (catálogos guardados + enlace `/c/…`)? → Sí, salvo que digas otra cosa.
2. ¿Ofrecer un botón «Usar precio sugerido de la hoja» para rellenar los textos? → No, por la decisión F.
3. ¿Poner el logo/nombre «Kino» en el PDF o dejarlo neutro para que cada distribuidor lo use como suyo? → Neutro
   (solo el título y contacto que escriba el cliente).
4. ¿El PDF lleva una foto por código o todas las fotos marcadas? → Todas las marcadas, en el orden de la lista
   (Mi selección ya tiene «Dejar 1 foto por código»).
