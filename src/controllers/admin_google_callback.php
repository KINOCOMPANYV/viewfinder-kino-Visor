<?php
/**
 * Google OAuth Callback — Intercambia code por tokens.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

$code = $_GET['code'] ?? '';
if (empty($code)) {
    $_SESSION['flash_error'] = 'No se recibió código de autorización.';
    redirect('/admin');
}

$drive = new GoogleDriveService();
$tokens = $drive->exchangeCode($code);

if (empty($tokens['access_token'])) {
    $err = $tokens['error_description'] ?? $tokens['error'] ?? 'Error desconocido';
    $_SESSION['flash_error'] = "Error autenticando con Google: {$err}";
    redirect('/admin');
}

// Guardar tokens en base de datos
$db = getDB();

// Eliminar tokens anteriores
$db->exec("DELETE FROM google_tokens");

$expiresAt = date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600));
$stmt = $db->prepare("INSERT INTO google_tokens (access_token, refresh_token, expires_at) VALUES (?, ?, ?)");
$stmt->execute([
    $tokens['access_token'],
    $tokens['refresh_token'] ?? '',
    $expiresAt,
]);

$_SESSION['flash_success'] = '✅ Google Drive conectado exitosamente.';
redirect('/admin/media');
