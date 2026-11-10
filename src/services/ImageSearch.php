<?php
/**
 * Búsqueda por foto: cada portada (foto) visible se convierte una vez en un vector ("huella") con el
 * modelo de imágenes jina-clip-v2 (API de Jina AI, env JINA_API_KEY) y se guarda en image_embeddings.
 * Al buscar, la foto del usuario se convierte igual y se compara (producto punto; vectores normalizados)
 * contra todas: las más parecidas primero. Sin JINA_API_KEY todo queda apagado (no sale el botón).
 *
 * Indexa el sync (scripts/cli_sync.php imagenes), nunca una página pública: allí solo se embebe la foto subida.
 */
class ImageSearch
{
    public const MODEL = 'jina-clip-v2';
    public const DIM = 512;
    private const API = 'https://api.jina.ai/v1/embeddings';
    private const STATUS_FILE = BASE_DIR . '/storage/image_index_status.json';
    private const BATCH = 8; // 8 fotos de 512 px ≈ 32 000 tokens por llamada

    public static function enabled(): bool
    {
        return env('JINA_API_KEY') !== '';
    }

    /** fileId de una portada guardada (lh3 …/d/{id}=s400); null si es video o tiene otro formato. */
    public static function coverFileId(?string $stored): ?string
    {
        $stored = (string) $stored;
        if ($stored === '' || str_starts_with($stored, '[VIDEO]')) {
            return null;
        }
        return preg_match('#googleusercontent\.com/d/([A-Za-z0-9_-]{10,})#', $stored, $m) ? $m[1] : null;
    }

    /**
     * Vectores de varias imágenes (bytes) en una sola llamada.
     * @param string[] $images
     * @return array<int, float[]> mismo orden que $images
     */
    public static function embedImages(array $images): array
    {
        $input = [];
        foreach ($images as $bytes) {
            $input[] = ['image' => base64_encode($bytes)];
        }
        $ch = curl_init(self::API);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . env('JINA_API_KEY'),
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => self::MODEL,
                'dimensions' => self::DIM,
                'normalized' => true,
                'input' => $input,
            ]),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        if ($code !== 200 || !isset($json['data']) || !is_array($json['data'])) {
            $detail = is_array($json) ? ($json['detail'] ?? $json['message'] ?? '') : '';
            throw new RuntimeException("Jina respondió {$code}" . ($detail ? ': ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . ($err ? " ({$err})" : ''), $code);
        }
        $out = [];
        foreach ($json['data'] as $i => $row) {
            $out[(int) ($row['index'] ?? $i)] = self::unit(array_map('floatval', $row['embedding'] ?? []));
        }
        ksort($out);
        return $out;
    }

    /** Largo 1 (así la comparación es un producto punto), por si la API no lo deja normalizado tras recortar. */
    private static function unit(array $v): array
    {
        $n = sqrt(array_sum(array_map(fn($x) => $x * $x, $v)));
        return $n > 0 ? array_map(fn($x) => $x / $n, $v) : $v;
    }

    private static function pack(array $v): string
    {
        return pack('g*', ...$v);
    }

    /** Descarga varias fotos de lh3 en paralelo. @return array<string, ?string> fileId => bytes|null */
    private static function downloadMany(array $fileIds, int $size = 512): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($fileIds as $id) {
            $ch = curl_init(lh3Url($id, $size));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 8,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        $out = [];
        foreach ($handles as $id => $ch) {
            $body = curl_multi_getcontent($ch);
            $ok = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200
                && str_starts_with(strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)), 'image/')
                && is_string($body) && $body !== '';
            $out[$id] = $ok ? $body : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /**
     * Indexa las portadas visibles que aún no tienen huella. Corta a los $maxSeconds y sigue en el próximo sync.
     * Una foto que no baja queda marcada (embedding NULL) y se reintenta al día siguiente.
     */
    public static function indexPending(PDO $db, int $maxSeconds = 1500): array
    {
        $start = time();
        $db->exec("DELETE FROM image_embeddings WHERE embedding IS NULL AND created_at < NOW() - INTERVAL 1 DAY");
        $have = array_flip($db->query("SELECT file_id FROM image_embeddings")->fetchAll(PDO::FETCH_COLUMN));

        $pending = [];
        $rows = $db->query("SELECT cover_image_url FROM products WHERE archived = 0 AND cover_image_url > ''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $stored) {
            $id = self::coverFileId($stored);
            if ($id !== null && !isset($have[$id])) {
                $pending[$id] = true;
            }
        }
        $pending = array_keys($pending);
        $total = count($pending);
        $done = 0;
        $failed = 0;
        $error = null;
        $ins = $db->prepare("REPLACE INTO image_embeddings (file_id, embedding, model) VALUES (?, ?, ?)");

        foreach (array_chunk($pending, self::BATCH) as $chunk) {
            if (time() - $start > $maxSeconds) {
                break;
            }
            $bytes = self::downloadMany($chunk);
            $okIds = [];
            $images = [];
            foreach ($bytes as $id => $b) {
                if ($b === null) {
                    $ins->execute([$id, null, self::MODEL]);
                    $failed++;
                } else {
                    $okIds[] = $id;
                    $images[] = $b;
                }
            }
            if (!$images) {
                continue;
            }
            try {
                try {
                    $vectors = self::embedImages($images);
                } catch (\RuntimeException $e) {
                    if ($e->getCode() !== 429) {
                        throw $e;
                    }
                    sleep(65); // límite por minuto de la clave (la gratis es baja): esperar y reintentar una vez
                    $vectors = self::embedImages($images);
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage(); // clave mala, sin saldo o caída: se reintenta en el próximo sync
                break;
            }
            foreach ($okIds as $i => $id) {
                if (!empty($vectors[$i])) {
                    $ins->execute([$id, self::pack($vectors[$i]), self::MODEL]);
                    $done++;
                }
            }
        }
        $indexed = (int) $db->query("SELECT COUNT(*) FROM image_embeddings WHERE embedding IS NOT NULL")->fetchColumn();
        return [
            'pendientes' => $total,
            'indexadas_ahora' => $done,
            'fallidas' => $failed,
            'total_indexadas' => $indexed,
            'segundos' => time() - $start,
            'error' => $error,
        ];
    }

    /** Cuántas portadas visibles tienen huella (para el admin). */
    public static function status(PDO $db): array
    {
        $ids = [];
        foreach ($db->query("SELECT cover_image_url FROM products WHERE archived = 0 AND cover_image_url > ''")->fetchAll(PDO::FETCH_COLUMN) as $s) {
            $id = self::coverFileId($s);
            if ($id !== null) {
                $ids[$id] = true;
            }
        }
        $have = 0;
        foreach ($db->query("SELECT file_id FROM image_embeddings WHERE embedding IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (isset($ids[$id])) {
                $have++;
            }
        }
        return ['fotos' => count($ids), 'indexadas' => $have];
    }

    /**
     * Productos visibles más parecidos a la foto subida (bytes), y cuántas portadas ya se pueden comparar.
     * Si todavía no hay ninguna lista, no llama a Jina (no gasta tokens).
     * @return array{results: array<int, array{sku: string, url: string, name: string, thumb: string, score: float}>,
     *               progress: array{fotos: int, indexadas: int}}
     */
    public static function search(PDO $db, string $imageBytes, int $limit = 30): array
    {
        // Portada → productos visibles que la usan (una foto de familia puede ser de varios códigos)
        $byFile = [];
        $rows = $db->query("SELECT sku, name, cover_image_url FROM products WHERE archived = 0 AND cover_image_url > ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $id = self::coverFileId($r['cover_image_url']);
            if ($id !== null) {
                $byFile[$id][] = $r;
            }
        }
        $vectors = [];
        $st = $db->query("SELECT file_id, embedding FROM image_embeddings WHERE embedding IS NOT NULL");
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            if (isset($byFile[$row[0]])) {
                $vectors[$row[0]] = $row[1];
            }
        }
        $progress = ['fotos' => count($byFile), 'indexadas' => count($vectors)];
        if (!$vectors) {
            return ['results' => [], 'progress' => $progress];
        }

        $q = self::embedImages([$imageBytes])[0] ?? [];
        if (!$q) {
            throw new RuntimeException('Respuesta inesperada del modelo');
        }
        $scores = [];
        foreach ($vectors as $fileId => $packed) {
            $dot = 0.0;
            $i = 0;
            foreach (unpack('g*', $packed) as $x) {
                $dot += $x * $q[$i++];
            }
            $scores[$fileId] = $dot;
        }
        arsort($scores);

        $out = [];
        foreach ($scores as $fileId => $score) {
            foreach ($byFile[$fileId] as $p) {
                $out[] = [
                    'sku' => cleanSkuDisplay($p['sku']),
                    'url' => '/producto/' . rawurlencode($p['sku']),
                    'name' => (string) $p['name'],
                    'thumb' => lh3Url($fileId, 300),
                    'score' => round($score, 3),
                ];
                if (count($out) >= $limit) {
                    break 2;
                }
            }
        }
        return ['results' => $out, 'progress' => $progress];
    }

    /** Última vuelta del indexado (la escribe cli_sync.php imagenes; el admin la muestra). */
    public static function lastRun(): ?array
    {
        $raw = @file_get_contents(self::STATUS_FILE);
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    public static function saveLastRun(array $result): void
    {
        @mkdir(dirname(self::STATUS_FILE), 0775, true);
        @file_put_contents(self::STATUS_FILE, json_encode($result + ['at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
    }
}
