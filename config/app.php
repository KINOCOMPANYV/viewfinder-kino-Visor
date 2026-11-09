<?php
/**
 * Configuración general de la aplicación Viewfinder Kino Visor.
 */

// Cargar .env si existe (para desarrollo local)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        if (strpos($line, '=') === false)
            continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// Constantes de la app
define('BASE_DIR', dirname(__DIR__));
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost:8080');
define('APP_SECRET', getenv('APP_SECRET') ?: 'dev-secret-cambiar');

// Admin
define('ADMIN_USER', env('ADMIN_USER', 'admin'));
// Password del admin: ADMIN_PASSWORD_HASH (bcrypt) si está definido; si no, ADMIN_PASSWORD.
// La comprobación se hace solo al entrar (adminPasswordMatches): calcular un bcrypt en cada
// petición costaba ~60 ms de CPU en todas las páginas y llamadas de la API.
$passHash = env('ADMIN_PASSWORD_HASH', '');
// true si NO hay password configurado por env (se está usando el default "admin123")
define('ADMIN_PASSWORD_IS_DEFAULT', $passHash === '' && env('ADMIN_PASSWORD', '') === '');

function adminPasswordMatches(string $pass): bool
{
    $hash = env('ADMIN_PASSWORD_HASH', '');
    if ($hash !== '') {
        return password_verify($pass, $hash);
    }
    return hash_equals((string) env('ADMIN_PASSWORD', 'admin123'), $pass);
}

// Detectar HTTPS detrás de proxy
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// Cache buster — basado en timestamp de deploy (se regenera cada build)
define('APP_VERSION', substr(md5(filemtime(__FILE__) . filemtime(BASE_DIR . '/assets/css/style.css')), 0, 8));

// App info
define('APP_BUILD', '2026-04-06');
