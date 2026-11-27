-- v2 (2026-11-27): cartera del cliente. balance > 0 = debe; 0 = al día. debt_since = desde cuándo debe (alertas a 20 y 40 días). Lo editan Admin y Facturación en /clientes.
ALTER TABLE customers ADD COLUMN balance DECIMAL(14,2) NOT NULL DEFAULT 0;
ALTER TABLE customers ADD COLUMN balance_note VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE customers ADD COLUMN balance_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE customers ADD COLUMN debt_since DATE NULL DEFAULT NULL;
