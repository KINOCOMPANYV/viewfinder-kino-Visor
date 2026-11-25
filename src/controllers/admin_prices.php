<?php
/**
 * Admin → 💲 Precios (/admin/precios). Subir el Excel de productos de KYTE, cruzarlo con PRICE_SUGGESTED de la Hoja master
 * (precio del visor) y aplicarlo en el visor al momento (precio editado; el sync suelta la marca cuando la Hoja master ya
 * trae ese precio). Descargas: columna PRICE_SUGGESTED para pegar (?descargar=columna) y reporte (?descargar=reporte).
 * El archivo SYS va en Admin → 📦 Inventario. Escribe en la Hoja master (SheetWriter) si se marca la casilla. Último resultado: storage/precios/ultimo.json.
 */
require_once __DIR__ . '/../services/MasterSheet.php';
require_once __DIR__ . '/../services/XlsxWriter.php';
require_once __DIR__ . '/../services/PriceImport.php';
require_once __DIR__ . '/../services/SheetWriter.php';

$dir = BASE_DIR . '/storage/precios';
$file = $dir . '/ultimo.json';
$error = $flash = null;
$result = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'config') {
    // Ajustes del precio público (decisión del dueño 2026-11-24): multiplicador sobre el precio SYS y mostrar mayorista
    try {
        if (!verifyCsrf()) throw new RuntimeException('La sesión expiró: recarga la página.');
        $m = (float) str_replace(',', '.', (string) ($_POST['multiplicador'] ?? ''));
        if ($m <= 0 || $m > 20) throw new RuntimeException('El multiplicador debe estar entre 0,1 y 20.');
        setAppSetting(getDB(), 'price_multiplier', rtrim(rtrim(number_format($m, 3, '.', ''), '0'), '.'));
        setAppSetting(getDB(), 'show_wholesale', !empty($_POST['mayorista']) ? '1' : '0');
        $n = recomputePublicPrices(getDB(), $m);
        redirect('/admin/precios?cfg=' . $n);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!verifyCsrf()) throw new RuntimeException('La sesión expiró: recarga la página.');
        $up = $_FILES['archivo'] ?? null;
        if (!$up || $up['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Elige el archivo de precios (.xlsx o .csv).');
        @set_time_limit(120);
        $parsed = PriceImport::parseItems(InventoryImport::readFile($up['tmp_name'], $up['name']));
        if (!$parsed['items']) throw new RuntimeException('El archivo no tiene filas con código y precio.');
        if ($parsed['source'] === 'SYS') throw new RuntimeException('Este es el archivo de SYS: súbelo en 📦 Inventario (su precio va a la columna PRICE y no se muestra en el visor).');
        $sheet = PriceImport::sheetRows(getDB());
        if (!$sheet['rows']) throw new RuntimeException('No hay filas de la Hoja master para comparar.');
        $result = PriceImport::compare($parsed['items'], $sheet['rows']);
        $result['meta'] = ['archivo' => $up['name'], 'origen' => $parsed['source'], 'fecha' => date('Y-m-d H:i'), 'fuente_hoja' => $sheet['source'], 'columna' => 'PRICE_SUGGESTED'];
        // Decisión del dueño 2026-11-24: KYTE ya NO pone precio. Solo se compara (reporte); el precio público sale de SYS × multiplicador.
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (@file_put_contents($file, json_encode($result, JSON_UNESCAPED_UNICODE)) === false) throw new RuntimeException('No se pudo guardar el resultado en storage/precios.');
        redirect('/admin/precios?ok=1');
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}
if (isset($_GET['ok'])) $flash = '✅ Archivo KYTE comparado (solo reporte: no cambia precios).';
if (isset($_GET['cfg'])) $flash = '✅ Guardado. Precios públicos recalculados: ' . (int) $_GET['cfg'] . ' productos cambiaron.';

$dl = $_GET['descargar'] ?? null;
if ($dl && $result) {
    $isCol = $dl === 'columna';
    $src = $result['meta']['origen'] ?? 'archivo';
    $bin = $isCol ? PriceImport::pasteXlsx($result) : PriceImport::reportXlsx($result);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . ($isCol ? 'PRICE_SUGGESTED_para_pegar_en_Hoja_master_' : "Reporte_precios_{$src}_vs_Hoja_master_") . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

$labels = array_map(fn($t) => $t[0], PriceImport::TYPES);
$tab = $_GET['tipo'] ?? '';
$counts = [];
foreach ($result['report'] ?? [] as $r) $counts[$r['tipo']] = ($counts[$r['tipo']] ?? 0) + 1;
if (!isset($counts[$tab])) $tab = array_key_first($counts) ?? '';
$shown = array_slice(array_values(array_filter($result['report'] ?? [], fn($r) => $r['tipo'] === $tab)), 0, 300);
$s = $result['summary'] ?? [];
$n = fn($v) => $v === null || $v === '' ? '—' : number_format((float) $v, 0, ',', '.');
$src = $result['meta']['origen'] ?? 'archivo';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Precios · Viewfinder Admin</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
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
        <?php $adminNav = 'precios'; include __DIR__ . '/../../templates/admin/partials/nav.php'; ?>
        <h1 class="pb-title">💲 Precio público</h1>
        <p class="pb-help">El precio que ve el público es el <b>Precio SYS × multiplicador</b> (50.000 con ×2 → 100.000). El Precio SYS se sube en
            <a href="/admin/inventario">📦 Inventario</a> y es el valor <b>mayorista</b>: va a la columna PRICE de la Hoja master y no se muestra al público
            salvo que lo actives aquí. Un precio editado a mano en Admin → Productos (✏️) sigue mandando. Sin Precio SYS sale «Sin precio en tabla».</p>
        <form method="post" class="pb-bar" style="flex-wrap:wrap;gap:10px">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="config">
            <label>Multiplicador × <input type="number" name="multiplicador" step="0.01" min="0.1" max="20" value="<?= e((string) priceMultiplier()) ?>" style="width:80px"></label>
            <label><input type="checkbox" name="mayorista" value="1" <?= showWholesalePublic() ? 'checked' : '' ?>> Mostrar el precio mayorista al público (en la ficha)</label>
            <button type="submit" class="btn btn-sm btn-primary">Guardar y recalcular</button>
        </form>

        <h2 class="pb-title" style="font-size:1.05rem;margin-top:1.4rem">Comparar un archivo de KYTE (solo reporte)</h2>
        <p class="pb-help">KYTE <b>ya no pone precio</b>: este archivo solo se compara con la Hoja master y da un reporte; no cambia nada en el visor ni en la hoja.</p>
        <form method="post" enctype="multipart/form-data" class="pb-bar" style="flex-wrap:wrap;gap:10px">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="file" name="archivo" accept=".xlsx,.csv,.txt" required>
            <button type="submit" class="btn btn-sm btn-secondary">Subir y comparar</button>
        </form>
        <?php if ($error): ?><p class="pb-empty" style="color:#ff8080">⚠️ <?= e($error) ?></p><?php endif; ?>
        <?php if ($flash): ?><p class="pb-help" style="color:#7ee2a8"><?= e($flash) ?></p><?php endif; ?>

        <?php if ($result): ?>
            <p class="pb-help">Último archivo: <b><?= e($result['meta']['archivo']) ?></b> (<?= e($src) ?>) · <?= e($result['meta']['fecha']) ?> ·
                Hoja master: <?= e($result['meta']['fuente_hoja'] ?? '') ?></p>
            <p class="pb-help">
                <?= $n($s['filas_archivo']) ?> filas en <?= e($src) ?> · <b><?= $n($s['encontrados']) ?></b> de <?= $n($s['filas_hoja']) ?> códigos de la Hoja master encontrados ·
                <?= $n($s['iguales']) ?> con el mismo precio · <b><?= $n($s['cambian']) ?></b> cambian · <b><?= $n($s['nuevos']) ?></b> no tenían precio ·
                <?= $n($s['conflictos']) ?> con precios repetidos distintos y <?= $n($s['grandes']) ?> cambios muy grandes (no se cambian) ·
                <?= $n($s['falta_archivo']) ?> de la Hoja master no están en el archivo · <?= $n($s['falta_hoja']) ?> códigos del archivo no están en la Hoja master.
            </p>
            <div class="pb-bar" style="flex-wrap:wrap;gap:10px">
                <a class="btn btn-sm btn-primary" href="/admin/precios?descargar=columna">📥 Columna PRICE_SUGGESTED para pegar (Excel)</a>
                <a class="btn btn-sm btn-secondary" href="/admin/precios?descargar=reporte">📥 Reporte de cambios (Excel)</a>
                <?php if (!empty($result['meta']['aplicado'])): ?><span class="pb-help">Aplicado en el visor: <?= e($result['meta']['aplicado']) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($result['meta']['hoja'])): ?><p class="pb-help" style="color:<?= str_starts_with($result['meta']['hoja'], '✅') ? '#7ee2a8' : '#ffb366' ?>"><?= e($result['meta']['hoja']) ?></p><?php endif; ?>

            <nav class="pb-tabs">
                <?php foreach ($labels as $k => $label): if (empty($counts[$k])) continue; ?>
                    <a href="/admin/precios?tipo=<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= e($label) ?> <b><?= number_format($counts[$k]) ?></b></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($tab): ?><p class="pb-help"><b><?= e(PriceImport::TYPES[$tab][1]) ?></b> <?= e(PriceImport::TYPES[$tab][2]) ?></p><?php endif; ?>
            <?php if ($shown): ?>
                <?php if (($counts[$tab] ?? 0) > 300): ?><p class="pb-help">Se muestran los primeros 300; el reporte en Excel trae todos.</p><?php endif; ?>
                <div class="pb-table-wrap">
                    <table class="pb-table">
                        <thead><tr><th>Código <?= e($src) ?></th><th>Código Hoja master</th><th>Nombre</th><th>Precio Hoja master</th><th>Precio <?= e($src) ?></th><th>Nota</th></tr></thead>
                        <tbody>
                            <?php foreach ($shown as $r): ?>
                                <tr>
                                    <td data-h="Código <?= e($src) ?>"><?= e($r['codigo_archivo']) ?></td>
                                    <td data-h="Código Hoja master"><?= e($r['codigo_hoja']) ?></td>
                                    <td data-h="Nombre"><?= e($r['nombre']) ?></td>
                                    <td data-h="Precio Hoja master"><?= $n($r['precio_hoja']) ?></td>
                                    <td data-h="Precio <?= e($src) ?>"><?= $n($r['precio_archivo']) ?></td>
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
