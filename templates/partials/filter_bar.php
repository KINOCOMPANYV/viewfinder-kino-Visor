<?php
/**
 * Barra de filtros del catálogo público (inicio, búsqueda, carpetas, Novedades). Siempre lleva a /buscar.
 * Marca, categoría y carpeta: se pueden marcar varias (cualquiera de ellas); todo se combina con el precio
 * y con lo escrito en el buscador. Necesita $db; usa $q y $filters si existen (en /buscar).
 * $filterPreset (opcional, p. ej. ['carpeta' => [id]] en una carpeta) deja marcado algo de entrada.
 */
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
$fbAll = ['marca', 'cat', 'carpeta', 'pmin', 'pmax', 'foto'];
$fbUrl = function (array $drop, ?array $only = null) use ($fbQ, $fbReal): string {
    $fbF = $fbReal;
    $qs = ['q' => $fbQ];
    foreach (['marca', 'cat', 'carpeta'] as $k) {
        $vals = $fbF[$k];
        if ($only && $only[0] === $k) $vals = array_values(array_diff($vals, [$only[1]]));
        if ($vals && !in_array($k, $drop, true)) $qs[$k] = $vals;
    }
    foreach (['pmin', 'pmax'] as $k) if ($fbF[$k] !== null && !in_array($k, $drop, true)) $qs[$k] = (int) $fbF[$k];
    return '/buscar?' . http_build_query(array_filter($qs, fn($v) => $v !== '' && $v !== []));
};
$fbPrice = fn($v) => '$' . number_format((float) $v, 0, ',', '.');
$fbChips = [];
foreach ($fbReal['marca'] as $v) $fbChips[] = [$v, $fbUrl([], ['marca', $v])];
foreach ($fbReal['cat'] as $v) $fbChips[] = [ucfirst(mb_strtolower($v, 'UTF-8')), $fbUrl([], ['cat', $v])];
foreach ($fbReal['carpeta'] as $v) $fbChips[] = ['📁 ' . ($fbFolderName[$v] ?? 'Carpeta'), $fbUrl([], ['carpeta', $v])];
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
<form class="filter-bar" id="filterForm" action="/buscar" method="GET">
    <input type="hidden" name="q" value="<?= e($fbQ) ?>">
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
    <button type="submit" class="filter-field filter-go">Filtrar</button>
</form>
<?php if ($fbChips): ?>
    <div class="filter-active">
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
    let dirty = false;
    const go = () => {
        const input = document.getElementById('searchInput');
        const qs = new URLSearchParams();
        const q = (input ? input.value : (ff.elements.q ? ff.elements.q.value : '')).trim();
        if (q) qs.set('q', q);
        new FormData(ff).forEach((v, k) => {
            v = String(v).trim();
            if (k === 'q' || v === '') return;
            if (k === 'pmin' || k === 'pmax') { v = v.replace(/\D/g, ''); if (!v) return; }
            qs.append(k, v);
        });
        window.location.href = '/buscar' + (qs.toString() ? '?' + qs.toString() : '');
    };
    ff.addEventListener('change', (e) => {
        if (e.target.type === 'checkbox') { dirty = true; return; }
        if (e.target.classList.contains('filter-find')) return;
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
