<?php
/**
 * Prueba rápida de la regla foto ↔ código (sin BD): php tests/sku_match_test.php
 * Sale con código 1 si algo falla.
 */
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/helpers.php';

$fails = 0;
function check(string $label, $got, $want): void
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) $fails++;
    echo ($ok ? 'OK   ' : 'FALLA') . " $label" . ($ok ? '' : ' → ' . var_export($got, true) . ' (esperado ' . var_export($want, true) . ')') . "\n";
}

// Solo existen S-640 y S-647: el combinado NO se pega a S-640 (queda libre para «Solo Drive»)
$idx = skuMatchIndex(['S-640', 'S-647', 'X6071']);
check('S-640+S-647-1.jpg sin producto propio', matchFileToProduct('S-640+S-647-1.jpg', $idx), null);
check('S-640.jpg sigue siendo de S-640', matchFileToProduct('S-640.jpg', $idx), ['S-640', 3]);
check('S-640-A.jpg sigue siendo de S-640', matchFileToProduct('S-640-A.jpg', $idx), ['S-640', 2]);
check('X6071+caja.jpg (texto, no código) sigue en X6071', matchFileToProduct('X6071+caja.jpg', $idx), ['X6071', 2]);
check('S-640 + S-647.jpg con espacios sigue en S-640', matchFileToProduct('S-640 + S-647.jpg', $idx), ['S-640', 2]);

// Con el combinado como producto: su foto exacta
$idx = skuMatchIndex(['S-640', 'S-640+S-647-1', 'S-640+S-647']);
check('S-640+S-647-1.jpg → su propio código', matchFileToProduct('S-640+S-647-1.jpg', $idx), ['S-640+S-647-1', 3]);
check('S-640+S-647.jpg → S-640+S-647', matchFileToProduct('S-640+S-647.jpg', $idx), ['S-640+S-647', 3]);
check('S-640+S-647 (2).jpg → S-640+S-647', matchFileToProduct('S-640+S-647 (2).jpg', $idx), ['S-640+S-647', 2]);

// «Solo Drive»: el nombre propone el código combinado
check('driveCodeFromName S-640+S-647-3.jpg', driveCodeFromName('S-640+S-647-3.jpg'), 'S-640+S-647-3');
check('driveCodeFromName C9081BL-2.png', driveCodeFromName('C9081BL-2.png'), 'C9081BL-2');

// Familia: S-640+S-647-2 → padre S-640+S-647-1, base de fotos genéricas S-640+S-647
$parents = computeParentSkuMap(['S-640+S-647-1' => ['sku' => 'S-640+S-647-1', 'name' => 'a'], 'S-640+S-647-2' => ['sku' => 'S-640+S-647-2', 'name' => 'b']]);
check('padre de S-640+S-647-2', $parents['S-640+S-647-2'], 'S-640+S-647-1');
check('base de fotos de la familia', productMediaBase('S-640+S-647-2', $parents), 'S-640+S-647');

// Condenados: condenar S-640 no condena el combinado
$cond = skuMatchIndex(['S-640']);
check('condenado S-640 no toca S-640+S-647-1.jpg', isCondemnedFile('S-640+S-647-1.jpg', $cond), false);
check('condenado S-640 sí toca S-640-A.jpg', isCondemnedFile('S-640-A.jpg', $cond), true);

echo $fails ? "\n$fails fallas\n" : "\nTodo bien\n";
exit($fails ? 1 : 0);
