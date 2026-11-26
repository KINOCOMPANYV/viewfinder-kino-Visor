# Plan v2 — Centro de Administración KINO

Rama larga `v2` (producción sigue en `main`, no se toca). Los PRs de v2 van **hacia `v2`**, no hacia `main`.
Fuente: plan del dueño del 2026-11-26 (portal de clientes + panel interno; el **pedido** une ambas áreas).
Referencia visual: pantalla Productos de KYTE (foto, nombre, código, categoría, stock, precio, interruptor de catálogo, totales, filtros, exportar).

## Estado (2026-11-26, pausa para continuar después)
**Hecho**
- Rama `v2` creada desde `main`; producción no se toca. Orden de módulos aprobado por el dueño.
- Módulo 0 (parte de código): plan y rama (PR #138). El respaldo nocturno de v1 ya existe (`docs/RESPALDOS.md`); falta el respaldo manual con fecha/hora del plan, antes de tocar datos.
- Módulo 1 Usuarios y permisos (PR #139, fusionado en `v2`): tabla `users` (migración 039), roles, Admin → 👥 Usuarios, `/panel` provisional. **Sin probar con MySQL real.**

**En curso / hecho sin probar**
- Módulo 2 Productos estilo KYTE: **hecho en código, sin probar con MySQL real** (2026-11-26). Pantalla nueva `/admin/productos` (nav «🛍 Productos v2»; `/admin/products` de v1 queda igual): foto, nombre/código, categoría, stock, precio, interruptor «Catálogo» (= `is_active` vía `/admin/products/active`), totales del filtro (en stock, sin stock, valor = stock × precio público), búsqueda, filtros (categoría, marca, stock, catálogo/sin foto), orden y paginado de 50, «⬇ Excel» reutiliza el de v1. Falta decidir con el dueño: reemplazar la pantalla de v1 o convivir.

**Pendiente del dueño**
1. Railway: duplicar el servicio, rama `v2`, dominio nuevo, copiar variables (cambiar `GOOGLE_REDIRECT_URI`), MySQL propia restaurada del respaldo nocturno, `AUTO_SYNC=0` hasta confirmar que es la base de pruebas.
2. Con v2 arriba: crear cuentas de prueba (Vendedor y Bodega) en Admin → Usuarios y confirmar que entran.
3. Antes del módulo 3, decidir las reglas operativas (abajo).

**Próximos pasos exactos**
1. Probar módulo 1 en el servicio v2 (migración 039 se aplica sola al arrancar).
2. Probar `/admin/productos` en el servicio v2 y ajustar a lo que falte del diseño KYTE.
3. Con las reglas decididas: módulo 3 (Pedido + Clientes básico), luego 4 a 8.
Nota: los PRs hacia `v2` se fusionan con squash; si la rama de trabajo se reutiliza, traer `v2` antes (`git merge origin/v2`).

## Módulos y orden (propuesto)
0. **Base y respaldo** — respaldo completo con fecha/hora (código + BD + configuración), servicio v2 en Railway.
1. **Usuarios y permisos** — cuentas propias; Cliente, Vendedor, Facturación, Bodega, Administrador, Desarrollador (niveles 1-5 + vendedor); reemplaza la clave única del admin.
2. **Productos (estilo KYTE)** — lista con foto, código, categoría, stock, precio, interruptor «Catálogo», totales, filtros y exportar. Fuente del catálogo y de Vender.
3. **Pedido (núcleo)** — número único, etapas (Recibido → Por alistar → En alistamiento → Listo para facturar → Facturado), historial, origen / vendedor asignado / responsable de etapa por separado. Incluye **Clientes** básico (ficha + pedidos).
4. **Portal de clientes + Catálogo Online** — registro, catálogo con referencias publicadas, «Enviar pedido», «Mis pedidos», «Mis datos».
5. **Vender** (hecho: `/vender`) — buscador y fotos al centro, pedido a la derecha, elegir cliente, cantidades, total.
6. **Facturación y Bodega** — bandejas «Pedidos recibidos» y «Listos para facturar»; «Pedidos por alistar» con faltantes.
7. **Transacciones y estadísticas** — ventas por cliente/producto/vendedor/fecha; resúmenes diario/semanal/mensual.
8. **Gestión** (un botón con las herramientas actuales del admin) y **Finanzas** (alcance por definir).

## Se reutiliza de v1
Búsqueda, fotos de Drive y portadas, Hoja master, precios/stock SYS, checklist de Pedido de bodega, PDFs, respaldo nocturno, Registro de errores, tema claro/oscuro. Las herramientas de admin pasan a **Gestión**.

## Reglas por decidir (antes del módulo 3)
Hasta cuándo edita el cliente un pedido · qué clientes ve cada vendedor · quién autoriza descuentos y cancelaciones · cómo se resuelven faltantes · cuándo se reserva o descuenta inventario.

## Validación
Recorrer dos pedidos de ejemplo (uno de cliente, uno de vendedor): qué ve cada perfil, quién lo recibe después y a quién se atribuye la venta.
