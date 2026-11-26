<?php
/**
 * Usuarios y roles (v2, módulo 1). Tabla `users` (migración 039).
 * Niveles del plan: cliente 1 · facturacion 2 · bodega 3 · admin 4 · dev 5; vendedor es un perfil comercial aparte (rango 1).
 * Regla de gestión: quien gestiona solo puede tocar cuentas de rango MENOR al suyo (admin → hasta bodega; dev → todas).
 */
class Users
{
    public const ROLES = [
        'cliente'     => ['Cliente (nivel 1)', 1],
        'vendedor'    => ['Vendedor (perfil comercial)', 1],
        'facturacion' => ['Facturación (nivel 2)', 2],
        'bodega'      => ['Gestor de bodega (nivel 3)', 3],
        'admin'       => ['Administrador (nivel 4)', 4],
        'dev'         => ['Desarrollador (nivel 5)', 5],
    ];

    public static function rank(string $role): int
    {
        return self::ROLES[$role][1] ?? 0;
    }

    public static function label(string $role): string
    {
        return self::ROLES[$role][0] ?? $role;
    }

    /** Roles que quien tiene `$actorRole` puede asignar o gestionar. */
    public static function manageable(string $actorRole): array
    {
        $r = self::rank($actorRole);
        return array_keys(array_filter(self::ROLES, fn($v) => $v[1] < $r));
    }

    public static function validUsername(string $u): bool
    {
        return (bool) preg_match('/^[a-z0-9._-]{3,40}$/', $u);
    }

    public static function find(PDO $db, int $id): ?array
    {
        $s = $db->prepare("SELECT id, username, name, role, is_active FROM users WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Valida usuario y clave; devuelve la fila activa o null. */
    public static function authenticate(PDO $db, string $username, string $password): ?array
    {
        try {
            $s = $db->prepare("SELECT id, username, name, role, is_active, password_hash FROM users WHERE username = ?");
            $s->execute([strtolower($username)]);
            $u = $s->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null; // tabla aún sin migrar
        }
        if (!$u || !(int) $u['is_active'] || !password_verify($password, $u['password_hash'])) {
            return null;
        }
        $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$u['id']]);
        unset($u['password_hash']);
        return $u;
    }

    /** @return string|null mensaje de error, o null si se creó */
    public static function create(PDO $db, string $username, string $name, string $role, string $password, string $actorRole): ?string
    {
        $username = strtolower(trim($username));
        if (!self::validUsername($username)) return 'Usuario: 3 a 40 letras minúsculas, números, punto, guion o guion bajo.';
        if (defined('ADMIN_USER') && strtolower((string) ADMIN_USER) === $username) return 'Ese usuario está reservado.';
        if (!in_array($role, self::manageable($actorRole), true)) return 'No puedes crear ese tipo de cuenta.';
        if (mb_strlen($password) < 8) return 'La clave debe tener al menos 8 caracteres.';
        try {
            $db->prepare("INSERT INTO users (username, name, password_hash, role) VALUES (?, ?, ?, ?)")
               ->execute([$username, mb_substr(trim($name), 0, 120), password_hash($password, PASSWORD_DEFAULT), $role]);
        } catch (\PDOException $e) {
            return ((int) ($e->errorInfo[1] ?? 0)) === 1062 ? 'Ese usuario ya existe.' : 'No se pudo crear: ' . $e->getMessage();
        }
        return null;
    }
}
