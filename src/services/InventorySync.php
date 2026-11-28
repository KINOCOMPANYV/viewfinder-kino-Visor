<?php
/** SYS → KINO → Hoja master. Historial en MySQL y reintentos sin volver a importar el archivo. */
require_once __DIR__ . '/InventoryImport.php';
require_once __DIR__ . '/MasterSheet.php';
require_once __DIR__ . '/SheetWriter.php';

class InventorySync
{
    private PDO $db;
    private Closure $sheetReader;
    private Closure $sheetWriter;

    public function __construct(PDO $db, ?callable $sheetReader = null, ?callable $sheetWriter = null)
    {
        $this->db = $db;
        $this->sheetReader = Closure::fromCallable($sheetReader ?? [InventoryImport::class, 'sheetRows']);
        $this->sheetWriter = Closure::fromCallable($sheetWriter ?? [SheetWriter::class, 'writeColumns']);
    }

    public function ready(): bool
    {
        try { $this->db->query('SELECT id FROM inventory_imports LIMIT 0'); return true; }
        catch (PDOException $e) { return false; }
    }

    public function history(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->query("SELECT id, filename, user_name, status, sheet_status, applied_count, created_at, completed_at
            FROM inventory_imports ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function load(int $id = 0): ?array
    {
        $st = $this->db->prepare($id > 0 ? 'SELECT * FROM inventory_imports WHERE id = ?' : 'SELECT * FROM inventory_imports ORDER BY id DESC LIMIT 1');
        $st->execute($id > 0 ? [$id] : []);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['result'] = json_decode($row['result_json'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function latestAppliedId(): int
    {
        return (int) $this->db->query("SELECT id FROM inventory_imports WHERE status IN ('applied', 'partial') ORDER BY id DESC LIMIT 1")->fetchColumn();
    }

    public function latestAppliedSummary(): ?array
    {
        return $this->db->query("SELECT id, filename, status, sheet_status, user_name, created_at FROM inventory_imports
            WHERE status IN ('applied', 'partial') ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Candado compartido con hoja/Drive: no se publica una carga mientras otro proceso modifica sus fuentes. */
    public function locked(callable $work)
    {
        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $st = $this->db->prepare("SELECT GET_LOCK('vf_sync', 0)");
            $st->execute();
            if (!(int) $st->fetchColumn()) throw new RuntimeException(SYNC_BUSY_MESSAGE);
        }
        try { return $work(); }
        finally { if ($mysql) $this->db->query("SELECT RELEASE_LOCK('vf_sync')"); }
    }

    public function import(array $parsed, string $filename, string $hash, bool $negativeToZero, bool $sendSheet, ?array $user): int
    {
        if (!$this->ready()) throw new RuntimeException('Falta preparar el historial de inventario. Aplica la migración 047 antes de importar.');
        if (!$parsed['items'] || count($parsed['items']) > 50000) throw new RuntimeException('El archivo debe contener entre 1 y 50.000 filas de artículos.');
        return $this->locked(function () use ($parsed, $filename, $hash, $negativeToZero, $sendSheet, $user) {
            $sheet = ($this->sheetReader)($this->db);
            if (!$sheet['rows']) throw new RuntimeException('No hay productos en la Hoja master ni en su copia local. Sincroniza primero los productos.');
            $result = InventoryImport::compare($parsed['items'], $sheet['rows'], $negativeToZero);
            // Las filas ambiguas o con precios en conflicto no se envían a ninguna de las dos fuentes.
            $products = $this->db->query('SELECT sku FROM products')->fetchAll(PDO::FETCH_ASSOC);
            $matches = InventoryImport::matchItems($parsed['items'], $products);
            $issues = [];
            foreach ($result['report'] as $issue) $issues[$issue['tipo'] . '|' . $issue['codigo_inventario']] = true;
            foreach ($matches['ambiguous'] as $item) {
                $key = 'codigo_ambiguo|' . $item['code'];
                if (isset($issues[$key])) continue;
                $issues[$key] = true;
                $result['report'][] = ['tipo' => 'codigo_ambiguo', 'codigo_inventario' => $item['code'], 'codigo_hoja' => '', 'descripcion' => $item['desc'],
                    'stock_inventario' => $item['stock'], 'stock_hoja' => null, 'diferencia' => null, 'precio_inventario' => $item['price'], 'PRICE_hoja' => null,
                    'nota' => 'Hay varios productos posibles en KINO: se conserva el inventario anterior.'];
                $result['summary']['conflictos']++;
            }
            $safe = [];
            foreach ($matches['assigned'] as $i => $items) {
                if (!InventoryImport::itemTotals($items)['conflict']) array_push($safe, ...$items);
            }
            $sheetResult = InventoryImport::compare($safe, $sheet['rows'], $negativeToZero);
            $columns = self::sheetColumns($sheetResult);
            $result['meta'] = ['archivo' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 180),
                'fecha' => gmdate('Y-m-d H:i:s'), 'columnas' => $parsed['columns'], 'fuente_hoja' => $sheet['source'],
                'neg_cero' => $negativeToZero, 'hoja' => $sendSheet ? 'Pendiente de enviar a la Hoja master.' : 'No solicitado: KINO se actualiza directamente.',
                'filas_no_aplicadas' => count($parsed['items']) - count($safe)];
            $json = self::json($result);
            $st = $this->db->prepare('INSERT INTO inventory_imports (filename, source_hash, user_id, user_name, status, sheet_status, result_json, sheet_columns_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$result['meta']['archivo'], $hash, $user['id'] ?? null, mb_substr(($user['name'] ?? '') ?: ($user['username'] ?? 'Administrador'), 0, 120),
                'processing', $sendSheet ? 'pending' : 'not_requested', $json, self::json($columns), gmdate('Y-m-d H:i:s')]);
            $id = (int) $this->db->lastInsertId();
            try {
                $this->db->beginTransaction();
                $count = InventoryImport::applyToVisor($this->db, $safe, $negativeToZero);
                $result['meta']['aplicado'] = $count;
                $this->save($id, $result, 'applied', $sendSheet ? 'pending' : 'not_requested', '', $count);
                $this->db->commit();
                recordSyncStatus($this->db, 'inventario', true, "SYS: $count productos actualizados en KINO. Archivo: " . $result['meta']['archivo']);
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                $this->save($id, $result, 'failed', 'not_requested', 'No se pudo publicar el inventario en KINO.', 0);
                recordSyncStatus($this->db, 'inventario', false, 'No se pudo publicar la carga SYS #' . $id);
                if (class_exists('ErrorLog')) ErrorLog::record('php', 'inventario', $e->getMessage(), __FILE__, __LINE__);
                throw new RuntimeException('No se pudo guardar el inventario. Los cambios de esta carga se deshicieron. Revisa el Registro.', 0, $e);
            }
            // La intención ya está guardada: si PHP se interrumpe aquí, la pantalla ofrece reintentar.
            if ($sendSheet) $this->syncSheet($id);
            return $id;
        });
    }

    public static function sheetColumns(array $result): array
    {
        $columns = ['PRICE' => [], 'STOCK' => []];
        $matched = array_fill_keys($result['matched_skus'] ?? [], true);
        foreach ($result['paste'] as $row) {
            if ($row['SKU'] === '' || !isset($matched[$row['SKU']])) continue;
            // El escritor compara con la hoja en vivo, también al reintentar: no reenviar valores de productos ausentes en SYS.
            if ($row['PRICE_nuevo'] !== null) $columns['PRICE'][$row['SKU']] = $row['PRICE_nuevo'];
            if ($row['STOCK_nuevo'] !== null) $columns['STOCK'][$row['SKU']] = $row['STOCK_nuevo'];
        }
        return $columns;
    }

    public function retrySheet(int $id): void
    {
        $this->locked(fn() => $this->syncSheet($id));
    }

    private function syncSheet(int $id): void
    {
        $row = $this->load($id);
        if (!$row || !in_array($row['status'], ['applied', 'partial'], true)) throw new RuntimeException('Esta carga no se aplicó a KINO y no se puede enviar a la hoja.');
        if ($id !== $this->latestAppliedId()) throw new RuntimeException('Hay un inventario más reciente. Abre la última carga para no enviar datos antiguos a la hoja.');
        if ($row['sheet_status'] === 'ok') return;
        $result = $row['result'];
        try {
            $columns = json_decode($row['sheet_columns_json'], true, 512, JSON_THROW_ON_ERROR);
            $sent = ($this->sheetWriter)($this->db, $columns);
            $result['meta']['hoja'] = 'Hoja master actualizada: ' . (int) $sent['celdas'] . ' celdas. KINO y la hoja están al día para esta carga.';
            $this->save($id, $result, 'applied', 'ok', '', (int) $row['applied_count']);
        } catch (Throwable $e) {
            $result['meta']['hoja'] = 'KINO actualizado. Pendiente Hoja master: ' . mb_substr($e->getMessage(), 0, 700);
            $this->save($id, $result, 'partial', 'failed', mb_substr($e->getMessage(), 0, 1000), (int) $row['applied_count']);
            if (class_exists('ErrorLog')) ErrorLog::record('php', 'inventario_hoja', $e->getMessage(), __FILE__, __LINE__);
        }
    }

    public function rememberResult(int $id, array $result): void
    {
        $this->db->prepare('UPDATE inventory_imports SET result_json = ? WHERE id = ?')->execute([self::json($result), $id]);
    }

    private function save(int $id, array $result, string $status, string $sheetStatus, string $error, int $count): void
    {
        $this->db->prepare('UPDATE inventory_imports SET result_json = ?, status = ?, sheet_status = ?, error_message = ?, applied_count = ?, completed_at = ? WHERE id = ?')
            ->execute([self::json($result), $status, $sheetStatus, $error, $count, gmdate('Y-m-d H:i:s'), $id]);
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
