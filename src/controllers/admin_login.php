<?php
/**
 * Admin Login — procesa credenciales.
 */
$user = trim($_POST['username'] ?? '');
$pass = $_POST['password'] ?? '';

if ($user === ADMIN_USER && $pass === ADMIN_PASSWORD) {
    $_SESSION['admin_logged_in'] = true;
    redirect('/admin');
} else {
    $_SESSION['login_error'] = 'Usuario o contraseña incorrectos';
    redirect('/admin/login');
}
