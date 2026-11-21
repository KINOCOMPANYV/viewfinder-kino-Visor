-- Códigos condenados (2026-11-21, decisión del dueño). Los agrega el admin a mano en Admin → 🚫 Condenados.
-- Un código condenado se ignora en todo: la Hoja master no lo crea ni lo actualiza, no se crea como «Solo Drive»,
-- ninguna foto se enlaza a él y no sale en la búsqueda. Si el producto ya existía en el visor, se BORRA
-- (única excepción a «no se borran productos»). code_key = código normalizado: "M:", "M" y "M-" son lo mismo.
CREATE TABLE IF NOT EXISTS condemned_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL,
    code_key VARCHAR(100) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_condemned_key (code_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO condemned_codes (code, code_key, note) VALUES ('M:8325-1', 'm8325-1', 'Primer código condenado (2026-11-21)');
