<?php
/**
 * Admin — Previo de la Hoja master (GET /admin/hoja?q=texto&n=50).
 * Descarga la hoja solo al abrir esta pantalla (nunca al arrancar) y muestra las filas tal cual,
 * con todas las columnas y qué campo del visor alimenta cada una. Al lado: el nombre que tiene hoy el visor.
 */
require_once __DIR__ . '/../services/MasterSheet.php';

$q = trim((string) ($_GET['q'] ?? ''));
$n = (int) ($_GET['n'] ?? 50);
$n = in_array($n, [20, 50, 100, 300], true) ? $n : 50;
$sheetId = env('GOOGLE_SHEET_ID', '');

$error = null;
$data = ['headers' => [], 'rows' => [], 'total' => 0, 'matched' => 0];
try {
    $data = MasterSheet::preview(MasterSheet::fetchCsv(20), $n, $q);
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

// Nombre que tiene hoy el visor para esos códigos (para comparar con la hoja)
$skuCol = null;
foreach ($data['headers'] as $i => $h) {
    if ($h['field'] === 'sku') { $skuCol = $i; break; }
}
$visorNames = [];
if ($skuCol !== null && $data['rows']) {
    $skus = array_values(array_unique(array_filter(array_map(fn($r) => $r['cells'][$skuCol], $data['rows']))));
    if ($skus) {
        try {
            $stmt = getDB()->prepare("SELECT sku, name FROM products WHERE sku IN (" . implode(',', array_fill(0, count($skus), '?')) . ")");
            $stmt->execute($skus);
            foreach ($stmt->fetchAll() as $p) $visorNames[$p['sku']] = $p['name'];
        } catch (\PDOException $e) { /* sin BD: se muestra solo la hoja */ }
    }
}
$used = array_filter($data['headers'], fn($h) => $h['field'] !== null);
$ignored = array_filter($data['headers'], fn($h) => $h['field'] === null && $h['name'] !== '');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hoja master · Viewfinder Admin</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}</style>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .hm-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem; margin-bottom:1.25rem; }
        .hm-card p { color:var(--color-text-muted); font-size:0.85rem; }
        .hm-map { display:flex; flex-wrap:wrap; gap:0.4rem; margin-top:0.75rem; font-size:0.8rem; }
        .hm-map span { padding:0.25rem 0.55rem; border-radius:999px; background:var(--color-surface-2); border:1px solid var(--color-border); }
        .hm-map .on { border-color:rgba(52,168,83,0.5); }
        .hm-map .key { border-color:#fbbf24; color:#fbbf24; }
        .hm-map .off { opacity:0.6; text-decoration:line-through; }
        .hm-form { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem; }
        .hm-form input, .hm-form select { padding:0.5rem 0.7rem; border-radius:var(--radius-sm); border:1px solid var(--color-border); background:var(--color-surface-2); color:inherit; }
        .hm-form input { flex:1; min-width:180px; }
        .hm-wrap { overflow:auto; max-height:70vh; border:1px solid var(--color-border); border-radius:var(--radius-sm); }
        .hm-table { border-collapse:collapse; font-size:0.78rem; white-space:nowrap; }
        .hm-table th, .hm-table td { padding:0.4rem 0.6rem; border-bottom:1px solid var(--color-border); border-right:1px solid var(--color-border); text-align:left; max-width:260px; overflow:hidden; text-overflow:ellipsis; }
        .hm-table thead th { position:sticky; top:0; background:var(--color-surface-2); z-index:1; vertical-align:top; }
        .hm-table th small { display:block; font-weight:normal; color:#34a853; margin-top:2px; }
        .hm-table th.off small { color:var(--color-text-muted); }
        .hm-table th.off, .hm-table td.off { opacity:0.55; }
        .hm-table .key { background:rgba(251,191,36,0.10); }
        .hm-table th.key small { color:#fbbf24; }
        .hm-table .line { color:var(--color-text-muted); }
        .hm-table .empty { color:#f87171; font-style:italic; }
        .hm-table .visor { background:rgba(99,102,241,0.10); }
    </style>
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/admin" class="logo"><span class="logo-icon">VF</span> Viewfinder Admin</a>
            <a href="/admin/logout" class="btn btn-sm btn-secondary">Cerrar Sesión</a>
        </div>
    </header>

    <main class="container" style="padding-top:2rem; padding-bottom:3rem;">
        <?php $adminNav = 'hoja'; include __DIR__ . '/../../templates/admin/partials/nav.php'; ?>
        <h1 style="font-size:1.5rem; margin-bottom:1rem;">📋 Previo de la Hoja master</h1>

        <section class="hm-card">
            <p>
                Así llega la Hoja master al visor, fila por fila y con todas sus columnas. Debajo de cada encabezado dice qué campo
                del visor llena. <b style="color:#fbbf24;">NAME</b> es el nombre del producto: si está vacía, el visor usa el código.
                Los productos «Solo Drive» (foto sin fila en la hoja) también muestran el código como nombre.
                <?php if ($sheetId): ?>
                    · <a href="https://docs.google.com/spreadsheets/d/<?= e($sheetId) ?>/edit" target="_blank" rel="noopener" style="color:var(--color-primary);">Abrir la hoja ↗</a>
                <?php endif; ?>
            </p>
            <?php if ($data['headers']): ?>
                <div class="hm-map">
                    <?php foreach ($used as $h): ?>
                        <span class="<?= $h['field'] === 'name' ? 'key' : 'on' ?>"><b><?= e($h['name']) ?></b> → <?= e($h['label']) ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($ignored as $h): ?>
                        <span class="off" title="El visor no usa esta columna"><?= e($h['name']) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <form class="hm-form" method="get" action="/admin/hoja">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar en la hoja: código, nombre, marca…">
            <select name="n">
                <?php foreach ([20, 50, 100, 300] as $opt): ?>
                    <option value="<?= $opt ?>"<?= $opt === $n ? ' selected' : '' ?>><?= $opt ?> filas</option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Ver</button>
            <?php if ($q !== ''): ?><a class="btn btn-secondary" href="/admin/hoja">Quitar búsqueda</a><?php endif; ?>
        </form>

        <?php if ($error): ?>
            <div class="hm-card" style="color:#f87171;">No se pudo leer la hoja: <?= e($error) ?></div>
        <?php else: ?>
            <p style="font-size:0.85rem; color:var(--color-text-muted); margin-bottom:0.5rem;">
                <?php if ($q !== ''): ?>
                    <?= number_format($data['matched']) ?> filas contienen «<?= e($q) ?>» de <?= number_format($data['total']) ?>; se muestran <?= count($data['rows']) ?>.
                <?php else: ?>
                    Primeras <?= count($data['rows']) ?> filas de <?= number_format($data['total']) ?>.
                <?php endif; ?>
                La columna morada es el nombre que tiene hoy el visor (cambia al pulsar «Sincronizar hoja»).
            </p>
            <div class="hm-wrap">
                <table class="hm-table">
                    <thead>
                        <tr>
                            <th class="line">Fila</th>
                            <?php foreach ($data['headers'] as $h): ?>
                                <th class="<?= $h['field'] === null ? 'off' : ($h['field'] === 'name' ? 'key' : '') ?>">
                                    <?= e($h['name'] !== '' ? $h['name'] : '(sin título)') ?>
                                    <small><?= $h['field'] ? '→ ' . e($h['label']) : 'no se usa' ?></small>
                                </th>
                            <?php endforeach; ?>
                            <th class="visor">Nombre en el visor<small style="color:#a5b4fc;">hoy</small></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['rows'] as $row): ?>
                            <tr>
                                <td class="line"><?= $row['line'] ?></td>
                                <?php foreach ($row['cells'] as $i => $cell):
                                    $h = $data['headers'][$i]; ?>
                                    <td class="<?= $h['field'] === null ? 'off' : ($h['field'] === 'name' ? 'key' : '') ?>" title="<?= e($cell) ?>">
                                        <?php if ($cell === '' && $h['field'] === 'name'): ?>
                                            <span class="empty">vacío → usa el código</span>
                                        <?php else: ?><?= e($cell) ?><?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <?php $sku = $skuCol !== null ? $row['cells'][$skuCol] : ''; ?>
                                <td class="visor"><?= isset($visorNames[$sku]) ? e($visorNames[$sku]) : '<span class="line">— no está en el visor —</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$data['rows']): ?>
                            <tr><td colspan="<?= count($data['headers']) + 2 ?>" class="line">Sin filas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</body>

</html>
