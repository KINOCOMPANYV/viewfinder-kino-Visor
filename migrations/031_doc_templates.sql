-- Plantillas editables de los documentos PDF (2026-11-14). name = 'remision'.
-- data = JSON con textos, colores y logos (los logos subidos van como data:image/png;base64 dentro del JSON).
CREATE TABLE IF NOT EXISTS doc_templates (
    name VARCHAR(40) NOT NULL PRIMARY KEY,
    data MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
