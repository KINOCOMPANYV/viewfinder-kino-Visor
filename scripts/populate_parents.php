<?php
/**
 * Script para poblar la columna parent_sku en toda la base de datos.
 * Ejecutar desde la raíz: php scripts/populate_parents.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../src/helpers.php';

echo "🚀 Viewfinder Kino — Poblando columna parent_sku...\n\n";

try {
    $db = getDB();

    // 1. Verificar si existe la columna
    try {
        $db->query("SELECT parent_sku FROM products LIMIT 1");
    } catch (\PDOException $e) {
        echo "❌ Error: La columna 'parent_sku' no existe. ¿Ejecutaste 'php migrate.php'?\n";
        exit(1);
    }

    // 2. Obtener todos los productos
    $stmt = $db->query("SELECT id, sku FROM products");
    $products = $stmt->fetchAll();
    $total = count($products);

    echo "📊 Procesando {$total} productos...\n";

    $updateStmt = $db->prepare("UPDATE products SET parent_sku = ? WHERE id = ?");
    $count = 0;

    foreach ($products as $p) {
        $skuClean = preg_replace('/\.\w{2,4}$/i', '', $p['sku']);
        $parent = extractRootSku($skuClean);
        
        // Solo actualizar si es diferente al SKU (o si queremos forzar que todos tengan valor)
        // Guardaremos siempre el parent_sku para consistencia
        $updateStmt->execute([$parent, $p['id']]);
        
        $count++;
        if ($count % 100 === 0) {
            echo "  ⏳ {$count}/{$total} procesados...\n";
        }
    }

    echo "\n✅ Proceso completado. {$count} productos actualizados.\n";

} catch (PDOException $e) {
    echo "❌ Error de BD: " . $e->getMessage() . "\n";
    exit(1);
}
