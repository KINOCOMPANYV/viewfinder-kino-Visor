<?php
/**
 * Admin — 🗄 Pedidos (GET/POST /admin/pedidos). Copia de seguridad de «Pedidos bodega» (decisión del dueño 2026-11-23).
 * ?descargar=xlsx|json baja todos los pedidos; POST action=drive sube una copia a Google Drive ahora.
 * La copia nocturna automática la hace scripts/cli_sync.php pedidos (OrdersBackup).
 */
require_once __DIR__ . '/../services/OrdersBackup.php';

$db = getDB();
$dl = $_GET['descargar'] ?? '';
if ($dl === 'xlsx' || $dl === 'json') {
    $rows = OrdersBackup::rows($db);
    $name = OrdersBackup::baseName() . '_' . OrdersBackup::now()->format('H-i') . '.' . $dl;
    $body = $dl === 'xlsx' ? OrdersBackup::xlsx($rows) : OrdersBackup::json($rows);
    header('Content-Type: ' . ($dl === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/json; charset=utf-8'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

$msg = $err = null;
$folder = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'db') {
    if (!verifyCsrf()) {
        $err = 'Token CSRF inválido. Recarga la página.';
    } else {
        require_once __DIR__ . '/../services/DbBackup.php';
        set_time_limit(600);
        $r = DbBackup::toDrive($db, true);
        $r['ok'] ? $msg = '☁️ ' . $r['msg'] : $err = $r['msg'];
        $folder = $r['folder'] ?? null;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'drive') {
    if (!verifyCsrf()) {
        $err = 'Token CSRF inválido. Recarga la página.';
    } else {
        $r = OrdersBackup::toDrive($db, true);
        $r['ok'] ? $msg = '☁️ ' . $r['msg'] : $err = $r['msg'];
        $folder = $r['folder'] ?? null;
    }
}

$stats = $db->query("SELECT COUNT(*) AS n, SUM(status = 'listo') AS listos, MIN(created_at) AS desde, MAX(updated_at) AS ultimo
    FROM warehouse_orders")->fetch(PDO::FETCH_ASSOC);
$hour = (int) env('PEDIDOS_BACKUP_HOUR', '22');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos · Viewfinder Admin</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .ob-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem; margin-bottom:1.25rem; }
        .ob-card p { color:var(--color-text-muted); font-size:0.9rem; margin-bottom:0.75rem; }
        .ob-row { display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center; }
        .ob-stats { display:flex; gap:1.5rem; flex-wrap:wrap; font-size:0.95rem; margin-bottom:0.75rem; }
        .ob-stats b { font-size:1.3rem; display:block; }
        .ob-msg { padding:0.75rem 1rem; border-radius:var(--radius-sm); margin-bottom:1rem; font-size:0.9rem; }
        .ob-msg.ok { background:rgba(52,168,83,0.12); border:1px solid rgba(52,168,83,0.4); }
        .ob-msg.err { background:rgba(248,113,113,0.12); border:1px solid rgba(248,113,113,0.4); color:var(--color-danger); }
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
        <?php $adminNav = 'pedidos'; include __DIR__ . '/../../templates/admin/partials/nav.php'; ?>
        <h1 style="font-size:1.5rem; margin-bottom:1rem;">🗄 Copia de seguridad de Pedidos bodega</h1>

        <?php if ($msg): ?><div class="ob-msg ok"><?= e($msg) ?><?php if ($folder): ?> · <a href="https://drive.google.com/drive/folders/<?= e($folder) ?>" target="_blank" rel="noopener">Abrir carpeta en Drive</a><?php endif; ?></div><?php endif; ?>
        <?php if ($err): ?><div class="ob-msg err"><?= e($err) ?></div><?php endif; ?>

        <section class="ob-card">
            <div class="ob-stats">
                <div><b><?= (int) $stats['n'] ?></b>pedidos guardados</div>
                <div><b><?= (int) $stats['listos'] ?></b>listos</div>
                <div><b><?= (int) $stats['n'] - (int) $stats['listos'] ?></b>pendientes</div>
                <?php if ($stats['ultimo']): ?><div><b style="font-size:1rem"><?= e($stats['ultimo']) ?></b>último cambio</div><?php endif; ?>
            </div>
            <p>
                Los pedidos se guardan en la base de datos del visor (MySQL en Railway) y no se borran al actualizar el visor.
                Aquí puedes bajar una copia cuando quieras. El <b>Excel</b> trae una pestaña «Pedidos» y otra «Líneas» (cada producto,
                cantidades, precio, alistado y nota). El <b>JSON</b> trae todo tal cual, para poder restaurar.
            </p>
            <div class="ob-row">
                <a class="btn btn-primary" href="/admin/pedidos?descargar=xlsx">⬇ Descargar Excel</a>
                <a class="btn btn-secondary" href="/admin/pedidos?descargar=json">⬇ Descargar JSON completo</a>
            </div>
        </section>

        <section class="ob-card">
            <p>
                <b>Copia automática en Google Drive:</b> cada noche (desde las <?= $hour ?>:00, hora Colombia) el visor sube
                «Pedidos_bodega_AAAA-MM-DD» en Excel y JSON a la carpeta
                «<?= e(env('PEDIDOS_BACKUP_FOLDER_ID', '') !== '' ? 'PEDIDOS_BACKUP_FOLDER_ID' : OrdersBackup::FOLDER_NAME) ?>» de tu Drive
                (la cuenta conectada en Admin → Media). Las copias viejas no se borran.
            </p>
            <form method="post" action="/admin/pedidos" class="ob-row">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="drive">
                <button class="btn btn-secondary" type="submit">☁️ Copiar a Drive ahora</button>
            </form>
        </section>
        <section class="ob-card">
            <p>
                <b>Respaldo de toda la base de datos (MySQL):</b> cada madrugada (desde las <?= (int) env('BACKUP_DB_HOUR', '3') ?>:00, hora Colombia)
                el visor sube «visor-mysql-AAAA-MM-DD.sql.gz» a la misma carpeta de Drive y deja los últimos <?= max(3, (int) env('BACKUP_KEEP', '14')) ?>.
                Trae todas las tablas (productos, precios editados, pedidos, huellas de fotos…) menos las claves de Google (se reconecta en Admin → Media) y cachés.
                Cómo probar la restauración: <code>docs/RESPALDOS.md</code>. Un respaldo que falla aparece en Admin → 🧾 Registro.
            </p>
            <form method="post" action="/admin/pedidos" class="ob-row" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Copiando… (puede tardar 1 minuto)'">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="db">
                <button class="btn btn-secondary" type="submit">🗄 Respaldar toda la base ahora</button>
            </form>
        </section>
    </main>
</body>

</html>
