-- Índice de búsqueda (2026-11-20): texto ya armado por producto para que buscar y filtrar no recorran drive_index
-- en cada consulta. Lo rehace SearchIndex::rebuild() tras cada sincronización (DriveIndex::relink / refreshFolderState).
-- search_text  = código (y sin «M:», «-», espacios), nombre, marca, categoría, color y nombres de las carpetas de sus fotos
-- contains_text = nombre + nombres de sus archivos en Drive («Contiene la palabra»)
-- folder_ids   = " id1 id2 … " carpetas (y todas sus carpetas superiores) donde tiene fotos visibles (filtro Carpetas)
ALTER TABLE products ADD COLUMN search_text TEXT NULL;
ALTER TABLE products ADD COLUMN contains_text TEXT NULL;
ALTER TABLE products ADD COLUMN folder_ids TEXT NULL;
CREATE TABLE IF NOT EXISTS search_meta (
    k VARCHAR(64) PRIMARY KEY,
    v MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
