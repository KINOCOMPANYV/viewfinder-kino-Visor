<?php
/**
 * Respaldo completo de MySQL (2026-11-23, decisión del dueño). PHP puro: la imagen de Docker no trae mysqldump.
 *   dump($db, $path)      escribe un .sql.gz: una sentencia por línea (CREATE TABLE sin saltos; INSERT de hasta 200 filas;
 *                         binarios en 0x…) y al principio «-- COUNT tabla n» para comprobar la restauración.
 *   toDrive($db, $force)  sube el .sql.gz a la carpeta de Drive (la misma de los respaldos de pedidos) y deja solo las
 *                         últimas BACKUP_KEEP (14). Una vez al día desde BACKUP_DB_HOUR (3, hora Colombia).
 *   restore($db, $path)   ejecuta el .sql.gz en OTRA base (scripts/restore_backup.php; nunca en la de producción).
 * No se copian los datos de google_tokens (claves de Google: se reconecta en Admin → Media) ni de caché (drive_cache,
 * media_search_cache). Sí va todo lo demás, incluidos pedidos, precios editados y huellas de fotos.
 */
require_once __DIR__ . '/OrdersBackup.php';
require_once __DIR__ . '/GoogleDriveService.php';

class DbBackup
{
    const PREFIX = 'visor-mysql-';
    const NO_DATA = ['google_tokens', 'drive_cache', 'media_search_cache'];
    const FOLDER_NAME = OrdersBackup::FOLDER_NAME;

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
    }

    /** @return array{tables: int, rows: int, bytes: int} */
    public static function dump(PDO $db, string $path): array
    {
        $gz = gzopen($path, 'wb6');
        if (!$gz) {
            throw new RuntimeException('No se pudo crear el archivo del respaldo.');
        }
        // Foto consistente: todas las tablas se leen en el mismo instante (InnoDB, REPEATABLE READ), aunque
        // alguien guarde un pedido mientras corre el respaldo. Si ya hay una transacción abierta, no se toca.
        $snap = !$db->inTransaction();
        if ($snap) {
            $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }
        try {
            return self::dumpTables($db, $path, $gz);
        } finally {
            if ($snap) {
                $db->exec('COMMIT');
            }
        }
    }

    /** @return array{tables: int, rows: int, bytes: int} */
    private static function dumpTables(PDO $db, string $path, $gz): array
    {
        $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];
        $body = [];
        $rows = 0;
        // Los datos se escriben en un archivo aparte y se unen tras la cabecera con los conteos
        $tmp = $path . '.body';
        $out = gzopen($tmp, 'wb6');
        foreach ($tables as $t) {
            $q = '`' . str_replace('`', '``', $t) . '`';
            $create = $db->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($out, "DROP TABLE IF EXISTS $q;\n" . preg_replace('/\s*\n\s*/', ' ', $create) . ";\n");
            $n = 0;
            if (!in_array($t, self::NO_DATA, true)) {
                $cols = $db->query("SHOW COLUMNS FROM $q")->fetchAll(PDO::FETCH_ASSOC);
                $bin = array_map(fn($c) => (bool) preg_match('/blob|binary/i', $c['Type']), $cols);
                $names = implode(',', array_map(fn($c) => '`' . $c['Field'] . '`', $cols));
                $stmt = $db->query("SELECT * FROM $q", PDO::FETCH_NUM);
                $batch = [];
                while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
                    $vals = [];
                    foreach ($r as $i => $v) {
                        $vals[] = $v === null ? 'NULL' : ($bin[$i] && $v !== '' ? '0x' . bin2hex($v) : $db->quote((string) $v));
                    }
                    $batch[] = '(' . implode(',', $vals) . ')';
                    $n++;
                    if (count($batch) >= 200) {
                        gzwrite($out, "INSERT INTO $q ($names) VALUES " . implode(',', $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    gzwrite($out, "INSERT INTO $q ($names) VALUES " . implode(',', $batch) . ";\n");
                }
            }
            $counts[$t] = $n;
            $rows += $n;
        }
        gzclose($out);
        gzwrite($gz, "-- Respaldo Viewfinder Kino Visor " . self::now()->format('Y-m-d H:i:s') . " (hora Colombia)\n");
        foreach ($counts as $t => $n) {
            gzwrite($gz, "-- COUNT {$t} {$n}\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
        $in = gzopen($tmp, 'rb');
        while (!gzeof($in)) {
            gzwrite($gz, gzread($in, 1 << 20));
        }
        gzclose($in);
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- FIN\n");
        gzclose($gz);
        @unlink($tmp);
        return ['tables' => count($tables), 'rows' => $rows, 'bytes' => (int) filesize($path)];
    }

    /**
     * Ejecuta un respaldo en la base $db (vacía o de pruebas) y compara conteos.
     * @return array{ok: bool, tables: int, mismatches: array<string,string>, statements: int}
     */
    public static function restore(PDO $db, string $path, array $skipTables = []): array
    {
        $gz = gzopen($path, 'rb');
        if (!$gz) {
            throw new RuntimeException("No se pudo abrir {$path}.");
        }
        $expected = [];
        $statements = 0;
        $ended = false;
        while (($line = gzgets($gz)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '' ) {
                continue;
            }
            if (strncmp($line, '-- COUNT ', 9) === 0) {
                [, , $t, $n] = explode(' ', $line);
                if (!in_array($t, $skipTables, true)) $expected[$t] = (int) $n;
                continue;
            }
            if ($line === '-- FIN') {
                $ended = true;
                continue;
            }
            if (strncmp($line, '--', 2) === 0) {
                continue;
            }
            // Tablas a conservar tal como están en la base destino (p. ej. google_tokens en v2): se saltan DROP/CREATE/INSERT
            foreach ($skipTables as $st) {
                if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `' . preg_quote($st, '/') . '`/', $line)) continue 2;
            }
            $db->exec($line);
            $statements++;
        }
        gzclose($gz);
        $bad = [];
        if (!$ended) {
            $bad['(archivo)'] = 'incompleto: falta la línea final';
        }
        foreach ($expected as $t => $n) {
            $got = (int) $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn();
            if ($got !== $n) {
                $bad[$t] = "esperadas {$n}, restauradas {$got}";
            }
        }
        return ['ok' => !$bad, 'tables' => count($expected), 'mismatches' => $bad, 'statements' => $statements];
    }

    /** @return array{ok: bool, skipped?: bool, msg: string} */
    public static function toDrive(PDO $db, bool $force = false): array
    {
        if (GoogleDriveService::readonly()) {
            return ['ok' => true, 'skipped' => true, 'msg' => 'Este servicio (v2) no escribe en Drive (DRIVE_READONLY=1): sin respaldo en Drive.'];
        }
        $drive = new GoogleDriveService();
        if (!$drive->getValidToken($db)) {
            return ['ok' => false, 'msg' => 'No hay conexión a Google Drive (reconectar en Admin → Media).'];
        }
        $path = null;
        try {
            $folder = trim((string) env('PEDIDOS_BACKUP_FOLDER_ID', ''));
            if ($folder === '') {
                $folder = $drive->findChildByName('root', self::FOLDER_NAME, true)
                    ?: ($drive->createFolder('root', self::FOLDER_NAME)['id'] ?? null);
                if (!$folder) {
                    return ['ok' => false, 'msg' => 'No se pudo crear la carpeta de respaldos en Drive.'];
                }
            }
            $name = self::PREFIX . self::now()->format('Y-m-d');
            if (!$force && $drive->findChildByName($folder, $name . '.sql.gz')) {
                return ['ok' => true, 'skipped' => true, 'msg' => "Ya existe el respaldo de hoy ({$name})."];
            }
            if ($force) {
                $name .= '_' . self::now()->format('H-i');
            }
            $path = tempnam(sys_get_temp_dir(), 'dbbk');
            $info = self::dump($db, $path);
            $res = $drive->uploadFile($folder, $name . '.sql.gz', $path, 'application/gzip');
            if (empty($res['id'])) {
                return ['ok' => false, 'msg' => 'No se pudo subir el respaldo: ' . ($res['error']['message'] ?? 'sin respuesta')];
            }
            $removed = self::prune($drive, $folder);
            $mb = round($info['bytes'] / 1048576, 1);
            return ['ok' => true, 'folder' => $folder, 'msg' => "Respaldo MySQL en Drive ({$name}.sql.gz, {$mb} MB, {$info['tables']} tablas, {$info['rows']} filas"
                . ($removed ? "; {$removed} viejos borrados" : '') . ').'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'Respaldo MySQL falló: ' . $e->getMessage()];
        } finally {
            if ($path) {
                @unlink($path);
                @unlink($path . '.body');
            }
        }
    }

    /** Deja solo los últimos BACKUP_KEEP respaldos de MySQL (por nombre, que lleva la fecha). Devuelve cuántos borró. */
    private static function prune(GoogleDriveService $drive, string $folder): int
    {
        $keep = max(3, (int) env('BACKUP_KEEP', '14'));
        $files = array_filter($drive->listFiles($folder)['files'] ?? [],
            fn($f) => strncmp($f['name'], self::PREFIX, strlen(self::PREFIX)) === 0 && substr($f['name'], -7) === '.sql.gz');
        usort($files, fn($a, $b) => strcmp($b['name'], $a['name']));
        $n = 0;
        foreach (array_slice($files, $keep) as $f) {
            $n += $drive->deleteFile($f['id']) ? 1 : 0;
        }
        return $n;
    }
}
