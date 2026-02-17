-- Log de descargas (auditoría)
CREATE TABLE IF NOT EXISTS downloads_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    download_type ENUM('photos', 'videos', 'all') NOT NULL,
    ip VARCHAR(45) DEFAULT '',
    user_agent VARCHAR(500) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_product_date (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
