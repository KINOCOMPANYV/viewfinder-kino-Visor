<?php
/**
 * Admin Media Delete — Elimina un archivo de Google Drive.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

$db = getDB();
$drive = new GoogleDriveService();

$token = $drive->getValidToken($db);
if (!$token) {
    $_SESSION['flash_error'] = 'Google Drive no está conectado.';
    redirect('/admin/media');
}

$fileId = $_POST['file_id'] ?? '';
if (empty($fileId)) {
    $_SESSION['flash_error'] = 'ID de archivo no proporcionado.';
    redirect('/admin/media');
}

if ($drive->deleteFile($fileId)) {
    $_SESSION['flash_success'] = '✅ Archivo eliminado de Drive.';
} else {
    $_SESSION['flash_error'] = 'Error al eliminar el archivo.';
}

redirect('/admin/media');
