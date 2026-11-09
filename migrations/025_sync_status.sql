-- Última sincronización con éxito y último error de cada sync (hoy: 'hoja').
-- Antes "Última actualización" era MAX(products.updated_at): solo cambiaba si alguna fila cambiaba.
CREATE TABLE IF NOT EXISTS sync_status (
    name VARCHAR(30) NOT NULL PRIMARY KEY,
    last_ok_at DATETIME NULL,
    last_ok_message TEXT NULL,
    last_error_at DATETIME NULL,
    last_error TEXT NULL
);
