-- Fix Google Drive URLs from non-embeddable uc?id= format to lh3.googleusercontent.com
UPDATE products 
SET cover_image_url = REPLACE(cover_image_url, 'https://drive.google.com/uc?id=', 'https://lh3.googleusercontent.com/d/')
WHERE cover_image_url LIKE '%drive.google.com/uc?id=%';

UPDATE media_assets 
SET storage_path = REPLACE(storage_path, 'https://drive.google.com/uc?id=', 'https://lh3.googleusercontent.com/d/')
WHERE storage_path LIKE '%drive.google.com/uc?id=%';
