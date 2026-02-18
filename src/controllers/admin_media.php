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

// Cargar todos los productos (id, sku, cover) para enlazar en la vista
$allProducts = $db->query("SELECT id, sku, cover_image_url FROM products")->fetchAll(PDO::FETCH_ASSOC);
$productsBySku = [];
foreach ($allProducts as $prod) {
    $productsBySku[$prod['sku']] = $prod;
}

// Obtener productos enlazados (que tienen archivos en Drive con su SKU)
$linkedCount = 0;
if ($isConnected && !empty($driveFiles)) {
    foreach ($productsBySku as $sku => $prod) {
        foreach ($driveFiles as $file) {
            if (stripos($file['name'], $sku) !== false) {
                $linkedCount++;
                break;
            }
        }
    }
}

include __DIR__ . '/../../templates/admin/media.php';

