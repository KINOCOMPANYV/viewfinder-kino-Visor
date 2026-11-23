# Guía del Visor Kino (explicada fácil)

El **Visor Kino** es una página web donde los distribuidores ven el catálogo de productos (relojes y accesorios)
con sus fotos y videos, buscan por código y mandan fotos por WhatsApp o en PDF.
Dirección: **viewfinder-k.up.railway.app** (en el computador o en el celular).

## ¿De dónde salen los datos?
- **Hoja master** (hoja de Google «MAESTRA DE PRODUCTOS KINO»): código, nombre, marca, precio, stock…
- **Google Drive** (carpeta **PRODUCTOS**): las fotos y videos. **El nombre del archivo es el código** del producto
  («M8291-1.jpg» = producto M:8291-1). «M:», «M» y «M-» cuentan como el mismo código. El nombre de la carpeta no cuenta.
- Si en Drive hay una foto con código que no está en la Hoja master, el producto sale igual como **«Solo Drive»**
  (sin precio hasta que se agregue a la hoja).
- El visor se **actualiza solo cada hora** (y cada vez que se publica una versión nueva). No hay que hacer nada.

## Lo que ve el distribuidor (parte pública)
- **Catálogo**: una tarjeta por referencia con foto, código, precio («Sin precio en tabla» si no tiene) y
  «In Stock» (verde) / «Out stock» (rojo). Solo salen productos **con foto**.
- **Buscar**: por código (el exacto sale primero, «✓ Código exacto»), nombre, marca, color o carpeta.
  Si se escribe mal, sugiere «¿Quisiste decir…?». También sirve pegar el nombre exacto de una foto.
- **Buscar por foto** (📷 en el buscador): se toma o sube una foto y muestra los productos más parecidos.
- **Filtros** («⚙️ Filtros y orden»): marcas, categorías, carpetas, precio y stock, combinables.
- **Carpetas**: se navega igual que en Drive (Inicio › Marca › Carpeta…).
- **Novedades**: fotos nuevas de la semana.
- **Ficha del producto**: fotos grandes, variantes de la familia, botón WhatsApp y Descargar en cada foto.
- **⭐ Selección**: «Seleccionar» en las fotos que se quieran → **Descargar**, **📲 Enviar** por WhatsApp en tandas de 10,
  o **📄 PDF**. Lo marcado se guarda en el aparato aunque se cambie de página. «Mi selección» permite ordenar y quitar.
- **📤 Enviar fotos con lista**: se pega una lista de códigos (o se saca de una **foto de la lista** 📷) y arma la galería
  para descargar o mandar por WhatsApp. Corrige confusiones típicas (B↔8, O↔0…) y avisa cuáles corrigió.
- **📄 Catálogo PDF**: arma un PDF con las fotos elegidas: título, logo propio, colores, contacto, 4/6/9 por página.
- **Enlace de catálogo** (`/c/…`): un catálogo guardado que se comparte con un enlace y siempre muestra la foto vigente.

## Pedidos (uso interno)
- **🧾 Extractor**: se sube el PDF de un recibo o cotización y saca código, cantidad y precio, y revisa que el total cuadre.
  El cliente se puede corregir a mano.
- Desde ahí salen dos documentos:
  - **Remisión** (factura en tabla, azul): con NIT, dirección, precio y total.
  - **Lista Bodega** (recibo, negro y dorado): foto, código y cantidad de cada línea, para alistar.
  - «👁 Previo» muestra el PDF antes de descargarlo o enviarlo.
- **📋 Pedidos bodega**: la Lista Bodega se envía aquí y queda guardada. El **alistador** abre cada pedido, marca
  «Alistado» por línea, cambia cantidades o productos (buscando con fotos), pone quién chequeó y lo finaliza.
  El alistador **no puede cambiar precios**. Al final sale el listado para copiar o el PDF «Pedido finalizado».
  Borrar un pedido pide contraseña.

## Administración (solo el dueño, `/admin`)
- **Productos**: activar/desactivar cada producto (no se borran) y editar el precio solo en el visor.
- **Carpetas**: mostrar u ocultar carpetas, y «Como carpeta» para sacarla de las listas sin ocultar sus fotos.
  Las carpetas «archivado» o «desactivados» se ocultan solas.
- **Sincronizar**: ver la última actualización de la hoja y de Drive, reintentar, y **«¿Por qué no sale la foto?»**
  (se escribe un código y dice qué pasa).
- **Media / Problemas**: conexión con Google Drive (si dice «Drive not connected», volver a conectar aquí) y lista de
  fotos o productos con problemas (sin foto, nombre que no coincide…).
- **💲 Precios**: subir el Excel de **KYTE** → precio público (PRICE_SUGGESTED). Se aplica al subirlo y también se escribe
  en la Hoja master.
- **📦 Inventario**: subir el Excel de **SYS** (INVENTARIO GENERAL) → stock y precio SYS. También se escribe en la Hoja master.
- **🚫 Condenados**: códigos que no deben salir nunca. Se ignoran en todo y el producto se borra. Quitarlo de la lista = vuelve.
- **🚚 Remisión**: cambiar logos, colores y pie del PDF de Remisión.
- **🗄 Pedidos**: bajar todos los Pedidos bodega en Excel o JSON. Cada noche se guarda sola una copia en Drive.

## Preguntas rápidas
- **No sale la foto de un producto**: revisar que el archivo en Drive se llame igual que el código. Usar
  Admin → Sincronizar → «¿Por qué no sale la foto?».
- **Un producto no aparece**: puede estar desactivado en Admin → Productos, sin foto, en una carpeta oculta o condenado.
- **Cambié algo en Drive o en la hoja**: esperar la siguiente hora, o Admin → Sincronizar para hacerlo ya.
- **WhatsApp**: se mandan imágenes (no texto). El envío automático a un número no se puede desde la web.
