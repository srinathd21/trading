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

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'];
$manufacturer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$manufacturer_id) {
    header('Location: manufacturers.php');
    exit();
}

// Get date filters
$from_date = $_GET['from_date'] ?? date('Y-m-01'); // First day of current month
$to_date = $_GET['to_date'] ?? date('Y-m-d'); // Today

// Get manufacturer details
$stmt = $pdo->prepare("
    SELECT m.*, 
           s.shop_name,
           s.shop_code,
           (SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE manufacturer_id = m.id) as total_purchases,
           (SELECT COALESCE(SUM(paid_amount), 0) FROM purchases WHERE manufacturer_id = m.id) as total_paid,
           (SELECT COUNT(*) FROM purchases WHERE manufacturer_id = m.id) as purchase_count
    FROM manufacturers m
    LEFT JOIN shops s ON m.shop_id = s.id
    WHERE m.id = ? AND m.business_id = ?
");
$stmt->execute([$manufacturer_id, $business_id]);
$manufacturer = $stmt->fetch();

if (!$manufacturer) {
    $_SESSION['error'] = "Manufacturer not found";
    header('Location: manufacturers.php');
    exit();
}

// Get all transactions for statement
$transactions_sql = "
    SELECT 
        'purchase' as transaction_type,
        p.id as reference_id,
        p.purchase_number as reference_no,
        p.purchase_date as transaction_date,
        p.total_amount as debit,
        0 as credit,
        p.paid_amount as paid_amount,
        p.payment_status,
        p.notes,
        p.created_at,
        'Purchase Order' as description
    FROM purchases p
    WHERE p.manufacturer_id = ? AND p.business_id = ?
    AND DATE(p.purchase_date) BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT 
        'payment' as transaction_type,
        pay.id as reference_id,
        COALESCE(pay.reference_no, '') as reference_no,
        pay.payment_date as transaction_date,
        0 as debit,
        pay.amount as credit,
        NULL as paid_amount,
        'payment' as payment_status,
        CONCAT('Payment - ', pay.notes) as notes,
        pay.created_at,
        CONCAT('Payment (', pay.payment_method, ')') as description
    FROM payments pay
    WHERE pay.type = 'supplier' AND pay.reference_id IN (
        SELECT id FROM purchases WHERE manufacturer_id = ?
    )
    AND DATE(pay.payment_date) BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT 
        'outstanding' as transaction_type,
        osh.id as reference_id,
        COALESCE(osh.reference_no, '') as reference_no,
        osh.date as transaction_date,
        CASE WHEN osh.type IN ('debit', 'payment_made', 'purchase') THEN osh.amount ELSE 0 END as debit,
        CASE WHEN osh.type IN ('credit', 'payment_received', 'purchase_return') THEN osh.amount ELSE 0 END as credit,
        NULL as paid_amount,
        osh.type as payment_status,
        osh.notes,
        osh.created_at,
        CONCAT('Outstanding: ', osh.type) as description
    FROM manufacturer_outstanding_history osh
    WHERE osh.manufacturer_id = ?
    AND DATE(osh.date) BETWEEN ? AND ?
    
    ORDER BY transaction_date ASC, created_at ASC
";

$stmt = $pdo->prepare($transactions_sql);
$stmt->execute([
    $manufacturer_id, $business_id, $from_date, $to_date,
    $manufacturer_id, $from_date, $to_date,
    $manufacturer_id, $from_date, $to_date
]);
$transactions = $stmt->fetchAll();

// Calculate opening balance (all transactions before from_date)
$opening_sql = "
    SELECT 
        COALESCE((
            SELECT SUM(total_amount) FROM purchases 
            WHERE manufacturer_id = ? AND business_id = ? 
            AND DATE(purchase_date) < ?
        ), 0) as total_purchases_before,
        
        COALESCE((
            SELECT SUM(amount) FROM payments 
            WHERE type = 'supplier' AND reference_id IN (
                SELECT id FROM purchases WHERE manufacturer_id = ?
            )
            AND DATE(payment_date) < ?
        ), 0) as total_payments_before,
        
        COALESCE((
            SELECT 
                SUM(CASE 
                    WHEN type IN ('debit', 'payment_made', 'purchase') THEN amount 
                    WHEN type IN ('credit', 'payment_received', 'purchase_return') THEN -amount 
                    ELSE 0 
                END) 
            FROM manufacturer_outstanding_history 
            WHERE manufacturer_id = ? AND DATE(date) < ?
        ), 0) as outstanding_balance_before
";

$stmt = $pdo->prepare($opening_sql);
$stmt->execute([
    $manufacturer_id, $business_id, $from_date,
    $manufacturer_id, $from_date,
    $manufacturer_id, $from_date
]);
$opening = $stmt->fetch();

$opening_balance = $opening['total_purchases_before'] - $opening['total_payments_before'] + $opening['outstanding_balance_before'];

// Get summary for the period
$period_summary = [
    'total_purchases' => 0,
    'total_payments' => 0,
    'total_outstanding_debit' => 0,
    'total_outstanding_credit' => 0
];

foreach ($transactions as $t) {
    if ($t['transaction_type'] == 'purchase') {
        $period_summary['total_purchases'] += $t['debit'];
    } elseif ($t['transaction_type'] == 'payment') {
        $period_summary['total_payments'] += $t['credit'];
    } elseif ($t['transaction_type'] == 'outstanding') {
        $period_summary['total_outstanding_debit'] += $t['debit'];
        $period_summary['total_outstanding_credit'] += $t['credit'];
    }
}

$closing_balance = $opening_balance + $period_summary['total_purchases'] - $period_summary['total_payments'] 
                   + $period_summary['total_outstanding_debit'] - $period_summary['total_outstanding_credit'];
?>
<!DOCTYPE html>
<html lang="en">
<?php $page_title = "Manufacturer Statement - " . htmlspecialchars($manufacturer['name']); ?>
<?php include('includes/head.php'); ?>

<!-- Statement specific styles -->
<style>
    .statement-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
        border-left: 4px solid #28a745;
    }
    .balance-card.negative {
        border-left: 4px solid #dc3545;
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
    .transaction-row.purchase {
        background-color: #fff3e0;
    }
    .transaction-row.payment {
        background-color: #e8f5e8;
    }
    .transaction-row.outstanding {
        background-color: #e3f2fd;
    }
    .badge-transaction {
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    .badge-purchase {
        background-color: #ff9800;
        color: white;
    }
    .badge-payment {
        background-color: #4caf50;
        color: white;
    }
    .badge-outstanding {
        background-color: #2196f3;
        color: white;
    }
    .amount-positive {
        color: #28a745;
        font-weight: 600;
    }
    .amount-negative {
        color: #dc3545;
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
                                <i class="bx bx-file me-2"></i> Manufacturer Statement
                                <small class="text-muted ms-2">
                                    <i class="bx bx-building me-1"></i>
                                    <?= htmlspecialchars($manufacturer['name']) ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2 no-print">
                                <button onclick="window.print()" class="btn btn-outline-primary">
                                    <i class="bx bx-printer me-1"></i> Print
                                </button>
                                <a href="manufacturer_statement_export.php?id=<?= $manufacturer_id ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                                   class="btn btn-outline-success">
                                    <i class="bx bx-download me-1"></i> Export PDF
                                </a>
                                <a href="manufacturers.php" class="btn btn-outline-secondary">
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
                            <h3 class="mb-1">Supplier Statement</h3>
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
                        <input type="hidden" name="id" value="<?= $manufacturer_id ?>">
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

                <!-- Manufacturer Info -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="balance-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h4><?= htmlspecialchars($manufacturer['name']) ?></h4>
                                    <p class="text-muted mb-1">
                                        <i class="bx bx-map me-1"></i> <?= htmlspecialchars($manufacturer['address'] ?: 'No address') ?>
                                    </p>
                                    <p class="text-muted mb-1">
                                        <i class="bx bx-phone me-1"></i> <?= htmlspecialchars($manufacturer['phone'] ?: 'No phone') ?>
                                        <span class="mx-2">|</span>
                                        <i class="bx bx-envelope me-1"></i> <?= htmlspecialchars($manufacturer['email'] ?: 'No email') ?>
                                    </p>
                                    <p class="text-muted mb-0">
                                        <i class="bx bx-barcode me-1"></i> GSTIN: <?= htmlspecialchars($manufacturer['gstin'] ?: 'Not Available') ?>
                                        <?php if ($manufacturer['shop_name']): ?>
                                        <span class="mx-2">|</span>
                                        <i class="bx bx-store me-1"></i> <?= htmlspecialchars($manufacturer['shop_name']) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-<?= $manufacturer['is_active'] ? 'success' : 'secondary' ?> px-3 py-2">
                                        <?= $manufacturer['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="balance-card <?= $closing_balance >= 0 ? 'positive' : 'negative' ?>">
                            <div class="text-center">
                                <small class="text-muted text-uppercase">Current Balance</small>
                                <h3 class="<?= $closing_balance >= 0 ? 'text-success' : 'text-danger' ?>">
                                    ₹<?= number_format(abs($closing_balance), 2) ?>
                                </h3>
                                <small><?= $closing_balance >= 0 ? 'You owe to supplier' : 'Supplier owes to you' ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="label">Opening Balance</div>
                            <div class="value <?= $opening_balance >= 0 ? 'text-warning' : 'text-info' ?>">
                                ₹<?= number_format(abs($opening_balance), 2) ?>
                            </div>
                            <small><?= $opening_balance >= 0 ? 'You owe' : 'They owe' ?></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="label">Total Purchases</div>
                            <div class="value text-primary">
                                ₹<?= number_format($period_summary['total_purchases'], 2) ?>
                            </div>
                            <small><?= count(array_filter($transactions, fn($t) => $t['transaction_type'] == 'purchase')) ?> orders</small>
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
                            <div class="value <?= $closing_balance >= 0 ? 'text-warning' : 'text-info' ?>">
                                ₹<?= number_format(abs($closing_balance), 2) ?>
                            </div>
                            <small><?= $closing_balance >= 0 ? 'Payable' : 'Receivable' ?></small>
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
                                        <td class="text-end <?= $opening_balance >= 0 ? 'text-warning' : 'text-info' ?>">
                                            ₹<?= number_format(abs($opening_balance), 2) ?>
                                            <small class="d-block"><?= $opening_balance >= 0 ? 'Dr' : 'Cr' ?></small>
                                        </td>
                                    </tr>
                                    
                                    <?php foreach ($transactions as $t): 
                                        $has_transactions = true;
                                        $running_balance += $t['debit'] - $t['credit'];
                                        $row_class = '';
                                        $badge_class = '';
                                        
                                        if ($t['transaction_type'] == 'purchase') {
                                            $row_class = 'purchase';
                                            $badge_class = 'badge-purchase';
                                            $type_label = 'PURCHASE';
                                        } elseif ($t['transaction_type'] == 'payment') {
                                            $row_class = 'payment';
                                            $badge_class = 'badge-payment';
                                            $type_label = 'PAYMENT';
                                        } else {
                                            $row_class = 'outstanding';
                                            $badge_class = 'badge-outstanding';
                                            $type_label = strtoupper(str_replace('_', ' ', $t['payment_status']));
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
                                            <?php if ($t['transaction_type'] == 'purchase'): ?>
                                            <br>
                                            <small class="text-muted"><?= ucfirst($t['payment_status']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($t['reference_no'] ?: '—') ?></strong>
                                            <?php if ($t['transaction_type'] == 'purchase'): ?>
                                            <br>
                                            <a href="purchase_view.php?id=<?= $t['reference_id'] ?>" class="small" target="_blank">
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
                                        <td class="text-end amount-negative">
                                            <?php if ($t['debit'] > 0): ?>
                                            ₹<?= number_format($t['debit'], 2) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end amount-positive">
                                            <?php if ($t['credit'] > 0): ?>
                                            ₹<?= number_format($t['credit'], 2) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end running-balance <?= $running_balance >= 0 ? 'text-warning' : 'text-info' ?>">
                                            ₹<?= number_format(abs($running_balance), 2) ?>
                                            <small class="d-block"><?= $running_balance >= 0 ? 'Dr' : 'Cr' ?></small>
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
                                        <td class="text-end <?= $closing_balance >= 0 ? 'text-warning' : 'text-info' ?>">
                                            ₹<?= number_format(abs($closing_balance), 2) ?>
                                            <small class="d-block"><?= $closing_balance >= 0 ? 'Dr' : 'Cr' ?></small>
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
                                    <span class="badge-purchase badge-transaction">PURCHASE</span> - Purchase Orders
                                    <span class="mx-2"></span>
                                    <span class="badge-payment badge-transaction">PAYMENT</span> - Payments Made
                                    <span class="mx-2"></span>
                                    <span class="badge-outstanding badge-transaction">OUTSTANDING</span> - Outstanding Adjustments
                                </small>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    <span class="text-warning">Dr (Debit)</span> - You owe to supplier |
                                    <span class="text-info">Cr (Credit)</span> - Supplier owes to you
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bank Details Card -->
                <?php if ($manufacturer['account_holder_name'] || $manufacturer['bank_name']): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-bank me-2"></i> Bank Details
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <small class="text-muted d-block">Account Holder</small>
                                <strong><?= htmlspecialchars($manufacturer['account_holder_name'] ?: '—') ?></strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted d-block">Bank Name</small>
                                <strong><?= htmlspecialchars($manufacturer['bank_name'] ?: '—') ?></strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted d-block">Account Number</small>
                                <strong><?= htmlspecialchars($manufacturer['account_number'] ?: '—') ?></strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted d-block">IFSC Code</small>
                                <strong><?= htmlspecialchars($manufacturer['ifsc_code'] ?: '—') ?></strong>
                            </div>
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
    .table {
        border: 1px solid #dee2e6 !important;
    }
    .badge-transaction {
        print-color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
    }
    .purchase {
        background-color: #fff3e0 !important;
        print-color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
    }
    .payment {
        background-color: #e8f5e8 !important;
        print-color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
    }
    .outstanding {
        background-color: #e3f2fd !important;
        print-color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
    }
}
</style>

</body>
</html>