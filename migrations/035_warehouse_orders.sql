-- Pedidos a alistar (2026-11-21, decisión del dueño; etapa B3 de docs/PLAN_PEDIDO_BODEGA.md).
-- Lista Bodega → «📦 Enviar a pedidos a alistar» guarda aquí el pedido leído en el Extractor (order_json = kinoPedido).
-- El panel /pedido-bodega?id=N guarda el avance del checklist en lines_json. status: pendiente (rojo) | listo (verde).
-- order_key = huella del pedido (n.º, cliente, códigos y cantidades) para no crearlo dos veces.
CREATE TABLE IF NOT EXISTS warehouse_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_key CHAR(40) NOT NULL,
    number VARCHAR(100) NOT NULL DEFAULT '',
    client VARCHAR(255) NOT NULL DEFAULT '',
    order_date VARCHAR(100) NOT NULL DEFAULT '',
    items_count INT NOT NULL DEFAULT 0,
    checked_count INT NOT NULL DEFAULT 0,
    order_json MEDIUMTEXT NOT NULL,
    lines_json MEDIUMTEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    checked_by VARCHAR(80) NOT NULL DEFAULT '',
    total_final DECIMAL(14,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    UNIQUE KEY uq_warehouse_order_key (order_key),
    KEY idx_warehouse_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
