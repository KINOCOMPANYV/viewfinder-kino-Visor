<?php
/**
 * Admin Media — Gestión de archivos en Google Drive.
 * Muestra galería con navegación de carpetas, permite subir y sincronizar.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

$db = getDB();
$drive = new GoogleDriveService();
$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

// Navegación de carpetas: ?folder=xxx
$currentFolderId = $_GET['folder'] ?? $rootFolderId;
$currentFolderName = 'Raíz';
$isRoot = ($currentFolderId === $rootFolderId);

// Verificar si hay conexión a Google
$token = $drive->getValidToken($db);
$isConnected = !empty($token);

// Obtener archivos y subcarpetas de la carpeta actual
$driveFiles = [];
$subfolders = [];
$syncResult = null;

if ($isConnected && !empty($currentFolderId)) {
    $result = $drive->listFiles($currentFolderId);
    $allItems = $result['files'] ?? [];

    // Separar carpetas de archivos
    foreach ($allItems as $item) {
        if (($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
            $subfolders[] = $item;
        } else {
            $driveFiles[] = $item;
        }
    }
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

// Auto-asignar imagen principal: busca en TODAS las carpetas usando findBySku
$autoAssigned = 0;
if ($isConnected && $isRoot) {
    $updateStmt = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
    foreach ($productsBySku as $sku => $prod) {
        if (!empty($prod['cover_image_url']))
            continue;

        // findBySku busca en carpeta raíz Y subcarpetas
        $skuFiles = $drive->findBySku($rootFolderId, $sku);
        foreach ($skuFiles as $file) {
            $isImage = str_starts_with($file['mimeType'] ?? '', 'image/');
            if ($isImage) {
                $coverUrl = "https://lh3.googleusercontent.com/d/{$file['id']}";
                $updateStmt->execute([$coverUrl, $prod['id']]);
                $productsBySku[$sku]['cover_image_url'] = $coverUrl;
                $autoAssigned++;
                break;
            }
        }
    }
    if ($autoAssigned > 0) {
        $_SESSION['flash_success'] = ($_SESSION['flash_success'] ?? '') .
            " ⭐ {$autoAssigned} producto(s) recibieron imagen principal automáticamente.";
    }
}

include __DIR__ . '/../../templates/admin/media.php';
