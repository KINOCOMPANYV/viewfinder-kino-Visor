-- v2 Tienda web (2026-11-26): datos de entrega y forma de pago del pedido hecho desde el catálogo de venta (/tienda).
ALTER TABLE client_orders ADD COLUMN city VARCHAR(80) NOT NULL DEFAULT '';
ALTER TABLE client_orders ADD COLUMN address VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE client_orders ADD COLUMN pay_method VARCHAR(20) NOT NULL DEFAULT '';
