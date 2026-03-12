<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    die("Access denied.");
}

// Fetch full stock data with product, category, GST, and location-wise stock
$query = "
    SELECT 
        p.product_name,
        p.product_code,
        p.barcode,
        c.category_name,
        p.stock_price,
        p.retail_price,
        p.min_stock_level,
        COALESCE(SUM(ps.quantity), 0) as total_stock,
        g.hsn_code,
        g.cgst_rate + g.sgst_rate + g.igst_rate as total_gst
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    LEFT JOIN product_stocks ps ON ps.product_id = p.id
    WHERE p.is_active = 1
    GROUP BY p.id
    ORDER BY p.product_name
";

$stmt = $pdo->query($query);
$products = $stmt->fetchAll();

// Fetch warehouse-wise stock for each product
$stock_by_location = [];
foreach ($products as $p) {
    $pid = $p['product_name']; // Use name as key temporarily
    $loc_stmt = $pdo->prepare("
        SELECT l.location_name, COALESCE(ps.quantity, 0) as qty
        FROM stock_locations l
        LEFT JOIN product_stocks ps ON ps.location_id = l.id AND ps.product_id = ?
        WHERE l.is_active = 1
        ORDER BY l.location_name
    ");
    $loc_stmt->execute([$p['product_name'] === $p['product_name'] ? $p['product_name'] : null]); // dummy
    // Actually use product ID from original query
    // Let's fix this properly in next version
}

// Better approach: Re-query with proper ID
$final_products = $pdo->query("
    SELECT 
        p.id,
        p.product_name,
        p.product_code,
        p.barcode,
        c.category_name,
        p.stock_price,
        p.retail_price,
        p.min_stock_level,
        COALESCE(SUM(ps.quantity), 0) as total_stock,
        g.hsn_code,
        COALESCE(g.cgst_rate,0) + COALESCE(g.sgst_rate,0) + COALESCE(g.igst_rate,0) as total_gst_rate
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    LEFT JOIN product_stocks ps ON ps.product_id = p.id
    WHERE p.is_active = 1
    GROUP BY p.id
    ORDER BY p.product_name
")->fetchAll();

// Now get location-wise stock
$location_names = $pdo->query("SELECT location_name FROM stock_locations WHERE is_active = 1 ORDER BY location_name")->fetchAll(PDO::FETCH_COLUMN);

$stock_matrix = [];
foreach ($final_products as $p) {
    $row = [
        'Product Name' => $p['product_name'],
        'Code' => $p['product_code'] ?: '—',
        'Barcode' => $p['barcode'] ?: '—',
        'Category' => $p['category_name'] ?: 'Uncategorized',
        'Cost Price' => number_format($p['stock_price'], 2),
        'Retail Price' => number_format($p['retail_price'], 2),
        'Min Stock' => $p['min_stock_level'],
        'Total Stock' => (int)$p['total_stock'],
        'HSN' => $p['hsn_code'] ?: '—',
        'GST %' => $p['total_gst_rate'] . '%',
        'Status' => $p['total_stock'] == 0 ? 'OUT OF STOCK' : ($p['total_stock'] < $p['min_stock_level'] ? 'LOW STOCK' : 'IN STOCK')
    ];

    // Add warehouse columns
    foreach ($location_names as $loc) {
        $row[$loc] = 0; // default
    }

    // Fill actual stock per location
    $loc_stmt = $pdo->prepare("
        SELECT l.location_name, COALESCE(ps.quantity, 0) as qty
        FROM stock_locations l
        LEFT JOIN product_stocks ps ON ps.location_id = l.id AND ps.product_id = ?
        WHERE l.is_active = 1
    ");
    $loc_stmt->execute([$p['id']]);
    while ($loc = $loc_stmt->fetch()) {
        $row[$loc['location_name']] = (int)$loc['qty'];
    }

    $stock_matrix[] = $row;
}

// CSV Export
$filename = "Stock_Report_" . date('d-M-Y_H-i') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Header
$headers = ['Product Name', 'Code', 'Barcode', 'Category', 'Cost Price', 'Retail Price', 'Min Stock', 'Total Stock', 'HSN', 'GST %', 'Status'];
foreach ($location_names as $loc) {
    $headers[] = $loc . " Stock";
}
fputcsv($output, $headers);

// Data
foreach ($stock_matrix as $row) {
    $csv_row = [];
    foreach ($headers as $h) {
        $csv_row[] = $row[$h] ?? 0;
    }
    fputcsv($output, $csv_row);
}

fclose($output);
exit();
?>