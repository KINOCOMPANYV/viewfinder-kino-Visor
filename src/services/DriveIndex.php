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
     * Fotos/videos de un SKU desde el índice, con la misma regla de familia que
     * fetchAndCacheMediaForSku() (variante = propios + genéricos del padre).
     * Mismo formato que la API de Drive (id, name, mimeType, size, thumbnailLink...).
     * @return array{files: array, root_sku: string}
     */
    public static function filesForSku(PDO $db, string $sku): array
    {
        $rootSku = getFamilyRootSku($db, $sku);
        $rootFiles = self::findByPrefix($db, $rootSku);
        $childFiles = $sku !== $rootSku ? self::findByPrefix($db, $sku) : [];
        return ['files' => combineFamilyFiles($sku, $rootSku, $rootFiles, $childFiles), 'root_sku' => $rootSku];
    }

    /** Archivos cuyo nombre empieza con el SKU (índice idx_stem + regla fileNameStartsWithSku). */
    private static function findByPrefix(PDO $db, string $sku): array
    {
        if ($sku === '') return [];
        // "M:8392-1" en la hoja; "M8392-1.jpg" o "M-8392-1.jpg" en Drive
        $files = [];
        foreach (skuNameVariants($sku) as $variant) {
            foreach (self::findByExactPrefix($db, $variant) as $f) {
                $files[$f['id']] = $f;
            }
        }
        return array_values($files);
    }

    private static function findByExactPrefix(PDO $db, string $sku): array
    {
        $stmt = $db->prepare(
            "SELECT file_id, name, mime_type, size FROM drive_index
             WHERE is_folder = 0 AND is_hidden = 0 AND name_stem LIKE ? ORDER BY name ASC LIMIT 2000"
        );
        $stmt->execute([addcslashes($sku, '%_\\') . '%']);

        $files = [];
        foreach ($stmt->fetchAll() as $r) {
            if (fileNameStartsWithSku($r['name'], $sku)) {
                $files[] = self::toApiFile($r);
            }
        }
        return $files;
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
        return self::refreshFolderState($db);
    }
}
