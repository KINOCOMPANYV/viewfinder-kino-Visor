-- Caché de resultados de búsqueda de media por SKU
-- Almacena el JSON completo de archivos encontrados para evitar
-- llamadas repetidas a la API de Drive (TTL: 5 minutos)
CREATE TABLE IF NOT EXISTS media_search_cache (
    sku VARCHAR(100) PRIMARY KEY,
    root_sku VARCHAR(100) NOT NULL,
    files_json LONGTEXT NOT NULL,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_cached_at (cached_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
