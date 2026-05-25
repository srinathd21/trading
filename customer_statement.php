<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once ('config/database.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager', 'accountant'])) {
    header('Location: dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'];
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$customer_id) {
    header('Location: customers.php');
    exit();
}

// Get date filters
$from_date = $_GET['from_date'] ?? date('Y-m-01'); // First day of current month
$to_date = $_GET['to_date'] ?? date('Y-m-d'); // Today

// Get customer details
$stmt = $pdo->prepare("
    SELECT c.*, 
           (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE customer_id = c.id AND business_id = ?) as total_purchases,
           (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = ?) as total_paid,
           (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND business_id = ?) as invoice_count
    FROM customers c
    WHERE c.id = ? AND c.business_id = ?
");
$stmt->execute([$business_id, $business_id, $business_id, $customer_id, $business_id]);
$customer = $stmt->fetch();

if (!$customer) {
    $_SESSION['error'] = "Customer not found";
    header('Location: customers.php');
    exit();
}

// Get all transactions for statement - using invoices + customer_payments + customer_payment_allocations only
// IMPORTANT: invoice_payments table is not used here.
$transactions_sql = "
    SELECT
        CAST('invoice' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as transaction_type,
        i.id as reference_id,
        CONVERT(COALESCE(i.invoice_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as reference_no,
        i.created_at as transaction_datetime,
        DATE(i.created_at) as transaction_date,
        TIME(i.created_at) as transaction_time,
        i.total as debit,
        0 as credit,
        i.paid_amount as paid_amount,
        CONVERT(COALESCE(i.payment_status, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as payment_status,
        CONVERT(COALESCE(i.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as notes,
        i.created_at,
        CONVERT(CONCAT('Invoice - ', COALESCE(i.invoice_type, 'Sale')) USING utf8mb4) COLLATE utf8mb4_unicode_ci as description
    FROM invoices i
    WHERE i.customer_id = ?
      AND i.business_id = ?
      AND DATE(i.created_at) BETWEEN ? AND ?

    UNION ALL

    SELECT
        CAST('payment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as transaction_type,
        cp.id as reference_id,
        CONVERT(
            CASE
                WHEN COALESCE(cp.reference_no, '') <> '' THEN cp.reference_no
                ELSE CONCAT('PAY-', cp.id)
            END USING utf8mb4
        ) COLLATE utf8mb4_unicode_ci as reference_no,
        cp.created_at as transaction_datetime,
        DATE(cp.payment_date) as transaction_date,
        TIME(cp.created_at) as transaction_time,
        0 as debit,
        COALESCE(SUM(cpa.allocated_amount), 0) as credit,
        NULL as paid_amount,
        CONVERT(COALESCE(cp.payment_type, 'payment') USING utf8mb4) COLLATE utf8mb4_unicode_ci as payment_status,
        CONVERT(COALESCE(cp.notes, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as notes,
        cp.created_at,
        CONVERT(
            CONCAT(
                CASE
                    WHEN COALESCE(cp.payment_type, '') = 'outstanding' THEN 'Outstanding Collection'
                    WHEN COALESCE(cp.payment_mode, '') = 'invoice_wise' THEN 'Invoice-wise Payment'
                    WHEN COALESCE(cp.payment_mode, '') = 'bulk' THEN 'Overall Collection'
                    ELSE 'Payment'
                END,
                ' (', COALESCE(cp.payment_method, ''), ')',
                CASE
                    WHEN GROUP_CONCAT(
                        CONCAT(
                            CASE
                                WHEN cpa.allocation_type = 'invoice' THEN COALESCE(cpa.invoice_number, CONCAT('Invoice #', cpa.invoice_id))
                                WHEN cpa.allocation_type = 'manual_credit' THEN 'Manual Outstanding'
                                WHEN cpa.allocation_type = 'advance' THEN 'Advance'
                                ELSE cpa.allocation_type
                            END,
                            ': ₹', FORMAT(cpa.allocated_amount, 2)
                        )
                        ORDER BY cpa.id ASC SEPARATOR ', '
                    ) IS NOT NULL
                    THEN CONCAT(' - ', GROUP_CONCAT(
                        CONCAT(
                            CASE
                                WHEN cpa.allocation_type = 'invoice' THEN COALESCE(cpa.invoice_number, CONCAT('Invoice #', cpa.invoice_id))
                                WHEN cpa.allocation_type = 'manual_credit' THEN 'Manual Outstanding'
                                WHEN cpa.allocation_type = 'advance' THEN 'Advance'
                                ELSE cpa.allocation_type
                            END,
                            ': ₹', FORMAT(cpa.allocated_amount, 2)
                        )
                        ORDER BY cpa.id ASC SEPARATOR ', '
                    ))
                    ELSE ''
                END
            ) USING utf8mb4
        ) COLLATE utf8mb4_unicode_ci as description
    FROM customer_payments cp
    LEFT JOIN customer_payment_allocations cpa
           ON cpa.payment_id = cp.id
          AND cpa.business_id = cp.business_id
          AND cpa.customer_id = cp.customer_id
          AND COALESCE(cpa.is_deleted, 0) = 0
    WHERE cp.customer_id = ?
      AND cp.business_id = ?
      AND COALESCE(cp.is_deleted, 0) = 0
      AND DATE(cp.payment_date) BETWEEN ? AND ?
    GROUP BY cp.id, cp.business_id, cp.customer_id, cp.reference_no, cp.created_at, cp.payment_date,
             cp.payment_type, cp.payment_mode, cp.payment_method, cp.notes

    UNION ALL

    SELECT
        CAST('adjustment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci as transaction_type,
        ca.id as reference_id,
        CONVERT(COALESCE(ca.description, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as reference_no,
        ca.created_at as transaction_datetime,
        DATE(ca.adjustment_date) as transaction_date,
        TIME(ca.created_at) as transaction_time,
        CASE WHEN ca.adjustment_type = 'debit' THEN ca.amount ELSE 0 END as debit,
        CASE WHEN ca.adjustment_type = 'credit' THEN ca.amount ELSE 0 END as credit,
        NULL as paid_amount,
        CONVERT(COALESCE(ca.adjustment_type, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as payment_status,
        CONVERT(COALESCE(ca.description, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci as notes,
        ca.created_at,
        CONVERT(CONCAT('Credit Adjustment (', COALESCE(ca.adjustment_type, ''), ')') USING utf8mb4) COLLATE utf8mb4_unicode_ci as description
    FROM customer_credit_adjustments ca
    WHERE ca.customer_id = ?
      AND ca.business_id = ?
      AND DATE(ca.adjustment_date) BETWEEN ? AND ?

    ORDER BY transaction_datetime DESC, created_at DESC
";

$stmt = $pdo->prepare($transactions_sql);
$stmt->execute([
    $customer_id, $business_id, $from_date, $to_date,
    $customer_id, $business_id, $from_date, $to_date,
    $customer_id, $business_id, $from_date, $to_date
]);
$transactions = $stmt->fetchAll();

// Live manual outstanding balance from customers table.
// This is displayed as the first row of the statement when present.
$manual_outstanding_balance = (($customer['outstanding_type'] ?? 'credit') === 'credit')
    ? (float)($customer['outstanding_amount'] ?? 0)
    : -(float)($customer['outstanding_amount'] ?? 0);

$invoice_outstanding_stmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(total - paid_amount, 0)), 0) FROM invoices WHERE customer_id = ? AND business_id = ?");
$invoice_outstanding_stmt->execute([$customer_id, $business_id]);
$invoice_outstanding_balance = (float)$invoice_outstanding_stmt->fetchColumn();
$live_balance = $invoice_outstanding_balance + $manual_outstanding_balance;

// Calculate opening balance (all transactions before from_date)
// Payments are calculated from customer_payment_allocations, not invoice_payments.
$opening_sql = "
    SELECT
        COALESCE((
            SELECT SUM(total)
            FROM invoices
            WHERE customer_id = ?
              AND business_id = ?
              AND DATE(created_at) < ?
        ), 0) as total_invoices_before,

        COALESCE((
            SELECT SUM(cpa.allocated_amount)
            FROM customer_payments cp
            INNER JOIN customer_payment_allocations cpa
                    ON cpa.payment_id = cp.id
                   AND cpa.business_id = cp.business_id
                   AND cpa.customer_id = cp.customer_id
                   AND COALESCE(cpa.is_deleted, 0) = 0
            WHERE cp.customer_id = ?
              AND cp.business_id = ?
              AND COALESCE(cp.is_deleted, 0) = 0
              AND DATE(cp.payment_date) < ?
        ), 0) as total_payments_before,

        COALESCE((
            SELECT
                SUM(CASE
                    WHEN adjustment_type = 'debit' THEN amount
                    WHEN adjustment_type = 'credit' THEN -amount
                    ELSE 0
                END)
            FROM customer_credit_adjustments
            WHERE customer_id = ?
              AND business_id = ?
              AND DATE(adjustment_date) < ?
        ), 0) as adjustment_balance_before
";

$stmt = $pdo->prepare($opening_sql);
$stmt->execute([
    $customer_id, $business_id, $from_date,
    $customer_id, $business_id, $from_date,
    $customer_id, $business_id, $from_date
]);
$opening = $stmt->fetch();

$opening_balance = $opening['total_invoices_before'] - $opening['total_payments_before'] + $opening['adjustment_balance_before'];

// Get summary for the period
$period_summary = [
    'total_invoices' => 0,
    'total_payments' => 0,
    'total_adjustment_debit' => 0,
    'total_adjustment_credit' => 0
];

foreach ($transactions as $t) {
    if ($t['transaction_type'] == 'invoice') {
        $period_summary['total_invoices'] += $t['debit'];
    } elseif ($t['transaction_type'] == 'payment') {
        $period_summary['total_payments'] += $t['credit'];
    } elseif ($t['transaction_type'] == 'adjustment') {
        $period_summary['total_adjustment_debit'] += $t['debit'];
        $period_summary['total_adjustment_credit'] += $t['credit'];
    }
}

// Calculate running balance from oldest to newest for the selected period.
$transactions_reverse = array_reverse($transactions);
$running_balance = $opening_balance;
foreach ($transactions_reverse as $t) {
    $running_balance += $t['debit'] - $t['credit'];
}
$period_closing_balance = $running_balance;

// Current balance should reflect live invoice pending + live manual outstanding/debit balance.
$closing_balance = $live_balance;

// Get credit limit status
$credit_limit = $customer['credit_limit'];
$credit_used = $closing_balance > 0 ? $closing_balance : 0;
$credit_available = $credit_limit ? ($credit_limit - $credit_used) : null;
$is_over_limit = $credit_limit && $credit_used > $credit_limit;
?>
<!DOCTYPE html>
<html lang="en">
<?php $page_title = "Customer Statement - " . htmlspecialchars($customer['name']); ?>
<?php include('includes/head.php'); ?>

<!-- Statement specific styles -->
<style>
    .card-hover { transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .card-hover:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important; }
    .border-start { border-left-width: 4px !important; }
    .avatar-sm { width: 48px; height: 48px; }
    .statement-header {
        background: #ffffff;
        border-left: 4px solid #0d6efd;
        border-radius: 0.25rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
    }
    .company-info { border-right: 1px solid #e9ecef; }
    .balance-card {
        background: #ffffff;
        border-radius: 0.25rem;
        padding: 1.25rem;
        box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
        margin-bottom: 1rem;
    }
    .balance-card h3 { margin: 0; font-size: 1.8rem; font-weight: 600; }
    .balance-card.positive { border-left: 4px solid #dc3545; }
    .balance-card.negative { border-left: 4px solid #198754; }
    .balance-card.warning { border-left: 4px solid #ffc107; }
    .summary-card {
        background: #ffffff;
        border-left: 4px solid #0d6efd;
        border-radius: 0.25rem;
        padding: 1.25rem;
        text-align: left;
        box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
        height: 100%;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .summary-card:hover { transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.12) !important; }
    .summary-card .label { font-size: 0.85rem; color: #6c757d; font-weight: 600; }
    .summary-card .value { font-size: 1.4rem; font-weight: 700; margin-top: 0.35rem; }
    .transaction-row.invoice { background-color: #fff8ed; }
    .transaction-row.payment { background-color: #f1fff4; }
    .transaction-row.adjustment { background-color: #f3f8ff; }
    .transaction-row.manual-balance { background-color: #fff5f5; border-left: 4px solid #dc3545; }
    .badge-transaction { padding: 0.4rem 0.8rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    .badge-invoice { background-color: #ff9800; color: white; }
    .badge-payment { background-color: #4caf50; color: white; }
    .badge-adjustment { background-color: #2196f3; color: white; }
    .badge-debit { background-color: #dc3545; color: white; }
    .badge-credit { background-color: #28a745; color: white; }
    .badge-outstanding { background-color: #dc3545; color: white; }
    .amount-positive { color: #dc3545; font-weight: 600; }
    .amount-negative { color: #198754; font-weight: 600; }
    .filter-section {
        background: #ffffff;
        border-radius: 0.25rem;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
    }
    .credit-card { background: #ffffff; color: inherit; border-left: 4px solid #0d6efd; }
    .credit-card.warning { border-left-color: #dc3545; }
    .credit-card .value { font-size: 1.6rem; font-weight: bold; }
    @media (max-width: 768px) {
        .company-info { border-right: 0; border-bottom: 1px solid #e9ecef; padding-bottom: 1rem; margin-bottom: 1rem; }
    }
    @media print {
        .no-print { display: none !important; }
        .statement-header, .balance-card, .summary-card, .credit-card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
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

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-file me-2"></i> Customer Statement
                                <small class="text-muted ms-2">
                                    <i class="bx bx-user me-1"></i>
                                    <?= htmlspecialchars($customer['name']) ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2 no-print">
                                <button onclick="window.print()" class="btn btn-outline-primary">
                                    <i class="bx bx-printer me-1"></i> Print
                                </button>
                                <a href="customer_statement_export.php?id=<?= (int)$customer_id ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" 
                                   class="btn btn-success">
                                    <i class="bx bx-download me-1"></i> Download Statement
                                </a>
                                <a href="customers.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statement Header -->
                <div class="statement-header no-print">
                    <div class="row align-items-center">
                        <div class="col-md-6 company-info">
                            <h2 class="mb-1"><?= htmlspecialchars($_SESSION['business_name'] ?? 'Your Business') ?></h2>
                            <p class="mb-0 text-muted">GSTIN: <?= htmlspecialchars($_SESSION['business_gst'] ?? 'Not Available') ?></p>
                            <p class="mb-0 text-muted"><?= htmlspecialchars($_SESSION['business_address'] ?? '') ?></p>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h3 class="mb-1">Customer Statement</h3>
                            <p class="mb-0 text-muted">
                                Period: <?= date('d M Y', strtotime($from_date)) ?> - <?= date('d M Y', strtotime($to_date)) ?>
                            </p>
                            <p class="mb-0 text-muted">Generated on: <?= date('d M Y h:i A') ?></p>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section no-print">
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="id" value="<?= $customer_id ?>">
                        <div class="col-md-4">
                            <label class="form-label">From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?= $from_date ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?= $to_date ?>" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary flex-fill">
                                <i class="bx bx-filter-alt me-1"></i> Generate
                            </button>
                            <a href="customer_statement_export.php?id=<?= (int)$customer_id ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>"
                               class="btn btn-success flex-fill">
                                <i class="bx bx-download me-1"></i> Download
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Customer Info -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="balance-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h4><?= htmlspecialchars($customer['name']) ?></h4>
                                    <p class="text-muted mb-1">
                                        <i class="bx bx-phone me-1"></i> <?= htmlspecialchars($customer['phone'] ?: 'No phone') ?>
                                        <?php if ($customer['alt_phone']): ?>
                                        <span class="mx-2">|</span>
                                        <i class="bx bx-phone-call me-1"></i> <?= htmlspecialchars($customer['alt_phone']) ?>
                                        <?php endif; ?>
                                    </p>
                                    <p class="text-muted mb-1">
                                        <i class="bx bx-envelope me-1"></i> <?= htmlspecialchars($customer['email'] ?: 'No email') ?>
                                        <span class="mx-2">|</span>
                                        <i class="bx bx-map me-1"></i> <?= htmlspecialchars($customer['address'] ?: 'No address') ?>
                                    </p>
                                    <p class="text-muted mb-0">
                                        <i class="bx bx-barcode me-1"></i> GSTIN: <?= htmlspecialchars($customer['gstin'] ?: 'Not Available') ?>
                                        <span class="mx-2">|</span>
                                        <i class="bx bx-trending-up me-1"></i> Type: <?= ucfirst($customer['customer_type']) ?>
                                    </p>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-<?= $customer['outstanding_type'] == 'credit' ? 'warning' : 'info' ?> px-3 py-2">
                                        <?= ucfirst($customer['outstanding_type']) ?> Customer
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="balance-card <?= $closing_balance > 0 ? 'positive' : ($closing_balance < 0 ? 'negative' : '') ?>">
                            <div class="text-center">
                                <small class="text-muted text-uppercase">Current Balance</small>
                                <h3 class="<?= $closing_balance > 0 ? 'text-danger' : ($closing_balance < 0 ? 'text-success' : 'text-secondary') ?>">
                                    ₹<?= number_format(abs($closing_balance), 2) ?>
                                </h3>
                                <small><?= $closing_balance > 0 ? 'Customer owes to you' : ($closing_balance < 0 ? 'You owe to customer' : 'Settled') ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Credit Limit Card -->
                <?php if ($credit_limit): ?>
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="credit-card <?= $is_over_limit ? 'warning' : '' ?> balance-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-1">Credit Limit Information</h5>
                                    <p class="mb-0 text-muted">Credit Limit: ₹<?= number_format($credit_limit, 2) ?></p>
                                    <p class="mb-0 text-muted">Credit Used: ₹<?= number_format($credit_used, 2) ?></p>
                                </div>
                                <div class="text-end">
                                    <div class="value">
                                        Available: ₹<?= number_format($credit_available, 2) ?>
                                    </div>
                                    <?php if ($is_over_limit): ?>
                                    <small class="text-warning">⚠️ Credit limit exceeded!</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="progress mt-3" style="height: 8px;">
                                <?php 
                                $percentage = ($credit_used / $credit_limit) * 100;
                                $bar_class = $percentage > 90 ? 'bg-danger' : ($percentage > 70 ? 'bg-warning' : 'bg-success');
                                ?>
                                <div class="progress-bar <?= $bar_class ?>" style="width: <?= min($percentage, 100) ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="label">Opening Balance</div>
                            <div class="value <?= $opening_balance > 0 ? 'text-danger' : ($opening_balance < 0 ? 'text-success' : 'text-secondary') ?>">
                                ₹<?= number_format(abs($opening_balance), 2) ?>
                            </div>
                            <small><?= $opening_balance > 0 ? 'Customer owes' : ($opening_balance < 0 ? 'You owe' : 'Settled') ?></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="label">Total Purchases</div>
                            <div class="value text-primary">
                                ₹<?= number_format($period_summary['total_invoices'], 2) ?>
                            </div>
                            <small><?= count(array_filter($transactions, fn($t) => $t['transaction_type'] == 'invoice')) ?> invoices</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="label">Total Payments</div>
                            <div class="value text-success">
                                ₹<?= number_format($period_summary['total_payments'], 2) ?>
                            </div>
                            <small><?= count(array_filter($transactions, fn($t) => $t['transaction_type'] == 'payment')) ?> payments</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="label">Net Outstanding</div>
                            <div class="value <?= $closing_balance > 0 ? 'text-danger' : ($closing_balance < 0 ? 'text-success' : 'text-secondary') ?>">
                                ₹<?= number_format(abs($closing_balance), 2) ?>
                            </div>
                            <small><?= $closing_balance > 0 ? 'Receivable' : ($closing_balance < 0 ? 'Payable' : 'Settled') ?></small>
                        </div>
                    </div>
                </div>

                <!-- Statement Table - Most Recent on Top -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-list-ul me-2"></i> Transaction Statement
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Transaction Type</th>
                                        <th>Reference</th>
                                        <th>Description</th>
                                        <th class="text-end">Debit (Purchases)</th>
                                        <th class="text-end">Credit (Payments)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $has_transactions = false;
                                    ?>

                                    <?php if (abs($manual_outstanding_balance) > 0.01):
                                        $has_transactions = true;
                                        $manual_balance_is_receivable = $manual_outstanding_balance > 0;
                                    ?>
                                    <tr class="transaction-row manual-balance">
                                        <td>
                                            <strong>Current</strong><br>
                                            <small class="text-muted">Manual Balance</small>
                                        </td>
                                        <td>
                                            <span class="badge-transaction badge-outstanding">OUTSTANDING</span>
                                        </td>
                                        <td><strong>Opening/Manual</strong></td>
                                        <td>
                                            Manual outstanding balance from customer master.
                                            <br><small class="text-muted">Shown first for clarity. Payments below are calculated from customer_payments and customer_payment_allocations.</small>
                                        </td>
                                        <td class="text-end amount-positive">
                                            <?= $manual_balance_is_receivable ? '₹' . number_format(abs($manual_outstanding_balance), 2) : '—' ?>
                                        </td>
                                        <td class="text-end amount-negative">
                                            <?= !$manual_balance_is_receivable ? '₹' . number_format(abs($manual_outstanding_balance), 2) : '—' ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <?php foreach ($transactions as $t): 
                                        $has_transactions = true;
                                        $row_class = '';
                                        $badge_class = '';
                                        
                                        if ($t['transaction_type'] == 'invoice') {
                                            $row_class = 'invoice';
                                            $badge_class = 'badge-invoice';
                                            $type_label = 'INVOICE';
                                        } elseif ($t['transaction_type'] == 'payment') {
                                            $row_class = 'payment';
                                            $badge_class = 'badge-payment';
                                            $type_label = 'PAYMENT';
                                        } else {
                                            $row_class = 'adjustment';
                                            $badge_class = $t['payment_status'] == 'debit' ? 'badge-debit' : 'badge-credit';
                                            $type_label = strtoupper($t['payment_status'] == 'debit' ? 'DEBIT NOTE' : 'CREDIT NOTE');
                                        }
                                    ?>
                                    <tr class="transaction-row <?= $row_class ?>">
                                        <td>
                                            <strong><?= date('d M Y', strtotime($t['transaction_date'])) ?></strong>
                                            <br>
                                            <small class="text-muted"><?= date('h:i A', strtotime($t['transaction_time'] ?? $t['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge-transaction <?= $badge_class ?>">
                                                <?= $type_label ?>
                                            </span>
                                            <?php if ($t['transaction_type'] == 'invoice'): ?>
                                            <br>
                                            <small class="text-muted"><?= ucfirst($t['payment_status']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($t['reference_no'] ?: '—') ?></strong>
                                            <?php if ($t['transaction_type'] == 'invoice'): ?>
                                            <br>
                                            <a href="invoice_view.php?id=<?= $t['reference_id'] ?>" class="small" target="_blank">
                                                <i class="bx bx-link-external"></i> View
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($t['description']) ?>
                                            <?php if ($t['notes']): ?>
                                            <br>
                                            <small class="text-muted">
                                                <i class="bx bx-note"></i> <?= htmlspecialchars($t['notes']) ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end amount-positive">
                                            <?php if ($t['debit'] > 0): ?>
                                            ₹<?= number_format($t['debit'], 2) ?>
                                            <?php else: ?>
                                            —
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end amount-negative">
                                            <?php if ($t['credit'] > 0): ?>
                                            ₹<?= number_format($t['credit'], 2) ?>
                                            <?php else: ?>
                                            —
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (!$has_transactions): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <div class="empty-state">
                                                <i class="bx bx-data fs-1 text-muted mb-3"></i>
                                                <h5>No transactions found</h5>
                                                <p class="text-muted">
                                                    No transactions in the selected period
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                             </table>
                        </div>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="row">
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="bx bx-info-circle me-1"></i>
                                    <span class="badge-invoice badge-transaction">INVOICE</span> - Sales Invoices
                                    <span class="mx-2"></span>
                                    <span class="badge-payment badge-transaction">PAYMENT</span> - Payments Received
                                    <span class="mx-2"></span>
                                    <span class="badge-adjustment badge-transaction">ADJUSTMENT</span> - Credit/Debit Notes <span class="mx-2"></span><span class="badge-outstanding badge-transaction">OUTSTANDING</span> - Manual Balance
                                </small>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    <span class="text-danger">Dr (Debit)</span> - Customer owes you |
                                    <span class="text-success">Cr (Credit)</span> - You owe customer
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment History Summary -->
                <?php if (!empty(array_filter($transactions, fn($t) => $t['transaction_type'] == 'payment'))): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-credit-card me-2"></i> Payment Summary
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php
                            $payment_methods = [];
                            foreach ($transactions as $t) {
                                if ($t['transaction_type'] == 'payment' && $t['credit'] > 0) {
                                    $method = 'Unknown';
                                    $desc = strtolower($t['description']);
                                    if (strpos($desc, 'cash') !== false) $method = 'Cash';
                                    elseif (strpos($desc, 'upi') !== false) $method = 'UPI';
                                    elseif (strpos($desc, 'bank') !== false) $method = 'Bank Transfer';
                                    elseif (strpos($desc, 'cheque') !== false) $method = 'Cheque';
                                    else $method = 'Other';
                                    
                                    if (!isset($payment_methods[$method])) {
                                        $payment_methods[$method] = 0;
                                    }
                                    $payment_methods[$method] += $t['credit'];
                                }
                            }
                            ?>
                            <?php foreach ($payment_methods as $method => $amount): ?>
                            <div class="col-md-3">
                                <div class="summary-card">
                                    <div class="label"><?= $method ?></div>
                                    <div class="value text-success">
                                        ₹<?= number_format($amount, 2) ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Outstanding Invoices Section -->
                <?php 
                // Get outstanding invoices
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
                $outstanding_invoices = $stmt->fetchAll();
                ?>
                
                <?php if (!empty($outstanding_invoices)): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-time me-2"></i> Outstanding Invoices
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th class="text-end">Total Amount</th>
                                        <th class="text-end">Paid Amount</th>
                                        <th class="text-end">Outstanding</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($outstanding_invoices as $inv): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong>
                                        </td>
                                        <td><?= date('d M Y', strtotime($inv['created_at'])) ?></td>
                                        <td class="text-end">₹<?= number_format($inv['total'], 2) ?></td>
                                        <td class="text-end">₹<?= number_format($inv['paid_amount'], 2) ?></td>
                                        <td class="text-end text-danger fw-bold">
                                            ₹<?= number_format($inv['outstanding'], 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $inv['paid_amount'] > 0 ? 'warning' : 'danger' ?>">
                                                <?= $inv['paid_amount'] > 0 ? 'Partial' : 'Unpaid' ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                             </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Footer Note -->
                <div class="row mt-4">
                    <div class="col-12 text-center text-muted">
                        <small>
                            This is a computer generated statement and does not require signature.
                            <br>
                            For any discrepancies, please contact the accounts department.
                        </small>
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
    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Validate date range
    $('form').on('submit', function(e) {
        const fromDate = new Date($('input[name="from_date"]').val());
        const toDate = new Date($('input[name="to_date"]').val());
        
        if (fromDate > toDate) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Date Range',
                text: 'From Date cannot be greater than To Date'
            });
        }
    });
});

function printStatement() {
    window.print();
}
</script>

<style>
@media print {
    .no-print, .btn, .page-title-box .d-flex, .filter-section, 
    #topnav, .vertical-menu, .footer, .card-footer {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .container-fluid {
        padding: 0 !important;
    }
    .statement-header {
        background: white !important;
        color: black !important;
        border: 1px solid #dee2e6 !important;
        margin: 0 !important;
        padding: 1rem !important;
    }
    .balance-card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
    .credit-card {
        background: white !important;
        color: black !important;
        border: 1px solid #dee2e6;
    }
    .table {
        border: 1px solid #dee2e6 !important;
    }
    .badge-transaction {
        print-color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
    }
    .invoice {
        background-color: #fff3e0 !important;
        print-color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
    }
    .payment {
        background-color: #e8f5e8 !important;
        print-color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
    }
    .adjustment {
        background-color: #e3f2fd !important;
        print-color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
    }
}
</style>

</body>
</html>