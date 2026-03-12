<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$min_amount = trim($_GET['min_amount'] ?? '');
$max_amount = trim($_GET['max_amount'] ?? '');

// Build WHERE clause (same as main page)
$where = ["gc.business_id = ?"];
$params = [$business_id];

if (!empty($search)) {
    $where[] = "(gc.purchase_number LIKE ? OR gc.purchase_invoice_no LIKE ? OR p.reference LIKE ? OR m.name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($status) && in_array($status, ['claimed', 'not_claimed'])) {
    $where[] = "gc.status = ?";
    $params[] = $status;
}

if (!empty($date_from)) {
    $where[] = "DATE(gc.created_at) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where[] = "DATE(gc.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($min_amount) && is_numeric($min_amount)) {
    $where[] = "gc.credit_amount >= ?";
    $params[] = floatval($min_amount);
}
if (!empty($max_amount) && is_numeric($max_amount)) {
    $where[] = "gc.credit_amount <= ?";
    $params[] = floatval($max_amount);
}

$whereClause = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

$sql = "
    SELECT
        gc.id AS credit_id,
        gc.purchase_number,
        gc.purchase_invoice_no,
        gc.credit_amount,
        gc.status,
        DATE_FORMAT(gc.created_at, '%d-%m-%Y') AS created_date,
        DATE_FORMAT(gc.updated_at, '%d-%m-%Y') AS updated_date,
        p.purchase_date,
        p.total_amount AS po_total,
        p.payment_status AS po_payment_status,
        m.name AS supplier_name
    FROM gst_credits gc
    LEFT JOIN purchases p ON gc.purchase_id = p.id AND gc.business_id = p.business_id
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    $whereClause
    ORDER BY gc.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=gst_input_credits_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add headers
fputcsv($output, [
    'Credit ID',
    'Purchase Order',
    'Purchase Invoice',
    'Credit Amount (₹)',
    'Status',
    'Created Date',
    'Updated Date',
    'Purchase Date',
    'PO Total (₹)',
    'PO Payment Status',
    'Supplier'
]);

// Add data
foreach ($credits as $credit) {
    fputcsv($output, [
        'GC' . str_pad($credit['credit_id'], 4, '0', STR_PAD_LEFT),
        $credit['purchase_number'],
        $credit['purchase_invoice_no'] ?? '',
        $credit['credit_amount'],
        ucfirst(str_replace('_', ' ', $credit['status'])),
        $credit['created_date'],
        $credit['updated_date'] ?: '-',
        date('d-m-Y', strtotime($credit['purchase_date'])),
        $credit['po_total'],
        ucfirst($credit['po_payment_status']),
        $credit['supplier_name']
    ]);
}

fclose($output);
exit;
?>