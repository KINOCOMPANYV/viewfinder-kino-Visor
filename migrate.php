<?php
/**
 * Ejecuta migraciones SQL pendientes.
 * Uso: php migrate.php
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

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

        // Ejecutar migración
        $sql = file_get_contents($file);
        $db->exec($sql);

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
