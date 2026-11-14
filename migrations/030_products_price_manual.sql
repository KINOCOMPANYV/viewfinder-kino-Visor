-- Precio editable en el admin (2026-11-14).
-- price_suggested sigue siendo el precio que se muestra. price_sheet guarda el PRICE_SUGGESTED de la Hoja master.
-- price_manual = 1: el admin cambió el precio y el sync de la Hoja master ya no lo pisa (sí actualiza price_sheet).
ALTER TABLE products ADD COLUMN price_sheet DECIMAL(10,2) NULL;
ALTER TABLE products ADD COLUMN price_manual TINYINT(1) NOT NULL DEFAULT 0;
UPDATE products SET price_sheet = price_suggested WHERE in_sheet = 1;
