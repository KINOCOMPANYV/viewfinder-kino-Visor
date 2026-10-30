-- Carpetas: mostrar/ocultar desde el admin y portada al azar.
-- folder_settings guarda la decisión del admin (sobrevive a los syncs y a que la carpeta se mueva).
CREATE TABLE IF NOT EXISTS folder_settings (
    folder_id VARCHAR(100) PRIMARY KEY,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calculados por DriveIndex::refreshFolderState() después de cada sync y de cada cambio en el admin:
-- is_hidden = la fila está dentro de una carpeta oculta (o es una); cover_file_id = foto al azar de su contenido.
ALTER TABLE drive_index ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE drive_index ADD COLUMN cover_file_id VARCHAR(100) NULL;
ALTER TABLE drive_index ADD INDEX idx_parent_hidden (parent_id, is_hidden);
