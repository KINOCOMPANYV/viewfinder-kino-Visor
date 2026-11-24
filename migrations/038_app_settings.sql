-- Ajustes simples del admin (2026-11-24). Hoy: multiplicador del precio público (SYS x N) y si se muestra el precio mayorista.
CREATE TABLE IF NOT EXISTS app_settings (
    name VARCHAR(40) NOT NULL PRIMARY KEY,
    value VARCHAR(255) NOT NULL DEFAULT ''
);
