<?php
/**
 * Índice de búsqueda del catálogo (migración 032). Buscar y filtrar leen solo columnas de `products`
 * (search_text, contains_text, folder_ids) en vez de cruzar drive_index en cada consulta (eso tardaba 30+ s).
 * Se rehace completo tras cada sincronización (cada hora con el sync automático) y cuando cambian carpetas ocultas.
 * También guarda en search_meta las carpetas del filtro (folder_options) y la hora del último armado (built_at).
 */
class SearchIndex
{
    /** Arma el índice si nunca se armó (p. ej. justo después de desplegar la migración). */
    public static function ensure(PDO $db): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            if (!$db->query("SELECT 1 FROM search_meta WHERE k = 'built_at'")->fetchColumn()) self::rebuild($db);
        } catch (Throwable $e) { /* migración 032 aún no aplicada */ }
    }

    /** @return array{products: int, folders: int, ms: int}|null */
    public static function rebuild(PDO $db): ?array
    {
        $t0 = microtime(true);
        try {
            $root = env('GOOGLE_DRIVE_FOLDER_ID', '');
            // Carpetas visibles: id → [nombre, carpeta superior]
            $folders = [];
            foreach ($db->query("SELECT file_id, name, parent_id FROM drive_index WHERE is_folder = 1 AND is_hidden = 0")->fetchAll(PDO::FETCH_NUM) as [$id, $name, $parent]) {
                $folders[$id] = [(string) $name, (string) $parent];
            }
            $chain = []; // carpeta → [ids de ella y sus superiores visibles, nombres]
            $up = function (string $id) use (&$up, &$chain, $folders, $root): array {
                if (isset($chain[$id])) return $chain[$id];
                $chain[$id] = [[], []]; // evita ciclos
                if (!isset($folders[$id]) || $id === $root) return $chain[$id];
                [$pIds, $pNames] = $up($folders[$id][1]);
                return $chain[$id] = [array_merge($pIds, [$id]), array_merge($pNames, [$folders[$id][0]])];
            };
            // Archivos visibles enlazados a un código
            $byCode = []; // código → [folderIds => true, folderNames => true, fileNames => true]
            foreach ($db->query("SELECT matched_sku, name, parent_id FROM drive_index WHERE is_folder = 0 AND is_hidden = 0 AND matched_sku IS NOT NULL")->fetchAll(PDO::FETCH_NUM) as [$code, $name, $parent]) {
                $c = &$byCode[$code];
                $c['n'][pathinfo((string) $name, PATHINFO_FILENAME)] = true;
                if ($parent && isset($folders[$parent])) {
                    [$ids, $names] = $up((string) $parent);
                    foreach ($ids as $i) $c['i'][$i] = true;
                    foreach ($names as $n) $c['f'][$n] = true;
                }
                unset($c);
            }
            // Carpetas para el filtro: las que tienen fotos de productos visibles (y sus superiores)
            $visible = array_flip($db->query("SELECT sku FROM products WHERE archived = 0 AND cover_image_url > ''")->fetchAll(PDO::FETCH_COLUMN));
            $optIds = [];
            foreach ($byCode as $code => $c) if (isset($visible[$code])) foreach (($c['i'] ?? []) as $i => $_) $optIds[$i] = true;
            $options = [];
            foreach (array_keys($optIds) as $id) {
                $label = implode(' › ', array_map('folderDisplayName', $up((string) $id)[1]));
                if ($label !== '') $options[] = ['id' => (string) $id, 'label' => $label];
            }
            usort($options, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

            $upd = $db->prepare("UPDATE products SET search_text = ?, contains_text = ?, folder_ids = ? WHERE id = ?");
            $n = 0;
            $db->beginTransaction();
            foreach ($db->query("SELECT id, sku, parent_sku, name, brand, category, color FROM products")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $ids = []; $fnames = []; $files = [];
                foreach (array_unique(array_filter([$p['sku'], $p['parent_sku']])) as $code) {
                    $c = $byCode[$code] ?? [];
                    $ids += $c['i'] ?? []; $fnames += $c['f'] ?? []; $files += $c['n'] ?? [];
                }
                $sku = (string) $p['sku'];
                $search = implode(' ', array_unique(array_filter(array_merge(
                    [$sku, skuSearchNorm($sku), (string) $p['name'], (string) $p['brand'], (string) $p['category'], (string) $p['color']],
                    array_map('folderDisplayName', array_keys($fnames)), array_keys($fnames)
                ), fn($x) => $x !== '')));
                $contains = implode(' ', array_filter(array_merge([(string) $p['name']], array_map('strval', array_keys($files))), fn($x) => $x !== ''));
                $upd->execute([mb_substr($search, 0, 4000), mb_substr($contains, 0, 8000), $ids ? ' ' . implode(' ', array_keys($ids)) . ' ' : '', $p['id']]);
                $n++;
            }
            $meta = $db->prepare("REPLACE INTO search_meta (k, v, updated_at) VALUES (?, ?, NOW())");
            $meta->execute(['folder_options', json_encode($options, JSON_UNESCAPED_UNICODE)]);
            $meta->execute(['built_at', date('c')]);
            $db->commit();
            return ['products' => $n, 'folders' => count($options), 'ms' => (int) ((microtime(true) - $t0) * 1000)];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('SearchIndex::rebuild: ' . $e->getMessage());
            return null;
        }
    }

    /** Carpetas del filtro guardadas en el último armado. @return array<int, array{id: string, label: string}> */
    public static function folderOptions(PDO $db): array
    {
        self::ensure($db);
        try {
            $v = $db->query("SELECT v FROM search_meta WHERE k = 'folder_options'")->fetchColumn();
            $d = $v ? json_decode($v, true) : [];
            return is_array($d) ? $d : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
