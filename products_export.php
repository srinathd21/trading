<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    die("Access denied.");
}

// Optional: Restrict to admin/warehouse
// if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager'])) {
//     die("Access denied.");
// }

// Apply same filters as products.php
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? 'all';

$where = "WHERE p.is_active = 1";
$params = [];

if ($search) {
    $where .= " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($category) {
    $where .= " AND p.category_id = ?";
    $params[] = $category;
}
if ($status === 'low') {
    $where .= " AND (COALESCE(SUM(ps.quantity), 0) < p.min_stock_level)";
} elseif ($status === 'out') {
    $where .= " AND (COALESCE(SUM(ps.quantity), 0) = 0)";
}

$sql = "
    SELECT 
        p.product_name,
        p.product_code,
        p.barcode,
        c.category_name,
        p.unit_of_measure,
        p.stock_price,
        p.retail_price,
        p.wholesale_price,
        p.min_stock_level,
        COALESCE(SUM(ps.quantity), 0) as current_stock,
        g.hsn_code,
        g.cgst_rate,
        g.sgst_rate,
        g.igst_rate,
        p.description,
        p.created_at
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_stocks ps ON ps.product_id = p.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    $where
    GROUP BY p.id
    ORDER BY p.product_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// CSV Export
$filename = "Products_Export_" . date('d-M-Y') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM for Excel (supports Indian characters)
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Header Row
fputcsv($output, [
    'S.No',
    'Product Name',
    'Product Code',
    'Barcode',
    'Category',
    'Unit',
    'Cost Price (₹)',
    'Retail Price (₹)',
    'Wholesale Price (₹)',
    'Current Stock',
    'Min Stock Level',
    'Stock Status',
    'HSN Code',
    'CGST %',
    'SGST %',
    'IGST %',
    'Total GST %',
    'Description',
    'Date Added'
]);

// Data Rows
$sno = 1;
foreach ($products as $p) {
    $stock_status = '';
    if ($p['current_stock'] == 0) {
        $stock_status = 'Out of Stock';
    } elseif ($p['current_stock'] < $p['min_stock_level']) {
        $stock_status = 'Low Stock';
    } else {
        $stock_status = 'In Stock';
    }

    fputcsv($output, [
        $sno++,
        $p['product_name'],
        $p['product_code'] ?: '—',
        $p['barcode'] ?: '—',
        $p['category_name'] ?: 'Uncategorized',
        strtoupper($p['unit_of_measure']),
        number_format($p['stock_price'], 2),
        number_format($p['retail_price'], 2),
        number_format($p['wholesale_price'], 2),
        $p['current_stock'],
        $p['min_stock_level'],
        $stock_status,
        $p['hsn_code'] ?: '—',
        $p['cgst_rate'] ?? 0,
        $p['sgst_rate'] ?? 0,
        $p['igst_rate'] ?? 0,
        ($p['cgst_rate'] ?? 0) + ($p['sgst_rate'] ?? 0) + ($p['igst_rate'] ?? 0),
        $p['description'] ? strip_tags($p['description']) : '—',
        date('d-M-Y', strtotime($p['created_at']))
    ]);
}

fclose($output);
exit();
?>