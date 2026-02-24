-- Add archived column to products (replaces status for public filtering)
-- archived = 0 means "active" (visible publicly)
-- archived = 1 means "archived" (hidden from public)
ALTER TABLE products ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0;
UPDATE products SET archived = 1 WHERE status = 'discontinued';
ALTER TABLE products ADD INDEX idx_archived (archived);
