<?php
/**
 * Admin Albums — Gestión de Álbumes (carpetas principales de Drive).
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

$db = getDB();
$drive = new GoogleDriveService();
$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

$token = $drive->getValidToken($db);
$isConnected = !empty($token);

if (!$isConnected) {
    redirect('/admin/login');
}

// 1. Obtener álbumes de la DB
$albums = $db->query("SELECT * FROM albums ORDER BY order_priority DESC, name ASC")->fetchAll();

// 2. Obtener carpetas reales de Drive para comparar/importar
$driveFolders = [];
if ($rootFolderId) {
    $result = $drive->listFiles($rootFolderId);
    foreach ($result['files'] as $item) {
        if (($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
            $driveFolders[] = $item;
        }
    }
}

// Procesar acciones POST (Update visibility, order, icon)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();

    if ($_POST['action'] === 'update_album') {
        $driveId = $_POST['drive_id'];
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $order = (int) ($_POST['order_priority'] ?? 0);
        $iconUrl = $_POST['icon_url'] ?? '';

        $stmt = $db->prepare("UPDATE albums SET is_active = ?, order_priority = ?, icon_url = ? WHERE drive_id = ?");
        $stmt->execute([$isActive, $order, $iconUrl, $driveId]);

        $_SESSION['flash'] = 'Álbum actualizado correctamente.';
        redirect('/admin/albums');
    }

    if ($_POST['action'] === 'sync_folders') {
        // Importar carpetas nuevas de Drive que no estén en DB
        $count = 0;
        foreach ($driveFolders as $df) {
            $check = $db->prepare("SELECT drive_id FROM albums WHERE drive_id = ?");
            $check->execute([$df['id']]);
            if (!$check->fetch()) {
                $ins = $db->prepare("INSERT INTO albums (drive_id, name) VALUES (?, ?)");
                $ins->execute([$df['id'], $df['name']]);
                $count++;
            }
        }
        $_SESSION['flash'] = "Sincronización completada. $count nuevas carpetas encontradas.";
        redirect('/admin/albums');
    }

    if ($_POST['action'] === 'sync_covers') {
        // Auto-asignar portadas: buscar dentro de cada carpeta un archivo
        // cuyo nombre (sin extensión) coincida con el nombre de la carpeta
        $assigned = 0;
        $errors = [];
        $fileIdsToPublish = [];
        $updatesQueue = [];

        // Obtener todos los álbumes
        $allAlbums = $db->query("SELECT drive_id, name, icon_url FROM albums")->fetchAll();

        foreach ($allAlbums as $album) {
            try {
                // Listar archivos dentro de esta carpeta
                $result = $drive->listFiles($album['drive_id']);
                $files = $result['files'] ?? [];

                $folderName = $album['name'];
                $bestMatch = null;

                foreach ($files as $file) {
                    $mime = $file['mimeType'] ?? '';
                    // Solo considerar imágenes
                    if (!str_starts_with($mime, 'image/')) {
                        continue;
                    }

                    // Comparar nombre del archivo (sin extensión) con nombre de carpeta
                    $fileBaseName = pathinfo($file['name'] ?? '', PATHINFO_FILENAME);

                    if (strcasecmp($fileBaseName, $folderName) === 0) {
                        $bestMatch = $file;
                        break; // Match exacto, no buscar más
                    }
                }

                if ($bestMatch) {
                    $fileIdsToPublish[] = $bestMatch['id'];
                    $coverUrl = "https://lh3.googleusercontent.com/d/{$bestMatch['id']}=s400";
                    $updatesQueue[] = [
                        'drive_id' => $album['drive_id'],
                        'icon_url' => $coverUrl,
                    ];
                    $assigned++;
                }
            } catch (\Exception $e) {
                $errors[] = "Carpeta '{$album['name']}': {$e->getMessage()}";
            }
        }

        // Hacer públicos en batch
        if (!empty($fileIdsToPublish)) {
            $drive->makePublicBatch($fileIdsToPublish);
        }

        // Actualizar DB
        $updateStmt = $db->prepare("UPDATE albums SET icon_url = ? WHERE drive_id = ?");
        foreach ($updatesQueue as $upd) {
            $updateStmt->execute([$upd['icon_url'], $upd['drive_id']]);
        }

        $errMsg = !empty($errors) ? ' Errores: ' . implode(', ', array_slice($errors, 0, 5)) : '';
        $_SESSION['flash'] = "✅ Portadas asignadas: $assigned de " . count($allAlbums) . " carpetas.{$errMsg}";
        redirect('/admin/albums');
    }
}

include __DIR__ . '/../../templates/admin/albums.php';
