-- v2 Tienda (2026-11-27): galería de fotos con scroll lateral en /tienda, editable en Configuraciones → Tienda.
-- data = imagen ya optimizada (WebP o JPG, máx. 1000 px de ancho). pos = orden (menor primero).
CREATE TABLE IF NOT EXISTS tienda_gallery (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pos INT NOT NULL DEFAULT 0,
    caption VARCHAR(80) NOT NULL DEFAULT '',
    link VARCHAR(200) NOT NULL DEFAULT '',
    mime VARCHAR(20) NOT NULL DEFAULT 'image/webp',
    width SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    data MEDIUMBLOB NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pos (pos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
