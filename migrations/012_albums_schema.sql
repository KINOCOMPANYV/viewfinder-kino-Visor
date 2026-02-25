-- Tabla para gestionar los Álbumes (carpetas principales de Drive)
CREATE TABLE IF NOT EXISTS albums (
    drive_id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    icon_url VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    order_priority INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Añadir album_id a productos para vincularlos con un Álbum
ALTER TABLE products ADD COLUMN album_id VARCHAR(50) DEFAULT NULL;
CREATE INDEX idx_album_id ON products(album_id);
