<?php
/**
 * Admin — Productos: listado con filtros y activar/desactivar (uno, varios o todos los del filtro).
 * Los datos vienen de la Hoja master; aquí el admin decide qué se ve y puede cambiar el precio (solo en el visor).
 */
$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'estado' => $_GET['estado'] ?? '',
    'marca' => $_GET['marca'] ?? '',
    'categoria' => $_GET['categoria'] ?? '',
    'genero' => $_GET['genero'] ?? '',
    'carpeta' => $_GET['carpeta'] ?? '',
    'pmin' => trim($_GET['pmin'] ?? ''),
    'pmax' => trim($_GET['pmax'] ?? ''),
    'contiene' => trim($_GET['contiene'] ?? ''),
    'stock' => in_array($_GET['stock'] ?? '', ['con', 'sin'], true) ? $_GET['stock'] : '',
    'oprecio' => in_array($_GET['oprecio'] ?? '', ['desc', 'asc'], true) ? $_GET['oprecio'] : '',
    'ocodigo' => in_array($_GET['ocodigo'] ?? '', ['asc', 'desc'], true) ? $_GET['ocodigo'] : '',
];
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$db = getDB();

[$where, $params] = adminProductFilter($filters);
$countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE {$where}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);

// Orden combinable: precio (sin precio al final) y luego código (alfanumérico: por largo y luego texto, así 8-1 va
// antes que 10-1); sin ninguno, el de la hoja
$codeKey = "REPLACE(REPLACE(sku, 'M:', ''), 'M-', '')";
$orderParts = [];
if ($filters['oprecio'] !== '') $orderParts[] = '(price_suggested IS NULL OR price_suggested <= 0), price_suggested ' . strtoupper($filters['oprecio']);
if ($filters['ocodigo'] !== '') { $d = strtoupper($filters['ocodigo']); $orderParts[] = "LENGTH($codeKey) $d, $codeKey $d"; }
$orderSql = $orderParts ? implode(', ', $orderParts) . ', sku' : 'in_sheet DESC, sheet_row ASC, sku ASC';

// ⬇ Descargar Excel: la lista completa del filtro actual (mismo WHERE y orden que la pantalla y que la tarjeta del Dashboard)
if (($_GET['descargar'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../services/XlsxWriter.php';
    exportAdminProductsXlsx($db, $filters, $where, $params, $orderSql, $total);
    exit;
}

$stmt = $db->prepare(
    "SELECT id, sku, name, brand, color, category, price_suggested, price_sheet, price_manual, " . stockSql() . " AS stock, cover_image_url,
            is_active, in_sheet, from_drive, sheet_status
     FROM products WHERE {$where}
     ORDER BY {$orderSql} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$products = $stmt->fetchAll();

$summary = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(is_active = 1 AND (in_sheet = 1 OR from_drive = 1)) AS visibles,
            SUM(is_active = 0) AS inactivos,
            SUM(in_sheet = 0) AS fuera
     FROM products"
)->fetch();
$brands = $db->query("SELECT brand, COUNT(*) AS n FROM products WHERE brand <> '' GROUP BY brand ORDER BY brand")->fetchAll();
$categories = $db->query("SELECT category, COUNT(*) AS n FROM products WHERE category <> '' GROUP BY category ORDER BY category")->fetchAll();

// Carpetas de Drive para el filtro (ruta «Marca › Subcarpeta»), las mismas que en el catálogo público
$folders = catalogFolderOptions($db);

include __DIR__ . '/../../templates/admin/products.php';

/** Excel de la lista del filtro: código, nombre, marca, carpeta, precio, stock, estado y motivo. */
function exportAdminProductsXlsx(PDO $db, array $filters, string $where, array $params, string $orderSql, int $total): void
{
    $listas = [
        '' => 'Productos total', 'activos' => 'Visibles', 'inactivos' => 'Desactivados', 'fuera' => 'Fuera de la Hoja master',
        'sinfoto' => 'Sin foto', 'conportada' => 'Con portada', 'sinportada' => 'Sin portada', 'hoy' => 'Actualizados hoy',
        'sinprecio' => 'Sin precio en tabla', 'preciomanual' => 'Precio editado en admin',
    ];
    $lista = $listas[$filters['estado']] ?? 'Productos';
    @set_time_limit(120);
    $stmt = $db->prepare(
        "SELECT sku, name, brand, category, color, price_suggested, " . stockSql() . " AS stock, cover_image_url,
                is_active, archived, in_sheet, from_drive, price_manual, sheet_status, updated_at,
                (SELECT d.parent_id FROM drive_index d WHERE d.matched_sku = products.sku AND d.is_folder = 0 ORDER BY d.is_hidden LIMIT 1) AS folder_id
         FROM products WHERE {$where} ORDER BY {$orderSql}"
    );
    $stmt->execute($params);
    // Carpeta: la ruta «Marca › Subcarpeta» del filtro; si no está, el nombre de la carpeta en Drive
    $folderLabel = [];
    foreach (catalogFolderOptions($db) as $fo) $folderLabel[$fo['id']] = $fo['label'];
    $folderName = [];
    try {
        foreach ($db->query("SELECT file_id, name FROM drive_index WHERE is_folder = 1") as $f) $folderName[$f['file_id']] = $f['name'];
    } catch (\PDOException $e) { /* índice aún no creado */ }

    $rows = [['Código', 'Nombre', 'Marca', 'Categoría', 'Color', 'Carpeta (Drive)', 'Precio', 'Stock', 'Estado',
        'Origen', 'Portada', 'Precio editado en admin', 'STATUS hoja', 'Actualizado', 'Motivo (' . $lista . ')']];
    foreach ($stmt as $p) {
        $hasCover = trim((string) $p['cover_image_url']) !== '';
        $estado = !$p['is_active'] ? 'Desactivado (admin)' : ((int) $p['archived'] ? 'Oculto (fuera de la Hoja master)'
            : ($hasCover ? 'Visible' : 'Oculto (sin portada)'));
        $origen = $p['in_sheet'] ? 'Hoja master' : ($p['from_drive'] ? 'Solo Drive' : 'Fuera de la Hoja master');
        $motivo = match ($filters['estado']) {
            'activos' => 'Activo y en la Hoja master o Solo Drive' . ($hasCover ? '' : ' (no sale al público: sin portada)'),
            'inactivos' => 'Desactivado con el interruptor del admin',
            'fuera' => $p['from_drive'] ? 'No está en la Hoja master (creado desde Drive)' : 'Ya no está en la Hoja master',
            'sinfoto', 'sinportada' => 'Ninguna foto o video de Drive se llama como el código',
            'conportada' => 'Tiene foto o video enlazado por el nombre del archivo',
            'hoy' => 'Cambió hoy (sync o admin)',
            'sinprecio' => 'Sin PRICE_SUGGESTED (KYTE) ni precio puesto en admin',
            'preciomanual' => 'Precio cambiado a mano en el admin (el sync no lo pisa)',
            default => '',
        };
        $fid = (string) $p['folder_id'];
        $rows[] = [
            $p['sku'], (string) $p['name'], (string) $p['brand'], (string) $p['category'], (string) $p['color'],
            $fid === '' ? '' : ($folderLabel[$fid] ?? $folderName[$fid] ?? ''),
            $p['price_suggested'] > 0 ? (float) $p['price_suggested'] : '',
            $p['stock'] === null ? '' : (float) $p['stock'],
            $estado, $origen, $hasCover ? 'sí' : 'no', $p['price_manual'] ? 'sí' : 'no', (string) $p['sheet_status'],
            (string) $p['updated_at'], $motivo,
        ];
    }
    $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $lista) ?: 'Productos'), '_');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Lista_' . $slug . '_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: no-store');
    echo XlsxWriter::build([$lista => ['rows' => $rows, 'widths' => [14, 40, 16, 16, 14, 34, 12, 8, 26, 20, 8, 10, 14, 19, 48]]]);
}
