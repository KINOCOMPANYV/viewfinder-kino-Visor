<?php
/**
 * Búsqueda con resultados — muestra grid de productos.
 * Soporta búsqueda múltiple: SKUs separados por comas o saltos de línea.
 */
$q = trim($_GET['q'] ?? '');
$albumId = trim($_GET['album'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$db = getDB();

// Verificar si existe la columna album_id
$hasAlbumId = false;
try {
    $db->query("SELECT album_id FROM products LIMIT 1");
    $hasAlbumId = true;
} catch (\PDOException $e) { /* columna no existe aún */ }

// Si no existe album_id, ignorar filtro por álbum
if (!$hasAlbumId) $albumId = '';

// Detectar si es búsqueda multi-código (comas o saltos de línea)
$isMultiCode = (strpos($q, ',') !== false || strpos($q, "\n") !== false || strpos($q, "\r") !== false);
$multiCodes = [];

if ($isMultiCode && $q !== '') {
    // Parsear: separar por comas, saltos de línea, o ambos
    $raw = preg_split('/[\r\n,]+/', $q);
    foreach ($raw as $code) {
        $code = trim($code);
        if ($code !== '') {
            $multiCodes[] = $code;
        }
    }
    $multiCodes = array_unique($multiCodes);
    // Si después del parse solo queda 1, tratar como búsqueda normal
    if (count($multiCodes) <= 1) {
        $isMultiCode = false;
        $q = !empty($multiCodes) ? $multiCodes[0] : $q;
    }
}

if ($q === '' && !$isMultiCode) {
    // Sin query: mostrar todos los activos paginados
    $where = "archived = 0";
    $params = [];
    if ($albumId) {
        $where .= " AND album_id = ?";
        $params[] = $albumId;
    }
    
    $countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT sku, name, category, gender, price_suggested, cover_image_url 
         FROM products WHERE $where 
         ORDER BY name ASC"
    );
    $stmt->execute($params);
    $allMatches = $stmt->fetchAll();
} elseif ($isMultiCode) {
    // BÚSQUEDA MULTI-CÓDIGO: buscar cada SKU con LIKE para mayor flexibilidad
    // Esto permite encontrar "1971-1" aunque en la BD esté como "1971-1.JPG"
    $conditions = [];
    $params = [];
    foreach ($multiCodes as $code) {
        $codeClean = preg_replace('/\.\w{2,4}$/i', '', $code);
        $conditions[] = "sku LIKE ?";
        $params[] = $codeClean . '%';
    }
    $whereOr = implode(' OR ', $conditions);
    
    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM products 
         WHERE archived = 0 AND ($whereOr)"
    );
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT sku, name, category, gender, price_suggested, cover_image_url 
         FROM products 
         WHERE archived = 0 AND ($whereOr)
         ORDER BY sku ASC"
    );
    $stmt->execute($params);
    $allMatches = $stmt->fetchAll();
    
    // Sin paginación para multi-código
    $perPage = max($total, 1);
} else {
    // Con query simple: buscar por LIKE extendido (+ albums)
    $qCleanForSku = preg_replace('/\.\w{2,4}$/i', '', $q);
    $likeSku = "%{$qCleanForSku}%";
    $like = "%{$q}%";
    
    $whereSimple = "p.archived = 0 AND (p.sku LIKE ? OR p.name LIKE ? OR p.category LIKE ? OR a.name LIKE ?)";
    $paramsBase = [$likeSku, $like, $like, $like];
    
    if ($albumId) {
        $whereSimple .= " AND p.album_id = ?";
        $paramsBase[] = $albumId;
    }

    $countStmt = $db->prepare(
        "SELECT COUNT(p.id) FROM products p
         LEFT JOIN albums a ON p.album_id = a.drive_id
         WHERE $whereSimple"
    );
    $countStmt->execute($paramsBase);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT p.sku, p.name, p.category, p.gender, p.price_suggested, p.cover_image_url 
         FROM products p
         LEFT JOIN albums a ON p.album_id = a.drive_id
         WHERE $whereSimple
         ORDER BY 
            CASE WHEN p.sku = ? THEN 0
                 WHEN p.sku LIKE ? THEN 1
                 ELSE 2 END,
            p.name ASC"
    );
    $startsWith = "{$qCleanForSku}%";
    $finalParams = array_merge($paramsBase, [$qCleanForSku, $startsWith]);
    $stmt->execute($finalParams);
    $allMatches = $stmt->fetchAll();
}

// Fetch matched folders (Albums and Subfolders) if single query
$matchedFolders = [];
if (!$isMultiCode && $q !== '') {
    $like = "%{$q}%";
    
    // 1. Fetch top-level Albums
    $albumStmt = $db->prepare("SELECT drive_id as id, name, icon_url FROM albums WHERE is_active = 1 AND name LIKE ? ORDER BY name ASC LIMIT 20");
    $albumStmt->execute([$like]);
    $matchedAlbums = $albumStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Fetch cached Subfolders from drive_cache
    $matchedSubfolders = [];
    try {
        $subStmt = $db->prepare("SELECT file_id as id, file_name as name, thumbnail_link as icon_url FROM drive_cache WHERE mime_type = 'application/vnd.google-apps.folder' AND file_name LIKE ? ORDER BY file_name ASC LIMIT 20");
        $subStmt->execute([$like]);
        $matchedSubfolders = $subStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) { /* might not exist yet */ }
    
    // Merge and deduplicate by ID
    $folderMap = [];
    foreach ($matchedAlbums as $a) $folderMap[$a['id']] = $a;
    foreach ($matchedSubfolders as $s) $folderMap[$s['id']] = $s;
    
    $matchedFolders = array_values($folderMap);
}

// Group by family SKU using the global extractRootSku standard
$grouped = [];
foreach ($allMatches as $p) {
    $sku = trim($p['sku']);
    $skuClean = preg_replace('/\.\w{2,4}$/i', '', $sku);
    $family = extractRootSku($skuClean);
    
    if (!isset($grouped[$family])) {
        $grouped[$family] = ['parent' => $p, 'children' => []];
    } else {
        if ($p['sku'] !== $grouped[$family]['parent']['sku']) {
            $grouped[$family]['children'][] = $p;
        }
    }
}

$parentOrder = array_keys($grouped);
$totalParents = count($parentOrder);
$totalPages = max(1, ceil($totalParents / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pageRoots = array_slice($parentOrder, $offset, $perPage);

// Helper para limpiar el SKU
function cleanSkuDisplay(string $sku): string {
    return preg_replace('/\.\w{2,4}$/i', '', $sku);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>
        <?= $q ? e($q) . ' — ' : '' ?>Búsqueda · Viewfinder
    </title>
    <style>body{background:#0a0a0f;color:#e8e8f0}img{max-width:100%;height:auto}</style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://lh3.googleusercontent.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= APP_VERSION ?>">
</head>

<body>
    <header class="header">
        <div class="container header-inner">
            <a href="/" class="logo"><span class="logo-icon">VF</span> Viewfinder</a>
        </div>
    </header>

    <?php if ($albumId): 
        $albumName = $db->prepare("SELECT name FROM albums WHERE drive_id = ?");
        $albumName->execute([$albumId]);
        $albumTitle = $albumName->fetchColumn() ?: 'Álbum';
    ?>
    <div style="background:var(--color-surface); border-bottom:1px solid var(--color-border); padding: 1.5rem 0;">
        <div class="container">
            <h1 style="font-size:1.5rem; margin:0;">📂 <?= e($albumTitle) ?></h1>
            <p style="color:var(--color-text-muted); font-size:0.9rem; margin-top:0.25rem;">Mostrando productos de esta colección</p>
        </div>
    </div>
    <?php endif; ?>

    <section class="container" style="padding-top:2rem;">
        <!-- Search bar inline -->
        <div class="search-box" style="max-width:100%; margin-bottom:1.5rem;">
            <span class="search-icon">🔍</span>
            <form action="/buscar" method="GET" id="searchForm">
                <textarea name="q" id="searchInput" rows="1"
                    placeholder="Buscar por SKU o nombre..."
                    autocomplete="off"><?= e($q) ?></textarea>
                <button type="submit" class="search-btn">Buscar</button>
            </form>
            <div class="autocomplete-dropdown" id="autocomplete"></div>
        </div>

        <!-- Results count -->
        <p style="color:var(--color-text-muted); font-size:0.9rem; margin-bottom:1rem;">
            <?php if ($isMultiCode): ?>
                <?= $totalParents ?> resultado<?= $totalParents !== 1 ? 's' : '' ?> para <?= count($multiCodes) ?> código<?= count($multiCodes) !== 1 ? 's' : '' ?>
                <?php
                    // Verificar cuáles códigos no tuvieron ningún resultado (ni exacto ni hijo)
                    $allSkus = array_column($allMatches, 'sku');
                    $sinResultado = [];
                    foreach ($multiCodes as $code) {
                        $found = false;
                        foreach ($allSkus as $sku) {
                            if (stripos($sku, $code) === 0) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) $sinResultado[] = $code;
                    }
                    if (!empty($sinResultado)): ?>
                    <br><span style="color:var(--color-warning, #fbbf24); font-size:0.85rem;">Sin resultados para: <?= e(implode(', ', $sinResultado)) ?></span>
                <?php endif; ?>
            <?php elseif ($q): ?>
                <?= $totalParents ?> resultado<?= $totalParents !== 1 ? 's' : '' ?> para "<strong style="color:var(--color-text)">
                    <?= e($q) ?>
                </strong>"
            <?php else: ?>
                <?= $totalParents ?> colección(es)
            <?php endif; ?>
        </p>

        <?php if (!empty($matchedFolders)): ?>
            <!-- Matched Folders -->
            <div class="section-header-landing" style="margin-bottom:1rem; border-top: 1px solid var(--color-border); padding-top:1.5rem;">
                <h2 style="font-size:1.2rem;">📂 Carpetas encontradas</h2>
            </div>
            <div class="parent-grid" style="margin-bottom:2rem;">
                <?php foreach ($matchedFolders as $idx => $folder): 
                    $fIcon = $folder['icon_url'] ?? '';
                ?>
                    <a href="/carpeta/<?= urlencode($folder['id']) ?>" class="parent-card" style="text-decoration:none; color:inherit; display:block;">
                        <div class="card-image">
                            <?php if ($fIcon): ?>
                                <img src="<?= e($fIcon) ?>" alt="<?= e($folder['name']) ?>"
                                     loading="lazy"
                                     class="img-fade-in loaded"
                                     onerror="this.outerHTML='<div class=\'cover-placeholder\' style=\'font-size:3rem;\'>📂</div>'"
                                     style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <div class="cover-placeholder" style="font-size:3rem;">📂</div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="card-sku" style="font-size:0.7rem;">Carpeta</div>
                            <div class="card-name"><?= e($folder['name']) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($pageRoots)): ?>
            <!-- WhatsApp toolbar -->
            <div class="search-wa-toolbar" id="searchWaBar">
                <label class="wa-toolbar-select">
                    <input type="checkbox" id="searchSelectAll"> 
                    <span>Seleccionar todas</span>
                </label>
                <span id="searchSelectedCount" class="wa-toolbar-count">0</span>
                <button class="wa-toolbar-send" id="btnSearchWaSend" disabled>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    Enviar por WhatsApp
                </button>
            </div>

            <div class="parent-grid">
                <?php $cardIndex = 0; ?>
                <?php foreach ($pageRoots as $root):
                    $group = $grouped[$root];
                    $p = $group['parent'];
                    $children = $group['children'];
                    $childCount = count($children);
                    $coverUrl = $p['cover_image_url'] ?? '';
                    $isVideo = str_starts_with($coverUrl, '[VIDEO]');
                    if ($isVideo) $coverUrl = substr($coverUrl, 7);
                ?>
                    <div class="parent-card search-selectable-card">
                        <div style="text-decoration:none; color:inherit; display:block;">
                            <a href="/producto/<?= rawurlencode($p['sku']) ?>" class="dynamic-card-link" style="display:block;">
                                <div class="card-image" data-sku="<?= e($p['sku']) ?>"
                                     <?php if ($coverUrl): ?>
                                        data-cover="<?= e($coverUrl) ?>"
                                        data-video="<?= $isVideo ? '1' : '0' ?>"
                                     <?php endif; ?>
                                >
                                    <?php $loadMode = ($cardIndex < 3 && $page === 1) ? 'eager' : 'lazy'; ?>
                                    <?php if ($coverUrl): ?>
                                        <img src="<?= e($coverUrl) ?>" alt="<?= e($p['name']) ?>"
                                             loading="<?= $loadMode ?>" class="img-fade-in"
                                             onload="this.classList.add('loaded')"
                                             onerror="this.outerHTML='<div class=\'cover-placeholder\'>📷</div>'">
                                    <?php else: ?>
                                        <div class="cover-placeholder">📷</div>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <div class="card-body">
                                <a href="/producto/<?= rawurlencode($p['sku']) ?>" class="dynamic-card-link" style="text-decoration:none; color:inherit; display:block;">
                                    <div class="card-sku dynamic-card-sku"><?= e(cleanSkuDisplay($p['sku'])) ?></div>
                                    <div class="card-name"><?= e($p['name']) ?></div>
                                </a>
                                <?php if ($p['category']): ?>
                                    <div class="card-meta"><span><?= e($p['category']) ?></span></div>
                                <?php endif; ?>

                                <!-- Children thumbnails -->
                                <?php if ($childCount > 0): ?>
                                    <div class="children-scroll-wrapper">
                                        <button class="children-scroll-btn scroll-left hidden" onclick="scrollChildren(this,-1)" type="button">◀</button>
                                        <div class="children-row">
                                            <?php
                                            foreach ($children as $child):
                                                $childCover = $child['cover_image_url'] ?? '';
                                                $childIsVideo = str_starts_with($childCover, '[VIDEO]');
                                                if ($childIsVideo) $childCover = substr($childCover, 7);
                                            ?>
                                                <div class="child-thumb" style="cursor:pointer;" 
                                                    title="<?= e(cleanSkuDisplay($child['sku'])) ?>"
                                                    data-sku="<?= e($child['sku']) ?>"
                                                    onclick="previewVariant(this, '<?= rawurlencode($child['sku']) ?>', '<?= e(cleanSkuDisplay($child['sku'])) ?>')">
                                                    <?php if ($childCover): ?>
                                                        <img src="<?= e($childCover) ?>" alt="<?= e($child['sku']) ?>" loading="lazy" onerror="this.outerHTML='<span class=\'child-placeholder\'>📷</span>'">
                                                    <?php else: ?>
                                                        <span class="child-placeholder">📷</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button class="children-scroll-btn scroll-right" onclick="scrollChildren(this,1)" type="button">▶</button>
                                    </div>
                                    <div class="children-info">
                                        <span class="children-label"><?= $childCount ?> variante<?= $childCount > 1 ? 's' : '' ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Checkbox FUERA de la imagen -->
                        <label class="search-check-footer" style="border-top: 1px solid var(--color-border); margin-top: auto; border-radius: 0 0 var(--radius) var(--radius);">
                            <input type="checkbox" class="search-card-check" 
                                   data-index="<?= $cardIndex ?>" 
                                   data-sku="<?= e($p['sku']) ?>"
                                   data-name="<?= e(addslashes($p['name'])) ?>"
                                   data-image="<?= e($coverUrl) ?>"
                                   data-is-video="<?= $isVideo ? '1' : '0' ?>">
                            <span class="search-check-icon">✓</span>
                            <span class="search-check-text">Seleccionar</span>
                        </label>
                    </div>
                <?php $cardIndex++; endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php
                        $params = ['q' => $q, 'page' => $i];
                        if ($albumId) $params['album'] = $albumId;
                        $url = '/buscar?' . http_build_query($params);
                        ?>
                        <?php if ($i === $page): ?>
                            <span class="current">
                                <?= $i ?>
                            </span>
                        <?php else: ?>
                            <a href="<?= $url ?>">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php elseif(empty($matchedFolders)): ?>
            <div class="no-results fade-in">
                <h3>Sin resultados</h3>
                <p>No encontramos productos con "
                    <?= e($q) ?>". Intenta con otro SKU o nombre.
                </p>
            </div>
        <?php endif; ?>
    </section>

    <footer class="footer">
        <div class="container">
            <p>Esta es una app desarrollada por <strong>K GENIUS</strong> · Más información
                <a href="https://wa.me/573146116450" target="_blank" rel="noopener" style="color:var(--color-gold);text-decoration:underline;">escríbanos</a>
            </p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // No Drive API calls on search — speed is priority
            // All covers come from DB (cover_image_url) or show placeholder

            // --- WhatsApp search checkboxes ---
            const waBar = document.getElementById('searchWaBar');
            const selectAllCb = document.getElementById('searchSelectAll');
            const selectedCountEl = document.getElementById('searchSelectedCount');
            const btnSend = document.getElementById('btnSearchWaSend');
            const checks = document.querySelectorAll('.search-card-check');

            function updateCount() {
                const selected = document.querySelectorAll('.search-card-check:checked').length;
                selectedCountEl.textContent = selected;
                btnSend.disabled = selected === 0;
                selectAllCb.checked = selected === checks.length && checks.length > 0;
                selectAllCb.indeterminate = selected > 0 && selected < checks.length;
            }

            checks.forEach(cb => cb.addEventListener('change', updateCount));
            selectAllCb.addEventListener('change', () => {
                checks.forEach(cb => cb.checked = selectAllCb.checked);
                updateCount();
            });

            btnSend.addEventListener('click', async () => {
                const selected = [];
                document.querySelectorAll('.search-card-check:checked').forEach(cb => {
                    selected.push({ sku: cb.dataset.sku, name: cb.dataset.name, image: cb.dataset.image, isVideo: cb.dataset.isVideo === '1' });
                });
                if (selected.length === 0) return;
                if (selected.length > 20) {
                    alert('\u26A0\uFE0F M\u00E1ximo 20 productos a la vez.\n\nDeselecciona algunos y haz otro env\u00EDo.');
                    return;
                }

                const images = selected.filter(s => !s.isVideo);
                const videos = selected.filter(s => s.isVideo);

                // Avisar si hay videos
                if (videos.length > 0) {
                    const videoSkus = videos.map(v => v.sku).join(', ');
                    if (images.length > 0) {
                        alert('\uD83C\uDFAC Los siguientes productos son videos:\n' + videoSkus + '\n\nLos videos no se pueden enviar directamente por WhatsApp.\nDescargalos primero a tu dispositivo desde la p\u00E1gina del producto y comp\u00E1rtelos manualmente.\n\nSe enviar\u00E1n solo las ' + images.length + ' im\u00E1gen(es).');
                    } else {
                        alert('\uD83C\uDFAC Los productos seleccionados son videos:\n' + videoSkus + '\n\nLos videos no se pueden enviar directamente por WhatsApp.\nDescargalos primero a tu dispositivo desde la p\u00E1gina del producto y comp\u00E1rtelos manualmente.');
                        return;
                    }
                }

                if (images.length === 0) return;

                // Web Share API (mobile)
                const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                if (isMobile && navigator.canShare && navigator.share) {
                    btnSend.disabled = true;
                    btnSend.innerHTML = '<div class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></div> Preparando im\u00E1genes...';
                    try {
                        const files = (await Promise.all(images.map(async (item, i) => {
                            try {
                                const r = await fetch(item.image, { mode: 'cors' });
                                const b = await r.blob();
                                return new File([b], `imagen_${i + 1}.jpg`, { type: b.type || 'image/jpeg' });
                            } catch { return null; }
                        }))).filter(Boolean);
                        if (files.length > 0 && navigator.canShare({ files })) {
                            await navigator.share({ files });
                            resetBtn();
                            return;
                        }
                    } catch (e) { if (e.name === 'AbortError') { resetBtn(); return; } }
                    resetBtn();
                }

                // Fallback desktop: links
                let text = '\uD83D\uDCE6 *Cat\u00E1logo - ' + images.length + ' producto(s)*\n\n';
                images.forEach((item, i) => {
                    text += (i+1) + '. *' + item.sku + '* - ' + item.name + '\n';
                    if (item.image) text += '\uD83D\uDDBC\uFE0F ' + item.image + '\n';
                    text += '\uD83D\uDD17 ' + location.origin + '/producto/' + item.sku + '\n\n';
                });
                window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
            });

            function resetBtn() {
                btnSend.disabled = false;
                btnSend.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> Enviar por WhatsApp';
            }
        });
    </script>
    <script>
        // Lazy-load covers from Drive for products without cover_image_url
        document.addEventListener('DOMContentLoaded', () => {
            // Collect SKUs that need covers (main card images)
            const skuElements = {};
            document.querySelectorAll('.cover-placeholder').forEach(ph => {
                const cardImage = ph.closest('.card-image');
                if (cardImage && cardImage.dataset.sku) {
                    const sku = cardImage.dataset.sku;
                    if (!skuElements[sku]) skuElements[sku] = [];
                    skuElements[sku].push({ el: cardImage, type: 'main' });
                }
            });

            // Collect SKUs from child thumbnails with placeholders
            document.querySelectorAll('.child-thumb').forEach(thumb => {
                const ph = thumb.querySelector('.child-placeholder');
                if (ph && thumb.dataset.sku) {
                    const sku = thumb.dataset.sku;
                    if (!skuElements[sku]) skuElements[sku] = [];
                    skuElements[sku].push({ el: thumb, type: 'child' });
                }
            });

            const allSkus = Object.keys(skuElements);
            if (allSkus.length === 0) return;

            // Fetch in batches of 50
            const batchSize = 50;
            for (let i = 0; i < allSkus.length; i += batchSize) {
                const batch = allSkus.slice(i, i + batchSize);
                fetch('/api/covers/batch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ skus: batch })
                })
                .then(r => r.json())
                .then(data => {
                    const covers = data.covers || {};
                    Object.entries(covers).forEach(([sku, cover]) => {
                        if (!cover || !cover.url) return;
                        const entries = skuElements[sku] || [];
                        entries.forEach(entry => {
                            if (entry.type === 'main') {
                                const placeholder = entry.el.querySelector('.cover-placeholder');
                                if (placeholder) {
                                    const img = document.createElement('img');
                                    img.src = cover.url;
                                    img.alt = sku;
                                    img.loading = 'lazy';
                                    img.className = 'img-fade-in';
                                    img.onload = () => img.classList.add('loaded');
                                    img.onerror = () => { img.outerHTML = '<div class="cover-placeholder">📷</div>'; };
                                    placeholder.replaceWith(img);
                                }
                            } else {
                                // child-thumb: replace placeholder with small image
                                const placeholder = entry.el.querySelector('.child-placeholder');
                                if (placeholder) {
                                    const img = document.createElement('img');
                                    img.src = cover.url;
                                    img.alt = sku;
                                    img.loading = 'lazy';
                                    img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                                    img.onerror = () => { img.outerHTML = '<span class="child-placeholder">📷</span>'; };
                                    placeholder.replaceWith(img);
                                }
                            }
                        });
                    });
                })
                .catch(() => {});
            }
        });
    </script>

    <script>
        function previewVariant(thumbEl, skuEnc, skuLabel) {
            const card = thumbEl.closest('.parent-card');
            if (!card) return;

            const thumbImg = thumbEl.querySelector('img');
            const newSrc = thumbImg ? thumbImg.src : null;
            if (!newSrc) return;

            const mainImgContainer = card.querySelector('.card-image');
            const mainImg = mainImgContainer.querySelector('img');
            const hiresUrl = newSrc.replace(/=s\d+/, '=s600'); 
            
            if (mainImg) {
                mainImg.src = hiresUrl;
            } else {
                const ph = mainImgContainer.querySelector('.cover-placeholder');
                if (ph) {
                    ph.outerHTML = `<img src="${hiresUrl}" class="img-fade-in loaded" style="width:100%;height:100%;object-fit:cover;">`;
                }
            }

            // SKU label update (visual only)

            const skuText = card.querySelector('.dynamic-card-sku');
            if (skuText) skuText.textContent = skuLabel;

            card.querySelectorAll('.child-thumb').forEach(t => t.style.boxShadow = 'none');
            thumbEl.style.boxShadow = 'var(--glow-accent)';
        }

        function scrollChildren(btn, direction) {
            const wrapper = btn.closest('.children-scroll-wrapper');
            const row = wrapper.querySelector('.children-row');
            row.scrollBy({ left: direction * 120, behavior: 'smooth' });
            setTimeout(() => {
                const leftBtn = wrapper.querySelector('.scroll-left');
                const rightBtn = wrapper.querySelector('.scroll-right');
                if (leftBtn) leftBtn.classList.toggle('hidden', row.scrollLeft <= 0);
                if (rightBtn) rightBtn.classList.toggle('hidden', row.scrollLeft + row.clientWidth >= row.scrollWidth - 2);
            }, 350);
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.children-scroll-wrapper').forEach(wrapper => {
                const row = wrapper.querySelector('.children-row');
                const leftBtn = wrapper.querySelector('.scroll-left');
                const rightBtn = wrapper.querySelector('.scroll-right');
                if (!row) return;
                const needsScroll = row.scrollWidth > row.clientWidth + 2;
                if (leftBtn) leftBtn.classList.add('hidden');
                if (rightBtn) rightBtn.classList.toggle('hidden', !needsScroll);
            });
        });
    </script>
    <script src="/assets/js/search.js?v=<?= APP_VERSION ?>"></script>
    <?php include __DIR__ . '/../../templates/partials/loading_overlay.php'; ?>
</body>

</html>