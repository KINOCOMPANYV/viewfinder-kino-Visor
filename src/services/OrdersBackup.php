<?php
/**
 * Copia de seguridad de «Pedidos bodega» (tabla warehouse_orders). Decisión del dueño 2026-11-23:
 * los pedidos siguen en MySQL de Railway y además se pueden bajar en Excel (Admin → 🗄 Pedidos) y cada noche
 * se sube una copia sola a Google Drive (cli_sync.php pedidos, desde sync_loop.sh).
 *   xlsx(): pestañas «Pedidos» (uno por fila) y «Líneas» (cada producto del checklist; sin avance = lo del pedido).
 *   json(): todo tal cual (order_json + lines_json) para poder restaurar.
 * Carpeta en Drive: env PEDIDOS_BACKUP_FOLDER_ID, o «Respaldos pedidos bodega (visor)» en Mi unidad (se crea sola).
 * Nunca dentro de GOOGLE_DRIVE_FOLDER_ID (el índice del catálogo la recorrería). No borra copias viejas.
 */
require_once __DIR__ . '/GoogleDriveService.php';
require_once __DIR__ . '/XlsxWriter.php';

class OrdersBackup
{
    const FOLDER_NAME = 'Respaldos pedidos bodega (visor)';
    const TZ = 'America/Bogota';

    /** @return array<int, array> todos los pedidos con order/lines decodificados */
    public static function rows(PDO $db): array
    {
        $rows = $db->query('SELECT * FROM warehouse_orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['order'] = json_decode((string) $r['order_json'], true);
            $r['lines'] = $r['lines_json'] ? json_decode((string) $r['lines_json'], true) : null;
            unset($r['order_json'], $r['lines_json']);
        }
        return $rows;
    }

    public static function json(array $rows): string
    {
        return json_encode(['exported_at' => self::now()->format('c'), 'count' => count($rows), 'orders' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function xlsx(array $rows): string
    {
        $num = fn($v) => $v === null || $v === '' || !is_numeric($v) ? '' : $v + 0;
        $orders = [['ID', 'N.º pedido', 'Cliente', 'Fecha pedido', 'Estado', 'Productos', 'Alistados', 'Chequeado por',
            'Total pedido (PDF)', 'Total final', 'Creado', 'Actualizado', 'Finalizado', 'Facturado']];
        $lines = [['ID pedido', 'N.º pedido', 'Cliente', 'Estado', 'Código', 'Nombre', 'Cant. pedida', 'Cant. final',
            'Precio unidad', 'Subtotal final', 'Alistado', 'Nota', 'Agregado / cambiado']];
        foreach ($rows as $r) {
            $info = is_array($r['order']['order'] ?? null) ? $r['order']['order'] : [];
            $orders[] = [(int) $r['id'], $r['number'], $r['client'], $r['order_date'], $r['status'], (int) $r['items_count'],
                (int) $r['checked_count'], $r['checked_by'], (string) ($info['totalText'] ?? ''), $num($r['total_final']),
                $r['created_at'], $r['updated_at'], $r['finished_at'] ?? '', $r['invoiced_at'] ?? ''];
            $base = [(int) $r['id'], $r['number'], $r['client'], $r['status']];
            if (is_array($r['lines'])) {
                foreach ($r['lines'] as $l) {
                    if (!is_array($l)) continue;
                    $price = $num($l['price'] ?? null);
                    $qty = $num($l['qty'] ?? null);
                    $changed = empty($l['orig']) ? 'agregado'
                        : (is_array($l['base'] ?? null) && ($l['base']['code'] ?? '') !== ($l['code'] ?? '') ? 'cambiado (antes ' . $l['base']['code'] . ')' : '');
                    $lines[] = array_merge($base, [(string) ($l['code'] ?? ''), (string) ($l['name'] ?? ''), $num($l['origQty'] ?? null), $qty,
                        $price, $price !== '' && $qty !== '' ? $price * $qty : '', !empty($l['checked']) ? 'sí' : 'no', (string) ($l['note'] ?? ''), $changed]);
                }
            } else { // sin avance guardado: los productos tal cual vinieron del PDF
                foreach (($r['order']['items'] ?? []) as $it) {
                    if (!is_array($it)) continue;
                    $lines[] = array_merge($base, [(string) ($it['code'] ?? ''), (string) ($it['desc'] ?? ''), $num($it['qty'] ?? null), '',
                        (string) ($it['priceText'] ?? ''), '', 'no', '', '']);
                }
            }
        }
        return XlsxWriter::build([
            'Pedidos' => ['rows' => $orders, 'widths' => [6, 14, 30, 14, 10, 10, 10, 18, 16, 14, 19, 19, 19, 19]],
            'Líneas' => ['rows' => $lines, 'widths' => [9, 14, 26, 10, 14, 34, 11, 11, 13, 14, 9, 30, 22]],
        ]);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TZ));
    }

    /** Nombre base de la copia del día, p. ej. «Pedidos_bodega_2026-11-23». */
    public static function baseName(?DateTimeImmutable $d = null): string
    {
        return 'Pedidos_bodega_' . ($d ?? self::now())->format('Y-m-d');
    }

    /**
     * Sube la copia (Excel + JSON) a Drive.
     * $force = false: solo si hoy aún no hay copia (la nocturna). true: siempre (botón del admin, con la hora en el nombre).
     * @return array{ok: bool, skipped?: bool, msg: string, folder?: string, count?: int}
     */
    public static function toDrive(PDO $db, bool $force = false): array
    {
        if (GoogleDriveService::readonly()) {
            return ['ok' => true, 'skipped' => true, 'msg' => 'Este servicio (v2) no escribe en Drive (DRIVE_READONLY=1): sin respaldo en Drive.'];
        }
        $drive = new GoogleDriveService();
        if (!$drive->getValidToken($db)) {
            return ['ok' => false, 'msg' => 'No hay conexión a Google Drive (reconectar en Admin → Media).'];
        }
        try {
            $folder = trim((string) env('PEDIDOS_BACKUP_FOLDER_ID', ''));
            if ($folder === '') {
                $folder = $drive->findChildByName('root', self::FOLDER_NAME, true);
                if (!$folder) {
                    $folder = $drive->createFolder('root', self::FOLDER_NAME)['id'] ?? null;
                    if (!$folder) {
                        return ['ok' => false, 'msg' => 'No se pudo crear la carpeta de respaldos en Drive.'];
                    }
                }
            }
            $name = self::baseName();
            if (!$force && $drive->findChildByName($folder, $name . '.xlsx')) {
                return ['ok' => true, 'skipped' => true, 'msg' => "Ya existe la copia de hoy ({$name}).", 'folder' => $folder];
            }
            if ($force) {
                $name .= '_' . self::now()->format('H-i');
            }
            $rows = self::rows($db);
            $tmp = sys_get_temp_dir();
            $files = [
                $name . '.xlsx' => [self::xlsx($rows), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                $name . '.json' => [self::json($rows), 'application/json'],
            ];
            foreach ($files as $fname => [$content, $mime]) {
                $path = tempnam($tmp, 'pedbk');
                file_put_contents($path, $content);
                $res = $drive->uploadFile($folder, $fname, $path, $mime);
                @unlink($path);
                if (empty($res['id'])) {
                    $why = $res['error']['message'] ?? 'sin respuesta';
                    return ['ok' => false, 'msg' => "No se pudo subir {$fname}: {$why}", 'folder' => $folder];
                }
            }
            return ['ok' => true, 'msg' => count($rows) . " pedidos copiados a Drive ({$name}).", 'folder' => $folder, 'count' => count($rows)];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}
