<?php
/**
 * Barra de filtros del catálogo público (inicio, búsqueda, carpetas, Novedades). Siempre lleva a /buscar.
 * Marca, categoría y carpeta: se pueden marcar varias (cualquiera de ellas); todo se combina con el precio
 * y con lo escrito en el buscador. Necesita $db; usa $q y $filters si existen (en /buscar).
 * $filterPreset (opcional, p. ej. ['carpeta' => [id]] en una carpeta) deja marcado algo de entrada.
 * $filterTarget = '/catalogo-pdf': en vez de buscar, arma un catálogo PDF con lo filtrado (?desde=filtro), con un
 * campo de texto propio (no hay buscador en esa pantalla).
 */
$fbTarget = isset($filterTarget) && $filterTarget === '/catalogo-pdf' ? '/catalogo-pdf' : '/buscar';
$fbExtra = $fbTarget === '/buscar' ? [] : ['desde' => 'filtro'];
$fbQ = isset($q) && is_string($q) ? $q : '';
$fbF = isset($filters) && is_array($filters) && isset($filters['carpeta']) ? $filters : catalogFiltersFromRequest([]);
$fbReal = $fbF; // los chips muestran solo lo que está filtrando de verdad (no lo marcado de entrada)
foreach (($filterPreset ?? []) as $k => $v) if (!$fbF[$k]) $fbF[$k] = $v;
$fbO = searchFilterOptions($db);
$fbFolders = $fbO['folders'] ?? [];
// Una carpeta marcada que no esté en la lista (p. ej. la carpeta abierta) se agrega para que se vea marcada
foreach ($fbF['carpeta'] as $cid) {
    if (!in_array($cid, array_column($fbFolders, 'id'), true) && ($lbl = folderFilterLabel($db, $cid)) !== '') {
        array_unshift($fbFolders, ['id' => $cid, 'label' => $lbl]);
    }
}
$fbFolderName = array_column($fbFolders, 'label', 'id');
$fbAll = ['marca', 'cat', 'carpeta', 'pmin', 'pmax', 'foto', 'contiene', 'stock'];
$fbStocks = ['' => 'Stock: todos', 'con' => 'Con stock', 'sin' => 'Sin stock'];
$fbPriceOrders = ['' => '↕ Precio', 'desc' => 'Mayor precio', 'asc' => 'Menor precio'];
$fbCodeOrders = ['' => '↕ Código', 'asc' => 'Código: A → Z', 'desc' => 'Código: Z → A'];
$fbUrl = function (array $drop, ?array $only = null) use ($fbQ, $fbReal, $fbTarget, $fbExtra): string {
    $fbF = $fbReal;
    $qs = $fbExtra + ['q' => $fbQ];
    foreach (['marca', 'cat', 'carpeta'] as $k) {
        $vals = $fbF[$k];
        if ($only && $only[0] === $k) $vals = array_values(array_diff($vals, [$only[1]]));
        if ($vals && !in_array($k, $drop, true)) $qs[$k] = $vals;
    }
    foreach (['pmin', 'pmax'] as $k) if ($fbF[$k] !== null && !in_array($k, $drop, true)) $qs[$k] = (int) $fbF[$k];
    foreach (['contiene', 'stock', 'oprecio', 'ocodigo'] as $k) if ($fbF[$k] !== '' && !in_array($k, $drop, true)) $qs[$k] = $fbF[$k];
    return $fbTarget . '?' . http_build_query(array_filter($qs, fn($v) => $v !== '' && $v !== []));
};
$fbPrice = fn($v) => '$' . number_format((float) $v, 0, ',', '.');
$fbChips = [];
foreach ($fbReal['marca'] as $v) $fbChips[] = [$v, $fbUrl([], ['marca', $v])];
foreach ($fbReal['cat'] as $v) $fbChips[] = [ucfirst(mb_strtolower($v, 'UTF-8')), $fbUrl([], ['cat', $v])];
foreach ($fbReal['carpeta'] as $v) $fbChips[] = ['📁 ' . ($fbFolderName[$v] ?? 'Carpeta'), $fbUrl([], ['carpeta', $v])];
if ($fbReal['stock'] !== '') $fbChips[] = [$fbStocks[$fbReal['stock']], $fbUrl(['stock'])];
if ($fbReal['contiene'] !== '') $fbChips[] = ['Contiene «' . $fbReal['contiene'] . '»', $fbUrl(['contiene'])];
if ($fbReal['pmin'] !== null || $fbReal['pmax'] !== null) {
    $fbChips[] = [($fbReal['pmin'] !== null ? $fbPrice($fbReal['pmin']) : '…') . ' – ' . ($fbReal['pmax'] !== null ? $fbPrice($fbReal['pmax']) : '…'), $fbUrl(['pmin', 'pmax'])];
}
$fbMulti = function (string $name, string $title, array $opts, array $sel) {
    if (!$opts) return;
    $n = count($sel);
    ?>
    <details class="filter-multi<?= $n ? ' on' : '' ?>">
        <summary class="filter-field<?= $n ? ' on' : '' ?>"><?= e($title) ?><?= $n ? ' (' . $n . ')' : '' ?> ▾</summary>
        <div class="filter-pop">
            <?php if (count($opts) > 8): ?>
                <input type="search" class="filter-find" placeholder="Buscar <?= e(mb_strtolower($title, 'UTF-8')) ?>…" aria-label="Buscar en <?= e($title) ?>">
            <?php endif; ?>
            <div class="filter-opts">
                <?php foreach ($opts as $val => $label): ?>
                    <label><input type="checkbox" name="<?= e($name) ?>[]" value="<?= e((string) $val) ?>" <?= in_array((string) $val, $sel, true) ? 'checked' : '' ?>> <span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="filter-pop-act">
                <button type="button" class="filter-none">Ninguna</button>
                <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
            </div>
        </div>
    </details>
    <?php
};
if (!$fbO['brands'] && !$fbO['categories'] && !$fbO['prices'] && !$fbFolders) return;
?>
<?php
// Celular: la barra va plegada tras un botón «⚙️ Filtros» (los chips de lo activo siguen a la vista).
// En el catálogo PDF (filtrar es lo principal de esa pantalla) queda abierta.
$fbOn = count($fbChips) + ($fbF['oprecio'] !== '' ? 1 : 0) + ($fbF['ocodigo'] !== '' ? 1 : 0);
$fbFold = !$fbExtra;
?>
<?php if ($fbFold): ?>
    <button type="button" class="filter-toggle<?= $fbOn ? ' on' : '' ?>" aria-expanded="false" aria-controls="filterForm"
            onclick="var f=document.getElementById('filterForm'),o=f.classList.toggle('fb-open');this.setAttribute('aria-expanded',o)">
        <span>⚙️ Filtros y orden<?= $fbOn ? ' (' . $fbOn . ')' : '' ?></span><span class="filter-toggle-arrow" aria-hidden="true">▾</span>
    </button>
<?php endif; ?>
<form class="filter-bar<?= $fbFold ? ' fb-fold' : '' ?>" id="filterForm" action="<?= e($fbTarget) ?>" method="GET" data-target="<?= e($fbTarget) ?>">
    <?php if ($fbExtra): ?>
        <input type="text" name="q" value="<?= e($fbQ) ?>" placeholder="Texto: nombre, color, carpeta…" aria-label="Texto" class="filter-field filter-q<?= $fbQ !== '' ? ' on' : '' ?>">
    <?php else: ?>
        <input type="hidden" name="q" value="<?= e($fbQ) ?>">
    <?php endif; ?>
    <?php
    $fbMulti('marca', 'Marcas', array_combine($fbO['brands'], $fbO['brands']) ?: [], $fbF['marca']);
    $fbMulti('cat', 'Categorías', $fbO['categories'] ? array_combine($fbO['categories'], array_map(fn($c) => ucfirst(mb_strtolower($c, 'UTF-8')), $fbO['categories'])) : [], $fbF['cat']);
    $fbMulti('carpeta', 'Carpetas', $fbFolderName, $fbF['carpeta']);
    ?>
    <?php if ($fbO['prices']): ?>
        <span class="filter-price">
            <input type="text" name="pmin" inputmode="numeric" placeholder="Precio desde $" aria-label="Precio desde"
                   class="filter-field<?= $fbF['pmin'] !== null ? ' on' : '' ?>" value="<?= $fbF['pmin'] !== null ? e(number_format($fbF['pmin'], 0, ',', '.')) : '' ?>">
            <input type="text" name="pmax" inputmode="numeric" placeholder="hasta $" aria-label="Precio hasta"
                   class="filter-field<?= $fbF['pmax'] !== null ? ' on' : '' ?>" value="<?= $fbF['pmax'] !== null ? e(number_format($fbF['pmax'], 0, ',', '.')) : '' ?>">
        </span>
    <?php endif; ?>
    <input type="text" name="contiene" value="<?= e($fbF['contiene']) ?>" maxlength="60" placeholder="Contiene la palabra…"
           title="Muestra solo los productos cuyo nombre, o el nombre de su archivo en Drive, contiene esta palabra" aria-label="Contiene la palabra"
           class="filter-field filter-contains<?= $fbF['contiene'] !== '' ? ' on' : '' ?>">
    <select name="stock" aria-label="Stock" title="Con stock: más de 0 · Sin stock: 0" class="filter-field filter-sort<?= $fbF['stock'] !== '' ? ' on' : '' ?>">
        <?php foreach ($fbStocks as $sv => $sl): ?>
            <option value="<?= e($sv) ?>" <?= $fbF['stock'] === $sv ? 'selected' : '' ?>><?= e($sl) ?></option>
        <?php endforeach; ?>
    </select>
    <?php foreach ([['oprecio', $fbPriceOrders, 'Ordenar por precio (se combina con el código)'], ['ocodigo', $fbCodeOrders, 'Ordenar por código (con precio: entre los de igual precio)']] as [$on, $oo, $ot]): ?>
        <select name="<?= $on ?>" aria-label="<?= e($ot) ?>" title="<?= e($ot) ?>" class="filter-field filter-sort<?= $fbF[$on] !== '' ? ' on' : '' ?>">
            <?php foreach ($oo as $ov => $ol): ?>
                <option value="<?= e($ov) ?>" <?= $fbF[$on] === $ov ? 'selected' : '' ?>><?= e($ol) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endforeach; ?>
    <button type="submit" class="filter-field filter-go"><?= $fbExtra ? '📄 Armar catálogo' : 'Filtrar' ?></button>
</form>
<?php
// Botón para sacar un catálogo PDF con lo filtrado (o con la carpeta abierta)
$fbPdf = '';
if (!$fbExtra) {
    $pdfQs = ['desde' => 'filtro', 'q' => $fbQ];
    foreach (['marca', 'cat', 'carpeta'] as $k) if ($fbF[$k]) $pdfQs[$k] = $fbF[$k];
    foreach (['pmin', 'pmax'] as $k) if ($fbF[$k] !== null) $pdfQs[$k] = (int) $fbF[$k];
    foreach (['contiene', 'stock', 'oprecio', 'ocodigo'] as $k) if ($fbF[$k] !== '') $pdfQs[$k] = $fbF[$k];
    $pdfQs = array_filter($pdfQs, fn($v) => $v !== '' && $v !== []);
    if (count(array_diff_key($pdfQs, ['oprecio' => 1, 'ocodigo' => 1])) > 1) $fbPdf = '/catalogo-pdf?' . http_build_query($pdfQs);
}
?>
<?php if ($fbChips || $fbPdf): ?>
    <div class="filter-active">
        <?php if ($fbPdf): ?>
            <a class="filter-pdf" href="<?= e($fbPdf) ?>" title="Arma un catálogo PDF con las fotos de estos productos">📄 Catálogo PDF<?= $fbReal === $fbF ? ' con esto' : ' de esta carpeta' ?></a>
        <?php endif; ?>
        <?php foreach ($fbChips as [$label, $href]): ?>
            <a class="filter-chip" href="<?= e($href) ?>" aria-label="Quitar filtro <?= e($label) ?>"><?= e($label) ?> <span>✕</span></a>
        <?php endforeach; ?>
        <?php if (count($fbChips) > 1): ?>
            <a class="filter-clear" href="<?= e($fbUrl($fbAll)) ?>">Quitar filtros</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
<script>
// Filtros: las casillas se aplican al cerrar su lista (o con «Aplicar»); el precio, al terminar de escribirlo.
(function () {
    const ff = document.getElementById('filterForm');
    if (!ff || ff.dataset.ready) return;
    ff.dataset.ready = '1';
    let dirty = false, going = false;
    const go = () => {
        if (going) return; // Enter en un campo dispara «change» y «submit»: una sola recarga
        going = true;
        const target = ff.dataset.target || '/buscar';
        const input = target === '/buscar' ? document.getElementById('searchInput') : null;
        const qs = new URLSearchParams();
        if (target !== '/buscar') qs.set('desde', 'filtro');
        const q = (input ? input.value : (ff.elements.q ? ff.elements.q.value : '')).trim();
        if (q) qs.set('q', q);
        new FormData(ff).forEach((v, k) => {
            v = String(v).trim();
            if (k === 'q' || v === '') return;
            if (k === 'pmin' || k === 'pmax') { v = v.replace(/\D/g, ''); if (!v) return; }
            qs.append(k, v);
        });
        window.location.href = target + (qs.toString() ? '?' + qs.toString() : '');
    };
    ff.addEventListener('change', (e) => {
        if (e.target.type === 'checkbox') { dirty = true; return; }
        if (e.target.classList.contains('filter-find') || e.target.classList.contains('filter-q')) return;
        go();
    });
    ff.addEventListener('submit', (e) => { e.preventDefault(); go(); });
    const all = ff.querySelectorAll('.filter-multi');
    all.forEach((d) => {
        d.addEventListener('toggle', () => {
            if (d.open) {
                all.forEach((o) => { if (o !== d) o.open = false; });
                const f = d.querySelector('.filter-find'); if (f && window.matchMedia('(hover: hover)').matches) f.focus();
            } else if (dirty) go();
        });
        const find = d.querySelector('.filter-find');
        if (find) find.addEventListener('input', () => {
            const t = find.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            d.querySelectorAll('.filter-opts label').forEach((l) => {
                l.hidden = t !== '' && !l.textContent.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').includes(t);
            });
        });
        d.querySelector('.filter-none').addEventListener('click', () => {
            d.querySelectorAll('input[type=checkbox]').forEach((c) => { if (c.checked) { c.checked = false; dirty = true; } });
        });
    });
    // Tocar fuera cierra la lista abierta (y aplica si cambió algo)
    document.addEventListener('click', (e) => { all.forEach((d) => { if (d.open && !d.contains(e.target)) d.open = false; }); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') all.forEach((d) => { d.open = false; }); });
})();
</script>
