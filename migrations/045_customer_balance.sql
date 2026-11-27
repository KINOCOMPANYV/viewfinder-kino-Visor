-- v2 (2026-11-27): cartera del cliente. balance > 0 = debe; 0 = al día. Lo editan Admin y Facturación en /clientes.
ALTER TABLE customers ADD COLUMN balance DECIMAL(14,2) NOT NULL DEFAULT 0;
ALTER TABLE customers ADD COLUMN balance_note VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE customers ADD COLUMN balance_at TIMESTAMP NULL DEFAULT NULL;
