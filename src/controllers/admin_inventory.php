<?php
/**
 * Admin → Inventario (/admin/inventario). Subir el Excel/CSV de existencias del sistema contable,
 * cruzarlo con la hoja maestra y descargar:
 *   ?csv=stock    columna STOCK nueva en el orden de filas de la hoja (para pegarla en la hoja)
 *   ?descargar=precio  columna PRICE nueva (Precio SYS) en el mismo orden (decisión del dueño 2026-11-20)
 *   ?csv=reporte  diferencias (duplicados, stock distinto, negativos, faltantes, precio distinto)
 * Al subir el archivo, el visor guarda al momento Precio SYS y existencias SYS (products.sys_price / sys_stock;
 * el precio SYS no se muestra al público) y, con la casilla, escribe STOCK y PRICE en la Hoja master (SheetWriter). El último resultado queda en storage/inventario/ultimo.json.
 */
require_once __DIR__ . '/../services/MasterSheet.php';
require_once __DIR__ . '/../services/InventoryImport.php';
require_once __DIR__ . '/../services/XlsxWriter.php';
require_once __DIR__ . '/../services/SheetWriter.php';

$dir = BASE_DIR . '/storage/inventario';
$file = $dir . '/ultimo.json';
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    // Botón «Crear en la Hoja master»: agrega los códigos SYS con stock que faltan (acción explícita del admin)
    try {
        if (!verifyCsrf()) throw new RuntimeException('La sesión expiró: recarga la página.');
        $last = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (empty($last['crear'])) throw new RuntimeException('No hay códigos pendientes por crear.');
        $r = SheetWriter::appendProducts(getDB(), $last['crear']);
        $last['crear'] = [];
        $last['meta']['crear_nota'] = "✅ Creados en la Hoja master: {$r['creados']} códigos con su stock y precio SYS" . ($r['existentes'] ? " ({$r['existentes']} ya estaban)" : '') . '. Ahora corre Admin → Sincronizar → hoja para verlos en el visor.';
        file_put_contents($file, json_encode($last, JSON_UNESCAPED_UNICODE));
        redirect('/admin/inventario');
    } catch (\Throwable $e) {
        $error = '⚠️ No se pudo crear en la Hoja master: ' . $e->getMessage();
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!verifyCsrf()) throw new RuntimeException('La sesión expiró: recarga la página.');
        $up = $_FILES['archivo'] ?? null;
        if (!$up || $up['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Elige el archivo de inventario (.xlsx o .csv).');
        @set_time_limit(120);
        $parsed = InventoryImport::parseItems(InventoryImport::readFile($up['tmp_name'], $up['name']));
        if (!$parsed['items']) throw new RuntimeException('El archivo no tiene artículos.');
        $sheet = InventoryImport::sheetRows(getDB());
        if (!$sheet['rows']) throw new RuntimeException('No hay filas de la Hoja master para comparar.');
        $result = InventoryImport::compare($parsed['items'], $sheet['rows'], !empty($_POST['neg_cero']));
        $result['meta'] = ['archivo' => $up['name'], 'fecha' => date('Y-m-d H:i'), 'columnas' => $parsed['columns'],
            'fuente_hoja' => $sheet['source'], 'neg_cero' => !empty($_POST['neg_cero']),
            'aplicado' => InventoryImport::applyToVisor(getDB(), $parsed['items'], !empty($_POST['neg_cero']))];
        // Y en la Hoja master (decisión del dueño 2026-11-20): PRICE = Precio SYS y STOCK, solo lo que cambia
        if (!empty($_POST['hoja'])) {
            $price = $stock = [];
            foreach ($result['paste'] as $p) {
                if ($p['SKU'] === '') continue;
                if (($p['PRICE_nuevo'] ?? null) !== null && $p['PRICE_nuevo'] !== $p['PRICE_actual']) $price[$p['SKU']] = $p['PRICE_nuevo'];
                if ($p['STOCK_nuevo'] !== null && $p['STOCK_nuevo'] !== $p['STOCK_actual']) $stock[$p['SKU']] = $p['STOCK_nuevo'];
            }
            $result['meta']['hoja'] = sheetWriteNote(fn() => SheetWriter::writeColumns(getDB(), ['PRICE' => $price, 'STOCK' => $stock]));
        }
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
    [$bin, $name] = match ($dl) {
        'stock' => [InventoryImport::stockXlsx($result), 'STOCK_para_pegar_en_Hoja_master_'],
        'precio' => [InventoryImport::priceXlsx($result), 'PRICE_SYS_para_pegar_en_Hoja_master_'],
        default => [InventoryImport::reportXlsx($result), 'Reporte_SYS_vs_Hoja_master_'],
    };
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . date('Y-m-d') . '.xlsx"');
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
        <h1 class="pb-title">📦 Importar inventario SYS</h1>
        <p class="pb-help">Sube el Excel de existencias de <b>SYS</b> (por ejemplo «INVENTARIO GENERAL.xlsx»: Artículo, Exist. unidades, Precio).
            <b>Al subirlo, el visor guarda al momento el Precio SYS y las existencias</b> de cada código (el Precio SYS es el mayorista: el público ve ese precio × multiplicador, ver 💲 Precios).
            Además <b>escribe en la Hoja master</b> las columnas <b>STOCK</b> y <b>PRICE</b> (= Precio SYS), solo las celdas que cambian
            (la cuenta de Google conectada debe poder editar la hoja). También te da esas columnas para pegarlas a mano, en el mismo orden de filas
            (cruce por código: exacto primero; «M:», «M» y «M-» cuentan igual). El precio que ve el público = Precio SYS × multiplicador (💲 Precios).</p>

        <form method="post" enctype="multipart/form-data" class="pb-bar" style="flex-wrap:wrap;gap:10px">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="file" name="archivo" accept=".xlsx,.csv,.txt" required>
            <label><input type="checkbox" name="neg_cero" value="1" checked> Negativos → 0</label>
            <label><input type="checkbox" name="hoja" value="1" checked> También escribir en la Hoja master</label>
            <button type="submit" class="btn btn-sm btn-primary">Comparar con la Hoja master</button>
        </form>
        <?php if ($error): ?><p class="pb-empty" style="color:#ff8080">⚠️ <?= e($error) ?></p><?php endif; ?>

        <?php if ($result): ?>
            <p class="pb-help">Último archivo SYS: <b><?= e($result['meta']['archivo']) ?></b> · <?= e($result['meta']['fecha']) ?> ·
                columnas: <?= e(implode(', ', array_map(fn($k, $v) => "$k = «{$v}»", array_keys($result['meta']['columnas']), $result['meta']['columnas']))) ?> ·
                Hoja master: <?= e($result['meta']['fuente_hoja']) ?></p>
            <p class="pb-help">
                <?= $n($s['articulos']) ?> artículos en SYS · <?= $n($s['filas_hoja']) ?> filas en la Hoja master ·
                <b><?= $n($s['encontrados']) ?></b> códigos de la Hoja master encontrados · <?= $n($s['iguales']) ?> con el mismo stock ·
                <b><?= $n($s['diferentes']) ?></b> cambian · <?= $n($s['negativos']) ?> negativos ·
                <?= $n($s['solo_hoja']) ?> de la Hoja master no están en SYS (se deja su stock) ·
                <?= $n($s['solo_inventario']) ?> códigos de SYS no están en la Hoja master (<?= $n($s['solo_inventario_con_stock']) ?> con existencias distintas de 0) ·
                duplicados: <?= $n($s['duplicados_inventario']) ?> en SYS, <?= $n($s['duplicados_hoja']) ?> en la Hoja master.
            </p>
            <?php if (isset($result['meta']['aplicado'])): ?><p class="pb-help" style="color:#7ee2a8">✅ Guardado en el visor: Precio SYS y existencias de <?= $n($result['meta']['aplicado']) ?> productos.</p><?php endif; ?>
            <?php if (!empty($result['meta']['hoja'])): ?><p class="pb-help" style="color:<?= str_starts_with($result['meta']['hoja'], '✅') ? '#7ee2a8' : '#ffb366' ?>"><?= e($result['meta']['hoja']) ?></p><?php endif; ?>
            <?php if (!empty($result['meta']['crear_nota'])): ?><p class="pb-help" style="color:#7ee2a8"><?= e($result['meta']['crear_nota']) ?></p><?php endif; ?>
            <?php if (!empty($result['crear'])): $cr = $result['crear']; ?>
                <div class="pb-help" style="border:1px solid #c9a24a;border-radius:8px;padding:10px">
                    <b><?= $n(count($cr)) ?> códigos de SYS tienen existencias y no están en la Hoja master.</b>
                    ¿Crearlos? Se agregan al final de la Hoja master con código, nombre (descripción SYS), STOCK y PRICE (Precio SYS).
                    Ejemplos: <?= e(implode(', ', array_map(fn($c) => $c['code'] . ' (' . $c['stock'] . ')', array_slice($cr, 0, 8)))) ?>…
                    <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Agregar <?= count($cr) ?> filas nuevas a la Hoja master?')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="accion" value="crear">
                        <button type="submit" class="btn btn-sm btn-primary">➕ Crear <?= $n(count($cr)) ?> en la Hoja master</button>
                    </form>
                </div>
            <?php endif; ?>
            <div class="pb-bar">
                <a class="btn btn-sm btn-primary" href="/admin/inventario?descargar=stock">📥 Columna STOCK para pegar (Excel)</a>
                <?php if (isset($result['paste'][0]) && array_key_exists('PRICE_nuevo', $result['paste'][0])): ?>
                    <a class="btn btn-sm btn-primary" href="/admin/inventario?descargar=precio">📥 Columna PRICE (Precio SYS) para pegar (Excel)</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-secondary" href="/admin/inventario?descargar=reporte">📥 Reporte de diferencias (Excel)</a>
            </div>
            <p class="pb-help">Para pegar: abre el Excel de la columna, copia <b>STOCK nuevo</b> (o <b>PRICE nuevo</b>) desde la fila 2 y pégalo en la columna STOCK (o PRICE) de la Hoja master desde la fila 2 (mismo orden de filas).</p>

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
                        <thead><tr><th>Código SYS</th><th>Código Hoja master</th><th>Descripción</th><th>Stock SYS</th><th>Stock Hoja master</th><th>Precio SYS</th><th>PRICE Hoja master</th><th>Nota</th></tr></thead>
                        <tbody>
                            <?php foreach ($shown as $r): ?>
                                <tr>
                                    <td data-h="Código SYS"><?= e($r['codigo_inventario']) ?></td>
                                    <td data-h="Código Hoja master"><?= e($r['codigo_hoja']) ?></td>
                                    <td data-h="Descripción"><?= e($r['descripcion']) ?></td>
                                    <td data-h="Stock SYS"><?= $n($r['stock_inventario']) ?></td>
                                    <td data-h="Stock Hoja master"><?= $n($r['stock_hoja']) ?></td>
                                    <td data-h="Precio SYS"><?= $n($r['precio_inventario']) ?></td>
                                    <td data-h="PRICE Hoja master"><?= $n($r['PRICE_hoja']) ?></td>
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
