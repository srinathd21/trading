<?php
/**
 * customer_statement_export.php
 * FPDF customer statement download.
 *
 * Usage:
 * customer_statement_export.php?id=1&from_date=2026-05-01&to_date=2026-05-25
 */

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

function isValidDateString($date, $format = 'Y-m-d'): bool {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Exception $e) {
        return false;
    }
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function moneyValue($value): string {
    return 'Rs. ' . number_format((float)$value, 2);
}

function cleanPdfText($value): string {
    $value = (string)($value ?? '');
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }

    return preg_replace('/[^\x20-\x7E]/', '', $value);
}

function shortText($value, int $length): string {
    $value = cleanPdfText($value);
    if (strlen($value) <= $length) {
        return $value;
    }
    return substr($value, 0, max(0, $length - 3)) . '...';
}

function findFpdfPath(): ?string {
    $paths = [
        __DIR__ . '/libs/fpdf/fpdf.php',
        __DIR__ . '/libs/fpdf.php',
        __DIR__ . '/fpdf/fpdf.php',
        __DIR__ . '/fpdf.php',
        __DIR__ . '/vendor/setasign/fpdf/fpdf.php',
        __DIR__ . '/vendor/fpdf/fpdf.php',
        dirname(__DIR__) . '/libs/fpdf/fpdf.php',
        dirname(__DIR__) . '/vendor/setasign/fpdf/fpdf.php'
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    return null;
}

try {
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (Exception $e) {
    // Ignore collation setup errors.
}

$business_id = (int)($_SESSION['current_business_id'] ?? $_SESSION['business_id'] ?? 0);
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$from_date = trim($_GET['from_date'] ?? date('Y-m-01'));
$to_date = trim($_GET['to_date'] ?? date('Y-m-d'));

if (!isValidDateString($from_date)) {
    $from_date = date('Y-m-01');
}
if (!isValidDateString($to_date)) {
    $to_date = date('Y-m-d');
}
if ($from_date > $to_date) {
    [$from_date, $to_date] = [$to_date, $from_date];
}

if ($customer_id <= 0 || $business_id <= 0) {
    $_SESSION['error'] = 'Invalid customer selected.';
    header('Location: customers.php');
    exit();
}

$fpdfPath = findFpdfPath();
if (!$fpdfPath) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "FPDF library not found. Please install FPDF and place fpdf.php in one of these locations:\n";
    echo "- /libs/fpdf/fpdf.php\n- /fpdf/fpdf.php\n- /fpdf.php\n- /vendor/setasign/fpdf/fpdf.php\n";
    exit();
}
require_once $fpdfPath;

$invoiceDeletedFilter = '';
if (tableHasColumn($pdo, 'invoices', 'is_deleted')) {
    $invoiceDeletedFilter .= ' AND COALESCE(is_deleted, 0) = 0 ';
}
if (tableHasColumn($pdo, 'invoices', 'deleted_at')) {
    $invoiceDeletedFilter .= ' AND deleted_at IS NULL ';
}

$invoiceDeletedFilterAlias = '';
if (tableHasColumn($pdo, 'invoices', 'is_deleted')) {
    $invoiceDeletedFilterAlias .= ' AND COALESCE(i.is_deleted, 0) = 0 ';
}
if (tableHasColumn($pdo, 'invoices', 'deleted_at')) {
    $invoiceDeletedFilterAlias .= ' AND i.deleted_at IS NULL ';
}

$customerPaymentDeletedFilter = '';
if (tableHasColumn($pdo, 'customer_payments', 'is_deleted')) {
    $customerPaymentDeletedFilter .= ' AND COALESCE(cp.is_deleted, 0) = 0 ';
}
if (tableHasColumn($pdo, 'customer_payments', 'deleted_at')) {
    $customerPaymentDeletedFilter .= ' AND cp.deleted_at IS NULL ';
}

$allocationDeletedFilter = '';
if (tableHasColumn($pdo, 'customer_payment_allocations', 'is_deleted')) {
    $allocationDeletedFilter .= ' AND COALESCE(cpa.is_deleted, 0) = 0 ';
}
if (tableHasColumn($pdo, 'customer_payment_allocations', 'deleted_at')) {
    $allocationDeletedFilter .= ' AND cpa.deleted_at IS NULL ';
}

$adjustmentDeletedFilter = '';
if (tableExists($pdo, 'customer_credit_adjustments')) {
    if (tableHasColumn($pdo, 'customer_credit_adjustments', 'is_deleted')) {
        $adjustmentDeletedFilter .= ' AND COALESCE(ca.is_deleted, 0) = 0 ';
    }
    if (tableHasColumn($pdo, 'customer_credit_adjustments', 'deleted_at')) {
        $adjustmentDeletedFilter .= ' AND ca.deleted_at IS NULL ';
    }
}

$business = [];
if (tableExists($pdo, 'businesses')) {
    try {
        $businessStmt = $pdo->prepare('SELECT * FROM businesses WHERE id = ? LIMIT 1');
        $businessStmt->execute([$business_id]);
        $business = $businessStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $business = [];
    }
}

$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE customer_id = c.id AND business_id = ? $invoiceDeletedFilter) AS total_purchases,
           (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = ? $invoiceDeletedFilter) AS total_paid,
           (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND business_id = ? $invoiceDeletedFilter) AS invoice_count
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

$hasAdjustments = tableExists($pdo, 'customer_credit_adjustments');

$transactionsSql = "
    SELECT * FROM (
        SELECT
            CAST('invoice' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_type,
            i.id AS reference_id,
            CONVERT(COALESCE(i.invoice_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,
            i.created_at AS transaction_datetime,
            DATE(i.created_at) AS transaction_date,
            i.total AS debit,
            0 AS credit,
            i.paid_amount AS paid_amount,
            CONVERT(COALESCE(i.payment_status, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(i.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,
            i.created_at,
            CONVERT(CONCAT('Invoice - ', COALESCE(i.invoice_type, 'Sale')) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description
        FROM invoices i
        WHERE i.customer_id = ?
          AND i.business_id = ?
          $invoiceDeletedFilterAlias
          AND DATE(i.created_at) BETWEEN ? AND ?

        UNION ALL

        SELECT
            CAST('payment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_type,
            cp.id AS reference_id,
            CONVERT(
                CASE
                    WHEN COALESCE(cp.reference_no, '') <> '' THEN cp.reference_no
                    ELSE CONCAT('PAY-', cp.id)
                END USING utf8mb4
            ) COLLATE utf8mb4_unicode_ci AS reference_no,
            cp.created_at AS transaction_datetime,
            DATE(cp.payment_date) AS transaction_date,
            0 AS debit,
            COALESCE(SUM(cpa.allocated_amount), 0) AS credit,
            NULL AS paid_amount,
            CONVERT(COALESCE(cp.payment_type, 'payment') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(cp.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,
            cp.created_at,
            CONVERT(
                CONCAT(
                    CASE
                        WHEN COALESCE(cp.payment_type, '') = 'outstanding' THEN 'Outstanding Collection'
                        WHEN COALESCE(cp.payment_mode, '') = 'invoice_wise' THEN 'Invoice-wise Payment'
                        WHEN COALESCE(cp.payment_mode, '') = 'bulk' THEN 'Overall Collection'
                        ELSE 'Payment'
                    END,
                    ' (', COALESCE(cp.payment_method, ''), ')'
                ) USING utf8mb4
            ) COLLATE utf8mb4_unicode_ci AS description
        FROM customer_payments cp
        LEFT JOIN customer_payment_allocations cpa
               ON cpa.payment_id = cp.id
              AND cpa.business_id = cp.business_id
              AND cpa.customer_id = cp.customer_id
              $allocationDeletedFilter
        WHERE cp.customer_id = ?
          AND cp.business_id = ?
          $customerPaymentDeletedFilter
          AND DATE(cp.payment_date) BETWEEN ? AND ?
        GROUP BY cp.id, cp.business_id, cp.customer_id, cp.reference_no, cp.created_at, cp.payment_date,
                 cp.payment_type, cp.payment_mode, cp.payment_method, cp.notes
";

$params = [
    $customer_id, $business_id, $from_date, $to_date,
    $customer_id, $business_id, $from_date, $to_date
];

if ($hasAdjustments) {
    $transactionsSql .= "
        UNION ALL

        SELECT
            CAST('adjustment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_type,
            ca.id AS reference_id,
            CONVERT(COALESCE(ca.description, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,
            ca.created_at AS transaction_datetime,
            DATE(ca.adjustment_date) AS transaction_date,
            CASE WHEN ca.adjustment_type = 'debit' THEN ca.amount ELSE 0 END AS debit,
            CASE WHEN ca.adjustment_type = 'credit' THEN ca.amount ELSE 0 END AS credit,
            NULL AS paid_amount,
            CONVERT(COALESCE(ca.adjustment_type, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(ca.description, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,
            ca.created_at,
            CONVERT(CONCAT('Credit Adjustment (', COALESCE(ca.adjustment_type, ''), ')') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description
        FROM customer_credit_adjustments ca
        WHERE ca.customer_id = ?
          AND ca.business_id = ?
          $adjustmentDeletedFilter
          AND DATE(ca.adjustment_date) BETWEEN ? AND ?
    ";
    $params = array_merge($params, [$customer_id, $business_id, $from_date, $to_date]);
}

$transactionsSql .= "
    ) statement_rows
    ORDER BY transaction_date ASC, transaction_datetime ASC, reference_id ASC
";

$stmt = $pdo->prepare($transactionsSql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Extra safety: do not print deleted customer payments even if query is modified later.
if (!empty($transactions)) {
    $transactions = array_values(array_filter($transactions, function ($row) use ($pdo) {
        if (($row['transaction_type'] ?? '') !== 'payment') {
            return true;
        }
        $paymentId = (int)($row['reference_id'] ?? 0);
        if ($paymentId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT COALESCE(is_deleted, 0) AS is_deleted, deleted_at FROM customer_payments WHERE id = ? LIMIT 1");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return false;
        }

        return ((int)$payment['is_deleted'] === 0) && empty($payment['deleted_at']);
    }));
}

$openingSql = "
    SELECT
        COALESCE((
            SELECT SUM(total)
            FROM invoices
            WHERE customer_id = ?
              AND business_id = ?
              $invoiceDeletedFilter
              AND DATE(created_at) < ?
        ), 0) AS total_invoices_before,

        COALESCE((
            SELECT SUM(cpa.allocated_amount)
            FROM customer_payments cp
            INNER JOIN customer_payment_allocations cpa
                    ON cpa.payment_id = cp.id
                   AND cpa.business_id = cp.business_id
                   AND cpa.customer_id = cp.customer_id
                   $allocationDeletedFilter
            WHERE cp.customer_id = ?
              AND cp.business_id = ?
              $customerPaymentDeletedFilter
              AND DATE(cp.payment_date) < ?
        ), 0) AS total_payments_before
";
$openingParams = [$customer_id, $business_id, $from_date, $customer_id, $business_id, $from_date];

if ($hasAdjustments) {
    $openingSql .= ",
        COALESCE((
            SELECT SUM(CASE
                WHEN ca.adjustment_type = 'debit' THEN ca.amount
                WHEN ca.adjustment_type = 'credit' THEN -ca.amount
                ELSE 0
            END)
            FROM customer_credit_adjustments ca
            WHERE ca.customer_id = ?
              AND ca.business_id = ?
              $adjustmentDeletedFilter
              AND DATE(ca.adjustment_date) < ?
        ), 0) AS adjustment_balance_before
    ";
    $openingParams = array_merge($openingParams, [$customer_id, $business_id, $from_date]);
} else {
    $openingSql .= ", 0 AS adjustment_balance_before";
}

$stmt = $pdo->prepare($openingSql);
$stmt->execute($openingParams);
$opening = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_invoices_before' => 0,
    'total_payments_before' => 0,
    'adjustment_balance_before' => 0
];

$opening_balance = (float)$opening['total_invoices_before'] - (float)$opening['total_payments_before'] + (float)$opening['adjustment_balance_before'];

$manual_outstanding_balance = (($customer['outstanding_type'] ?? 'credit') === 'credit')
    ? (float)($customer['outstanding_amount'] ?? 0)
    : -(float)($customer['outstanding_amount'] ?? 0);

$periodSummary = [
    'total_invoices' => 0.00,
    'total_payments' => 0.00,
    'total_adjustment_debit' => 0.00,
    'total_adjustment_credit' => 0.00,
    'invoice_count' => 0,
    'payment_count' => 0
];

foreach ($transactions as $row) {
    if (($row['transaction_type'] ?? '') === 'invoice') {
        $periodSummary['total_invoices'] += (float)$row['debit'];
        $periodSummary['invoice_count']++;
    } elseif (($row['transaction_type'] ?? '') === 'payment') {
        $periodSummary['total_payments'] += (float)$row['credit'];
        $periodSummary['payment_count']++;
    } elseif (($row['transaction_type'] ?? '') === 'adjustment') {
        $periodSummary['total_adjustment_debit'] += (float)$row['debit'];
        $periodSummary['total_adjustment_credit'] += (float)$row['credit'];
    }
}

$runningForClosing = $opening_balance + $manual_outstanding_balance;
foreach ($transactions as $row) {
    $runningForClosing += (float)$row['debit'] - (float)$row['credit'];
}
$closingBalance = $runningForClosing;

$outstandingSql = "
    SELECT invoice_number, total, paid_amount, GREATEST(total - paid_amount, 0) AS outstanding, created_at, payment_status
    FROM invoices
    WHERE customer_id = ?
      AND business_id = ?
      $invoiceDeletedFilter
      AND payment_status IN ('pending', 'partial')
      AND GREATEST(total - paid_amount, 0) > 0.01
    ORDER BY created_at ASC
";
$stmt = $pdo->prepare($outstandingSql);
$stmt->execute([$customer_id, $business_id]);
$outstandingInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$businessName = $business['business_name'] ?? $business['name'] ?? ($_SESSION['business_name'] ?? 'Your Business');
$businessGstin = $business['gstin'] ?? ($_SESSION['business_gst'] ?? 'Not Available');
$businessAddress = $business['address'] ?? ($_SESSION['business_address'] ?? '');
$businessPhone = $business['phone'] ?? '';
$businessEmail = $business['email'] ?? '';

class CustomerStatementPDF extends FPDF {
    public string $businessName = '';
    public string $titleText = 'Customer Statement';

    public function Header() {
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(0, 7, cleanPdfText($this->businessName), 0, 1, 'L');
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 7, cleanPdfText($this->titleText), 0, 1, 'R');
        $this->Ln(2);
        $this->SetDrawColor(180, 180, 180);
        $this->Line(10, $this->GetY(), 287, $this->GetY());
        $this->Ln(5);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' | Generated ' . date('d M Y h:i A'), 0, 0, 'C');
    }

    public function sectionTitle($title) {
        $this->SetFillColor(240, 240, 240);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 7, cleanPdfText($title), 1, 1, 'L', true);
    }

    public function row(array $widths, array $values, int $height = 6, bool $fill = false) {
        foreach ($values as $i => $value) {
            $align = $i >= count($values) - 3 ? 'R' : 'L';
            $this->Cell($widths[$i], $height, cleanPdfText($value), 1, 0, $align, $fill);
        }
        $this->Ln();
    }
}

$pdf = new CustomerStatementPDF('L', 'mm', 'A4');
$pdf->businessName = $businessName;
$pdf->titleText = 'Customer Statement';
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 15);

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(138.5, 5, 'GSTIN: ' . cleanPdfText($businessGstin ?: 'Not Available'), 0, 0, 'L');
$pdf->Cell(138.5, 5, 'Period: ' . date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date)), 0, 1, 'R');
if ($businessAddress !== '') {
    $pdf->Cell(0, 5, cleanPdfText($businessAddress), 0, 1, 'L');
}
$contactLine = trim(($businessPhone ? 'Phone: ' . $businessPhone : '') . ($businessEmail ? '  Email: ' . $businessEmail : ''));
if ($contactLine !== '') {
    $pdf->Cell(0, 5, cleanPdfText($contactLine), 0, 1, 'L');
}
$pdf->Ln(3);

$pdf->sectionTitle('Customer Details');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(138.5, 6, 'Name: ' . shortText($customer['name'] ?? '', 70), 1, 0);
$pdf->Cell(138.5, 6, 'Phone: ' . shortText($customer['phone'] ?: '-', 60), 1, 1);
$pdf->Cell(138.5, 6, 'GSTIN: ' . shortText($customer['gstin'] ?: '-', 60), 1, 0);
$pdf->Cell(138.5, 6, 'Type: ' . shortText(ucfirst((string)($customer['customer_type'] ?? '-')), 60), 1, 1);
$pdf->Cell(277, 6, 'Address: ' . shortText($customer['address'] ?: '-', 140), 1, 1);
$pdf->Ln(3);

$pdf->sectionTitle('Statement Summary');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(69.25, 6, 'Opening Balance', 1, 0, 'C');
$pdf->Cell(69.25, 6, 'Invoices', 1, 0, 'C');
$pdf->Cell(69.25, 6, 'Payments', 1, 0, 'C');
$pdf->Cell(69.25, 6, 'Closing Balance', 1, 1, 'C');

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(69.25, 7, moneyValue(abs($opening_balance)) . ' ' . ($opening_balance > 0 ? 'Dr' : ($opening_balance < 0 ? 'Cr' : '')), 1, 0, 'C');
$pdf->Cell(69.25, 7, moneyValue($periodSummary['total_invoices']), 1, 0, 'C');
$pdf->Cell(69.25, 7, moneyValue($periodSummary['total_payments']), 1, 0, 'C');
$pdf->Cell(69.25, 7, moneyValue(abs($closingBalance)) . ' ' . ($closingBalance > 0 ? 'Dr' : ($closingBalance < 0 ? 'Cr' : '')), 1, 1, 'C');
$pdf->Ln(3);

$pdf->sectionTitle('Transaction Statement');
$widths = [25, 25, 48, 82, 35, 35, 27];
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(230, 230, 230);
$pdf->row($widths, ['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance'], 7, true);

$pdf->SetFont('Arial', '', 7);
$running = $opening_balance;
$pdf->row($widths, [
    date('d M Y', strtotime($from_date)),
    'OPENING',
    '-',
    'Opening balance before selected period',
    $opening_balance > 0 ? moneyValue($opening_balance) : '-',
    $opening_balance < 0 ? moneyValue(abs($opening_balance)) : '-',
    moneyValue(abs($running)) . ' ' . ($running > 0 ? 'Dr' : ($running < 0 ? 'Cr' : ''))
]);

if (abs($manual_outstanding_balance) > 0.01) {
    $debit = $manual_outstanding_balance > 0 ? $manual_outstanding_balance : 0;
    $credit = $manual_outstanding_balance < 0 ? abs($manual_outstanding_balance) : 0;
    $running += $debit - $credit;

    $pdf->row($widths, [
        date('d M Y', strtotime($from_date)),
        'OUTSTANDING',
        'MANUAL',
        'Manual outstanding balance from customer master',
        $debit > 0 ? moneyValue($debit) : '-',
        $credit > 0 ? moneyValue($credit) : '-',
        moneyValue(abs($running)) . ' ' . ($running > 0 ? 'Dr' : ($running < 0 ? 'Cr' : ''))
    ]);
}

if (!empty($transactions)) {
    foreach ($transactions as $t) {
        if ($pdf->GetY() > 185) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->row($widths, ['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance'], 7, true);
            $pdf->SetFont('Arial', '', 7);
        }

        $debit = (float)($t['debit'] ?? 0);
        $credit = (float)($t['credit'] ?? 0);
        $running += $debit - $credit;

        $type = strtoupper((string)($t['transaction_type'] ?? ''));
        $desc = (string)($t['description'] ?? '');
        if (!empty($t['notes'])) {
            $desc .= ' | ' . $t['notes'];
        }

        $pdf->row($widths, [
            !empty($t['transaction_date']) ? date('d M Y', strtotime($t['transaction_date'])) : '-',
            shortText($type, 14),
            shortText($t['reference_no'] ?: '-', 28),
            shortText($desc, 58),
            $debit > 0 ? moneyValue($debit) : '-',
            $credit > 0 ? moneyValue($credit) : '-',
            moneyValue(abs($running)) . ' ' . ($running > 0 ? 'Dr' : ($running < 0 ? 'Cr' : ''))
        ]);
    }
} else {
    $pdf->Cell(277, 7, 'No transactions found for this selected period.', 1, 1, 'C');
}

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 235, 255);
$pdf->row($widths, [
    date('d M Y', strtotime($to_date)),
    'CLOSING',
    '-',
    'Closing Balance',
    '-',
    '-',
    moneyValue(abs($closingBalance)) . ' ' . ($closingBalance > 0 ? 'Dr' : ($closingBalance < 0 ? 'Cr' : ''))
], 7, true);
$pdf->Ln(4);

if (!empty($outstandingInvoices)) {
    $pdf->sectionTitle('Outstanding Invoices');
    $poWidths = [55, 35, 45, 45, 50, 47];
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->row($poWidths, ['Invoice No', 'Date', 'Total', 'Paid', 'Outstanding', 'Status'], 7, true);

    $pdf->SetFont('Arial', '', 8);
    foreach ($outstandingInvoices as $inv) {
        if ($pdf->GetY() > 185) {
            $pdf->AddPage();
        }

        $pdf->row($poWidths, [
            shortText($inv['invoice_number'] ?: '-', 32),
            !empty($inv['created_at']) ? date('d M Y', strtotime($inv['created_at'])) : '-',
            moneyValue($inv['total']),
            moneyValue($inv['paid_amount']),
            moneyValue($inv['outstanding']),
            ucfirst((string)$inv['payment_status'])
        ]);
    }
    $pdf->Ln(4);
}

$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->MultiCell(0, 5, cleanPdfText('This is a computer generated statement and does not require signature. Dr = Customer owes you | Cr = You owe customer.'), 0, 'C');

$filename = 'customer_statement_' . $customer_id . '_' . date('Ymd_His') . '.pdf';
while (ob_get_level()) {
    ob_end_clean();
}
$pdf->Output('D', $filename);
exit();
