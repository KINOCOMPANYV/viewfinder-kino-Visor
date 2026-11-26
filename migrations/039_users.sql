-- v2 módulo 1 (2026-11-26): cuentas propias con rol. El admin de las variables de entorno (ADMIN_USER) sigue valiendo como Desarrollador.
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'cliente',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_users_username (username)
);
