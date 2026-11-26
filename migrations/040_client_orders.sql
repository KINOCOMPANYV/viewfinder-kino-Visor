-- v2 módulo 4 (2026-11-26): pedidos que el cliente arma en «Hacer pedido». Estado inicial «recibido»; las etapas siguientes llegan con el módulo 3.
CREATE TABLE IF NOT EXISTS client_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL DEFAULT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(40) NOT NULL,
    note VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'recibido',
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_client_orders_status (status, created_at)
);
CREATE TABLE IF NOT EXISTS client_order_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    sku VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    qty INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    KEY idx_client_order_items_order (order_id)
);
