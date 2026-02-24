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

    // Cross-reference with drive_cache for visibility state
    if (!empty($driveFiles)) {
        $visibilityMap = [];
        try {
            $fileIds = array_map(fn($f) => $f['id'], $driveFiles);
            $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
            $visStmt = $db->prepare("SELECT file_id, visible_publico FROM drive_cache WHERE file_id IN ({$placeholders})");
            $visStmt->execute($fileIds);
            while ($row = $visStmt->fetch(PDO::FETCH_ASSOC)) {
                $visibilityMap[$row['file_id']] = (int) $row['visible_publico'];
            }
        } catch (Exception $e) {
            // Migration 009 might not have run yet — ignore
        }
        // Attach visibility to each file (default = 1 if not in cache)
        foreach ($driveFiles as &$file) {
            $file['visible_publico'] = $visibilityMap[$file['id']] ?? 1;
        }
        unset($file);
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
// Optimización: listar TODOS los archivos recursivamente una sola vez,
// luego hacer el matching localmente en PHP (en vez de N llamadas a la API).
$linkedCount = 0;
if ($isConnected && !empty($rootFolderId)) {
    // Recopilar todos los archivos (raíz + subcarpetas)
    $allDriveFiles = $driveFiles; // archivos de la carpeta actual
    if ($isRoot) {
        // Agregar archivos de subcarpetas recursivamente
        $allDriveFiles = collectAllFilesRecursive($drive, $rootFolderId);
    }

    foreach ($productsBySku as $sku => $prod) {
        foreach ($allDriveFiles as $file) {
            if (skuMatchesFilename($sku, $file['name'])) {
                $linkedCount++;
                break;
            }
        }
    }
}

/**
 * Recopila TODOS los archivos de una carpeta y subcarpetas (recursivo).
 * Hace pocas llamadas a la API (una por carpeta) en vez de una por producto.
 */
function collectAllFilesRecursive(GoogleDriveService $drive, string $folderId, int $depth = 0, int $maxDepth = 3): array
{
    if ($depth >= $maxDepth)
        return [];

    $result = $drive->listFiles($folderId);
    $items = $result['files'] ?? [];

    $files = [];
    $subfolders = [];

    foreach ($items as $item) {
        if (($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
            $subfolders[] = $item;
        } else {
            $files[] = $item;
        }
    }

    // Recurrir en subcarpetas
    foreach ($subfolders as $sub) {
        $subFiles = collectAllFilesRecursive($drive, $sub['id'], $depth + 1, $maxDepth);
        $files = array_merge($files, $subFiles);
    }

    return $files;
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
