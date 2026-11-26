<?php
/**
 * Admin — Usuarios (GET/POST /admin/usuarios). v2 módulo 1.
 * POST action=create | role | active | password, con csrf_token. Solo se gestionan cuentas de rango menor al propio
 * (Users::manageable); el Desarrollador del entorno (ADMIN_USER) no es una cuenta de esta tabla.
 */
require_once __DIR__ . '/../services/Users.php';

$db = getDB();
$actorRole = currentRole();
$me = sessionUser();
$allowed = Users::manageable($actorRole);
$msg = null;
$err = null;
$tableOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $target = $id ? Users::find($db, $id) : null;
    $isSelf = $target && $me && (int) $target['id'] === (int) $me['id'];
    if (!verifyCsrf()) {
        $err = 'Token CSRF inválido. Recarga la página.';
    } elseif ($action === 'create') {
        $err = Users::create($db, (string) ($_POST['username'] ?? ''), (string) ($_POST['name'] ?? ''),
            (string) ($_POST['role'] ?? ''), (string) ($_POST['password'] ?? ''), $actorRole);
        $msg = $err ? null : '✅ Cuenta creada.';
    } elseif (!$target) {
        $err = 'Cuenta no encontrada.';
    } elseif ($action === 'password' && ($isSelf || in_array($target['role'], $allowed, true))) {
        $pw = (string) ($_POST['password'] ?? '');
        if (mb_strlen($pw) < 8) {
            $err = 'La clave debe tener al menos 8 caracteres.';
        } else {
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
            $msg = '✅ Clave cambiada para «' . $target['username'] . '».';
        }
    } elseif ($isSelf || !in_array($target['role'], $allowed, true)) {
        $err = 'No puedes modificar esta cuenta (es la tuya o de un nivel igual o superior).';
    } elseif ($action === 'role' && in_array((string) ($_POST['role'] ?? ''), $allowed, true)) {
        $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$_POST['role'], $id]);
        $msg = '✅ Perfil de «' . $target['username'] . '» cambiado.';
    } elseif ($action === 'active') {
        $on = !empty($_POST['on']) ? 1 : 0;
        $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$on, $id]);
        $msg = $on ? '✅ Cuenta activada.' : '✅ Cuenta desactivada: ya no puede entrar.';
    } else {
        $err = 'Acción no válida.';
    }
    $_SESSION['users_flash'] = [$msg, $err];
    redirect('/admin/usuarios');
}
[$msg, $err] = $_SESSION['users_flash'] ?? [null, null];
unset($_SESSION['users_flash']);

$list = [];
try {
    $list = $db->query("SELECT id, username, name, role, is_active, last_login_at FROM users ORDER BY role, username")->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $tableOk = false;
}
?>
<?php $v2 = ['nav' => 'usuarios', 'title' => 'Usuarios', 'subtitle' => 'Cuentas y permisos'];
include __DIR__ . '/../../templates/admin/partials/v2_start.php'; ?>
<style>
        .us-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:1.25rem; margin-bottom:1.25rem; }
        .us-card p { color:var(--color-text-muted); font-size:0.85rem; }
        .us-form { display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.75rem; }
        .us-form input, .us-form select, .us-row select, .us-row input { padding:0.45rem 0.6rem; border-radius:var(--radius-sm); border:1px solid var(--color-border); background:var(--color-surface-2); color:inherit; }
        .us-msg { padding:0.75rem 1rem; border-radius:var(--radius-sm); margin-bottom:1rem; font-size:0.9rem; }
        .us-msg.ok { background:rgba(52,168,83,0.12); border:1px solid rgba(52,168,83,0.4); }
        .us-msg.err { background:rgba(248,113,113,0.12); border:1px solid rgba(248,113,113,0.4); color:var(--color-danger); }
        .us-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
        .us-table th, .us-table td { padding:0.55rem 0.6rem; border-bottom:1px solid var(--color-border); text-align:left; vertical-align:middle; }
        .us-table th { color:var(--color-text-muted); font-weight:600; font-size:0.78rem; }
        .us-row { display:inline-flex; gap:0.35rem; margin:0 0.5rem 0.25rem 0; }
        .us-off { opacity:0.55; }
    </style>


        <?php if ($msg): ?><div class="us-msg ok"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="us-msg err"><?= e($err) ?></div><?php endif; ?>

        <section class="us-card">
            <p>
                Tú entras como <b><?= e(Users::label($actorRole)) ?></b>. Puedes crear y gestionar cuentas de nivel menor al tuyo
                (Administrador: hasta Gestor de bodega; Desarrollador: todas). Quien tenga una cuenta desactivada no puede entrar.
                Los <b>clientes</b> se registrarán solos en el portal (módulo 4); aquí puedes crearlos a mano si hace falta.
            </p>
            <?php if ($tableOk): ?>
                <form class="us-form" method="post" action="/admin/usuarios" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="text" name="username" placeholder="usuario (minúsculas)" required maxlength="40">
                    <input type="text" name="name" placeholder="Nombre" maxlength="120">
                    <select name="role">
                        <?php foreach ($allowed as $r): ?><option value="<?= e($r) ?>"><?= e(Users::label($r)) ?></option><?php endforeach; ?>
                    </select>
                    <input type="password" name="password" placeholder="Clave (mín. 8)" required minlength="8" autocomplete="new-password">
                    <button class="btn btn-primary" type="submit">➕ Crear cuenta</button>
                </form>
            <?php else: ?>
                <p style="color:var(--color-danger);">La tabla aún no existe: falta aplicar la migración 039 (se aplica sola al desplegar).</p>
            <?php endif; ?>
        </section>

        <section class="us-card">
            <?php if (!$list): ?>
                <p>Todavía no hay cuentas propias. La cuenta de las variables de entorno (<code>ADMIN_USER</code>) sigue entrando como Desarrollador.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="us-table">
                    <thead><tr><th>Usuario</th><th>Nombre</th><th>Perfil</th><th>Último ingreso</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $u):
                        $self = $me && (int) $me['id'] === (int) $u['id'];
                        $canManage = !$self && in_array($u['role'], $allowed, true);
                        $canPw = $self || $canManage; ?>
                        <tr class="<?= $u['is_active'] ? '' : 'us-off' ?>">
                            <td><b><?= e($u['username']) ?></b><?= $self ? ' (tú)' : '' ?></td>
                            <td><?= e($u['name']) ?></td>
                            <td><?= e(Users::label($u['role'])) ?><?= $u['is_active'] ? '' : ' · desactivada' ?></td>
                            <td><?= e((string) ($u['last_login_at'] ?? 'nunca')) ?></td>
                            <td>
                                <?php if ($canManage): ?>
                                    <form class="us-row" method="post" action="/admin/usuarios">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <select name="role"><?php foreach ($allowed as $r): ?><option value="<?= e($r) ?>"<?= $r === $u['role'] ? ' selected' : '' ?>><?= e(Users::label($r)) ?></option><?php endforeach; ?></select>
                                        <button class="btn btn-sm btn-secondary" type="submit">Cambiar perfil</button>
                                    </form>
                                    <form class="us-row" method="post" action="/admin/usuarios">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="active"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <input type="hidden" name="on" value="<?= $u['is_active'] ? 0 : 1 ?>">
                                        <button class="btn btn-sm btn-secondary" type="submit"><?= $u['is_active'] ? 'Desactivar' : 'Activar' ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canPw): ?>
                                    <form class="us-row" method="post" action="/admin/usuarios" autocomplete="off">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <input type="password" name="password" placeholder="Nueva clave" minlength="8" required autocomplete="new-password" style="width:130px;">
                                        <button class="btn btn-sm btn-secondary" type="submit">Cambiar clave</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>
    <?php include __DIR__ . '/../../templates/admin/partials/v2_end.php'; ?>
