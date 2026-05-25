<?php
/**
 * manufacturer_statement_export.php
 * FPDF supplier/manufacturer statement export.
 * URL example:
 * manufacturer_statement_export.php?id=102&from_date=2026-05-01&to_date=2026-05-25
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

function isValidDateString($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function moneyValue($value) {
    return 'Rs. ' . number_format((float)$value, 2);
}

function cleanPdfText($value) {
    $value = (string)($value ?? '');
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/\s+/', ' ', $value);
    // FPDF core fonts are ISO-8859-1. Transliterate UTF-8 safely.
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }
    return preg_replace('/[^\x20-\x7E]/', '', $value);
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

function findFpdfPath(): ?string {
    $paths = [
        __DIR__ . '/libs/fpdf/fpdf.php',
        __DIR__ . '/libs/fpdf.php',
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

$business_id = (int)($_SESSION['business_id'] ?? $_SESSION['current_business_id'] ?? 0);
$manufacturer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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

if ($manufacturer_id <= 0 || $business_id <= 0) {
    $_SESSION['error'] = 'Invalid supplier selected.';
    header('Location: manufacturers.php');
    exit();
}

try {
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (Exception $e) {
    // Ignore collation setup errors.
}

$fpdfPath = findFpdfPath();
if (!$fpdfPath) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "FPDF library not found. Please install FPDF and place fpdf.php in one of these locations:\n";
    echo "- /fpdf/fpdf.php\n- /fpdf.php\n- /vendor/setasign/fpdf/fpdf.php\n";
    exit();
}
require_once $fpdfPath;

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
    SELECT m.*, s.shop_name, s.shop_code,
           (SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE manufacturer_id = m.id AND business_id = ? $purchaseDeletedFilter) AS total_purchases,
           (SELECT COALESCE(SUM(paid_amount), 0) FROM purchases WHERE manufacturer_id = m.id AND business_id = ? $purchaseDeletedFilter) AS total_paid,
           (SELECT COUNT(*) FROM purchases WHERE manufacturer_id = m.id AND business_id = ? $purchaseDeletedFilter) AS purchase_count
    FROM manufacturers m
    LEFT JOIN shops s ON m.shop_id = s.id
    WHERE m.id = ? AND m.business_id = ?
    LIMIT 1
");
$stmt->execute([$business_id, $business_id, $business_id, $manufacturer_id, $business_id]);
$manufacturer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$manufacturer) {
    $_SESSION['error'] = 'Supplier not found.';
    header('Location: manufacturers.php');
    exit();
}

$hasPaymentsBusinessId = tableHasColumn($pdo, 'payments', 'business_id');
$hasPaymentsManufacturerId = tableHasColumn($pdo, 'payments', 'manufacturer_id');
$hasPaymentsIsDeleted = tableHasColumn($pdo, 'payments', 'is_deleted');
$hasPaymentsDeletedAt = tableHasColumn($pdo, 'payments', 'deleted_at');
$hasPaymentsPaymentType = tableHasColumn($pdo, 'payments', 'payment_type');
$hasAllocTable = tableExists($pdo, 'supplier_payment_allocations');

$hasPurchasesIsDeleted = tableHasColumn($pdo, 'purchases', 'is_deleted');
$hasPurchasesDeletedAt = tableHasColumn($pdo, 'purchases', 'deleted_at');

$purchaseDeletedFilter = '';
if ($hasPurchasesIsDeleted) {
    $purchaseDeletedFilter .= ' AND COALESCE(is_deleted, 0) = 0 ';
}
if ($hasPurchasesDeletedAt) {
    $purchaseDeletedFilter .= ' AND deleted_at IS NULL ';
}

$purchaseDeletedFilterAlias = '';
if ($hasPurchasesIsDeleted) {
    $purchaseDeletedFilterAlias .= ' AND COALESCE(p.is_deleted, 0) = 0 ';
}
if ($hasPurchasesDeletedAt) {
    $purchaseDeletedFilterAlias .= ' AND p.deleted_at IS NULL ';
}

$paymentWhereParts = ["pay.type IN ('supplier', 'supplier_outstanding')"];
if ($hasPaymentsManufacturerId) {
    $paymentWhereParts[] = '(pay.manufacturer_id = ? OR (pay.type = \'supplier\' AND pay.reference_id IN (SELECT id FROM purchases WHERE manufacturer_id = ? AND business_id = ? $purchaseDeletedFilter)))';
} else {
    $paymentWhereParts[] = "pay.reference_id IN (SELECT id FROM purchases WHERE manufacturer_id = ? AND business_id = ? $purchaseDeletedFilter)";
}
if ($hasPaymentsBusinessId) {
    $paymentWhereParts[] = '(pay.business_id = ? OR pay.business_id IS NULL)';
}
if ($hasPaymentsIsDeleted) {
    $paymentWhereParts[] = 'COALESCE(pay.is_deleted, 0) = 0';
}
if ($hasPaymentsDeletedAt) {
    $paymentWhereParts[] = 'pay.deleted_at IS NULL';
}
$paymentWhereParts[] = 'DATE(pay.payment_date) BETWEEN ? AND ?';
$paymentWhereSql = implode(' AND ', $paymentWhereParts);

$allocationSelect = "CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS allocation_details";
if ($hasAllocTable) {
    $allocationSelect = "(
        SELECT CONVERT(GROUP_CONCAT(
            CONCAT(
                CASE
                    WHEN spa.allocation_type = 'purchase_order' THEN COALESCE(spa.purchase_number, CONCAT('PO #', spa.purchase_id))
                    WHEN spa.allocation_type = 'outstanding' THEN 'Supplier Outstanding'
                    ELSE spa.allocation_type
                END,
                ': Rs. ', FORMAT(spa.allocated_amount, 2)
            )
            ORDER BY spa.id ASC SEPARATOR ' | '
        ) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        FROM supplier_payment_allocations spa
        WHERE spa.payment_id = pay.id
          AND COALESCE(spa.is_deleted, 0) = 0
    ) AS allocation_details";
}

$paymentTypeSelect = $hasPaymentsPaymentType
    ? "CONVERT(COALESCE(pay.payment_type, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci"
    : "CAST('payment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci";

$transactionsSql = "
    SELECT * FROM (
        SELECT
            CAST('purchase' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_type,
            p.id AS reference_id,
            CONVERT(COALESCE(p.purchase_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,
            DATE(p.purchase_date) AS transaction_date,
            COALESCE(p.created_at, p.purchase_date) AS created_at,
            p.total_amount AS debit,
            0 AS credit,
            p.paid_amount AS paid_amount,
            CONVERT(COALESCE(p.payment_status, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(p.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,
            CAST('Purchase Order' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
            CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS allocation_details
        FROM purchases p
        WHERE p.manufacturer_id = ?
          AND p.business_id = ?
          $purchaseDeletedFilterAlias
          AND DATE(p.purchase_date) BETWEEN ? AND ?

        UNION ALL

        SELECT
            CAST('payment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_type,
            pay.id AS reference_id,
            CONVERT(CASE WHEN COALESCE(pay.reference_no, '') <> '' THEN pay.reference_no ELSE CONCAT('PAY-', pay.id) END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,
            DATE(pay.payment_date) AS transaction_date,
            COALESCE(pay.created_at, pay.payment_date) AS created_at,
            0 AS debit,
            pay.amount AS credit,
            NULL AS paid_amount,
            $paymentTypeSelect AS payment_status,
            CONVERT(COALESCE(pay.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,
            CONVERT(CONCAT('Payment (', COALESCE(pay.payment_method, ''), ')') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
            $allocationSelect
        FROM payments pay
        WHERE $paymentWhereSql
    ) statement_rows
    ORDER BY transaction_date ASC, created_at ASC, reference_id ASC
";

$transactionParams = [$manufacturer_id, $business_id, $from_date, $to_date];
if ($hasPaymentsManufacturerId) {
    $transactionParams[] = $manufacturer_id;
    $transactionParams[] = $manufacturer_id;
    $transactionParams[] = $business_id;
} else {
    $transactionParams[] = $manufacturer_id;
    $transactionParams[] = $business_id;
}
if ($hasPaymentsBusinessId) {
    $transactionParams[] = $business_id;
}
$transactionParams[] = $from_date;
$transactionParams[] = $to_date;

$stmt = $pdo->prepare($transactionsSql);
$stmt->execute($transactionParams);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Extra safety: never print deleted payment rows in PDF export.
if (!empty($transactions)) {
    $transactions = array_values(array_filter($transactions, function ($row) use ($pdo) {
        if (($row['transaction_type'] ?? '') !== 'payment') {
            return true;
        }
        $paymentId = (int)($row['reference_id'] ?? 0);
        if ($paymentId <= 0) {
            return false;
        }
        $check = $pdo->prepare("SELECT COALESCE(is_deleted, 0) AS is_deleted, deleted_at FROM payments WHERE id = ? LIMIT 1");
        $check->execute([$paymentId]);
        $payRow = $check->fetch(PDO::FETCH_ASSOC);
        if (!$payRow) {
            return false;
        }
        return ((int)$payRow['is_deleted'] === 0) && empty($payRow['deleted_at']);
    }));
}

$paymentBeforeWhereParts = ["pay.type IN ('supplier', 'supplier_outstanding')"];
if ($hasPaymentsManufacturerId) {
    $paymentBeforeWhereParts[] = '(pay.manufacturer_id = ? OR (pay.type = \'supplier\' AND pay.reference_id IN (SELECT id FROM purchases WHERE manufacturer_id = ? AND business_id = ? $purchaseDeletedFilter)))';
} else {
    $paymentBeforeWhereParts[] = "pay.reference_id IN (SELECT id FROM purchases WHERE manufacturer_id = ? AND business_id = ? $purchaseDeletedFilter)";
}
if ($hasPaymentsBusinessId) {
    $paymentBeforeWhereParts[] = '(pay.business_id = ? OR pay.business_id IS NULL)';
}
if ($hasPaymentsIsDeleted) {
    $paymentBeforeWhereParts[] = 'COALESCE(pay.is_deleted, 0) = 0';
}
if ($hasPaymentsDeletedAt) {
    $paymentBeforeWhereParts[] = 'pay.deleted_at IS NULL';
}
$paymentBeforeWhereParts[] = 'DATE(pay.payment_date) < ?';
$paymentBeforeWhereSql = implode(' AND ', $paymentBeforeWhereParts);

$openingSql = "
    SELECT
        COALESCE((
            SELECT SUM(total_amount)
            FROM purchases
            WHERE manufacturer_id = ?
              AND business_id = ?
              $purchaseDeletedFilter
              AND DATE(purchase_date) < ?
        ), 0) AS purchases_before,
        COALESCE((
            SELECT SUM(pay.amount)
            FROM payments pay
            WHERE $paymentBeforeWhereSql
        ), 0) AS payments_before
";
$openingParams = [$manufacturer_id, $business_id, $from_date];
if ($hasPaymentsManufacturerId) {
    $openingParams[] = $manufacturer_id;
    $openingParams[] = $manufacturer_id;
    $openingParams[] = $business_id;
} else {
    $openingParams[] = $manufacturer_id;
    $openingParams[] = $business_id;
}
if ($hasPaymentsBusinessId) {
    $openingParams[] = $business_id;
}
$openingParams[] = $from_date;

$stmt = $pdo->prepare($openingSql);
$stmt->execute($openingParams);
$opening = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['purchases_before' => 0, 'payments_before' => 0];

$baseOpeningBalance = (float)$opening['purchases_before'] - (float)$opening['payments_before'];
$initialType = $manufacturer['initial_outstanding_type'] ?? 'none';
$initialAmount = (float)($manufacturer['initial_outstanding_amount'] ?? 0);
$initialSigned = 0.00;
if ($initialType === 'debit') {
    $initialSigned = $initialAmount;
} elseif ($initialType === 'credit') {
    $initialSigned = -$initialAmount;
}
$openingBalance = $baseOpeningBalance + $initialSigned;

$periodSummary = [
    'total_purchases' => 0.00,
    'total_payments' => 0.00,
    'purchase_count' => 0,
    'payment_count' => 0
];

foreach ($transactions as $row) {
    if (($row['transaction_type'] ?? '') === 'purchase') {
        $periodSummary['total_purchases'] += (float)$row['debit'];
        $periodSummary['purchase_count']++;
    } elseif (($row['transaction_type'] ?? '') === 'payment') {
        $periodSummary['total_payments'] += (float)$row['credit'];
        $periodSummary['payment_count']++;
    }
}

$closingBalance = $openingBalance + $periodSummary['total_purchases'] - $periodSummary['total_payments'];

$outstandingSql = "
    SELECT purchase_number, total_amount, paid_amount,
           GREATEST(total_amount - paid_amount, 0) AS due_amount,
           purchase_date,
           payment_status
    FROM purchases
    WHERE manufacturer_id = ?
      AND business_id = ?
      $purchaseDeletedFilter
      AND GREATEST(total_amount - paid_amount, 0) > 0.01
    ORDER BY purchase_date ASC, id ASC
";
$stmt = $pdo->prepare($outstandingSql);
$stmt->execute([$manufacturer_id, $business_id]);
$outstandingPurchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

$businessName = $business['business_name'] ?? $business['name'] ?? ($_SESSION['business_name'] ?? 'Your Business');
$businessGstin = $business['gstin'] ?? ($_SESSION['business_gst'] ?? 'Not Available');
$businessAddress = $business['address'] ?? ($_SESSION['business_address'] ?? '');
$businessPhone = $business['phone'] ?? '';
$businessEmail = $business['email'] ?? '';

class StatementPDF extends FPDF {
    public string $businessName = '';
    public string $titleText = 'Supplier Statement';

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

$pdf = new StatementPDF('L', 'mm', 'A4');
$pdf->businessName = $businessName;
$pdf->titleText = 'Supplier Statement';
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

$pdf->sectionTitle('Supplier Details');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(138.5, 6, 'Name: ' . cleanPdfText($manufacturer['name'] ?? ''), 1, 0);
$pdf->Cell(138.5, 6, 'Phone: ' . cleanPdfText($manufacturer['phone'] ?: '-'), 1, 1);
$pdf->Cell(138.5, 6, 'GSTIN: ' . cleanPdfText($manufacturer['gstin'] ?: '-'), 1, 0);
$pdf->Cell(138.5, 6, 'Shop: ' . cleanPdfText($manufacturer['shop_name'] ?: '-'), 1, 1);
$pdf->Cell(277, 6, 'Address: ' . cleanPdfText($manufacturer['address'] ?: '-'), 1, 1);
$pdf->Ln(3);

$pdf->sectionTitle('Statement Summary');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(69.25, 6, 'Opening Balance', 1, 0, 'C');
$pdf->Cell(69.25, 6, 'Purchases', 1, 0, 'C');
$pdf->Cell(69.25, 6, 'Payments', 1, 0, 'C');
$pdf->Cell(69.25, 6, 'Closing Balance', 1, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(69.25, 7, moneyValue(abs($openingBalance)) . ' ' . ($openingBalance > 0 ? 'Dr' : ($openingBalance < 0 ? 'Cr' : '')), 1, 0, 'C');
$pdf->Cell(69.25, 7, moneyValue($periodSummary['total_purchases']), 1, 0, 'C');
$pdf->Cell(69.25, 7, moneyValue($periodSummary['total_payments']), 1, 0, 'C');
$pdf->Cell(69.25, 7, moneyValue(abs($closingBalance)) . ' ' . ($closingBalance > 0 ? 'Dr' : ($closingBalance < 0 ? 'Cr' : '')), 1, 1, 'C');
$pdf->Ln(3);

$pdf->sectionTitle('Transaction Statement');
$widths = [25, 25, 48, 82, 35, 35, 27];
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(230, 230, 230);
$pdf->row($widths, ['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance'], 7, true);

$pdf->SetFont('Arial', '', 7);
$running = $baseOpeningBalance;
$pdf->row($widths, [date('d M Y', strtotime($from_date)), 'OPENING', '-', 'Opening balance', $baseOpeningBalance > 0 ? moneyValue($baseOpeningBalance) : '-', $baseOpeningBalance < 0 ? moneyValue(abs($baseOpeningBalance)) : '-', moneyValue(abs($running)) . ' ' . ($running > 0 ? 'Dr' : ($running < 0 ? 'Cr' : ''))], 6, false);

if ($initialAmount > 0.01 && in_array($initialType, ['debit', 'credit'], true)) {
    $debit = $initialType === 'debit' ? $initialAmount : 0;
    $credit = $initialType === 'credit' ? $initialAmount : 0;
    $running += $debit - $credit;
    $pdf->row($widths, [date('d M Y', strtotime($from_date)), 'OUTSTANDING', 'OPENING', 'Supplier ' . $initialType . ' outstanding balance', $debit > 0 ? moneyValue($debit) : '-', $credit > 0 ? moneyValue($credit) : '-', moneyValue(abs($running)) . ' ' . ($running > 0 ? 'Dr' : ($running < 0 ? 'Cr' : ''))], 6, false);
}

if (!empty($transactions)) {
    foreach ($transactions as $t) {
        if ($pdf->GetY() > 260) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->row($widths, ['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance'], 7, true);
            $pdf->SetFont('Arial', '', 8);
        }

        $debit = (float)($t['debit'] ?? 0);
        $credit = (float)($t['credit'] ?? 0);
        $running += $debit - $credit;
        $type = strtoupper((string)($t['transaction_type'] ?? ''));
        $desc = (string)($t['description'] ?? '');
        if (!empty($t['allocation_details'])) {
            $desc .= ' - ' . $t['allocation_details'];
        }
        if (!empty($t['notes'])) {
            $desc .= ' | ' . $t['notes'];
        }
        if (strlen($desc) > 55) {
            $desc = substr($desc, 0, 52) . '...';
        }

        $refNo = (string)($t['reference_no'] ?: '-');
        if (strlen($refNo) > 26) {
            $refNo = substr($refNo, 0, 23) . '...';
        }

        $pdf->row($widths, [
            !empty($t['transaction_date']) ? date('d M Y', strtotime($t['transaction_date'])) : '-',
            $type,
            $refNo,
            $desc,
            $debit > 0 ? moneyValue($debit) : '-',
            $credit > 0 ? moneyValue($credit) : '-',
            moneyValue(abs($running)) . ' ' . ($running > 0 ? 'Dr' : ($running < 0 ? 'Cr' : ''))
        ], 6, false);
    }
} else {
    $pdf->Cell(277, 7, 'No transactions found for this selected period.', 1, 1, 'C');
}

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 235, 255);
$pdf->row($widths, [date('d M Y', strtotime($to_date)), 'CLOSING', '-', 'Closing Balance', '-', '-', moneyValue(abs($closingBalance)) . ' ' . ($closingBalance > 0 ? 'Dr' : ($closingBalance < 0 ? 'Cr' : ''))], 7, true);
$pdf->Ln(4);

if (!empty($outstandingPurchases)) {
    $pdf->sectionTitle('Outstanding Purchase Orders');
    $poWidths = [55, 35, 45, 45, 50, 47];
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->row($poWidths, ['PO Number', 'Date', 'Total', 'Paid', 'Due', 'Status'], 7, true);
    $pdf->SetFont('Arial', '', 8);
    foreach ($outstandingPurchases as $po) {
        if ($pdf->GetY() > 260) {
            $pdf->AddPage();
        }
        $pdf->row($poWidths, [
            $po['purchase_number'] ?: '-',
            !empty($po['purchase_date']) ? date('d M Y', strtotime($po['purchase_date'])) : '-',
            moneyValue($po['total_amount']),
            moneyValue($po['paid_amount']),
            moneyValue($po['due_amount']),
            ucfirst((string)$po['payment_status'])
        ], 6, false);
    }
    $pdf->Ln(4);
}

if (!empty($manufacturer['account_holder_name']) || !empty($manufacturer['bank_name']) || !empty($manufacturer['account_number'])) {
    $pdf->sectionTitle('Bank Details');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(138.5, 6, 'Account Holder: ' . cleanPdfText($manufacturer['account_holder_name'] ?: '-'), 1, 0);
    $pdf->Cell(138.5, 6, 'Bank: ' . cleanPdfText($manufacturer['bank_name'] ?: '-'), 1, 1);
    $pdf->Cell(138.5, 6, 'Account No: ' . cleanPdfText($manufacturer['account_number'] ?: '-'), 1, 0);
    $pdf->Cell(138.5, 6, 'IFSC: ' . cleanPdfText($manufacturer['ifsc_code'] ?: '-'), 1, 1);
    $pdf->Cell(277, 6, 'Branch: ' . cleanPdfText($manufacturer['branch_name'] ?: '-'), 1, 1);
    $pdf->Ln(4);
}

$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->MultiCell(0, 5, cleanPdfText('This is a computer generated statement and does not require signature. Dr = You owe supplier | Cr = Supplier owes you.'), 0, 'C');

$filename = 'manufacturer_statement_' . $manufacturer_id . '_' . date('Ymd_His') . '.pdf';
while (ob_get_level()) {
    ob_end_clean();
}
$pdf->Output('D', $filename);
exit();
