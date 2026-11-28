-- Historial persistente de importaciones SYS y envíos pendientes a Hoja master.
CREATE TABLE IF NOT EXISTS inventory_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(180) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(120) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'processing',
    sheet_status VARCHAR(20) NOT NULL DEFAULT 'not_requested',
    applied_count INT UNSIGNED NOT NULL DEFAULT 0,
    result_json LONGTEXT NOT NULL,
    sheet_columns_json LONGTEXT NULL,
    error_message VARCHAR(1000) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    KEY idx_inventory_imports_created (created_at, id),
    KEY idx_inventory_imports_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
