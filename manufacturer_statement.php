<?php
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

$user_id = (int)$_SESSION['user_id'];
$business_id = (int)($_SESSION['current_business_id'] ?? $_SESSION['business_id'] ?? 0);
$manufacturer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($manufacturer_id <= 0 || $business_id <= 0) {
    header('Location: manufacturers.php');
    exit();
}

function isValidDateString($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function moneyValue($value): float {
    return round((float)($value ?? 0), 2);
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

try {
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (Exception $e) {
    // Ignore collation setup failure.
}

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

// Get manufacturer details.
$stmt = $pdo->prepare("\n    SELECT m.*,\n           s.shop_name,\n           s.shop_code,\n           (SELECT COALESCE(SUM(p.total_amount), 0) FROM purchases p WHERE p.manufacturer_id = m.id AND p.business_id = ?) AS total_purchases,\n           (SELECT COALESCE(SUM(p.paid_amount), 0) FROM purchases p WHERE p.manufacturer_id = m.id AND p.business_id = ?) AS total_paid,\n           (SELECT COUNT(*) FROM purchases p WHERE p.manufacturer_id = m.id AND p.business_id = ?) AS purchase_count,\n           (SELECT COALESCE(SUM(GREATEST(p.total_amount - p.paid_amount, 0)), 0)\n            FROM purchases p\n            WHERE p.manufacturer_id = m.id\n              AND p.business_id = ?\n              AND GREATEST(p.total_amount - p.paid_amount, 0) > 0.01) AS purchase_balance\n    FROM manufacturers m\n    LEFT JOIN shops s ON m.shop_id = s.id\n    WHERE m.id = ? AND m.business_id = ?\n    LIMIT 1\n");
$stmt->execute([$business_id, $business_id, $business_id, $business_id, $manufacturer_id, $business_id]);
$manufacturer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$manufacturer) {
    $_SESSION['error'] = 'Manufacturer not found';
    header('Location: manufacturers.php');
    exit();
}

$has_allocations = tableExists($pdo, 'supplier_payment_allocations');
$payments_has_manufacturer = tableHasColumn($pdo, 'payments', 'manufacturer_id');
$payments_has_business = tableHasColumn($pdo, 'payments', 'business_id');
$payments_has_deleted = tableHasColumn($pdo, 'payments', 'is_deleted');
$payments_has_type_col = tableHasColumn($pdo, 'payments', 'payment_type');

$payment_business_filter = $payments_has_business ? ' AND pay.business_id = ? ' : '';
$payment_deleted_filter = $payments_has_deleted ? ' AND COALESCE(pay.is_deleted, 0) = 0 ' : '';
$payment_manufacturer_filter = $payments_has_manufacturer
    ? ' pay.manufacturer_id = ? '
    : ' pay.reference_id IN (SELECT id FROM purchases WHERE manufacturer_id = ? AND business_id = ?) ';

$payment_reference_params = $payments_has_manufacturer ? [$manufacturer_id] : [$manufacturer_id, $business_id];
if ($payments_has_business) {
    $payment_reference_params[] = $business_id;
}

$allocation_details_select = $has_allocations ? "\n                   ,(\n               SELECT GROUP_CONCAT(\n                   CONCAT(\n                       CASE\n                           WHEN spa.allocation_type = 'purchase_order' THEN COALESCE(spa.purchase_number, CONCAT('PO #', spa.purchase_id))\n                           WHEN spa.allocation_type = 'outstanding' THEN 'Supplier Outstanding'\n                           ELSE spa.allocation_type\n                       END,\n                       ': ₹', FORMAT(spa.allocated_amount, 2)\n                   )\n                   ORDER BY spa.id ASC SEPARATOR '<br>'\n               )\n               FROM supplier_payment_allocations spa\n               WHERE spa.payment_id = pay.id\n                 AND COALESCE(spa.is_deleted, 0) = 0\n           ) AS allocation_details,\n" : "\n                   ,NULL AS allocation_details,\n";

$payment_type_select = $payments_has_type_col ? "COALESCE(pay.payment_type, '')" : "''";

// Build transaction list. Payments are read from payments table as the main source.
$transactions_sql = "\n    SELECT * FROM (\n        SELECT\n            'purchase' AS transaction_type,\n            p.id AS reference_id,\n            CONVERT(COALESCE(p.purchase_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,\n            DATE(p.purchase_date) AS transaction_date,\n            p.created_at AS transaction_datetime,\n            p.total_amount AS debit,\n            0 AS credit,\n            p.paid_amount AS paid_amount,\n            CONVERT(COALESCE(p.payment_status, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,\n            CONVERT(COALESCE(p.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,\n            p.created_at,\n            CONVERT('Purchase Order' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,\n            NULL AS allocation_details\n        FROM purchases p\n        WHERE p.manufacturer_id = ?\n          AND p.business_id = ?\n          AND DATE(p.purchase_date) BETWEEN ? AND ?\n\n        UNION ALL\n\n        SELECT\n            'payment' AS transaction_type,\n            pay.id AS reference_id,\n            CONVERT(\n                CASE\n                    WHEN COALESCE(pay.reference_no, '') <> '' THEN pay.reference_no\n                    ELSE CONCAT('PAY-', pay.id)\n                END USING utf8mb4\n            ) COLLATE utf8mb4_unicode_ci AS reference_no,\n            DATE(pay.payment_date) AS transaction_date,\n            pay.created_at AS transaction_datetime,\n            0 AS debit,\n            pay.amount AS credit,\n            NULL AS paid_amount,\n            CONVERT('payment' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,\n            CONVERT(COALESCE(pay.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS notes,\n            pay.created_at,\n            CONVERT(\n                CONCAT(\n                    'Payment (', COALESCE(pay.payment_method, ''), ')',\n                    CASE WHEN {$payment_type_select} <> '' THEN CONCAT(' - ', REPLACE({$payment_type_select}, '_', ' ')) ELSE '' END\n                ) USING utf8mb4\n            ) COLLATE utf8mb4_unicode_ci AS description,\n            allocation_details\n        FROM (\n            SELECT pay.*\n            {$allocation_details_select}\n                   1 AS dummy_column\n            FROM payments pay\n            WHERE pay.type IN ('supplier', 'supplier_outstanding')\n              AND {$payment_manufacturer_filter}\n              {$payment_business_filter}\n              {$payment_deleted_filter}\n              AND DATE(pay.payment_date) BETWEEN ? AND ?\n        ) pay\n    ) x\n    ORDER BY transaction_date ASC, transaction_datetime ASC, reference_id ASC\n";

$transaction_params = [$manufacturer_id, $business_id, $from_date, $to_date];
$transaction_params = array_merge($transaction_params, $payment_reference_params, [$from_date, $to_date]);
$stmt = $pdo->prepare($transactions_sql);
$stmt->execute($transaction_params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Opening balance: purchase debit minus payments credit before selected from date.
$opening_payments_where = "pay.type IN ('supplier', 'supplier_outstanding') AND {$payment_manufacturer_filter} {$payment_business_filter} {$payment_deleted_filter} AND DATE(pay.payment_date) < ?";
$opening_sql = "\n    SELECT\n        COALESCE((\n            SELECT SUM(p.total_amount)\n            FROM purchases p\n            WHERE p.manufacturer_id = ?\n              AND p.business_id = ?\n              AND DATE(p.purchase_date) < ?\n        ), 0) AS total_purchases_before,\n\n        COALESCE((\n            SELECT SUM(pay.amount)\n            FROM payments pay\n            WHERE {$opening_payments_where}\n        ), 0) AS total_payments_before\n";

$opening_params = [$manufacturer_id, $business_id, $from_date];
$opening_params = array_merge($opening_params, $payment_reference_params, [$from_date]);
$stmt = $pdo->prepare($opening_sql);
$stmt->execute($opening_params);
$opening = $stmt->fetch(PDO::FETCH_ASSOC);

$opening_balance = moneyValue($opening['total_purchases_before'] ?? 0) - moneyValue($opening['total_payments_before'] ?? 0);

// Outstanding balance should appear as the first row if available.
$initial_outstanding_amount = moneyValue($manufacturer['initial_outstanding_amount'] ?? 0);
$initial_outstanding_type = $manufacturer['initial_outstanding_type'] ?? 'none';
$outstanding_row = null;
if ($initial_outstanding_amount > 0.01 && in_array($initial_outstanding_type, ['debit', 'credit'], true)) {
    $outstanding_row = [
        'transaction_type' => 'opening_outstanding',
        'reference_id' => 0,
        'reference_no' => 'OPENING-OUTSTANDING',
        'transaction_date' => $from_date,
        'transaction_datetime' => $from_date . ' 00:00:00',
        'debit' => $initial_outstanding_type === 'debit' ? $initial_outstanding_amount : 0,
        'credit' => $initial_outstanding_type === 'credit' ? $initial_outstanding_amount : 0,
        'paid_amount' => null,
        'payment_status' => $initial_outstanding_type,
        'notes' => 'Current supplier outstanding balance from supplier master',
        'created_at' => $from_date . ' 00:00:00',
        'description' => $initial_outstanding_type === 'debit' ? 'Supplier debit outstanding' : 'Supplier credit balance',
        'allocation_details' => null
    ];
}

$period_summary = [
    'total_purchases' => 0.00,
    'total_payments' => 0.00,
    'total_outstanding_debit' => 0.00,
    'total_outstanding_credit' => 0.00,
];

if ($outstanding_row) {
    $period_summary['total_outstanding_debit'] += moneyValue($outstanding_row['debit']);
    $period_summary['total_outstanding_credit'] += moneyValue($outstanding_row['credit']);
}

foreach ($transactions as $t) {
    $debit = moneyValue($t['debit'] ?? 0);
    $credit = moneyValue($t['credit'] ?? 0);
    if ($t['transaction_type'] === 'purchase') {
        $period_summary['total_purchases'] += $debit;
    } elseif ($t['transaction_type'] === 'payment') {
        $period_summary['total_payments'] += $credit;
    }
}

$closing_balance = $opening_balance
    + $period_summary['total_outstanding_debit']
    - $period_summary['total_outstanding_credit']
    + $period_summary['total_purchases']
    - $period_summary['total_payments'];

$purchase_balance = moneyValue($manufacturer['purchase_balance'] ?? 0);
if ($initial_outstanding_type === 'credit') {
    $current_net_payable = max(0, $purchase_balance - $initial_outstanding_amount);
} elseif ($initial_outstanding_type === 'debit') {
    $current_net_payable = $purchase_balance + $initial_outstanding_amount;
} else {
    $current_net_payable = $purchase_balance;
}

$purchase_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'purchase'));
$payment_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'payment'));
?>
<!DOCTYPE html>
<html lang="en">
<?php $page_title = 'Manufacturer Statement - ' . htmlspecialchars($manufacturer['name']); ?>
<?php include('includes/head.php'); ?>

<style>
    .stat-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        border-left: 4px solid #5b73e8;
        transition: transform .2s ease, box-shadow .2s ease;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.10);
    }
    .stat-icon {
        width: 48px;
        height: 48px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: 24px;
    }
    .filter-section, .info-card {
        background: #fff;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    }
    .transaction-row.purchase { background-color: #fff8ed; }
    .transaction-row.payment { background-color: #f0fbf2; }
    .transaction-row.opening_outstanding { background-color: #eef6ff; }
    .badge-transaction {
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-block;
    }
    .badge-purchase { background-color: #ff9800; color: #fff; }
    .badge-payment { background-color: #28a745; color: #fff; }
    .badge-outstanding { background-color: #2196f3; color: #fff; }
    .amount-debit { color: #dc3545; font-weight: 700; }
    .amount-credit { color: #198754; font-weight: 700; }
    .running-balance { font-weight: 700; font-size: 1rem; }
    @media print {
        .no-print, .btn, .page-title-box .d-flex, .filter-section, #topnav, .vertical-menu, .footer, .card-footer { display: none !important; }
        .main-content { margin-left: 0 !important; padding: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .stat-card, .info-card, .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
        .transaction-row.purchase, .transaction-row.payment, .transaction-row.opening_outstanding { print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; }
    }
</style>

<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-file me-2"></i> Supplier Statement
                                <small class="text-muted ms-2"><i class="bx bx-building me-1"></i><?= htmlspecialchars($manufacturer['name']) ?></small>
                            </h4>
                            <div class="d-flex gap-2 no-print">
                                <button onclick="window.print()" class="btn btn-outline-primary"><i class="bx bx-printer me-1"></i> Print</button>
                                <a href="manufacturer_statement_export.php?id=<?= $manufacturer_id ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" class="btn btn-outline-success"><i class="bx bx-download me-1"></i> Download PDF</a>
                                <a href="manufacturers.php" class="btn btn-outline-secondary"><i class="bx bx-arrow-back me-1"></i> Back</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-section no-print">
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="id" value="<?= $manufacturer_id ?>">
                        <div class="col-md-4">
                            <label class="form-label">From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100"><i class="bx bx-filter-alt me-1"></i> Generate Statement</button>
                        </div>
                    </form>
                </div>

                <div class="row mb-4">
                    <div class="col-lg-8">
                        <div class="info-card mb-0">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                <div>
                                    <h4 class="mb-2"><?= htmlspecialchars($manufacturer['name']) ?></h4>
                                    <p class="text-muted mb-1"><i class="bx bx-map me-1"></i><?= htmlspecialchars($manufacturer['address'] ?: 'No address') ?></p>
                                    <p class="text-muted mb-1">
                                        <i class="bx bx-phone me-1"></i><?= htmlspecialchars($manufacturer['phone'] ?: 'No phone') ?>
                                        <span class="mx-2">|</span>
                                        <i class="bx bx-envelope me-1"></i><?= htmlspecialchars($manufacturer['email'] ?: 'No email') ?>
                                    </p>
                                    <p class="text-muted mb-0">
                                        <i class="bx bx-barcode me-1"></i>GSTIN: <?= htmlspecialchars($manufacturer['gstin'] ?: 'Not Available') ?>
                                        <?php if (!empty($manufacturer['shop_name'])): ?>
                                            <span class="mx-2">|</span><i class="bx bx-store me-1"></i><?= htmlspecialchars($manufacturer['shop_name']) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <span class="badge bg-<?= !empty($manufacturer['is_active']) ? 'success' : 'secondary' ?> px-3 py-2"><?= !empty($manufacturer['is_active']) ? 'Active' : 'Inactive' ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="stat-card h-100 border-start border-<?= $current_net_payable > 0 ? 'warning' : 'success' ?> border-4">
                            <div class="card-body text-center">
                                <small class="text-muted text-uppercase">Current Net Payable</small>
                                <h3 class="mt-2 mb-1 text-<?= $current_net_payable > 0 ? 'warning' : 'success' ?>">₹<?= number_format(abs($current_net_payable), 2) ?></h3>
                                <small><?= $current_net_payable > 0 ? 'You owe to supplier' : 'No payable balance' ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card border-start border-secondary border-4">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div class="p-3">
                                    <h6 class="text-muted mb-1">Opening Balance</h6>
                                    <h4 class="mb-0 <?= $opening_balance >= 0 ? 'text-warning' : 'text-info' ?>">₹<?= number_format(abs($opening_balance), 2) ?></h4>
                                    <small><?= $opening_balance >= 0 ? 'Payable' : 'Receivable' ?></small>
                                </div>
                                <span class="stat-icon bg-secondary bg-opacity-10"><i class="bx bx-time text-secondary"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card border-start border-primary border-4">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div class="p-3">
                                    <h6 class="text-muted mb-1">Total Purchases</h6>
                                    <h4 class="mb-0 text-primary">₹<?= number_format($period_summary['total_purchases'], 2) ?></h4>
                                    <small><?= $purchase_count ?> order(s)</small>
                                </div>
                                <span class="stat-icon bg-primary bg-opacity-10"><i class="bx bx-cart text-primary"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card border-start border-success border-4">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div class="p-3">
                                    <h6 class="text-muted mb-1">Total Payments</h6>
                                    <h4 class="mb-0 text-success">₹<?= number_format($period_summary['total_payments'], 2) ?></h4>
                                    <small><?= $payment_count ?> payment(s)</small>
                                </div>
                                <span class="stat-icon bg-success bg-opacity-10"><i class="bx bx-money text-success"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card border-start border-info border-4">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div class="p-3">
                                    <h6 class="text-muted mb-1">Statement Closing</h6>
                                    <h4 class="mb-0 <?= $closing_balance >= 0 ? 'text-warning' : 'text-info' ?>">₹<?= number_format(abs($closing_balance), 2) ?></h4>
                                    <small><?= $closing_balance >= 0 ? 'Payable' : 'Receivable' ?></small>
                                </div>
                                <span class="stat-icon bg-info bg-opacity-10"><i class="bx bx-line-chart text-info"></i></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bx bx-list-ul me-2"></i>Transaction Statement</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Transaction Type</th>
                                        <th>Reference</th>
                                        <th>Description</th>
                                        <th class="text-end">Debit / Payable</th>
                                        <th class="text-end">Credit / Payment</th>
                                        <th class="text-end">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $running_balance = $opening_balance; ?>
                                    <tr class="table-secondary fw-bold">
                                        <td colspan="6" class="text-end">Opening Balance (as on <?= date('d M Y', strtotime($from_date)) ?>)</td>
                                        <td class="text-end <?= $running_balance >= 0 ? 'text-warning' : 'text-info' ?>">
                                            ₹<?= number_format(abs($running_balance), 2) ?><small class="d-block"><?= $running_balance >= 0 ? 'Dr' : 'Cr' ?></small>
                                        </td>
                                    </tr>

                                    <?php if ($outstanding_row): ?>
                                        <?php
                                            $running_balance += moneyValue($outstanding_row['debit']) - moneyValue($outstanding_row['credit']);
                                        ?>
                                        <tr class="transaction-row opening_outstanding">
                                            <td><strong><?= date('d M Y', strtotime($outstanding_row['transaction_date'])) ?></strong><br><small class="text-muted">Opening row</small></td>
                                            <td><span class="badge-transaction badge-outstanding">OUTSTANDING</span><br><small class="text-muted"><?= ucfirst($initial_outstanding_type) ?></small></td>
                                            <td><strong><?= htmlspecialchars($outstanding_row['reference_no']) ?></strong></td>
                                            <td>
                                                <?= htmlspecialchars($outstanding_row['description']) ?>
                                                <br><small class="text-muted"><i class="bx bx-note"></i> <?= htmlspecialchars($outstanding_row['notes']) ?></small>
                                            </td>
                                            <td class="text-end amount-debit"><?= moneyValue($outstanding_row['debit']) > 0 ? '₹' . number_format($outstanding_row['debit'], 2) : '—' ?></td>
                                            <td class="text-end amount-credit"><?= moneyValue($outstanding_row['credit']) > 0 ? '₹' . number_format($outstanding_row['credit'], 2) : '—' ?></td>
                                            <td class="text-end running-balance <?= $running_balance >= 0 ? 'text-warning' : 'text-info' ?>">₹<?= number_format(abs($running_balance), 2) ?><small class="d-block"><?= $running_balance >= 0 ? 'Dr' : 'Cr' ?></small></td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php if (!empty($transactions)): ?>
                                        <?php foreach ($transactions as $t): ?>
                                            <?php
                                                $debit = moneyValue($t['debit'] ?? 0);
                                                $credit = moneyValue($t['credit'] ?? 0);
                                                $running_balance += $debit - $credit;
                                                if ($t['transaction_type'] === 'purchase') {
                                                    $row_class = 'purchase';
                                                    $badge_class = 'badge-purchase';
                                                    $type_label = 'PURCHASE';
                                                } else {
                                                    $row_class = 'payment';
                                                    $badge_class = 'badge-payment';
                                                    $type_label = 'PAYMENT';
                                                }
                                            ?>
                                            <tr class="transaction-row <?= $row_class ?>">
                                                <td><strong><?= date('d M Y', strtotime($t['transaction_date'])) ?></strong><br><small class="text-muted"><?= !empty($t['created_at']) ? date('h:i A', strtotime($t['created_at'])) : '' ?></small></td>
                                                <td>
                                                    <span class="badge-transaction <?= $badge_class ?>"><?= $type_label ?></span>
                                                    <?php if ($t['transaction_type'] === 'purchase'): ?><br><small class="text-muted"><?= ucfirst($t['payment_status']) ?></small><?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($t['reference_no'] ?: '—') ?></strong>
                                                    <?php if ($t['transaction_type'] === 'purchase'): ?><br><a href="purchase_view.php?id=<?= (int)$t['reference_id'] ?>" class="small" target="_blank"><i class="bx bx-link-external"></i> View</a><?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($t['description']) ?>
                                                    <?php if (!empty($t['allocation_details'])): ?><br><small class="text-muted"><i class="bx bx-git-branch"></i> <?= $t['allocation_details'] ?></small><?php endif; ?>
                                                    <?php if (!empty($t['notes'])): ?><br><small class="text-muted"><i class="bx bx-note"></i> <?= htmlspecialchars($t['notes']) ?></small><?php endif; ?>
                                                </td>
                                                <td class="text-end amount-debit"><?= $debit > 0 ? '₹' . number_format($debit, 2) : '—' ?></td>
                                                <td class="text-end amount-credit"><?= $credit > 0 ? '₹' . number_format($credit, 2) : '—' ?></td>
                                                <td class="text-end running-balance <?= $running_balance >= 0 ? 'text-warning' : 'text-info' ?>">₹<?= number_format(abs($running_balance), 2) ?><small class="d-block"><?= $running_balance >= 0 ? 'Dr' : 'Cr' ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php elseif (!$outstanding_row): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <i class="bx bx-data fs-1 text-muted mb-3"></i>
                                                <h5>No transactions found</h5>
                                                <p class="text-muted mb-0">No transactions in the selected period</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                    <tr class="table-primary fw-bold">
                                        <td colspan="6" class="text-end">Closing Balance (as on <?= date('d M Y', strtotime($to_date)) ?>)</td>
                                        <td class="text-end <?= $running_balance >= 0 ? 'text-warning' : 'text-info' ?>">
                                            ₹<?= number_format(abs($running_balance), 2) ?><small class="d-block"><?= $running_balance >= 0 ? 'Dr' : 'Cr' ?></small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="row">
                            <div class="col-md-6">
                                <small class="text-muted"><i class="bx bx-info-circle me-1"></i><span class="badge-purchase badge-transaction">PURCHASE</span> Purchase Orders <span class="mx-2"></span><span class="badge-payment badge-transaction">PAYMENT</span> Payments from payments table <span class="mx-2"></span><span class="badge-outstanding badge-transaction">OUTSTANDING</span> Supplier opening/current balance</small>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted"><span class="text-warning">Dr</span> = You owe supplier | <span class="text-info">Cr</span> = Supplier owes you</small>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($manufacturer['account_holder_name']) || !empty($manufacturer['bank_name']) || !empty($manufacturer['account_number'])): ?>
                    <div class="card shadow-sm mt-4">
                        <div class="card-header bg-light"><h5 class="mb-0"><i class="bx bx-bank me-2"></i>Bank Details</h5></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3"><small class="text-muted d-block">Account Holder</small><strong><?= htmlspecialchars($manufacturer['account_holder_name'] ?: '—') ?></strong></div>
                                <div class="col-md-3"><small class="text-muted d-block">Bank Name</small><strong><?= htmlspecialchars($manufacturer['bank_name'] ?: '—') ?></strong></div>
                                <div class="col-md-3"><small class="text-muted d-block">Account Number</small><strong><?= htmlspecialchars($manufacturer['account_number'] ?: '—') ?></strong></div>
                                <div class="col-md-3"><small class="text-muted d-block">IFSC Code</small><strong><?= htmlspecialchars($manufacturer['ifsc_code'] ?: '—') ?></strong></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row mt-4">
                    <div class="col-12 text-center text-muted">
                        <small>This is a computer generated statement and does not require signature.<br>For any discrepancies, please contact the accounts department.</small>
                    </div>
                </div>
            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/scripts.php'); ?>
<script>
$(document).ready(function() {
    $('[data-bs-toggle="tooltip"]').tooltip();
    $('form').on('submit', function(e) {
        const fromDate = new Date($('input[name="from_date"]').val());
        const toDate = new Date($('input[name="to_date"]').val());
        if (fromDate > toDate) {
            e.preventDefault();
            Swal.fire({ icon: 'error', title: 'Invalid Date Range', text: 'From Date cannot be greater than To Date' });
        }
    });
});
</script>
</body>
</html>
