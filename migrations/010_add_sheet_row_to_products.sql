-- Orden de la hoja de Google Sheets (la landing ordena por esto).
-- Sin "IF NOT EXISTS": MySQL 8 no lo soporta en ADD COLUMN/CREATE INDEX;
-- migrate.php tolera el error de columna/índice duplicado.
ALTER TABLE products ADD COLUMN sheet_row INT DEFAULT 0;
CREATE INDEX idx_sheet_row ON products (sheet_row);
