<?php
/**
 * Admin — 🧾 Registro (GET/POST /admin/registro). Decisión del dueño 2026-11-23: medir antes de seguir agregando.
 * Errores de PHP y JS (app_errors, agrupados: el mismo error cuenta veces) y búsquedas sin resultado (search_misses).
 * POST action=clear_errors | clear_one (id) | clear_misses, con csrf_token. Datos: ErrorLog (migración 036).
 */
$db = getDB();
$tableOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCsrf()) {
        try {
            switch ($_POST['action'] ?? '') {
                case 'clear_errors': $db->exec('DELETE FROM app_errors'); break;
                case 'clear_one': $db->prepare('DELETE FROM app_errors WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]); break;
                case 'clear_misses': $db->exec('DELETE FROM search_misses'); break;
            }
        } catch (\PDOException $e) { /* tabla aún no existe */ }
    }
    redirect('/admin/registro');
}

$days = in_array((int) ($_GET['dias'] ?? 7), [1, 7, 30, 365], true) ? (int) $_GET['dias'] : 7;
$errors = $misses = $stats = [];
$errTotal = $missTotal = $people = 0;
try {
    $st = $db->prepare("SELECT * FROM app_errors WHERE last_seen > NOW() - INTERVAL ? DAY ORDER BY last_seen DESC LIMIT 200");
    $st->execute([$days]);
    $errors = $st->fetchAll();
    $st = $db->prepare("SELECT COALESCE(SUM(count),0) FROM app_errors WHERE last_seen > NOW() - INTERVAL ? DAY");
    $st->execute([$days]);
    $errTotal = (int) $st->fetchColumn();
    $st = $db->prepare("SELECT MIN(term) AS term, term_key, COUNT(*) AS veces, COUNT(DISTINCT sid) AS personas, MAX(created_at) AS ultima
        FROM search_misses WHERE created_at > NOW() - INTERVAL ? DAY GROUP BY term_key ORDER BY veces DESC, ultima DESC LIMIT 100");
    $st->execute([$days]);
    $misses = $st->fetchAll();
    $st = $db->prepare("SELECT COUNT(*), COUNT(DISTINCT sid) FROM search_misses WHERE created_at > NOW() - INTERVAL ? DAY");
    $st->execute([$days]);
    [$missTotal, $people] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
} catch (\PDOException $e) {
    $tableOk = false;
}
$fatal = count(array_filter($errors, fn($r) => in_array($r['level'], ['fatal', 'parse'], true)));
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro · Viewfinder Admin</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .lg-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem; margin-bottom:1.25rem; overflow-x:auto; }
        .lg-card p { color:var(--color-text-muted); font-size:0.9rem; margin-bottom:0.75rem; }
        .lg-stats { display:flex; gap:1.5rem; flex-wrap:wrap; margin-bottom:0.75rem; }
        .lg-stats b { font-size:1.3rem; display:block; }
        .lg-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        .lg-table th, .lg-table td { text-align:left; padding:0.4rem 0.5rem; border-bottom:1px solid var(--color-border); vertical-align:top; }
        .lg-msg { font-family:monospace; white-space:pre-wrap; word-break:break-word; max-width:520px; }
        .lg-lvl { padding:1px 6px; border-radius:4px; font-size:0.75rem; background:rgb(var(--rgb-fg,255 255 255) / 0.1); }
        .lg-lvl.fatal, .lg-lvl.parse { background:rgba(248,113,113,0.25); color:#f87171; }
        .lg-lvl.js, .lg-lvl.promise { background:rgba(96,165,250,0.2); }
        .lg-row { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:0.75rem; }
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
        <?php $adminNav = 'registro'; include __DIR__ . '/../../templates/admin/partials/nav.php'; ?>
        <h1 style="font-size:1.5rem; margin-bottom:1rem;">🧾 Registro de errores y búsquedas sin resultado</h1>
        <?php if (!$tableOk): ?>
            <div class="lg-card"><p>Las tablas del registro aún no existen (migración 036). Se crean al reiniciar el visor.</p></div>
        <?php else: ?>
        <div class="lg-row">
            <?php foreach ([1 => 'Hoy (24 h)', 7 => '7 días', 30 => '30 días', 365 => 'Un año'] as $d => $label): ?>
                <a class="btn btn-sm <?= $d === $days ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/registro?dias=<?= $d ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <section class="lg-card">
            <h2 style="font-size:1.1rem; margin-bottom:0.5rem;">Errores</h2>
            <div class="lg-stats">
                <div><b><?= $errTotal ?></b>veces</div>
                <div><b><?= count($errors) ?></b>errores distintos</div>
                <div><b><?= $fatal ?></b>fatales</div>
            </div>
            <p>PHP (avisos y errores fatales) y JavaScript del navegador. Meta: 0 fatales en 7 días. Un mismo error se cuenta una vez con su número de veces.</p>
            <?php if ($errors): ?>
            <table class="lg-table">
                <tr><th>Cuándo</th><th>Tipo</th><th>Veces</th><th>Error</th><th>Dónde</th><th></th></tr>
                <?php foreach ($errors as $r): ?>
                <tr>
                    <td><?= e($r['last_seen']) ?></td>
                    <td><span class="lg-lvl <?= e($r['source'] === 'js' ? 'js' : $r['level']) ?>"><?= e(strtoupper($r['source'])) ?> · <?= e($r['level']) ?></span></td>
                    <td><?= (int) $r['count'] ?></td>
                    <td class="lg-msg"><?= e(mb_substr($r['message'], 0, 600)) ?></td>
                    <td><?= e($r['file']) ?>:<?= (int) $r['line'] ?><br><small style="color:var(--color-text-muted)"><?= e($r['url']) ?></small></td>
                    <td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="clear_one"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-secondary" title="Marcar como resuelto">✓</button></form></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <form method="post" style="margin-top:0.75rem"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="clear_errors"><button class="btn btn-sm btn-secondary" onclick="return confirm('¿Borrar todo el registro de errores?')">Limpiar errores</button></form>
            <?php else: ?>
                <p>✅ Sin errores en este período.</p>
            <?php endif; ?>
        </section>

        <section class="lg-card">
            <h2 style="font-size:1.1rem; margin-bottom:0.5rem;">Búsquedas sin resultado</h2>
            <div class="lg-stats">
                <div><b><?= $missTotal ?></b>búsquedas</div>
                <div><b><?= count($misses) ?></b>términos distintos</div>
                <div><b><?= $people ?></b>personas (sesiones)</div>
            </div>
            <p>Lo que se buscó y no salió nada (ni productos ni carpetas). Lo más repetido arriba: o falta la foto/producto, o hay que renombrar algo.</p>
            <?php if ($misses): ?>
            <table class="lg-table">
                <tr><th>Término</th><th>Veces</th><th>Personas</th><th>Última</th></tr>
                <?php foreach ($misses as $r): ?>
                <tr><td><?= e($r['term']) ?></td><td><?= (int) $r['veces'] ?></td><td><?= (int) $r['personas'] ?></td><td><?= e($r['ultima']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <form method="post" style="margin-top:0.75rem"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="clear_misses"><button class="btn btn-sm btn-secondary" onclick="return confirm('¿Borrar la lista de búsquedas sin resultado?')">Limpiar búsquedas</button></form>
            <?php else: ?>
                <p>Sin búsquedas fallidas en este período.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
</body>

</html>
