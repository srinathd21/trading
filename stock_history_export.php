<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    die('Access denied.');
}

// Authorization
$allowed_roles = ['admin', 'warehouse_manager', 'shop_manager'];
if (!in_array($_SESSION['role'] ?? '', $allowed_roles)) {
    die('Unauthorized access.');
}

// === REUSE SAME FILTER LOGIC ===
$search     = trim($_GET['search'] ?? '');
$location   = (int)($_GET['location'] ?? 0);
$reason     = $_GET['reason'] ?? '';
$date_from  = $_GET['date_from'] ?? '';
$date_to    = $_GET['date_to'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($search) {
    $where .= " AND (p.product_name LIKE ? OR p.product_code LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like;
}
if ($location) {
    $where .= " AND sa.shop_id = ?";
    $params[] = $location;
}
if ($reason) {
    $where .= " AND sa.adjustment_type = ?";
    $params[] = $reason;
}
if ($date_from) { $where .= " AND DATE(sa.adjusted_at) >= ?"; $params[] = $date_from; }
if ($date_to)   { $where .= " AND DATE(sa.adjusted_at) <= ?"; $params[] = $date_to; }

$query = "
    SELECT
        DATE_FORMAT(sa.adjusted_at, '%d/%m/%Y %h:%i %p') AS date_time,
        p.product_name,
        p.product_code,
        s.shop_name,
        UPPER(sa.adjustment_type) AS adjustment_type,
        sa.quantity,
        sa.old_stock,
        sa.new_stock,
        COALESCE(
            CASE sa.adjustment_type
                WHEN 'add' THEN 'Stock Added'
                WHEN 'remove' THEN 'Stock Removed'
                WHEN 'damage' THEN 'Damaged'
                WHEN 'expiry' THEN 'Expired'
                WHEN 'correction' THEN 'Correction'
                WHEN 'transfer_in' THEN 'Transfer In'
                WHEN 'transfer_out' THEN 'Transfer Out'
                ELSE 'Other'
            END, 'Other'
        ) AS reason_label,
        COALESCE(sa.notes, '-') AS notes,
        u.full_name AS adjusted_by
    FROM stock_adjustments sa
    JOIN products p ON sa.product_id = p.id
    JOIN shops s ON sa.shop_id = s.id
    JOIN users u ON sa.adjusted_by = u.id
    $where
    ORDER BY sa.adjusted_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === GENERATE CSV ===
$filename = "Stock_Movement_History_" . date('d_M_Y_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Header
fputcsv($output, [
    'Date & Time',
    'Product Name',
    'Product Code',
    'Location',
    'Adjustment Type',
    'Quantity',
    'Old Stock',
    'New Stock',
    'Reason',
    'Notes',
    'Adjusted By'
]);

// CSV Rows
foreach ($results as $row) {
    fputcsv($output, [
        $row['date_time'],
        $row['product_name'],
        $row['product_code'],
        $row['shop_name'],
        $row['adjustment_type'],
        $row['quantity'],
        $row['old_stock'],
        $row['new_stock'],
        $row['reason_label'],
        $row['notes'],
        $row['adjusted_by']
    ]);
}

fclose($output);
exit();