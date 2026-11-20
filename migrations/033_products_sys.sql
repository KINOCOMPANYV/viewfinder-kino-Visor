-- Datos de SYS en el visor (2026-11-20). Al subir el archivo de inventario SYS (Admin → 📦 Inventario) se guardan
-- aquí al momento, sin esperar a la Hoja master. El sync de la Hoja master no toca estas columnas.
-- sys_price = Precio SYS (no se muestra al público por ahora) · sys_stock = existencias SYS · sys_at = fecha de la subida.
ALTER TABLE products ADD COLUMN sys_price DECIMAL(10,2) NULL;
ALTER TABLE products ADD COLUMN sys_stock INT NULL;
ALTER TABLE products ADD COLUMN sys_at DATETIME NULL;
