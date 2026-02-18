<?php
/**
 * Admin Media — Gestión de archivos en Google Drive.
 * Muestra galería con navegación de carpetas, permite subir y sincronizar.
 * Soporta navegación multi-nivel con breadcrumb (Raíz > Marca > Referencia).
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

$db = getDB();
$drive = new GoogleDriveService();
$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

// Navegación de carpetas: ?folder=xxx&path=[{id,name},...]
$currentFolderId = $_GET['folder'] ?? $rootFolderId;
$currentFolderName = 'Raíz';
$isRoot = ($currentFolderId === $rootFolderId);
$folderLabel = $_GET['name'] ?? '';

// Breadcrumb path: array de {id, name} desde la raíz hasta la carpeta actual
$breadcrumbPath = [];
if (!empty($_GET['path'])) {
    $decoded = json_decode($_GET['path'], true);
    if (is_array($decoded)) {
        $breadcrumbPath = $decoded;
    }
}

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

// Contar productos sin portada (para mostrar en botón de sync)
$withoutCover = 0;
foreach ($productsBySku as $prod) {
    if (empty($prod['cover_image_url']))
        $withoutCover++;
}

// Preparar path JSON para pasar al template
$currentPathJson = json_encode($breadcrumbPath, JSON_UNESCAPED_UNICODE);

include __DIR__ . '/../../templates/admin/media.php';
