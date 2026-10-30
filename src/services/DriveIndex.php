<?php
/**
 * Índice local de Drive — copia en MySQL (tabla drive_index) del árbol de la
 * carpeta raíz GOOGLE_DRIVE_FOLDER_ID: archivos y carpetas con su álbum (marca).
 *
 * - sync(): recorre el árbol con pocas llamadas (varias carpetas por consulta),
 *   guarda/actualiza filas, borra lo que ya no existe y hace públicos los archivos
 *   nuevos. Se ejecuta desde admin "Sincronizar portadas" y /api/cron/sync-covers.
 * - filesForSku(): las fotos/videos de un SKU leyendo SOLO la BD (lo usan las
 *   páginas públicas para no llamar a Drive en cada visita).
 */
require_once __DIR__ . '/GoogleDriveService.php';

class DriveIndex
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const PARENTS_PER_QUERY = 40; // carpetas por consulta "'a' in parents or ..."
    private const MAX_DEPTH = 20; // niveles de subcarpetas (marca > HOMBRE > CUERO > 836 > ...)

    /** true si el índice ya tiene datos (se llenó al menos una vez). Memo por petición. */
    public static function isReady(PDO $db): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = (bool) $db->query("SELECT 1 FROM drive_index LIMIT 1")->fetchColumn();
            } catch (\PDOException $e) {
                $ready = false; // migración 018 aún no aplicada
            }
        }
        return $ready;
    }

    /**
     * Recorre la carpeta raíz completa y actualiza drive_index.
     * @return array{ok: bool, files: int, folders: int, removed: int, made_public: int, api_calls: int, error?: string}
     */
    public static function sync(PDO $db, GoogleDriveService $drive, string $rootFolderId): array
    {
        // Un solo sync a la vez (cron + botón admin): dos en paralelo se borrarían filas
        // mutuamente en el DELETE final.
        if (!$db->query("SELECT GET_LOCK('drive_index_sync', 0)")->fetchColumn()) {
            return ['ok' => false, 'error' => 'Ya hay una sincronización de Drive en curso. Intenta en unos minutos.'];
        }
        try {
            return self::doSync($db, $drive, $rootFolderId);
        } catch (\PDOException $e) {
            return ['ok' => false, 'error' => 'Error de base de datos al indexar Drive: ' . $e->getMessage()];
        } finally {
            $db->query("SELECT RELEASE_LOCK('drive_index_sync')");
        }
    }

    private static function doSync(PDO $db, GoogleDriveService $drive, string $rootFolderId): array
    {
        // Con microsegundos: dos syncs en el mismo segundo no deben confundirse al borrar
        $startedAt = $db->query("SELECT NOW(6)")->fetchColumn();
        $stats = ['ok' => true, 'files' => 0, 'folders' => 0, 'removed' => 0, 'made_public' => 0, 'api_calls' => 0];

        $albumOf = [$rootFolderId => null]; // carpeta → álbum (carpeta de primer nivel)
        $level = [$rootFolderId];
        $rows = [];

        for ($depth = 0; $level && $depth < self::MAX_DEPTH; $depth++) {
            $next = [];
            foreach (array_chunk($level, self::PARENTS_PER_QUERY) as $parents) {
                $stats['api_calls']++;
                $items = $drive->listChildrenOfMany($parents);
                if ($items === null) {
                    // Sin el árbol completo no se puede saber qué se borró: no tocar el índice
                    return ['ok' => false, 'error' => 'Drive no respondió al listar carpetas.'] + $stats;
                }
                $parentSet = array_flip($parents);
                foreach ($items as $item) {
                    $parentId = null;
                    foreach ($item['parents'] ?? [] as $p) {
                        if (isset($parentSet[$p])) { $parentId = $p; break; }
                    }
                    if ($parentId === null) continue;

                    $isFolder = ($item['mimeType'] ?? '') === self::FOLDER_MIME;
                    $album = $parentId === $rootFolderId
                        ? ($isFolder ? $item['id'] : null)
                        : $albumOf[$parentId];

                    if ($isFolder) {
                        if (array_key_exists($item['id'], $albumOf)) continue; // carpeta repetida (atajo/ciclo)
                        $albumOf[$item['id']] = $album;
                        $next[] = $item['id'];
                        $stats['folders']++;
                    } else {
                        $stats['files']++;
                    }

                    $rows[] = [
                        $item['id'],
                        mb_substr($item['name'] ?? '', 0, 500),
                        // A las carpetas no se les quita "extensión" ("1.POEDAGAR" no es "1")
                        mb_substr($isFolder ? ($item['name'] ?? '') : pathinfo($item['name'] ?? '', PATHINFO_FILENAME), 0, 191),
                        $item['mimeType'] ?? '',
                        $isFolder ? 1 : 0,
                        $parentId,
                        $album,
                        (int) ($item['size'] ?? 0),
                        $startedAt,
                    ];
                }
            }
            $level = $next;
        }

        // Raíz vacía o equivocada (permiso perdido, ID mal puesto): no vaciar el índice
        if (!$rows) {
            return ['ok' => false, 'error' => 'La carpeta raíz de Drive aparece vacía; no se tocó el índice. Revisa GOOGLE_DRIVE_FOLDER_ID y los permisos.'] + $stats;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $db->prepare(
                "INSERT INTO drive_index (file_id, name, name_stem, mime_type, is_folder, parent_id, album_id, size, synced_at)
                 VALUES " . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?)')) . "
                 ON DUPLICATE KEY UPDATE name = VALUES(name), name_stem = VALUES(name_stem), mime_type = VALUES(mime_type),
                    is_folder = VALUES(is_folder), parent_id = VALUES(parent_id), album_id = VALUES(album_id),
                    size = VALUES(size), synced_at = VALUES(synced_at)"
            )->execute(array_merge(...$chunk));
        }

        // Lo que no apareció en este recorrido ya no está en la carpeta raíz
        $del = $db->prepare("DELETE FROM drive_index WHERE synced_at < ?");
        $del->execute([$startedAt]);
        $stats['removed'] = $del->rowCount();

        // Hacer públicos solo los archivos que aún no lo son (las URLs lh3 lo necesitan)
        $pending = $db->query(
            "SELECT file_id FROM drive_index WHERE is_folder = 0 AND is_public = 0
               AND (mime_type LIKE 'image/%' OR mime_type LIKE 'video/%')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $madePublic = $pending ? $drive->makePublicMany($pending) : [];
        foreach (array_chunk($madePublic, 500) as $chunk) {
            $db->prepare("UPDATE drive_index SET is_public = 1 WHERE file_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")")
               ->execute($chunk);
        }
        $stats['made_public'] = count($madePublic);

        return $stats;
    }

    /**
     * Fotos/videos de un SKU desde el índice: los enlazados a su código (drive_index.matched_sku,
     * lo escribe relink()) + las genéricas de su familia ("M8363.jpg"), sin las de sus hermanos.
     * Mismo formato que la API de Drive (id, name, mimeType, size, thumbnailLink...).
     * @return array{files: array, root_sku: string}
     */
    public static function filesForSku(PDO $db, string $sku): array
    {
        $sku = self::productSku($db, $sku);
        $rootSku = getFamilyRootSku($db, $sku);
        // Fotos genéricas de la familia: "M8363.jpg" (base), no las del padre "M8363-1"
        $base = familyMediaBase($db, $rootSku);
        $byKey = self::linkedFiles($db, array_unique([$sku, $base]));
        $files = $byKey[$sku] ?? [];
        if ($base !== $sku) {
            foreach ($byKey[$base] ?? [] as $f) {
                if (isGenericMediaForSku($base, $f['name'])) $files[] = $f;
            }
        }
        return ['files' => $files, 'root_sku' => $rootSku];
    }

    /** "M-8464-2" o "m8464-2" → "M:8464-2" si ese es el código guardado; si no, el mismo texto. */
    private static function productSku(PDO $db, string $sku): string
    {
        $names = skuNameVariants($sku);
        if (!$names) return $sku;
        $stmt = $db->prepare("SELECT sku FROM products WHERE sku IN (" . implode(',', array_fill(0, count($names), '?')) . ")
                              ORDER BY sku = ? DESC, archived ASC LIMIT 1");
        $stmt->execute(array_merge($names, [$sku]));
        return $stmt->fetchColumn() ?: $sku;
    }

    /** Archivos visibles enlazados a cada código: [codigo => [archivo API, ...]]. */
    private static function linkedFiles(PDO $db, array $keys): array
    {
        $keys = array_values(array_filter($keys, 'strlen'));
        if (!$keys) return [];
        $stmt = $db->prepare(
            "SELECT file_id, name, mime_type, size, matched_sku FROM drive_index
             WHERE is_folder = 0 AND is_hidden = 0 AND matched_sku IN (" . implode(',', array_fill(0, count($keys), '?')) . ")
             ORDER BY name ASC, file_id ASC"
        );
        $stmt->execute($keys);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['matched_sku']][] = self::toApiFile($r);
        }
        return $out;
    }

    /**
     * EL ÚNICO ENLACE foto ↔ producto. Solo BD (no llama a Drive). Para cada archivo visible del
     * índice decide de qué código es (matchFileToProduct) o si es la foto genérica de una familia
     * (se llama como la base: "993.jpg"), y lo guarda en drive_index.matched_sku.
     * Después recalcula la portada de TODOS los productos:
     *   foto propia > foto de la familia > video propio > video de la familia > sin portada.
     * Se respeta la portada elegida a mano si es una de esas fotos del mismo nivel ($force = no).
     * Se quitan portadas viejas: de otra carpeta raíz, de otro código o de un archivo oculto/borrado.
     * Llamar tras cada sync de Drive, cambio de carpetas, subida/borrado y sync de la hoja.
     * @return array{ok: bool, files: int, linked: int, family: int, unlinked: string[], covers_set: int,
     *               covers_cleared: int, without_photo: int, album_of: array<string,string>}
     */
    public static function relink(PDO $db, bool $force = false): array
    {
        $stats = ['ok' => false, 'files' => 0, 'linked' => 0, 'family' => 0, 'unlinked' => [], 'drive_products' => 0,
                  'covers_set' => 0, 'covers_cleared' => 0, 'without_photo' => 0, 'album_of' => []];
        if (!self::isReady($db)) return $stats;
        $productsSql = "SELECT id, sku, parent_sku, cover_image_url, in_sheet, from_drive FROM products ORDER BY archived ASC, id ASC";
        try {
            // Todos los productos (también los ocultos): así la foto de uno desactivado no se
            // le pega a otro código más corto. Los visibles ganan si dos códigos chocan.
            $products = $db->query($productsSql)->fetchAll(PDO::FETCH_ASSOC);
            $files = $db->query(
                "SELECT file_id AS id, name, mime_type AS mimeType, album_id, parent_id FROM drive_index
                 WHERE is_folder = 0 AND is_hidden = 0 AND (mime_type LIKE 'image/%' OR mime_type LIKE 'video/%')
                 ORDER BY name ASC, file_id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return $stats; // migración 020/021/024 aún no aplicada
        }
        $stats['files'] = count($files);

        // 0) Productos "solo Drive": fotos con nombre de código que no está en la hoja
        $drive = self::syncDriveProducts($db, $products, $files);
        $stats['drive_products'] = $drive['visible'];
        if ($drive['changed']) {
            $products = $db->query($productsSql)->fetchAll(PDO::FETCH_ASSOC);
        }

        // 1) Cada archivo → código (o base de familia)
        $m = self::matchFiles($products, $files);
        $byKey = $m['by_key'];   // código => [archivo + quality]
        $linkOf = $m['link_of']; // file_id => código
        $baseOf = $m['base_of'];
        $stats['family'] = $m['family'];
        $stats['unlinked'] = array_column($m['unlinked'], 'name');
        $stats['linked'] = count($linkOf);

        // 2) Guardar el enlace (lo leen la galería, las miniaturas y las listas "sin código")
        $byLink = [];
        foreach ($linkOf as $fileId => $key) $byLink[$key][] = $fileId;
        $db->beginTransaction();
        try {
            $db->exec("UPDATE drive_index SET matched_sku = NULL WHERE matched_sku IS NOT NULL");
            foreach ($byLink as $key => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    $db->prepare("UPDATE drive_index SET matched_sku = ? WHERE file_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")")
                       ->execute(array_merge([$key], $chunk));
                }
            }
            $db->commit();
        } catch (\PDOException $e) {
            $db->rollBack();
            return $stats;
        }

        // 3) Portada de cada producto
        $setCover = $db->prepare("UPDATE products SET cover_image_url = ? WHERE id = ?");
        foreach ($products as $p) {
            $sku = $p['sku'];
            $base = $baseOf[$sku];
            $cands = []; // file_id => [nivel, calidad, puntaje, archivo]
            foreach ($byKey[$sku] ?? [] as $f) {
                $isImage = str_starts_with($f['mimeType'], 'image/');
                $cands[$f['id']] = [$isImage ? 4 : 2, $f['quality'], scoreCoverCandidate($f), $f];
                if ($f['album_id'] !== null) {
                    $best = $stats['album_of'][$sku] ?? null;
                    if ($best === null || [$f['quality'], scoreCoverCandidate($f)] > $best[0]) {
                        $stats['album_of'][$sku] = [[$f['quality'], scoreCoverCandidate($f)], $f['album_id']];
                    }
                }
            }
            if ($base !== $sku) {
                foreach ($byKey[$base] ?? [] as $f) {
                    if (isset($cands[$f['id']]) || !isGenericMediaForSku($base, $f['name'])) continue;
                    $cands[$f['id']] = [str_starts_with($f['mimeType'], 'image/') ? 3 : 1, $f['quality'], scoreCoverCandidate($f), $f];
                }
            }

            $best = null;
            foreach ($cands as $c) {
                if ($best === null || array_slice($c, 0, 3) > array_slice($best, 0, 3)) $best = $c;
            }

            $current = (string) $p['cover_image_url'];
            $currentId = preg_match('#/d/([\w-]+)#', $current, $m) ? $m[1] : null;
            if ($best === null) {
                $stats['without_photo']++;
                $newCover = null;
            } elseif (!$force && $currentId !== null && isset($cands[$currentId]) && $cands[$currentId][0] === $best[0]) {
                continue; // elegida a mano entre las del mismo nivel: se respeta
            } else {
                $file = $best[3];
                $newCover = str_starts_with($file['mimeType'], 'video/')
                    ? "[VIDEO]https://lh3.googleusercontent.com/d/{$file['id']}"
                    : lh3Url($file['id']);
            }
            if ($newCover === ($current !== '' ? $current : null)) continue;
            $setCover->execute([$newCover, $p['id']]);
            $newCover === null ? $stats['covers_cleared']++ : $stats['covers_set']++;
        }
        $stats['album_of'] = array_map(fn($a) => $a[1], $stats['album_of']);
        $stats['ok'] = true;
        return $stats;
    }

    /**
     * Enlaza archivos con códigos (regla única matchFileToProduct + fotos genéricas de familia).
     * @return array{by_key: array, link_of: array, base_of: array, unlinked: array, family: int}
     */
    private static function matchFiles(array $products, array $files): array
    {
        $productIndex = skuMatchIndex(array_column($products, 'sku'));
        $parentOf = array_column($products, 'parent_sku', 'sku');

        // Bases de familia que no son un producto ("993" para 993-1…993-4)
        $baseOf = [];
        $baseIndex = [];
        foreach ($products as $p) {
            $base = productMediaBase($p['sku'], $parentOf);
            $baseOf[$p['sku']] = $base;
            if ($base === $p['sku']) continue;
            foreach (skuNameVariants($base) as $v) {
                if (!isset($productIndex[$v])) $baseIndex[$v] ??= $base;
            }
        }

        $out = ['by_key' => [], 'link_of' => [], 'base_of' => $baseOf, 'unlinked' => [], 'family' => 0];
        foreach ($files as $f) {
            $match = matchFileToProduct($f['name'], $productIndex);
            $stem = genericMediaStem($f['name']);
            if ((!$match || $match[1] < 3) && isset($baseIndex[$stem])) {
                $match = [$baseIndex[$stem], 3]; // "993.jpg": foto de toda la familia
                $out['family']++;
            }
            if (!$match) {
                $out['unlinked'][] = $f;
                continue;
            }
            $f['quality'] = $match[1];
            $out['by_key'][$match[0]][] = $f;
            $out['link_of'][$f['id']] = $match[0];
        }
        return $out;
    }

    /**
     * Productos "solo Drive" (decisión del dueño, 2026-10-30): si una foto se llama como un código
     * (driveCodeFromName) que no está en la hoja maestra, se crea el producto (nombre = código,
     * marca = carpeta de primer nivel) con from_drive = 1 para que salga en el visor. Sin foto ya no
     * cuenta (from_drive = 0, queda oculto; nunca se borra). No cuentan fotos dentro de carpetas
     * "DESACTIVADOS". Cuando el código entra a la hoja, la hoja pone los datos.
     * @return array{changed: bool, visible: int}
     */
    private static function syncDriveProducts(PDO $db, array $products, array $files): array
    {
        $out = ['changed' => false, 'visible' => 0];
        $enabled = env('DRIVE_PRODUCTS', '1') !== '0'; // DRIVE_PRODUCTS=0 en Railway lo apaga
        // Productos reales = los de la hoja (también los que salieron de ella: siguen ocultos)
        $real = array_values(array_filter($products, fn($p) => !(int) $p['from_drive'] || (int) $p['in_sheet']));
        $unlinked = self::matchFiles($real, $files)['unlinked'];

        $folders = [];
        foreach ($db->query("SELECT file_id, name, parent_id FROM drive_index WHERE is_folder = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $folders[$r['file_id']] = $r;
        }
        $inDisabledFolder = function (string $folderId) use ($folders): bool {
            for ($i = 0; isset($folders[$folderId]) && $i < self::MAX_DEPTH; $i++) {
                if (preg_match('/desactivad|descontinuad/i', $folders[$folderId]['name'])) return true;
                $folderId = (string) $folders[$folderId]['parent_id'];
            }
            return false;
        };

        // Código de cada archivo; "X-1-A.jpg" va a "X-1" solo si "X-1" también es un código de foto
        $stems = [];
        foreach ($enabled ? $unlinked : [] as $f) {
            // Archivos de diseño (.psd) no se ven en el visor: no crean producto
            if (str_contains($f['mimeType'], 'photoshop') || $inDisabledFolder((string) $f['parent_id'])) continue;
            $code = driveCodeFromName($f['name']);
            if ($code !== null) $stems[$code] ??= $f['album_id'];
        }
        $codes = [];
        foreach ($stems as $code => $album) {
            $root = preg_replace('/[-_ ][A-Z]{1,2}$/', '', $code);
            $codes[isset($stems[$root]) ? $root : $code] ??= $album;
        }

        $brandOf = [];
        foreach ($db->query("SELECT drive_id, name FROM albums")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $brandOf[$a['drive_id']] = mb_substr(trim(preg_replace('/^\d+\s*\.\s*/', '', $a['name'])), 0, 100);
        }

        $current = [];
        $existing = [];
        foreach ($products as $p) {
            if ((int) $p['from_drive']) $current[$p['sku']] = true;
            $existing[strtolower($p['sku'])] = true;
        }
        $insert = $db->prepare(
            "INSERT INTO products (sku, name, brand, category, from_drive, in_sheet, archived) VALUES (?, ?, ?, ?, 1, 0, 0)"
        );
        $mark = $db->prepare("UPDATE products SET from_drive = 1 WHERE sku = ?");
        foreach ($codes as $code => $album) {
            $code = (string) $code;
            if (!isset($current[$code])) {
                if (isset($existing[strtolower($code)])) {
                    $mark->execute([$code]);
                } else {
                    $brand = $brandOf[$album] ?? '';
                    $insert->execute([$code, $code, $brand, $brand]);
                }
                $out['changed'] = true;
            }
            unset($current[$code]);
        }
        // Ya no tienen foto (o entraron a la hoja): dejan de ser "solo Drive"
        foreach (array_chunk(array_keys($current), 500) as $chunk) {
            $db->prepare("UPDATE products SET from_drive = 0 WHERE sku IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")")
               ->execute($chunk);
            $out['changed'] = true;
        }
        if ($out['changed']) {
            refreshProductVisibility($db);
            recomputeParentSkus($db); // las variantes nuevas forman familia (-1 = padre)
        }
        $out['visible'] = count($codes);
        return $out;
    }

    /**
     * Fila del índice → formato de archivo de la API de Drive que espera el front.
     * thumbnailLink apunta a lh3 por ID (estable; el JS cambia el =sNNN).
     */
    private static function toApiFile(array $r): array
    {
        $id = $r['file_id'];
        return [
            'id' => $id,
            'name' => $r['name'],
            'mimeType' => $r['mime_type'],
            'size' => (string) $r['size'],
            'thumbnailLink' => lh3Url($id, 220),
            'webViewLink' => "https://drive.google.com/file/d/{$id}/view",
            'webContentLink' => "https://drive.google.com/uc?id={$id}&export=download",
        ];
    }

    /**
     * Ruta de carpetas desde la raíz hasta $folderId (sin la raíz), como la barra de Drive:
     * [['id' => ..., 'name' => '1.POEDAGAR'], ['id' => ..., 'name' => 'HOMBRE'], ...].
     */
    public static function folderPath(PDO $db, string $folderId, string $rootFolderId): array
    {
        $path = [];
        $stmt = $db->prepare("SELECT file_id, name, parent_id FROM drive_index WHERE file_id = ? AND is_folder = 1");
        for ($id = $folderId, $i = 0; $id !== '' && $id !== $rootFolderId && $i < self::MAX_DEPTH; $i++) {
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) break;
            array_unshift($path, ['id' => $row['file_id'], 'name' => $row['name']]);
            $id = (string) $row['parent_id'];
        }
        return $path;
    }

    /**
     * Contenido directo de una carpeta desde el índice (para /carpeta/{id}), sin lo oculto.
     * Cada subcarpeta trae 'cover' (URL lh3 de una foto al azar de su contenido, o '').
     * @return array{name: string, subfolders: array, files: array}|null null si la carpeta no está indexada o está oculta
     */
    public static function folderContents(PDO $db, string $folderId, string $rootFolderId): ?array
    {
        if ($folderId === $rootFolderId) {
            $name = 'Colecciones'; // la raíz no es fila del índice, solo sus hijos
        } else {
            $stmt = $db->prepare("SELECT name FROM drive_index WHERE file_id = ? AND is_folder = 1 AND is_hidden = 0");
            $stmt->execute([$folderId]);
            $name = $stmt->fetchColumn();
            if ($name === false) return null;
        }

        $stmt = $db->prepare(
            "SELECT file_id, name, mime_type, size, is_folder, cover_file_id FROM drive_index
             WHERE parent_id = ? AND is_hidden = 0 ORDER BY name ASC"
        );
        $stmt->execute([$folderId]);
        $subfolders = [];
        $files = [];
        foreach ($stmt->fetchAll() as $r) {
            if ($r['is_folder']) {
                $subfolders[] = [
                    'id' => $r['file_id'],
                    'name' => $r['name'],
                    'cover' => $r['cover_file_id'] ? lh3Url($r['cover_file_id'], 300) : '',
                ];
            } elseif (str_starts_with($r['mime_type'], 'image/') || str_starts_with($r['mime_type'], 'video/')) {
                $files[] = self::toApiFile($r);
            }
        }
        return ['name' => $name, 'subfolders' => $subfolders, 'files' => $files];
    }

    /**
     * Alinea la tabla albums con las carpetas de primer nivel de la raíz:
     * crea las nuevas, actualiza nombres y DESACTIVA (sin borrar) las que ya no
     * están bajo la raíz (p. ej. de una carpeta raíz anterior) o no son marcas
     * ("_INDICE", "PORTADAS"). Reactiva solo lo que el propio sync desactivó
     * (auto_disabled); lo que el admin desactivó a mano se respeta.
     * Llamar solo después de un sync() exitoso.
     * @return array{added: int, deactivated: int, album_ids: string[]}
     */
    public static function syncAlbums(PDO $db, string $rootFolderId): array
    {
        $stmt = $db->prepare("SELECT file_id, name FROM drive_index WHERE is_folder = 1 AND parent_id = ?");
        $stmt->execute([$rootFolderId]);
        $valid = [];
        foreach ($stmt->fetchAll() as $f) {
            if (isAlbumFolderName($f['name'])) {
                $valid[$f['file_id']] = $f['name'];
            }
        }

        // Raíz mal configurada o vacía: no desactivar nada (evita apagar todos los álbumes)
        if (!$valid) {
            return ['added' => 0, 'deactivated' => 0, 'album_ids' => []];
        }

        $existing = [];
        foreach ($db->query("SELECT drive_id, is_active, auto_disabled FROM albums")->fetchAll() as $a) {
            $existing[$a['drive_id']] = $a;
        }
        $added = 0;
        $ins = $db->prepare("INSERT INTO albums (drive_id, name) VALUES (?, ?)");
        $ren = $db->prepare("UPDATE albums SET name = ? WHERE drive_id = ? AND name <> ?");
        $back = $db->prepare("UPDATE albums SET is_active = 1, auto_disabled = 0 WHERE drive_id = ?");
        foreach ($valid as $id => $name) {
            if (!isset($existing[$id])) {
                $ins->execute([$id, $name]);
                $added++;
                continue;
            }
            $ren->execute([$name, $id, $name]);
            if ($existing[$id]['auto_disabled']) {
                $back->execute([$id]); // volvió a la raíz: se reactiva (solo si lo apagó el sync)
            }
        }

        $deactivated = 0;
        $off = $db->prepare("UPDATE albums SET is_active = 0, auto_disabled = 1 WHERE drive_id = ?");
        foreach ($existing as $id => $a) {
            if ($a['is_active'] && !isset($valid[$id])) {
                $off->execute([$id]);
                $deactivated++;
            }
        }

        return ['added' => $added, 'deactivated' => $deactivated, 'album_ids' => array_keys($valid)];
    }

    /** Registra en el índice un archivo recién subido desde el admin (sin esperar al próximo sync). */
    public static function addUploadedFile(PDO $db, array $file, string $parentId, bool $isPublic): void
    {
        if (!self::isReady($db)) return;
        try {
            $stmt = $db->prepare("SELECT album_id, is_hidden FROM drive_index WHERE file_id = ?");
            $stmt->execute([$parentId]);
            $parent = $stmt->fetch() ?: ['album_id' => null, 'is_hidden' => 0];
            $db->prepare(
                "INSERT INTO drive_index (file_id, name, name_stem, mime_type, is_folder, parent_id, album_id, size, is_public, is_hidden, synced_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW(6))
                 ON DUPLICATE KEY UPDATE name = VALUES(name), name_stem = VALUES(name_stem), is_public = VALUES(is_public)"
            )->execute([
                $file['id'], $file['name'], mb_substr(pathinfo($file['name'], PATHINFO_FILENAME), 0, 191),
                $file['mimeType'] ?? '', $parentId, $parent['album_id'], (int) ($file['size'] ?? 0), $isPublic ? 1 : 0,
                (int) $parent['is_hidden'],
            ]);
        } catch (\PDOException $e) {
            // el próximo sync lo recoge
        }
    }

    /** Quita del índice un archivo borrado desde el admin. */
    public static function removeFile(PDO $db, string $fileId): void
    {
        try {
            $db->prepare("DELETE FROM drive_index WHERE file_id = ?")->execute([$fileId]);
        } catch (\PDOException $e) {
            // tabla puede no existir aún
        }
    }

    /**
     * Recalcula, solo con la BD, el estado de las carpetas:
     * - is_hidden: 1 para cada carpeta oculta en el admin (folder_settings, o álbum apagado)
     *   y para todo lo que tiene dentro, a cualquier nivel.
     * - cover_file_id: una foto al azar (pública, no oculta) de todo el contenido de cada carpeta.
     * Quita además las portadas de productos que apuntan a una foto que quedó oculta.
     * Llamar después de cada sync() y de cada cambio de visibilidad.
     * @return array{hidden: int, covers: int, product_covers_cleared: int}
     */
    public static function refreshFolderState(PDO $db): array
    {
        $stats = ['hidden' => 0, 'covers' => 0, 'product_covers_cleared' => 0];
        try {
            $seeds = array_flip($db->query("SELECT folder_id FROM folder_settings WHERE hidden = 1")->fetchAll(PDO::FETCH_COLUMN));
            // Álbum apagado a mano en el admin = carpeta de primer nivel oculta
            foreach ($db->query("SELECT drive_id FROM albums WHERE is_active = 0 AND auto_disabled = 0")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $seeds[$id] = true;
            }
            $rows = $db->query("SELECT file_id, parent_id, is_folder, mime_type, is_public, is_hidden, cover_file_id FROM drive_index")
                       ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return $stats; // migración 021 aún no aplicada
        }

        $parentOf = [];
        foreach ($rows as $r) {
            $parentOf[$r['file_id']] = (string) $r['parent_id'];
        }

        // Una carpeta está oculta si ella o alguna superior está en $seeds (memo por carpeta)
        $hiddenMemo = [];
        $isHidden = function (string $id) use (&$hiddenMemo, $seeds, $parentOf): bool {
            $chain = [];
            $result = false;
            for ($i = 0; $id !== '' && $i < self::MAX_DEPTH; $i++) {
                if (isset($hiddenMemo[$id])) { $result = $hiddenMemo[$id]; break; }
                if (isset($seeds[$id])) { $result = true; $chain[] = $id; break; }
                $chain[] = $id;
                $id = $parentOf[$id] ?? '';
            }
            foreach ($chain as $c) $hiddenMemo[$c] = $result;
            return $result;
        };

        $newHidden = [];
        $count = [];  // carpeta → fotos vistas (muestreo al azar de una sola pasada)
        $cover = [];
        foreach ($rows as $r) {
            $id = $r['file_id'];
            $hidden = $r['is_folder'] ? $isHidden($id) : $isHidden((string) $r['parent_id']);
            $newHidden[$id] = $hidden ? 1 : 0;
            if ($hidden || $r['is_folder'] || !$r['is_public'] || !str_starts_with($r['mime_type'], 'image/')) continue;

            // La foto cuenta para su carpeta y todas las superiores
            for ($f = (string) $r['parent_id'], $i = 0; isset($parentOf[$f]) && $i < self::MAX_DEPTH; $f = $parentOf[$f], $i++) {
                $count[$f] = ($count[$f] ?? 0) + 1;
                if (mt_rand(1, $count[$f]) === 1) $cover[$f] = $id;
            }
        }

        // Guardar solo lo que cambió
        $toHide = $toShow = $coverRows = [];
        foreach ($rows as $r) {
            $id = $r['file_id'];
            if ((int) $r['is_hidden'] !== $newHidden[$id]) {
                if ($newHidden[$id]) $toHide[] = $id; else $toShow[] = $id;
            }
            if ($r['is_folder'] && ($cover[$id] ?? null) !== $r['cover_file_id']) {
                $coverRows[$id] = $cover[$id] ?? null;
            }
        }
        foreach ([1 => $toHide, 0 => $toShow] as $value => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $db->prepare("UPDATE drive_index SET is_hidden = {$value} WHERE file_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")")
                   ->execute($chunk);
            }
        }
        foreach (array_chunk($coverRows, 500, true) as $chunk) {
            $params = [];
            foreach ($chunk as $folder => $file) { $params[] = $folder; $params[] = $file; }
            $params = array_merge($params, array_keys($chunk));
            $db->prepare(
                "UPDATE drive_index SET cover_file_id = CASE file_id " . str_repeat('WHEN ? THEN ? ', count($chunk)) . "END
                 WHERE file_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")"
            )->execute($params);
        }
        $stats['hidden'] = count(array_filter($newHidden));
        $stats['covers'] = count($cover);

        // Portadas guardadas que son una foto oculta: se vacían y el visor elige otra visible
        $hiddenFiles = array_filter($newHidden);
        if ($hiddenFiles) {
            $clear = $db->prepare("UPDATE products SET cover_image_url = '' WHERE id = ?");
            foreach ($db->query("SELECT id, cover_image_url FROM products WHERE cover_image_url LIKE '%googleusercontent.com/d/%'")->fetchAll() as $p) {
                if (preg_match('#/d/([A-Za-z0-9_-]+)#', $p['cover_image_url'], $m) && isset($hiddenFiles[$m[1]])) {
                    $clear->execute([$p['id']]);
                    $stats['product_covers_cleared']++;
                }
            }
        }
        return $stats;
    }

    /** Guarda la decisión del admin de mostrar u ocultar una carpeta y recalcula el estado. */
    public static function setFolderHidden(PDO $db, string $folderId, bool $hidden): array
    {
        $db->prepare(
            "INSERT INTO folder_settings (folder_id, hidden) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE hidden = VALUES(hidden)"
        )->execute([$folderId, $hidden ? 1 : 0]);
        // Carpeta de primer nivel: la portada de inicio lee albums.is_active
        $db->prepare("UPDATE albums SET is_active = ?, auto_disabled = 0 WHERE drive_id = ?")
           ->execute([$hidden ? 0 : 1, $folderId]);
        $stats = self::refreshFolderState($db);
        // Las fotos que se ocultan/muestran cambian el enlace y las portadas
        $link = self::relink($db);
        return $stats + ['covers_set' => $link['covers_set'], 'covers_cleared' => $link['covers_cleared']];
    }
}
