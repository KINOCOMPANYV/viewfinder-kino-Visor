<?php
/**
 * Admin → 🚚 Remisión (/admin/remision): plantilla del PDF «Remisión» (Extractor → 🚚 Remisión).
 * Se editan título, prefijo del número, colores, líneas del pie, marcas y logos (subir, reemplazar, pasar a
 * blanco, mover, quitar). Guarda en doc_templates vía RemisionTemplate. POST JSON {action: save|reset, template}
 * con header X-CSRF-TOKEN. El PDF se arma en el dispositivo (assets/js/order_list.js) con esta plantilla.
 */
require_once __DIR__ . '/../services/RemisionTemplate.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        jsonResponse(['error' => 'La sesión expiró: recarga la página.'], 403);
    }
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    try {
        $db = getDB();
        if (($in['action'] ?? '') === 'reset') {
            RemisionTemplate::reset($db);
            jsonResponse(['ok' => true, 'template' => RemisionTemplate::load($db), 'message' => '↩️ Volvió la plantilla de fábrica.']);
        }
        $t = RemisionTemplate::save($db, (array) ($in['template'] ?? []));
        jsonResponse(['ok' => true, 'template' => $t, 'message' => '✅ Plantilla guardada. La próxima Remisión ya sale así.']);
    } catch (\Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

$tpl = RemisionTemplate::load(getDB());
include __DIR__ . '/../../templates/admin/remision.php';
