<?php
/**
 * Google OAuth Callback — Intercambia code por tokens.
 * Cualquier error vuelve a /admin/media, que es donde se muestra el aviso.
 */
require_once __DIR__ . '/../services/GoogleDriveService.php';

$fail = function (string $msg): void {
    error_log('[google-callback] ' . $msg);
    $_SESSION['flash_error'] = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
        . ' — Pulsa «Conectar Google Drive» para intentarlo de nuevo.';
    redirect('/admin/media');
};

// El usuario canceló o Google devolvió un error en la pantalla de permisos.
if (!empty($_GET['error'])) {
    $fail('Google no autorizó la conexión: ' . $_GET['error']);
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    $fail('No se recibió código de autorización de Google.');
}

// Google debe haber dado los dos permisos (Drive y Hojas).
$scope = (string) ($_GET['scope'] ?? '');
if ($scope !== '' && !str_contains($scope, '/auth/drive')) {
    $fail('Falta el permiso de Google Drive: marca todas las casillas en la pantalla de Google.');
}

$drive = new GoogleDriveService();
$tokens = $drive->exchangeCode($code);

if (empty($tokens['access_token'])) {
    $err = $tokens['error_description'] ?? $tokens['error'] ?? 'sin respuesta de Google';
    if (($tokens['error'] ?? '') === 'invalid_grant') {
        $err .= ' (el código ya se usó o caducó; recargar esta página no sirve)';
    } elseif (($tokens['error'] ?? '') === 'redirect_uri_mismatch') {
        $err .= ' (revisa GOOGLE_REDIRECT_URI en Railway)';
    } elseif (($tokens['error'] ?? '') === 'invalid_client') {
        $err .= ' (revisa GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET en Railway)';
    }
    $fail('Error autenticando con Google: ' . $err);
}

$db = getDB();

// Si Google no manda refresh_token (pasa al reconectar la misma cuenta),
// se conserva el anterior; sin él la conexión caducaría en 1 hora.
$refresh = (string) ($tokens['refresh_token'] ?? '');
if ($refresh === '') {
    $old = $db->query("SELECT refresh_token FROM google_tokens ORDER BY id DESC LIMIT 1")->fetchColumn();
    $refresh = (string) ($old ?: '');
}

$expiresAt = date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600));
$db->beginTransaction();
$db->exec("DELETE FROM google_tokens");
$stmt = $db->prepare("INSERT INTO google_tokens (access_token, refresh_token, expires_at) VALUES (?, ?, ?)");
$stmt->execute([$tokens['access_token'], $refresh, $expiresAt]);
$db->commit();

$_SESSION['flash_success'] = $refresh === ''
    ? '⚠️ Google Drive conectado, pero sin token de renovación: caducará en 1 hora. Quita el acceso de la app en myaccount.google.com/permissions y vuelve a conectar.'
    : '✅ Google Drive conectado exitosamente.';
redirect('/admin/media');
