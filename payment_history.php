<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
if (!$current_shop_id && $user_role !== 'admin') {
    header('Location: select_shop.php');
    exit();
}

// Since we don't have payment_entries table, we'll create payment records from invoice history
// For now, let's track payments based on invoice payment amounts

// Filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$customer_id_filter = (int)($_GET['customer_id'] ?? 0);
$payment_mode_filter = $_GET['payment_mode'] ?? '';
$search = trim($_GET['search'] ?? '');

// First, let's check what tables exist
$tables_stmt = $pdo->query("SHOW TABLES LIKE 'payment_entries'");
$payment_table_exists = $tables_stmt->rowCount() > 0;

if ($payment_table_exists) {
    // If payment_entries table exists, use it
    $where = "WHERE p.business_id = ? AND DATE(p.created_at) BETWEEN ? AND ?";
    $params = [$business_id, $start_date, $end_date];
    
    if ($user_role !== 'admin') {
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
    
    if ($search) {
        $where .= " AND (p.payment_note LIKE ? OR p.reference_number LIKE ? OR i.invoice_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)";
        $like = "%$search%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    
    // Stats
    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_payments,
            SUM(p.amount) as total_amount,
            SUM(CASE WHEN p.payment_mode = 'cash' THEN p.amount ELSE 0 END) as cash_amount,
            SUM(CASE WHEN p.payment_mode = 'upi' THEN p.amount ELSE 0 END) as upi_amount,
            SUM(CASE WHEN p.payment_mode = 'bank' THEN p.amount ELSE 0 END) as bank_amount,
            SUM(CASE WHEN p.payment_mode = 'cheque' THEN p.amount ELSE 0 END) as cheque_amount
        FROM payment_entries p
        LEFT JOIN invoices i ON p.invoice_id = i.id
        LEFT JOIN customers c ON i.customer_id = c.id
        $where
    ");
    $stats_stmt->execute($params);
    $stats = $stats_stmt->fetch();
    
    // Fetch payment history
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
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
    // This is a temporary solution until you create the payment_entries table
    $where = "WHERE i.business_id = ? AND DATE(i.created_at) BETWEEN ? AND ?";
    $params = [$business_id, $start_date, $end_date];
    
    if ($user_role !== 'admin') {
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
    
    // Since we don't have separate payment records, we'll show invoices as payment records
    // Each invoice shows the amount collected (total - pending)
    $stmt = $pdo->prepare("
        SELECT 
            i.id,
            i.invoice_number,
            i.total as invoice_total,
            i.pending_amount as invoice_pending,
            i.cash_amount,
            i.upi_amount,
            i.bank_amount,
            i.cheque_amount,
            i.created_at,
            i.seller_id as collected_by,
            c.name as customer_name,
            c.phone as customer_phone,
            u.full_name as collector_name,
            s.shop_name,
            i.shop_id
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN users u ON i.seller_id = u.id
        LEFT JOIN shops s ON i.shop_id = s.id
        $where
        ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
    
    // Convert invoices to payment records for display
    $payments = [];
    $total_amount = 0;
    $cash_amount = 0;
    $upi_amount = 0;
    $bank_amount = 0;
    $cheque_amount = 0;
    
    foreach ($invoices as $invoice) {
        $collected = $invoice['invoice_total'] - $invoice['invoice_pending'];
        
        if ($collected > 0) {
            // Create payment records for each payment mode
            $payment_modes = ['cash', 'upi', 'bank', 'cheque'];
            foreach ($payment_modes as $mode) {
                $amount = $invoice[$mode . '_amount'] ?? 0;
                if ($amount > 0) {
                    $payment_id = $invoice['id'] . '_' . $mode;
                    $payments[] = [
                        'id' => $payment_id,
                        'invoice_id' => $invoice['id'],
                        'amount' => $amount,
                        'payment_mode' => $mode,
                        'reference_number' => '',
                        'payment_note' => 'Initial invoice payment',
                        'collected_by' => $invoice['collected_by'],
                        'created_at' => $invoice['created_at'],
                        'invoice_number' => $invoice['invoice_number'],
                        'invoice_total' => $invoice['invoice_total'],
                        'invoice_pending' => $invoice['invoice_pending'],
                        'customer_name' => $invoice['customer_name'],
                        'customer_phone' => $invoice['customer_phone'],
                        'collector_name' => $invoice['collector_name'],
                        'shop_name' => $invoice['shop_name']
                    ];
                    
                    // Update stats
                    $total_amount += $amount;
                    ${$mode . '_amount'} += $amount;
                }
            }
        }
    }
    
    // Create stats array
    $stats = [
        'total_payments' => count($payments),
        'total_amount' => $total_amount,
        'cash_amount' => $cash_amount,
        'upi_amount' => $upi_amount,
        'bank_amount' => $bank_amount,
        'cheque_amount' => $cheque_amount
    ];
}

// Customers for filter
$customers = $pdo->prepare("SELECT id, name, phone FROM customers WHERE business_id = ? ORDER BY name");
$customers->execute([$business_id]);
$customers_list = $customers->fetchAll();

// Collectors for filter
$collectors = $pdo->prepare("SELECT id, full_name FROM users WHERE business_id = ? AND is_active = 1 ORDER BY full_name");
$collectors->execute([$business_id]);
$collectors_list = $collectors->fetchAll();

// Payment modes
$payment_modes = ['cash', 'upi', 'bank', 'cheque'];
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Payment History"; include 'includes/head.php'; ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
        </div>
    </div>
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-history me-2"></i> Payment History
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <?php if (!$payment_table_exists): ?>
                                <div class="alert alert-warning mt-2 mb-0 p-2 d-inline-block">
                                    <small><i class="bx bx-info-circle me-1"></i> Showing invoice-based payments</small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if (!$payment_table_exists): ?>
                                <a href="javascript:void(0)" onclick="showCreateTableModal()" class="btn btn-warning">
                                    <i class="bx bx-database me-1"></i> Setup Payment Table
                                </a>
                                <?php endif; ?>
                                <button class="btn btn-outline-secondary" onclick="exportPayments()">
                                    <i class="bx bx-download me-1"></i> Export
                                </button>
                                <a href="invoices.php" class="btn btn-outline-primary">
                                    <i class="bx bx-receipt me-1"></i> View Invoices
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Payments
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Customer</label>
                                    <select name="customer_id" class="form-select">
                                        <option value="">All Customers</option>
                                        <?php foreach($customers_list as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= $customer_id_filter == $c['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['name']) ?> (<?= $c['phone'] ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Payment Mode</label>
                                    <select name="payment_mode" class="form-select">
                                        <option value="">All Modes</option>
                                        <?php foreach($payment_modes as $mode): ?>
                                        <option value="<?= $mode ?>" <?= $payment_mode_filter == $mode ? 'selected' : '' ?>>
                                            <?= ucfirst($mode) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Invoice / Name / Phone"
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bx bx-filter me-1"></i> Apply
                                        </button>
                                        <?php if ($start_date != date('Y-m-01') || $end_date != date('Y-m-d') || $customer_id_filter || $payment_mode_filter || $search): ?>
                                        <a href="payment_history.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row mb-4 g-3">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Payments</h6>
                                        <h3 class="mb-0 text-primary"><?= number_format($stats['total_payments'] ?? 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-history text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Amount</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($stats['total_amount'] ?? 0, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-success"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Cash</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($stats['cash_amount'] ?? 0, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-money text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Digital</h6>
                                        <h3 class="mb-0 text-info">₹<?= number_format(($stats['upi_amount'] ?? 0) + ($stats['bank_amount'] ?? 0), 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-credit-card text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment History Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="paymentsTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 15%;">Payment Details</th>
                                        <th style="width: 20%;">Invoice & Customer</th>
                                        <th style="width: 15%;">Amount & Mode</th>
                                        <th style="width: 20%;">Collection Info</th>
                                        <th style="width: 15%;">Notes</th>
                                        <th style="width: 15%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($payments)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bx bx-history display-1 text-muted mb-3"></i>
                                                <h5 class="text-muted">No Payment Records Found</h5>
                                                <p class="text-muted">No payments found for the selected filters.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($payments as $payment): 
                                        $mode_class = [
                                            'cash' => 'success',
                                            'upi' => 'primary',
                                            'bank' => 'info',
                                            'cheque' => 'warning'
                                        ][$payment['payment_mode']] ?? 'secondary';
                                        
                                        $invoice_pending = $payment['invoice_pending'] ?? 0;
                                        $invoice_total = $payment['invoice_total'] ?? 0;
                                        $paid_before = $invoice_total - $invoice_pending - $payment['amount'];
                                        $payment_percentage = $invoice_total > 0 ? round(($paid_before + $payment['amount']) / $invoice_total * 100, 0) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong class="d-block mb-1"><?= !$payment_table_exists && strpos($payment['id'], '_') !== false ? 'Invoice Payment' : 'Payment #' . $payment['id'] ?></strong>
                                                <small class="text-muted d-block">
                                                    <i class="bx bx-calendar me-1"></i><?= date('d M Y', strtotime($payment['created_at'])) ?>
                                                    <i class="bx bx-time ms-2 me-1"></i><?= date('h:i A', strtotime($payment['created_at'])) ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($payment['invoice_number']): ?>
                                            <div class="mb-2">
                                                <strong class="text-primary">Invoice #<?= htmlspecialchars($payment['invoice_number']) ?></strong>
                                                <div class="progress mt-2" style="height: 8px;">
                                                    <div class="progress-bar bg-success" role="progressbar" 
                                                         style="width: <?= $payment_percentage ?>%;" 
                                                         title="<?= $payment_percentage ?>% paid">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($payment['customer_name']): ?>
                                            <div>
                                                <strong><?= htmlspecialchars($payment['customer_name']) ?></strong><br>
                                                <small class="text-muted">
                                                    <i class="bx bx-phone me-1"></i><?= htmlspecialchars($payment['customer_phone'] ?? '') ?>
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="mb-2">
                                                <h4 class="text-success mb-0">₹<?= number_format($payment['amount'], 2) ?></h4>
                                                <small class="text-muted">Payment Amount</small>
                                            </div>
                                            <span class="badge bg-<?= $mode_class ?> bg-opacity-10 text-<?= $mode_class ?> px-3 py-2">
                                                <i class="bx bx-<?= $payment['payment_mode'] == 'upi' ? 'qr' : ($payment['payment_mode'] == 'bank' ? 'credit-card' : $payment['payment_mode']) ?> me-1"></i>
                                                <?= ucfirst($payment['payment_mode']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="mb-2">
                                                <strong>Collected By:</strong><br>
                                                <?= htmlspecialchars($payment['collector_name'] ?? 'System') ?>
                                            </div>
                                            <div>
                                                <strong>Shop:</strong><br>
                                                <?= htmlspecialchars($payment['shop_name'] ?? 'N/A') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($payment['payment_note']): ?>
                                            <div class="alert alert-light p-2 mb-2">
                                                <small class="text-muted"><?= htmlspecialchars($payment['payment_note']) ?></small>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($payment['reference_number']): ?>
                                            <div>
                                                <small class="text-muted">
                                                    Ref: <?= htmlspecialchars($payment['reference_number']) ?>
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <?php if (isset($payment['invoice_id']) && $payment['invoice_id']): ?>
                                                <a href="invoice_view.php?invoice_id=<?= $payment['invoice_id'] ?>" 
                                                   class="btn btn-outline-primary" title="View Invoice">
                                                    <i class="bx bx-receipt"></i>
                                                </a>
                                                <?php endif; ?>
                                                <button class="btn btn-outline-secondary" 
                                                        onclick="printPaymentReceipt(<?= isset($payment['invoice_id']) ? $payment['invoice_id'] : 0 ?>, '<?= $payment['payment_mode'] ?>')"
                                                        title="Print Receipt">
                                                    <i class="bx bx-printer"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Setup Table Modal -->
<div class="modal fade" id="setupTableModal" tabindex="-1" aria-labelledby="setupTableModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="setupTableModalLabel">
                    <i class="bx bx-database me-2"></i> Setup Payment Tracking System
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bx bx-info-circle me-2"></i>
                    <strong>Information:</strong> To enable advanced payment tracking, we need to create a dedicated payment table in your database.
                </div>
                
                <h6 class="mt-4 mb-3">Benefits of setting up payment table:</h6>
                <ul>
                    <li>Track individual payment transactions</li>
                    <li>Record payment modes separately</li>
                    <li>Add reference numbers and notes</li>
                    <li>Track who collected each payment</li>
                    <li>Better payment history reports</li>
                </ul>
                
                <div class="mt-4">
                    <h6>SQL to run in your database:</h6>
                    <pre class="bg-light p-3 rounded" style="font-size: 12px;">
CREATE TABLE IF NOT EXISTS `payment_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) DEFAULT NULL,
  `business_id` int(11) NOT NULL,
  `shop_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_mode` enum('cash','upi','bank','cheque') NOT NULL DEFAULT 'cash',
  `reference_number` varchar(100) DEFAULT NULL,
  `payment_note` text DEFAULT NULL,
  `collected_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
                </div>
                
                <div class="alert alert-warning mt-3">
                    <i class="bx bx-alarm-exclamation me-2"></i>
                    <strong>Note:</strong> You need to run this SQL in your database phpMyAdmin or similar tool.
                    After creating the table, refresh this page.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="phpmyadmin" target="_blank" class="btn btn-primary">
                    <i class="bx bx-data me-2"></i> Open phpMyAdmin
                </a>
            </div>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#paymentsTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search payments:",
            lengthMenu: "Show _MENU_",
            info: "Showing _START_ to _END_ of _TOTAL_ payments",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        },
        columnDefs: [
            { 
                targets: [0, 1, 2, 3, 4, 5],
                orderable: false 
            }
        ]
    });

    // Export payments
    window.exportPayments = function() {
        const btn = event.target.closest('button');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
        btn.disabled = true;
        
        const params = new URLSearchParams(window.location.search);
        const exportUrl = 'payment_history_export.php' + (params.toString() ? '?' + params.toString() : '');
        window.location = exportUrl;
        
        setTimeout(() => {
            btn.innerHTML = original;
            btn.disabled = false;
        }, 3000);
    };

    // Show setup table modal
    window.showCreateTableModal = function() {
        const modal = new bootstrap.Modal(document.getElementById('setupTableModal'));
        modal.show();
    };

    // Print payment receipt
    window.printPaymentReceipt = function(invoiceId, paymentMode) {
        if (invoiceId > 0) {
            window.open('invoice_print.php?invoice_id=' + invoiceId, '_blank');
        } else {
            alert('No invoice linked to this payment');
        }
    };

    // Auto-close alerts
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});
</script>

<style>
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}
.empty-state i {
    font-size: 4rem;
    opacity: 0.5;
}
.avatar-sm {
    width: 48px;
    height: 48px;
    flex-shrink: 0;
}
.badge.bg-opacity-10 {
    opacity: 0.9;
}
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
    vertical-align: middle;
}
.btn-group .btn {
    padding: 0.375rem 0.75rem;
}
.card-hover {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.card-hover:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important;
}
.border-start {
    border-left-width: 4px !important;
}
.progress {
    background-color: #e9ecef;
    border-radius: 4px;
}
.progress-bar {
    border-radius: 4px;
}
pre {
    overflow-x: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}
@media (max-width: 768px) {
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .btn-group .btn {
        width: 100%;
    }
}
</style>
</body>
</html>