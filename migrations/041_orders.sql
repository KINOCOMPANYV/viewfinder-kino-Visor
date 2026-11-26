-- v2 módulo 3 (2026-11-26): Pedido núcleo. Un pedido con número único (P-00001), etapa e historial; une el pedido del
-- cliente (client_orders) y el checklist de bodega (warehouse_orders). Clientes básico: ficha por WhatsApp (phone_key = solo dígitos).
CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL DEFAULT '',
    phone VARCHAR(40) NOT NULL DEFAULT '',
    phone_key VARCHAR(20) NULL DEFAULT NULL,
    doc_id VARCHAR(40) NOT NULL DEFAULT '',
    city VARCHAR(80) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    seller_user_id INT UNSIGNED NULL DEFAULT NULL,
    note VARCHAR(1000) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customers_phone (phone_key),
    KEY idx_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(20) NULL DEFAULT NULL,
    stage VARCHAR(30) NOT NULL DEFAULT 'recibido',
    origin VARCHAR(20) NOT NULL DEFAULT 'cliente',
    customer_id INT UNSIGNED NULL DEFAULT NULL,
    customer_name VARCHAR(255) NOT NULL DEFAULT '',
    customer_phone VARCHAR(40) NOT NULL DEFAULT '',
    seller_user_id INT UNSIGNED NULL DEFAULT NULL,
    responsible_user_id INT UNSIGNED NULL DEFAULT NULL,
    client_order_id INT UNSIGNED NULL DEFAULT NULL,
    warehouse_order_id INT NULL DEFAULT NULL,
    items_count INT NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    note VARCHAR(1000) NOT NULL DEFAULT '',
    created_by VARCHAR(120) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    stage_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_orders_number (number),
    UNIQUE KEY uq_orders_client_order (client_order_id),
    UNIQUE KEY uq_orders_warehouse (warehouse_order_id),
    KEY idx_orders_stage (stage, created_at),
    KEY idx_orders_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS order_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    stage_from VARCHAR(30) NOT NULL DEFAULT '',
    stage_to VARCHAR(30) NOT NULL DEFAULT '',
    note VARCHAR(1000) NOT NULL DEFAULT '',
    user_id INT UNSIGNED NULL DEFAULT NULL,
    user_name VARCHAR(120) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order_history_order (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
