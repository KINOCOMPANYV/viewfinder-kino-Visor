<?php
/** v2 · Configuraciones: punto de entrada a las herramientas de v1 (Dashboard, Drive, Hoja master, precios…). */
include __DIR__ . '/partials/config_tools.php';
$v2 = ['nav' => 'gestion', 'title' => 'Configuraciones', 'subtitle' => 'Herramientas del sistema anterior'];
include __DIR__ . '/partials/v2_start.php';
$groups = [];
foreach ($configTools as [$href, $label, $desc, $group]) {
    $groups[$group][] = [$href, $label, $desc];
}
$groups['Herramientas de pedido (v1)'] = $configLinks;
?>
<?php foreach ($groups as $group => $items): ?>
    <h2 class="v2-cfg-h"><?= e($group) ?></h2>
    <div class="v2-cfg-grid">
        <?php foreach ($items as [$href, $label, $desc]): ?>
            <a class="v2-card v2-cfg" href="<?= e($href) ?>"><b><?= e($label) ?></b><span><?= e($desc) ?></span></a>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php include __DIR__ . '/partials/v2_end.php'; ?>
