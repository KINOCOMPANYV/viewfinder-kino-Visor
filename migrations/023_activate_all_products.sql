-- Activación única pedida por el usuario (2026-10-30): todos los productos quedan activos.
-- Después solo el admin vuelve a desactivar. Visibles = activos y en la hoja maestra.
UPDATE products SET is_active = 1 WHERE is_active = 0;
UPDATE products SET archived = IF(is_active = 1 AND in_sheet = 1, 0, 1) WHERE archived <> IF(is_active = 1 AND in_sheet = 1, 0, 1);
