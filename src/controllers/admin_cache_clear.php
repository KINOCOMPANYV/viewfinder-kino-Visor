<?php
/**
 * Controlador: Borrado de caché del sistema.
 * POST /admin/cache/clear
 * Parámetro: cache_type = media_search | drive_cache | all
 */

if (!verifyCsrf()) {
    $_SESSION['cache_flash'] = ['type' => 'error', 'msg' => '❌ Token CSRF inválido. Recarga la página.'];
    redirect('/admin');
}

$db = getDB();
$type = $_POST['cache_type'] ?? '';
$results = [];

try {
    // --- Media Search Cache ---
    if ($type === 'media_search' || $type === 'all') {
        $db->exec("DELETE FROM media_search_cache");
        $results[] = 'Media Search Cache limpiada';
    }

    // --- Drive Cache ---
    if ($type === 'drive_cache' || $type === 'all') {
        // Conservar las filas de archivos ocultos: ahí vive la marca visible_publico = 0
        // (antes se borraba todo y las fotos ocultas volvían a ser públicas).
        $db->exec("DELETE FROM drive_cache WHERE visible_publico <> 0");
        $results[] = 'Drive Cache limpiada (se conservan las fotos ocultas)';
    }


    if (empty($results)) {
        $_SESSION['cache_flash'] = ['type' => 'warning', 'msg' => 'Tipo de caché no reconocido.'];
    } else {
        $_SESSION['cache_flash'] = ['type' => 'success', 'msg' => '✅ ' . implode(' · ', $results)];
    }
} catch (Exception $e) {
    $_SESSION['cache_flash'] = ['type' => 'error', 'msg' => '❌ Error: ' . $e->getMessage()];
}

redirect('/admin');
