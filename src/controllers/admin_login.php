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

if ($user === ADMIN_USER && adminPasswordMatches($pass)) {
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
    $_SESSION['login_error'] = 'Usuario o contraseña incorrectos';
    redirect('/admin/login');
}
