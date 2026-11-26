<?php
/**
 * Admin — Códigos condenados (GET/POST /admin/condenados). Decisión del dueño 2026-11-21.
 * Un código de esta lista se ignora en todo el visor: la Hoja master no lo crea ni lo actualiza, no se crea como
 * «Solo Drive», ninguna foto se enlaza a él y no sale en la búsqueda. Si el producto ya estaba, se borra.
 * "M:", "M" y "M-" son el mismo código (condemnedKey). Al quitar uno de la lista vuelve en el próximo sync.
 * POST action=add (code, note) | action=remove (id), con csrf_token.
 */
require_once __DIR__ . '/../services/DriveIndex.php';

$db = getDB();
$msg = null;
$err = null;
$tableOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $err = 'Token CSRF inválido. Recarga la página.';
    } elseif (($_POST['action'] ?? '') === 'add') {
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
        $key = condemnedKey($code);
        if ($key === '' || !preg_match('/\d/', $key) || mb_strlen($code) > 100) {
            $err = 'Escribe un código válido (con números), p. ej. M:8325-1.';
        } else {
            try {
                $stmt = $db->prepare("INSERT IGNORE INTO condemned_codes (code, code_key, note) VALUES (?, ?, ?)");
                $stmt->execute([$code, $key, $note]);
                if ($stmt->rowCount() === 0) {
                    $err = "«{$code}» ya está en la lista.";
                } else {
                    condemnedIndex($db, true);
                    $deleted = purgeCondemnedProducts($db);
                    DriveIndex::relink($db); // sus fotos se desenlazan y las portadas se rehacen al momento
                    $msg = "🚫 «{$code}» condenado." . ($deleted
                        ? ' Se borró del visor: ' . implode(', ', $deleted) . '.'
                        : ' No estaba en el visor.');
                }
            } catch (\PDOException $e) {
                $err = 'No se pudo guardar: ' . $e->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'remove') {
        try {
            $stmt = $db->prepare("SELECT code FROM condemned_codes WHERE id = ?");
            $stmt->execute([(int) ($_POST['id'] ?? 0)]);
            $code = $stmt->fetchColumn();
            if ($code !== false) {
                $db->prepare("DELETE FROM condemned_codes WHERE id = ?")->execute([(int) $_POST['id']]);
                $msg = "«{$code}» salió de la lista. Vuelve al visor en el próximo sync (cada hora) si está en la hoja o tiene foto.";
            }
        } catch (\PDOException $e) {
            $err = 'No se pudo quitar: ' . $e->getMessage();
        }
    }
    $_SESSION['condemned_flash'] = [$msg, $err];
    redirect('/admin/condenados');
}
[$msg, $err] = $_SESSION['condemned_flash'] ?? [null, null];
unset($_SESSION['condemned_flash']);

$list = [];
try {
    $list = $db->query("SELECT id, code, note, created_at FROM condemned_codes ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $tableOk = false;
}
// Archivos de Drive con ese código (quedan en Drive, pero el visor no los enlaza a nada)
$fileCount = [];
if ($list) {
    try {
        $names = $db->query("SELECT name FROM drive_index WHERE is_folder = 0")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($list as $c) {
            $idx = skuMatchIndex([$c['code']]);
            $fileCount[$c['id']] = count(array_filter($names, fn($n) => isCondemnedFile((string) $n, $idx)));
        }
    } catch (\PDOException $e) { /* índice aún no existe */ }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Condenados · Viewfinder Admin</title>
    <style>body{background:var(--color-bg,#161618);color:var(--color-text,#f5f5f7)}</style>
    <script src="/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .cd-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem; margin-bottom:1.25rem; }
        .cd-card p { color:var(--color-text-muted); font-size:0.85rem; }
        .cd-form { display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.75rem; }
        .cd-form input { padding:0.5rem 0.7rem; border-radius:var(--radius-sm); border:1px solid var(--color-border); background:var(--color-surface-2); color:inherit; }
        .cd-form input[name=code] { width:180px; text-transform:uppercase; }
        .cd-form input[name=note] { flex:1; min-width:180px; }
        .cd-msg { padding:0.75rem 1rem; border-radius:var(--radius-sm); margin-bottom:1rem; font-size:0.9rem; }
        .cd-msg.ok { background:rgba(52,168,83,0.12); border:1px solid rgba(52,168,83,0.4); }
        .cd-msg.err { background:rgba(248,113,113,0.12); border:1px solid rgba(248,113,113,0.4); color:var(--color-danger); }
        .cd-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
        .cd-table th, .cd-table td { padding:0.55rem 0.6rem; border-bottom:1px solid var(--color-border); text-align:left; vertical-align:middle; }
        .cd-table th { color:var(--color-text-muted); font-weight:600; font-size:0.78rem; }
        .cd-table td.code { font-weight:700; color:var(--color-danger); white-space:nowrap; }
        .cd-table td.muted { color:var(--color-text-muted); white-space:nowrap; }
    </style>
</head>

<body class="v2 v2-legacy">

    <main class="container" style="padding-top:2rem; padding-bottom:3rem;">
        <?php $adminNav = 'condenados'; include __DIR__ . '/../../templates/admin/partials/nav.php'; ?>
        <h1 style="font-size:1.5rem; margin-bottom:1rem;">🚫 Códigos condenados</h1>

        <?php if ($msg): ?><div class="cd-msg ok"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="cd-msg err"><?= e($err) ?></div><?php endif; ?>

        <section class="cd-card">
            <p>
                Un código condenado <b>no vuelve a tenerse en cuenta</b>: la Hoja master no lo crea ni lo actualiza, no se crea como
                «Solo Drive», ninguna foto se enlaza a él y no sale en la búsqueda. Si ya estaba en el visor, <b>se borra</b>.
                «M:», «M» y «M-» cuentan como el mismo código (M:8325-1 = M8325-1 = M-8325-1). Las fotos siguen en Drive; si quieres que
                tampoco se vean al abrir su carpeta, oculta o mueve la carpeta.
            </p>
            <?php if ($tableOk): ?>
                <form class="cd-form" method="post" action="/admin/condenados">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="text" name="code" placeholder="Código, p. ej. M:8325-1" required maxlength="100" autocomplete="off">
                    <input type="text" name="note" placeholder="Motivo (opcional)" maxlength="255">
                    <button class="btn btn-primary" type="submit" onclick="return confirm('¿Condenar este código? Si está en el visor se borra.')">🚫 Condenar</button>
                </form>
            <?php else: ?>
                <p style="color:var(--color-danger);">La tabla aún no existe: falta aplicar la migración 034 (se aplica sola al desplegar).</p>
            <?php endif; ?>
        </section>

        <?php if ($tableOk): ?>
            <section class="cd-card">
                <?php if (!$list): ?>
                    <p>No hay códigos condenados.</p>
                <?php else: ?>
                    <p style="margin-bottom:0.5rem;"><?= count($list) ?> código<?= count($list) === 1 ? '' : 's' ?> en la lista.</p>
                    <div style="overflow-x:auto;">
                        <table class="cd-table">
                            <thead><tr><th>Código</th><th>Motivo</th><th>Archivos en Drive</th><th>Desde</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($list as $c): ?>
                                    <tr>
                                        <td class="code"><?= e($c['code']) ?></td>
                                        <td><?= e($c['note']) ?></td>
                                        <td class="muted"><?= isset($fileCount[$c['id']]) ? number_format($fileCount[$c['id']]) . ' sin enlazar' : '—' ?></td>
                                        <td class="muted"><?= e(substr((string) $c['created_at'], 0, 16)) ?></td>
                                        <td>
                                            <form method="post" action="/admin/condenados" style="margin:0;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                <button class="btn btn-sm btn-secondary" type="submit" onclick="return confirm('¿Quitar este código de la lista? Volverá en el próximo sync.')">Quitar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>

</html>
