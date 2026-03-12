<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// Get filters from GET parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$customer_id_filter = (int)($_GET['customer_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Build WHERE clause
$where = "WHERE i.business_id = ? AND DATE(i.created_at) BETWEEN ? AND ?";
$params = [$business_id, $start_date, $end_date];

if ($user_role !== 'admin' && $current_shop_id) {
    $where .= " AND i.shop_id = ?";
    $params[] = $current_shop_id;
}

if ($customer_id_filter > 0) {
    $where .= " AND i.customer_id = ?";
    $params[] = $customer_id_filter;
}

if ($search) {
    $where .= " AND (i.invoice_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Fetch invoices with complete details
$stmt = $pdo->prepare("
    SELECT 
        i.id,
        i.invoice_number,
        DATE(i.created_at) as invoice_date,
        TIME(i.created_at) as invoice_time,
        i.total,
        i.pending_amount,
        i.cash_amount,
        i.upi_amount,
        i.bank_amount,
        i.cheque_amount,
        i.change_given,
        i.gst_amount,
        i.discount_amount,
        c.name as customer_name,
        c.phone as customer_phone,
        c.gstin as customer_gstin,
        u.full_name as seller_name,
        s.shop_name,
        (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.id) as item_count
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.seller_id = u.id
    LEFT JOIN shops s ON i.shop_id = s.id
    $where
    ORDER BY i.created_at DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Calculate totals
$total_sales = 0;
$total_pending = 0;
$total_collected = 0;
$total_items = 0;
$cash_total = 0;
$upi_total = 0;
$bank_total = 0;
$cheque_total = 0;

foreach ($invoices as $invoice) {
    $total_sales += $invoice['total'];
    $total_pending += $invoice['pending_amount'];
    $total_collected += ($invoice['total'] - $invoice['pending_amount']);
    $total_items += $invoice['item_count'];
    $cash_total += $invoice['cash_amount'];
    $upi_total += $invoice['upi_amount'];
    $bank_total += $invoice['bank_amount'];
    $cheque_total += $invoice['cheque_amount'];
}

// Set headers for Excel file
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="invoices_export_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Start Excel content
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';

// Company Header
echo '<table border="0" width="100%" style="margin-bottom: 20px;">';
echo '<tr>';
echo '<td><h2>' . htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') . '</h2></td>';
echo '<td align="right"><h3>Invoices Report</h3></td>';
echo '</tr>';
echo '<tr>';
echo '<td>Export Date: ' . date('d-m-Y H:i:s') . '</td>';
echo '<td align="right">Date Range: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)) . '</td>';
echo '</tr>';
echo '</table>';

// Summary Statistics
echo '<table border="1" width="100%" style="margin-bottom: 20px; background-color: #f8f9fc;">';
echo '<tr style="background-color: #4e73df; color: white;">';
echo '<th colspan="4" style="padding: 10px; text-align: center; font-weight: bold;">SUMMARY STATISTICS</th>';
echo '</tr>';
echo '<tr>';
echo '<td style="padding: 8px; text-align: center;"><strong>Total Invoices</strong><br>' . count($invoices) . '</td>';
echo '<td style="padding: 8px; text-align: center;"><strong>Total Sales</strong><br>₹' . number_format($total_sales, 2) . '</td>';
echo '<td style="padding: 8px; text-align: center;"><strong>Collected</strong><br>₹' . number_format($total_collected, 2) . '</td>';
echo '<td style="padding: 8px; text-align: center;"><strong>Pending</strong><br>₹' . number_format($total_pending, 2) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td style="padding: 8px; text-align: center;"><strong>Cash</strong><br>₹' . number_format($cash_total, 2) . '</td>';
echo '<td style="padding: 8px; text-align: center;"><strong>UPI</strong><br>₹' . number_format($upi_total, 2) . '</td>';
echo '<td style="padding: 8px; text-align: center;"><strong>Bank</strong><br>₹' . number_format($bank_total, 2) . '</td>';
echo '<td style="padding: 8px; text-align: center;"><strong>Cheque</strong><br>₹' . number_format($cheque_total, 2) . '</td>';
echo '</tr>';
echo '</table>';

// Invoices Data Table
echo '<table border="1" width="100%" cellpadding="5" cellspacing="0">';
echo '<tr style="background-color: #4e73df; color: white;">';
echo '<th style="padding: 10px; font-weight: bold;">#</th>';
echo '<th style="padding: 10px; font-weight: bold;">Invoice Details</th>';
echo '<th style="padding: 10px; font-weight: bold;">Customer</th>';
echo '<th style="padding: 10px; font-weight: bold;">Items</th>';
echo '<th style="padding: 10px; font-weight: bold;">Amount Details</th>';
echo '<th style="padding: 10px; font-weight: bold;">Payment Details</th>';
echo '<th style="padding: 10px; font-weight: bold;">Status</th>';
echo '</tr>';

// Invoices Data Rows
foreach ($invoices as $index => $invoice) {
    $invoice_total = (float)$invoice['total'];
    $pending = (float)$invoice['pending_amount'];
    $collected = $invoice_total - $pending;
    $payment_status = $pending == 0 ? 'Paid' : ($collected > 0 ? 'Partial' : 'Unpaid');
    
    // Determine status color class
    $status_class = '';
    switch ($payment_status) {
        case 'Paid': $status_class = 'background-color: #d4edda; color: #155724;'; break;
        case 'Partial': $status_class = 'background-color: #fff3cd; color: #856404;'; break;
        case 'Unpaid': $status_class = 'background-color: #f8d7da; color: #721c24;'; break;
    }
    
    echo '<tr>';
    
    // Serial Number
    echo '<td style="padding: 8px; text-align: center;">' . ($index + 1) . '</td>';
    
    // Invoice Details
    echo '<td style="padding: 8px;">';
    echo '<strong>Invoice #:</strong> ' . htmlspecialchars($invoice['invoice_number']) . '<br>';
    echo '<strong>Date:</strong> ' . $invoice['invoice_date'] . '<br>';
    echo '<strong>Time:</strong> ' . $invoice['invoice_time'] . '<br>';
    echo '<strong>Seller:</strong> ' . htmlspecialchars($invoice['seller_name'] ?? 'N/A') . '<br>';
    echo '<strong>Shop:</strong> ' . htmlspecialchars($invoice['shop_name'] ?? 'Main Shop');
    echo '</td>';
    
    // Customer Details
    echo '<td style="padding: 8px;">';
    echo '<strong>' . htmlspecialchars($invoice['customer_name'] ?? 'Walk-in Customer') . '</strong><br>';
    echo '<strong>Phone:</strong> ' . ($invoice['customer_phone'] ? htmlspecialchars($invoice['customer_phone']) : 'N/A') . '<br>';
    if ($invoice['customer_gstin']) {
        echo '<strong>GSTIN:</strong> ' . htmlspecialchars($invoice['customer_gstin']);
    }
    echo '</td>';
    
    // Items Count
    echo '<td style="padding: 8px; text-align: center;">';
    echo '<strong>' . $invoice['item_count'] . '</strong><br>';
    echo 'items';
    echo '</td>';
    
    // Amount Details
    echo '<td style="padding: 8px;">';
    echo '<strong>Total:</strong> ₹' . number_format($invoice_total, 2) . '<br>';
    if ($invoice['discount_amount'] > 0) {
        echo '<strong>Discount:</strong> -₹' . number_format($invoice['discount_amount'], 2) . '<br>';
    }
    if ($invoice['gst_amount'] > 0) {
        echo '<strong>GST:</strong> ₹' . number_format($invoice['gst_amount'], 2) . '<br>';
    }
    echo '<strong>Collected:</strong> ₹' . number_format($collected, 2) . '<br>';
    echo '<strong>Pending:</strong> ₹' . number_format($pending, 2);
    echo '</td>';
    
    // Payment Details
    echo '<td style="padding: 8px;">';
    if ($invoice['cash_amount'] > 0) {
        echo '<strong>Cash:</strong> ₹' . number_format($invoice['cash_amount'], 2) . '<br>';
    }
    if ($invoice['upi_amount'] > 0) {
        echo '<strong>UPI:</strong> ₹' . number_format($invoice['upi_amount'], 2) . '<br>';
    }
    if ($invoice['bank_amount'] > 0) {
        echo '<strong>Bank:</strong> ₹' . number_format($invoice['bank_amount'], 2) . '<br>';
    }
    if ($invoice['cheque_amount'] > 0) {
        echo '<strong>Cheque:</strong> ₹' . number_format($invoice['cheque_amount'], 2) . '<br>';
    }
    if ($invoice['change_given'] > 0) {
        echo '<strong>Change Given:</strong> ₹' . number_format($invoice['change_given'], 2) . '<br>';
    }
    echo '</td>';
    
    // Status
    echo '<td style="padding: 8px; text-align: center; ' . $status_class . ' font-weight: bold;">';
    echo $payment_status;
    if ($pending > 0) {
        echo '<br><small>(' . ($invoice_total > 0 ? number_format(($collected / $invoice_total) * 100, 1) : 0) . '% Paid)</small>';
    }
    echo '</td>';
    
    echo '</tr>';
}

// If no invoices found
if (empty($invoices)) {
    echo '<tr>';
    echo '<td colspan="7" style="padding: 20px; text-align: center; color: #6c757d;">';
    echo 'No invoices found for the selected filters';
    echo '</td>';
    echo '</tr>';
}

// Footer with totals
echo '<tr style="background-color: #e3f2fd; font-weight: bold;">';
echo '<td colspan="3" style="padding: 10px; text-align: right;">GRAND TOTALS:</td>';
echo '<td style="padding: 10px; text-align: center;">' . $total_items . ' items</td>';
echo '<td style="padding: 10px; text-align: left;">';
echo 'Sales: ₹' . number_format($total_sales, 2) . '<br>';
echo 'Collected: ₹' . number_format($total_collected, 2) . '<br>';
echo 'Pending: ₹' . number_format($total_pending, 2);
echo '</td>';
echo '<td style="padding: 10px; text-align: left;">';
echo 'Cash: ₹' . number_format($cash_total, 2) . '<br>';
echo 'UPI: ₹' . number_format($upi_total, 2) . '<br>';
echo 'Bank: ₹' . number_format($bank_total, 2) . '<br>';
echo 'Cheque: ₹' . number_format($cheque_total, 2);
echo '</td>';
echo '<td style="padding: 10px; text-align: center;">';
echo 'Invoices: ' . count($invoices) . '<br>';
echo 'Paid: ' . count(array_filter($invoices, fn($inv) => $inv['pending_amount'] == 0)) . '<br>';
echo 'Pending: ' . count(array_filter($invoices, fn($inv) => $inv['pending_amount'] > 0));
echo '</td>';
echo '</tr>';

// Filter Information
echo '<tr style="background-color: #fff3cd; font-size: 11px;">';
echo '<td colspan="7" style="padding: 8px;">';
echo '<strong>Filter Information:</strong> ';
echo 'Business: ' . htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') . ' | ';
echo 'Date Range: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)) . ' | ';
echo 'Records: ' . count($invoices) . ' | ';
echo 'Generated: ' . date('d-m-Y H:i:s');
if ($customer_id_filter > 0) {
    $customer_name = '';
    foreach ($invoices as $inv) {
        if ($inv['customer_name']) {
            $customer_name = $inv['customer_name'];
            break;
        }
    }
    echo ' | Customer: ' . htmlspecialchars($customer_name);
}
if ($search) {
    echo ' | Search: ' . htmlspecialchars($search);
}
echo '</td>';
echo '</tr>';

echo '</table>';

// Payment Mode Summary Table
if ($total_sales > 0) {
    echo '<table border="1" width="100%" style="margin-top: 20px; background-color: #f8f9fc;">';
    echo '<tr style="background-color: #36b9cc; color: white;">';
    echo '<th colspan="4" style="padding: 10px; text-align: center; font-weight: bold;">PAYMENT MODE SUMMARY</th>';
    echo '</tr>';
    echo '<tr>';
    echo '<td style="padding: 8px; text-align: center;"><strong>Payment Mode</strong></td>';
    echo '<td style="padding: 8px; text-align: center;"><strong>Amount (₹)</strong></td>';
    echo '<td style="padding: 8px; text-align: center;"><strong>Percentage</strong></td>';
    echo '<td style="padding: 8px; text-align: center;"><strong>Average per Invoice</strong></td>';
    echo '</tr>';
    
    $modes = [
        ['Cash', $cash_total],
        ['UPI', $upi_total],
        ['Bank Transfer', $bank_total],
        ['Cheque', $cheque_total]
    ];
    
    foreach ($modes as $mode) {
        $mode_name = $mode[0];
        $mode_amount = $mode[1];
        $percentage = $total_collected > 0 ? ($mode_amount / $total_collected) * 100 : 0;
        $average = count($invoices) > 0 ? $mode_amount / count($invoices) : 0;
        
        echo '<tr>';
        echo '<td style="padding: 8px; text-align: center;">' . $mode_name . '</td>';
        echo '<td style="padding: 8px; text-align: right;">₹' . number_format($mode_amount, 2) . '</td>';
        echo '<td style="padding: 8px; text-align: center;">' . number_format($percentage, 1) . '%</td>';
        echo '<td style="padding: 8px; text-align: right;">₹' . number_format($average, 2) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
}

echo '</body></html>';
exit();