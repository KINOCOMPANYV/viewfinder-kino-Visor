-- Hash por fila para detectar cambios en el sync de Sheets.
ALTER TABLE products ADD COLUMN sheet_hash VARCHAR(32) DEFAULT NULL;
