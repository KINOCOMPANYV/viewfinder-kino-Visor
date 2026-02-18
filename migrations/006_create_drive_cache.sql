-- Caché de estructura de carpetas de Google Drive
-- Reduce llamadas a la API almacenando temporalmente el mapa de archivos
CREATE TABLE IF NOT EXISTS drive_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id VARCHAR(100) NOT NULL,
    file_name VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) DEFAULT '',
    parent_folder_id VARCHAR(100) DEFAULT '',
    file_size BIGINT DEFAULT 0,
    thumbnail_link VARCHAR(1000) DEFAULT '',
    web_view_link VARCHAR(1000) DEFAULT '',
    web_content_link VARCHAR(1000) DEFAULT '',
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_file_id (file_id),
    INDEX idx_parent (parent_folder_id),
    INDEX idx_name (file_name(100)),
    INDEX idx_cached (cached_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
