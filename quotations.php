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

// Check if user can create quotations
$can_create_quotations = in_array($user_role, ['admin', 'shop_manager', 'seller']);

// ==================== GET DATA ====================
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build WHERE clause
$where = "WHERE q.business_id = ? AND DATE(q.quotation_date) BETWEEN ? AND ?";
$params = [$business_id, $start_date, $end_date];
if ($user_role !== 'admin') {
    $where .= " AND q.shop_id = ?";
    $params[] = $current_shop_id;
}
if ($status_filter && $status_filter !== 'all') {
    $where .= " AND q.status = ?";
    $params[] = $status_filter;
}
if ($search) {
    $where .= " AND (q.quotation_number LIKE ? OR q.customer_name LIKE ? OR q.customer_phone LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Stats - Count by status
$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) as count,
        SUM(q.grand_total) as total_amount,
        SUM(CASE WHEN q.status = 'draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END) as sent_count,
        SUM(CASE WHEN q.status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
        SUM(CASE WHEN q.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
        SUM(CASE WHEN q.status = 'expired' THEN 1 ELSE 0 END) as expired_count
    FROM quotations q
    $where
");
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

// Fetch quotations - ORDER BY created_at DESC for newest first
$stmt = $pdo->prepare("
    SELECT q.*, 
           s.shop_name as shop_name,
           u.username as created_by_name
    FROM quotations q
    LEFT JOIN shops s ON q.shop_id = s.id
    LEFT JOIN users u ON q.created_by = u.id
    $where
    ORDER BY q.created_at DESC, q.id DESC
");
$stmt->execute($params);
$quotations = $stmt->fetchAll();

// Get quotation items count
$item_counts = [];
if (!empty($quotations)) {
    $quotation_ids = array_column($quotations, 'id');
    $placeholders = str_repeat('?,', count($quotation_ids) - 1) . '?';
    
    $item_stmt = $pdo->prepare("
        SELECT quotation_id, COUNT(*) as item_count 
        FROM quotation_items 
        WHERE quotation_id IN ($placeholders) 
        GROUP BY quotation_id
    ");
    $item_stmt->execute($quotation_ids);
    $item_counts_result = $item_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Convert to associative array for easy lookup
    foreach ($quotations as $quotation) {
        $item_counts[$quotation['id']] = $item_counts_result[$quotation['id']] ?? 0;
    }
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Quotations"; include 'includes/head.php'; ?>
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
                                    <i class="bx bx-file me-2"></i> Quotations
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <small class="text-muted">
                                    Create and manage price quotations for customers
                                </small>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="pos.php" class="btn btn-primary">
                                    <i class="bx bx-plus-circle me-1"></i> Create Quotation
                                </a>
                                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importModal">
                                    <i class="bx bx-import me-1"></i> Import
                                </button>
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
                            <i class="bx bx-filter-alt me-1"></i> Filter Quotations
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
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="all">All Status</option>
                                        <option value="draft" <?= $status_filter == 'draft' ? 'selected' : '' ?>>Draft</option>
                                        <option value="sent" <?= $status_filter == 'sent' ? 'selected' : '' ?>>Sent</option>
                                        <option value="accepted" <?= $status_filter == 'accepted' ? 'selected' : '' ?>>Accepted</option>
                                        <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                        <option value="expired" <?= $status_filter == 'expired' ? 'selected' : '' ?>>Expired</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Quotation # / Customer / Phone"
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-1 col-md-12">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bx bx-filter me-1"></i> Apply
                                        </button>
                                        <?php if ($start_date != date('Y-m-01') || $end_date != date('Y-m-d') || $status_filter || $search): ?>
                                        <a href="quotations.php" class="btn btn-outline-secondary">
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
                                        <h6 class="text-muted mb-1">Total Value</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($stats['total_amount'] ?? 0, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted"><?= number_format($stats['count'] ?? 0) ?> quotations</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4">
                        <div class="card card-hover border-start border-info border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Draft</h6>
                                        <h3 class="mb-0 text-info"><?= number_format($stats['draft_count'] ?? 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-edit text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Sent</h6>
                                        <h3 class="mb-0 text-warning"><?= number_format($stats['sent_count'] ?? 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-send text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4">
                        <div class="card card-hover border-start border-success border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Accepted</h6>
                                        <h3 class="mb-0 text-success"><?= number_format($stats['accepted_count'] ?? 0) ?></h3>
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
                        <div class="card card-hover border-start border-danger border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Rejected/Expired</h6>
                                        <h3 class="mb-0 text-danger"><?= number_format(($stats['rejected_count'] ?? 0) + ($stats['expired_count'] ?? 0)) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-x-circle text-danger"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <?= ($stats['rejected_count'] ?? 0) ?> rejected • 
                                        <?= ($stats['expired_count'] ?? 0) ?> expired
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quotations Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="quotationsTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;">Quotation Details</th>
                                        <th class="text-center" style="width: 20%;">Customer</th>
                                        <th class="text-center" style="width: 15%;">Validity</th>
                                        <th class="text-end" style="width: 20%;">Amount Details</th>
                                        <th class="text-center" style="width: 20%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($quotations)): ?>
                                    
                                    <?php else: ?>
                                    <?php 
                                    foreach ($quotations as $i => $q):
                                        $total = (float)($q['grand_total'] ?? 0);
                                        $item_count = $item_counts[$q['id']] ?? 0;
                                        
                                        // Status colors
                                        $status_class = [
                                            'draft' => 'secondary',
                                            'sent' => 'warning',
                                            'accepted' => 'success',
                                            'rejected' => 'danger',
                                            'expired' => 'dark'
                                        ][$q['status']] ?? 'secondary';
                                        
                                        $status_icon = [
                                            'draft' => 'bx-edit',
                                            'sent' => 'bx-send',
                                            'accepted' => 'bx-check-circle',
                                            'rejected' => 'bx-x-circle',
                                            'expired' => 'bx-time'
                                        ][$q['status']] ?? 'bx-edit';
                                        
                                        // Check if expired
                                        $is_expired = strtotime($q['valid_until']) < time() && $q['status'] !== 'accepted' && $q['status'] !== 'rejected';
                                        
                                        // Show converted info if applicable
                                        $converted_info = '';
                                        if ($q['converted_to_invoice_id']) {
                                            $converted_info = '<span class="badge bg-success bg-opacity-10 text-success px-3 py-1 d-inline-block">
                                                <i class="bx bx-receipt me-1"></i> Converted to Invoice
                                            </span>';
                                        }
                                    ?>
                                    <tr class="quotation-row" data-id="<?= $q['id'] ?>" data-customer-id="<?= $q['customer_id'] ?? 0 ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3 flex-shrink-0">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx bx-file fs-4"></i>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <strong class="d-block mb-1 text-primary"><?= htmlspecialchars($q['quotation_number']) ?></strong>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-calendar me-1"></i><?= date('d M Y', strtotime($q['quotation_date'])) ?>
                                                        <i class="bx bx-user ms-2 me-1"></i><?= htmlspecialchars($q['created_by_name'] ?? 'System') ?>
                                                    </small>
                                                    <div class="d-flex gap-2 mt-2">
                                                        <span class="badge bg-<?= $status_class ?> bg-opacity-10 text-<?= $status_class ?> px-3 py-1 d-inline-block">
                                                            <i class="bx <?= $status_icon ?> me-1"></i>
                                                            <?= ucfirst($q['status']) ?>
                                                        </span>
                                                        <?php if ($is_expired && $q['status'] !== 'expired'): ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-1 d-inline-block">
                                                            <i class="bx bx-time me-1"></i> Expired
                                                        </span>
                                                        <?php endif; ?>
                                                        <?= $converted_info ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div>
                                                <strong class="d-block mb-1"><?= htmlspecialchars($q['customer_name'] ?? 'Customer') ?></strong>
                                                <?php if ($q['customer_phone']): ?>
                                                <small class="text-muted">
                                                    <i class="bx bx-phone me-1"></i><?= htmlspecialchars($q['customer_phone']) ?>
                                                </small>
                                                <br>
                                                <?php endif; ?>
                                                <?php if ($q['customer_email']): ?>
                                                <small class="text-muted">
                                                    <i class="bx bx-envelope me-1"></i><?= htmlspecialchars($q['customer_email']) ?>
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3 py-2 fs-6">
                                                    <i class="bx bx-package me-1"></i> <?= $item_count ?>
                                                    <small class="ms-1">items</small>
                                                </span>
                                            </div>
                                            <small class="text-muted">
                                                Valid until:<br>
                                                <strong class="<?= $is_expired ? 'text-danger' : 'text-success' ?>">
                                                    <?= date('d M Y', strtotime($q['valid_until'])) ?>
                                                </strong>
                                            </small>
                                        </td>
                                        <td class="text-end">
                                            <div class="mb-2">
                                                <strong class="text-primary fs-5">₹<?= number_format($total, 2) ?></strong>
                                                <small class="text-muted d-block">Grand Total</small>
                                            </div>
                                            <div class="d-flex justify-content-end gap-3 mb-1">
                                                <div class="text-end">
                                                    <span class="text-muted fw-bold">₹<?= number_format($q['subtotal'] ?? 0, 2) ?></span>
                                                    <small class="text-muted d-block">Subtotal</small>
                                                </div>
                                                <?php if ($q['total_tax'] > 0): ?>
                                                <div class="text-end">
                                                    <span class="text-muted fw-bold">₹<?= number_format($q['total_tax'] ?? 0, 2) ?></span>
                                                    <small class="text-muted d-block">Tax</small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="quotation_view.php?id=<?= $q['id'] ?>"
                                                   class="btn btn-outline-primary" title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </a>
                                                <a href="quotation_print.php?id=<?= $q['id'] ?>" target="_blank"
                                                   class="btn btn-outline-success" title="Print Quotation">
                                                    <i class="bx bx-printer"></i>
                                                </a>
                                                
                                                <?php if ($q['status'] == 'accepted' && !$q['converted_to_invoice_id'] && $can_create_quotations): ?>
                                                <button class="btn btn-outline-info convert-to-invoice-btn"
                                                        data-quotation-id="<?= $q['id'] ?>"
                                                        title="Convert to Invoice">
                                                    <i class="bx bx-receipt"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if ($q['status'] == 'draft'): ?>
                                                <button class="btn btn-outline-danger delete-quotation-btn"
                                                        data-quotation-id="<?= $q['id'] ?>"
                                                        data-quotation-number="<?= htmlspecialchars($q['quotation_number']) ?>"
                                                        title="Delete Quotation">
                                                    <i class="bx bx-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($q['notes']): ?>
                                            <div class="mt-2">
                                                <small class="text-muted" title="<?= htmlspecialchars($q['notes']) ?>">
                                                    <i class="bx bx-note me-1"></i> <?= substr(htmlspecialchars($q['notes']), 0, 30) ?><?= strlen($q['notes']) > 30 ? '...' : '' ?>
                                                </small>
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

<!-- Convert to Invoice Modal -->
<div class="modal fade" id="convertToInvoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bx bx-receipt me-2"></i> Convert to Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="convert_quotation_to_invoice.php" id="convertToInvoiceForm">
                <input type="hidden" name="quotation_id" id="convertQuotationId">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bx bx-info-circle me-2"></i>
                        This will create a new invoice from the quotation. All items and customer details will be copied.
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Invoice Date <span class="text-danger">*</span></label>
                            <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <option value="">-- Select Method --</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="upi">UPI</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="credit">Credit</option>
                                <option value="multiple">Multiple Methods</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Payment Status <span class="text-danger">*</span></label>
                            <select name="payment_status" class="form-select" required>
                                <option value="pending">Pending</option>
                                <option value="partial">Partial</option>
                                <option value="paid" selected>Paid</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Paid Amount</label>
                            <input type="number" name="paid_amount" class="form-control" step="0.01" min="0" 
                                   placeholder="Enter paid amount if partial">
                        </div>
                        
                        <div class="col-md-12">
                            <div class="form-check">
                                <input type="checkbox" name="include_all_items" id="include_all_items" class="form-check-input" checked>
                                <label class="form-check-label" for="include_all_items">
                                    Include all quotation items in the invoice
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Additional Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes for the invoice..."></textarea>
                        </div>
                    </div>
                    
                    <!-- Preview of items -->
                    <div class="mt-4" id="quotationItemsPreview">
                        <h6 class="mb-3">Items to be included:</h6>
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2">Loading items...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info" id="convertSubmitBtn">
                        <i class="bx bx-check me-1"></i> Convert to Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteQuotationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bx bx-trash me-2"></i> Delete Quotation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete quotation <strong id="deleteQuotationNumber"></strong>?</p>
                <p class="text-danger"><i class="bx bx-error-circle me-1"></i> This action cannot be undone. All items will be deleted.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="bx bx-trash me-1"></i> Delete
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
    var quotationsTable = $('#quotationsTable').DataTable({
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
            search: "Search quotations:",
            lengthMenu: "Show _MENU_",
            info: "Showing _START_ to _END_ of _TOTAL_ quotations",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    $('[data-bs-toggle="tooltip"]').tooltip();

    // ==================== CONVERT TO INVOICE ====================
    $('.convert-to-invoice-btn').click(function() {
        const quotationId = $(this).data('quotation-id');
        $('#convertQuotationId').val(quotationId);
        
        // Show modal
        const convertModal = new bootstrap.Modal(document.getElementById('convertToInvoiceModal'));
        convertModal.show();
        
        // Load quotation items preview
        $.ajax({
            url: 'ajax/get_quotation_items.php',
            method: 'GET',
            data: { quotation_id: quotationId },
            beforeSend: function() {
                $('#quotationItemsPreview').html(`
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2">Loading items...</p>
                    </div>
                `);
            },
            success: function(response) {
                $('#quotationItemsPreview').html(response);
            },
            error: function() {
                $('#quotationItemsPreview').html(`
                    <div class="alert alert-danger">
                        Failed to load items. Please try again.
                    </div>
                `);
            }
        });
    });
    
    // Handle convert to invoice form submission
    $('#convertToInvoiceForm').submit(function(e) {
        e.preventDefault();
        
        // Validate form
        const paymentMethod = $('select[name="payment_method"]').val();
        if (!paymentMethod) {
            alert('Please select a payment method.');
            return false;
        }
        
        if (!confirm('Are you sure you want to convert this quotation to an invoice?')) {
            return false;
        }
        
        // Show processing
        const btn = $('#convertSubmitBtn');
        const originalText = btn.html();
        btn.html('<i class="bx bx-loader bx-spin me-2"></i> Processing...');
        btn.prop('disabled', true);
        
        // Submit form
        $.ajax({
            url: 'convert_quotation_to_invoice.php',
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                try {
                    const data = JSON.parse(response);
                    if (data.success) {
                        alert(data.message);
                        $('#convertToInvoiceModal').modal('hide');
                        window.location.href = 'invoices.php';
                    } else {
                        alert(data.message || 'Failed to convert quotation.');
                        btn.html(originalText);
                        btn.prop('disabled', false);
                    }
                } catch (e) {
                    // If response is not JSON, assume it's a redirect or HTML
                    if (response.includes('success') || response.includes('Success')) {
                        $('#convertToInvoiceModal').modal('hide');
                        window.location.href = 'invoices.php';
                    } else {
                        alert('Error processing request. Please try again.');
                        console.log('Response:', response);
                        btn.html(originalText);
                        btn.prop('disabled', false);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Conversion error:', error, xhr.responseText);
                alert('Failed to convert quotation. Please try again.');
                btn.html(originalText);
                btn.prop('disabled', false);
            }
        });
    });
    
    // ==================== DELETE QUOTATION ====================
    $('.delete-quotation-btn').click(function() {
        const quotationId = $(this).data('quotation-id');
        const quotationNumber = $(this).data('quotation-number');
        
        $('#deleteQuotationNumber').text(quotationNumber);
        
        // Show modal
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteQuotationModal'));
        deleteModal.show();
        
        // Handle delete confirmation
        $('#confirmDeleteBtn').off('click').on('click', function() {
            const btn = $(this);
            const originalText = btn.html();
            btn.html('<i class="bx bx-loader bx-spin me-2"></i> Deleting...');
            btn.prop('disabled', true);
            
            $.ajax({
                url: 'ajax/delete_quotation.php',
                method: 'POST',
                data: { quotation_id: quotationId },
                success: function(response) {
                    try {
                        const data = JSON.parse(response);
                        if (data.success) {
                            deleteModal.hide();
                            // Remove row from DataTable
                            const row = $('.delete-quotation-btn[data-quotation-id="' + quotationId + '"]').closest('tr');
                            quotationsTable.row(row).remove().draw();
                            
                            // Show success message
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        } else {
                            alert(data.message || 'Failed to delete quotation.');
                            btn.html(originalText);
                            btn.prop('disabled', false);
                        }
                    } catch (e) {
                        alert('Error processing request. Please try again.');
                        btn.html(originalText);
                        btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Failed to delete quotation. Please try again.');
                    btn.html(originalText);
                    btn.prop('disabled', false);
                }
            });
        });
    });
    
    // Update payment status fields based on selection
    $('select[name="payment_status"]').change(function() {
        const status = $(this).val();
        const paidAmountField = $('input[name="paid_amount"]');
        
        if (status === 'paid') {
            paidAmountField.val('');
            paidAmountField.prop('required', false);
        } else if (status === 'partial') {
            paidAmountField.prop('required', true);
            paidAmountField.focus();
        } else {
            paidAmountField.val('');
            paidAmountField.prop('required', false);
        }
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
.quotation-row .d-flex {
    min-width: 0;
}
.quotation-row .flex-grow-1 {
    min-width: 0;
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
</style>
</body>
</html>