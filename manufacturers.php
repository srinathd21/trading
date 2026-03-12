<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager','shop_manager'])) {
    header('Location: dashboard.php');
    exit();
}

// Get current user's business and shop info
$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'];
$shop_id = $_SESSION['shop_id'] ?? null;

// Get available shops for the current business
$shops_stmt = $pdo->prepare("
    SELECT id, shop_name, shop_code, is_warehouse 
    FROM shops 
    WHERE business_id = ? AND is_active = 1
    ORDER BY shop_name
");
$shops_stmt->execute([$business_id]);
$shops = $shops_stmt->fetchAll();

// Display messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['form_data']);

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$shop_filter = $_GET['shop_filter'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$outstanding_filter = $_GET['outstanding'] ?? 'all'; // New filter for outstanding status

$where = "WHERE m.business_id = :business_id";
$params = ['business_id' => $business_id];

if ($search) {
    $where .= " AND (m.name LIKE :search 
        OR m.phone LIKE :search3 
        OR m.gstin LIKE :search4 
        OR m.account_number LIKE :search5 
        OR m.ifsc_code LIKE :search6 
        OR m.upi_id LIKE :search7
        OR EXISTS (
            SELECT 1 FROM manufacturer_contacts mc 
            WHERE mc.manufacturer_id = m.id 
            AND (mc.contact_person LIKE :search2 OR mc.phone LIKE :search2 OR mc.mobile LIKE :search2 OR mc.email LIKE :search2)
        )
    )";
    $like = "%$search%";
    $params['search'] = $like;
    $params['search2'] = $like;
    $params['search3'] = $like;
    $params['search4'] = $like;
    $params['search5'] = $like;
    $params['search6'] = $like;
    $params['search7'] = $like;
}

if ($shop_filter) {
    $where .= " AND m.shop_id = :shop_filter";
    $params['shop_filter'] = $shop_filter;
}

if ($status_filter !== 'all') {
    $where .= " AND m.is_active = :status";
    $params['status'] = ($status_filter === 'active') ? 1 : 0;
}

// First, get all manufacturers with their basic data
$base_sql = "
    SELECT m.*, 
           s.shop_name,
           s.shop_code,
           (SELECT COUNT(*) FROM purchases p WHERE p.manufacturer_id = m.id) as total_purchases,
           (SELECT COALESCE(SUM(p.total_amount), 0) FROM purchases p WHERE p.manufacturer_id = m.id) as total_purchase_amount,
           (SELECT COALESCE(SUM(p.paid_amount), 0) FROM purchases p WHERE p.manufacturer_id = m.id) as total_paid_amount,
           (SELECT COUNT(*) FROM manufacturer_contacts mc WHERE mc.manufacturer_id = m.id) as total_contacts,
           (SELECT contact_person FROM manufacturer_contacts mc WHERE mc.manufacturer_id = m.id AND mc.is_primary = 1 LIMIT 1) as primary_contact,
           (SELECT phone FROM manufacturer_contacts mc WHERE mc.manufacturer_id = m.id AND mc.is_primary = 1 LIMIT 1) as primary_phone,
           (SELECT email FROM manufacturer_contacts mc WHERE mc.manufacturer_id = m.id AND mc.is_primary = 1 LIMIT 1) as primary_email
    FROM manufacturers m
    LEFT JOIN shops s ON m.shop_id = s.id
    $where
";

$stmt = $pdo->prepare($base_sql);
$stmt->execute($params);
$manufacturers = $stmt->fetchAll();

// Calculate financial data for each manufacturer
foreach ($manufacturers as &$manufacturer) {
    // Get purchase balance (pending amount from purchases)
    $balance_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0) as purchase_balance
        FROM purchases 
        WHERE manufacturer_id = ? AND payment_status != 'paid'
    ");
    $balance_stmt->execute([$manufacturer['id']]);
    $purchase_balance = $balance_stmt->fetchColumn();
    
    $manufacturer['purchase_balance'] = $purchase_balance;
    
    // Calculate net outstanding (initial outstanding + purchase balance)
    $initial_outstanding = $manufacturer['initial_outstanding_amount'] ?? 0;
    $initial_type = $manufacturer['initial_outstanding_type'] ?? 'none';
    
    // Initial outstanding affects the net balance
    if ($initial_type === 'credit') {
        // Credit: Supplier owes us - reduces what we owe
        $manufacturer['net_outstanding'] = $purchase_balance - $initial_outstanding;
    } elseif ($initial_type === 'debit') {
        // Debit: We owe supplier - increases what we owe
        $manufacturer['net_outstanding'] = $purchase_balance + $initial_outstanding;
    } else {
        $manufacturer['net_outstanding'] = $purchase_balance;
    }
    
    $manufacturer['net_outstanding'] = max(0, $manufacturer['net_outstanding']); // Ensure non-negative
}

// Apply outstanding filter after calculation
if ($outstanding_filter !== 'all') {
    $filtered_manufacturers = [];
    foreach ($manufacturers as $manufacturer) {
        if ($outstanding_filter === 'has_outstanding' && $manufacturer['net_outstanding'] > 0) {
            $filtered_manufacturers[] = $manufacturer;
        } elseif ($outstanding_filter === 'no_outstanding' && $manufacturer['net_outstanding'] == 0) {
            $filtered_manufacturers[] = $manufacturer;
        } elseif ($outstanding_filter === 'credit' && $manufacturer['initial_outstanding_type'] === 'credit') {
            $filtered_manufacturers[] = $manufacturer;
        } elseif ($outstanding_filter === 'debit' && $manufacturer['initial_outstanding_type'] === 'debit') {
            $filtered_manufacturers[] = $manufacturer;
        }
    }
    $manufacturers = $filtered_manufacturers;
}

// Summary statistics based on current filter
$total_suppliers = count($manufacturers);
$active_suppliers = 0;
$inactive_suppliers = 0;
$total_purchase_amount = 0;
$total_paid_amount = 0;
$total_outstanding = 0;
$total_credit = 0;
$total_debit = 0;

foreach ($manufacturers as $m) {
    if ($m['is_active']) $active_suppliers++;
    else $inactive_suppliers++;
    
    $total_purchase_amount += ($m['total_purchase_amount'] ?? 0);
    $total_paid_amount += ($m['total_paid_amount'] ?? 0);
    $total_outstanding += $m['net_outstanding'];
    
    if ($m['initial_outstanding_type'] === 'credit') {
        $total_credit += ($m['initial_outstanding_amount'] ?? 0);
    } elseif ($m['initial_outstanding_type'] === 'debit') {
        $total_debit += ($m['initial_outstanding_amount'] ?? 0);
    }
}

$total_purchases_count = array_sum(array_column($manufacturers, 'total_purchases'));
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Manufacturers & Suppliers"; ?>
<?php include('includes/head.php'); ?>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

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
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-buildings me-2"></i> Manufacturers & Suppliers
                                <small class="text-muted ms-2">
                                    <i class="bx bx-store me-1"></i>
                                    <?= htmlspecialchars($_SESSION['current_shop_name'] ?? 'All Shops') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addManufacturerModal">
                                    <i class="bx bx-plus-circle me-1"></i> Add Supplier
                                </button>
                                <button class="btn btn-outline-secondary" id="exportBtn" type="button">
                                    <i class="bx bx-download me-1"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success): ?>
                    <div style="display:none" id="successMessage"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div style="display:none" id="errorMessage"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <!-- Enhanced Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Suppliers</h6>
                                        <h3 class="mb-0 text-primary"><?= $total_suppliers ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-buildings text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-2 small text-muted">
                                    <span class="text-success me-2">
                                        <i class="bx bx-check-circle"></i> Active: <?= $active_suppliers ?>
                                    </span>
                                    <span class="text-secondary">
                                        <i class="bx bx-x-circle"></i> Inactive: <?= $inactive_suppliers ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Purchase Overview</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($total_purchase_amount, 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-cart text-success"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-2 small">
                                    <span class="text-info me-2">
                                        <i class="bx bx-purchase-tag"></i> Orders: <?= $total_purchases_count ?>
                                    </span>
                                    <span class="text-muted">
                                        <i class="bx bx-check-circle"></i> Paid: ₹<?= number_format($total_paid_amount, 2) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Outstanding</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($total_outstanding, 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-money text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-2 small">
                                    <span class="text-success me-2">
                                        <i class="bx bx-up-arrow-alt"></i> Credit: ₹<?= number_format($total_credit, 2) ?>
                                    </span>
                                    <span class="text-danger">
                                        <i class="bx bx-down-arrow-alt"></i> Debit: ₹<?= number_format($total_debit, 2) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Payment Progress</h6>
                                        <h3 class="mb-0 text-info">
                                            <?php 
                                            $paid_percent = $total_purchase_amount > 0 ? round(($total_paid_amount / $total_purchase_amount) * 100) : 0;
                                            echo $paid_percent . '%';
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-pie-chart-alt text-info"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar bg-success" style="width: <?= $paid_percent ?>%"></div>
                                </div>
                                <div class="mt-1 small text-muted">
                                    Paid: ₹<?= number_format($total_paid_amount, 2) ?> / Total: ₹<?= number_format($total_purchase_amount, 2) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Suppliers
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Name, Contact, Phone, GSTIN..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Shop</label>
                                    <select name="shop_filter" class="form-select">
                                        <option value="">All Shops</option>
                                        <?php foreach ($shops as $shop): ?>
                                            <option value="<?= $shop['id'] ?>" <?= $shop_filter == $shop['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($shop['shop_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Outstanding</label>
                                    <select name="outstanding" class="form-select">
                                        <option value="all" <?= $outstanding_filter === 'all' ? 'selected' : '' ?>>All</option>
                                        <option value="has_outstanding" <?= $outstanding_filter === 'has_outstanding' ? 'selected' : '' ?>>Has Outstanding</option>
                                        <option value="no_outstanding" <?= $outstanding_filter === 'no_outstanding' ? 'selected' : '' ?>>No Outstanding</option>
                                        <option value="credit" <?= $outstanding_filter === 'credit' ? 'selected' : '' ?>>Credit Balance</option>
                                        <option value="debit" <?= $outstanding_filter === 'debit' ? 'selected' : '' ?>>Debit Balance</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply
                                        </button>
                                        <?php if ($search || $shop_filter || $status_filter !== 'all' || $outstanding_filter !== 'all'): ?>
                                            <a href="manufacturers.php" class="btn btn-outline-secondary">
                                                <i class="bx bx-reset"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <p class="text-muted">
                            Showing <?= count($manufacturers) ?> suppliers
                            <?php if ($search): ?> matching "<?= htmlspecialchars($search) ?>"<?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Suppliers Table with Enhanced Columns -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="suppliersTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                <tr>
                                    <th>Supplier</th>
                                    <th>Primary Contact</th>
                                    <th>Contact Details</th>
                                    <th>Bank & UPI</th>
                                    <th class="text-center">Shop</th>
                                    <th class="text-center">Purchase & Outstanding</th>
                                    <th class="text-end">Total Amount</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($manufacturers)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bx bx-buildings fs-1 text-muted mb-3"></i>
                                                <h5>No suppliers found</h5>
                                                <p class="text-muted">
                                                    <?php if ($search || $shop_filter || $status_filter !== 'all' || $outstanding_filter !== 'all'): ?>
                                                        Try adjusting your filters or <a href="manufacturers.php">clear all filters</a>
                                                    <?php else: ?>
                                                        Get started by adding your first supplier
                                                    <?php endif; ?>
                                                </p>
                                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addManufacturerModal">
                                                    <i class="bx bx-plus-circle me-1"></i> Add Supplier
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($manufacturers as $m):
                                        $outstanding_amount = $m['initial_outstanding_amount'] ?? 0;
                                        $outstanding_type = $m['initial_outstanding_type'] ?? 'none';
                                        $purchase_balance = $m['purchase_balance'] ?? 0;
                                        $net_outstanding = $m['net_outstanding'] ?? 0;
                                        ?>
                                        <tr class="supplier-row" data-id="<?= $m['id'] ?>">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm me-3">
                                                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                             style="width: 48px; height: 48px;">
                                                        <span class="fw-bold fs-5">
                                                            <?= strtoupper(substr($m['name'], 0, 2)) ?>
                                                        </span>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <strong class="d-block mb-1"><?= htmlspecialchars($m['name']) ?></strong>
                                                        <?php if (!empty($m['gstin'])): ?>
                                                            <small class="text-muted"><i class="bx bx-barcode me-1"></i><?= htmlspecialchars($m['gstin']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($m['primary_contact'])): ?>
                                                    <div class="fw-bold"><?= htmlspecialchars($m['primary_contact']) ?></div>
                                                    <?php if (!empty($m['primary_phone'])): ?>
                                                        <small class="text-muted d-block">
                                                            <i class="bx bx-phone me-1"></i><?= htmlspecialchars($m['primary_phone']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No primary contact</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($m['phone'])): ?>
                                                    <div class="mb-1">
                                                        <small><i class="bx bx-phone text-primary me-1"></i> <?= htmlspecialchars($m['phone']) ?></small>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($m['email'])): ?>
                                                    <div>
                                                        <small><i class="bx bx-envelope text-info me-1"></i> <?= htmlspecialchars($m['email']) ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($m['bank_name']) || !empty($m['upi_id'])): ?>
                                                    <?php if (!empty($m['bank_name'])): ?>
                                                        <small class="d-block"><i class="bx bx-bank me-1"></i><?= htmlspecialchars($m['bank_name']) ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($m['upi_id'])): ?>
                                                        <small class="d-block"><i class="bx bx-mobile-alt me-1"></i><?= htmlspecialchars($m['upi_id']) ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!empty($m['shop_name'])): ?>
                                                    <span class="badge bg-info bg-opacity-10 text-info">
                                                        <?= htmlspecialchars($m['shop_code']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <!-- Combined Purchase & Outstanding Column -->
                                                <div class="d-flex flex-column gap-1">
                                                    <!-- Purchase Balance -->
                                                    <?php if ($purchase_balance > 0): ?>
                                                        <span class="badge bg-warning bg-opacity-10 text-warning px-2 py-1">
                                                            <i class="bx bx-cart me-1"></i> PO Due: ₹<?= number_format($purchase_balance, 2) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Initial Outstanding -->
                                                    <?php if ($outstanding_amount > 0 && $outstanding_type !== 'none'): ?>
                                                        <span class="badge <?= $outstanding_type === 'credit' ? 'bg-success' : 'bg-danger' ?> bg-opacity-10 text-<?= $outstanding_type === 'credit' ? 'success' : 'danger' ?> px-2 py-1">
                                                            <i class="bx bx-<?= $outstanding_type === 'credit' ? 'up-arrow-alt' : 'down-arrow-alt' ?> me-1"></i>
                                                            <?= ucfirst($outstanding_type) ?>: ₹<?= number_format($outstanding_amount, 2) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Net Outstanding -->
                                                    <?php if ($net_outstanding > 0): ?>
                                                        <span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1 fw-bold">
                                                            <i class="bx bx-money me-1"></i> Net Due: ₹<?= number_format($net_outstanding, 2) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success bg-opacity-10 text-success px-2 py-1">
                                                            <i class="bx bx-check me-1"></i> No Dues
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <button class="btn btn-sm btn-outline-info mt-2 view-outstanding-history-btn"
                                                        data-id="<?= $m['id'] ?>"
                                                        data-name="<?= htmlspecialchars($m['name']) ?>"
                                                        title="View Outstanding History">
                                                    <i class="bx bx-history"></i>
                                                </button>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex flex-column">
                                                    <strong class="text-success">₹<?= number_format((float)($m['total_purchase_amount'] ?? 0), 2) ?></strong>
                                                    <small class="text-muted">(<?= (int)($m['total_purchases'] ?? 0) ?> orders)</small>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!empty($m['is_active'])): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success px-3 py-1">
                                                        <i class="bx bx-circle me-1"></i>Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1">
                                                        <i class="bx bx-circle me-1"></i>Inactive
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-outline-primary view-supplier-btn"
                                                            data-id="<?= $m['id'] ?>"
                                                            title="View Details">
                                                        <i class="bx bx-show"></i>
                                                    </button>

                                                    <?php if ($net_outstanding > 0): ?>
    <?php
    // Get the first pending purchase for this supplier to redirect to its payment page
    $pending_purchase_stmt = $pdo->prepare("
        SELECT id, purchase_number 
        FROM purchases 
        WHERE manufacturer_id = ? AND payment_status != 'paid' 
        ORDER BY purchase_date ASC 
        LIMIT 1
    ");
    $pending_purchase_stmt->execute([$m['id']]);
    $pending_purchase = $pending_purchase_stmt->fetch();
    
    if ($pending_purchase): 
    ?>
        <a href="purchase_payment.php?id=<?= $pending_purchase['id'] ?>" 
           class="btn btn-sm btn-outline-success"
           title="Make Payment for PO: <?= htmlspecialchars($pending_purchase['purchase_number']) ?>">
            <i class="bx bx-money"></i>
        </a>
    <?php else: ?>
        <button class="btn btn-sm btn-outline-success make-payment-btn"
                data-id="<?= $m['id'] ?>"
                data-name="<?= htmlspecialchars($m['name']) ?>"
                data-outstanding-type="<?= htmlspecialchars($outstanding_type) ?>"
                data-outstanding-amount="<?= (float)$outstanding_amount ?>"
                data-purchase-balance="<?= (float)$purchase_balance ?>"
                data-net-outstanding="<?= (float)$net_outstanding ?>"
                title="Make Payment">
            <i class="bx bx-money"></i>
        </button>
    <?php endif; ?>
<?php else: ?>
    <button class="btn btn-sm btn-outline-success make-payment-btn"
            data-id="<?= $m['id'] ?>"
            data-name="<?= htmlspecialchars($m['name']) ?>"
            data-outstanding-type="<?= htmlspecialchars($outstanding_type) ?>"
            data-outstanding-amount="<?= (float)$outstanding_amount ?>"
            data-purchase-balance="<?= (float)$purchase_balance ?>"
            data-net-outstanding="<?= (float)$net_outstanding ?>"
            title="Make Payment">
        <i class="bx bx-money"></i>
    </button>
<?php endif; ?>

                                                    <a href="purchases.php?manufacturer=<?= $m['id'] ?>"
                                                       class="btn btn-sm btn-outline-info"
                                                       title="View Purchases">
                                                        <i class="bx bx-cart"></i>
                                                    </a>

                                                    <a href="manufacturer_statement.php?id=<?= $m['id'] ?>" 
                                                       class="btn btn-sm btn-outline-info"
                                                       title="View Statement">
                                                        <i class="bx bx-file"></i>
                                                    </a>

                                                    <button class="btn btn-sm btn-outline-warning edit-btn"
                                                            data-id="<?= $m['id'] ?>"
                                                            data-name="<?= htmlspecialchars($m['name']) ?>"
                                                            data-phone="<?= htmlspecialchars($m['phone'] ?? '') ?>"
                                                            data-email="<?= htmlspecialchars($m['email'] ?? '') ?>"
                                                            data-address="<?= htmlspecialchars($m['address'] ?? '') ?>"
                                                            data-gstin="<?= htmlspecialchars($m['gstin'] ?? '') ?>"
                                                            data-accountholder="<?= htmlspecialchars($m['account_holder_name'] ?? '') ?>"
                                                            data-bankname="<?= htmlspecialchars($m['bank_name'] ?? '') ?>"
                                                            data-accountno="<?= htmlspecialchars($m['account_number'] ?? '') ?>"
                                                            data-ifsc="<?= htmlspecialchars($m['ifsc_code'] ?? '') ?>"
                                                            data-branch="<?= htmlspecialchars($m['branch_name'] ?? '') ?>"
                                                            data-upi="<?= htmlspecialchars($m['upi_id'] ?? '') ?>"
                                                            data-qr="<?= htmlspecialchars($m['upi_qr_code'] ?? '') ?>"
                                                            data-outstanding-type="<?= htmlspecialchars($outstanding_type) ?>"
                                                            data-outstanding-amount="<?= (float)$outstanding_amount ?>"
                                                            data-active="<?= (int)$m['is_active'] ?>"
                                                            data-shop="<?= htmlspecialchars($m['shop_id'] ?? '') ?>"
                                                            title="Edit Supplier">
                                                        <i class="bx bx-edit"></i>
                                                    </button>
                                                
                                                    <a href="manufacturers_process.php?delete=<?= $m['id'] ?>"
                                                       class="btn btn-sm btn-outline-danger delete-supplier-btn"
                                                       data-name="<?= htmlspecialchars($m['name']) ?>"
                                                       title="Delete Supplier">
                                                        <i class="bx bx-trash"></i>
                                                    </a>
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

        <?php include('includes/footer.php'); ?>
    </div>
</div>


<!-- Add/Edit Modal -->
<div class="modal fade" id="addManufacturerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-plus-circle me-2"></i> 
                    <span id="modalTitle">Add Supplier</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manufacturers_process.php" id="manufacturerForm" enctype="multipart/form-data">
                <input type="hidden" name="id" id="editId" value="">
                <div class="modal-body">
                    <!-- ... (keep existing tabs content) ... -->
                    <ul class="nav nav-tabs nav-tabs-custom" id="supplierTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic-tab-pane" type="button" role="tab">
                                <i class="bx bx-building-house me-1"></i> Basic Info
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact-tab-pane" type="button" role="tab">
                                <i class="bx bx-contact me-1"></i> Contact Details
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="bank-tab" data-bs-toggle="tab" data-bs-target="#bank-tab-pane" type="button" role="tab">
                                <i class="bx bx-credit-card me-1"></i> Bank & UPI Details
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="outstanding-tab" data-bs-toggle="tab" data-bs-target="#outstanding-tab-pane" type="button" role="tab">
                                <i class="bx bx-money me-1"></i> Outstanding
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content p-3 border border-top-0 rounded-bottom">
                        <!-- Basic Info Tab -->
                        <div class="tab-pane fade show active" id="basic-tab-pane" role="tabpanel" tabindex="0">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label"><strong>Company Name <span class="text-danger">*</span></strong></label>
                                    <input type="text" name="name" id="companyName" 
                                           class="form-control form-control-lg" 
                                           required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">GSTIN</label>
                                    <input type="text" name="gstin" id="gstin" 
                                           class="form-control"
                                           placeholder="33ABCDE1234F1Z5">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone (Main Line)</label>
                                    <input type="text" name="phone" id="phone" 
                                           class="form-control"
                                           placeholder="+91 9876543210">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email (General)</label>
                                    <input type="email" name="email" id="email" 
                                           class="form-control"
                                           placeholder="supplier@example.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Address</label>
                                    <textarea name="address" id="address" 
                                              class="form-control" 
                                              rows="2"
                                              placeholder="Full address with city, state, and pin code"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Shop / Warehouse</label>
                                    <select name="shop_id" id="shopId" class="form-select">
                                        <option value="">-- Select Shop (Optional) --</option>
                                        <?php foreach ($shops as $shop): ?>
                                        <option value="<?= $shop['id'] ?>">
                                            <?= htmlspecialchars($shop['shop_name']) ?> 
                                            (<?= $shop['shop_code'] ?>)
                                            <?= $shop['is_warehouse'] == 1 ? ' - Warehouse' : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Assign supplier to a specific shop or warehouse</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Details Tab - Multiple Contacts -->
                        <div class="tab-pane fade" id="contact-tab-pane" role="tabpanel" tabindex="0">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0"><i class="bx bx-user me-1"></i> Contact Persons</h6>
                                <button type="button" class="btn btn-sm btn-primary" id="addContactBtn">
                                    <i class="bx bx-plus me-1"></i> Add Contact
                                </button>
                            </div>
                            
                            <div id="contactsContainer">
                                <!-- Contact entries will be added here dynamically -->
                            </div>
                            
                            <div class="alert alert-info mt-2">
                                <i class="bx bx-info-circle me-1"></i>
                                <small>Add multiple contact persons for this supplier. The primary contact will be displayed in the main listing.</small>
                            </div>
                        </div>
                        
                        <!-- Bank & UPI Details Tab -->
                        <div class="tab-pane fade" id="bank-tab-pane" role="tabpanel" tabindex="0">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Account Holder Name</label>
                                    <input type="text" name="account_holder_name" id="accountHolderName" 
                                           class="form-control"
                                           placeholder="Name as per bank account">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Bank Name</label>
                                    <input type="text" name="bank_name" id="bankName" 
                                           class="form-control"
                                           placeholder="e.g., State Bank of India">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Account Number</label>
                                    <input type="text" name="account_number" id="accountNumber" 
                                           class="form-control"
                                           placeholder="Account number">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">IFSC Code</label>
                                    <input type="text" name="ifsc_code" id="ifscCode" 
                                           class="form-control"
                                           placeholder="SBIN0001234">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Branch Name</label>
                                    <input type="text" name="branch_name" id="branchName" 
                                           class="form-control"
                                           placeholder="Branch location">
                                </div>
                                
                                <!-- UPI Section -->
                                <div class="col-12 mt-3">
                                    <hr>
                                    <h6 class="mb-3"><i class="bx bx-mobile-alt me-1"></i> UPI Payment Details</h6>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">UPI ID</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bx bx-at"></i></span>
                                        <input type="text" name="upi_id" id="upiId" 
                                               class="form-control"
                                               placeholder="supplier@bankname">
                                    </div>
                                    <small class="text-muted">e.g., 9876543210@okhdfcbank, supplier@paytm</small>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">UPI QR Code</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bx bx-qr"></i></span>
                                        <input type="file" name="upi_qr_code" id="upiQrCode" 
                                               class="form-control"
                                               accept="image/*">
                                    </div>
                                    <small class="text-muted">Upload QR code image (Max: 2MB, JPG/PNG)</small>
                                </div>
                                
                                <!-- Display existing QR code if editing -->
                                <div class="col-12" id="existingQrContainer" style="display: none;">
                                    <label class="form-label">Current QR Code:</label>
                                    <div class="mt-2">
                                        <img id="existingQrImage" src="" alt="UPI QR Code" style="max-width: 150px; max-height: 150px;" class="border rounded p-2">
                                        <div class="mt-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="remove_qr_code" id="removeQrCode" value="1">
                                                <label class="form-check-label text-danger" for="removeQrCode">
                                                    Remove current QR code
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Outstanding Tab -->
                        <div class="tab-pane fade" id="outstanding-tab-pane" role="tabpanel" tabindex="0">
                            <div class="alert alert-info">
                                <i class="bx bx-info-circle me-2"></i>
                                <strong>Note:</strong> Set initial outstanding balance when adding this supplier. 
                                This will create an entry in the outstanding history.
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><strong>Outstanding Type</strong></label>
                                    <select name="initial_outstanding_type" id="outstandingType" class="form-select" onchange="toggleOutstandingAmount()">
                                        <option value="none">No Outstanding</option>
                                        <option value="credit">Credit (Supplier owes you)</option>
                                        <option value="debit">Debit (You owe supplier)</option>
                                    </select>
                                    <small class="text-muted">
                                        <span class="text-success d-block mt-1"><i class="bx bx-up-arrow-alt"></i> Credit: Supplier owes money to you</span>
                                        <span class="text-danger d-block"><i class="bx bx-down-arrow-alt"></i> Debit: You owe money to supplier</span>
                                    </small>
                                </div>
                                <div class="col-md-6" id="amountField">
                                    <label class="form-label"><strong>Outstanding Amount (₹)</strong></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" name="initial_outstanding_amount" id="outstandingAmount" 
                                               class="form-control"
                                               placeholder="0.00"
                                               step="0.01"
                                               min="0">
                                    </div>
                                    <small class="text-muted">Enter the outstanding amount</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Common fields -->
                        <div class="row g-3 mt-3">
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" 
                                           name="is_active" id="isActive" 
                                           value="1" checked>
                                    <label class="form-check-label" for="isActive">
                                        <strong>Active Supplier</strong>
                                        <small class="text-muted d-block">Inactive suppliers won't appear in purchase forms</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bx bx-x me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bx bx-save me-2"></i> Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Supplier Modal -->
<div class="modal fade" id="viewSupplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bx bx-buildings me-2"></i> 
                    Supplier Details: <span id="viewSupplierName"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center" id="viewLoading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
                <div id="viewContent" style="display: none;">
                    <!-- Supplier details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Outstanding History Modal -->
<div class="modal fade" id="outstandingHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bx bx-history me-2"></i> 
                    Outstanding History: <span id="historySupplierName"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center" id="historyLoading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
                <div id="historyContent" style="display: none;">
                    <!-- History table will be loaded here via AJAX -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Make Payment Modal (Integrated with purchase_payment.php) -->
<div class="modal fade" id="makePaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bx bx-money me-2"></i> 
                    Make Payment to: <span id="paymentSupplierName"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Outstanding Summary Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bx bx-info-circle me-2"></i> Current Balance Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center p-2 bg-light rounded">
                                    <small class="text-muted d-block">Purchase Due</small>
                                    <strong class="fs-5 text-warning" id="summaryPurchaseDue">₹0.00</strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-2 bg-light rounded">
                                    <small class="text-muted d-block">Outstanding Balance</small>
                                    <strong class="fs-5" id="summaryOutstandingDue">₹0.00</strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-2 bg-success bg-opacity-10 rounded">
                                    <small class="text-muted d-block">Net Payable</small>
                                    <strong class="fs-5 text-success" id="summaryNetPayable">₹0.00</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Purchases List -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <i class="bx bx-cart me-2"></i> Pending Purchase Orders
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div id="pendingPurchasesLoading" class="text-center py-3">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                        <div id="pendingPurchasesContent" style="display: none;">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="50">
                                                <input type="checkbox" id="selectAllPurchases" class="form-check-input">
                                            </th>
                                            <th>PO Number</th>
                                            <th>Date</th>
                                            <th class="text-end">Total Amount</th>
                                            <th class="text-end">Paid</th>
                                            <th class="text-end">Balance Due</th>
                                        </tr>
                                    </thead>
                                    <tbody id="purchasesTableBody">
                                    </tbody>
                                    <tfoot id="purchasesTableFooter" style="display: none;">
                                        <tr class="table-info">
                                            <td colspan="5" class="text-end"><strong>Selected Total:</strong></td>
                                            <td class="text-end"><strong id="selectedTotal">₹0.00</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Form -->
                <form id="paymentForm">
                    <input type="hidden" name="manufacturer_id" id="paymentManufacturerId">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Method</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="upi">UPI</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        
                        <!-- Reference Number -->
                        <div class="col-md-6">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_no" class="form-control" 
                                   placeholder="Cheque/UPI/Transaction ID">
                        </div>
                        
                        <!-- Notes -->
                        <div class="col-md-6">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="1" 
                                      placeholder="Additional notes..."></textarea>
                        </div>
                        
                        <!-- Amount Input -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Payment Amount</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="amount" id="paymentAmount" 
                                       class="form-control" 
                                       step="0.01" min="0.01" 
                                       placeholder="Enter amount to pay" required>
                            </div>
                            <small class="text-muted" id="maxAmountHint">Maximum payable: ₹0.00</small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bx bx-x me-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" id="processPaymentBtn">
                    <i class="bx bx-check-circle me-1"></i> Process Payment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- QR Code Viewer Modal -->
<div class="modal fade" id="qrCodeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bx bx-qr me-2"></i>
                    UPI QR Code: <span id="qrSupplierName"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <img id="qrCodeImage" src="" alt="UPI QR Code" style="max-width: 250px;" class="img-fluid border rounded">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
let contactCount = 1;

$(document).ready(function() {

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Select2 initialization
    $('#filterForm select.form-select').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });

    $('#addManufacturerModal select.form-select').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#addManufacturerModal')
    });

    $('#makePaymentModal select.form-select').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#makePaymentModal')
    });

    // DataTables init
    if ($.fn.DataTable) {
        $('#suppliersTable').DataTable({
            responsive: true,
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [8] },
                { searchable: false, targets: [8] }
            ]
        });
    }

    // SweetAlert messages
    <?php if ($success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: <?= json_encode($success) ?>,
        timer: 3000,
        showConfirmButton: true
    });
    <?php endif; ?>

    <?php if ($error): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error!',
        text: <?= json_encode($error) ?>,
        timer: 3000,
        showConfirmButton: true
    });
    <?php endif; ?>

    // Export button
    $('#exportBtn').on('click', function() {
        const btn = this;
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
        btn.disabled = true;

        const params = new URLSearchParams(window.location.search);
        const exportUrl = 'manufacturers_export.php' + (params.toString() ? '?' + params.toString() : '');
        window.location = exportUrl;

        setTimeout(() => {
            btn.innerHTML = original;
            btn.disabled = false;
        }, 3000);
    });

    // Delete confirmation
    $(document).on('click', '.delete-supplier-btn', function(e) {
        e.preventDefault();
        const href = $(this).attr('href');
        const name = $(this).data('name');

        Swal.fire({
            title: 'Delete Supplier?',
            html: `Are you sure you want to delete <strong>${name}</strong>?<br><br>This action cannot be undone!`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = href;
            }
        });
    });

    // View Supplier Button Handler
    $(document).on('click', '.view-supplier-btn', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        
        $('#viewSupplierName').text(name);
        $('#viewLoading').show();
        $('#viewContent').hide();
        
        $.ajax({
            url: 'get_supplier_details.php',
            method: 'POST',
            data: { manufacturer_id: id },
            success: function(response) {
                $('#viewLoading').hide();
                $('#viewContent').html(response).show();
            },
            error: function() {
                $('#viewLoading').hide();
                $('#viewContent').html('<div class="alert alert-danger">Error loading supplier details</div>').show();
            }
        });
        
        const modal = new bootstrap.Modal(document.getElementById('viewSupplierModal'));
        modal.show();
    });

    // Outstanding History Button Handler
    $(document).on('click', '.view-outstanding-history-btn', function() {
        const manufacturerId = $(this).data('id');
        const manufacturerName = $(this).data('name');
        
        $('#historySupplierName').text(manufacturerName);
        $('#historyLoading').show();
        $('#historyContent').hide();
        
        $.ajax({
            url: 'get_outstanding_history.php',
            method: 'POST',
            data: { manufacturer_id: manufacturerId },
            success: function(response) {
                $('#historyLoading').hide();
                $('#historyContent').html(response).show();
            },
            error: function() {
                $('#historyLoading').hide();
                $('#historyContent').html('<div class="alert alert-danger">Error loading history</div>').show();
            }
        });
        
        const modal = new bootstrap.Modal(document.getElementById('outstandingHistoryModal'));
        modal.show();
    });

    // View QR Code
    $(document).on('click', '.view-qr-btn', function() {
        const qrPath = $(this).data('qr');
        const supplierName = $(this).data('name');
        
        $('#qrSupplierName').text(supplierName);
        $('#qrCodeImage').attr('src', qrPath);
        
        new bootstrap.Modal(document.getElementById('qrCodeModal')).show();
    });

    // Edit button handler
    $(document).on('click', '.edit-btn', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        const phone = $(this).data('phone');
        const email = $(this).data('email');
        const address = $(this).data('address');
        const gstin = $(this).data('gstin');
        const accountholder = $(this).data('accountholder');
        const bankname = $(this).data('bankname');
        const accountno = $(this).data('accountno');
        const ifsc = $(this).data('ifsc');
        const branch = $(this).data('branch');
        const upi = $(this).data('upi') || '';
        const qrCode = $(this).data('qr') || '';
        const outstandingType = $(this).data('outstanding-type');
        const outstandingAmount = $(this).data('outstanding-amount');
        const active = $(this).data('active');
        const shop = $(this).data('shop');

        $('#editId').val(id);
        $('#companyName').val(name);
        $('#phone').val(phone);
        $('#email').val(email);
        $('#address').val(address);
        $('#gstin').val(gstin);
        $('#accountHolderName').val(accountholder);
        $('#bankName').val(bankname);
        $('#accountNumber').val(accountno);
        $('#ifscCode').val(ifsc);
        $('#branchName').val(branch);
        $('#upiId').val(upi);
        
        // Handle existing QR code
        if (qrCode) {
            $('#existingQrContainer').show();
            $('#existingQrImage').attr('src', qrCode);
            $('#removeQrCode').prop('checked', false);
        } else {
            $('#existingQrContainer').hide();
        }
        
        $('#shopId').val(shop).trigger('change');
        $('#outstandingType').val(outstandingType);
        $('#outstandingAmount').val(outstandingAmount);
        $('#isActive').prop('checked', active == 1);
        
        loadContacts(id);
        toggleOutstandingAmount();
        
        $('#modalTitle').text('Edit Supplier');
        
        const modal = new bootstrap.Modal(document.getElementById('addManufacturerModal'));
        modal.show();
        
        $('.nav-tabs button[data-bs-target="#basic-tab-pane"]').tab('show');
    });

    // Add contact button
    $('#addContactBtn').click(function() {
        addNewContact();
    });

    // Reset modal when closed
    $('#addManufacturerModal').on('hidden.bs.modal', function () {
        $('#modalTitle').text('Add Supplier');
        $(this).find('form')[0].reset();
        $('#editId').val('');
        $('#isActive').prop('checked', true);
        $('#outstandingType').val('none');
        $('#shopId').val('').trigger('change');
        $('#existingQrContainer').hide();
        $('#removeQrCode').prop('checked', false);
        $('#upiId').val('');
        $('#upiQrCode').val('');
        toggleOutstandingAmount();
        $('#contactsContainer').empty();
        resetContacts();
    });

    // Make Payment button handler
    // Make Payment button handler
$(document).on('click', '.make-payment-btn', function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    const purchaseBalance = parseFloat($(this).data('purchase-balance') || 0);
    const outstandingAmount = parseFloat($(this).data('outstanding-amount') || 0);
    const outstandingType = String($(this).data('outstanding-type') || 'none');
    const netOutstanding = parseFloat($(this).data('net-outstanding') || 0);

    // Calculate net payable based on outstanding type
    let netPayable = purchaseBalance;
    if (outstandingType === 'debit') {
        netPayable += outstandingAmount; // We owe them, add to payable
    } else if (outstandingType === 'credit') {
        netPayable = Math.max(0, purchaseBalance - outstandingAmount); // They owe us, subtract from payable
    }

    $('#paymentForm')[0].reset();
    $('#paymentManufacturerId').val(id);
    $('#paymentSupplierName').text(name);
    
    // Update summary
    $('#summaryPurchaseDue').text('₹' + purchaseBalance.toFixed(2));
    $('#summaryOutstandingDue').text('₹' + outstandingAmount.toFixed(2) + (outstandingType !== 'none' ? ' (' + outstandingType + ')' : ''));
    $('#summaryNetPayable').text('₹' + netPayable.toFixed(2));
    
    $('#maxAmountHint').text('Maximum payable: ₹' + netPayable.toFixed(2));
    $('#paymentAmount').attr('max', netPayable);

    // Check if only outstanding exists (no purchase balance)
    if (purchaseBalance === 0 && outstandingAmount > 0 && outstandingType === 'debit') {
        // Outstanding only payment - hide purchase selection section
        $('#pendingPurchasesContent').hide();
        $('#pendingPurchasesLoading').hide();
        $('#purchasesTableFooter').hide();
        $('.card:has(#pendingPurchasesContent)').hide(); // Hide the pending purchases card
        
        // Update modal title to indicate outstanding payment
        $('.modal-header.bg-success h5.modal-title').html('<i class="bx bx-money me-2"></i> Pay Outstanding to: ' + name);
        
        // Show outstanding only message
        const outstandingMessage = `
            <div class="alert alert-info mb-3">
                <i class="bx bx-info-circle me-2"></i>
                This supplier has a debit outstanding of <strong>₹${outstandingAmount.toFixed(2)}</strong> with no pending purchase orders.
                The payment will be applied directly to the outstanding balance.
            </div>
        `;
        
        // Insert message after summary card
        $('.card.mb-4:first').after(outstandingMessage);
        
        // Auto-select "outstanding only" mode in the background
        $('#paymentForm').data('payment-mode', 'outstanding-only');
        
    } else {
        // Normal payment with purchases - show purchase selection
        $('.card:has(#pendingPurchasesContent)').show();
        $('#pendingPurchasesContent').hide();
        $('#purchasesTableFooter').hide();
        
        // Remove any outstanding message if exists
        $('.alert-info').remove();
        
        // Update modal title back to normal
        $('.modal-header.bg-success h5.modal-title').html('<i class="bx bx-money me-2"></i> Make Payment to: ' + name);
        
        // Load pending purchases
        loadPendingPurchases(id);
    }
    
    // Select All purchases checkbox
    $('#selectAllPurchases').prop('checked', false);

    const modal = new bootstrap.Modal(document.getElementById('makePaymentModal'));
    modal.show();
});

// Process payment button - updated to handle outstanding-only payments
$('#processPaymentBtn').on('click', function() {
    const selectedPurchases = [];
    $('.purchase-checkbox:checked').each(function() {
        selectedPurchases.push($(this).val());
    });

    const amount = parseFloat($('#paymentAmount').val() || 0);
    const maxAmount = parseFloat($('#paymentAmount').attr('max') || 0);
    const paymentMode = $('#paymentForm').data('payment-mode') || 'normal';
    
    // For outstanding-only mode, we don't need selected purchases
    if (paymentMode !== 'outstanding-only' && selectedPurchases.length === 0) {
        Swal.fire({
            icon: 'error',
            title: 'No Selection',
            text: 'Please select at least one purchase order to pay'
        });
        return;
    }

    if (amount <= 0) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Amount',
            text: 'Please enter a valid payment amount'
        });
        return;
    }

    if (amount > maxAmount) {
        Swal.fire({
            icon: 'error',
            title: 'Amount Exceeded',
            text: 'Payment amount cannot exceed ₹' + maxAmount.toFixed(2)
        });
        return;
    }

    // Show loading
    Swal.fire({
        title: 'Processing Payment',
        html: 'Please wait...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    // Prepare data for AJAX
    const postData = {
        manufacturer_id: $('#paymentManufacturerId').val(),
        payment_date: $('input[name="payment_date"]').val(),
        payment_method: $('select[name="payment_method"]').val(),
        amount: amount,
        reference_no: $('input[name="reference_no"]').val(),
        notes: $('textarea[name="notes"]').val()
    };
    
    // Only add purchases if in normal mode and purchases selected
    if (paymentMode !== 'outstanding-only' && selectedPurchases.length > 0) {
        postData.purchases = selectedPurchases;
        postData.outstanding_amount = 0; // No outstanding portion in this case
    } else {
        // Outstanding-only payment
        postData.purchases = [];
        postData.outstanding_amount = amount; // Entire amount goes to outstanding
    }

    // Process payment via AJAX
    $.ajax({
        url: 'process_supplier_payment.php',
        method: 'POST',
        data: postData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    const modalEl = document.getElementById('makePaymentModal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message
                });
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            console.error('Response:', xhr.responseText);
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to process payment. Please try again.'
            });
        }
    });
});

// Clear outstanding message when modal is hidden
$('#makePaymentModal').on('hidden.bs.modal', function () {
    $('.alert-info').remove();
    $('#pendingPurchasesContent').show();
    $('.card:has(#pendingPurchasesContent)').show();
    $('#paymentForm').removeData('payment-mode');
    
    // Reset modal title
    $('.modal-header.bg-success h5.modal-title').html('<i class="bx bx-money me-2"></i> Make Payment to: <span id="paymentSupplierName"></span>');
});

    // Select all purchases
    $(document).on('change', '#selectAllPurchases', function() {
        $('.purchase-checkbox').prop('checked', $(this).is(':checked'));
        updateSelectedTotal();
    });

    // Individual purchase checkbox
    $(document).on('change', '.purchase-checkbox', function() {
        updateSelectedTotal();
        const allChecked = $('.purchase-checkbox:checked').length === $('.purchase-checkbox').length;
        $('#selectAllPurchases').prop('checked', allChecked);
    });

    // Process payment button
    $('#processPaymentBtn').on('click', function() {
        const selectedPurchases = [];
        $('.purchase-checkbox:checked').each(function() {
            selectedPurchases.push($(this).val());
        });

        const amount = parseFloat($('#paymentAmount').val() || 0);
        const maxAmount = parseFloat($('#paymentAmount').attr('max') || 0);
        
        if (selectedPurchases.length === 0) {
            Swal.fire({
                icon: 'error',
                title: 'No Selection',
                text: 'Please select at least one purchase order to pay'
            });
            return;
        }

        if (amount <= 0) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Amount',
                text: 'Please enter a valid payment amount'
            });
            return;
        }

        if (amount > maxAmount) {
            Swal.fire({
                icon: 'error',
                title: 'Amount Exceeded',
                text: 'Payment amount cannot exceed ₹' + maxAmount.toFixed(2)
            });
            return;
        }

        // Show loading
        Swal.fire({
            title: 'Processing Payment',
            html: 'Please wait...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Process payment via AJAX
        $.ajax({
            url: 'process_supplier_payment.php',
            method: 'POST',
            data: {
                manufacturer_id: $('#paymentManufacturerId').val(),
                payment_date: $('input[name="payment_date"]').val(),
                payment_method: $('select[name="payment_method"]').val(),
                amount: amount,
                reference_no: $('input[name="reference_no"]').val(),
                notes: $('textarea[name="notes"]').val(),
                purchases: selectedPurchases
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        const modalEl = document.getElementById('makePaymentModal');
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: response.message
                    });
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                console.error('Response:', xhr.responseText);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Failed to process payment. Please try again.'
                });
            }
        });
    });

    // Primary checkbox change
    $(document).on('change', '.primary-checkbox', function() {
        updatePrimaryCheckboxes($(this));
    });

});

// ----- Pending purchases loading -----
function loadPendingPurchases(manufacturerId) {
    $('#pendingPurchasesLoading').show();
    $('#pendingPurchasesContent').hide();
    $('#purchasesTableFooter').hide();

    $.ajax({
        url: 'get_pending_purchases.php',
        method: 'POST',
        data: { manufacturer_id: manufacturerId },
        dataType: 'json',
        success: function(response) {
            $('#pendingPurchasesLoading').hide();

            if (response.success && response.purchases.length > 0) {
                let html = '';
                let totalBalance = 0;

                response.purchases.forEach(function(purchase) {
                    totalBalance += purchase.balance_due;
                    html += `
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input purchase-checkbox" 
                                       value="${purchase.id}" data-amount="${purchase.balance_due}">
                            </td>
                            <td>
                                <strong>${purchase.purchase_number}</strong>
                            </td>
                            <td>${purchase.purchase_date}</td>
                            <td class="text-end">₹${parseFloat(purchase.total_amount).toFixed(2)}</td>
                            <td class="text-end">₹${parseFloat(purchase.paid_amount).toFixed(2)}</td>
                            <td class="text-end text-warning fw-bold">₹${parseFloat(purchase.balance_due).toFixed(2)}</td>
                        </tr>
                    `;
                });

                $('#purchasesTableBody').html(html);
                $('#selectedTotal').text('₹0.00');
                $('#purchasesTableFooter').show();
                $('#pendingPurchasesContent').show();

                // Update max amount hint
                const maxAmount = parseFloat($('#summaryNetPayable').text().replace('₹', ''));
                $('#maxAmountHint').text('Maximum payable: ₹' + maxAmount.toFixed(2));
                $('#paymentAmount').attr('max', maxAmount);

                // Attach change event to checkboxes
                $('.purchase-checkbox').on('change', updateSelectedTotal);
            } else {
                $('#purchasesTableBody').html(`
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            <i class="bx bx-info-circle fs-4 text-muted mb-2"></i>
                            <p class="text-muted">No pending purchases found for this supplier.</p>
                        </td>
                    </tr>
                `);
                $('#purchasesTableFooter').hide();
                $('#pendingPurchasesContent').show();
            }
        },
        error: function() {
            $('#pendingPurchasesLoading').hide();
            $('#purchasesTableBody').html(`
                <tr>
                    <td colspan="6" class="text-center py-4 text-danger">
                        <i class="bx bx-error-circle fs-4 mb-2"></i>
                        <p>Error loading purchases. Please try again.</p>
                    </td>
                </tr>
            `);
            $('#purchasesTableFooter').hide();
            $('#pendingPurchasesContent').show();
        }
    });
}

// Update selected total
function updateSelectedTotal() {
    let total = 0;
    $('.purchase-checkbox:checked').each(function() {
        total += parseFloat($(this).data('amount') || 0);
    });
    $('#selectedTotal').text('₹' + total.toFixed(2));
    
    // Auto-fill payment amount with total if not set
    const currentAmount = parseFloat($('#paymentAmount').val() || 0);
    if (currentAmount === 0 && total > 0) {
        $('#paymentAmount').val(total.toFixed(2));
    }
}

// ----- Contact Management Functions (keep existing) -----
function addNewContact(contactData = null) {
    const index = contactCount++;
    const isPrimary = contactData ? contactData.is_primary : false;
    const isFirst = $('#contactsContainer .contact-entry').length === 0;
    
    const html = `
        <div class="contact-entry card mb-3" data-index="${index}">
            <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold">Contact Person #${index + 1}</span>
                <div>
                    <span class="badge bg-primary primary-badge" style="${isPrimary ? '' : 'display: none;'}">Primary</span>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-contact">
                        <i class="bx bx-trash"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contacts[${index}][contact_person]" class="form-control form-control-sm contact-person" value="${contactData ? (contactData.contact_person || '') : ''}" placeholder="Full Name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Designation</label>
                        <input type="text" name="contacts[${index}][designation]" class="form-control form-control-sm" value="${contactData ? (contactData.designation || '') : ''}" placeholder="e.g., Manager">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="contacts[${index}][phone]" class="form-control form-control-sm" value="${contactData ? (contactData.phone || '') : ''}" placeholder="Phone number">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Mobile</label>
                        <input type="text" name="contacts[${index}][mobile]" class="form-control form-control-sm" value="${contactData ? (contactData.mobile || '') : ''}" placeholder="Mobile">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="contacts[${index}][email]" class="form-control form-control-sm" value="${contactData ? (contactData.email || '') : ''}" placeholder="Email address">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">&nbsp;</label>
                        <div class="form-check">
                            <input type="checkbox" name="contacts[${index}][is_primary]" class="form-check-input primary-checkbox" value="1" ${isPrimary ? 'checked' : ''}>
                            <label class="form-check-label">Set as primary contact</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    $('#contactsContainer').append(html);
    
    if (isFirst || isPrimary) {
        updatePrimaryCheckboxes($('#contactsContainer .contact-entry:last-child .primary-checkbox'));
    }
    
    attachRemoveHandler();
}

function resetContacts() {
    contactCount = 1;
    $('#contactsContainer').empty();
    addNewContact();
}

function attachRemoveHandler() {
    $('.remove-contact').off('click').on('click', function() {
        const entry = $(this).closest('.contact-entry');
        if ($('#contactsContainer .contact-entry').length > 1) {
            entry.remove();
            renumberContacts();
        }
    });
}

function renumberContacts() {
    $('#contactsContainer .contact-entry').each(function(index) {
        $(this).attr('data-index', index);
        $(this).find('.card-header span.fw-bold').text(`Contact Person #${index + 1}`);
        
        $(this).find('input, select').each(function() {
            const name = $(this).attr('name');
            if (name) {
                const newName = name.replace(/contacts\[\d+\]/, `contacts[${index}]`);
                $(this).attr('name', newName);
            }
        });
    });
}

function loadContacts(manufacturerId) {
    $.ajax({
        url: 'get_manufacturer_contacts.php',
        method: 'POST',
        data: { manufacturer_id: manufacturerId },
        dataType: 'json',
        success: function(contacts) {
            $('#contactsContainer').empty();
            contactCount = 0;
            
            if (contacts && contacts.length > 0) {
                contacts.forEach(function(contact) {
                    addNewContact(contact);
                });
            } else {
                resetContacts();
            }
        },
        error: function() {
            resetContacts();
        }
    });
}

function updatePrimaryCheckboxes(clickedCheckbox) {
    if (clickedCheckbox.prop('checked')) {
        $('.primary-checkbox').not(clickedCheckbox).prop('checked', false);
        
        $('.contact-entry').each(function() {
            const hasPrimary = $(this).find('.primary-checkbox').prop('checked');
            $(this).find('.primary-badge').toggle(hasPrimary);
        });
    }
}

function toggleOutstandingAmount() {
    const type = $('#outstandingType').val();
    const amountField = $('#amountField');
    
    if (type === 'none') {
        amountField.hide();
        $('#outstandingAmount').val('').prop('required', false);
    } else {
        amountField.show();
        $('#outstandingAmount').prop('required', true);
    }
}

</script>

<style>
.action-buttons {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    justify-content: center;
}
.action-buttons .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    border-radius: 4px;
    min-width: 32px;
}
.action-buttons .btn:hover {
    transform: translateY(-2px);
    transition: transform 0.2s;
}
.select2-container--bootstrap-5 .select2-selection { 
    min-height: 38px; 
}
.nav-tabs-custom .nav-link {
    padding: 0.75rem 1.5rem;
    font-weight: 500;
}
.nav-tabs-custom .nav-link.active {
    border-bottom: 3px solid #5b73e8;
}
.avatar-sm .bg-primary {
    transition: all 0.3s ease;
}
.supplier-row:hover .avatar-sm .bg-primary {
    transform: scale(1.1);
}
.empty-state {
    text-align: center;
    padding: 2rem;
}
.empty-state i {
    font-size: 4rem;
    opacity: 0.5;
}
.avatar-sm {
    width: 48px;
    height: 48px;
}
.badge.bg-opacity-10 {
    opacity: 0.9;
}
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
}
.form-switch .form-check-input:checked {
    background-color: #5b73e8;
    border-color: #5b73e8;
}
#amountField {
    display: none;
}
.contact-entry {
    transition: all 0.3s ease;
}
.contact-entry:hover {
    border-color: #5b73e8;
}
.primary-badge {
    margin-right: 10px;
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
@media (max-width: 768px) {
    .action-buttons {
        flex-direction: row;
        flex-wrap: wrap;
    }
}
</style>

</body>
</html>