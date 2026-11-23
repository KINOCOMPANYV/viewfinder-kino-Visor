<?php
/**
 * Registro de errores y de búsquedas sin resultado (2026-11-23, decisión del dueño: medir antes de agregar más).
 * Todo va a MySQL (migración 036) y se ve en Admin → 🧾 Registro. Gratis, sin servicios externos (Sentry no hace falta).
 *   install()       PHP: avisos (warning/notice) y errores fatales (incluida excepción no atrapada). No cambia lo que ve el usuario.
 *   jsCatcher()     <script> en el <head> de cada página: errores de JavaScript → POST /api/error-js (máx. 5 por página).
 *   recordJs()      guarda lo que manda ese script.
 *   searchMiss()    búsqueda pública sin nada (ni productos ni carpetas). Mientras se escribe ("relo" → "relog")
 *                   queda solo el término más largo de esa persona en el último minuto.
 * Un mismo error se guarda una vez (huella) y se cuenta (count, last_seen). Nunca rompe la página: si la tabla
 * no existe o MySQL falla, se va al log de PHP (Railway) y sigue.
 */
class ErrorLog
{
    private static bool $busy = false;

    public static function install(): void
    {
        set_error_handler([self::class, 'onError']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    /** Avisos de PHP. Devuelve false: PHP sigue con su manejo normal (log de Railway). */
    public static function onError(int $no, string $msg, string $file = '', int $line = 0): bool
    {
        // Respeta el @ y error_reporting; lo "deprecated" no se guarda (solo ruido de versiones)
        if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return false;
        }
        self::record('php', self::levelName($no), $msg, $file, $line);
        return false;
    }

    public static function onShutdown(): void
    {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            self::record('php', self::levelName($e['type']), $e['message'], $e['file'], (int) $e['line']);
        }
    }

    public static function record(string $source, string $level, string $message, string $file = '', int $line = 0, string $url = '', string $ua = ''): void
    {
        if (self::$busy) {
            return; // un error al guardar el error no se vuelve a guardar
        }
        self::$busy = true;
        try {
            $root = dirname(__DIR__, 2) . '/';
            $file = str_replace($root, '', $file);
            $message = mb_substr(trim($message), 0, 4000);
            if ($url === '') {
                $url = PHP_SAPI === 'cli'
                    ? 'cli: ' . implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 3))
                    : ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '');
            }
            if ($ua === '' && PHP_SAPI !== 'cli') {
                $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            }
            // Huella sin números variables (ids, tamaños, tiempos), para que el mismo error se cuente junto
            $fp = sha1($source . '|' . $level . '|' . preg_replace('/\d+/', '#', mb_substr($message, 0, 300)) . '|' . $file . '|' . $line);
            $db = getDB();
            $db->prepare("INSERT INTO app_errors (fingerprint, source, level, message, file, line, url, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE count = count + 1, last_seen = CURRENT_TIMESTAMP,
                        message = VALUES(message), url = VALUES(url), user_agent = VALUES(user_agent)")
                ->execute([$fp, $source, mb_substr($level, 0, 30), $message, mb_substr($file, 0, 255), $line,
                    mb_substr($url, 0, 500), mb_substr($ua, 0, 255)]);
        } catch (\Throwable $e) {
            error_log('ErrorLog: no se pudo guardar (' . $e->getMessage() . ')');
        } finally {
            self::$busy = false;
        }
    }

    /** Lo que manda el navegador (POST /api/error-js). */
    public static function recordJs(array $in): void
    {
        $msg = trim((string) ($in['msg'] ?? ''));
        $src = (string) ($in['src'] ?? '');
        // "Script error." = error de otro dominio sin detalles; extensiones del navegador: no son del visor
        if ($msg === '' || stripos($msg, 'Script error') === 0 || preg_match('#^(chrome|moz|safari)(-web)?-extension://#', $src)) {
            return;
        }
        $stack = trim((string) ($in['stack'] ?? ''));
        $full = $msg . ($stack !== '' && strpos($msg, $stack) === false ? "\n" . mb_substr($stack, 0, 1500) : '');
        $src = preg_replace('#^https?://[^/]+#', '', $src);
        $src = preg_replace('/\?.*$/', '', $src); // sin ?v=… para no separar el mismo error por versión
        self::record('js', mb_substr((string) ($in['kind'] ?? 'error'), 0, 30), $full, $src, (int) ($in['line'] ?? 0),
            mb_substr((string) ($in['url'] ?? ''), 0, 500));
    }

    /** <script> para el <head>: atrapa errores de JS y promesas rechazadas y los manda (sin cookies de terceros). */
    public static function jsCatcher(): string
    {
        return <<<'HTML'
<script>(function(){var n=0;function s(k,m,f,l,st){if(n++>=5||!m)return;try{var b=JSON.stringify({kind:k,msg:String(m).slice(0,1000),src:f||'',line:l||0,stack:st?String(st).slice(0,1500):'',url:location.pathname+location.search});if(navigator.sendBeacon){navigator.sendBeacon('/api/error-js',new Blob([b],{type:'application/json'}));}else{fetch('/api/error-js',{method:'POST',body:b,headers:{'Content-Type':'application/json'},keepalive:true});}}catch(e){}}
window.addEventListener('error',function(e){if(e.target&&e.target!==window){return;}s('error',e.message,e.filename,e.lineno,e.error&&e.error.stack);});
window.addEventListener('unhandledrejection',function(e){var r=e.reason;if(r&&r.name==='AbortError')return;s('promise',r&&r.message||r,'',0,r&&r.stack);});})();</script>
HTML;
    }

    /**
     * Pone jsCatcher() dentro del <head> de las páginas HTML (todas las del visor, sin tocar 27 plantillas).
     * Búfer por tramos de 64 KB: si lo primero que sale no es HTML (descargas, JSON, imágenes) pasa tal cual.
     */
    public static function startPageCapture(): void
    {
        $done = false;
        ob_start(function (string $chunk) use (&$done) {
            if ($done || $chunk === '') {
                return $chunk;
            }
            $done = true;
            $head = ltrim(substr($chunk, 0, 200));
            if (stripos($head, '<!doctype html') !== 0 && stripos($head, '<html') !== 0) {
                return $chunk;
            }
            $pos = stripos($chunk, '</head>');
            return $pos === false ? $chunk : substr($chunk, 0, $pos) . self::jsCatcher() . substr($chunk, $pos);
        }, 65536);
    }

    /** Búsqueda pública sin resultados. $q ya viene recortado. */
    public static function searchMiss(PDO $db, string $q): void
    {
        $term = mb_substr(trim(preg_replace('/\s+/u', ' ', $q)), 0, 120);
        $key = mb_strtolower($term);
        if (mb_strlen($key) < 2 || self::isBot()) {
            return;
        }
        try {
            $sid = substr(sha1(session_id() ?: getClientIP()), 0, 12);
            // Mientras escribe: si en el último minuto ya buscó algo más largo que empieza igual, no cuenta este
            $st = $db->prepare("SELECT 1 FROM search_misses WHERE sid = ? AND created_at > NOW() - INTERVAL 60 SECOND
                AND term_key LIKE ? LIMIT 1");
            $st->execute([$sid, addcslashes($key, '%_\\') . '%']);
            if ($st->fetchColumn()) {
                return;
            }
            // …y lo más corto que escribió antes ("relo") se reemplaza por esto ("relog")
            $db->prepare("DELETE FROM search_misses WHERE sid = ? AND created_at > NOW() - INTERVAL 60 SECOND
                AND LEFT(?, CHAR_LENGTH(term_key)) = term_key")
                ->execute([$sid, $key]);
            $db->prepare("INSERT INTO search_misses (term, term_key, sid) VALUES (?, ?, ?)")->execute([$term, $key, $sid]);
            if (random_int(1, 200) === 1) { // limpieza ocasional: se guarda un año
                $db->exec("DELETE FROM search_misses WHERE created_at < NOW() - INTERVAL 365 DAY");
            }
        } catch (\Throwable $e) { /* migración 036 pendiente */ }
    }

    private static function isBot(): bool
    {
        return (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|preview/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    private static function levelName(int $no): string
    {
        return [
            E_ERROR => 'fatal', E_PARSE => 'parse', E_CORE_ERROR => 'fatal', E_COMPILE_ERROR => 'fatal',
            E_USER_ERROR => 'fatal', E_RECOVERABLE_ERROR => 'fatal', E_WARNING => 'warning', E_USER_WARNING => 'warning',
            E_NOTICE => 'notice', E_USER_NOTICE => 'notice', E_CORE_WARNING => 'warning', E_COMPILE_WARNING => 'warning',
        ][$no] ?? (string) $no;
    }
}
