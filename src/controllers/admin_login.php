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

if ($user === ADMIN_USER && password_verify($pass, ADMIN_PASSWORD_HASH)) {
    // Regenerar session ID para prevenir session fixation
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    redirect('/admin');
} else {
    $_SESSION['login_error'] = 'Usuario o contraseña incorrectos';
    redirect('/admin/login');
}
