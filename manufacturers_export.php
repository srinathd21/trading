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

// Build same search filter as manufacturers.php
$search = trim($_GET['search'] ?? '');
$where = "WHERE 1=1";
$params = [];

if ($search) {
    $where .= " AND (m.name LIKE ? OR m.contact_person LIKE ? OR m.phone LIKE ? OR m.gstin LIKE ?)";
    $like = "%$search%";
    $params = array_fill(0, 4, $like);
}

$sql = "
    SELECT 
        m.name,
        m.contact_person,
        m.phone,
        m.email,
        m.address,
        m.gstin,
        m.is_active,
        m.created_at,
        COALESCE(COUNT(p.id), 0) as total_purchases,
        COALESCE(SUM(p.total_amount), 0) as total_spent
    FROM manufacturers m
    LEFT JOIN purchases p ON p.manufacturer_id = m.id
    $where
    GROUP BY m.id
    ORDER BY m.name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$manufacturers = $stmt->fetchAll();

// Set headers for CSV download
$filename = "Manufacturers_" . date('d-m-Y') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM for Excel to detect UTF-8 (supports Indian company names)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Header
fputcsv($output, [
    'S.No',
    'Company Name',
    'Contact Person',
    'Phone',
    'Email',
    'GSTIN',
    'Address',
    'Status',
    'Total Purchases',
    'Total Amount (₹)',
    'Date Added'
]);

// Data rows
$sno = 1;
foreach ($manufacturers as $m) {
    fputcsv($output, [
        $sno++,
        $m['name'],
        $m['contact_person'] ?: '—',
        $m['phone'] ?: '—',
        $m['email'] ?: '—',
        $m['gstin'] ?: '—',
        $m['address'] ?: '—',
        $m['is_active'] ? 'Active' : 'Inactive',
        $m['total_purchases'],
        number_format($m['total_spent'], 2),
        date('d-M-Y', strtotime($m['created_at']))
    ]);
}

fclose($output);
exit();
?>