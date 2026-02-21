-- Agregar columna sheet_row para ordenar productos por posición en Google Sheets
ALTER TABLE products ADD COLUMN IF NOT EXISTS sheet_row INT DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_sheet_row ON products (sheet_row);
