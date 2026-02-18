<?php
/**
 * Funciones helper reutilizables.
 */

/**
 * Devuelve JSON y termina la ejecución.
 */
function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Redirige a una URL.
 */
function redirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

/**
 * Escapa HTML para prevenir XSS.
 */
function e(?string $text): string
{
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Obtiene la IP real del cliente (detrás de proxy).
 */
function getClientIP(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}

/**
 * Verifica si el admin está autenticado.
 */
function isAdminLoggedIn(): bool
{
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Requiere autenticación de admin. Redirige si no está logueado.
 */
function requireAdmin(): void
{
    if (!isAdminLoggedIn()) {
        redirect('/admin/login');
    }
}

/**
 * Genera un token CSRF y lo guarda en sesión.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica el token CSRF del formulario.
 */
function verifyCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Formatea un precio.
 */
function formatPrice(float $price): string
{
    return '$' . number_format($price, 2, '.', ',');
}

/**
 * Trunca un texto a N caracteres.
 */
function truncate(string $text, int $length = 100): string
{
    if (mb_strlen($text) <= $length)
        return $text;
    return mb_substr($text, 0, $length) . '...';
}

/**
 * Verifica si un nombre de archivo coincide con un SKU usando regla de prefijo.
 * 
 * Regla: El nombre del archivo debe comenzar con el SKU y el siguiente carácter
 * (si existe) NO puede ser un dígito. Esto evita que "123-1" coincida con "123-10".
 * 
 * Ejemplos para SKU "123-1":
 *   ✅ 123-1.jpg     (SKU + extensión)
 *   ✅ 123-1f1.jpg   (SKU + letra + ...)
 *   ✅ 123-1v2.jpg   (SKU + letra + ...)
 *   ✅ 123-1_foto.jpg(SKU + guion bajo + ...)
 *   ❌ 123-10.jpg    (SKU + dígito = otro producto)
 *   ❌ 123-12.jpg    (SKU + dígito = otro producto)
 *   ❌ 456-1.jpg     (SKU diferente)
 */
function skuMatchesFilename(string $sku, string $filename): bool
{
    // Quitar extensión del nombre para comparar
    $nameOnly = pathinfo($filename, PATHINFO_FILENAME);

    // Verificar que el nombre comience con el SKU (case-insensitive)
    if (stripos($nameOnly, $sku) !== 0) {
        return false;
    }

    // Si el nombre es exactamente el SKU, es match
    if (strlen($nameOnly) === strlen($sku)) {
        return true;
    }

    // El carácter siguiente al SKU NO puede ser un dígito
    $nextChar = $nameOnly[strlen($sku)];
    return !ctype_digit($nextChar);
}
