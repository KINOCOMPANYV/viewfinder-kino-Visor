<?php
/**
 * Admin — Restaurar respaldo de MySQL desde Drive (GET/POST /admin/restaurar). SOLO v2.
 * Existe únicamente si el servicio tiene la variable ALLOW_DB_RESTORE=1 (producción NO la tiene: allí responde 404).
 * Baja el .sql.gz elegido de Drive y lo restaura en la base de ESTE servicio (borra y recrea sus tablas).
 * Se conserva google_tokens (la conexión con Google de v2) y users (cuentas de v2).
 */
if (env('ALLOW_DB_RESTORE') !== '1') {
    http_response_code(404);
    echo 'No encontrado';
    exit;
}
require_once __DIR__ . '/../services/OrdersBackup.php';
require_once __DIR__ . '/../services/DbBackup.php';
require_once __DIR__ . '/../services/GoogleDriveService.php';

$db = getDB();
$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$msg = $err = null;
$files = [];
$drive = new GoogleDriveService();
$connected = (bool) $drive->getValidToken($db);
$folder = '';
if ($connected) {
    $folder = trim((string) env('PEDIDOS_BACKUP_FOLDER_ID', '')) ?: (string) $drive->findChildByName('root', DbBackup::FOLDER_NAME, true);
    if ($folder !== '') {
        $files = array_values(array_filter($drive->listFiles($folder)['files'] ?? [],
            fn($f) => strncmp($f['name'], DbBackup::PREFIX, strlen(DbBackup::PREFIX)) === 0 && substr($f['name'], -7) === '.sql.gz'));
        usort($files, fn($a, $b) => strcmp($b['name'], $a['name']));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (string) ($_POST['file_id'] ?? '');
    $pick = null;
    foreach ($files as $f) if ($f['id'] === $id) $pick = $f;
    if (!verifyCsrf()) {
        $err = 'Token CSRF inválido. Recarga la página.';
    } elseif (!$pick) {
        $err = 'Elige un respaldo de la lista.';
    } elseif (trim((string) ($_POST['confirm'] ?? '')) !== 'RESTAURAR') {
        $err = 'Escribe RESTAURAR para confirmar.';
    } else {
        @set_time_limit(900);
        $tmp = tempnam(sys_get_temp_dir(), 'rst');
        try {
            if (!$drive->downloadFile($pick['id'], $tmp) || filesize($tmp) < 100) {
                throw new RuntimeException('No se pudo bajar el respaldo de Drive.');
            }
            $r = DbBackup::restore($db, $tmp, ['google_tokens', 'users']);
            if ($r['ok']) {
                $msg = "✅ Restauración correcta de «{$pick['name']}» en la base «{$dbName}»: {$r['tables']} tablas, {$r['statements']} sentencias. "
                    . 'Reinicia el servicio en Railway para que corran las migraciones de v2.';
            } else {
                $err = '❌ Restauración con diferencias: ' . json_encode($r['mismatches'], JSON_UNESCAPED_UNICODE);
            }
        } catch (\Throwable $e) {
            $err = 'Falló: ' . $e->getMessage();
        } finally {
            @unlink($tmp);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurar respaldo · Viewfinder Admin</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>
<body class="v2 v2-legacy">
<div class="container" style="padding-top:1rem;max-width:760px;">
    <?php $adminNav = 'restaurar'; include __DIR__ . '/../../templates/admin/partials/nav.php'; ?>
    <h1 style="font-size:1.3rem;">♻️ Restaurar respaldo (solo v2)</h1>
    <p style="color:var(--color-text-muted);">Reemplaza las tablas de la base <b><?= e($dbName) ?></b> de ESTE servicio con un respaldo de Drive.
        Se conservan la conexión con Google y las cuentas de usuario de v2. La base de producción no se toca (este servicio no tiene acceso a ella).</p>
    <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
    <?php if (!$connected): ?>
        <p>Primero conecta Google Drive en <a href="/admin/media">Admin → Media</a> (botón «Conectar») y vuelve aquí.</p>
    <?php elseif (!$files): ?>
        <p>No hay respaldos <code>visor-mysql-*.sql.gz</code> en la carpeta de Drive de esta cuenta.</p>
    <?php else: ?>
        <form method="post" onsubmit="return confirm('Esto reemplaza los datos de la base de v2. ¿Seguir?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <select class="form-input" name="file_id" style="margin-bottom:.6rem;">
                <?php foreach ($files as $f): ?><option value="<?= e($f['id']) ?>"><?= e($f['name']) ?> (<?= round(((int) ($f['size'] ?? 0)) / 1048576, 1) ?> MB)</option><?php endforeach; ?>
            </select>
            <input class="form-input" name="confirm" placeholder="Escribe RESTAURAR" autocomplete="off" style="margin-bottom:.6rem;">
            <button class="btn btn-primary" type="submit">Restaurar en v2</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
