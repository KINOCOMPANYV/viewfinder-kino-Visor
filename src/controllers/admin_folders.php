<?php
/**
 * Admin — Carpetas: mostrar/ocultar cada carpeta del visor (cualquier nivel).
 * GET /admin/folders?id=ID   subcarpetas de ID (por defecto la raíz)
 * GET /admin/folders?q=texto buscar por nombre · ?ver=ocultas  solo las ocultas
 * Lee solo drive_index (la copia local de Drive); el cambio se guarda en folder_settings.
 */
require_once __DIR__ . '/../services/DriveIndex.php';

$db = getDB();
$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');
$folderId = trim($_GET['id'] ?? '') ?: $rootFolderId;
$q = trim($_GET['q'] ?? '');
$onlyHidden = ($_GET['ver'] ?? '') === 'ocultas';
$indexReady = DriveIndex::isReady($db);

// own_hidden = la ocultó el admin; is_hidden = oculta (por ella o por una carpeta superior)
$select = "SELECT d.file_id, d.name, d.parent_id, d.cover_file_id, d.is_hidden,
                  (COALESCE(fs.hidden, 0) = 1 OR (a.is_active = 0 AND a.auto_disabled = 0)) AS own_hidden,
                  (SELECT COUNT(*) FROM drive_index c WHERE c.parent_id = d.file_id AND c.is_folder = 1) AS n_folders,
                  (SELECT COUNT(*) FROM drive_index c WHERE c.parent_id = d.file_id AND c.is_folder = 0) AS n_files
           FROM drive_index d
           LEFT JOIN folder_settings fs ON fs.folder_id = d.file_id
           LEFT JOIN albums a ON a.drive_id = d.file_id
           WHERE d.is_folder = 1";

$folders = [];
$path = [];
$currentHidden = false;
$listMode = $q !== '' || $onlyHidden; // lista plana con la ruta de cada carpeta
if ($indexReady) {
    if ($q !== '') {
        $stmt = $db->prepare("$select AND d.name LIKE ? ORDER BY d.name ASC LIMIT 100");
        $stmt->execute(['%' . addcslashes($q, '%_\\') . '%']);
    } elseif ($onlyHidden) {
        $stmt = $db->prepare("$select AND (fs.hidden = 1 OR (a.is_active = 0 AND a.auto_disabled = 0)) ORDER BY d.name ASC LIMIT 500");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("$select AND d.parent_id = ? ORDER BY CAST(d.name AS UNSIGNED) = 0, CAST(d.name AS UNSIGNED), d.name ASC");
        $stmt->execute([$folderId]);
        $path = DriveIndex::folderPath($db, $folderId, $rootFolderId);
        if ($folderId !== $rootFolderId) {
            $h = $db->prepare("SELECT is_hidden FROM drive_index WHERE file_id = ?");
            $h->execute([$folderId]);
            $currentHidden = (bool) $h->fetchColumn();
        }
    }
    $folders = $stmt->fetchAll();
    if ($listMode) {
        foreach ($folders as &$f) {
            $f['path'] = implode(' / ', array_column(array_slice(DriveIndex::folderPath($db, $f['file_id'], $rootFolderId), 0, -1), 'name'));
        }
        unset($f);
    }
}

$hiddenCount = 0;
try {
    $hiddenCount = (int) $db->query("SELECT COUNT(*) FROM folder_settings WHERE hidden = 1")->fetchColumn();
} catch (\PDOException $e) { /* migración 021 pendiente */ }

include __DIR__ . '/../../templates/admin/folders.php';
