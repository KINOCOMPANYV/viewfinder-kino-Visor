-- Productos: la hoja MAESTRA es la única fuente de datos y solo el admin activa/desactiva.
-- is_active  = interruptor del admin (la hoja nunca lo cambia).
-- in_sheet   = el código está en la hoja maestra (el sync lo pone en 0 si se quitó de la hoja).
-- archived   = se mantiene como "oculto en el portal" = NOT (is_active AND in_sheet);
--              lo recalcula refreshProductVisibility() (src/helpers.php). Las páginas siguen filtrando por archived.
ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE products ADD COLUMN in_sheet TINYINT(1) NOT NULL DEFAULT 1;
-- Se conserva lo que hoy está oculto como "desactivado por el admin"
UPDATE products SET is_active = 0 WHERE archived = 1;

-- Columnas de la hoja maestra que antes no se guardaban
ALTER TABLE products ADD COLUMN color VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN brand VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN price_wholesale DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN stock INT NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN case_material VARCHAR(150) NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN strap_material VARCHAR(150) NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN sheet_status VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'STATUS/estado de la hoja, solo informativo';

CREATE INDEX idx_brand ON products (brand);
CREATE INDEX idx_is_active ON products (is_active, in_sheet);
