-- Sistema de colas para tareas pesadas en segundo plano
-- Almacena y rastrea tareas como sync masivo, generación de ZIPs, etc.
CREATE TABLE IF NOT EXISTS task_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_type VARCHAR(50) NOT NULL COMMENT 'sync_covers, sync_sheets, generate_zip, etc.',
    payload JSON DEFAULT NULL COMMENT 'Datos de la tarea en formato JSON',
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    progress INT DEFAULT 0 COMMENT 'Porcentaje de progreso (0-100)',
    result TEXT DEFAULT NULL COMMENT 'Resultado o error de la tarea',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    
    INDEX idx_status (status),
    INDEX idx_type (task_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
