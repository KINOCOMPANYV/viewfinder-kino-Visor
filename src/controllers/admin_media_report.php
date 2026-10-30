<?php
/**
 * Listas para renombrar (CSV, se abre en Excel/Sheets). Salen del último "Sincronizar portadas".
 * GET /admin/media/sin-codigo.csv?tipo=archivos   fotos/videos cuyo nombre no es un código
 * GET /admin/media/sin-codigo.csv?tipo=productos  productos sin ninguna foto con su código
 */
$db = getDB();
$tipo = ($_GET['tipo'] ?? 'archivos') === 'productos' ? 'productos' : 'archivos';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . ($tipo === 'productos' ? 'productos_sin_foto' : 'archivos_sin_codigo') . '_' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea las tildes

if ($tipo === 'archivos') {
    fputcsv($out, ['archivo', 'album', 'enlace']);
    $rows = $db->query(
        "SELECT d.file_id, d.name, a.name AS album FROM drive_index d
         LEFT JOIN albums a ON a.drive_id = d.album_id
         WHERE d.is_folder = 0 AND d.is_hidden = 0 AND d.matched_sku IS NULL
           AND (d.mime_type LIKE 'image/%' OR d.mime_type LIKE 'video/%')
           AND (d.album_id IS NULL OR a.is_active = 1)
         ORDER BY a.name, d.name"
    );
    foreach ($rows as $r) {
        fputcsv($out, [$r['name'], $r['album'] ?? '(raíz)', "https://drive.google.com/file/d/{$r['file_id']}/view"]);
    }
} else {
    fputcsv($out, ['sku', 'nombre']);
    $rows = $db->query(
        "SELECT p.sku, p.name FROM products p
         WHERE p.archived = 0
           AND NOT EXISTS (SELECT 1 FROM drive_index d WHERE d.matched_sku = p.sku)
         ORDER BY p.sku"
    );
    foreach ($rows as $r) {
        fputcsv($out, [$r['sku'], $r['name']]);
    }
}
fclose($out);
