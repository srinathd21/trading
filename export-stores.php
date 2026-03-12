<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'field_executive'])) {
    header('Location: login.php');
    exit();
}

// Get filter parameters from POST
$search_term = $_POST['search'] ?? '';
$city_filter = $_POST['city'] ?? '';
$executive_filter = $_POST['executive'] ?? '';
$status_filter = $_POST['status'] ?? '';

// Build WHERE conditions for filters
$where_conditions = ["1=1"];
$params = [];

// City filter
if (!empty($city_filter) && $city_filter !== 'all') {
    if ($city_filter === '') {
        $where_conditions[] = "(s.city IS NULL OR s.city = '')";
    } else {
        $where_conditions[] = "s.city = ?";
        $params[] = $city_filter;
    }
}

// Executive filter
if (!empty($executive_filter) && $executive_filter !== 'all') {
    if ($executive_filter === '') {
        $where_conditions[] = "s.field_executive_id IS NULL";
    } else {
        $where_conditions[] = "s.field_executive_id = ?";
        $params[] = $executive_filter;
    }
}

// Status filter
if ($status_filter === 'active') {
    $where_conditions[] = "s.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "s.is_active = 0";
}

// Search filter
if (!empty($search_term)) {
    $where_conditions[] = "(s.store_code LIKE ? OR s.store_name LIKE ? OR s.phone LIKE ? OR s.city LIKE ? OR u.full_name LIKE ? OR s.owner_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param; // store_code
    $params[] = $search_param; // store_name
    $params[] = $search_param; // phone
    $params[] = $search_param; // city
    $params[] = $search_param; // executive name
    $params[] = $search_param; // owner name
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch stores with stats
$stmt = $pdo->prepare("
    SELECT 
        s.id,
        s.store_code,
        s.store_name,
        s.owner_name,
        s.phone,
        s.whatsapp_number,
        s.email,
        s.address,
        s.city,
        s.gstin,
        u.full_name AS executive_name,
        s.field_executive_id,
        s.is_active,
        s.created_at,
        s.updated_at,
        (SELECT COUNT(*) FROM store_visits WHERE store_id = s.id) as visit_count,
        (SELECT COUNT(*) FROM store_requirements sr
         JOIN store_visits sv ON sv.id = sr.store_visit_id
         WHERE sv.store_id = s.id) as requirement_count,
        (SELECT COUNT(*) FROM store_requirements sr
         JOIN store_visits sv ON sv.id = sr.store_visit_id
         WHERE sv.store_id = s.id AND sr.requirement_status = 'delivered') as delivered_count
    FROM stores s
    LEFT JOIN users u ON s.field_executive_id = u.id
    WHERE $where_clause
    ORDER BY s.store_name
");
$stmt->execute($params);
$stores = $stmt->fetchAll();

// Generate filename
$filter_desc = '';
if (!empty($search_term)) $filter_desc .= '_search-' . substr($search_term, 0, 20);
if (!empty($city_filter) && $city_filter !== 'all') $filter_desc .= '_city-' . $city_filter;
if (!empty($executive_filter) && $executive_filter !== 'all') $filter_desc .= '_executive-' . $executive_filter;
if (!empty($status_filter)) $filter_desc .= '_' . $status_filter;

$filename = 'Stores_' . date('Y-m-d_H-i') . $filter_desc . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Open output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write header information
fputcsv($output, ['Stores Export - ' . date('d/m/Y h:i A')]);
fputcsv($output, ['Total Stores: ' . count($stores)]);
fputcsv($output, ['Export Date: ' . date('Y-m-d H:i:s')]);
fputcsv($output, []); // Empty row

// Write column headers
$headers = [
    'S.No',
    'Store Code',
    'Store Name',
    'Owner Name',
    'Phone',
    'WhatsApp',
    'Email',
    'Address',
    'City',
    'GSTIN',
    'Assigned Executive',
    'Status',
    'Total Visits',
    'Total Requirements',
    'Delivered Items',
    'Created Date',
    'Last Updated'
];
fputcsv($output, $headers);

// Write data rows
foreach ($stores as $index => $store) {
    $row = [
        $index + 1,
        $store['store_code'],
        $store['store_name'],
        $store['owner_name'] ?: 'N/A',
        $store['phone'],
        $store['whatsapp_number'] ?: 'N/A',
        $store['email'] ?: 'N/A',
        $store['address'],
        $store['city'] ?: 'N/A',
        $store['gstin'] ?: 'N/A',
        $store['executive_name'] ?: 'Unassigned',
        $store['is_active'] ? 'Active' : 'Inactive',
        $store['visit_count'],
        $store['requirement_count'],
        $store['delivered_count'],
        date('d/m/Y', strtotime($store['created_at'])),
        $store['updated_at'] ? date('d/m/Y', strtotime($store['updated_at'])) : 'N/A'
    ];
    fputcsv($output, $row);
}

// Write summary
fputcsv($output, []); // Empty row
fputcsv($output, ['SUMMARY']);
fputcsv($output, ['Metric', 'Value']);

$active_count = count(array_filter($stores, fn($s) => $s['is_active']));

fputcsv($output, ['Total Stores', count($stores)]);
fputcsv($output, ['Active Stores', $active_count]);
fputcsv($output, ['Inactive Stores', count($stores) - $active_count]);
fputcsv($output, ['Total Visits', array_sum(array_column($stores, 'visit_count'))]);
fputcsv($output, ['Total Requirements', array_sum(array_column($stores, 'requirement_count'))]);
fputcsv($output, ['Delivered Items', array_sum(array_column($stores, 'delivered_count'))]);

fclose($output);
exit;
?>