<?php
/**
 * Admin → Inventario (/admin/inventario). Subir el Excel/CSV de existencias del sistema contable,
 * cruzarlo con la hoja maestra y descargar:
 *   ?csv=stock    columna STOCK nueva en el orden de filas de la hoja (para pegarla en la hoja)
 *   ?csv=reporte  diferencias (duplicados, stock distinto, negativos, faltantes, precio distinto)
 * No escribe en la hoja ni en la BD. El último resultado queda en storage/inventario/ultimo.json.
 */
require_once __DIR__ . '/../services/MasterSheet.php';
require_once __DIR__ . '/../services/InventoryImport.php';
require_once __DIR__ . '/../services/XlsxWriter.php';

$dir = BASE_DIR . '/storage/inventario';
$file = $dir . '/ultimo.json';
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!verifyCsrf()) throw new RuntimeException('La sesión expiró: recarga la página.');
        $up = $_FILES['archivo'] ?? null;
        if (!$up || $up['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Elige el archivo de inventario (.xlsx o .csv).');
        @set_time_limit(120);
        $parsed = InventoryImport::parseItems(InventoryImport::readFile($up['tmp_name'], $up['name']));
        if (!$parsed['items']) throw new RuntimeException('El archivo no tiene artículos.');
        $sheet = InventoryImport::sheetRows(getDB());
        if (!$sheet['rows']) throw new RuntimeException('No hay filas de la hoja maestra para comparar.');
        $result = InventoryImport::compare($parsed['items'], $sheet['rows'], !empty($_POST['neg_cero']));
        $result['meta'] = ['archivo' => $up['name'], 'fecha' => date('Y-m-d H:i'), 'columnas' => $parsed['columns'],
            'fuente_hoja' => $sheet['source'], 'neg_cero' => !empty($_POST['neg_cero'])];
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (@file_put_contents($file, json_encode($result, JSON_UNESCAPED_UNICODE)) === false) throw new RuntimeException('No se pudo guardar el resultado en storage/inventario.');
        redirect('/admin/inventario');
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$result = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

// Descargas en Excel (.xlsx): un CSV con comas lo abre todo en la columna A en Excel en español
$dl = $_GET['descargar'] ?? $_GET['csv'] ?? null;
if ($dl && $result) {
    $isStock = $dl === 'stock';
    $bin = $isStock ? InventoryImport::stockXlsx($result) : InventoryImport::reportXlsx($result);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . ($isStock ? 'STOCK_para_pegar_en_hoja_' : 'Reporte_inventario_vs_hoja_') . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

$labels = array_map(fn($t) => $t[0], InventoryImport::TYPES);
$tab = $_GET['tipo'] ?? '';
$counts = [];
foreach ($result['report'] ?? [] as $r) $counts[$r['tipo']] = ($counts[$r['tipo']] ?? 0) + 1;
if (!isset($counts[$tab])) $tab = array_key_first($counts) ?? '';
$shown = array_slice(array_values(array_filter($result['report'] ?? [], fn($r) => $r['tipo'] === $tab)), 0, 300);
$s = $result['summary'] ?? [];
$n = fn($v) => $v === null || $v === '' ? '—' : number_format((float) $v, 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario · Viewfinder Admin</title>
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
        <?php $adminNav = 'inventario'; include __DIR__ . '/../../templates/admin/partials/nav.php'; ?>
        <h1 class="pb-title">📦 Importar inventario</h1>
        <p class="pb-help">Sube el Excel de existencias del sistema (por ejemplo «INVENTARIO GENERAL.xlsx»: Artículo, Exist. unidades, Precio).
            Se cruza con la hoja maestra por código (exacto primero; «M:», «M» y «M-» cuentan igual) y te da la columna
            <b>STOCK</b> lista para pegar en la hoja, en el mismo orden de filas. No se escribe nada en la hoja: tú la pegas
            y el visor la toma en la siguiente sincronización.</p>

        <form method="post" enctype="multipart/form-data" class="pb-bar" style="flex-wrap:wrap;gap:10px">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="file" name="archivo" accept=".xlsx,.csv,.txt" required>
            <label><input type="checkbox" name="neg_cero" value="1" checked> Negativos → 0</label>
            <button type="submit" class="btn btn-sm btn-primary">Comparar con la hoja</button>
        </form>
        <?php if ($error): ?><p class="pb-empty" style="color:#ff8080">⚠️ <?= e($error) ?></p><?php endif; ?>

        <?php if ($result): ?>
            <p class="pb-help">Último archivo: <b><?= e($result['meta']['archivo']) ?></b> · <?= e($result['meta']['fecha']) ?> ·
                columnas: <?= e(implode(', ', array_map(fn($k, $v) => "$k = «{$v}»", array_keys($result['meta']['columnas']), $result['meta']['columnas']))) ?> ·
                hoja: <?= e($result['meta']['fuente_hoja']) ?></p>
            <p class="pb-help">
                <?= $n($s['articulos']) ?> artículos en el archivo · <?= $n($s['filas_hoja']) ?> filas en la hoja ·
                <b><?= $n($s['encontrados']) ?></b> códigos de la hoja encontrados · <?= $n($s['iguales']) ?> con el mismo stock ·
                <b><?= $n($s['diferentes']) ?></b> cambian · <?= $n($s['negativos']) ?> negativos ·
                <?= $n($s['solo_hoja']) ?> de la hoja no están en el archivo (se deja su stock) ·
                <?= $n($s['solo_inventario']) ?> códigos del archivo no están en la hoja (<?= $n($s['solo_inventario_con_stock']) ?> con existencias distintas de 0) ·
                duplicados: <?= $n($s['duplicados_inventario']) ?> en el archivo, <?= $n($s['duplicados_hoja']) ?> en la hoja.
            </p>
            <div class="pb-bar">
                <a class="btn btn-sm btn-primary" href="/admin/inventario?descargar=stock">📥 Columna STOCK para pegar (Excel)</a>
                <a class="btn btn-sm btn-secondary" href="/admin/inventario?descargar=reporte">📥 Reporte de diferencias (Excel)</a>
            </div>
            <p class="pb-help">Para pegar: abre el Excel de la columna, copia <b>STOCK nuevo</b> desde la fila 2 y pégalo en la columna STOCK de la hoja desde la fila 2 (mismo orden de filas).</p>

            <nav class="pb-tabs">
                <?php foreach ($labels as $k => $label): if (empty($counts[$k])) continue; ?>
                    <a href="/admin/inventario?tipo=<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= e($label) ?> <b><?= number_format($counts[$k]) ?></b></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($tab): ?><p class="pb-help"><b><?= e(InventoryImport::TYPES[$tab][1]) ?></b> <?= e(InventoryImport::TYPES[$tab][2]) ?></p><?php endif; ?>
            <?php if ($shown): ?>
                <?php if (($counts[$tab] ?? 0) > 300): ?><p class="pb-help">Se muestran los primeros 300; el reporte en Excel trae todos.</p><?php endif; ?>
                <div class="pb-table-wrap">
                    <table class="pb-table">
                        <thead><tr><th>Código archivo</th><th>Código hoja</th><th>Descripción</th><th>Stock archivo</th><th>Stock hoja</th><th>Precio archivo</th><th>PRICE hoja</th><th>Nota</th></tr></thead>
                        <tbody>
                            <?php foreach ($shown as $r): ?>
                                <tr>
                                    <td data-h="Código archivo"><?= e($r['codigo_inventario']) ?></td>
                                    <td data-h="Código hoja"><?= e($r['codigo_hoja']) ?></td>
                                    <td data-h="Descripción"><?= e($r['descripcion']) ?></td>
                                    <td data-h="Stock archivo"><?= $n($r['stock_inventario']) ?></td>
                                    <td data-h="Stock hoja"><?= $n($r['stock_hoja']) ?></td>
                                    <td data-h="Precio archivo"><?= $n($r['precio_inventario']) ?></td>
                                    <td data-h="PRICE hoja"><?= $n($r['PRICE_hoja']) ?></td>
                                    <td data-h="Nota"><?= e($r['nota']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>

</html>
