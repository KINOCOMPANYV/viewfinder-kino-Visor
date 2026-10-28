<?php
/**
 * Ejecuta migraciones SQL pendientes.
 * Uso: php migrate.php
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

// 1050 tabla existe · 1060 columna duplicada · 1061 índice duplicado · 1091 no existe para DROP
const ALREADY_EXISTS_ERRORS = [1050, 1060, 1061, 1091];

/**
 * Separa un archivo .sql en sentencias (una por ';' de fin de línea), sin comentarios "--".
 * Convención para migraciones: ';' solo al final de línea, sin comentarios después ni ';' en textos.
 */
function splitSqlStatements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $sql))));
}

echo "🔄 Viewfinder Kino Visor — Ejecutando migraciones...\n\n";

try {
    $db = getDB();

    // Crear tabla de migraciones primero
    $initSql = file_get_contents(__DIR__ . '/migrations/000_create_migrations.sql');
    $db->exec($initSql);

    // Obtener migraciones ya ejecutadas
    $executed = $db->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);

    // Leer archivos de migración
    $files = glob(__DIR__ . '/migrations/*.sql');
    sort($files);

    $count = 0;
    foreach ($files as $file) {
        $filename = basename($file);

        // Saltar la migración 000 (ya ejecutada arriba) y las ya ejecutadas
        if ($filename === '000_create_migrations.sql')
            continue;
        if (in_array($filename, $executed)) {
            echo "  ✅ {$filename} (ya ejecutada)\n";
            continue;
        }

        // Ejecutar migración sentencia por sentencia. Los errores de "ya existe"
        // (columna/índice/tabla) se toleran para que una migración idempotente no
        // bloquee el resto (antes un fallo dejaba todas las siguientes sin correr).
        foreach (splitSqlStatements(file_get_contents($file)) as $statement) {
            try {
                $db->exec($statement);
            } catch (PDOException $e) {
                if (!in_array((int) ($e->errorInfo[1] ?? 0), ALREADY_EXISTS_ERRORS, true)) {
                    throw new PDOException("{$filename}: " . $e->getMessage(), 0, $e);
                }
                echo "  ⚠️  {$filename}: ya existía, se omite → " . $e->getMessage() . "\n";
            }
        }

        // Registrar como ejecutada
        $stmt = $db->prepare("INSERT INTO migrations (filename) VALUES (?)");
        $stmt->execute([$filename]);

        echo "  🆕 {$filename} — OK\n";
        $count++;
    }

    echo "\n✅ Migraciones completadas. {$count} nuevas ejecutadas.\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
