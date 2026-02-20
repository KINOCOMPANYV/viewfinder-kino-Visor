<?php
/**
 * Controlador: Borrado de caché del sistema.
 * POST /admin/cache/clear
 * Parámetro: cache_type = media_search | drive_cache | zip_files | all
 */

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
        $db->exec("DELETE FROM drive_cache");
        $results[] = 'Drive Cache limpiada';
    }

    // --- ZIP Files Cache ---
    if ($type === 'zip_files' || $type === 'all') {
        $dir = BASE_DIR . '/storage/zip_cache';
        $count = 0;
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.gitkeep') {
                    unlink($file);
                    $count++;
                }
            }
        }
        $results[] = "ZIP Cache limpiada ({$count} archivos eliminados)";
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
