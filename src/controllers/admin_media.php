<?php
/**
 * Admin Media — Gestión de archivos en Google Drive.
 * Muestra galería, permite subir y sincronizar.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

$db = getDB();
$drive = new GoogleDriveService();
$folderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

// Verificar si hay conexión a Google
$token = $drive->getValidToken($db);
$isConnected = !empty($token);

// Obtener archivos de Drive si está conectado
$driveFiles = [];
$syncResult = null;

if ($isConnected && !empty($folderId)) {
    $result = $drive->listFiles($folderId);
    $driveFiles = $result['files'] ?? [];
}

// Contar productos para info
$totalProducts = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();

// Obtener productos enlazados (que tienen archivos en Drive con su SKU)
$linkedCount = 0;
if ($isConnected && !empty($driveFiles)) {
    $skus = $db->query("SELECT sku FROM products")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($skus as $sku) {
        foreach ($driveFiles as $file) {
            if (stripos($file['name'], $sku) !== false) {
                $linkedCount++;
                break;
            }
        }
    }
}

include __DIR__ . '/../../templates/admin/media.php';
