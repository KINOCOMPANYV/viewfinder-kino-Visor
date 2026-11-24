-- Pedidos bodega: marca «Facturado» (2026-11-24, decisión del dueño). Un pedido listo (verde) pasa a naranja cuando
-- el usuario lo marca a mano tras facturarlo en otra aplicación. NULL = no facturado. status sigue en 'listo'.
ALTER TABLE warehouse_orders ADD COLUMN invoiced_at DATETIME NULL AFTER finished_at;
