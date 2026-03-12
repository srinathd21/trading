<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=product_import_template.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV headers
$headers = [
    'product_name',
    'product_code',
    'barcode',
    'hsn_code',
    'category_id',
    'description',
    'unit_of_measure',
    'stock_price',
    'retail_price',
    'wholesale_price',
    'min_stock_level'
];

fputcsv($output, $headers);

// Sample data
$sample_data = [
    [
        'Sample Product 1',
        'PROD1001',
        '8901234567890',
        '123456',
        '1',
        'This is a sample product description',
        'pcs',
        '100.00',
        '150.00',
        '120.00',
        '10'
    ],
    [
        'Sample Product 2',
        'PROD1002',
        '8901234567891',
        '',
        '1',
        'Another sample product',
        'pcs',
        '200.00',
        '300.00',
        '250.00',
        '5'
    ],
    [
        'Sample Product 3',
        '',
        '',
        '654321',
        '',
        'Product with auto-generated code',
        'kg',
        '50.00',
        '75.00',
        '60.00',
        '20'
    ]
];

// Add sample data
foreach ($sample_data as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit();