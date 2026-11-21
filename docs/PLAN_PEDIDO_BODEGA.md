# Plan: Pedido de bodega (alistar y chequear un pedido)

Estado: **aprobado por el dueño (2026-11-21)**; se tomaron mis propuestas en las preguntas. **B1 y B2 hechas** (un solo PR);
**B3 hecha (2026-11-21)** en forma de «Pedidos a alistar» (`/pedidos-alistar`, tabla `warehouse_orders`): rojo = por alistar, verde = listo.

Cambios al construirlo: en vez de «cantidad encontrada» + «estado», cada ítem tiene una sola **cantidad final** (+/−, empieza
igual a la pedida) y el estado sale solo (Completo / Menos / Más / Cambiado / Agregado / Quitado). El precio de cada ítem se
puede editar a mano. Sin clave: entra cualquiera con el enlace, igual que Lista Bodega.

## Qué se quiere
Un módulo aparte, conectado al generador de pedido (Extractor → Lista Bodega / Remisión), para que la bodega
aliste el pedido, lo chequee ítem por ítem y al final genere un PDF nuevo de **pedido finalizado**.

## Cómo funcionaría (flujo)
1. En `/lista-pedido` (Lista Bodega y Remisión) y en el Extractor aparece el botón **«📦 Pedido de bodega»**.
2. El botón abre el panel (`/pedido-bodega`) con la **misma selección que arma el PDF** (`sessionStorage kinoPedido`:
   datos del pedido + ítems en el mismo orden). No toca la Lista Bodega ni la Remisión.
3. Arriba: **datos del pedido** (n.º, cliente, fecha, total de ítems, unidades, valor), tal cual del PDF.
4. Lista, una fila por ítem: **foto, código, nombre, precio, cantidad pedida** y su **checklist**:
   - casilla «✓ Alistado» (grande, fácil en celular),
   - cantidad encontrada (viene igual a la pedida; se cambia si falta),
   - estado: Completo / Incompleto / No hay (sale solo según la cantidad, se puede cambiar),
   - nota corta opcional (ej. «cambiado por color negro»).
5. **Cambios al pedido** (pedido del dueño 2026-11-21), por ítem:
   - **Cambiar el producto** por otro: se escribe el código y trae foto, nombre y precio del visor; queda marcado
     «cambiado: A → B» (y se ve el original).
   - **Bajar o subir la cantidad** (+ / −), también poner 0 (quitar). Se puede **agregar un ítem** que no estaba.
   - Cada ítem muestra su **subtotal** (cantidad × precio) y arriba el **total final recalculado**, junto al total
     original del pedido y la diferencia («original $X → final $Y»).
6. Contador arriba: «18 de 25 chequeados», faltantes en rojo, filtro «ver solo pendientes».
7. Campo obligatorio **«Chequeado por»** (nombre de quien revisa; se recuerda en el dispositivo).
8. **«✅ Finalizar pedido»**: solo se activa con todos los ítems revisados y el nombre escrito. Abre el
   **Previo** del PDF nuevo con «📝 Nombre del archivo» editable (`KinoPdf.setName`), Descargar y Enviar.
9. El avance se guarda solo en el dispositivo mientras se trabaja: si se cierra la página, no se pierde.

## PDF «Pedido finalizado»
- Encabezado: título «Pedido finalizado», n.º, cliente, fecha del pedido, **fecha y hora de cierre**, **chequeado por**.
- Resumen: **total original vs total final** con la diferencia; ítems completos / incompletos / no hay; unidades pedidas vs alistadas.
- Tabla: N.º, foto (con enlace a Drive como hoy), código, nombre, precio, pedida, alistada, subtotal, estado, nota; ítems cambiados («A → B»), agregados o quitados marcados.
- Faltantes resaltados en rojo. Nombre sugerido: `Pedido_finalizado_{n.º}_{fecha}.pdf`, editable.
- Se arma en el dispositivo con jsPDF y `pdf_shared.js`, igual que Lista Bodega.

## De dónde sale cada dato
- Foto: la misma búsqueda de Lista Bodega (`POST /enviar-catalogo/extraer` → `catalogSendLookup()`).
- Nombre y precio: se agregan a esa respuesta desde el visor (`products.name`, precio público). Si el PDF del pedido
  trae precio, se muestra **el del pedido** (como en Lista Bodega/Remisión); el del visor solo si el pedido no tiene.
- Cantidad y datos del pedido: del PDF (`kinoPedido`).

## Etapas (un PR cada una)
- **B1 – Panel y checklist**: botón, `/pedido-bodega`, lista con foto/código/nombre/precio/cantidad, checklist,
  contador, «Chequeado por», avance guardado en el dispositivo. Sin servidor nuevo salvo nombre/precio en la búsqueda.
- **B2 – Finalizar y PDF**: validación, PDF «Pedido finalizado» con Previo, nombre editable, Descargar/Enviar.
- **B3 – Historial (opcional, según la respuesta 1)**: guardar el pedido finalizado en MySQL (migración nueva
  `warehouse_orders`), pantalla Admin → 📦 Pedidos de bodega con lista, estado y volver a descargar el PDF.

## Preguntas para el dueño
1. **¿Se guardan los pedidos en el servidor con historial?** (B3). Sin B3, el PDF descargado es el único registro.
2. **¿Quién entra al panel?** a) cualquiera con el enlace (como Lista Bodega hoy), b) con una clave de bodega
   sencilla, c) solo el admin.
3. **¿El checklist sirve así** (alistado + cantidad encontrada + estado + nota) o quiere otros puntos, por ejemplo
   «empacado», «revisado calidad» o un checklist general del pedido (cajas, factura adjunta)?
4. **¿Precio del pedido o del visor?** Propuesta: el del pedido; el del visor solo si falta.
5. **¿El precio de un producto cambiado** sale del visor (propuesta) o se puede escribir a mano? ¿Se permite editar también el precio de un ítem?
6. **¿Se puede finalizar con faltantes?** Propuesta: sí, quedan en rojo en el PDF.

## Fuera de este plan
- Descontar stock o tocar la Hoja master / SYS al finalizar.
- Varias personas chequeando el mismo pedido a la vez (necesita B3 y más).
