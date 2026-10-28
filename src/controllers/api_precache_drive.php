<?php
/**
 * Cron de pre-caché Drive — calienta media_search_cache para todos los productos activos.
 * GET /api/cron/precache-drive?token=CRON_SECRET
 *
 * Procesa hasta 30 SKUs sin caché reciente por ejecución para no agotar el tiempo de Railway.
 * Responde JSON con estadísticas de la ejecución.
 */

header('Content-Type: application/json; charset=utf-8');

// ── Autenticación por token secreto ──────────────────────────────────────────
$cronSecret = env('CRON_SECRET', '');
$tokenProvided = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';

if ($cronSecret === '' || !hash_equals($cronSecret, $tokenProvided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token inválido o no configurado.']);
    exit;
}

// ── Configuración ─────────────────────────────────────────────────────────────
$batchSize   = (int) ($_GET['batch'] ?? 30);   // SKUs por ejecución (máx 50)
$batchSize   = min($batchSize, 50);
$cacheTtlMin = 120;                              // Considerar "reciente" si < 2 horas

$startTime = microtime(true);
$stats = [
    'processed'  => 0,
    'cached_new' => 0,
    'skipped'    => 0,
    'errors'     => 0,
    'duration_ms' => 0,
];

// ── Conexión a BD ─────────────────────────────────────────────────────────────
$db        = getDB();
$folderId  = env('GOOGLE_DRIVE_FOLDER_ID', '');

if (!$folderId) {
    echo json_encode(array_merge($stats, ['error' => 'GOOGLE_DRIVE_FOLDER_ID no configurado.']));
    exit;
}

// ── Obtener SKUs sin caché reciente ──────────────────────────────────────────
// Selecciona productos activos cuyo SKU NO tiene una entrada en media_search_cache
// con cached_at reciente (< $cacheTtlMin minutos). Orden aleatorio: si algún SKU falla
// siempre en Drive, no bloquea al resto del catálogo en cada ejecución.
try {
    $stmt = $db->prepare(
        "SELECT p.sku
         FROM products p
         LEFT JOIN media_search_cache c
               ON c.sku = p.sku
              AND c.cached_at > NOW() - INTERVAL ? MINUTE
         WHERE p.archived = 0
           AND c.sku IS NULL
         ORDER BY RAND()
         LIMIT ?"
    );
    $stmt->execute([$cacheTtlMin, $batchSize]);
    $skuRows = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {
    // media_search_cache podría no existir todavía
    echo json_encode(array_merge($stats, ['error' => 'DB error: ' . $e->getMessage()]));
    exit;
}

if (empty($skuRows)) {
    $stats['duration_ms'] = round((microtime(true) - $startTime) * 1000);
    echo json_encode(array_merge($stats, ['message' => 'Todo el catálogo ya tiene caché reciente.']));
    exit;
}

// ── Inicializar Drive ─────────────────────────────────────────────────────────
require_once __DIR__ . '/../services/GoogleDriveService.php';
$drive = new GoogleDriveService();
$token = $drive->getValidToken($db);

if (!$token) {
    echo json_encode(array_merge($stats, ['error' => 'Drive no conectado. Autoriza en Admin → Google Drive.']));
    exit;
}

// ── Pre-cachear cada SKU ──────────────────────────────────────────────────────
foreach ($skuRows as $sku) {
    $stats['processed']++;

    try {
        // Misma lógica que /api/media y /api/covers/batch: busca (padre↔hijo),
        // hace públicos, cachea (incluido "sin foto") y auto-asigna portada.
        $result = fetchAndCacheMediaForSku($db, $drive, $folderId, $sku);
        $stats[$result['failed'] ? 'errors' : 'cached_new']++;

    } catch (\Exception $e) {
        $stats['errors']++;
        // Continuar con el siguiente SKU aunque este falle
    }
}

$stats['duration_ms'] = round((microtime(true) - $startTime) * 1000);
$stats['skipped']     = $batchSize - $stats['processed'];

echo json_encode($stats, JSON_PRETTY_PRINT);
exit;
