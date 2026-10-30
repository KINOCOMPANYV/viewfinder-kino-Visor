<?php
require_once __DIR__ . '/../services/DriveIndex.php';
/**
 * Admin — Activar o desactivar productos (nunca se borran).
 * POST /admin/products/active  (header X-CSRF-TOKEN)
 *   active=1|0 y ids=1,2,3        → esos productos
 *   active=1|0 y all=1 + filtros  → todos los del filtro (q, estado, marca, categoria)
 */
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    jsonResponse(['error' => 'Token CSRF inválido.'], 403);
}

$active = ($_POST['active'] ?? '') === '1' ? 1 : 0;
$db = getDB();

if (($_POST['all'] ?? '') === '1') {
    [$where, $params] = adminProductFilter($_POST);
    $stmt = $db->prepare("UPDATE products SET is_active = ?, updated_at = NOW() WHERE {$where} AND is_active <> ?");
    $stmt->execute(array_merge([$active], $params, [$active]));
    $affected = $stmt->rowCount();
} else {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? '')))));
    if (!$ids) {
        jsonResponse(['error' => 'No hay productos seleccionados.'], 400);
    }
    $affected = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $stmt = $db->prepare("UPDATE products SET is_active = ?, updated_at = NOW() WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
        $stmt->execute(array_merge([$active], $chunk));
        $affected += $stmt->rowCount();
    }
}

refreshProductVisibility($db);
DriveIndex::relink($db); // las familias pueden cambiar: fotos genéricas y portadas

jsonResponse([
    'ok' => true,
    'active' => (bool) $active,
    'affected' => $affected,
    'message' => ($active ? '✅ ' : '⏸️ ') . $affected . ' producto' . ($affected === 1 ? '' : 's') . ($active ? ' activado' : ' desactivado') . ($affected === 1 ? '' : 's'),
]);
