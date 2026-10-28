-- Marca los álbumes que desactivó el sync (carpeta fuera de la raíz actual), para
-- reactivarlos solos si la carpeta vuelve. Los desactivados a mano por el admin no se tocan.
ALTER TABLE albums ADD COLUMN auto_disabled TINYINT(1) NOT NULL DEFAULT 0;
