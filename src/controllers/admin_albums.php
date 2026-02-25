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
}

include __DIR__ . '/../../templates/admin/albums.php';
