<?php
/**
 * Admin Login — procesa credenciales con password_verify (bcrypt).
 */
$user = trim($_POST['username'] ?? '');
$pass = $_POST['password'] ?? '';

if ($user === ADMIN_USER && password_verify($pass, ADMIN_PASSWORD_HASH)) {
    // Regenerar session ID para prevenir session fixation
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    redirect('/admin');
} else {
    $_SESSION['login_error'] = 'Usuario o contraseña incorrectos';
    redirect('/admin/login');
}
