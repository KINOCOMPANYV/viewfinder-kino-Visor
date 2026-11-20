<?php
/**
 * Índice de búsqueda del catálogo (migración 032). Buscar y filtrar leen solo columnas de `products`
 * (search_text, contains_text, folder_ids) en vez de cruzar drive_index en cada consulta (eso tardaba 30+ s).
 * Se rehace completo tras cada sincronización (cada hora con el sync automático) y cuando cambian carpetas ocultas.
 * También guarda en search_meta las carpetas del filtro (folder_options) y la hora del último armado (built_at).
 */
class SearchIndex
{
    /**
     * ¿Ya hay índice armado? Las páginas NUNCA lo arman (eso trabó el arranque en Railway el 2026-11-20):
     * lo arma el sync en segundo plano. Mientras no exista, la búsqueda usa solo columnas propias de products.
     */
    public static function ready(PDO $db): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $ready = (bool) $db->query("SELECT 1 FROM search_meta WHERE k = 'built_at'")->fetchColumn();
        } catch (Throwable $e) {
            $ready = false; // migración 032 aún no aplicada
        }
        return $ready;
    }

    /** @return array{products: int, folders: int, ms: int}|null */
    public static function rebuild(PDO $db): ?array
    {
        $t0 = microtime(true);
        // Uno a la vez (sync, admin y arranque pueden pedirlo juntos); si ya se está armando, no esperar
        try {
            if (!(int) $db->query("SELECT GET_LOCK('kino_search_index', 0)")->fetchColumn()) return null;
        } catch (Throwable $e) {
            return null;
        }
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
            // Carpetas para el filtro: las que tienen fotos de productos visibles (y sus superiores), solo hasta
            // 2 niveles («AUTOMATICOS › 427»; no «› 427-1»). La de nivel 2 ya incluye todo lo de sus subcarpetas
            // porque folder_ids guarda también las carpetas superiores (decisión del dueño 2026-11-20).
            $visible = array_flip($db->query("SELECT sku FROM products WHERE archived = 0 AND cover_image_url > ''")->fetchAll(PDO::FETCH_COLUMN));
            $optIds = [];
            foreach ($byCode as $code => $c) if (isset($visible[$code])) foreach (($c['i'] ?? []) as $i => $_) $optIds[$i] = true;
            $options = [];
            foreach (array_keys($optIds) as $id) {
                $names = $up((string) $id)[1];
                if (count($names) > 2) continue;
                $label = implode(' › ', array_map('folderDisplayName', $names));
                if ($label !== '') $options[] = ['id' => (string) $id, 'label' => $label];
            }
            usort($options, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

            // En tandas de 300 con un solo UPDATE … CASE (6000 UPDATE sueltos por red tardaban demasiado)
            $rows = [];
            foreach ($db->query("SELECT id, sku, parent_sku, name, brand, category, color FROM products")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $ids = []; $fnames = []; $files = [];
                foreach (array_unique(array_filter([$p['sku'], $p['parent_sku']])) as $code) {
                    $c = $byCode[$code] ?? [];
                    $ids += $c['i'] ?? []; $fnames += $c['f'] ?? []; $files += $c['n'] ?? [];
                }
                $sku = (string) $p['sku'];
                $search = implode(' ', array_unique(array_filter(array_merge(
                    [$sku, skuSearchNorm($sku), (string) $p['name'], (string) $p['brand'], (string) $p['category'], (string) $p['color']],
                    array_map('folderDisplayName', array_keys($fnames)), array_map('strval', array_keys($fnames))
                ), fn($x) => $x !== '')));
                $contains = implode(' ', array_filter(array_merge([(string) $p['name']], array_map('strval', array_keys($files))), fn($x) => $x !== ''));
                $rows[] = [(int) $p['id'], mb_substr($search, 0, 4000), mb_substr($contains, 0, 8000), $ids ? ' ' . implode(' ', array_keys($ids)) . ' ' : ''];
            }
            foreach (array_chunk($rows, 300) as $chunk) {
                $case = str_repeat('WHEN ? THEN ? ', count($chunk));
                $params = [];
                foreach ([1, 2, 3] as $col) foreach ($chunk as $r) array_push($params, $r[0], $r[$col]);
                foreach ($chunk as $r) $params[] = $r[0];
                $db->prepare("UPDATE products SET search_text = CASE id {$case}END, contains_text = CASE id {$case}END,
                              folder_ids = CASE id {$case}END WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")")
                   ->execute($params);
            }
            $n = count($rows);
            $meta = $db->prepare("REPLACE INTO search_meta (k, v, updated_at) VALUES (?, ?, NOW())");
            $meta->execute(['folder_options', json_encode($options, JSON_UNESCAPED_UNICODE)]);
            $meta->execute(['built_at', date('c')]);
            $db->query("SELECT RELEASE_LOCK('kino_search_index')");
            return ['products' => $n, 'folders' => count($options), 'ms' => (int) ((microtime(true) - $t0) * 1000)];
        } catch (Throwable $e) {
            try { $db->query("SELECT RELEASE_LOCK('kino_search_index')"); } catch (Throwable $e2) {}
            error_log('SearchIndex::rebuild: ' . $e->getMessage());
            return null;
        }
    }

    /** Carpetas del filtro guardadas en el último armado. @return array<int, array{id: string, label: string}> */
    public static function folderOptions(PDO $db): array
    {
        if (!self::ready($db)) return []; // aparecen tras el primer sync (un minuto después del deploy)
        try {
            $v = $db->query("SELECT v FROM search_meta WHERE k = 'folder_options'")->fetchColumn();
            $d = $v ? json_decode($v, true) : [];
            return is_array($d) ? $d : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
