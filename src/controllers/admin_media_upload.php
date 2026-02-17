<?php
/**
 * Admin Media Upload — Sube archivos a Google Drive y enlaza con productos por SKU.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

$db = getDB();
$drive = new GoogleDriveService();
$folderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

$token = $drive->getValidToken($db);
if (!$token || empty($folderId)) {
    $_SESSION['flash_error'] = 'Google Drive no está conectado.';
    redirect('/admin/media');
}

if (empty($_FILES['media_files'])) {
    $_SESSION['flash_error'] = 'No se seleccionaron archivos.';
    redirect('/admin/media');
}

$uploaded = 0;
$linked = 0;
$errors = [];
$files = $_FILES['media_files'];

// Cargar todos los productos una sola vez (fuera del loop)
$allProducts = $db->query("SELECT id, sku, cover_image_url FROM products")->fetchAll(PDO::FETCH_ASSOC);

for ($i = 0; $i < count($files['name']); $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = "{$files['name'][$i]}: error de subida";
        continue;
    }

    $filename = $files['name'][$i];
    $tmpPath = $files['tmp_name'][$i];
    $mimeType = $files['type'][$i] ?: 'application/octet-stream';

    // Validar tipo de archivo
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'video/webm'];
    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = "{$filename}: tipo no permitido ({$mimeType})";
        continue;
    }

    $result = $drive->uploadFile($folderId, $filename, $tmpPath, $mimeType);

    if ($result && !empty($result['id'])) {
        // Hacer el archivo público para que se pueda ver
        $drive->makePublic($result['id']);
        $uploaded++;

        // --- Enlazar con producto por SKU ---
        $driveUrl = "https://drive.google.com/uc?id={$result['id']}";
        $matchedProduct = null;

        foreach ($allProducts as $prod) {
            if (stripos($filename, $prod['sku']) !== false) {
                $matchedProduct = $prod;
                break;
            }
        }

        if ($matchedProduct) {
            try {
                // Insertar en media_assets
                $mediaType = str_starts_with($mimeType, 'video/') ? 'video' : 'image';
                $insertMedia = $db->prepare(
                    "INSERT INTO media_assets (product_id, type, filename, storage_path, file_size)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $insertMedia->execute([
                    $matchedProduct['id'],
                    $mediaType,
                    $filename,
                    $driveUrl,
                    $files['size'][$i] ?? 0
                ]);

                // Actualizar cover_image_url si está vacía y es imagen
                if ($mediaType === 'image' && empty($matchedProduct['cover_image_url'])) {
                    $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?")
                        ->execute([$driveUrl, $matchedProduct['id']]);
                }

                $linked++;
            } catch (\Exception $e) {
                // No bloquear la subida si falla el enlace
                $errors[] = "{$filename}: subido pero no se pudo enlazar ({$e->getMessage()})";
            }
        }
    } else {
        $errMsg = $result['error']['message'] ?? 'error desconocido';
        $errors[] = "{$filename}: {$errMsg}";
    }
}

$msg = "✅ {$uploaded} archivo(s) subido(s) a Google Drive.";
if ($linked > 0) {
    $msg .= " 🔗 {$linked} enlazado(s) con productos.";
}
$_SESSION['flash_success'] = $msg;
if (!empty($errors)) {
    $_SESSION['flash_error'] = implode('<br>', $errors);
}

redirect('/admin/media');
