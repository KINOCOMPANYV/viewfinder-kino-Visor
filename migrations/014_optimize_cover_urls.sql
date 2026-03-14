-- Migración 014: Optimizar URLs de portada existentes
-- Agrega =s400 a las URLs de lh3.googleusercontent.com que no tengan ya un parámetro de tamaño.
-- Esto reduce el peso de ~3-5MB (resolución original) a ~30-80KB (thumbnail 400px).
-- No afecta URLs de video ni URLs que ya tengan =s.

UPDATE products 
SET cover_image_url = CONCAT(cover_image_url, '=s400'),
    updated_at = NOW()
WHERE cover_image_url IS NOT NULL 
  AND cover_image_url != ''
  AND cover_image_url LIKE '%lh3.googleusercontent.com/d/%'
  AND cover_image_url NOT LIKE '%=s%'
  AND cover_image_url NOT LIKE '[VIDEO]%';
