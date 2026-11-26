# Plan v2 — Centro de Administración KINO

Rama larga `v2` (producción sigue en `main`, no se toca). Los PRs de v2 van **hacia `v2`**, no hacia `main`.
Fuente: plan del dueño del 2026-11-26 (portal de clientes + panel interno; el **pedido** une ambas áreas).
Referencia visual: pantalla Productos de KYTE (foto, nombre, código, categoría, stock, precio, interruptor de catálogo, totales, filtros, exportar).

## Estado
- Rama `v2` creada desde `main` (2026-11-26). Orden de módulos aprobado por el dueño (2026-11-26).
- Hecho: módulo 1 (Usuarios y permisos, en PR).
- Despliegue de v2: servicio Railway aparte (dominio propio, variables copiadas, BD restaurada del respaldo nocturno). Lo hace el dueño.

## Módulos y orden (propuesto)
0. **Base y respaldo** — respaldo completo con fecha/hora (código + BD + configuración), servicio v2 en Railway.
1. **Usuarios y permisos** — cuentas propias; Cliente, Vendedor, Facturación, Bodega, Administrador, Desarrollador (niveles 1-5 + vendedor); reemplaza la clave única del admin.
2. **Productos (estilo KYTE)** — lista con foto, código, categoría, stock, precio, interruptor «Catálogo», totales, filtros y exportar. Fuente del catálogo y de Vender.
3. **Pedido (núcleo)** — número único, etapas (Recibido → Por alistar → En alistamiento → Listo para facturar → Facturado), historial, origen / vendedor asignado / responsable de etapa por separado. Incluye **Clientes** básico (ficha + pedidos).
4. **Portal de clientes + Catálogo Online** — registro, catálogo con referencias publicadas, «Enviar pedido», «Mis pedidos», «Mis datos».
5. **Vender** — buscador y fotos al centro, pedido a la derecha, elegir cliente, cantidades, total.
6. **Facturación y Bodega** — bandejas «Pedidos recibidos» y «Listos para facturar»; «Pedidos por alistar» con faltantes.
7. **Transacciones y estadísticas** — ventas por cliente/producto/vendedor/fecha; resúmenes diario/semanal/mensual.
8. **Gestión** (un botón con las herramientas actuales del admin) y **Finanzas** (alcance por definir).

## Se reutiliza de v1
Búsqueda, fotos de Drive y portadas, Hoja master, precios/stock SYS, checklist de Pedido de bodega, PDFs, respaldo nocturno, Registro de errores, tema claro/oscuro. Las herramientas de admin pasan a **Gestión**.

## Reglas por decidir (antes del módulo 3)
Hasta cuándo edita el cliente un pedido · qué clientes ve cada vendedor · quién autoriza descuentos y cancelaciones · cómo se resuelven faltantes · cuándo se reserva o descuenta inventario.

## Validación
Recorrer dos pedidos de ejemplo (uno de cliente, uno de vendedor): qué ve cada perfil, quién lo recibe después y a quién se atribuye la venta.
