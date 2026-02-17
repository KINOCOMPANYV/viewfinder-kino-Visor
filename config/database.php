<?php
/**
 * Conexión a MySQL.
 * Devuelve la instancia PDO singleton.
 */

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null)
        return $pdo;

    // Railway provee variables nativas: MYSQLHOST, MYSQL_HOST, etc.
    // También soportamos DB_HOST (custom) como primera opción.
    $host = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: getenv('MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: getenv('MYSQL_PORT') ?: '3306';
    $name = getenv('DB_DATABASE') ?: getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: 'viewfinder_kino';
    $user = getenv('DB_USERNAME') ?: getenv('MYSQLUSER') ?: getenv('MYSQL_USER') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
