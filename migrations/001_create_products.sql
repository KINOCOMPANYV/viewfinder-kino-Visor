-- Productos (catálogo principal)
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100) DEFAULT '',
    gender ENUM('hombre', 'mujer', 'unisex') DEFAULT 'unisex',
    movement VARCHAR(100) DEFAULT '',
    price_suggested DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('active', 'discontinued') DEFAULT 'active',
    description TEXT,
    cover_image_url VARCHAR(500) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FULLTEXT INDEX idx_search (sku, name),
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_gender (gender)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
