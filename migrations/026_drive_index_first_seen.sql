-- Novedades (etapa H): cuándo vio el sync cada archivo por primera vez. Re-sincronizar no lo cambia
-- (el UPSERT de DriveIndex::sync no lo toca). Lo que ya estaba queda en NULL = "de antes", así el primer
-- deploy no marca 12 000 archivos como nuevos.
ALTER TABLE drive_index ADD COLUMN first_seen_at DATETIME NULL DEFAULT NULL;
CREATE INDEX idx_first_seen ON drive_index (first_seen_at);
