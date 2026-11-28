<?php
/**
 * Menú lateral v2 (lo usan v2_start.php y, en las pantallas v1, nav.php).
 * Lee $v2['nav'] (módulo activo) y $v2['sub'] (herramienta activa dentro de Configuraciones).
 * Configuraciones se despliega con sus herramientas (lista en config_tools.php).
 */
$v2 = ($v2 ?? []) + ['nav' => '', 'sub' => ''];
$v2User = sessionUser();
$v2Name = $v2User ? ($v2User['name'] !== '' ? $v2User['name'] : $v2User['username']) : (string) (env('ADMIN_USER') ?: 'Admin');
$v2Role = currentRole();
require_once __DIR__ . '/../../../src/services/Users.php';
$v2Initials = strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N} ]/u', '', $v2Name) ?: 'A', 0, 2));
include __DIR__ . '/config_tools.php';
// Texto del submenú sin el emoji inicial (menú sobrio, sin iconos)
$v2Plain = fn(string $t) => trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $t));
$v2Pending = null;
try {
    $v2Pending = (int) getDB()->query("SELECT COUNT(*) FROM orders WHERE stage IN ('recibido','por_alistar','en_alistamiento')")->fetchColumn();
} catch (Throwable $e) {
}
// Por cobrar: clientes con deuda de más de 20 / 40 días (el vendedor, solo los suyos). Avisa en el menú y arriba de cada pantalla.
$v2Cobro = [0, 0];
try {
    $cu = sessionUser();
    $cw = currentRole() === 'vendedor' && $cu ? ' AND seller_user_id = ' . (int) $cu['id'] : '';
    $cr = getDB()->query("SELECT COALESCE(SUM(debt_since < CURDATE() - INTERVAL 20 DAY), 0), COALESCE(SUM(debt_since < CURDATE() - INTERVAL 40 DAY), 0)
        FROM customers WHERE balance > 0$cw")->fetch(PDO::FETCH_NUM);
    $v2Cobro = [(int) $cr[0], (int) $cr[1]];
} catch (Throwable $e) { /* migración 045 aún sin aplicar */ }
// [clave, ruta (null = pronto), texto, icono SVG]
$v2Menu = [
    ['vender', '/vender', 'Vender', '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>'],
    ['pedidos', '/pedidos', 'Pedidos', '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>'],
    ['productos', '/admin/productos', 'Productos', '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'],
    ['inventario', '/admin/inventario', 'Inventario', '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="M3 8v9l9 5 9-5V8M12 13v9"/>'],
    ['catalogo', '/tienda', 'Catálogo Online', '<circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6"/>'],
    ['clientes', '/clientes', 'Clientes', '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'],
    ['transacciones', null, 'Transacciones', '<path d="M12 2v20"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
    ['finanzas', null, 'Finanzas', '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 3h12"/>'],
    ['estadisticas', null, 'Estadísticas', '<path d="M5 21V12"/><path d="M12 21V4"/><path d="M19 21v-6"/>'],
    ['usuarios', '/admin/usuarios', 'Usuarios', '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18.5 14a6.5 6.5 0 0 1 3 6"/>'],
    ['extractor', '/extractor', 'Extractor', '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>'],
    ['manifiestos', '/manifiestos', 'Manifiestos', '<path d="M3 7h11v10H3z"/><path d="M14 10h4l3 3v4h-7"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>'],
    ['gestion', '/admin/configuraciones', 'Configuraciones', '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>'],
];
// Usuarios, Productos y Configuraciones son solo del administrador: no se muestran a los demás perfiles
if (!isAdminLoggedIn()) {
    $v2Menu = array_values(array_filter($v2Menu, fn($m) => !in_array($m[0], ['productos', 'inventario', 'usuarios', 'gestion'], true)));
}
// Extractor y Manifiestos: solo el personal (igual que sus rutas)
if (!isStaff()) {
    $v2Menu = array_values(array_filter($v2Menu, fn($m) => !in_array($m[0], ['extractor', 'manifiestos'], true)));
}
$v2CfgOpen = $v2['nav'] === 'gestion';
?>
    <aside class="v2-side" id="v2Side">
        <div class="v2-brand">
            <a href="/admin">KINO COMPANY S.A.S</a>
            <span class="v2-badge">Sistema v2</span>
        </div>
        <nav class="v2-menu" aria-label="Módulos de KINO">
            <?php foreach ($v2Menu as [$key, $href, $label, $svg]): $on = $v2['nav'] === $key; ?>
                <?php if ($key === 'gestion'): ?>
                    <details class="v2-group"<?= $v2CfgOpen ? ' open' : '' ?>>
                        <summary class="v2-item<?= $v2CfgOpen ? ' on' : '' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $svg ?></svg><?= e($label) ?><i class="v2-caret" aria-hidden="true"></i></summary>
                        <div class="v2-subs">
                            <a class="v2-sub-item<?= $v2['sub'] === 'todas' ? ' on' : '' ?>" href="/admin/configuraciones">Todas</a>
                            <?php foreach ($configTools as $tk => [$th, $tl]): ?>
                                <a class="v2-sub-item<?= $v2['sub'] === $tk ? ' on' : '' ?>" href="<?= e($th) ?>"><?= e($v2Plain($tl)) ?></a>
                            <?php endforeach; ?>
                            <span class="v2-sub-h">Herramientas de pedido</span>
                            <?php foreach ($configLinks as [$th, $tl]): ?>
                                <a class="v2-sub-item" href="<?= e($th) ?>"><?= e($v2Plain($tl)) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php elseif ($href === null): ?>
                    <span class="v2-item off" title="Módulo en construcción"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $svg ?></svg><?= e($label) ?><em>Pronto</em></span>
                <?php else: ?>
                    <a class="v2-item<?= $on ? ' on' : '' ?>" href="<?= $href ?>"<?= $on ? ' aria-current="page"' : '' ?><?= $key === 'catalogo' ? ' target="_blank" rel="noopener"' : '' ?>>
                        <?php if ($key === 'pedidos' && $v2Pending): ?><b class="v2-count"><?= $v2Pending ?></b><?php elseif ($key === 'clientes' && $v2Cobro[0]): ?><b class="v2-count v2-count--cobro" title="Clientes por cobrar (más de 20 días)"><?= $v2Cobro[0] ?></b><?php else: ?><svg viewBox="0 0 24 24" aria-hidden="true"><?= $svg ?></svg><?php endif; ?><?= e($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <a class="v2-logout" href="/admin/logout">Cerrar sesión</a>
    </aside>
    <button class="v2-scrim" type="button" aria-label="Cerrar menú" tabindex="-1"></button>
    <script src="<?= e(assetVersion('/assets/js/v2_shell.js')) ?>" defer></script>
