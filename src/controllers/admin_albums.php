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
        // Auto-asignar portadas: buscar en la carpeta "PORTADAS" del Drive
        // Las fotos dentro de esa carpeta deben tener el mismo nombre que la carpeta/álbum
        $assigned = 0;
        $errors = [];
        $fileIdsToPublish = [];
        $updatesQueue = [];

        // 1) Buscar la carpeta "PORTADAS" en Drive (dentro de la raíz)
        $portadasFolderId = null;
        foreach ($driveFolders as $df) {
            if (strcasecmp($df['name'], 'PORTADAS') === 0) {
                $portadasFolderId = $df['id'];
                break;
            }
        }

        if (!$portadasFolderId) {
            $_SESSION['flash'] = '⚠️ No se encontró la carpeta "PORTADAS" en Drive. Créala y sube las fotos de portada con el mismo nombre de cada carpeta.';
            redirect('/admin/albums');
        }

        // 2) Listar TODOS los archivos dentro de PORTADAS (una sola llamada)
        $portadasResult = $drive->listFiles($portadasFolderId);
        $portadasFiles = $portadasResult['files'] ?? [];

        // Construir índice: nombre_sin_extension → archivo
        $coverIndex = [];
        foreach ($portadasFiles as $pf) {
            $mime = $pf['mimeType'] ?? '';
            if (!str_starts_with($mime, 'image/')) continue;
            $baseName = strtolower(pathinfo($pf['name'] ?? '', PATHINFO_FILENAME));
            $coverIndex[$baseName] = $pf;
        }

        // 3) Matchear álbumes con archivos de PORTADAS
        $allAlbums = $db->query("SELECT drive_id, name, icon_url FROM albums")->fetchAll();

        foreach ($allAlbums as $album) {
            $albumNameLower = strtolower($album['name']);
            
            if (isset($coverIndex[$albumNameLower])) {
                $match = $coverIndex[$albumNameLower];
                $fileIdsToPublish[] = $match['id'];
                $coverUrl = "https://lh3.googleusercontent.com/d/{$match['id']}=s400";
                $updatesQueue[] = [
                    'drive_id' => $album['drive_id'],
                    'icon_url' => $coverUrl,
                ];
                $assigned++;
            }
        }

        // 4) Hacer públicos en batch
        if (!empty($fileIdsToPublish)) {
            $drive->makePublicBatch($fileIdsToPublish);
        }

        // 5) Actualizar DB
        $updateStmt = $db->prepare("UPDATE albums SET icon_url = ? WHERE drive_id = ?");
        foreach ($updatesQueue as $upd) {
            $updateStmt->execute([$upd['icon_url'], $upd['drive_id']]);
        }

        $noMatch = count($allAlbums) - $assigned;
        
        // Debug info: mostrar nombres para diagnóstico
        $albumNames = array_map(fn($a) => $a['name'], array_slice($allAlbums, 0, 10));
        $coverNames = array_slice(array_keys($coverIndex), 0, 10);
        $unmatchedAlbums = [];
        foreach ($allAlbums as $a) {
            if (!isset($coverIndex[strtolower($a['name'])])) {
                $unmatchedAlbums[] = $a['name'];
            }
        }
        $debugInfo = " | 📋 Álbumes DB: [" . implode(', ', $albumNames) . "]"
                   . " | 🖼️ Fotos PORTADAS: [" . implode(', ', $coverNames) . "]";
        if (!empty($unmatchedAlbums)) {
            $debugInfo .= " | ❌ Sin match: [" . implode(', ', array_slice($unmatchedAlbums, 0, 10)) . "]";
        }
        
        $_SESSION['flash'] = "✅ Portadas asignadas: $assigned de " . count($allAlbums) . " carpetas. "
            . count($coverIndex) . " imágenes en PORTADAS. "
            . count($portadasFiles) . " archivos totales." . $debugInfo;
        redirect('/admin/albums');
    }
}

include __DIR__ . '/../../templates/admin/albums.php';
