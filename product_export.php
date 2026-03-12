<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!isset($_SESSION['current_business_id'])) {
    header('Location: select_shop.php');
    exit();
}

$current_business_id = (int) $_SESSION['current_business_id'];
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');

$shop_condition = $is_admin ? "" : "AND ps.shop_id = " . (int)$current_shop_id;

// Main query similar to products.php for consistency
$sql = "
    SELECT
        p.id,
        p.product_name,
        p.product_code,
        p.barcode,
        p.gst_id,
        p.unit_of_measure,
        p.stock_price,
        p.retail_price,
        p.wholesale_price,
        p.referral_enabled,
        p.referral_type,
        p.referral_value,
        p.mrp,
        c.category_name AS category,
        s.subcategory_name AS subcategory,
        COALESCE(ps.quantity, 0) AS current_stock
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id
    LEFT JOIN product_stocks ps ON ps.product_id = p.id $shop_condition
    WHERE p.is_active = 1 AND p.business_id = ?
    ORDER BY p.product_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$current_business_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
$filename = "products_export_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV headers
fputcsv($output, [
    'S.No',
    'Product Name',
    'Product Code',
    'Category',
    'Subcategory',
    'Current Stock',
    'Barcode',
    'GST ID',
    'Unit of Measure',
    'Stock Price',
    'Retail Price',
    'Wholesale Price',
    'Referral Enabled',
    'Referral Type',
    'Referral Value',
    'MRP'
]);

// Write data rows
$sno = 1;
foreach ($products as $p) {
    fputcsv($output, [
        $sno++,
        $p['product_name'],
        $p['product_code'] ?? '',
        $p['category'] ?? 'Uncategorized',
        $p['subcategory'] ?? '',
        $p['current_stock'],
        $p['barcode'] ?? '',
        $p['gst_id'] ?? '',
        $p['unit_of_measure'],
        number_format($p['stock_price'], 2),
        number_format($p['retail_price'], 2),
        number_format($p['wholesale_price'], 2),
        ($p['referral_enabled'] ? 'Yes' : 'No'),
        ($p['referral_enabled'] ? $p['referral_type'] : ''),
        ($p['referral_enabled'] ? number_format($p['referral_value'], 2) : ''),
        number_format($p['mrp'], 2)
    ]);
}

fclose($output);
exit();
?>