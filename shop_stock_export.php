<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    die("Access denied.");
}

$shop_id = (int)($_GET['id'] ?? 0);
if (!$shop_id) {
    die("Invalid Shop ID");
}

// Fetch shop name
$shop_stmt = $pdo->prepare("SELECT shop_name, shop_code FROM shops WHERE id = ?");
$shop_stmt->execute([$shop_id]);
$shop = $shop_stmt->fetch();

if (!$shop) {
    die("Shop not found");
}

// Fetch stock data
$stock = $pdo->prepare("
    SELECT 
        p.product_name,
        p.product_code,
        p.barcode,
        c.category_name,
        p.retail_price,
        p.min_stock_level as global_min,
        COALESCE(sp.min_stock_level, p.min_stock_level, 5) as shop_min_level,
        COALESCE(sp.quantity, 0) as quantity,
        g.hsn_code,
        COALESCE(g.cgst_rate,0) + COALESCE(g.sgst_rate,0) + COALESCE(g.igst_rate,0) as gst_percent
    FROM products p
    LEFT JOIN shops_products sp ON sp.product_id = p.id AND sp.shop_id = ?
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    WHERE p.is_active = 1
    ORDER BY p.product_name
");
$stock->execute([$shop_id]);
$products = $stock->fetchAll();

// Generate filename
$filename = "Stock_Report_{$shop['shop_code']}_" . date('d-M-Y_H-i') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Header Row
fputcsv($output, [
    'S.No',
    'Product Name',
    'Product Code',
    'Barcode',
    'Category',
    'Current Qty',
    'Min Level (Shop)',
    'Min Level (Global)',
    'Status',
    'Retail Price',
    'HSN Code',
    'GST %',
    'Stock Value (₹)'
]);

// Data Rows
$sno = 1;
foreach ($products as $p) {
    $qty = (int)$p['quantity'];
    $shop_min = (int)$p['shop_min_level'];
    $global_min = (int)$p['global_min'];
    
    $status = $qty == 0 ? 'OUT OF STOCK' : 
              ($qty < $shop_min ? 'LOW STOCK' : 'IN STOCK');
    
    $stock_value = $qty * $p['retail_price'];

    fputcsv($output, [
        $sno++,
        $p['product_name'],
        $p['product_code'] ?: '—',
        $p['barcode'] ?: '—',
        $p['category_name'] ?: '—',
        $qty,
        $shop_min,
        $global_min,
        $status,
        '₹' . number_format($p['retail_price'], 2),
        $p['hsn_code'] ?: '—',
        $p['gst_percent'] . '%',
        '₹' . number_format($stock_value, 2)
    ]);
}

fclose($output);
exit();
?>