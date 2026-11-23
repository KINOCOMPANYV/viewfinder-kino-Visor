-- Registro de errores y de búsquedas sin resultado (2026-11-23, decisión del dueño: medir antes de seguir agregando).
-- app_errors: un renglón por error distinto (huella = origen + mensaje + archivo + línea); count = cuántas veces pasó.
-- source: php | js. Lo llena ErrorLog (PHP: avisos y errores fatales; JS: /api/error-js desde cada página).
CREATE TABLE IF NOT EXISTS app_errors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fingerprint CHAR(40) NOT NULL,
    source VARCHAR(10) NOT NULL DEFAULT 'php',
    level VARCHAR(30) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    file VARCHAR(255) NOT NULL DEFAULT '',
    line INT NOT NULL DEFAULT 0,
    url VARCHAR(500) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    count INT NOT NULL DEFAULT 1,
    first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_errors_fp (fingerprint),
    KEY idx_app_errors_last (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- search_misses: una fila por búsqueda pública sin ningún resultado (ni productos ni carpetas, sin filtros).
-- term_key = término en minúsculas y sin espacios repetidos; sid = huella corta de la sesión (contar personas).
CREATE TABLE IF NOT EXISTS search_misses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term VARCHAR(120) NOT NULL,
    term_key VARCHAR(120) NOT NULL,
    sid CHAR(12) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_search_misses_key (term_key),
    KEY idx_search_misses_time (created_at),
    KEY idx_search_misses_sid (sid, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
