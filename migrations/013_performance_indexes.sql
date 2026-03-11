-- Optimizar rendimiento de la vista principal y búsqueda
-- La landing agrupa y ordena por sheet_row donde archived = 0

CREATE INDEX idx_archived_sheet_row ON products (archived, sheet_row);

-- Optimizar el helper de extractRootSku() en el ORM añadiendo índice en cover_image_url
CREATE INDEX idx_cover_image ON products (cover_image_url(255));
