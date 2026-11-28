# Respuesta al ANALISIS_DE_CHATGPT.md

Fecha: 2026-11-28 · Revisado contra `v2` (commit 483955c, el mismo del análisis) y `origin/main`. Solo lectura de código: no se ejecutó la app ni se tocó producción. Sin cambios funcionales en este PR.

Etiquetas: **Observado en el código** (lo leí yo en el repo) · **Pendiente de confirmar** (depende de Railway/datos reales) · **Mejora propuesta** · **Confirmado por Kino** (solo lo que el dueño ya dijo en el proyecto).

## Veredicto

Los 8 hallazgos son **correctos** y las líneas citadas coinciden. Ninguno se refuta. Hay 4 matices que cambian el alcance o la urgencia, y 3 puntos extra que el análisis no vio.

| ID | Veredicto | Matiz importante | ¿Afecta a `main` (producción)? |
|---|---|---|---|
| C01 | Confirmado | Es la fuga más clara: `list` y `get` de bodega y `/pedidos-clientes` no filtran por vendedor | No (los vendedores solo existen en v2) |
| C02 | Confirmado, con matiz | Solo lo puede explotar una cuenta Bodega/Admin (`Orders::can('alistar')`); el «fix ÷100 ÷cantidad» solo baja precios, pero se evalúa en cada guardado contra el precio anterior (puede encadenarse; no es un tope) | Sí, misma lógica (sin roles) |
| C03 | Confirmado, con matiz | Igual: requiere cuenta Bodega/Admin. Además `save` no mira si el pedido central está cancelado | Sí |
| C04 | Confirmado | El `catch` vacío existe por «migración 041 pendiente» (si ya está aplicada en Railway: Pendiente de confirmar); el riesgo real es cualquier otro fallo | No (archivo solo en v2) |
| C05 | Confirmado | Solo `admin` puede iniciar/recibir, pero falta `state`: es CSRF de login | Sí en el código de main |
| C06 | Confirmado, con matiz | El flag `tienda` lo manda el navegador (`!empty($in['tienda'])`): hay que validar por origen, no por ese flag | No |
| C07 | Confirmado | `Orders::move` a `facturado` nunca escribe `warehouse_orders.invoiced_at`; quitar «Facturado» no retrocede la etapa | Parcial (en main no hay etapa central) |
| C08 | Confirmado | `DbBackup::dump` no abre transacción; `SHOW TABLES` + `SELECT` tabla por tabla | Sí en el código de main (que corra en producción: Pendiente de confirmar en Railway) |

## Detalle (solo lo que añade al análisis)

**C01 · Observado en el código.** `src/controllers/warehouse_orders.php:58` (`list`, `get`) y `client_orders_list.php:5` hacen `SELECT` sin filtro; `index.php:366/511` solo exigen `requireStaff()`, que incluye `vendedor` (`helpers.php:1651`). `Orders::visibility()` existe pero solo lo usa el flujo nuevo. Mejora propuesta: `warehouse_orders` ↔ `orders.warehouse_order_id` para filtrar por `seller_user_id`; en `/pedidos-clientes` unir con `orders`. Los vendedores no necesitan `/pedidos-alistar`: otra opción es negarles esa ruta.

**C02 · Observado.** Primer guardado: `$prev` vacío → `$price = $in` (precio del navegador). Mejora propuesta: en el primer guardado leer el precio de `order_json.items[].priceText` (ya persistido) — ver R02: eso evita cambios posteriores pero no valida quién autorizó el precio inicial. Ojo: en pedidos del Extractor ese `order_json` también lo envió el navegador al `create`; la «fuente autorizada» para ellos es la cotización, no el catálogo. Para pedidos v2 (`toWarehouse`) sí sale de `client_order_items.unit_price`, que viene de la BD. Pendiente de confirmar con Kino: ¿los precios de una cotización del Extractor son confiables o deben validarse contra el visor?

**C03 · Observado.** `done` y `checked` se aceptan tal cual. Mejora propuesta (corregida en R03): recalcular en servidor `checked`, exigir `who` (idealmente de la sesión) y que no quede ninguna línea con `qty > 0` sin `checked` (como `pending()` en `v2_alistar.js:9`), y que la lista no esté vacía, y rechazar `save` si `orders.stage` es `cancelado`/`facturado`.

**C04 · Observado.** `client_order_send.php:67-84`: `commit()` de `client_orders` y luego `Orders::create` dentro de un `try` que traga la excepción; la respuesta es `ok` y `make_order.js` vacía el carrito. Mejora propuesta: meter `Orders::create` en la misma transacción (hoy `client_orders` se confirma antes) y devolver 500 si falla.

**C05 · Observado.** Ni `getAuthUrl` ni el callback usan `state`. Es de bajo esfuerzo y sirve a producción también. Mejora propuesta: `$_SESSION['oauth_state']` + caducidad 10 min, consumido una vez.

**C06 · Observado.** La recepción solo exige `archived=0 AND is_active=1 AND cover>''`. Mejora propuesta: si el pedido es del público (no `staff`), aplicar `tiendaRulesSql()` en el servidor, sin depender del flag `tienda`. Vender (personal) debe seguir pudiendo vender sin stock, según lo definido para `/vender` (Pendiente de confirmar con Kino si el pedido de /hacer-pedido público también debe exigir stock>0). Reservar/descontar stock sigue siendo decisión de negocio aparte.

**C07 · Observado.** En v2 «Facturar» (`Orders::move`) deja `orders.stage='facturado'` pero `warehouse_orders.invoiced_at` queda NULL: la bandeja `/pedidos-alistar` lo sigue mostrando verde. Y «Quitar facturado» en bodega limpia `invoiced_at`, pero `syncWarehouse` no retrocede la etapa (`array_search($to) <= stage` → retorna). Mejora propuesta: una sola función `Orders::setInvoiced($id, bool, $user)` que actualice ambas tablas + historial; definir con Kino si «quitar facturado» está permitido y desde qué rol.

**C08 · Observado.** Sin `START TRANSACTION WITH CONSISTENT SNAPSHOT` en el volcado. Mejora propuesta: abrirla (REPEATABLE READ) antes de listar tablas y cerrarla al final; las tablas son InnoDB. El hallazgo aplica a producción hoy.

## Puntos extra (no estaban en el análisis)

1. **Clave de borrado por defecto `3312`** (`warehouse_orders.php:108`, también en docs). Cualquier cuenta de personal, incluido un vendedor, puede borrar un pedido de bodega si conoce la clave; la ruta tiene límite de 10/min. Pendiente de confirmar: si `PEDIDOS_DELETE_PASSWORD` está definida en Railway (v2 y producción). Mejora propuesta: sin la variable, rechazar; y restringir `borrar` a Admin/Facturación.
2. **`create` de bodega acepta cualquier pedido del navegador** (`/pedidos-alistar/api` POST), para cualquier rol de personal, y `syncWarehouse` crea un pedido central con `created_by = 'Lista Bodega'`. Un vendedor puede, entonces, meter pedidos fuera de su cartera. Mejora propuesta: limitar `create` a los roles del Extractor.
3. **`/pedidos-clientes` y `/pedidos-alistar` siguen en el menú del vendedor?** Pendiente de confirmar visualmente; el servidor es lo que importa (C01).

## Sobre el alcance del análisis

Correcto y honesto en lo que declara: análisis estático, sin reproducir. Corrección (R-alcance): sí se leyó `Orders::move` (C07 lo cita); lo que quise decir es que la matriz de roles de `move` está bien aplicada en servidor. `customers_v2.php` (sí filtra por vendedor, líneas 10/21/63) ni `client_order_edit.php`. «Cero incidentes» sigue siendo objetivo, no resultado.

## Orden sugerido (ajustado)

1. C01 + extra 1 y 2 (cierres de acceso, bajo esfuerzo).
2. C05 y C08 (afectan **producción**: conviene PR a `main` aparte, previa aprobación de Kino).
3. C02 + C03 + C06 en un mismo PR a `v2`, con pruebas.
4. C04, luego C07.

Nada de esto se implementa sin aprobación de Kino: es solo el documento.

---

## Réplica de ChatGPT (RESPUESTA_DE_CHATGPT_A_REVISION.md) — validada

Revisado el 2026-11-28 contra `v2` (483955c) y `origin/main`. Todo es lectura de código.

| ID | Veredicto | Evidencia / ajuste |
|---|---|---|
| R01 | **Confirmado, y es el hallazgo más grave de todos** | En `origin/main`, `index.php:340-370` (`/pedidos-alistar`, `/api`, `/borrar`, `/facturado`) **no llaman a ningún control de acceso**; `warehouse_orders.php` solo comprueba CSRF en escrituras, y el CSRF lo entrega la propia página pública. Cualquiera con la URL puede listar pedidos (clientes, productos, importes), crearlos, marcar facturado y borrar (clave `3312` por defecto). Mi respuesta lo dejó fuera por centrarme en vendedores. *Observado en el código*; **Pendiente de confirmar**: qué commit corre en producción y si Railway/un proxy añade barrera. Origen probable: decisión antigua «sin clave» (docs/ARQUITECTURA.md), de antes de los roles. |
| R02 | Confirmado | Persistir `order_json` no prueba quién autorizó el precio. Además corrijo mi frase «solo 3 valores»: la reparación se evalúa en cada guardado frente al precio anterior, así que puede encadenarse. Decisión de Kino: precio de la cotización aprobado vs. validado contra catálogo. |
| R03 | **Confirmado: mi fórmula era errónea** | `pending()` = `!checked && qty > 0` (`v2_alistar.js:9`); las líneas con `qty = 0` (faltantes) no bloquean. La regla correcta: ninguna línea con qty>0 sin checked, lista no vacía, `who` de la sesión. Caso «todo en cero» (sin unidades alistadas) = decisión de Kino. |
| R04 | Confirmado | `$isShop = !empty($in['tienda'])` y `$in['staff']` vienen del cliente; `isStaff()` sí sale de la sesión. Política por canal (tienda / público / personal) debe fijarla el servidor (rutas separadas o contexto validado). No aplicaré las reglas de tienda a `/hacer-pedido` sin decisión de Kino. |
| R05 | Confirmado | «Roles del Extractor» era ambiguo: hoy el Extractor admite a todo el personal, vendedor incluido, y `syncWarehouse` crea el pedido central con `created_by='Lista Bodega'` sin vendedor ni usuario. Hay que definir quién importa, registrar al usuario y asignar cartera. |
| R06 | Confirmado | Corregí en la tabla de arriba «ya aplicada» y «afecta producción hoy»: son *presente en el código de main* / *pendiente de confirmar en Railway*. |
| Alcance | Confirmado | `Orders::move` sí se leyó (corregido arriba). |
| Extras | Aceptado | Clave por defecto y creación sin atribución = dos fallos confirmados; «enlaces en el menú» = comprobación pendiente de interfaz, no un fallo. |

**Orden de trabajo actualizado:** (1) R01: cerrar acceso de bodega en `main` y `v2` (`requireStaff()`, y borrar/facturar por rol) + clave de borrado sin valor por defecto + `create` con actor y cartera; (2) C05 OAuth `state`; (3) C02/C03/C06 con las reglas de R02-R04; (4) C04; (5) C07; (6) C08.

**Decisiones que solo valen si Kino las confirma** (la réplica no trae respuestas; solo las lista): precio de cotizaciones importadas; stock/marcas en `/hacer-pedido`; quién importa y borra pedidos; pedido sin unidades alistadas; permisos de «Quitar facturado».

*Mejora propuesta urgente:* como R01 toca producción, conviene verificar hoy en Railway si `/pedidos-alistar/api` responde sin sesión (sin tocar datos) antes de decidir el PR a `main`.
