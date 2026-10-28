-- Código de producto al que quedó enlazado cada archivo (NULL = nombre que no es un código)
ALTER TABLE drive_index ADD COLUMN matched_sku VARCHAR(100) NULL;
ALTER TABLE drive_index ADD INDEX idx_matched_sku (matched_sku);
