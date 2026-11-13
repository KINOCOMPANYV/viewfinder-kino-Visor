<?php
/**
 * Admin — Mostrar u ocultar una carpeta (y todo su contenido) en el visor.
 * POST /admin/folders/toggle  folder_id, visible=1|0  (header X-CSRF-TOKEN)
 *   field=lists: en vez de ocultar, solo saca la carpeta de las listas de carpetas (inicio / carpeta superior)
 */
require_once __DIR__ . '/../services/DriveIndex.php';

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    jsonResponse(['error' => 'Token CSRF inválido.'], 403);
}

$folderId = trim($_POST['folder_id'] ?? '');
$visible = ($_POST['visible'] ?? '') === '1';

$db = getDB();
$stmt = $db->prepare("SELECT name FROM drive_index WHERE file_id = ? AND is_folder = 1");
$stmt->execute([$folderId]);
$name = $stmt->fetchColumn();
if ($name === false) {
    jsonResponse(['error' => 'Carpeta no encontrada en el índice. Sincroniza las portadas primero.'], 404);
}

// field=lists: solo sacar/poner la carpeta en las listas de carpetas (su contenido sigue visible)
if (($_POST['field'] ?? '') === 'lists') {
    DriveIndex::setFolderInLists($db, $folderId, $visible);
    jsonResponse([
        'ok' => true,
        'visible' => $visible,
        'message' => $visible ? "📂 «{$name}» vuelve a salir como carpeta"
            : "📂 «{$name}» y sus productos ya no salen en inicio; en la búsqueda siguen igual",
    ]);
}

$stats = DriveIndex::setFolderHidden($db, $folderId, !$visible);
jsonResponse([
    'ok' => true,
    'visible' => $visible,
    'stats' => $stats,
    'message' => $visible ? "📂 «{$name}» visible" : "🙈 «{$name}» oculta con todo su contenido",
]);
