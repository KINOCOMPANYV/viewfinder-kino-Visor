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
    private const MAX_DEPTH = 8;

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
        $stmt = $db->prepare(
            "SELECT file_id, name, mime_type, size FROM drive_index
             WHERE is_folder = 0 AND name_stem LIKE ? ORDER BY name ASC LIMIT 2000"
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
     * Contenido directo de una carpeta desde el índice (para /carpeta/{id}).
     * @return array{name: string, subfolders: array, files: array}|null null si la carpeta no está indexada
     */
    public static function folderContents(PDO $db, string $folderId, string $rootFolderId): ?array
    {
        if ($folderId === $rootFolderId) {
            $name = 'Colecciones'; // la raíz no es fila del índice, solo sus hijos
        } else {
            $stmt = $db->prepare("SELECT name FROM drive_index WHERE file_id = ? AND is_folder = 1");
            $stmt->execute([$folderId]);
            $name = $stmt->fetchColumn();
            if ($name === false) return null;
        }

        $stmt = $db->prepare("SELECT file_id, name, mime_type, size, is_folder FROM drive_index WHERE parent_id = ? ORDER BY name ASC");
        $stmt->execute([$folderId]);
        $subfolders = [];
        $files = [];
        foreach ($stmt->fetchAll() as $r) {
            if ($r['is_folder']) {
                $subfolders[] = ['id' => $r['file_id'], 'name' => $r['name']];
            } elseif (str_starts_with($r['mime_type'], 'image/') || str_starts_with($r['mime_type'], 'video/')) {
                $files[] = self::toApiFile($r);
            }
        }
        return ['name' => $name, 'subfolders' => $subfolders, 'files' => $files];
    }

    /** Registra en el índice un archivo recién subido desde el admin (sin esperar al próximo sync). */
    public static function addUploadedFile(PDO $db, array $file, string $parentId, bool $isPublic): void
    {
        if (!self::isReady($db)) return;
        try {
            $stmt = $db->prepare("SELECT album_id FROM drive_index WHERE file_id = ?");
            $stmt->execute([$parentId]);
            $album = $stmt->fetchColumn();
            $db->prepare(
                "INSERT INTO drive_index (file_id, name, name_stem, mime_type, is_folder, parent_id, album_id, size, is_public, synced_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, NOW(6))
                 ON DUPLICATE KEY UPDATE name = VALUES(name), name_stem = VALUES(name_stem), is_public = VALUES(is_public)"
            )->execute([
                $file['id'], $file['name'], mb_substr(pathinfo($file['name'], PATHINFO_FILENAME), 0, 191),
                $file['mimeType'] ?? '', $parentId, $album === false ? null : $album, (int) ($file['size'] ?? 0), $isPublic ? 1 : 0,
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
}
