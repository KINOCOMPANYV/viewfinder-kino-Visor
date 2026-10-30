<?php
/**
 * Admin Media Upload — Sube archivos a Google Drive y enlaza con productos por SKU.
 */
require_once __DIR__ . '/../services/DriveIndex.php';

$db = getDB();

if (!verifyCsrf()) {
    $_SESSION['flash_error'] = 'Token CSRF inválido.';
    redirect('/admin/media');
}

$drive = new GoogleDriveService();
$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

// Usar la carpeta actual si se envió desde el form, si no la raíz
$folderId = !empty($_POST['upload_folder']) ? $_POST['upload_folder'] : $rootFolderId;

$token = $drive->getValidToken($db);
if (!$token || empty($folderId)) {
    $_SESSION['flash_error'] = 'Google Drive no está conectado.';
    redirect('/admin/media');
}

if (empty($_FILES['media_files'])) {
    $_SESSION['flash_error'] = 'No se seleccionaron archivos.';
    redirect('/admin/media');
}

$uploaded = 0;
$linked = 0;
$errors = [];
$files = $_FILES['media_files'];

$uploadedIds = [];

for ($i = 0; $i < count($files['name']); $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = "{$files['name'][$i]}: error de subida";
        continue;
    }

    $filename = $files['name'][$i];
    $tmpPath = $files['tmp_name'][$i];
    $mimeType = $files['type'][$i] ?: 'application/octet-stream';

    // Validar tipo de archivo
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'video/webm'];
    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = "{$filename}: tipo no permitido ({$mimeType})";
        continue;
    }

    $result = $drive->uploadFile($folderId, $filename, $tmpPath, $mimeType);

    if ($result && !empty($result['id'])) {
        // Hacer el archivo público para que se pueda ver
        $isPublic = $drive->makePublic($result['id']);
        DriveIndex::addUploadedFile($db, $result + ['mimeType' => $mimeType, 'size' => $files['size'][$i] ?? 0], $folderId, $isPublic);
        $uploaded++;
        $uploadedIds[] = $result['id'];
    } else {
        $errMsg = $result['error']['message'] ?? 'error desconocido';
        $errors[] = "{$filename}: {$errMsg}";
    }
}

// Enlazar con la regla única del visor (nombre = código) y recalcular portadas
if ($uploadedIds) {
    DriveIndex::relink($db);
    try {
        $q = $db->prepare("SELECT COUNT(*) FROM drive_index WHERE matched_sku IS NOT NULL AND file_id IN (" . implode(',', array_fill(0, count($uploadedIds), '?')) . ")");
        $q->execute($uploadedIds);
        $linked = (int) $q->fetchColumn();
    } catch (\PDOException $e) { /* índice aún no creado */ }
}

$msg = "✅ {$uploaded} archivo(s) subido(s) a Google Drive.";
if ($linked > 0) {
    $msg .= " 🔗 {$linked} enlazado(s) con productos.";
}
if ($uploaded > $linked && DriveIndex::isReady($db)) {
    $msg .= ' ⚠️ ' . ($uploaded - $linked) . ' con nombre que no es un código (renómbralos como el código).';
}
$_SESSION['flash_success'] = $msg;
if (!empty($errors)) {
    $_SESSION['flash_error'] = implode('<br>', $errors);
}

redirect('/admin/media');
