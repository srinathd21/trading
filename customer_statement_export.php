<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager', 'accountant'])) {
    header('Location: dashboard.php');
    exit();
}

function isValidDateString($date, $format = 'Y-m-d')
{
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function safeText($value, $default = '')
{
    return htmlspecialchars((string)($value ?? $default), ENT_QUOTES, 'UTF-8');
}

$business_id = (int)($_SESSION['business_id'] ?? $_SESSION['current_business_id'] ?? 0);
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customer_id <= 0 || $business_id <= 0) {
    $_SESSION['error'] = 'Invalid customer selected.';
    header('Location: customers.php');
    exit();
}

// Keep connection collation consistent
try {
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Exception $e) {
    // ignore
}

// Get date filters
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : date('Y-m-01');
$to_date   = isset($_GET['to_date']) ? trim($_GET['to_date']) : date('Y-m-d');

if (!isValidDateString($from_date)) {
    $from_date = date('Y-m-01');
}
if (!isValidDateString($to_date)) {
    $to_date = date('Y-m-d');
}
if ($from_date > $to_date) {
    $tmp = $from_date;
    $from_date = $to_date;
    $to_date = $tmp;
}

// Get business details
$businessStmt = $pdo->prepare("
    SELECT business_name, gstin, address, phone, email
    FROM businesses
    WHERE id = ?
    LIMIT 1
");
$businessStmt->execute([$business_id]);
$business = $businessStmt->fetch(PDO::FETCH_ASSOC);

// Get customer details
$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE customer_id = c.id AND business_id = ?) as total_purchases,
           (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = ?) as total_paid,
           (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND business_id = ?) as invoice_count
    FROM customers c
    WHERE c.id = ? AND c.business_id = ?
    LIMIT 1
");
$stmt->execute([$business_id, $business_id, $business_id, $customer_id, $business_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    $_SESSION['error'] = 'Customer not found.';
    header('Location: customers.php');
    exit();
}

// All invoices should show as debit, payments as credit, adjustments as per type
$transactions_sql = "
    SELECT
        CAST('invoice' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as transaction_type,
        i.id as reference_id,
        CONVERT(COALESCE(i.invoice_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as reference_no,
        i.created_at as transaction_date,
        i.total as debit,
        0 as credit,
        i.paid_amount as paid_amount,
        CONVERT(COALESCE(i.payment_status, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as payment_status,
        CONVERT(COALESCE(i.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as notes,
        i.created_at,
        CONVERT(CONCAT('Invoice Created', CASE WHEN i.invoice_type IS NOT NULL AND i.invoice_type <> '' THEN CONCAT(' - ', i.invoice_type) ELSE '' END) USING utf8mb4) COLLATE utf8mb4_unicode_ci as description
    FROM invoices i
    WHERE i.customer_id = ? AND i.business_id = ?
      AND DATE(i.created_at) BETWEEN ? AND ?

    UNION ALL

    SELECT
        CAST('payment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as transaction_type,
        ip.id as reference_id,
        CONVERT(COALESCE(ip.reference_no, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as reference_no,
        ip.payment_date as transaction_date,
        0 as debit,
        ip.payment_amount as credit,
        NULL as paid_amount,
        CAST('payment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as payment_status,
        CONVERT(COALESCE(ip.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as notes,
        ip.created_at,
        CONVERT(CONCAT('Payment (', COALESCE(ip.payment_method, ''), ') - Invoice #', COALESCE(i.invoice_number, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci as description
    FROM invoice_payments ip
    INNER JOIN invoices i ON ip.invoice_id = i.id
    WHERE i.customer_id = ? AND i.business_id = ?
      AND DATE(ip.payment_date) BETWEEN ? AND ?

    UNION ALL

    SELECT
        CAST('adjustment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as transaction_type,
        ca.id as reference_id,
        CONVERT(COALESCE(ca.description, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as reference_no,
        ca.adjustment_date as transaction_date,
        CASE WHEN ca.adjustment_type = 'debit' THEN ca.amount ELSE 0 END as debit,
        CASE WHEN ca.adjustment_type = 'credit' THEN ca.amount ELSE 0 END as credit,
        NULL as paid_amount,
        CONVERT(COALESCE(ca.adjustment_type, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as payment_status,
        CONVERT(COALESCE(ca.description, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as notes,
        ca.created_at,
        CONVERT(CONCAT(UPPER(COALESCE(ca.adjustment_type, 'adjustment')), ' Adjustment') USING utf8mb4) COLLATE utf8mb4_unicode_ci as description
    FROM customer_credit_adjustments ca
    WHERE ca.customer_id = ? AND ca.business_id = ?
      AND DATE(ca.adjustment_date) BETWEEN ? AND ?

    ORDER BY transaction_date ASC, created_at ASC
";

$stmt = $pdo->prepare($transactions_sql);
$stmt->execute([
    $customer_id, $business_id, $from_date, $to_date,
    $customer_id, $business_id, $from_date, $to_date,
    $customer_id, $business_id, $from_date, $to_date
]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Opening balance
$opening_sql = "
    SELECT
        COALESCE((
            SELECT SUM(total)
            FROM invoices
            WHERE customer_id = ? AND business_id = ?
              AND DATE(created_at) < ?
        ), 0) as total_invoices_before,

        COALESCE((
            SELECT SUM(ip.payment_amount)
            FROM invoice_payments ip
            INNER JOIN invoices i ON ip.invoice_id = i.id
            WHERE i.customer_id = ? AND i.business_id = ?
              AND DATE(ip.payment_date) < ?
        ), 0) as total_payments_before,

        COALESCE((
            SELECT SUM(
                CASE
                    WHEN adjustment_type = 'debit' THEN amount
                    WHEN adjustment_type = 'credit' THEN -amount
                    ELSE 0
                END
            )
            FROM customer_credit_adjustments
            WHERE customer_id = ? AND business_id = ?
              AND DATE(adjustment_date) < ?
        ), 0) as adjustment_balance_before
";
$stmt = $pdo->prepare($opening_sql);
$stmt->execute([
    $customer_id, $business_id, $from_date,
    $customer_id, $business_id, $from_date,
    $customer_id, $business_id, $from_date
]);
$opening = $stmt->fetch(PDO::FETCH_ASSOC);

$total_invoices_before     = isset($opening['total_invoices_before']) ? (float)$opening['total_invoices_before'] : 0;
$total_payments_before     = isset($opening['total_payments_before']) ? (float)$opening['total_payments_before'] : 0;
$adjustment_balance_before = isset($opening['adjustment_balance_before']) ? (float)$opening['adjustment_balance_before'] : 0;

$opening_balance = $total_invoices_before - $total_payments_before + $adjustment_balance_before;

$period_summary = [
    'total_invoices' => 0,
    'total_payments' => 0,
    'total_adjustment_debit' => 0,
    'total_adjustment_credit' => 0
];

$invoice_count = 0;
$payment_count = 0;
$adjustment_count = 0;

if (!empty($transactions)) {
    foreach ($transactions as $t) {
        $type   = isset($t['transaction_type']) ? $t['transaction_type'] : '';
        $debit  = isset($t['debit']) ? (float)$t['debit'] : 0;
        $credit = isset($t['credit']) ? (float)$t['credit'] : 0;

        if ($type === 'invoice') {
            $period_summary['total_invoices'] += $debit;
            $invoice_count++;
        } elseif ($type === 'payment') {
            $period_summary['total_payments'] += $credit;
            $payment_count++;
        } elseif ($type === 'adjustment') {
            $period_summary['total_adjustment_debit'] += $debit;
            $period_summary['total_adjustment_credit'] += $credit;
            $adjustment_count++;
        }
    }
}

$closing_balance = $opening_balance
                 + $period_summary['total_invoices']
                 - $period_summary['total_payments']
                 + $period_summary['total_adjustment_debit']
                 - $period_summary['total_adjustment_credit'];

$outstanding_sql = "
    SELECT invoice_number, total, paid_amount, (total - paid_amount) as outstanding, created_at
    FROM invoices
    WHERE customer_id = ? AND business_id = ?
      AND payment_status IN ('pending', 'partial')
      AND (total - paid_amount) > 0
    ORDER BY created_at ASC
";
$stmt = $pdo->prepare($outstanding_sql);
$stmt->execute([$customer_id, $business_id]);
$outstanding_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$business_name    = $business['business_name'] ?? ($_SESSION['business_name'] ?? 'Your Business');
$business_gstin   = $business['gstin'] ?? ($_SESSION['business_gst'] ?? 'Not Available');
$business_address = $business['address'] ?? ($_SESSION['business_address'] ?? '');
$business_phone   = $business['phone'] ?? '';
$business_email   = $business['email'] ?? '';

$customer_name    = $customer['name'] ?? '';
$customer_phone   = $customer['phone'] ?? '';
$customer_email   = $customer['email'] ?? '';
$customer_address = $customer['address'] ?? '';
$customer_gstin   = $customer['gstin'] ?? '';
$customer_type    = $customer['customer_type'] ?? 'customer';

// Start building HTML content
$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Statement - ' . safeText($customer_name) . '</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 12px;
            color: #222;
            margin: 0;
            padding: 20px;
            background: #fff;
        }
        .header {
            border: 1px solid #333;
            padding: 14px;
            margin-bottom: 16px;
        }
        .header-table,
        .summary-table,
        .txn-table,
        .outstanding-table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-table td {
            vertical-align: top;
            width: 50%;
        }
        .title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 6px;
        }
        .sub-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .muted {
            color: #555;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin: 16px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #999;
        }
        .summary-table td {
            border: 1px solid #999;
            padding: 8px;
            text-align: center;
            width: 25%;
        }
        .summary-label {
            font-size: 11px;
            color: #666;
            display: block;
            margin-bottom: 4px;
        }
        .summary-value {
            font-size: 15px;
            font-weight: bold;
        }
        .txn-table th,
        .txn-table td,
        .outstanding-table th,
        .outstanding-table td {
            border: 1px solid #666;
            padding: 6px;
            vertical-align: top;
        }
        .txn-table th,
        .outstanding-table th {
            background: #efefef;
            text-align: left;
        }
        .text-end {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .debit { color: #b00020; font-weight: bold; }
        .credit { color: #0b7a28; font-weight: bold; }
        .small {
            font-size: 10px;
            color: #666;
        }
        .opening-row,
        .closing-row {
            background: #f3f3f3;
            font-weight: bold;
        }
        .invoice-row {
            background: #fff6e8;
        }
        .payment-row {
            background: #eefbf0;
        }
        .adjustment-row {
            background: #edf6ff;
        }
        .footer-note {
            margin-top: 18px;
            text-align: center;
            font-size: 11px;
            color: #666;
        }
        @media print {
            body { padding: 0; }
        }
    </style>
</head>
<body>

    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    <div class="title">' . safeText($business_name) . '</div>
                    <div><strong>GSTIN:</strong> ' . safeText($business_gstin, 'Not Available') . '</div>
                    ' . ($business_address !== '' ? '<div>' . nl2br(safeText($business_address)) . '</div>' : '') . '
                    ' . ($business_phone !== '' ? '<div><strong>Phone:</strong> ' . safeText($business_phone) . '</div>' : '') . '
                    ' . ($business_email !== '' ? '<div><strong>Email:</strong> ' . safeText($business_email) . '</div>' : '') . '
                </td>
                <td class="text-end">
                    <div class="title">Customer Statement</div>
                    <div><strong>Period:</strong> ' . date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date)) . '</div>
                    <div><strong>Generated:</strong> ' . date('d M Y h:i A') . '</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">Customer Details</div>
    <table class="summary-table">
        <tr>
            <td><span class="summary-label">Customer Name</span><span class="summary-value">' . safeText($customer_name) . '</span></td>
            <td><span class="summary-label">Phone</span><span class="summary-value">' . safeText($customer_phone, '—') . '</span></td>
            <td><span class="summary-label">Customer Type</span><span class="summary-value">' . safeText(ucfirst($customer_type)) . '</span></td>
            <td><span class="summary-label">GSTIN</span><span class="summary-value">' . safeText($customer_gstin, '—') . '</span></td>
        </tr>
    </table>

    ' . (($customer_address !== '' || $customer_email !== '') ? '
    <div style="margin-top:8px;">
        ' . ($customer_email !== '' ? '<div><strong>Email:</strong> ' . safeText($customer_email) . '</div>' : '') . '
        ' . ($customer_address !== '' ? '<div><strong>Address:</strong> ' . nl2br(safeText($customer_address)) . '</div>' : '') . '
    </div>' : '') . '

    <div class="section-title">Statement Summary</div>
    <table class="summary-table">
        <tr>
            <td>
                <span class="summary-label">Opening Balance</span>
                <span class="summary-value">₹' . number_format(abs($opening_balance), 2) . '</span>
                <div class="small">' . ($opening_balance > 0 ? 'Dr' : ($opening_balance < 0 ? 'Cr' : 'Settled')) . '</div>
            </td>
            <td>
                <span class="summary-label">Total Debit Invoices</span>
                <span class="summary-value">₹' . number_format($period_summary['total_invoices'], 2) . '</span>
                <div class="small">' . (int)$invoice_count . ' invoice(s)</div>
            </td>
            <td>
                <span class="summary-label">Total Credit Payments</span>
                <span class="summary-value">₹' . number_format($period_summary['total_payments'], 2) . '</span>
                <div class="small">' . (int)$payment_count . ' payment(s)</div>
            </td>
            <td>
                <span class="summary-label">Closing Balance</span>
                <span class="summary-value">₹' . number_format(abs($closing_balance), 2) . '</span>
                <div class="small">' . ($closing_balance > 0 ? 'Dr' : ($closing_balance < 0 ? 'Cr' : 'Settled')) . '</div>
            </td>
        </tr>
    </table>

    <div class="section-title">Transaction Statement</div>
    <table class="txn-table">
        <thead>
            <tr>
                <th style="width: 12%;">Date</th>
                <th style="width: 14%;">Type</th>
                <th style="width: 14%;">Reference</th>
                <th style="width: 28%;">Description</th>
                <th style="width: 10%;" class="text-end">Debit</th>
                <th style="width: 10%;" class="text-end">Credit</th>
                <th style="width: 12%;" class="text-end">Balance</th>
            </tr>
        </thead>
        <tbody>';

$running_balance = $opening_balance;

$html .= '<tr class="opening-row">
                <td colspan="6" class="text-end">Opening Balance (as on ' . date('d M Y', strtotime($from_date)) . ')</td>
                <td class="text-end">
                    ₹' . number_format(abs($running_balance), 2) . '<br>
                    <span class="small">' . ($running_balance > 0 ? 'Dr' : ($running_balance < 0 ? 'Cr' : '')) . '</span>
                </td>
            </tr>';

if (!empty($transactions)) {
    foreach ($transactions as $t) {
        $type = $t['transaction_type'] ?? '';
        $debit = isset($t['debit']) ? (float)$t['debit'] : 0;
        $credit = isset($t['credit']) ? (float)$t['credit'] : 0;
        $payment_status = $t['payment_status'] ?? '';
        $running_balance += ($debit - $credit);

        $row_class = '';
        $type_label = '';
        if ($type === 'invoice') {
            $row_class = 'invoice-row';
            $type_label = 'DEBIT INVOICE';
        } elseif ($type === 'payment') {
            $row_class = 'payment-row';
            $type_label = 'CREDIT PAYMENT';
        } else {
            $row_class = 'adjustment-row';
            $type_label = strtoupper($payment_status === 'debit' ? 'DEBIT ADJUSTMENT' : 'CREDIT ADJUSTMENT');
        }

        $html .= '<tr class="' . $row_class . '">
                    <td>
                        ' . (!empty($t['transaction_date']) ? date('d M Y', strtotime($t['transaction_date'])) : '—') . '<br>
                        <span class="small">' . (!empty($t['created_at']) ? date('h:i A', strtotime($t['created_at'])) : '') . '</span>
                    </td>
                    <td>
                        ' . safeText($type_label) . '
                        ' . ($type === 'invoice' && $payment_status !== '' ? '<br><span class="small">' . safeText(ucfirst($payment_status)) . '</span>' : '') . '
                    </td>
                    <td>' . safeText($t['reference_no'] ?? '—') . '</td>
                    <td>
                        ' . safeText($t['description'] ?? '') . '
                        ' . (!empty($t['notes']) ? '<br><span class="small">' . safeText($t['notes']) . '</span>' : '') . '
                    </td>
                    <td class="text-end debit">' . ($debit > 0 ? '₹' . number_format($debit, 2) : '—') . '</td>
                    <td class="text-end credit">' . ($credit > 0 ? '₹' . number_format($credit, 2) : '—') . '</td>
                    <td class="text-end">
                        ₹' . number_format(abs($running_balance), 2) . '<br>
                        <span class="small">' . ($running_balance > 0 ? 'Dr' : ($running_balance < 0 ? 'Cr' : '')) . '</span>
                    </td>
                </tr>';
    }
} else {
    $html .= '<tr>
                <td colspan="7" class="text-center">No transactions found for this period.</td>
            </tr>';
}

$html .= '<tr class="closing-row">
                <td colspan="6" class="text-end">Closing Balance (as on ' . date('d M Y', strtotime($to_date)) . ')</td>
                <td class="text-end">
                    ₹' . number_format(abs($closing_balance), 2) . '<br>
                    <span class="small">' . ($closing_balance > 0 ? 'Dr' : ($closing_balance < 0 ? 'Cr' : '')) . '</span>
                </td>
            </tr>
        </tbody>
    </table>';

if (!empty($outstanding_invoices)) {
    $html .= '<div class="section-title">Outstanding Invoices</div>
        <table class="outstanding-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Outstanding</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($outstanding_invoices as $inv) {
        $inv_total = isset($inv['total']) ? (float)$inv['total'] : 0;
        $inv_paid = isset($inv['paid_amount']) ? (float)$inv['paid_amount'] : 0;
        $inv_outstanding = isset($inv['outstanding']) ? (float)$inv['outstanding'] : 0;

        $html .= '<tr>
                    <td>' . safeText($inv['invoice_number'] ?? '') . '</td>
                    <td>' . (!empty($inv['created_at']) ? date('d M Y', strtotime($inv['created_at'])) : '—') . '</td>
                    <td class="text-end">₹' . number_format($inv_total, 2) . '</td>
                    <td class="text-end">₹' . number_format($inv_paid, 2) . '</td>
                    <td class="text-end debit">₹' . number_format($inv_outstanding, 2) . '</td>
                    <td class="text-center">' . ($inv_paid > 0 ? 'Partial' : 'Unpaid') . '</td>
                </tr>';
    }

    $html .= '</tbody>
        </table>';
}

$html .= '<div class="footer-note">
        This is a computer generated statement and does not require signature.<br>
        Dr (Debit) = Customer owes you | Cr (Credit) = You owe customer
    </div>

</body>
</html>';

// ============================================================
// FORCE PDF DOWNLOAD using DOMPDF
// ============================================================

// Try to find Composer autoloader
$autoload_paths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php'
];

$autoload_found = false;
foreach ($autoload_paths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        $autoload_found = true;
        break;
    }
}

// If DOMPDF is available, generate and download PDF
if ($autoload_found && class_exists('\Dompdf\Dompdf')) {
    try {
        // Ensure no output buffering issues
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans'
        ]);
        
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $filename = 'customer_statement_' . $customer_id . '_' . date('Ymd_His') . '.pdf';
        
        // Stream the PDF to browser with forced download
        $dompdf->stream($filename, ['Attachment' => true]);
        exit();
        
    } catch (Exception $e) {
        // If PDF generation fails, fall back to HTML output
        error_log("DOMPDF Error: " . $e->getMessage());
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit();
    }
}

// Alternative: Try mPDF if available
if ($autoload_found && class_exists('\Mpdf\Mpdf')) {
    try {
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => sys_get_temp_dir()
        ]);
        
        $mpdf->WriteHTML($html);
        
        $filename = 'customer_statement_' . $customer_id . '_' . date('Ymd_His') . '.pdf';
        
        $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
        exit();
        
    } catch (Exception $e) {
        error_log("mPDF Error: " . $e->getMessage());
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit();
    }
}

// Fallback: Output HTML directly if no PDF library is available
header('Content-Type: text/html; charset=utf-8');
echo $html;
exit();
?>