-- Add visibility control and SKU match to drive_cache
-- visible_publico = 1 means file is shown in public search
-- visible_publico = 0 means file is hidden from public
ALTER TABLE drive_cache ADD COLUMN visible_publico TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE drive_cache ADD COLUMN matched_sku VARCHAR(50) DEFAULT NULL;
ALTER TABLE drive_cache ADD INDEX idx_visible (visible_publico);
ALTER TABLE drive_cache ADD INDEX idx_matched_sku (matched_sku);
