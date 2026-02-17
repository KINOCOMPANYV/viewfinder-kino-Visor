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
    // 1) getenv (funciona en CLI y algunos SAPI)
    $val = getenv($key);
    if ($val !== false && $val !== '')
        return $val;

    // 2) $_ENV (requiere variables_order con E)
    if (isset($_ENV[$key]) && $_ENV[$key] !== '')
        return $_ENV[$key];

    // 3) $_SERVER (Apache pasa env vars aquí)
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '')
        return $_SERVER[$key];

    return $default;
}

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null)
        return $pdo;

    // Railway provee: MYSQLHOST, MYSQL_HOST, DB_HOST (según config)
    $host = env('DB_HOST', env('MYSQLHOST', env('MYSQL_HOST', '127.0.0.1')));
    $port = env('DB_PORT', env('MYSQLPORT', env('MYSQL_PORT', '3306')));
    $name = env('DB_DATABASE', env('MYSQLDATABASE', env('MYSQL_DATABASE', 'viewfinder_kino')));
    $user = env('DB_USERNAME', env('MYSQLUSER', env('MYSQL_USER', 'root')));
    $pass = env('DB_PASSWORD', env('MYSQLPASSWORD', env('MYSQL_PASSWORD', '')));

    // También intentar parsear MYSQL_URL si nada funcionó
    if ($host === '127.0.0.1') {
        $url = env('MYSQL_URL', env('DATABASE_URL', ''));
        if ($url !== '') {
            $parts = parse_url($url);
            $host = $parts['host'] ?? $host;
            $port = (string) ($parts['port'] ?? $port);
            $name = ltrim($parts['path'] ?? "/{$name}", '/');
            $user = $parts['user'] ?? $user;
            $pass = $parts['pass'] ?? $pass;
        }
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
