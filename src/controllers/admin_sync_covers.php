<?php
/**
 * Admin Sync Covers — Auto-asignar portadas y álbumes a productos.
 * POST /admin/media/sync-covers
 *
 * ESTRATEGIA DIRECTA (v2):
 *   1) Obtener todos los álbumes (carpetas de primer nivel en Drive)
 *   2) Para cada álbum, listar TODOS sus archivos (incluida recursión en subcarpetas)
 *   3) Matchear archivos con productos por SKU
 *   4) Asignar cover_image_url Y album_id directamente
 *
 * Esta estrategia es 100% confiable porque no depende del campo "parents"
 * de la API de Google Drive, sino que recorre cada carpeta conocida.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

header('Content-Type: application/json');
set_time_limit(300);

$db = getDB();

$isCron = false;
$cronToken = env('CRON_SECRET', 'kino-cron-1234');
if (isset($_GET['token']) && hash_equals($cronToken, $_GET['token'])) {
    $isCron = true;
} else {
    session_start(); // Asegurar que la sesión exista para la validación normal
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido o acceso denegado.']);
        exit;
    }
}

$drive = new GoogleDriveService();
$rootFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '');

$token = $drive->getValidToken($db);
if (empty($token)) {
    echo json_encode(['ok' => false, 'error' => 'No hay conexión a Google Drive.']);
    exit;
}

if (empty($rootFolderId)) {
    echo json_encode(['ok' => false, 'error' => 'GOOGLE_DRIVE_FOLDER_ID no configurado.']);
    exit;
}

// ============================================================
// 1) Cargar TODOS los productos sin portada o sin album_id
// ============================================================
$products = $db->query(
    "SELECT id, sku, cover_image_url, album_id FROM products WHERE (cover_image_url IS NULL OR cover_image_url = '' OR album_id IS NULL) AND archived = 0"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    echo json_encode([
        'ok' => true,
        'assigned' => 0,
        'remaining' => 0,
        'message' => '✅ Todos los productos ya tienen portada y álbum asignado.'
    ]);
    exit;
}

// Construir índice de productos por SKU (limpio, sin extensión y sin prefijo)
$productIndex = [];
foreach ($products as $prod) {
    $sku = trim($prod['sku']);
    // Indexar el SKU original (limpio sin extensión)
    $skuClean = preg_replace('/\.\w{2,4}$/i', '', $sku);
    $skuLower = strtolower($skuClean);
    
    if (!isset($productIndex[$skuLower])) {
        $productIndex[$skuLower] = $prod;
    }
    
    // También indexar con extensión por si acaso
    $skuWithExt = strtolower($sku);
    if ($skuWithExt !== $skuLower && !isset($productIndex[$skuWithExt])) {
        $productIndex[$skuWithExt] = $prod;
    }
    
    // También indexar el SKU raíz (sin sufijos de color/variante)
    $rootSku = strtolower(extractRootSku($skuClean));
    if ($rootSku !== $skuLower && !isset($productIndex[$rootSku])) {
        $productIndex[$rootSku] = $prod;
    }
    
    // También indexar sin prefijo de marca (ej: KNM-8845 → 8845)
    $noPrefijo = strtolower(extractSkuWithoutPrefix($skuClean));
    if ($noPrefijo !== $skuLower && !isset($productIndex[$noPrefijo])) {
        $productIndex[$noPrefijo] = $prod;
    }
    
    // También indexar sin espacios
    $noSpace = str_replace(' ', '', $skuLower);
    if ($noSpace !== $skuLower && !isset($productIndex[$noSpace])) {
        $productIndex[$noSpace] = $prod;
    }
}

// ============================================================
// 2) Obtener todos los álbumes (carpetas de primer nivel)
// ============================================================
$albums = $db->query("SELECT drive_id, name FROM albums WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

if (empty($albums)) {
    // Si no hay álbumes, sincronizar desde Drive primero
    $rootResult = $drive->listFiles($rootFolderId);
    foreach ($rootResult['files'] as $item) {
        if (($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
            $check = $db->prepare("SELECT drive_id FROM albums WHERE drive_id = ?");
            $check->execute([$item['id']]);
            if (!$check->fetch()) {
                $ins = $db->prepare("INSERT INTO albums (drive_id, name) VALUES (?, ?)");
                $ins->execute([$item['id'], $item['name']]);
            }
        }
    }
    $albums = $db->query("SELECT drive_id, name FROM albums WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
}

// La raíz no se agrega como álbum para evitar duplicar el procesamiento recursivo de todas las subcarpetas.

// ============================================================
// 3) Buscar archivos sueltos en la carpeta raíz
// ============================================================
$rootFilesOnly = [];
try {
    $rootResult = $drive->listFiles($rootFolderId);
    $items = $rootResult['files'] ?? [];
    foreach ($items as $item) {
        $mime = $item['mimeType'] ?? '';
        if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            $rootFilesOnly[] = $item;
        }
    }
} catch (Exception $e) {}

if (!empty($rootFilesOnly)) {
    // Simulamos que la raíz es un álbum más, pero pasamos los archivos ya listados
    // para no hacer recursión en subcarpetas de la raíz (que ya son los demás álbumes).
    $albums[] = [
        'drive_id' => $rootFolderId,
        'name' => 'Raíz (Archivos sueltos)',
        '_files' => $rootFilesOnly
    ];
}

// ============================================================
// 4) Para cada álbum, listar archivos y matchear con productos
// ============================================================
$coverKeywords = ['principal', 'cover', 'portada', 'front', 'frente'];
$numericPriority = ['01', '_1', '-1', 'f1'];

function scoreCover(array $file, array $coverKeywords, array $numericPriority): int
{
    $name = strtolower($file['name'] ?? '');
    $score = 0;
    foreach ($coverKeywords as $kw) {
        if (str_contains($name, $kw)) $score += 10;
    }
    foreach ($numericPriority as $np) {
        if (str_contains($name, $np)) $score += 5;
    }
    return $score;
}

/**
 * Matchea un archivo con un producto del índice.
 * Retorna el SKU del producto que matchea, o null.
 */
function matchFileToProduct(array $file, array &$productIndex): ?string
{
    $baseName = strtolower(pathinfo($file['name'] ?? '', PATHINFO_FILENAME));
    $noSpaceName = str_replace(' ', '', $baseName);
    $noPrefixName = strtolower(extractSkuWithoutPrefix($baseName));
    
    $variations = array_unique([$baseName, $noSpaceName, $noPrefixName]);

    // Match exacto
    foreach ($variations as $v) {
        if (isset($productIndex[$v])) {
            return $v;
        }
    }
    
    // Match por prefijo: buscar si algún SKU del índice es prefijo del nombre del archivo
    foreach ($variations as $v) {
        foreach ($productIndex as $skuKey => $prod) {
            if (strpos($v, $skuKey) === 0) {
                // Verificar que el siguiente carácter no sea un dígito (evitar 123-1 -> 123-10)
                $nextPos = strlen($skuKey);
                if ($nextPos >= strlen($v)) {
                    return $skuKey; // Match exacto con nombre completo
                }
                $nextChar = $v[$nextPos];
                if (!ctype_digit($nextChar)) {
                    return $skuKey;
                }
            }
        }
    }
    
    return null;
}

$assigned = 0;
$assignedVideos = 0;
$albumsProcessed = 0;
$totalDriveFiles = 0;
$errors = [];
$unmappedFiles = []; // Track files that matched no products
$fileIdsToPublish = [];
$updatesQueue = []; // id => ['url' => ..., 'album_id' => ...]
$productUpdated = []; // Track which products have been updated (avoid duplicates)

foreach ($albums as $album) {
    try {
        if (isset($album['_files'])) {
            $albumFiles = $album['_files'];
        } else {
            // Listar TODOS los archivos dentro de este álbum (incluye subcarpetas)
            $albumFiles = listAlbumMediaRecursive($drive, $album['drive_id']);
        }
        $albumsProcessed++;
        $totalDriveFiles += count($albumFiles);
        
        foreach ($albumFiles as $file) {
            $isMedia = str_starts_with($file['mimeType'] ?? '', 'image/') || str_starts_with($file['mimeType'] ?? '', 'video/');
            if (!$isMedia) continue;
            
            $matchedSku = matchFileToProduct($file, $productIndex);
            if (!$matchedSku) {
                $unmappedFiles[] = $file['name'];
                continue;
            }
            
            $prod = $productIndex[$matchedSku];
            $prodId = $prod['id'];
            
            // Si ya se asignó este producto, revisar si esta es mejor portada
            if (isset($productUpdated[$prodId])) continue;
            
            $isImage = str_starts_with($file['mimeType'] ?? '', 'image/');
            $isVideo = str_starts_with($file['mimeType'] ?? '', 'video/');
            
            // Solo asignar portada si el producto no tiene una
            $needsCover = empty($prod['cover_image_url']);
            $needsAlbum = empty($prod['album_id']);
            
            if (!$needsCover && !$needsAlbum) continue;
            
            $coverUrl = null;
            if ($needsCover) {
                if ($isVideo) {
                    $coverUrl = "[VIDEO]https://lh3.googleusercontent.com/d/{$file['id']}";
                } else {
                    $coverUrl = "https://lh3.googleusercontent.com/d/{$file['id']}=s400";
                }
                $fileIdsToPublish[] = $file['id'];
            }
            
            $updatesQueue[$prodId] = [
                'url' => $coverUrl,
                'album_id' => $album['drive_id'],
                'id' => $prodId
            ];
            $productUpdated[$prodId] = true;
            $assigned++;
            if ($isVideo && $needsCover) $assignedVideos++;
        }
    } catch (Exception $e) {
        $errors[] = "Álbum {$album['name']}: {$e->getMessage()}";
    }
}

/**
 * Lista todos los archivos media recursivamente dentro de una carpeta.
 */
function listAlbumMediaRecursive(GoogleDriveService $drive, string $folderId, int $depth = 0, int $maxDepth = 3): array
{
    if ($depth >= $maxDepth) return [];
    
    $allMedia = [];
    $result = $drive->listFiles($folderId);
    $items = $result['files'] ?? [];
    
    foreach ($items as $item) {
        $mime = $item['mimeType'] ?? '';
        if ($mime === 'application/vnd.google-apps.folder') {
            $subFiles = listAlbumMediaRecursive($drive, $item['id'], $depth + 1, $maxDepth);
            $allMedia = array_merge($allMedia, $subFiles);
        } elseif (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            $allMedia[] = $item;
        }
    }
    
    return $allMedia;
}

// ============================================================
// 4) Hacer públicos en batch
// ============================================================
if (!empty($fileIdsToPublish)) {
    $drive->makePublicBatch($fileIdsToPublish);
}

// ============================================================
// 5) Actualizar DB en lote
// ============================================================
$updateStmt = $db->prepare(
    "UPDATE products SET cover_image_url = COALESCE(?, cover_image_url), album_id = COALESCE(?, album_id) WHERE id = ?"
);

foreach ($updatesQueue as $upd) {
    $updateStmt->execute([$upd['url'], $upd['album_id'], $upd['id']]);
}

// Invalidar cache
unset($_SESSION['media_linked_count_cache'], $_SESSION['media_linked_count_time']);

$remaining = count($products) - $assigned;

echo json_encode([
    'ok' => true,
    'assigned' => $assigned,
    'assigned_images' => $assigned - $assignedVideos,
    'assigned_videos' => $assignedVideos,
    'total' => count($products),
    'remaining' => $remaining,
    'albums_processed' => $albumsProcessed,
    'drive_files_indexed' => $totalDriveFiles,
    'strategy' => 'direct-album-scan',
    'errors' => $errors,
    'unmapped_files' => $unmappedFiles,
    'message' => $assigned > 0
        ? "⭐ {$assigned} producto(s) actualizado(s). "
          . "Escaneamos {$albumsProcessed} álbumes con {$totalDriveFiles} archivos en Drive. "
          . ($remaining > 0 ? "Quedan {$remaining} sin asignar." : '¡Todos los productos tienen portada y álbum!')
        : "No se encontraron coincidencias para " . count($products) . " productos. "
          . "Escaneamos {$albumsProcessed} álbumes con {$totalDriveFiles} archivos. "
          . "Verifica que los archivos contengan el código del producto (SKU) en su nombre."
]);
