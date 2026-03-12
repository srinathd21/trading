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
$payment_mode_filter = $_GET['payment_mode'] ?? '';
$collector_filter = (int)($_GET['collector_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Check if payment_entries table exists
$tables_stmt = $pdo->query("SHOW TABLES LIKE 'payment_entries'");
$payment_table_exists = $tables_stmt->rowCount() > 0;

if ($payment_table_exists) {
    // If payment_entries table exists, use it
    $where = "WHERE p.business_id = ? AND DATE(p.created_at) BETWEEN ? AND ?";
    $params = [$business_id, $start_date, $end_date];
    
    if ($user_role !== 'admin' && $current_shop_id) {
        $where .= " AND p.shop_id = ?";
        $params[] = $current_shop_id;
    }
    
    if ($customer_id_filter > 0) {
        $where .= " AND i.customer_id = ?";
        $params[] = $customer_id_filter;
    }
    
    if ($payment_mode_filter) {
        $where .= " AND p.payment_mode = ?";
        $params[] = $payment_mode_filter;
    }
    
    if ($collector_filter > 0) {
        $where .= " AND p.collected_by = ?";
        $params[] = $collector_filter;
    }
    
    if ($search) {
        $where .= " AND (p.payment_note LIKE ? OR p.reference_number LIKE ? OR i.invoice_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)";
        $like = "%$search%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    
    // Fetch payment history for export
    $stmt = $pdo->prepare("
        SELECT 
            p.id as payment_id,
            p.amount,
            p.payment_mode,
            p.reference_number,
            p.payment_note,
            DATE(p.created_at) as payment_date,
            TIME(p.created_at) as payment_time,
            i.invoice_number,
            i.total as invoice_total,
            i.pending_amount as invoice_pending,
            c.name as customer_name,
            c.phone as customer_phone,
            u.full_name as collector_name,
            s.shop_name
        FROM payment_entries p
        LEFT JOIN invoices i ON p.invoice_id = i.id
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN users u ON p.collected_by = u.id
        LEFT JOIN shops s ON p.shop_id = s.id
        $where
        ORDER BY p.created_at DESC
    ");
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
} else {
    // If payment_entries doesn't exist, create payment records from invoices
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
    
    // Fetch invoices for export
    $stmt = $pdo->prepare("
        SELECT 
            i.id as invoice_id,
            i.invoice_number,
            i.total as invoice_total,
            i.pending_amount as invoice_pending,
            i.cash_amount,
            i.upi_amount,
            i.bank_amount,
            i.cheque_amount,
            DATE(i.created_at) as invoice_date,
            TIME(i.created_at) as invoice_time,
            i.seller_id as collected_by,
            c.name as customer_name,
            c.phone as customer_phone,
            u.full_name as collector_name,
            s.shop_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN users u ON i.seller_id = u.id
        LEFT JOIN shops s ON i.shop_id = s.id
        $where
        ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
    
    // Convert invoices to payment records for export
    $payments = [];
    foreach ($invoices as $invoice) {
        $collected = $invoice['invoice_total'] - $invoice['invoice_pending'];
        
        if ($collected > 0) {
            // Create payment records for each payment mode
            $payment_modes = ['cash', 'upi', 'bank', 'cheque'];
            foreach ($payment_modes as $mode) {
                $amount = $invoice[$mode . '_amount'] ?? 0;
                if ($amount > 0) {
                    $payments[] = [
                        'payment_id' => $invoice['invoice_id'] . '_' . $mode,
                        'amount' => $amount,
                        'payment_mode' => $mode,
                        'reference_number' => '',
                        'payment_note' => 'Initial invoice payment',
                        'payment_date' => $invoice['invoice_date'],
                        'payment_time' => $invoice['invoice_time'],
                        'invoice_number' => $invoice['invoice_number'],
                        'invoice_total' => $invoice['invoice_total'],
                        'invoice_pending' => $invoice['invoice_pending'],
                        'customer_name' => $invoice['customer_name'],
                        'customer_phone' => $invoice['customer_phone'],
                        'collector_name' => $invoice['collector_name'],
                        'shop_name' => $invoice['shop_name']
                    ];
                }
            }
        }
    }
}

// Set headers for Excel file
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="payment_history_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Start Excel content
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
echo '<table border="1">';

// Excel Header
echo '<tr style="background-color: #4e73df; color: white;">';
echo '<th style="padding: 10px; font-weight: bold;">Payment ID</th>';
echo '<th style="padding: 10px; font-weight: bold;">Date</th>';
echo '<th style="padding: 10px; font-weight: bold;">Time</th>';
echo '<th style="padding: 10px; font-weight: bold;">Invoice No</th>';
echo '<th style="padding: 10px; font-weight: bold;">Customer Name</th>';
echo '<th style="padding: 10px; font-weight: bold;">Phone</th>';
echo '<th style="padding: 10px; font-weight: bold;">Amount</th>';
echo '<th style="padding: 10px; font-weight: bold;">Payment Mode</th>';
echo '<th style="padding: 10px; font-weight: bold;">Reference No</th>';
echo '<th style="padding: 10px; font-weight: bold;">Notes</th>';
echo '<th style="padding: 10px; font-weight: bold;">Collected By</th>';
echo '<th style="padding: 10px; font-weight: bold;">Shop</th>';
echo '<th style="padding: 10px; font-weight: bold;">Invoice Total</th>';
echo '<th style="padding: 10px; font-weight: bold;">Pending Amount</th>';
echo '</tr>';

// Total calculation
$total_amount = 0;
$cash_total = 0;
$upi_total = 0;
$bank_total = 0;
$cheque_total = 0;

// Excel Data Rows
foreach ($payments as $payment) {
    $amount = (float)$payment['amount'];
    $total_amount += $amount;
    
    // Calculate mode totals
    switch ($payment['payment_mode']) {
        case 'cash': $cash_total += $amount; break;
        case 'upi': $upi_total += $amount; break;
        case 'bank': $bank_total += $amount; break;
        case 'cheque': $cheque_total += $amount; break;
    }
    
    echo '<tr>';
    echo '<td style="padding: 8px;">' . ($payment_table_exists ? $payment['payment_id'] : str_replace('_', ' - ', $payment['payment_id'])) . '</td>';
    echo '<td style="padding: 8px;">' . ($payment['payment_date'] ?? '') . '</td>';
    echo '<td style="padding: 8px;">' . ($payment['payment_time'] ?? '') . '</td>';
    echo '<td style="padding: 8px;">' . ($payment['invoice_number'] ?? 'N/A') . '</td>';
    echo '<td style="padding: 8px;">' . ($payment['customer_name'] ?? 'Walk-in Customer') . '</td>';
    echo '<td style="padding: 8px;">' . ($payment['customer_phone'] ?? '') . '</td>';
    echo '<td style="padding: 8px; text-align: right;">₹' . number_format($amount, 2) . '</td>';
    echo '<td style="padding: 8px; text-transform: capitalize;">' . $payment['payment_mode'] . '</td>';
    echo '<td style="padding: 8px;">' . ($payment['reference_number'] ?? '') . '</td>';
    echo '<td style="padding: 8px;">' . ($payment['payment_note'] ?? '') . '</td>';
    echo '<td style="padding: 8px;">' . ($payment['collector_name'] ?? 'System') . '</td>';
    echo '<td style="padding: 8px;">' . ($payment['shop_name'] ?? 'Main Shop') . '</td>';
    echo '<td style="padding: 8px; text-align: right;">₹' . number_format($payment['invoice_total'] ?? 0, 2) . '</td>';
    echo '<td style="padding: 8px; text-align: right;">₹' . number_format($payment['invoice_pending'] ?? 0, 2) . '</td>';
    echo '</tr>';
}

// Summary Row
echo '<tr style="background-color: #f8f9fc; font-weight: bold;">';
echo '<td colspan="6" style="padding: 10px; text-align: right;">TOTALS:</td>';
echo '<td style="padding: 10px; text-align: right;">₹' . number_format($total_amount, 2) . '</td>';
echo '<td colspan="7" style="padding: 10px;"></td>';
echo '</tr>';

// Payment Mode Breakdown
if ($total_amount > 0) {
    echo '<tr style="background-color: #e3f2fd;">';
    echo '<td colspan="3" style="padding: 8px; font-weight: bold;">Payment Mode Breakdown:</td>';
    echo '<td style="padding: 8px; text-align: right;">Cash:</td>';
    echo '<td style="padding: 8px; text-align: right;">₹' . number_format($cash_total, 2) . '</td>';
    echo '<td style="padding: 8px; text-align: right;">(' . ($total_amount > 0 ? number_format(($cash_total / $total_amount) * 100, 1) : 0) . '%)</td>';
    
    echo '<td style="padding: 8px; text-align: right;">UPI:</td>';
    echo '<td style="padding: 8px; text-align: right;">₹' . number_format($upi_total, 2) . '</td>';
    echo '<td style="padding: 8px; text-align: right;">(' . ($total_amount > 0 ? number_format(($upi_total / $total_amount) * 100, 1) : 0) . '%)</td>';
    
    echo '<td style="padding: 8px; text-align: right;">Bank:</td>';
    echo '<td style="padding: 8px; text-align: right;">₹' . number_format($bank_total, 2) . '</td>';
    echo '<td style="padding: 8px; text-align: right;">(' . ($total_amount > 0 ? number_format(($bank_total / $total_amount) * 100, 1) : 0) . '%)</td>';
    
    echo '<td style="padding: 8px; text-align: right;">Cheque:</td>';
    echo '<td style="padding: 8px; text-align: right;">₹' . number_format($cheque_total, 2) . '</td>';
    echo '<td style="padding: 8px; text-align: right;">(' . ($total_amount > 0 ? number_format(($cheque_total / $total_amount) * 100, 1) : 0) . '%)</td>';
    echo '</tr>';
}

// Filter Information Row
echo '<tr style="background-color: #fff3cd;">';
echo '<td colspan="14" style="padding: 8px; font-size: 11px;">';
echo 'Export Date: ' . date('d-m-Y H:i:s') . ' | ';
echo 'Date Range: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)) . ' | ';
echo 'Total Records: ' . count($payments) . ' | ';
echo 'Business: ' . htmlspecialchars($_SESSION['current_business_name'] ?? 'Business');
echo '</td>';
echo '</tr>';

echo '</table>';
echo '</body></html>';
exit();