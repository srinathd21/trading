<?php
// customer_statement_pdf.php
// PDF download for customer statement using customer_payments + customer_payment_allocations.
// This file does not use invoice_payments.

date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager', 'accountant'], true)) {
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

function moneyText($amount)
{
    return '₹' . number_format((float)$amount, 2);
}

$business_id = (int)($_SESSION['business_id'] ?? $_SESSION['current_business_id'] ?? 0);
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customer_id <= 0 || $business_id <= 0) {
    $_SESSION['error'] = 'Invalid customer selected.';
    header('Location: customers.php');
    exit();
}

try {
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Ignore collation setup failures.
}

$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : date('Y-m-d');

if (!isValidDateString($from_date)) {
    $from_date = date('Y-m-01');
}
if (!isValidDateString($to_date)) {
    $to_date = date('Y-m-d');
}
if ($from_date > $to_date) {
    [$from_date, $to_date] = [$to_date, $from_date];
}

$businessStmt = $pdo->prepare("SELECT business_name, gstin, address, phone, email FROM businesses WHERE id = ? LIMIT 1");
$businessStmt->execute([$business_id]);
$business = $businessStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$customerStmt = $pdo->prepare("\n    SELECT c.*,\n           (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE customer_id = c.id AND business_id = ?) AS total_purchases,\n           (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = ?) AS total_paid,\n           (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND business_id = ?) AS invoice_count\n    FROM customers c\n    WHERE c.id = ? AND c.business_id = ?\n    LIMIT 1\n");
$customerStmt->execute([$business_id, $business_id, $business_id, $customer_id, $business_id]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    $_SESSION['error'] = 'Customer not found.';
    header('Location: customers.php');
    exit();
}

$transactionsSql = "\n    SELECT\n        CAST('invoice' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_type,\n        i.id AS reference_id,\n        CONVERT(COALESCE(i.invoice_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,\n        i.created_at AS transaction_datetime,\n        DATE(i.created_at) AS transaction_date,\n        TIME(i.created_at) AS transaction_time,\n        i.total AS debit,\n        0 AS credit,\n        i.paid_amount AS paid_amount,\n        CONVERT(COALESCE(i.payment_status, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,\n        CONVERT(COALESCE(i.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,\n        i.created_at,\n        CONVERT(CONCAT('Invoice - ', COALESCE(i.invoice_type, 'Sale')) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description\n    FROM invoices i\n    WHERE i.customer_id = ?\n      AND i.business_id = ?\n      AND DATE(i.created_at) BETWEEN ? AND ?\n\n    UNION ALL\n\n    SELECT\n        CAST('payment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_type,\n        cp.id AS reference_id,\n        CONVERT(CASE WHEN COALESCE(cp.reference_no, '') <> '' THEN cp.reference_no ELSE CONCAT('PAY-', cp.id) END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,\n        cp.created_at AS transaction_datetime,\n        DATE(cp.payment_date) AS transaction_date,\n        TIME(cp.created_at) AS transaction_time,\n        0 AS debit,\n        COALESCE(SUM(cpa.allocated_amount), 0) AS credit,\n        NULL AS paid_amount,\n        CONVERT(COALESCE(cp.payment_type, 'payment') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,\n        CONVERT(COALESCE(cp.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,\n        cp.created_at,\n        CONVERT(\n            CONCAT(\n                CASE\n                    WHEN COALESCE(cp.payment_type, '') = 'outstanding' THEN 'Outstanding Collection'\n                    WHEN COALESCE(cp.payment_mode, '') = 'invoice_wise' THEN 'Invoice-wise Payment'\n                    WHEN COALESCE(cp.payment_mode, '') = 'bulk' THEN 'Overall Collection'\n                    ELSE 'Payment'\n                END,\n                ' (', COALESCE(cp.payment_method, ''), ')',\n                CASE\n                    WHEN GROUP_CONCAT(\n                        CONCAT(\n                            CASE\n                                WHEN cpa.allocation_type = 'invoice' THEN COALESCE(cpa.invoice_number, CONCAT('Invoice #', cpa.invoice_id))\n                                WHEN cpa.allocation_type = 'manual_credit' THEN 'Manual Outstanding'\n                                WHEN cpa.allocation_type = 'advance' THEN 'Advance'\n                                ELSE cpa.allocation_type\n                            END,\n                            ': ₹', FORMAT(cpa.allocated_amount, 2)\n                        ) ORDER BY cpa.id ASC SEPARATOR ', '\n                    ) IS NOT NULL\n                    THEN CONCAT(' - ', GROUP_CONCAT(\n                        CONCAT(\n                            CASE\n                                WHEN cpa.allocation_type = 'invoice' THEN COALESCE(cpa.invoice_number, CONCAT('Invoice #', cpa.invoice_id))\n                                WHEN cpa.allocation_type = 'manual_credit' THEN 'Manual Outstanding'\n                                WHEN cpa.allocation_type = 'advance' THEN 'Advance'\n                                ELSE cpa.allocation_type\n                            END,\n                            ': ₹', FORMAT(cpa.allocated_amount, 2)\n                        ) ORDER BY cpa.id ASC SEPARATOR ', '\n                    ))\n                    ELSE ''\n                END\n            ) USING utf8mb4\n        ) COLLATE utf8mb4_unicode_ci AS description\n    FROM customer_payments cp\n    LEFT JOIN customer_payment_allocations cpa\n           ON cpa.payment_id = cp.id\n          AND cpa.business_id = cp.business_id\n          AND cpa.customer_id = cp.customer_id\n          AND COALESCE(cpa.is_deleted, 0) = 0\n    WHERE cp.customer_id = ?\n      AND cp.business_id = ?\n      AND COALESCE(cp.is_deleted, 0) = 0\n      AND DATE(cp.payment_date) BETWEEN ? AND ?\n    GROUP BY cp.id, cp.business_id, cp.customer_id, cp.reference_no, cp.created_at, cp.payment_date, cp.payment_type, cp.payment_mode, cp.payment_method, cp.notes\n\n    UNION ALL\n\n    SELECT\n        CAST('adjustment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_type,\n        ca.id AS reference_id,\n        CONVERT(COALESCE(ca.description, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,\n        ca.created_at AS transaction_datetime,\n        DATE(ca.adjustment_date) AS transaction_date,\n        TIME(ca.created_at) AS transaction_time,\n        CASE WHEN ca.adjustment_type = 'debit' THEN ca.amount ELSE 0 END AS debit,\n        CASE WHEN ca.adjustment_type = 'credit' THEN ca.amount ELSE 0 END AS credit,\n        NULL AS paid_amount,\n        CONVERT(COALESCE(ca.adjustment_type, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,\n        CONVERT(COALESCE(ca.description, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,\n        ca.created_at,\n        CONVERT(CONCAT('Credit Adjustment (', COALESCE(ca.adjustment_type, ''), ')') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description\n    FROM customer_credit_adjustments ca\n    WHERE ca.customer_id = ?\n      AND ca.business_id = ?\n      AND DATE(ca.adjustment_date) BETWEEN ? AND ?\n\n    ORDER BY transaction_datetime ASC, created_at ASC\n";

$txnStmt = $pdo->prepare($transactionsSql);
$txnStmt->execute([
    $customer_id, $business_id, $from_date, $to_date,
    $customer_id, $business_id, $from_date, $to_date,
    $customer_id, $business_id, $from_date, $to_date
]);
$transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);

$manual_outstanding_balance = (($customer['outstanding_type'] ?? 'credit') === 'credit')
    ? (float)($customer['outstanding_amount'] ?? 0)
    : -(float)($customer['outstanding_amount'] ?? 0);

$invoiceOutstandingStmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(total - paid_amount, 0)), 0) FROM invoices WHERE customer_id = ? AND business_id = ?");
$invoiceOutstandingStmt->execute([$customer_id, $business_id]);
$invoice_outstanding_balance = (float)$invoiceOutstandingStmt->fetchColumn();
$live_balance = $invoice_outstanding_balance + $manual_outstanding_balance;

$openingSql = "\n    SELECT\n        COALESCE((\n            SELECT SUM(total)\n            FROM invoices\n            WHERE customer_id = ? AND business_id = ? AND DATE(created_at) < ?\n        ), 0) AS total_invoices_before,\n        COALESCE((\n            SELECT SUM(cpa.allocated_amount)\n            FROM customer_payments cp\n            INNER JOIN customer_payment_allocations cpa\n                    ON cpa.payment_id = cp.id\n                   AND cpa.business_id = cp.business_id\n                   AND cpa.customer_id = cp.customer_id\n                   AND COALESCE(cpa.is_deleted, 0) = 0\n            WHERE cp.customer_id = ?\n              AND cp.business_id = ?\n              AND COALESCE(cp.is_deleted, 0) = 0\n              AND DATE(cp.payment_date) < ?\n        ), 0) AS total_payments_before,\n        COALESCE((\n            SELECT SUM(CASE WHEN adjustment_type = 'debit' THEN amount WHEN adjustment_type = 'credit' THEN -amount ELSE 0 END)\n            FROM customer_credit_adjustments\n            WHERE customer_id = ? AND business_id = ? AND DATE(adjustment_date) < ?\n        ), 0) AS adjustment_balance_before\n";
$openingStmt = $pdo->prepare($openingSql);
$openingStmt->execute([
    $customer_id, $business_id, $from_date,
    $customer_id, $business_id, $from_date,
    $customer_id, $business_id, $from_date
]);
$opening = $openingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$opening_balance = (float)($opening['total_invoices_before'] ?? 0)
    - (float)($opening['total_payments_before'] ?? 0)
    + (float)($opening['adjustment_balance_before'] ?? 0);

$period_summary = [
    'total_invoices' => 0.00,
    'total_payments' => 0.00,
    'total_adjustment_debit' => 0.00,
    'total_adjustment_credit' => 0.00
];
$invoice_count = 0;
$payment_count = 0;
$adjustment_count = 0;

foreach ($transactions as $t) {
    $type = $t['transaction_type'] ?? '';
    $debit = (float)($t['debit'] ?? 0);
    $credit = (float)($t['credit'] ?? 0);

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

$closing_balance = $opening_balance
    + $period_summary['total_invoices']
    - $period_summary['total_payments']
    + $period_summary['total_adjustment_debit']
    - $period_summary['total_adjustment_credit'];

$outstandingStmt = $pdo->prepare("\n    SELECT invoice_number, total, paid_amount, GREATEST(total - paid_amount, 0) AS outstanding, created_at\n    FROM invoices\n    WHERE customer_id = ?\n      AND business_id = ?\n      AND GREATEST(total - paid_amount, 0) > 0.01\n    ORDER BY created_at ASC\n");
$outstandingStmt->execute([$customer_id, $business_id]);
$outstanding_invoices = $outstandingStmt->fetchAll(PDO::FETCH_ASSOC);

$business_name = $business['business_name'] ?? ($_SESSION['business_name'] ?? 'Your Business');
$business_gstin = $business['gstin'] ?? ($_SESSION['business_gst'] ?? 'Not Available');
$business_address = $business['address'] ?? ($_SESSION['business_address'] ?? '');
$business_phone = $business['phone'] ?? '';
$business_email = $business['email'] ?? '';

$customer_name = $customer['name'] ?? '';
$customer_phone = $customer['phone'] ?? '';
$customer_email = $customer['email'] ?? '';
$customer_address = $customer['address'] ?? '';
$customer_gstin = $customer['gstin'] ?? '';
$customer_type = $customer['customer_type'] ?? 'customer';

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Statement - <?= safeText($customer_name) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; margin: 0; padding: 18px; background: #fff; }
        .header { border: 1px solid #333; padding: 12px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; width: 50%; }
        .title { font-size: 19px; font-weight: bold; margin-bottom: 6px; }
        .section-title { font-size: 13px; font-weight: bold; margin: 14px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #999; }
        .summary-table td { border: 1px solid #999; padding: 7px; text-align: center; width: 25%; }
        .summary-label { font-size: 10px; color: #666; display: block; margin-bottom: 4px; text-transform: uppercase; }
        .summary-value { font-size: 14px; font-weight: bold; }
        .txn-table th, .txn-table td, .outstanding-table th, .outstanding-table td { border: 1px solid #666; padding: 5px; vertical-align: top; }
        .txn-table th, .outstanding-table th { background: #efefef; text-align: left; }
        .text-end { text-align: right; } .text-center { text-align: center; }
        .debit { color: #b00020; font-weight: bold; } .credit { color: #0b7a28; font-weight: bold; }
        .small { font-size: 9px; color: #666; }
        .opening-row, .closing-row { background: #f3f3f3; font-weight: bold; }
        .manual-row { background: #fff0f0; }
        .invoice-row { background: #fff6e8; }
        .payment-row { background: #eefbf0; }
        .adjustment-row { background: #edf6ff; }
        .footer-note { margin-top: 16px; text-align: center; font-size: 10px; color: #666; }
    </style>
</head>
<body>
<div class="header">
    <table class="header-table">
        <tr>
            <td>
                <div class="title"><?= safeText($business_name) ?></div>
                <div><strong>GSTIN:</strong> <?= safeText($business_gstin, 'Not Available') ?></div>
                <?php if ($business_address !== ''): ?><div><?= nl2br(safeText($business_address)) ?></div><?php endif; ?>
                <?php if ($business_phone !== ''): ?><div><strong>Phone:</strong> <?= safeText($business_phone) ?></div><?php endif; ?>
                <?php if ($business_email !== ''): ?><div><strong>Email:</strong> <?= safeText($business_email) ?></div><?php endif; ?>
            </td>
            <td class="text-end">
                <div class="title">Customer Statement</div>
                <div><strong>Period:</strong> <?= date('d M Y', strtotime($from_date)) ?> to <?= date('d M Y', strtotime($to_date)) ?></div>
                <div><strong>Generated:</strong> <?= date('d M Y h:i A') ?></div>
            </td>
        </tr>
    </table>
</div>

<div class="section-title">Customer Details</div>
<table class="summary-table">
    <tr>
        <td><span class="summary-label">Customer Name</span><span class="summary-value"><?= safeText($customer_name) ?></span></td>
        <td><span class="summary-label">Phone</span><span class="summary-value"><?= safeText($customer_phone, '—') ?></span></td>
        <td><span class="summary-label">Customer Type</span><span class="summary-value"><?= safeText(ucfirst($customer_type)) ?></span></td>
        <td><span class="summary-label">GSTIN</span><span class="summary-value"><?= safeText($customer_gstin, '—') ?></span></td>
    </tr>
</table>
<?php if ($customer_email !== '' || $customer_address !== ''): ?>
<div style="margin-top:8px;">
    <?php if ($customer_email !== ''): ?><div><strong>Email:</strong> <?= safeText($customer_email) ?></div><?php endif; ?>
    <?php if ($customer_address !== ''): ?><div><strong>Address:</strong> <?= nl2br(safeText($customer_address)) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="section-title">Statement Summary</div>
<table class="summary-table">
    <tr>
        <td><span class="summary-label">Opening Balance</span><span class="summary-value"><?= moneyText(abs($opening_balance)) ?></span><div class="small"><?= $opening_balance > 0 ? 'Dr' : ($opening_balance < 0 ? 'Cr' : 'Settled') ?></div></td>
        <td><span class="summary-label">Total Invoices</span><span class="summary-value"><?= moneyText($period_summary['total_invoices']) ?></span><div class="small"><?= (int)$invoice_count ?> invoice(s)</div></td>
        <td><span class="summary-label">Total Payments</span><span class="summary-value"><?= moneyText($period_summary['total_payments']) ?></span><div class="small"><?= (int)$payment_count ?> payment(s)</div></td>
        <td><span class="summary-label">Live Net Outstanding</span><span class="summary-value"><?= moneyText(abs($live_balance)) ?></span><div class="small"><?= $live_balance > 0 ? 'Receivable' : ($live_balance < 0 ? 'Payable' : 'Settled') ?></div></td>
    </tr>
</table>

<div class="section-title">Transaction Statement</div>
<table class="txn-table">
    <thead>
    <tr>
        <th style="width: 12%;">Date</th>
        <th style="width: 14%;">Type</th>
        <th style="width: 14%;">Reference</th>
        <th style="width: 34%;">Description</th>
        <th style="width: 13%;" class="text-end">Debit</th>
        <th style="width: 13%;" class="text-end">Credit</th>
    </tr>
    </thead>
    <tbody>
    <?php if (abs($manual_outstanding_balance) > 0.01): ?>
        <tr class="manual-row">
            <td><strong>Current</strong><br><span class="small">Manual Balance</span></td>
            <td>OUTSTANDING</td>
            <td>Opening/Manual</td>
            <td>Manual outstanding balance from customer master. This row is shown first for clarity.</td>
            <td class="text-end debit"><?= $manual_outstanding_balance > 0 ? moneyText(abs($manual_outstanding_balance)) : '—' ?></td>
            <td class="text-end credit"><?= $manual_outstanding_balance < 0 ? moneyText(abs($manual_outstanding_balance)) : '—' ?></td>
        </tr>
    <?php endif; ?>
    <?php if (!empty($transactions)): ?>
        <?php foreach ($transactions as $t): ?>
            <?php
                $type = $t['transaction_type'] ?? '';
                $debit = (float)($t['debit'] ?? 0);
                $credit = (float)($t['credit'] ?? 0);
                $paymentStatus = $t['payment_status'] ?? '';
                if ($type === 'invoice') {
                    $rowClass = 'invoice-row';
                    $typeLabel = 'INVOICE';
                } elseif ($type === 'payment') {
                    $rowClass = 'payment-row';
                    $typeLabel = 'PAYMENT';
                } else {
                    $rowClass = 'adjustment-row';
                    $typeLabel = strtoupper($paymentStatus === 'debit' ? 'DEBIT NOTE' : 'CREDIT NOTE');
                }
            ?>
            <tr class="<?= $rowClass ?>">
                <td><?= !empty($t['transaction_date']) ? date('d M Y', strtotime($t['transaction_date'])) : '—' ?><br><span class="small"><?= !empty($t['created_at']) ? date('h:i A', strtotime($t['created_at'])) : '' ?></span></td>
                <td><?= safeText($typeLabel) ?><?= $type === 'invoice' && $paymentStatus !== '' ? '<br><span class="small">' . safeText(ucfirst($paymentStatus)) . '</span>' : '' ?></td>
                <td><?= safeText($t['reference_no'] ?? '—') ?></td>
                <td><?= safeText($t['description'] ?? '') ?><?= !empty($t['notes']) ? '<br><span class="small">' . safeText($t['notes']) . '</span>' : '' ?></td>
                <td class="text-end debit"><?= $debit > 0 ? moneyText($debit) : '—' ?></td>
                <td class="text-end credit"><?= $credit > 0 ? moneyText($credit) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
    <?php elseif (abs($manual_outstanding_balance) <= 0.01): ?>
        <tr><td colspan="6" class="text-center">No transactions found for this period.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php if (!empty($outstanding_invoices)): ?>
<div class="section-title">Outstanding Invoices</div>
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
    <tbody>
    <?php foreach ($outstanding_invoices as $inv): ?>
        <?php
            $invTotal = (float)($inv['total'] ?? 0);
            $invPaid = (float)($inv['paid_amount'] ?? 0);
            $invOutstanding = (float)($inv['outstanding'] ?? 0);
        ?>
        <tr>
            <td><?= safeText($inv['invoice_number'] ?? '') ?></td>
            <td><?= !empty($inv['created_at']) ? date('d M Y', strtotime($inv['created_at'])) : '—' ?></td>
            <td class="text-end"><?= moneyText($invTotal) ?></td>
            <td class="text-end"><?= moneyText($invPaid) ?></td>
            <td class="text-end debit"><?= moneyText($invOutstanding) ?></td>
            <td class="text-center"><?= $invPaid > 0 ? 'Partial' : 'Unpaid' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div class="footer-note">
    This is a computer generated statement and does not require signature.<br>
    Dr (Debit) = Customer owes you | Cr (Credit) = You owe customer<br>
    Payments are calculated from customer_payments and customer_payment_allocations only.
</div>
</body>
</html>
<?php
$html = ob_get_clean();

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

$filename = 'customer_statement_' . $customer_id . '_' . date('Ymd_His') . '.pdf';

if ($autoload_found && class_exists('\\Dompdf\\Dompdf')) {
    try {
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
        $dompdf->stream($filename, ['Attachment' => true]);
        exit();
    } catch (Exception $e) {
        error_log('DOMPDF Error: ' . $e->getMessage());
    }
}

if ($autoload_found && class_exists('\\Mpdf\\Mpdf')) {
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
        $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
        exit();
    } catch (Exception $e) {
        error_log('mPDF Error: ' . $e->getMessage());
    }
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
exit();
