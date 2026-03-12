<?php
// product_import_template.php

date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if (!isset($_SESSION['current_business_id'])) {
    header('Location: select_shop.php');
    exit();
}

$current_business_id = (int)$_SESSION['current_business_id'];

// Set headers for CSV download
$filename = "product_import_template_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

// Write BOM for proper UTF-8 support in Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers - Exactly matching import expectations
$headers = [
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
];

fputcsv($output, $headers);

// Add example rows for user guidance
$examples = [
    // Example 1
    [
        'Finolex PVC Pipe 1 inch',
        'FIN001',
        'Pipes',
        'PVC Pipes',
        '50',
        '8901234567890',
        '2',
        'mtr',
        '245.00',
        '280.00',
        '265.00',
        'Yes',
        'percentage',
        '5',
        '300.00'
    ],
    // Example 2
    [
        'Asian Paints Royale',
        'AP001',
        'Paints',
        'Interior',
        '20',
        '',
        '2',
        'ltr',
        '3200.00',
        '3800.00',
        '3600.00',
        'No',
        '',
        '',
        '4000.00'
    ],
    // Example 3
    [
        'Samsung Galaxy S23',
        'SAM001',
        'mobile phones',
        'Samsung',
        '10',
        '1234567890123',
        '2',
        'nos',
        '65000.00',
        '72000.00',
        '70000.00',
        'Yes',
        'fixed',
        '2000',
        '75000.00'
    ],
    // Example 4 (no referral)
    [
        'Dell Inspiron Laptop',
        'DEL001',
        'Laptops',
        'Dell',
        '5',
        '',
        '2',
        'nos',
        '55000.00',
        '62000.00',
        '60000.00',
        'No',
        '',
        '',
        '65000.00'
    ]
];

foreach ($examples as $row) {
    fputcsv($output, $row);
}

// Add instructions as comments (will appear as rows in CSV)
$instructions = [
    ['', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['INSTRUCTIONS:', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['1. Required columns:', 'Product Name, Unit of Measure, Stock Price, Retail Price, Wholesale Price', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['2. Category & Subcategory', 'Must exactly match existing names in your system (case-sensitive)', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['3. GST ID', 'Enter the ID number from your GST Rates list', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['4. Referral Enabled', 'Use: Yes, No, True, False, 1, 0', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['5. Referral Type', 'Only if Referral Enabled = Yes → use: percentage or fixed', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['6. Current Stock', 'Whole number (e.g., 10, 50). Will be added to selected shop/warehouse', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['7. Product Code & Barcode', 'Must be unique. If exists → product will be updated', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['8. Prices', 'Enter numbers without ₹ symbol (e.g., 2500.00)', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['Do not delete or rename columns. Keep headers in first row exactly as shown.', '', '', '', '', '', '', '', '', '', '', '', '', '', '']
];

foreach ($instructions as $inst) {
    fputcsv($output, $inst);
}

fclose($output);
exit();
?>