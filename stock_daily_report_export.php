<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    die('Access denied.');
}

$allowed_roles = ['admin', 'warehouse_manager', 'shop_manager'];
if (!in_array($_SESSION['role'] ?? '', $allowed_roles)) {
    die('Unauthorized access.');
}

$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{1,2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

// === SHOP-WISE DATA ===
$shops = $pdo->query("SELECT id, shop_name FROM shops WHERE is_active = 1 ORDER BY shop_name")->fetchAll();
$shop_data = [];

foreach ($shops as $shop) {
    $shop_id = $shop['id'];

    // Opening = Closing of previous day
    $prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
    $opening = (float)$pdo->query("
        SELECT COALESCE(SUM(quantity), 0)
        FROM product_stocks 
        WHERE shop_id = $shop_id AND last_updated <= '$prev_date 23:59:59'
    ")->fetchColumn();

    $inward = (float)$pdo->query("
        SELECT COALESCE(SUM(quantity), 0)
        FROM stock_adjustments 
        WHERE shop_id = $shop_id 
          AND adjustment_type IN ('add', 'transfer_in')
          AND DATE(adjusted_at) = '$selected_date'
    ")->fetchColumn();

    $outward = (float)$pdo->query("
        SELECT COALESCE(SUM(quantity), 0)
        FROM stock_adjustments 
        WHERE shop_id = $shop_id 
          AND adjustment_type IN ('remove', 'transfer_out', 'damage', 'expiry')
          AND DATE(adjusted_at) = '$selected_date'
    ")->fetchColumn();

    $closing = $opening + $inward - $outward;

    $shop_data[] = [
        'shop_name' => $shop['shop_name'],
        'opening'   => $opening,
        'inward'    => $inward,
        'outward'   => $outward,
        'closing'   => $closing
    ];
}

// === PRODUCT-WISE DATA ===
$product_data = $pdo->query("
    SELECT 
        p.product_name,
        p.product_code,
        COALESCE(SUM(ps.quantity), 0) AS current_stock,
        COALESCE((
            SELECT SUM(sa.quantity)
            FROM stock_adjustments sa
            WHERE sa.product_id = p.id
              AND sa.adjustment_type IN ('add', 'transfer_in')
              AND DATE(sa.adjusted_at) = '$selected_date'
        ), 0) AS inward,
        COALESCE((
            SELECT SUM(sa.quantity)
            FROM stock_adjustments sa
            WHERE sa.product_id = p.id
              AND sa.adjustment_type IN ('remove', 'transfer_out', 'damage', 'expiry')
              AND DATE(sa.adjusted_at) = '$selected_date'
        ), 0) AS outward
    FROM products p
    LEFT JOIN product_stocks ps ON ps.product_id = p.id
    GROUP BY p.id
    ORDER BY p.product_name
")->fetchAll();

foreach ($product_data as &$prod) {
    $prod['opening'] = $prod['current_stock'] - $prod['inward'] + $prod['outward'];
    $prod['closing'] = $prod['current_stock'];
}

// === GENERATE CSV ===
$filename = "Daily_Stock_Report_" . date('d_M_Y', strtotime($selected_date)) . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// === HEADER ===
fputcsv($output, ['DAILY STOCK REPORT']);
fputcsv($output, ['Date', date('d M Y', strtotime($selected_date))]);
fputcsv($output, ['Generated On', date('d M Y, h:i A')]);
fputcsv($output, []); // Empty line

// === SHOP-WISE SECTION ===
fputcsv($output, ['SHOP-WISE STOCK MOVEMENT']);
fputcsv($output, ['Shop Name', 'Opening Stock', 'Inward (+)', 'Outward (-)', 'Closing Stock']);

foreach ($shop_data as $row) {
    fputcsv($output, [
        $row['shop_name'],
        number_format($row['opening'], 0, '.', ''),
        number_format($row['inward'], 0, '.', ''),
        number_format($row['outward'], 0, '.', ''),
        number_format($row['closing'], 0, '.', '')
    ]);
}

// Grand Total Row
$total_opening = array_sum(array_column($shop_data, 'opening'));
$total_inward  = array_sum(array_column($shop_data, 'inward'));
$total_outward = array_sum(array_column($shop_data, 'outward'));
$total_closing = array_sum(array_column($shop_data, 'closing'));

fputcsv($output, [
    'TOTAL',
    number_format($total_opening, 0, '.', ''),
    number_format($total_inward, 0, '.', ''),
    number_format($total_outward, 0, '.', ''),
    number_format($total_closing, 0, '.', '')
]);

fputcsv($output, []); // Separator
fputcsv($output, []);

// === PRODUCT-WISE SECTION ===
fputcsv($output, ['PRODUCT-WISE STOCK MOVEMENT (ALL SHOPS)']);
fputcsv($output, ['#', 'Product Name', 'Product Code', 'Opening', 'In (+)', 'Out (-)', 'Closing']);

foreach ($product_data as $i => $prod) {
    fputcsv($output, [
        $i + 1,
        $prod['product_name'],
        $prod['product_code'],
        number_format($prod['opening'], 0, '.', ''),
        number_format($prod['inward'], 0, '.', ''),
        number_format($prod['outward'], 0, '.', ''),
        number_format($prod['closing'], 0, '.', '')
    ]);
}

fputcsv($output, []);
fputcsv($output, ['End of Report']);

fclose($output);
exit();