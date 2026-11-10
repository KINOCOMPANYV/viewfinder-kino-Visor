<?php
/**
 * Novedades (GET /novedades?dias=7|30|90, etapa H): fotos y videos que el sync vio por primera vez en ese periodo
 * (drive_index.first_seen_at; volver a sincronizar no los hace "nuevos"). Solo visibles. Cada foto se puede marcar
 * para Mi selección (misma barra; "Marcar todas (esta vista)" suma todas las novedades a la selección).
 */
$db = getDB();
$periods = [7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 3 meses'];
$days = (int) ($_GET['dias'] ?? 7);
if (!isset($periods[$days])) $days = 7;
$limit = 300;

$files = [];
$total = 0;
try {
    $where = "d.is_folder = 0 AND d.is_hidden = 0 AND d.first_seen_at >= NOW() - INTERVAL $days DAY
              AND (d.mime_type LIKE 'image/%' OR d.mime_type LIKE 'video/%')";
    $total = (int) $db->query("SELECT COUNT(*) FROM drive_index d WHERE $where")->fetchColumn();
    $files = $db->query(
        "SELECT d.file_id, d.name, d.mime_type, d.matched_sku, d.first_seen_at, f.name AS folder
         FROM drive_index d LEFT JOIN drive_index f ON f.file_id = d.parent_id
         WHERE $where ORDER BY d.first_seen_at DESC, d.name ASC LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) { /* migración 026 pendiente */ }
$fmtDay = function (string $d): string {
    $t = strtotime($d);
    if (date('Y-m-d', $t) === date('Y-m-d')) return 'Hoy';
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Ayer';
    return date('d/m', $t);
};
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Novedades · Viewfinder</title>
    <style>body{background:#0a0a0f;color:#e8e8f0}img{max-width:100%;height:auto}</style>
    <link rel="preconnect" href="https://lh3.googleusercontent.com">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
            <a href="/mi-seleccion" class="btn btn-sm btn-secondary hdr-hide-m" style="font-size:0.8rem; padding:0.4rem 0.8rem;">📌 Mi selección</a>
        </div>
    </header>

    <section class="container nw-main">
        <h1 class="nw-title">🆕 Novedades</h1>
        <p class="nw-help">Fotos y videos que llegaron a Drive en este periodo (los que solo se volvieron a sincronizar no cuentan).
            Márcalas para sumarlas a tu selección.</p>
        <nav class="pb-tabs">
            <?php foreach ($periods as $d => $label): ?>
                <a href="/novedades?dias=<?= $d ?>" class="<?= $d === $days ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <p class="nw-count"><?= number_format($total) ?> archivo<?= $total === 1 ? '' : 's' ?> nuevo<?= $total === 1 ? '' : 's' ?><?= $total > $limit ? " · se muestran los $limit más recientes" : '' ?></p>

        <?php if (!$files): ?>
            <p class="nw-empty">No llegó nada nuevo en este periodo.</p>
        <?php else: ?>
            <div class="parent-grid">
                <?php foreach ($files as $idx => $f):
                    $isVideo = str_starts_with($f['mime_type'], 'video/');
                    $code = $f['matched_sku'] ?: pathinfo($f['name'], PATHINFO_FILENAME);
                    $href = $f['matched_sku'] ? '/producto/' . rawurlencode($f['matched_sku']) : '#';
                    $thumb = lh3Url($f['file_id'], 400);
                ?>
                    <div class="parent-card search-selectable-card">
                        <a href="<?= e($href) ?>" style="display:block;color:inherit;text-decoration:none;">
                            <div class="card-image" style="position:relative;">
                                <span class="nw-badge"><?= $isVideo ? '🎬 ' : '' ?><?= e($fmtDay($f['first_seen_at'])) ?></span>
                                <img src="<?= e($thumb) ?>" alt="<?= e($code) ?>" <?= cardImgAttrs($thumb, $idx < 6) ?> class="img-fade-in"
                                     onload="this.classList.add('loaded')" onerror="this.outerHTML='<div class=\'cover-placeholder\'>📷</div>'">
                            </div>
                            <div class="card-body">
                                <div class="card-sku"><?= e(cleanSkuDisplay($code)) ?></div>
                                <?php if ($f['folder']): ?><div class="card-name nw-folder">📁 <?= e($f['folder']) ?></div><?php endif; ?>
                            </div>
                        </a>
                        <?= mediaActionButtons($f['file_id'], $f['name'], $code, $href) ?>
                        <?php if (!$isVideo): ?>
                            <label class="search-check-footer" style="border-top: 1px solid var(--color-border); margin-top: auto;">
                                <input type="checkbox" class="search-card-check" data-sku="<?= e($code) ?>" data-file-id="<?= e($f['file_id']) ?>" data-is-video="0">
                                <span class="search-check-icon">✓</span>
                                <span class="search-check-text">Seleccionar</span>
                            </label>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <script src="/assets/js/media_actions.js?v=<?= APP_VERSION ?>"></script>
    <script src="/assets/js/wa_batch.js?v=<?= APP_VERSION ?>"></script>
    <?php $mnavActive = 'inicio'; include __DIR__ . '/../../templates/partials/mobile_nav.php'; ?>
</body>

</html>
