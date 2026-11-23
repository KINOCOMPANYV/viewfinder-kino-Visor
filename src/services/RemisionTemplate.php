<?php
/**
 * Plantilla editable de la Remisión (Extractor → 🚚 Remisión, PDF en assets/js/order_list.js).
 * Guardada en doc_templates (name = 'remision') como JSON: título, colores, líneas del pie, marcas y logos.
 * Logos: lista ordenada [{id, name, side: left|right, src}]; src = archivo por defecto (/assets/img/remision/…)
 * o imagen subida en el admin (data:image/png|jpeg;base64,…). Lo público recibe los subidos por
 * /lista-pedido/logo/{id}?v=… para no inflar la página. Editor: Admin → 🚚 Remisión (/admin/remision).
 */
class RemisionTemplate
{
    const NAME = 'remision';
    const MAX_LOGOS = 8;
    const MAX_LOGO_BYTES = 700000; // por logo, ya en base64

    public static function defaults(): array
    {
        return [
            'title' => 'Cotización',
            'numPrefix' => 'Nº',
            'headerColor' => '#0e0e0e',
            'accentColor' => '#d3232d',
            'footer' => [
                'Cra. 53 # 45-15 · Centro Comercial Multivariedades, piso 6',
                'Medellín, Antioquia, Colombia · Contacto: +57 318 564 0716',
                'ventas@kinocompanysas.com.co',
            ],
            'brands' => 'Joefox · Sanse · T5 · Mini Focus · Skmei · Kardoo · Extri',
            'logos' => [
                ['id' => 'kino', 'name' => 'KINO Company', 'side' => 'left', 'src' => '/assets/img/remision/kino.png'],
                ['id' => 'curren', 'name' => 'CURREN', 'side' => 'right', 'src' => '/assets/img/remision/curren.png'],
                ['id' => 'poedagar', 'name' => 'Poedagar', 'side' => 'right', 'src' => '/assets/img/remision/poedagar.png'],
                ['id' => 'joefox', 'name' => 'JOEFOX', 'side' => 'right', 'src' => '/assets/img/remision/joefox.png'],
            ],
        ];
    }

    /** Plantilla guardada (o la de fábrica si no hay). Sin la tabla (migración pendiente) también da la de fábrica. */
    public static function load(PDO $db): array
    {
        try {
            $st = $db->prepare("SELECT data, updated_at FROM doc_templates WHERE name = ?");
            $st->execute([self::NAME]);
            $row = $st->fetch();
        } catch (\Throwable $e) {
            $row = null;
        }
        $t = self::defaults();
        if ($row) {
            $saved = json_decode((string) $row['data'], true);
            if (is_array($saved)) $t = self::clean($saved);
            // 2026-11-23: el documento pasó a llamarse «Cotización»; un título viejo guardado como «Remisión» se muestra con el nombre nuevo.
            if (preg_match('/^remisi[oó]n$/iu', trim((string) $t['title']))) $t['title'] = self::defaults()['title'];
            $t['updatedAt'] = $row['updated_at'];
        }
        return $t;
    }

    /** Valida y normaliza lo que manda el editor. Lanza RuntimeException si algo no sirve. */
    public static function clean(array $in): array
    {
        $d = self::defaults();
        $str = fn($v, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($v ?? ''))), 0, $max);
        $color = fn($v, $def) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? strtolower($v) : $def;
        $t = [
            'title' => $str($in['title'] ?? $d['title'], 40),
            'numPrefix' => $str($in['numPrefix'] ?? $d['numPrefix'], 20),
            'headerColor' => $color($in['headerColor'] ?? '', $d['headerColor']),
            'accentColor' => $color($in['accentColor'] ?? '', $d['accentColor']),
            'footer' => [],
            'brands' => $str($in['brands'] ?? '', 300),
            'logos' => [],
        ];
        foreach (array_slice((array) ($in['footer'] ?? []), 0, 4) as $l) $t['footer'][] = $str($l, 160);
        while (count($t['footer']) && end($t['footer']) === '') array_pop($t['footer']);
        $ids = [];
        foreach ((array) ($in['logos'] ?? []) as $l) {
            if (!is_array($l)) continue;
            $src = (string) ($l['src'] ?? '');
            $ok = preg_match('#^/assets/img/remision/[a-z0-9_-]+\.png$#', $src)
                || (preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $src) && strlen($src) <= self::MAX_LOGO_BYTES);
            if (!$ok) throw new RuntimeException('El logo «' . $str($l['name'] ?? '', 40) . '» no es una imagen válida o pesa demasiado.');
            $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($l['id'] ?? ''))) ?: 'logo';
            while (isset($ids[$id])) $id .= 'x';
            $ids[$id] = true;
            $t['logos'][] = ['id' => $id, 'name' => $str($l['name'] ?? 'Logo', 40) ?: 'Logo',
                'side' => ($l['side'] ?? '') === 'left' ? 'left' : 'right', 'src' => $src];
        }
        if (count($t['logos']) > self::MAX_LOGOS) throw new RuntimeException('Máximo ' . self::MAX_LOGOS . ' logos.');
        if ($t['title'] === '') $t['title'] = $d['title'];
        return $t;
    }

    public static function save(PDO $db, array $in): array
    {
        $t = self::clean($in);
        $db->prepare("INSERT INTO doc_templates (name, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)")
            ->execute([self::NAME, json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        return self::load($db);
    }

    public static function reset(PDO $db): void
    {
        $db->prepare("DELETE FROM doc_templates WHERE name = ?")->execute([self::NAME]);
    }

    /** Para la página pública: los logos subidos se cambian por su URL corta (con versión para la caché). */
    public static function forPublic(array $t): array
    {
        foreach ($t['logos'] as &$l) {
            if (str_starts_with($l['src'], 'data:')) $l['src'] = '/lista-pedido/logo/' . $l['id'] . '?v=' . substr(md5($l['src']), 0, 8);
        }
        unset($l, $t['updatedAt']);
        return $t;
    }

    /** Sirve un logo subido (GET /lista-pedido/logo/{id}). */
    public static function serveLogo(PDO $db, string $id): void
    {
        foreach (self::load($db)['logos'] as $l) {
            if ($l['id'] === $id && preg_match('#^data:(image/(?:png|jpeg));base64,(.+)$#', $l['src'], $m)) {
                $bin = base64_decode($m[2]);
                header('Content-Type: ' . $m[1]);
                header('Cache-Control: public, max-age=31536000, immutable');
                header('Content-Length: ' . strlen($bin));
                echo $bin;
                exit;
            }
        }
        http_response_code(404);
        exit;
    }
}
