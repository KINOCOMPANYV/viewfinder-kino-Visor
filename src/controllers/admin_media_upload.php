<?php
/**
 * Admin Media Upload — Sube archivos a Google Drive.
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
$errors = [];
$files = $_FILES['media_files'];

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
    } else {
        $errMsg = $result['error']['message'] ?? 'error desconocido';
        $errors[] = "{$filename}: {$errMsg}";
    }
}

$_SESSION['flash_success'] = "✅ {$uploaded} archivo(s) subido(s) a Google Drive.";
if (!empty($errors)) {
    $_SESSION['flash_error'] = implode('<br>', $errors);
}

redirect('/admin/media');
