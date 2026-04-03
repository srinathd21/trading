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

// Get all transactions for statement
// FIX: Use CONVERT or CAST to ensure consistent collation
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
        CONVERT(CONCAT('Invoice - ', COALESCE(i.invoice_type, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci as description
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
    JOIN invoices i ON ip.invoice_id = i.id
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
        CONVERT(CONCAT('Credit Adjustment (', COALESCE(ca.adjustment_type, ''), ')') USING utf8mb4) COLLATE utf8mb4_unicode_ci as description
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
$transactions = $stmt->fetchAll();

// Calculate opening balance (all transactions before from_date)
$opening_sql = "
    SELECT 
        COALESCE((
            SELECT SUM(total) FROM invoices 
            WHERE customer_id = ? AND business_id = ? 
            AND DATE(created_at) < ?
        ), 0) as total_invoices_before,
        
        COALESCE((
            SELECT SUM(ip.payment_amount) FROM invoice_payments ip
            JOIN invoices i ON ip.invoice_id = i.id
            WHERE i.customer_id = ? AND i.business_id = ?
            AND DATE(ip.payment_date) < ?
        ), 0) as total_payments_before,
        
        COALESCE((
            SELECT 
                SUM(CASE 
                    WHEN adjustment_type = 'debit' THEN amount 
                    WHEN adjustment_type = 'credit' THEN -amount 
                    ELSE 0 
                END) 
            FROM customer_credit_adjustments 
            WHERE customer_id = ? AND business_id = ? AND DATE(adjustment_date) < ?
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

$closing_balance = $opening_balance + $period_summary['total_invoices'] - $period_summary['total_payments'] 
                   + $period_summary['total_adjustment_debit'] - $period_summary['total_adjustment_credit'];

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
    .statement-header {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        padding: 2rem;
        border-radius: 10px;
        margin-bottom: 2rem;
    }
    .company-info {
        border-right: 2px solid rgba(255,255,255,0.2);
    }
    .balance-card {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 1rem;
    }
    .balance-card h3 {
        margin: 0;
        font-size: 1.8rem;
        font-weight: 600;
    }
    .balance-card.positive {
        border-left: 4px solid #dc3545;
    }
    .balance-card.negative {
        border-left: 4px solid #28a745;
    }
    .balance-card.warning {
        border-left: 4px solid #ffc107;
    }
    .summary-card {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
    }
    .summary-card .label {
        font-size: 0.85rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .summary-card .value {
        font-size: 1.4rem;
        font-weight: 600;
        margin-top: 0.5rem;
    }
    .transaction-row.invoice {
        background-color: #fff3e0;
    }
    .transaction-row.payment {
        background-color: #e8f5e8;
    }
    .transaction-row.adjustment {
        background-color: #e3f2fd;
    }
    .badge-transaction {
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    .badge-invoice {
        background-color: #ff9800;
        color: white;
    }
    .badge-payment {
        background-color: #4caf50;
        color: white;
    }
    .badge-adjustment {
        background-color: #2196f3;
        color: white;
    }
    .badge-debit {
        background-color: #dc3545;
        color: white;
    }
    .badge-credit {
        background-color: #28a745;
        color: white;
    }
    .amount-positive {
        color: #dc3545;
        font-weight: 600;
    }
    .amount-negative {
        color: #28a745;
        font-weight: 600;
    }
    .running-balance {
        font-weight: 600;
        font-size: 1.1rem;
    }
    .filter-section {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .credit-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    .credit-card.warning {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    }
    .credit-card .value {
        font-size: 2rem;
        font-weight: bold;
    }
    @media print {
        .no-print {
            display: none !important;
        }
        .statement-header {
            background: #f8f9fa !important;
            color: black !important;
        }
        .company-info {
            border-right: 2px solid #dee2e6 !important;
        }
        .balance-card {
            border: 1px solid #dee2e6 !important;
            box-shadow: none !important;
        }
        .credit-card {
            background: #f8f9fa !important;
            color: black !important;
            border: 1px solid #dee2e6;
        }
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
                                <a href="customer_statement_export.php?id=<?= $customer_id ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                                   class="btn btn-outline-success">
                                    <i class="bx bx-download me-1"></i> Export PDF
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
                            <p class="mb-0 opacity-75">GSTIN: <?= htmlspecialchars($_SESSION['business_gst'] ?? 'Not Available') ?></p>
                            <p class="mb-0 opacity-75"><?= htmlspecialchars($_SESSION['business_address'] ?? '') ?></p>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h3 class="mb-1">Customer Statement</h3>
                            <p class="mb-0 opacity-75">
                                Period: <?= date('d M Y', strtotime($from_date)) ?> - <?= date('d M Y', strtotime($to_date)) ?>
                            </p>
                            <p class="mb-0 opacity-75">Generated on: <?= date('d M Y h:i A') ?></p>
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
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bx bx-filter-alt me-1"></i> Generate Statement
                            </button>
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
                                    <p class="mb-0 opacity-75">Credit Limit: ₹<?= number_format($credit_limit, 2) ?></p>
                                    <p class="mb-0 opacity-75">Credit Used: ₹<?= number_format($credit_used, 2) ?></p>
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

                <!-- Statement Table -->
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
                                        <th>Date</th>
                                        <th>Transaction Type</th>
                                        <th>Reference</th>
                                        <th>Description</th>
                                        <th class="text-end">Debit (Purchases)</th>
                                        <th class="text-end">Credit (Payments)</th>
                                        <th class="text-end">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $running_balance = $opening_balance;
                                    $has_transactions = false;
                                    ?>
                                    
                                    <!-- Opening Balance Row -->
                                    <tr class="table-secondary fw-bold">
                                        <td colspan="6" class="text-end">Opening Balance (as on <?= date('d M Y', strtotime($from_date)) ?>):</td>
                                        <td class="text-end <?= $opening_balance > 0 ? 'text-danger' : ($opening_balance < 0 ? 'text-success' : 'text-secondary') ?>">
                                            ₹<?= number_format(abs($opening_balance), 2) ?>
                                            <small class="d-block"><?= $opening_balance > 0 ? 'Dr' : ($opening_balance < 0 ? 'Cr' : '') ?></small>
                                        </td>
                                    </tr>
                                    
                                    <?php foreach ($transactions as $t): 
                                        $has_transactions = true;
                                        $running_balance += $t['debit'] - $t['credit'];
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
                                            <small class="text-muted"><?= date('h:i A', strtotime($t['created_at'])) ?></small>
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
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end amount-negative">
                                            <?php if ($t['credit'] > 0): ?>
                                            ₹<?= number_format($t['credit'], 2) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end running-balance <?= $running_balance > 0 ? 'text-danger' : ($running_balance < 0 ? 'text-success' : 'text-secondary') ?>">
                                            ₹<?= number_format(abs($running_balance), 2) ?>
                                            <small class="d-block"><?= $running_balance > 0 ? 'Dr' : ($running_balance < 0 ? 'Cr' : '') ?></small>
                                         </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (!$has_transactions): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
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
                                    
                                    <!-- Closing Balance Row -->
                                    <tr class="table-primary fw-bold">
                                        <td colspan="6" class="text-end">Closing Balance (as on <?= date('d M Y', strtotime($to_date)) ?>):</td>
                                        <td class="text-end <?= $closing_balance > 0 ? 'text-danger' : ($closing_balance < 0 ? 'text-success' : 'text-secondary') ?>">
                                            ₹<?= number_format(abs($closing_balance), 2) ?>
                                            <small class="d-block"><?= $closing_balance > 0 ? 'Dr' : ($closing_balance < 0 ? 'Cr' : '') ?></small>
                                        </td>
                                    </tr>
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
                                    <span class="badge-adjustment badge-transaction">ADJUSTMENT</span> - Credit/Debit Notes
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
                                    if (strpos($t['description'], 'cash') !== false) $method = 'Cash';
                                    elseif (strpos($t['description'], 'upi') !== false) $method = 'UPI';
                                    elseif (strpos($t['description'], 'bank') !== false) $method = 'Bank Transfer';
                                    elseif (strpos($t['description'], 'cheque') !== false) $method = 'Cheque';
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