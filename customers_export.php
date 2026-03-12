<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    die("Access denied.");
}

// Optional: Restrict to admin/warehouse only
// if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager'])) {
//     die("Access denied.");
// }

// Build same search filter as customers.php
$search = trim($_GET['search'] ?? '');
$where = "WHERE 1=1";
$params = [];

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.gstin LIKE ?)";
    $like = "%$search%";
    $params = array_fill(0, 4, $like);
}

$sql = "
    SELECT 
        c.name,
        c.phone,
        c.email,
        c.address,
        c.gstin,
        c.created_at,
        COALESCE(COUNT(i.id), 0) as total_invoices,
        COALESCE(SUM(i.total), 0) as total_spent,
        MAX(i.created_at) as last_purchase
    FROM customers c
    LEFT JOIN invoices i ON i.customer_id = c.id
    $where
    GROUP BY c.id
    ORDER BY c.name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Set headers for CSV download
$filename = "Customers_" . date('d-m-Y') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM for Excel to detect UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Header
fputcsv($output, [
    'S.No',
    'Customer Name',
    'Phone',
    'Email',
    'GSTIN',
    'Address',
    'Total Invoices',
    'Total Spent (₹)',
    'Last Purchase',
    'Date Added'
]);

// Data rows
$sno = 1;
foreach ($customers as $c) {
    fputcsv($output, [
        $sno++,
        $c['name'],
        $c['phone'],
        $c['email'],
        $c['gstin'],
        $c['address'],
        $c['total_invoices'],
        number_format($c['total_spent'], 2),
        $c['last_purchase'] ? date('d-M-Y', strtotime($c['last_purchase'])) : 'Never',
        date('d-M-Y', strtotime($c['created_at']))
    ]);
}

fclose($output);
exit();
?>