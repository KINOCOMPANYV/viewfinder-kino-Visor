-- v2 Tienda (2026-11-27): categorías «Promocionales» (salen primero en /tienda). Se marcan en Productos → Categorías.
CREATE TABLE IF NOT EXISTS category_settings (
    category VARCHAR(100) NOT NULL PRIMARY KEY,
    is_promo TINYINT(1) NOT NULL DEFAULT 0
);
