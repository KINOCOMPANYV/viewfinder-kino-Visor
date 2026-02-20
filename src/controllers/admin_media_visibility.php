<?php
/**
 * Admin Media — Toggle visibilidad pública de un archivo.
 * POST /admin/media/visibility
 * Body: file_id (string), visible (0|1)
 */
header('Content-Type: application/json');

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    jsonResponse(['error' => 'Token CSRF inválido.'], 403);
}

$fileId = trim($_POST['file_id'] ?? '');
$visible = intval($_POST['visible'] ?? 1);

if (empty($fileId)) {
    jsonResponse(['error' => 'file_id requerido.'], 400);
}

$db = getDB();

// Upsert: si el archivo ya tiene registro, actualizar; si no, crear uno
$stmt = $db->prepare("SELECT id FROM drive_cache WHERE file_id = ?");
$stmt->execute([$fileId]);

if ($stmt->fetch()) {
    $db->prepare("UPDATE drive_cache SET visible_publico = ? WHERE file_id = ?")->execute([$visible, $fileId]);
} else {
    // Crear registro mínimo con los datos del POST
    $fileName = trim($_POST['file_name'] ?? 'unknown');
    $mimeType = trim($_POST['mime_type'] ?? '');
    $parentId = trim($_POST['parent_id'] ?? '');

    $db->prepare(
        "INSERT INTO drive_cache (file_id, file_name, mime_type, parent_folder_id, visible_publico, cached_at) 
         VALUES (?, ?, ?, ?, ?, NOW())"
    )->execute([$fileId, $fileName, $mimeType, $parentId, $visible]);
}

jsonResponse([
    'ok' => true,
    'file_id' => $fileId,
    'visible_publico' => $visible,
]);
