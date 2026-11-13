-- Carpeta "apagada como carpeta": no sale en la lista de carpetas de inicio ni en la de su carpeta superior,
-- pero su contenido (fotos, productos) sigue visible y la carpeta sigue saliendo en la búsqueda y por enlace.
-- Distinto de folder_settings.hidden, que oculta la carpeta con todo lo que tiene dentro.
ALTER TABLE folder_settings ADD COLUMN hide_in_lists TINYINT(1) NOT NULL DEFAULT 0;
