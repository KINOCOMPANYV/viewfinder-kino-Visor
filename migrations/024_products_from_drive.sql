-- Productos "solo Drive" (decisión del dueño, 2026-10-30): una foto cuyo nombre es un código que
-- no está en la hoja maestra crea el producto (nombre = código) para que la foto salga igual.
-- from_drive = 1 lo mantiene visible aunque in_sheet = 0. Lo recalcula DriveIndex::relink().
-- archived = NOT (is_active AND (in_sheet OR from_drive)), ver refreshProductVisibility().
ALTER TABLE products ADD COLUMN from_drive TINYINT(1) NOT NULL DEFAULT 0;
