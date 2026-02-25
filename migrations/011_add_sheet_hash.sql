-- Agregar columna sheet_hash para sincronización inteligente
-- Almacena MD5 del contenido de la fila en Sheets para detectar cambios
ALTER TABLE products ADD COLUMN IF NOT EXISTS sheet_hash VARCHAR(32) DEFAULT NULL;
