-- Agregar columna parent_sku para relaciones explícitas
ALTER TABLE products ADD COLUMN parent_sku VARCHAR(50) DEFAULT NULL AFTER sku;
CREATE INDEX idx_parent_sku ON products (parent_sku);
