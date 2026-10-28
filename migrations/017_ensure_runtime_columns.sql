-- Columnas que antes se creaban con ALTER TABLE en cada búsqueda (search.php).
-- Ahora se aseguran una sola vez aquí; los duplicados se toleran en migrate.php.
ALTER TABLE albums ADD COLUMN is_active TINYINT(1) DEFAULT 1;
ALTER TABLE products ADD COLUMN parent_sku VARCHAR(50) DEFAULT NULL;
CREATE INDEX idx_parent_sku ON products (parent_sku);
ALTER TABLE products ADD COLUMN album_id VARCHAR(50) DEFAULT NULL;
CREATE INDEX idx_album_id ON products (album_id);
-- Familias activas (search/product filtran por archived + parent_sku)
CREATE INDEX idx_archived_parent ON products (archived, parent_sku);
