<?php
require 'config/app.php';
require 'config/database.php';
require 'src/services/GoogleDriveService.php';
$db = getDB();

$rootId = env('GOOGLE_DRIVE_FOLDER_ID', '');
echo "GOOGLE_DRIVE_FOLDER_ID: " . ($rootId ?: "NO CONFIGURADO") . "\n";

$total_prods = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$with_album = $db->query("SELECT COUNT(*) FROM products WHERE album_id IS NOT NULL")->fetchColumn();
$with_cover = $db->query("SELECT COUNT(*) FROM products WHERE cover_image_url IS NOT NULL AND cover_image_url != ''")->fetchColumn();

echo "Total Productos: $total_prods\n";
echo "Con Album ID: $with_album\n";
echo "Con Portada: $with_cover\n";

if ($rootId) {
    try {
        $drive = new GoogleDriveService();
        $token = $drive->getValidToken($db);
        if ($token) {
            echo "Conexión a Drive: OK\n";
            // Tomar 1 producto con portada pero sin album_id
            $prod = $db->query("SELECT id, sku, cover_image_url FROM products WHERE album_id IS NULL AND cover_image_url LIKE 'https://lh3.googleusercontent.com/d/%' LIMIT 1")->fetch();
            if ($prod) {
                echo "\nProbando SKU: " . $prod['sku'] . "\n";
                preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $prod['cover_image_url'], $matches);
                if (isset($matches[1])) {
                    $fileId = $matches[1];
                    echo "File ID de portada: $fileId\n";
                    
                    // Obtener info del archivo
                    $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?fields=id,name,parents,mimeType";
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"]
                    ]);
                    $res = curl_exec($ch);
                    $fileData = json_decode($res, true);
                    echo "Metadata de Drive:\n";
                    print_r($fileData);
                    
                    if (isset($fileData['parents'])) {
                        $albumId = $drive->getAlbumIdForFile($fileData, $rootId);
                        echo "Album ID detectado: " . ($albumId ?: "NULL") . "\n";
                    }
                }
            }
        } else {
            echo "Conexión a Drive: FALLÓ (No hay token)\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
