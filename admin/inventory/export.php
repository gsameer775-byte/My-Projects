<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'inventory', 'view');

$search = sanitize($_GET['search'] ?? '');

$sql = "SELECT s.sku, s.name, c.name AS category_name, s.fabric, s.color, s.design_pattern,
               s.has_blouse, s.cost_price, s.selling_price, s.wholesale_price,
               s.rack_location, s.reorder_level, COALESCE(st.quantity, 0) AS quantity,
               s.is_active
        FROM sarees s
        LEFT JOIN categories c ON s.category_id = c.category_id
        LEFT JOIN stock st ON s.saree_id = st.saree_id";

$params = [];
if ($search !== '') {
    $sql .= " WHERE s.name LIKE :search1 OR s.sku LIKE :search2 OR c.name LIKE :search3";
    $params['search1'] = "%$search%";
    $params['search2'] = "%$search%";
    $params['search3'] = "%$search%";
}
$sql .= " ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Stream as CSV download
$filename = 'hikayat_banaras_inventory_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, [
    'SKU', 'Name', 'Category', 'Fabric', 'Color', 'Design Pattern',
    'Has Blouse', 'Cost Price', 'Selling Price', 'Wholesale Price',
    'Rack Location', 'Reorder Level', 'Current Stock', 'Status'
]);

foreach ($rows as $r) {
    fputcsv($output, [
        $r['sku'],
        $r['name'],
        $r['category_name'],
        $r['fabric'],
        $r['color'],
        $r['design_pattern'],
        $r['has_blouse'] ? 'Y' : 'N',
        $r['cost_price'],
        $r['selling_price'],
        $r['wholesale_price'],
        $r['rack_location'],
        $r['reorder_level'],
        $r['quantity'],
        $r['is_active'] ? 'Active' : 'Inactive',
    ]);
}

fclose($output);
exit;