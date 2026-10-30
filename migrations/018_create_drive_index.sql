-- Índice local de la carpeta raíz de Drive (GOOGLE_DRIVE_FOLDER_ID).
-- Lo llena DriveIndex::sync() (admin "Sincronizar portadas" o /api/cron/sync-covers).
-- Las páginas públicas buscan aquí las fotos de un SKU en vez de llamar a Drive.
CREATE TABLE IF NOT EXISTS drive_index (
    file_id VARCHAR(100) PRIMARY KEY,
    name VARCHAR(500) NOT NULL,
    name_stem VARCHAR(191) NOT NULL COMMENT 'Nombre sin extensión (búsqueda por prefijo de SKU)',
    mime_type VARCHAR(100) NOT NULL DEFAULT '',
    is_folder TINYINT(1) NOT NULL DEFAULT 0,
    parent_id VARCHAR(100) DEFAULT NULL,
    album_id VARCHAR(100) DEFAULT NULL COMMENT 'Carpeta de primer nivel (marca)',
    size BIGINT DEFAULT 0,
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    synced_at DATETIME(6) NOT NULL,

    INDEX idx_stem (name_stem),
    INDEX idx_parent (parent_id),
    INDEX idx_album (album_id),
    INDEX idx_synced (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
