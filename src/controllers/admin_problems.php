<?php
/**
 * Admin — Problemas de fotos (GET /admin/problemas, etapa G). Solo BD (drive_index + products).
 * Reúne en una página lo que antes estaba repartido entre Media (CSV) y Sincronizar ("¿Por qué no sale la foto?"):
 *   sinfoto   productos visibles sin ninguna foto propia ni de la familia
 *   ocultas   productos visibles cuya foto existe pero está en una carpeta oculta
 *   archivos  fotos/videos cuyo nombre no es un código (para renombrar)
 *   hoja      filas de la hoja sin precio sugerido o sin nombre
 * Cada lista muestra el motivo y la ruta en Drive; ?csv=1 descarga la lista completa.
 */
$db = getDB();
$tab = $_GET['tab'] ?? 'sinfoto';
$tabs = [
    'sinfoto' => '📷 Sin foto',
    'ocultas' => '🙈 Foto en carpeta oculta',
    'archivos' => '📝 Archivos sin código',
    'hoja' => '📋 Datos incompletos',
];
if (!isset($tabs[$tab])) $tab = 'sinfoto';
$limit = 300;

// Ruta de una carpeta en Drive ("PRODUCTOS / POEDAGAR / 2024"), con todas las carpetas en memoria (una consulta)
$folders = [];
foreach ($db->query("SELECT file_id, name, parent_id FROM drive_index WHERE is_folder = 1") as $f) $folders[$f['file_id']] = $f;
$pathOf = function (?string $id) use ($folders): string {
    $parts = [];
    for ($i = 0; $id && isset($folders[$id]) && $i < 12; $i++) { $parts[] = $folders[$id]['name']; $id = $folders[$id]['parent_id']; }
    return $parts ? implode(' / ', array_reverse($parts)) : '(raíz)';
};

$noVisiblePhoto = "NOT EXISTS (SELECT 1 FROM drive_index d WHERE d.is_folder = 0 AND d.is_hidden = 0
                     AND (d.matched_sku = p.sku OR (p.parent_sku IS NOT NULL AND d.matched_sku = p.parent_sku)))";
$queries = [
    'sinfoto' => "SELECT p.sku, p.name, p.brand FROM products p
                  WHERE p.archived = 0 AND (p.cover_image_url IS NULL OR p.cover_image_url = '')
                    AND $noVisiblePhoto
                    AND NOT EXISTS (SELECT 1 FROM drive_index d WHERE d.is_folder = 0 AND d.matched_sku = p.sku)
                  ORDER BY p.brand, p.sku",
    'ocultas' => "SELECT p.sku, p.name, p.brand, MIN(d.parent_id) AS parent_id, COUNT(*) AS n FROM products p
                  JOIN drive_index d ON d.is_folder = 0 AND d.is_hidden = 1 AND d.matched_sku = p.sku
                  WHERE p.archived = 0 AND $noVisiblePhoto
                  GROUP BY p.sku, p.name, p.brand ORDER BY p.brand, p.sku",
    'archivos' => "SELECT d.file_id, d.name, d.parent_id FROM drive_index d
                   WHERE d.is_folder = 0 AND d.is_hidden = 0 AND d.matched_sku IS NULL
                     AND (d.mime_type LIKE 'image/%' OR d.mime_type LIKE 'video/%')
                   ORDER BY d.parent_id, d.name",
    'hoja' => "SELECT p.sku, p.name, p.brand, p.price_suggested FROM products p
               WHERE p.in_sheet = 1 AND (p.price_suggested IS NULL OR p.price_suggested <= 0 OR p.name = p.sku OR p.name = '')
               ORDER BY p.brand, p.sku",
];
$counts = [];
foreach ($queries as $k => $sql) {
    try { $counts[$k] = (int) $db->query("SELECT COUNT(*) FROM ($sql) t")->fetchColumn(); } catch (\PDOException $e) { $counts[$k] = 0; }
}
$rows = $db->query($queries[$tab] . (isset($_GET['csv']) ? '' : " LIMIT $limit"))->fetchAll(PDO::FETCH_ASSOC);

// Cada fila → columnas legibles (motivo y ruta)
$table = [];
foreach ($rows as $r) {
    switch ($tab) {
        case 'sinfoto':
            $table[] = ['Código' => $r['sku'], 'Nombre' => $r['name'], 'Marca' => $r['brand'],
                'Motivo' => 'Ningún archivo en Drive se llama como el código (ni la base de la familia)'];
            break;
        case 'ocultas':
            $table[] = ['Código' => $r['sku'], 'Nombre' => $r['name'], 'Marca' => $r['brand'],
                'Motivo' => $r['n'] . ' foto(s) en una carpeta oculta', 'Ruta' => $pathOf($r['parent_id'])];
            break;
        case 'archivos':
            $table[] = ['Archivo' => $r['name'], 'Ruta' => $pathOf($r['parent_id']),
                'Motivo' => 'El nombre no es el código de ningún producto', 'Enlace' => "https://drive.google.com/file/d/{$r['file_id']}/view"];
            break;
        case 'hoja':
            $miss = [];
            if (!($r['price_suggested'] > 0)) $miss[] = 'sin PRICE_SUGGESTED';
            if ($r['name'] === $r['sku'] || $r['name'] === '') $miss[] = 'sin NAME';
            $table[] = ['Código' => $r['sku'], 'Nombre' => $r['name'], 'Marca' => $r['brand'], 'Motivo' => implode(' y ', $miss)];
            break;
    }
}

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="problemas_' . $tab . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($table) fputcsv($out, array_keys($table[0]));
    foreach ($table as $t) fputcsv($out, array_values($t));
    fclose($out);
    exit;
}
$hasSkuCol = $tab !== 'archivos';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Problemas de fotos · Viewfinder Admin</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">VF</span> Viewfinder Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container pb-main">
        <?php $adminNav = 'problemas'; include __DIR__ . '/../../templates/admin/partials/nav.php'; ?>
        <h1 class="pb-title">🩺 Problemas de fotos</h1>
        <p class="pb-help">Lo que impide que una foto salga en el catálogo, con el motivo y dónde está en Drive.
            Se calcula con la última copia de Drive (Sincronizar portadas). Para un código puntual:
            <a href="/admin/sync#photoSku">¿Por qué no sale la foto?</a></p>

        <nav class="pb-tabs">
            <?php foreach ($tabs as $k => $label): ?>
                <a href="/admin/problemas?tab=<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= $label ?> <b><?= number_format($counts[$k]) ?></b></a>
            <?php endforeach; ?>
        </nav>

        <div class="pb-bar">
            <span><?= number_format($counts[$tab]) ?> en total<?= $counts[$tab] > $limit ? " · se muestran los primeros $limit" : '' ?></span>
            <?php if ($counts[$tab]): ?><a class="btn btn-sm btn-secondary" href="/admin/problemas?tab=<?= $tab ?>&csv=1">📥 Descargar CSV</a><?php endif; ?>
        </div>

        <?php if (!$table): ?>
            <p class="pb-empty">✅ Nada pendiente aquí.</p>
        <?php else: ?>
            <div class="pb-table-wrap">
                <table class="pb-table">
                    <thead><tr><?php foreach (array_keys($table[0]) as $h): ?><th><?= e($h) ?></th><?php endforeach; ?><?php if ($hasSkuCol): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($table as $t): ?>
                            <tr>
                                <?php foreach ($t as $h => $v): ?>
                                    <td data-h="<?= e($h) ?>"><?php if ($h === 'Enlace'): ?><a href="<?= e($v) ?>" target="_blank" rel="noopener">Abrir en Drive ↗</a><?php else: ?><?= e((string) $v) ?><?php endif; ?></td>
                                <?php endforeach; ?>
                                <?php if ($hasSkuCol): ?><td><a href="/admin/sync?sku=<?= rawurlencode($t['Código']) ?>#photoSku">¿Por qué?</a></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</body>

</html>
