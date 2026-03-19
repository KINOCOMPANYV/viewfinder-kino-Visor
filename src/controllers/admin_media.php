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

// Enlazados: usar pre-index global (una sola llamada API) con caché de sesión
$linkedCount = 0;
if ($isConnected && !empty($rootFolderId)) {
    // Caché en sesión (5 min TTL) para evitar re-indexar Drive en cada carga
    $cacheKey = 'media_linked_count_cache';
    $cacheTimeKey = 'media_linked_count_time';
    $cached = $_SESSION[$cacheKey] ?? null;
    $cacheTime = $_SESSION[$cacheTimeKey] ?? 0;
    $cacheExpired = (time() - $cacheTime) > 300; // 5 minutos

    if ($cached !== null && !$cacheExpired) {
        $linkedCount = $cached;
    } else {
        // Pre-indexar TODOS los archivos de Drive con una sola llamada API
        $allDriveFilesIndex = $drive->listAllMediaFiles($rootFolderId);
        $allDriveFiles = $allDriveFilesIndex['files'] ?? [];

        // Construir HashMap: nombre_archivo → true (para O(1) lookup)
        $driveFileStems = [];
        foreach ($allDriveFiles as $file) {
            $stem = strtolower(pathinfo($file['name'] ?? '', PATHINFO_FILENAME));
            $driveFileStems[$stem] = true;
        }

        foreach ($productsBySku as $sku => $prod) {
            $skuLower = strtolower($sku);
            // Check exact match or prefix match in HashMap
            if (isset($driveFileStems[$skuLower])) {
                $linkedCount++;
                continue;
            }
            // Check prefix matches: any stem that starts with SKU
            foreach ($driveFileStems as $stem => $_) {
                if (stripos($stem, $skuLower) === 0) {
                    $nextPos = strlen($skuLower);
                    if ($nextPos >= strlen($stem) || !ctype_digit($stem[$nextPos])) {
                        $linkedCount++;
                        break;
                    }
                }
            }
        }

        $_SESSION[$cacheKey] = $linkedCount;
        $_SESSION[$cacheTimeKey] = time();
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
