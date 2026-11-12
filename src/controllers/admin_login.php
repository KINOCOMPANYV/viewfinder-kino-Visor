<?php
/**
 * Admin Login — procesa credenciales con password_verify (bcrypt).
 */
$user = trim($_POST['username'] ?? '');
$pass = $_POST['password'] ?? '';

// En producción no se permite entrar con el password por defecto (admin123):
// obliga a configurar ADMIN_PASSWORD o ADMIN_PASSWORD_HASH en las variables de entorno.
if (APP_ENV === 'production' && ADMIN_PASSWORD_IS_DEFAULT) {
    $_SESSION['login_error'] = 'Login deshabilitado: configura ADMIN_PASSWORD en las variables de entorno del servidor.';
    redirect('/admin/login');
}

// Límite de intentos: 10 fallos por IP en 15 minutos bloquean el login hasta que pase la ventana
// (frena a quien prueba contraseñas en masa). Contador en disco, como checkRateLimit().
$failDir = __DIR__ . '/../../storage/ratelimit';
if (!is_dir($failDir)) @mkdir($failDir, 0775, true);
$failFile = $failDir . '/login_' . md5(getClientIP()) . '.json';
$fails = array_values(array_filter(
    json_decode((string) @file_get_contents($failFile), true) ?: [],
    fn($t) => is_int($t) && $t > time() - 900
));
if (count($fails) >= 10) {
    $_SESSION['login_error'] = 'Demasiados intentos fallidos. Espera 15 minutos y vuelve a intentar.';
    redirect('/admin/login');
}

if ($user === ADMIN_USER && adminPasswordMatches($pass)) {
    @unlink($failFile);
    // Regenerar session ID para prevenir session fixation
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    // Volver a la página que pidió login (solo rutas internas /admin...).
    $next = $_SESSION['after_login'] ?? '/admin';
    unset($_SESSION['after_login']);
    if (!is_string($next) || !str_starts_with($next, '/admin') || str_starts_with($next, '//')) {
        $next = '/admin';
    }
    redirect($next);
} else {
    $fails[] = time();
    @file_put_contents($failFile, json_encode($fails), LOCK_EX);
    $_SESSION['login_error'] = 'Usuario o contraseña incorrectos';
    redirect('/admin/login');
}
