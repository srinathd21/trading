<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['current_business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
if (!$current_shop_id && $user_role !== 'admin') {
    header('Location: select_shop.php');
    exit();
}

// ==================== PERMISSION CHECK ====================
$is_admin = ($user_role === 'admin');
$is_shop_manager = in_array($user_role, ['admin', 'shop_manager']);
$is_seller = in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier']);

// Check if user can process returns
$can_process_returns = in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier']);

// ==================== GET DATA ====================
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$customer_id_filter = (int)($_GET['customer_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Build WHERE clause
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

// Stats
$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) as count,
        SUM(i.total) as sales,
        SUM(i.total - i.pending_amount) as collected,
        SUM(i.pending_amount) as pending
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    $where
");
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

// Fetch invoices with customer_id - ORDER BY created_at DESC for newest first
$stmt = $pdo->prepare("
    SELECT i.*, c.name as customer_name, c.phone as customer_phone, c.id as customer_id
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    $where
    ORDER BY i.created_at DESC, i.id DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Customers for filter
$customers = $pdo->prepare("SELECT id, name, phone FROM customers WHERE business_id = ? ORDER BY name");
$customers->execute([$business_id]);
$customers_list = $customers->fetchAll();

// Get return statistics for the period
$return_stats_sql = "SELECT 
                        COUNT(r.id) as total_returns,
                        COALESCE(SUM(r.total_return_amount), 0) as total_return_amount
                     FROM returns r
                     JOIN invoices i ON r.invoice_id = i.id
                     WHERE i.business_id = ? 
                       AND DATE(r.return_date) BETWEEN ? AND ?";
$return_stats_params = [$business_id, $start_date, $end_date];
if ($user_role !== 'admin') {
    $return_stats_sql .= " AND i.shop_id = ?";
    $return_stats_params[] = $current_shop_id;
}

$stmt = $pdo->prepare($return_stats_sql);
$stmt->execute($return_stats_params);
$return_stats = $stmt->fetch();

// Get recent invoices for quick return modal
$recent_invoices_sql = "SELECT 
                        i.id,
                        i.invoice_number,
                        i.total,
                        i.created_at,
                        i.customer_id,
                        c.name as customer_name,
                        c.phone as customer_phone,
                        (SELECT SUM(return_qty) FROM invoice_items WHERE invoice_id = i.id) as already_returned_qty
                      FROM invoices i
                      JOIN customers c ON i.customer_id = c.id
                      WHERE i.business_id = ? 
                        AND i.shop_id = ?
                        AND DATE(i.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        AND i.total > 0
                      ORDER BY i.created_at DESC
                      LIMIT 20";
$stmt = $pdo->prepare($recent_invoices_sql);
$stmt->execute([$business_id, $current_shop_id]);
$recent_invoices = $stmt->fetchAll();

// ==================== GET BUSINESS SETTINGS FOR WHATSAPP ====================
$business_settings = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM business_settings WHERE business_id = ? LIMIT 1");
    $stmt->execute([$business_id]);
    $business_settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
    $business_settings = [];
}

// Default business name if not set
$business_name = $_SESSION['current_business_name'] ?? 'Our Store';
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Invoices"; include 'includes/head.php'; ?>
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
                                    <i class="bx bx-receipt me-2"></i> Invoices
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <?php if (($return_stats['total_return_amount'] ?? 0) > 0): ?>
                                <small class="text-danger">
                                    <i class="bx bx-undo me-1"></i>
                                    Returns in period: ₹<?= number_format($return_stats['total_return_amount'], 2) ?> (<?= $return_stats['total_returns'] ?? 0 ?> returns)
                                </small>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($can_process_returns): ?>
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#quickReturnModal">
                                    <i class="bx bx-undo me-1"></i> Quick Return
                                </button>
                                <?php endif; ?>
                                <a href="pos.php" class="btn btn-primary">
                                    <i class="bx bx-plus-circle me-1"></i> New Invoice
                                </a>
                                <a href="return_management.php" class="btn btn-info">
                                    <i class="bx bx-refresh me-1"></i> Return Management
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
                
                <!-- WhatsApp Success/Error Messages -->
                <?php if (isset($_SESSION['whatsapp_success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bxl-whatsapp me-2"></i> <?= htmlspecialchars($_SESSION['whatsapp_success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['whatsapp_success']); endif; ?>
                <?php if (isset($_SESSION['whatsapp_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i> <?= htmlspecialchars($_SESSION['whatsapp_error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['whatsapp_error']); endif; ?>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Invoices
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                                </div>
                                <div class="col-lg-3 col-md-6">
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
                                <div class="col-lg-1 col-md-12">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bx bx-filter me-1"></i> Apply
                                        </button>
                                        <?php if ($start_date != date('Y-m-01') || $end_date != date('Y-m-d') || $customer_id_filter || $search): ?>
                                        <a href="invoices.php" class="btn btn-outline-secondary">
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
                                        <h6 class="text-muted mb-1">Total Sales</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($stats['sales'] ?? 0, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Collected</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($stats['collected'] ?? 0, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-check-circle text-success"></i>
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
                                        <h6 class="text-muted mb-1">Pending</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($stats['pending'] ?? 0, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-error text-warning"></i>
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
                                        <h6 class="text-muted mb-1">Total Invoices</h6>
                                        <h3 class="mb-0 text-info"><?= number_format($stats['count'] ?? 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-receipt text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoices Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="invoicesTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;">Invoice Details</th>
                                        <th class="text-center" style="width: 20%;">Customer</th>
                                        <th class="text-center" style="width: 10%;">Items</th>
                                        <th class="text-end" style="width: 25%;">Amount Details</th>
                                        <th class="text-center" style="width: 20%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($invoices)): ?>
                                    
                                    <?php else: ?>
                                    <?php 
                                    foreach ($invoices as $i => $inv):
                                        $total = (float)($inv['total'] ?? 0);
                                        $pending = (float)($inv['pending_amount'] ?? 0);
                                        $paid = $total - $pending;
                                        $item_count = 0;
                                        $returned_item_count = 0;
                                        
                                        try {
                                            // Get total items count
                                            $item_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoice_items WHERE invoice_id = ?");
                                            $item_stmt->execute([$inv['id']]);
                                            $item_count = (int)$item_stmt->fetchColumn();
                                            
                                            // Get returned items count
                                            $return_stmt = $pdo->prepare("SELECT SUM(return_qty) as total_returned FROM invoice_items WHERE invoice_id = ?");
                                            $return_stmt->execute([$inv['id']]);
                                            $return_data = $return_stmt->fetch();
                                            $returned_item_count = (int)($return_data['total_returned'] ?? 0);
                                        } catch (Exception $e) {
                                            $item_count = 0;
                                            $returned_item_count = 0;
                                        }
                                        
                                        $payment_status = $pending == 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                                        $status_class = [
                                            'paid' => 'success',
                                            'partial' => 'warning',
                                            'unpaid' => 'danger'
                                        ][$payment_status] ?? 'secondary';
                                        
                                        // Check if invoice has returns
                                        $has_returns = $returned_item_count > 0;
                                        
                                        // Check if customer has phone number for WhatsApp
                                        $has_whatsapp = !empty($inv['customer_phone']);
                                    ?>
                                    <tr class="invoice-row" data-id="<?= $inv['id'] ?>" data-customer-id="<?= $inv['customer_id'] ?? 0 ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3 flex-shrink-0">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx bx-receipt fs-4"></i>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <strong class="d-block mb-1 text-primary"><?= htmlspecialchars($inv['invoice_number']) ?></strong>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-calendar me-1"></i><?= date('d M Y', strtotime($inv['created_at'])) ?>
                                                        <i class="bx bx-time ms-2 me-1"></i><?= date('h:i A', strtotime($inv['created_at'])) ?>
                                                    </small>
                                                    <div class="d-flex gap-2 mt-2">
                                                        <span class="badge bg-<?= $status_class ?> bg-opacity-10 text-<?= $status_class ?> px-3 py-1 d-inline-block">
                                                            <i class="bx bx-<?= $status_class == 'success' ? 'check-circle' : ($status_class == 'warning' ? 'time-five' : 'x-circle') ?> me-1"></i>
                                                            <?= ucfirst($payment_status) ?>
                                                        </span>
                                                        <?php if ($has_returns): ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-1 d-inline-block">
                                                            <i class="bx bx-undo me-1"></i> <?= $returned_item_count ?> returned
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div>
                                                <strong class="d-block mb-1"><?= htmlspecialchars($inv['customer_name'] ?? 'Walk-in Customer') ?></strong>
                                                <?php if ($inv['customer_phone']): ?>
                                                <small class="text-muted">
                                                    <i class="bx bx-phone me-1"></i><?= htmlspecialchars($inv['customer_phone']) ?>
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3 py-2 fs-6">
                                                <i class="bx bx-package me-1"></i> <?= $item_count ?>
                                                <small class="ms-1">items</small>
                                            </span>
                                            <?php if ($has_returns): ?>
                                            <br>
                                            <small class="text-danger mt-1 d-block">
                                                <i class="bx bx-undo"></i> <?= $returned_item_count ?> returned
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="mb-2">
                                                <strong class="text-primary fs-5">₹<?= number_format($total, 2) ?></strong>
                                                <small class="text-muted d-block">Total</small>
                                            </div>
                                            <div class="d-flex justify-content-end gap-3 mb-1">
                                                <div class="text-end">
                                                    <span class="text-success fw-bold">₹<?= number_format($paid, 2) ?></span>
                                                    <small class="text-muted d-block">Paid</small>
                                                </div>
                                                <?php if ($pending > 0): ?>
                                                <div class="text-end">
                                                    <span class="text-danger fw-bold">₹<?= number_format($pending, 2) ?></span>
                                                    <small class="text-muted d-block">Due</small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
    <div class="btn-group-vertical btn-group-sm" style="width: 100%;" role="group">
        <div class="btn-group btn-group-sm mb-1" role="group">
            <a href="invoice_print.php?invoice_id=<?= $inv['id'] ?>" target="_blank"
               class="btn btn-outline-success btn-sm" title="Print Invoice">
                <i class="bx bx-printer"></i>
            </a>
            <a href="invoice_view.php?invoice_id=<?= $inv['id'] ?>"
               class="btn btn-outline-primary btn-sm" title="View Details">
                <i class="bx bx-show"></i>
            </a>
            <?php if ($pending > 0): ?>
            <a href="collect_payment.php?invoice_id=<?= $inv['id'] ?>"
               class="btn btn-outline-warning btn-sm" title="Collect Payment">
                <i class="bx bx-money"></i>
            </a>
            <?php endif; ?>
            <a href="payment_history.php?invoice_id=<?= $inv['id'] ?>"
               class="btn btn-outline-info btn-sm" title="Payment History">
               <i class="bx bx-history"></i>
            </a>
        </div>
        <div class="btn-group btn-group-sm" role="group">
            <?php if ($has_whatsapp): ?>
            <button type="button" 
                    class="btn btn-outline-success btn-sm whatsapp-btn"
                    data-invoice-id="<?= $inv['id'] ?>"
                    data-invoice-number="<?= htmlspecialchars($inv['invoice_number']) ?>"
                    data-customer-name="<?= htmlspecialchars($inv['customer_name'] ?? 'Customer') ?>"
                    data-customer-phone="<?= htmlspecialchars($inv['customer_phone']) ?>"
                    data-total="<?= $total ?>"
                    title="Send Invoice via WhatsApp">
                <i class="bx bxl-whatsapp"></i>
            </button>
            <?php else: ?>
            <button type="button" 
                    class="btn btn-outline-secondary btn-sm" 
                    disabled
                    title="Customer phone number not available">
                <i class="bx bxl-whatsapp"></i>
            </button>
            <?php endif; ?>
            
            <?php if ($item_count > 0 && $can_process_returns): ?>
            <button class="btn btn-outline-danger btn-sm return-btn"
                    data-invoice-id="<?= $inv['id'] ?>"
                    data-customer-id="<?= $inv['customer_id'] ?? 0 ?>"
                    data-invoice-number="<?= htmlspecialchars($inv['invoice_number']) ?>"
                    title="Return Items">
                <i class="bx bx-undo"></i>
            </button>
            <?php endif; ?>
            
            <?php if (in_array($user_role, ['admin', 'shop_manager']) && !$has_returns): ?>
            <button type="button" 
                    class="btn btn-outline-danger btn-sm delete-invoice-btn" 
                    title="Delete Invoice" 
                    data-invoice-id="<?= $inv['id'] ?>" 
                    data-invoice-number="<?= htmlspecialchars($inv['invoice_number']) ?>"
                    data-total="₹<?= number_format($total, 2) ?>"
                    data-has-returns="<?= $has_returns ? 'true' : 'false' ?>">
                <i class="bx bx-trash"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($has_returns): ?>
    <div class="mt-2">
        <a href="return_management.php?search_invoice=<?= urlencode($inv['invoice_number']) ?>" 
           class="btn btn-sm btn-outline-danger w-100" title="View Returns">
            <i class="bx bx-refresh me-1"></i> View Returns
        </a>
    </div>
    <?php endif; ?>
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

<!-- Quick Return Modal -->
<?php if ($can_process_returns): ?>
<div class="modal fade" id="quickReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bx bx-undo me-2"></i> Quick Return</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="process_return.php" id="quickReturnForm">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Select Invoice</label>
                            <select name="invoice_id" id="quickInvoiceSelect" class="form-select" required>
                                <option value="">-- Select Invoice --</option>
                                <?php foreach ($recent_invoices as $invoice): ?>
                                <option value="<?= $invoice['id'] ?>">
                                    <?= htmlspecialchars($invoice['invoice_number']) ?> - 
                                    <?= htmlspecialchars($invoice['customer_name']) ?> - 
                                    ₹<?= number_format($invoice['total'], 2) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer ID</label>
                            <input type="hidden" name="customer_id" id="quickCustomerId">
                            <input type="text" id="quickCustomerDisplay" class="form-control" readonly>
                        </div>
                        
                        <!-- Return Items Section -->
                        <div class="col-12" id="quickReturnItemsSection" style="display: none;">
                            <hr>
                            <div class="table-responsive">
                                <table class="table table-sm" id="quickReturnItemsTable">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th class="text-center">Sold Qty</th>
                                            <th class="text-center">Already Returned</th>
                                            <th class="text-center">Available</th>
                                            <th class="text-center">Return Qty</th>
                                            <th class="text-end">Unit Price</th>
                                        </tr>
                                    </thead>
                                    <tbody id="quickItemsTableBody">
                                        <!-- Items loaded via AJAX -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Return Details -->
                        <div class="col-md-6">
                            <label class="form-label">Return Reason <span class="text-danger">*</span></label>
                            <select name="return_reason" class="form-select" required>
                                <option value="">-- Select Reason --</option>
                                <option value="defective">Defective Product</option>
                                <option value="wrong_item">Wrong Item Delivered</option>
                                <option value="not_needed">Not Needed Anymore</option>
                                <option value="size_issue">Size/Fit Issue</option>
                                <option value="damaged">Damaged in Transit</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Return Date</label>
                            <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea name="return_notes" class="form-control" rows="2" placeholder="Any additional notes..."></textarea>
                        </div>
                        
                        <div class="col-md-12">
                            <div class="form-check">
                                <input type="checkbox" name="refund_to_cash" id="refund_to_cash" class="form-check-input" value="1">
                                <label class="form-check-label text-success" for="refund_to_cash">
                                    <i class="bx bx-money me-1"></i> Refund as cash payment
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="quickReturnSubmitBtn" disabled>
                        <i class="bx bx-check me-1"></i> Process Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Return Items Modal (from invoice row) -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-labelledby="returnModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="returnModalLabel">
                    <i class="bx bx-refresh me-2"></i>
                    <span id="returnModalTitle">Return Items</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" action="process_return.php" id="returnForm">
                <input type="hidden" name="invoice_id" id="returnInvoiceId">
                <input type="hidden" name="customer_id" id="returnCustomerId">
                
                <div class="modal-body p-0">
                    <div id="returnModalBody">
                        <div class="text-center py-5 text-muted">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-3">Loading items for return...</p>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bx bx-x me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger" id="processReturnBtn" disabled>
                        <i class="bx bx-check me-2"></i> Process Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- WhatsApp Send Modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1" aria-labelledby="whatsappModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="whatsappModalLabel">
                    <i class="bx bxl-whatsapp me-2"></i> Send Invoice via WhatsApp
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="send_invoice_whatsapp.php" id="whatsappForm">
                <div class="modal-body">
                    <input type="hidden" name="invoice_id" id="whatsappInvoiceId">
                    <input type="hidden" name="customer_phone" id="whatsappCustomerPhone">
                    
                    <div class="text-center mb-4">
                        <div class="avatar-lg mx-auto mb-3">
                            <div class="avatar-title bg-success bg-opacity-10 rounded-circle fs-1">
                                <i class="bx bxl-whatsapp text-success"></i>
                            </div>
                        </div>
                        <h5 id="whatsappCustomerNameDisplay"></h5>
                        <p class="text-muted" id="whatsappInvoiceNumberDisplay"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Recipient Phone Number</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bx bx-phone"></i></span>
                            <input type="text" class="form-control" id="whatsappPhoneDisplay" readonly>
                        </div>
                        <small class="text-muted">WhatsApp will open with this number</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Message Template</label>
                        <div class="bg-light p-3 rounded">
                            <p id="whatsappMessagePreview" class="mb-0 small"></p>
                        </div>
                        <small class="text-muted">You can edit the message before sending</small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bx bx-info-circle me-1"></i>
                        The invoice link will be sent. Customer can view and download the invoice without login.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bx bx-x me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success" id="sendWhatsappBtn">
                        <i class="bx bxl-whatsapp me-1"></i> Open WhatsApp
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Delete Invoice Confirmation Modal -->
<div class="modal fade" id="deleteInvoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bx bx-trash me-2"></i> Delete Invoice
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="deleteInvoiceId">
                <input type="hidden" id="deleteInvoiceNumber">
                <input type="hidden" id="deleteInvoiceTotal">
                
                <div class="text-center mb-4">
                    <div class="avatar-lg mx-auto mb-3">
                        <div class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-1">
                            <i class="bx bx-error-circle text-danger"></i>
                        </div>
                    </div>
                    <h5 class="mb-2">Are you sure?</h5>
                    <p class="text-muted mb-1">
                        You are about to delete invoice <strong id="modalInvoiceNumber"></strong>
                    </p>
                    <p class="text-muted">
                        Total Amount: <strong id="modalInvoiceTotal"></strong>
                    </p>
                </div>
                
                <div class="alert alert-warning">
                    <i class="bx bx-info-circle me-2"></i>
                    <strong>Warning:</strong> This action will:
                    <ul class="mb-0 mt-2">
                        <li>Delete the invoice permanently</li>
                        <li>Restock all items with exact quantities</li>
                        <li>Remove all payment records</li>
                    </ul>
                </div>
                
                <div class="alert alert-info">
                    <i class="bx bx-time me-2"></i>
                    This action cannot be undone. Please confirm.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bx bx-x me-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="bx bx-trash me-1"></i> Yes, Delete Invoice
                </button>
            </div>
        </div>
    </div>
</div>
<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>

<script>
$(document).ready(function() {
    // Initialize DataTable
    var invoicesTable = $('#invoicesTable').DataTable({
        responsive: true,
        pageLength: 25,
        ordering: false,
        columnDefs: [
            { 
                targets: '_all', 
                orderable: false 
            }
        ],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search invoices:",
            lengthMenu: "Show _MENU_",
            info: "Showing _START_ to _END_ of _TOTAL_ invoices",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    $('[data-bs-toggle="tooltip"]').tooltip();

    // ==================== WHATSAPP BUTTON HANDLER ====================
    $('#invoicesTable tbody').on('click', '.whatsapp-btn', function(e) {
        e.preventDefault();
        
        // Get data from button
        const invoiceId = $(this).data('invoice-id');
        const invoiceNumber = $(this).data('invoice-number');
        const customerName = $(this).data('customer-name');
        const customerPhone = $(this).data('customer-phone');
        const total = $(this).data('total');
        
        // Set values in modal
        $('#whatsappInvoiceId').val(invoiceId);
        $('#whatsappCustomerPhone').val(customerPhone);
        $('#whatsappCustomerNameDisplay').text(customerName);
        $('#whatsappInvoiceNumberDisplay').text('Invoice #' + invoiceNumber);
        $('#whatsappPhoneDisplay').val(customerPhone);
        
        // Show loading state
        $('#whatsappMessagePreview').text('Generating invoice link...');
        $('#sendWhatsappBtn').prop('disabled', true);
        
        // Get or generate token from server
        $.ajax({
            url: 'get_invoice_token.php',
            method: 'GET',
            data: { invoice_id: invoiceId },
            success: function(response) {
                if (response.token) {
                    // Generate invoice URL with proper token
                    const baseUrl = window.location.origin + window.location.pathname.replace('invoices.php', '');
                    const invoiceUrl = baseUrl + 'public_invoice.php?id=' + invoiceId + '&token=' + response.token;
                    
                    // Format total amount
                    const formattedTotal = new Intl.NumberFormat('en-IN', {
                        style: 'currency',
                        currency: 'INR',
                        minimumFractionDigits: 2
                    }).format(total);
                    
                    // Create message preview
                    const businessName = '<?= htmlspecialchars($business_name) ?>';
                    const date = new Date().toLocaleDateString('en-IN', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric'
                    });
                    
                    const message = `Dear ${customerName},\n\nThank you for your purchase from ${businessName}!\n\nInvoice Details:\nInvoice No: ${invoiceNumber}\nDate: ${date}\nTotal Amount: ${formattedTotal}\n\nYou can view and download your invoice here:\n${invoiceUrl}\n\nFor any queries, please contact us.\n\nThank you for your business!`;
                    
                    $('#whatsappMessagePreview').text(message);
                    
                    // Store message for form submission
                    $('#whatsappForm').data('message', message);
                    $('#whatsappForm').data('token', response.token);
                    
                    // Enable send button
                    $('#sendWhatsappBtn').prop('disabled', false);
                } else {
                    $('#whatsappMessagePreview').text('Error: Could not generate invoice link. Please try again.');
                    $('#sendWhatsappBtn').prop('disabled', true);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error getting token:', error);
                $('#whatsappMessagePreview').text('Error generating invoice link. Please try again.');
                $('#sendWhatsappBtn').prop('disabled', true);
            }
        });
        
        // Show modal
        const whatsappModal = new bootstrap.Modal(document.getElementById('whatsappModal'));
        whatsappModal.show();
    });
    
    // Handle WhatsApp form submission
    $('#whatsappForm').submit(function(e) {
        e.preventDefault();
        
        const invoiceId = $('#whatsappInvoiceId').val();
        const customerPhone = $('#whatsappCustomerPhone').val();
        const message = $(this).data('message');
        const token = $(this).data('token');
        
        // Clean phone number - remove non-digits
        let cleanPhone = customerPhone.replace(/\D/g, '');
        
        // Ensure phone has country code (assume India +91 if not present)
        if (cleanPhone.length === 10) {
            cleanPhone = '91' + cleanPhone;
        } else if (cleanPhone.length === 11 && cleanPhone.startsWith('0')) {
            cleanPhone = '91' + cleanPhone.substring(1);
        }
        
        // Encode message for URL
        const encodedMessage = encodeURIComponent(message);
        
        // Create WhatsApp URL
        const whatsappUrl = `https://wa.me/${cleanPhone}?text=${encodedMessage}`;
        
        // Open WhatsApp in new tab
        window.open(whatsappUrl, '_blank');
        
        // Close modal
        bootstrap.Modal.getInstance(document.getElementById('whatsappModal')).hide();
        
        // Log the send action via AJAX
        $.ajax({
            url: 'log_whatsapp_send.php',
            method: 'POST',
            data: {
                invoice_id: invoiceId,
                phone: customerPhone,
                status: 'sent',
                token: token
            },
            success: function(response) {
                console.log('WhatsApp send logged');
            },
            error: function(xhr, status, error) {
                console.log('Error logging WhatsApp send:', error);
            }
        });
    });

    // Reset WhatsApp modal when closed
    $('#whatsappModal').on('hidden.bs.modal', function() {
        $('#sendWhatsappBtn').prop('disabled', false);
        $('#whatsappMessagePreview').text('');
        $('#whatsappForm').data('message', '');
        $('#whatsappForm').data('token', '');
    });

    // ==================== QUICK RETURN MODAL ====================
    <?php if ($can_process_returns): ?>
    // Load invoice items in quick return modal
    $('#quickInvoiceSelect').change(function() {
        const invoiceId = $(this).val();
        if (!invoiceId) {
            $('#quickReturnItemsSection').hide();
            $('#quickReturnSubmitBtn').prop('disabled', true);
            return;
        }
        
        // Get customer info for selected invoice
        $.ajax({
            url: 'ajax/get_invoice_customer.php',
            method: 'GET',
            data: { invoice_id: invoiceId },
            success: function(response) {
                if (response.customer_id) {
                    $('#quickCustomerId').val(response.customer_id);
                    $('#quickCustomerDisplay').val(response.customer_name + ' (' + response.customer_phone + ')');
                }
            }
        });
        
        // Load items for return
        $.ajax({
            url: 'get_invoice_items.php',
            method: 'GET',
            data: { 
                invoice_id: invoiceId,
                for_return: 1
            },
            beforeSend: function() {
                $('#quickItemsTableBody').html('<tr><td colspan="6" class="text-center">Loading items...</td></tr>');
                $('#quickReturnItemsSection').show();
            },
            success: function(response) {
                $('#quickItemsTableBody').html(response);
                $('#quickReturnSubmitBtn').prop('disabled', false);
                
                // Initialize quantity validation
                initializeQuickReturnQuantities();
            },
            error: function() {
                $('#quickItemsTableBody').html('<tr><td colspan="6" class="text-center text-danger">Error loading items</td></tr>');
                $('#quickReturnSubmitBtn').prop('disabled', true);
            }
        });
    });
    
    function initializeQuickReturnQuantities() {
        $('.return-qty-input').on('input', function() {
            validateQuickReturnForm();
        });
        
        $('select[name="return_reason"]').change(function() {
            validateQuickReturnForm();
        });
    }
    // ==================== DELETE INVOICE FUNCTIONALITY ====================
// Toast notification function
function showToast(message, type = 'success') {
    const toastId = 'toast-' + Date.now();
    const icon = type === 'success' ? 'bx-check-circle' : (type === 'error' ? 'bx-error-circle' : 'bx-info-circle');
    const title = type === 'success' ? 'Success' : (type === 'error' ? 'Error' : 'Info');
    
    // Remove existing toasts if any
    if ($('.toast-container').length === 0) {
        $('body').append('<div class="toast-container"></div>');
    }
    
    const toastHtml = `
        <div id="${toastId}" class="custom-toast toast-${type}">
            <div class="toast-header">
                <div>
                    <i class="bx ${icon} me-2" style="color: ${type === 'success' ? '#28a745' : '#dc3545'}"></i>
                    <strong>${title}</strong>
                </div>
                <button type="button" class="toast-close" onclick="$(this).closest('.custom-toast').remove()">&times;</button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `;
    
    $('.toast-container').append(toastHtml);
    
    // Auto remove after 5 seconds
    setTimeout(function() {
        $('#' + toastId).fadeOut(300, function() {
            $(this).remove();
            if ($('.toast-container').children().length === 0) {
                $('.toast-container').remove();
            }
        });
    }, 5000);
}

// Handle delete button click
$(document).on('click', '.delete-invoice-btn:not(:disabled)', function(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const invoiceId = $(this).data('invoice-id');
    const invoiceNumber = $(this).data('invoice-number');
    const total = $(this).data('total');
    const hasReturns = $(this).data('has-returns') === 'true';
    
    if (hasReturns) {
        showToast('Cannot delete invoice with existing returns', 'error');
        return;
    }
    
    // Show confirmation modal with details
    $('#deleteInvoiceId').val(invoiceId);
    $('#deleteInvoiceNumber').val(invoiceNumber);
    $('#deleteInvoiceTotal').val(total);
    $('#modalInvoiceNumber').text(invoiceNumber);
    $('#modalInvoiceTotal').text(total);
    
    // Show the modal
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteInvoiceModal'));
    deleteModal.show();
});

// Handle delete confirmation
$('#confirmDeleteBtn').click(function() {
    const invoiceId = $('#deleteInvoiceId').val();
    const btn = $(this);
    const originalText = btn.html();
    
    btn.html('<i class="bx bx-loader bx-spin me-2"></i> Deleting...');
    btn.prop('disabled', true);
    
    $.ajax({
        url: 'delete_invoice.php',
        method: 'POST',
        data: { invoice_id: invoiceId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Hide modal
                bootstrap.Modal.getInstance(document.getElementById('deleteInvoiceModal')).hide();
                
                // Show success toast
                showToast(response.message, 'success');
                
                // Remove the row from table
                $(`tr[data-id="${invoiceId}"]`).fadeOut(500, function() {
                    $(this).remove();
                    
                    // Update DataTable if using it
                    if (typeof invoicesTable !== 'undefined') {
                        invoicesTable.row($(this)).remove().draw();
                    }
                    
                    // Update stats if needed (optional)
                    updateStatsAfterDelete();
                });
            } else {
                // Show error toast
                showToast(response.message, 'error');
                btn.html(originalText);
                btn.prop('disabled', false);
            }
        },
        error: function(xhr, status, error) {
            console.error('Delete error:', error);
            let errorMessage = 'Failed to delete invoice. Please try again.';
            
            // Try to parse error response
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.message) {
                    errorMessage = response.message;
                }
            } catch(e) {
                // If response is not JSON, use default message
            }
            
            showToast(errorMessage, 'error');
            btn.html(originalText);
            btn.prop('disabled', false);
        }
    });
});

// Reset modal on close
$('#deleteInvoiceModal').on('hidden.bs.modal', function() {
    $('#confirmDeleteBtn').html('<i class="bx bx-trash me-1"></i> Yes, Delete Invoice');
    $('#confirmDeleteBtn').prop('disabled', false);
});

// Optional: Update stats after delete
function updateStatsAfterDelete() {
    $.ajax({
        url: 'get_invoice_stats.php',
        method: 'GET',
        data: {
            start_date: $('input[name="start_date"]').val(),
            end_date: $('input[name="end_date"]').val(),
            customer_id: $('select[name="customer_id"]').val()
        },
        success: function(response) {
            if (response) {
                // Update stats cards if you want to refresh them
                // You'll need to parse and update the stats values
            }
        }
    });
}
    function validateQuickReturnForm() {
        let hasReturnItems = false;
        $('.return-qty-input').each(function() {
            if (parseInt($(this).val()) > 0) {
                hasReturnItems = true;
                return false;
            }
        });
        
        const hasReason = $('select[name="return_reason"]').val() !== '';
        $('#quickReturnSubmitBtn').prop('disabled', !(hasReturnItems && hasReason));
    }
    
   // Quick return form submission - REMOVE AJAX, USE NORMAL SUBMIT
$('#quickReturnForm').submit(function(e) {
    e.preventDefault();
    
    // Validate
    let totalQty = 0;
    $('.return-qty-input').each(function() {
        totalQty += parseInt($(this).val()) || 0;
    });
    
    if (totalQty === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Items Selected',
            text: 'Please select at least one item to return.'
        });
        return false;
    }
    
    if (!$('select[name="return_reason"]').val()) {
        Swal.fire({
            icon: 'warning',
            title: 'Return Reason Required',
            text: 'Please select a return reason.'
        });
        return false;
    }
    
    // Confirm return with SweetAlert
    Swal.fire({
        title: 'Process Return?',
        html: `<p>You are about to return <strong>${totalQty}</strong> item(s).</p>
               <p class="text-danger">This action cannot be undone!</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, process return',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading
            Swal.fire({
                title: 'Processing...',
                html: 'Please wait while we process the return.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Submit the form normally - it will redirect
            $('#quickReturnForm')[0].submit();
        }
    });
    
    return false;
});
    <?php endif; ?>
    
    // ==================== INVOICE ROW RETURN MODAL ====================
    $('#invoicesTable tbody').on('click', '.return-btn', function(e) {
        e.preventDefault();
        
        // Get data from the button
        const invoiceId = $(this).data('invoice-id');
        const customerId = $(this).data('customer-id');
        const invoiceNumber = $(this).data('invoice-number');
        
        console.log('Return button clicked:', invoiceId, customerId, invoiceNumber);
        
        if (!invoiceId) {
            alert('Invalid invoice ID');
            return;
        }
        
        $('#returnInvoiceId').val(invoiceId);
        $('#returnCustomerId').val(customerId);
        $('#returnModalTitle').text(`Return Items - Invoice #${invoiceNumber}`);
        
        // Show modal with loading state
        const returnModal = new bootstrap.Modal(document.getElementById('returnModal'));
        returnModal.show();
        
        // Load return items via AJAX
        $.ajax({
            url: 'get_invoice_items.php',
            method: 'GET',
            data: { 
                invoice_id: invoiceId,
                for_return: 1
            },
            beforeSend: function() {
                $('#returnModalBody').html(`
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-3">Loading items for return...</p>
                    </div>
                `);
                $('#processReturnBtn').prop('disabled', true);
            },
            success: function(response) {
                $('#returnModalBody').html(response);
                $('#processReturnBtn').prop('disabled', false);
                
                // Initialize validation for the loaded content
                initializeReturnFormValidation();
            },
            error: function(xhr, status, error) {
                console.error('Error loading items:', error);
                $('#returnModalBody').html(`
                    <div class="alert alert-danger m-4">
                        <i class="bx bx-error-circle me-2"></i>
                        Failed to load items. Please try again.<br>
                        <small>Error: ${error}</small>
                    </div>
                `);
            }
        });
    });
    
    function initializeReturnFormValidation() {
        // Clear previous event handlers
        $('.return-qty-input').off('input');
        $('select[name="return_reason"]').off('change');
        
        // Update total when return quantities change
        $(document).on('input', '.return-qty-input', function() {
            validateReturnForm();
        });
        
        // Return reason validation
        $(document).on('change', 'select[name="return_reason"]', function() {
            validateReturnForm();
        });
        
        // Initial validation
        validateReturnForm();
    }
    
    function validateReturnForm() {
        let hasReturnItems = false;
        $('.return-qty-input:not(:disabled)').each(function() {
            const qty = parseInt($(this).val()) || 0;
            const maxQty = parseInt($(this).data('max')) || 0;
            
            // Validate quantity doesn't exceed max
            if (qty > maxQty) {
                $(this).val(maxQty);
            }
            
            if (qty > 0) {
                hasReturnItems = true;
            }
        });
        
        const hasReason = $('select[name="return_reason"]').val() !== '';
        $('#processReturnBtn').prop('disabled', !(hasReturnItems && hasReason));
    }
    
// Handle return form submission - REMOVE AJAX, USE NORMAL SUBMIT
$('#returnForm').submit(function(e) {
    e.preventDefault();
    
    // Validate at least one item has return quantity > 0
    let hasReturnItems = false;
    let totalQty = 0;
    
    $('.return-qty-input:not(:disabled)').each(function() {
        const qty = parseInt($(this).val()) || 0;
        totalQty += qty;
        if (qty > 0) {
            hasReturnItems = true;
        }
    });
    
    if (!hasReturnItems) {
        Swal.fire({
            icon: 'warning',
            title: 'No Items Selected',
            text: 'Please enter return quantity for at least one item.'
        });
        return false;
    }
    
    // Validate return reason
    const returnReason = $('select[name="return_reason"]').val();
    if (!returnReason) {
        Swal.fire({
            icon: 'warning',
            title: 'Return Reason Required',
            text: 'Please select a return reason.'
        });
        return false;
    }
    
    // Confirm return with SweetAlert
    Swal.fire({
        title: 'Process Return?',
        html: `<p>You are about to return <strong>${totalQty}</strong> item(s).</p>
               <p class="text-danger">This action cannot be undone!</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, process return',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading
            Swal.fire({
                title: 'Processing...',
                html: 'Please wait while we process the return.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Submit the form normally - it will redirect
            $('#returnForm')[0].submit();
        }
    });
    
    return false;
});
    
    // Reset form when modal is closed
    $('#returnModal').on('hidden.bs.modal', function() {
        $('#returnForm')[0].reset();
        $('#processReturnBtn').prop('disabled', true);
        $('#processReturnBtn').html('<i class="bx bx-check me-2"></i> Process Return');
        $('#returnModalBody').html(`
            <div class="text-center py-5 text-muted">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-3">Loading items for return...</p>
            </div>
        `);
    });

    // Auto-hide alerts
    setTimeout(() => {
        $('.alert').fadeOut();
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
.invoice-row .d-flex {
    min-width: 0;
}
.invoice-row .flex-grow-1 {
    min-width: 0;
}
.return-qty-input {
    max-width: 100px;
    margin: 0 auto;
}
.table-danger {
    background-color: rgba(220, 53, 69, 0.1) !important;
}
.table-warning {
    background-color: rgba(255, 193, 7, 0.1) !important;
}
.btn-group-vertical .btn-group {
    width: 100%;
}
.btn-group-vertical .btn-group .btn {
    flex: 1;
}
.whatsapp-btn {
    border-color: #25D366;
    color: #25D366;
}
.whatsapp-btn:hover {
    background-color: #25D366;
    color: white;
}
.whatsapp-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
@media (max-width: 992px) {
    .page-title-box .d-flex {
        flex-direction: column;
        align-items: stretch !important;
        text-align: center;
    }
    .page-title-box .d-flex > div:last-child {
        margin-top: 1rem;
    }
}
@media (max-width: 576px) {
    .btn-group {
        display: flex;
        flex-direction: column;
        width: 100%;
    }
    .btn-group .btn {
        width: 100%;
        margin-bottom: 4px;
    }
}
/* Toast Notifications */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
}

.custom-toast {
    min-width: 300px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.15);
    margin-bottom: 10px;
    overflow: hidden;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.toast-success {
    border-left: 4px solid #28a745;
}

.toast-error {
    border-left: 4px solid #dc3545;
}

.toast-warning {
    border-left: 4px solid #ffc107;
}

.toast-header {
    padding: 12px 15px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #eee;
}

.toast-body {
    padding: 12px 15px;
    color: #666;
}

.toast-close {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #999;
}

.toast-close:hover {
    color: #333;
}
</style>
</body>
</html>