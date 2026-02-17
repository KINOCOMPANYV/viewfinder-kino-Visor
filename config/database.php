<?php
/**
 * Conexión a MySQL.
 * Devuelve la instancia PDO singleton.
 */

/**
 * Lee una variable de entorno de forma robusta.
 * En Docker+Apache, getenv() a veces no funciona;
 * hay que revisar también $_ENV y $_SERVER.
 */
function env(string $key, string $default = ''): string
{
    $val = getenv($key);
    if ($val !== false && $val !== '')
        return $val;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '')
        return $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '')
        return $_SERVER[$key];
    return $default;
}

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null)
        return $pdo;

    $host = '127.0.0.1';
    $port = '3306';
    $name = 'viewfinder_kino';
    $user = 'root';
    $pass = '';

    // ========================================
    // PRIORIDAD 1: MYSQL_URL (Railway la auto-genera)
    // Formato: mysql://user:pass@host:port/database
    // ========================================
    $url = env('MYSQL_URL', env('MYSQL_PUBLIC_URL', env('DATABASE_URL', '')));
    if ($url !== '') {
        $parts = parse_url($url);
        if ($parts !== false) {
            $host = $parts['host'] ?? $host;
            $port = (string) ($parts['port'] ?? $port);
            $name = ltrim($parts['path'] ?? "/{$name}", '/');
            $user = $parts['user'] ?? $user;
            $pass = $parts['pass'] ?? $pass;
        }
    } else {
        // PRIORIDAD 2: Variables individuales
        $h = env('DB_HOST', env('MYSQLHOST', env('MYSQL_HOST', '')));
        if ($h !== '')
            $host = $h;
        $p = env('DB_PORT', env('MYSQLPORT', env('MYSQL_PORT', '')));
        if ($p !== '')
            $port = $p;
        $n = env('DB_DATABASE', env('MYSQLDATABASE', env('MYSQL_DATABASE', '')));
        if ($n !== '')
            $name = $n;
        $u = env('DB_USERNAME', env('MYSQLUSER', env('MYSQL_USER', '')));
        if ($u !== '')
            $user = $u;
        $pw = env('DB_PASSWORD', env('MYSQLPASSWORD', env('MYSQL_PASSWORD', '')));
        if ($pw !== '')
            $pass = $pw;
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
