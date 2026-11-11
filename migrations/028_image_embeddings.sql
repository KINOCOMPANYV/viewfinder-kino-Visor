-- Búsqueda por foto: huella (vector float32 empaquetado, 512 dimensiones, modelo jina-clip-v2) de cada portada.
-- Clave = fileId de Drive (la misma foto sirve a todos los códigos que la usan). embedding NULL = no bajó; se reintenta en 1 día.
CREATE TABLE IF NOT EXISTS image_embeddings (
    file_id VARCHAR(100) NOT NULL PRIMARY KEY,
    embedding BLOB NULL,
    model VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
