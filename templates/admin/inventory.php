<?php
$v2 = ['nav' => 'inventario', 'title' => 'Inventario', 'subtitle' => 'Una carga. Stock y precios conectados.', 'styles' => ['/assets/css/inventory.css']];
include __DIR__ . '/partials/v2_start.php';
$sheetState = $latest['sheet_status'] ?? '';
$statuses = ['processing' => 'En proceso', 'applied' => 'KINO actualizado', 'partial' => 'Pendiente Hoja master', 'failed' => 'No aplicada'];
$sheetLabels = ['ok' => 'Al día', 'pending' => 'Pendiente', 'failed' => 'Requiere atención', 'not_requested' => 'Sin enviar'];
$warnings = (int) ($summary['conflictos'] ?? 0);
?>
<div class="inv-page">
    <?php if ($error): ?><div class="inv-notice inv-notice--error" role="alert"><b>No se completó el proceso.</b><p><?= e($error) ?></p><a href="/admin/registro">Ver registro</a></div><?php endif; ?>
    <?php if ($flash): ?><p class="inv-notice" role="status"><?= e($flash) ?></p><?php endif; ?>
    <?php if (!$ready): ?><div class="inv-notice inv-notice--warning" role="alert"><b>Preparación pendiente</b><p>Falta la migración 047 para guardar el historial. El inventario actual se conserva; la carga se habilita cuando termine la actualización.</p></div><?php endif; ?>

    <section class="inv-intro" aria-labelledby="inv-intro-title">
        <div><span class="inv-eyebrow">CENTRO DE INVENTARIO</span><h2 id="inv-intro-title">Del archivo SYS a todo KINO.</h2><p>Actualiza existencias y precios para Productos, Vender y el catálogo desde un solo lugar.</p></div>
        <div class="inv-actions"><a class="v2-btn" href="/admin/productos">Ver productos</a><a class="v2-chip" href="/tienda" target="_blank" rel="noopener">Abrir tienda ↗</a></div>
    </section>

    <section class="inv-connections" aria-label="Estado de las conexiones">
        <article class="inv-connection"><span class="inv-step">01 · ORIGEN</span><h3>SYS</h3><b><?= $latest ? 'Archivo recibido' : 'Sin cargas registradas' ?></b><p><?= $latest ? e($date($latest['created_at'])) : 'Importa el Excel o CSV de inventario.' ?></p><span class="inv-tag">Excel / CSV</span></article>
        <article class="inv-connection"><span class="inv-step">02 · INVENTARIO</span><h3>KINO</h3><b><?= $number($catalog['con_sys']) ?> referencias con SYS</b><p><?= $number($catalog['con_stock']) ?> con stock · <?= $number($catalog['total']) ?> productos</p><span class="inv-tag inv-tag--ok">Una fuente para el catálogo</span></article>
        <article class="inv-connection"><span class="inv-step">03 · RESPALDO DE DATOS</span><h3>Hoja master</h3><b><?= e($sheetLabels[$sheetState] ?? 'Sin cargas registradas') ?></b><p><?= $sheetState === 'ok' ? 'Stock y precio enviados para la última carga.' : 'Puedes reintentar un envío sin volver a cargar el archivo.' ?></p><a href="<?= $latestId ? e('/admin/inventario?carga=' . $latestId . '#resultado') : '/admin/hoja' ?>">Ver detalle →</a></article>
        <article class="inv-connection"><span class="inv-step">04 · FOTOGRAFÍAS</span><h3>Google Drive</h3><b><?= !empty($syncDates['drive']['last_ok_at']) ? 'Índice de fotos disponible' : 'Pendiente de comprobar' ?></b><p><?= e($date($syncDates['drive']['last_ok_at'] ?? null)) ?></p><a href="/admin/sync">Sincronizar fotos →</a></article>
    </section>

    <div class="inv-workspace">
        <section class="inv-panel" aria-labelledby="inv-upload-title">
            <header class="inv-panel-head"><span class="inv-eyebrow">ACTUALIZAR</span><h2 id="inv-upload-title">Carga el inventario de SYS</h2><p>KINO publica el stock y recalcula los precios al terminar la carga.</p></header>
            <form method="post" enctype="multipart/form-data" class="inv-upload" data-inventory-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="accion" value="importar">
                <label class="inv-file" for="invFile"><span class="inv-file-icon" aria-hidden="true">↑</span><b>Seleccionar archivo de inventario</b><span>XLSX o CSV · máximo 20 MB</span><input id="invFile" name="archivo" type="file" accept=".xlsx,.csv,.txt" required <?= $ready ? '' : 'disabled' ?> aria-describedby="invFileHelp"></label>
                <p class="inv-help" id="invFileHelp">Columnas: Artículo o Código, Existencias y Precio. También se acepta Descripción.</p>
                <label class="inv-option"><input type="checkbox" name="hoja" value="1" checked><span><b>Enviar también a la Hoja master</b><small>Si Google falla, KINO conserva la actualización y queda un envío pendiente.</small></span></label>
                <label class="inv-option"><input type="checkbox" name="neg_cero" value="1" checked><span><b>Tratar existencias negativas como cero</b><small>Las referencias que no aparecen en el archivo conservan su inventario.</small></span></label>
                <button type="submit" class="v2-btn inv-submit" <?= $ready ? '' : 'disabled' ?>>Actualizar inventario</button>
                <p class="inv-progress" data-inventory-progress role="status" aria-live="polite" hidden>Actualizando inventario. Espera el resultado antes de cerrar esta página…</p>
            </form>
            <div class="inv-note"><b>Precios conectados.</b> El precio público se calcula desde SYS × <?= e((string) priceMultiplier()) ?>. Los precios editados manualmente se conservan. <a href="/admin/precios">Ajustar precios</a></div>
        </section>

        <section class="inv-panel" aria-labelledby="inv-history-title">
            <header class="inv-panel-head"><span class="inv-eyebrow">TRAZABILIDAD</span><h2 id="inv-history-title">Últimas cargas</h2><p>El historial y los reportes se conservan después de los despliegues.</p></header>
            <?php if (!$history): ?><div class="inv-empty"><b>Tu primera carga empieza aquí.</b><p>Al importar verás el archivo, quién lo cargó y el resultado de cada envío.</p></div><?php else: ?>
            <ol class="inv-history">
                <?php foreach ($history as $h): ?><li><a href="/admin/inventario?carga=<?= (int) $h['id'] ?>#resultado" <?= $batch && (int) $h['id'] === (int) $batch['id'] ? 'aria-current="page"' : '' ?>>
                    <span class="inv-history-number">#<?= (int) $h['id'] ?></span><span class="inv-history-main"><b><?= e($h['filename']) ?></b><small><?= e($date($h['created_at'])) ?> · <?= e($h['user_name']) ?></small><span class="inv-tag <?= $h['status'] === 'partial' || $h['status'] === 'failed' ? 'inv-tag--warning' : '' ?>"><?= e($statuses[$h['status']] ?? $h['status']) ?></span></span><span class="inv-history-count"><?= $number($h['applied_count']) ?><small>productos</small></span>
                </a></li><?php endforeach; ?>
            </ol><?php endif; ?>
        </section>
    </div>

    <?php if ($result): ?>
    <section class="inv-panel inv-result" id="resultado" aria-labelledby="inv-result-title">
        <header class="inv-panel-head inv-result-head"><div><span class="inv-eyebrow"><?= $legacy ? 'REPORTE ANTERIOR' : 'RESULTADO DE LA CARGA #' . (int) $batch['id'] ?></span><h2 id="inv-result-title"><?= e($result['meta']['archivo'] ?? 'Inventario SYS') ?></h2><p><?= $legacy ? 'Reporte conservado del flujo anterior. Las nuevas cargas se guardan en el historial.' : e($date($batch['created_at'])) . ' · ' . e($batch['user_name']) ?></p></div><div class="inv-actions"><a class="v2-chip" href="<?= e($link(['descargar' => 'reporte'])) ?>">Descargar reporte</a></div></header>
        <div class="inv-stats"><div><b><?= $number($summary['articulos'] ?? 0) ?></b><span>filas en SYS</span></div><div><b><?= $number($result['meta']['aplicado'] ?? 0) ?></b><span>productos actualizados en KINO</span></div><div><b><?= $number(count($result['crear'] ?? [])) ?></b><span>referencias nuevas por revisar</span></div><div><b><?= $number($warnings) ?></b><span>conflictos por corregir</span></div></div>
        <div class="inv-result-body">
            <?php if ($batch && $batch['status'] === 'failed'): ?><div class="inv-notice inv-notice--error"><b>Esta carga no se aplicó.</b><p><?= e($batch['error_message']) ?> El inventario anterior se conserva.</p></div><?php endif; ?>
            <?php if ($batch && in_array($batch['status'], ['applied', 'partial'], true)): ?><div class="inv-delivery"><div><b><?= e($sheetLabels[$batch['sheet_status']] ?? 'Pendiente') ?> · Hoja master</b><p><?= e($result['meta']['hoja'] ?? '') ?></p></div>
                <?php if ((int) $batch['id'] === $latestId && $batch['sheet_status'] !== 'ok'): ?><form method="post" data-inventory-form><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="accion" value="reintentar"><input type="hidden" name="carga" value="<?= (int) $batch['id'] ?>"><button class="v2-btn" type="submit"><?= $batch['sheet_status'] === 'not_requested' ? 'Enviar a Hoja master' : 'Reintentar envío' ?></button></form><?php endif; ?>
            </div><?php endif; ?>
            <?php if (!empty($result['meta']['filas_no_aplicadas'])): ?><p class="inv-help"><?= $number($result['meta']['filas_no_aplicadas']) ?> filas no se aplicaron a KINO: revisa las referencias nuevas, los códigos ambiguos y los conflictos del reporte.</p><?php endif; ?>
            <?php if ($batch && (int) $batch['id'] !== $latestId): ?><p class="inv-help">Estás consultando una carga anterior. <a href="/admin/inventario?carga=<?= $latestId ?>">Abrir la última actualización aplicada</a>.</p><?php endif; ?>
            <?php if (!empty($result['meta']['crear_nota'])): ?><p class="inv-notice"><?= e($result['meta']['crear_nota']) ?> <a href="/admin/sync">Ir a sincronizar</a></p><?php endif; ?>
            <?php if ($batch && (int) $batch['id'] === $latestId && !empty($result['crear'])): ?><details class="inv-new"><summary><?= count($result['crear']) ?> referencias con stock todavía no están en la Hoja master</summary><p>Se agregan con su código, descripción, stock y precio SYS. Después se actualiza el catálogo y se enlazan las fotografías disponibles.</p><p class="inv-help"><?= e(implode(' · ', array_column(array_slice($result['crear'], 0, 8), 'code'))) ?></p><form method="post" data-inventory-form><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="accion" value="crear"><input type="hidden" name="carga" value="<?= (int) $batch['id'] ?>"><button type="submit" class="v2-chip">Agregar referencias a la Hoja master</button></form></details><?php endif; ?>
            <details class="inv-report" open><summary>Diferencias por referencia <span><?= $number(count($result['report'] ?? [])) ?> observaciones</span></summary>
                <?php if (!$counts): ?><div class="inv-empty"><b>Sin diferencias con la Hoja master.</b><p>Stock y precios coinciden con los datos comparados.</p></div><?php else: ?>
                <form method="get" class="inv-report-tools"><input type="hidden" name="carga" value="<?= (int) ($batch['id'] ?? 0) ?>"><label>Tipo de observación<select name="tipo"><?php foreach ($counts as $key => $count): ?><option value="<?= e($key) ?>" <?= $key === $type ? 'selected' : '' ?>><?= e(InventoryImport::TYPES[$key][0] ?? $key) ?> (<?= $count ?>)</option><?php endforeach; ?></select></label><label>Buscar referencia<input type="search" name="q" value="<?= e($query) ?>" placeholder="Código o descripción"></label><button type="submit" class="v2-chip">Filtrar</button></form>
                <p class="inv-help"><?= e(InventoryImport::TYPES[$type][1] ?? '') ?> <?= e(InventoryImport::TYPES[$type][2] ?? '') ?></p>
                <table class="inv-table"><caption class="inv-sr-only">Diferencias del inventario SYS frente a la Hoja master</caption><thead><tr><th scope="col">Referencia</th><th scope="col">Descripción</th><th scope="col">Stock SYS</th><th scope="col">Stock hoja</th><th scope="col">Precio SYS</th><th scope="col">Observación</th></tr></thead><tbody>
                <?php foreach ($shown as $r): ?><tr><td data-label="Referencia"><b><?= e($r['codigo_inventario'] ?: $r['codigo_hoja']) ?></b><?php if ($r['codigo_inventario'] && $r['codigo_hoja'] && $r['codigo_inventario'] !== $r['codigo_hoja']): ?><small>Hoja: <?= e($r['codigo_hoja']) ?></small><?php endif; ?></td><td data-label="Descripción"><?= e($r['descripcion']) ?></td><td data-label="Stock SYS"><?= $r['stock_inventario'] === null ? '—' : $number($r['stock_inventario']) ?></td><td data-label="Stock hoja"><?= $r['stock_hoja'] === null ? '—' : $number($r['stock_hoja']) ?></td><td data-label="Precio SYS"><?= $r['precio_inventario'] === null ? '—' : e(priceText($r['precio_inventario'])) ?></td><td data-label="Observación"><?= e($r['nota']) ?></td></tr><?php endforeach; ?>
                <?php if (!$shown): ?><tr><td colspan="6">No hay referencias que coincidan con la búsqueda.</td></tr><?php endif; ?>
                </tbody></table>
                <nav class="inv-pagination" aria-label="Páginas de observaciones"><?php if ($page > 1): ?><a class="v2-chip" href="<?= e($link(['pagina' => $page - 1])) ?>#resultado">← Anterior</a><?php endif; ?><span>Página <?= $page ?> de <?= $pages ?> · <?= $number(count($filtered)) ?> observaciones</span><?php if ($page < $pages): ?><a class="v2-chip" href="<?= e($link(['pagina' => $page + 1])) ?>#resultado">Siguiente →</a><?php endif; ?></nav>
                <?php endif; ?>
            </details>
            <details class="inv-exports"><summary>Columnas para trabajar en Excel</summary><div class="inv-actions"><a class="v2-chip" href="<?= e($link(['descargar' => 'stock'])) ?>">Columna STOCK</a><a class="v2-chip" href="<?= e($link(['descargar' => 'precio'])) ?>">Columna PRICE</a></div><p class="inv-help">Conservan el orden de la Hoja master al comparar esta carga.</p></details>
        </div>
    </section>
    <?php endif; ?>
    <p class="inv-footer">SYS es la fuente de existencias. Los pedidos todavía no reservan ni descuentan inventario.</p>
</div>
<script src="<?= e(assetVersion('/assets/js/inventory.js')) ?>" defer></script>
<?php include __DIR__ . '/partials/v2_end.php'; ?>
