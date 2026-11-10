-- Catálogo por enlace (etapa I): una selección guardada en el servidor con un código corto (/c/{code}).
-- items = [{"id": fileId, "sku": código}] en orden. manage_hash = sha256 del token que solo tiene quien lo creó
-- (en su dispositivo) para actualizarlo o desactivarlo. No se borra: se desactiva.
CREATE TABLE IF NOT EXISTS shared_catalogs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(16) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    items MEDIUMTEXT NOT NULL,
    contact VARCHAR(120) NULL,
    manage_hash CHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    views INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
