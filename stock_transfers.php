<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['admin', 'warehouse_manager', 'stock_manager', 'shop_manager'];
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "You don't have permission to view transfers.";
    header('Location: dashboard.php');
    exit();
}

// Get current business ID
$current_business_id = $_SESSION['current_business_id'] ?? null;
if (!$current_business_id) {
    $_SESSION['error'] = "Please select a business first.";
    header('Location: select_shop.php');
    exit();
}

// === FILTERS & SEARCH ===
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE conditions
$where = "WHERE st.business_id = ?";
$params = [$current_business_id];

if ($search !== '') {
    $where .= " AND (st.transfer_number LIKE ? OR fs.shop_name LIKE ? OR ts.shop_name LIKE ? OR u.full_name LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($status_filter !== 'all') {
    $where .= " AND st.status = ?";
    $params[] = $status_filter;
}

if ($from_date !== '') {
    $where .= " AND DATE(st.transfer_date) >= ?";
    $params[] = $from_date;
}

if ($to_date !== '') {
    $where .= " AND DATE(st.transfer_date) <= ?";
    $params[] = $to_date;
}

try {
    // Stats - with filters
    $stats_sql = "
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN st.status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN st.status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN st.status = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
            SUM(CASE WHEN st.status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN st.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            COALESCE(SUM(sti.quantity), 0) as total_items,
            COALESCE(SUM(p.retail_price * sti.quantity), 0) as total_value
        FROM stock_transfers st
        LEFT JOIN stock_transfer_items sti ON st.id = sti.stock_transfer_id AND sti.business_id = st.business_id
        LEFT JOIN products p ON sti.product_id = p.id AND p.business_id = st.business_id
        $where
    ";
    
    $stmt = $pdo->prepare($stats_sql);
    $stmt->execute($params);
    $status_counts = $stmt->fetch();

    // Main query with pagination
    $sql = "
        SELECT 
            st.*,
            fs.shop_name as from_shop_name,
            ts.shop_name as to_shop_name,
            u.full_name as created_by_name,
            COALESCE(SUM(sti.quantity), 0) as item_count,
            COALESCE(COUNT(DISTINCT sti.product_id), 0) as unique_items,
            COALESCE(SUM(p.retail_price * sti.quantity), 0) as estimated_value
        FROM stock_transfers st
        LEFT JOIN shops fs ON st.from_shop_id = fs.id AND fs.business_id = st.business_id
        LEFT JOIN shops ts ON st.to_shop_id = ts.id AND ts.business_id = st.business_id
        LEFT JOIN users u ON st.created_by = u.id
        LEFT JOIN stock_transfer_items sti ON st.id = sti.stock_transfer_id AND sti.business_id = st.business_id
        LEFT JOIN products p ON sti.product_id = p.id AND p.business_id = st.business_id
        $where
        GROUP BY st.id
        ORDER BY st.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transfers = $stmt->fetchAll();

    // Total count for pagination
    $count_sql = "
        SELECT COUNT(DISTINCT st.id)
        FROM stock_transfers st
        $where
    ";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_transfers = $count_stmt->fetchColumn();
    $total_pages = ceil($total_transfers / $limit);

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $status_counts = ['total' => 0, 'pending' => 0, 'approved' => 0, 'in_transit' => 0, 'delivered' => 0, 'cancelled' => 0, 'total_items' => 0, 'total_value' => 0];
    $transfers = [];
    $total_transfers = 0;
    $total_pages = 1;
}
?>
<!doctype html>
<html lang="en">
<?php 
$page_title = "Stock Transfers";
include 'includes/head.php'; 
?>
<!-- Add DataTables and Select2 CSS -->
<link href="assets/libs/datatables.net-bs5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="assets/libs/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
    border-radius: 0.375rem;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}
</style>
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
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-transfer-alt me-2"></i> Stock Transfers
                                <small class="text-muted ms-2">
                                    <i class="bx bx-store me-1"></i>
                                    <?= htmlspecialchars($_SESSION['current_shop_name'] ?? 'All Shops') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" onclick="exportTransfers()">
                                    <i class="bx bx-export me-1"></i> Export
                                </button>
                                <a href="stock_transfer_add.php" class="btn btn-primary">
                                    <i class="bx bx-plus-circle me-1"></i> New Transfer
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Transfers</h6>
                                        <h3 class="mb-0 text-primary"><?= $status_counts['total'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-transfer-alt text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Items</h6>
                                        <h3 class="mb-0 text-success"><?= number_format($status_counts['total_items'] ?? 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-package text-success"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Value</h6>
                                        <h3 class="mb-0 text-info">₹<?= number_format($status_counts['total_value'] ?? 0, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Pending</h6>
                                        <h3 class="mb-0 text-warning"><?= $status_counts['pending'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-time text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Transfers
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
                                               placeholder="Transfer No, Shop, Created By..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select select2-status">
                                        <option value="all" <?= $status_filter=='all'?'selected':'' ?>>All Status</option>
                                        <option value="pending" <?= $status_filter=='pending'?'selected':'' ?>>Pending</option>
                                        <option value="approved" <?= $status_filter=='approved'?'selected':'' ?>>Approved</option>
                                        <option value="in_transit" <?= $status_filter=='in_transit'?'selected':'' ?>>In Transit</option>
                                        <option value="delivered" <?= $status_filter=='delivered'?'selected':'' ?>>Delivered</option>
                                        <option value="cancelled" <?= $status_filter=='cancelled'?'selected':'' ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="from_date" class="form-control" 
                                           value="<?= htmlspecialchars($from_date) ?>">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="to_date" class="form-control" 
                                           value="<?= htmlspecialchars($to_date) ?>">
                                </div>
                                <div class="col-lg-3 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($search || $status_filter != 'all' || $from_date || $to_date): ?>
                                        <a href="stock_transfers.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Transfers Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="transfersTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Transfer Details</th>
                                        <th class="text-center">From → To</th>
                                        <th class="text-end">Items / Value</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Created By</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($transfers)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bx bx-transfer-alt fs-1 text-muted mb-3"></i>
                                                <h5>No Transfers Found</h5>
                                                <p class="text-muted">Try adjusting your filters or create a new transfer</p>
                                                <a href="stock_transfer_add.php" class="btn btn-primary">
                                                    <i class="bx bx-plus me-1"></i> Create New Transfer
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($transfers as $t): 
                                        // Determine status badge class
                                        $badge_class = match($t['status']) {
                                            'pending' => 'warning',
                                            'approved' => 'info',
                                            'in_transit' => 'primary',
                                            'delivered' => 'success',
                                            'cancelled' => 'danger',
                                            default => 'secondary'
                                        };
                                        
                                        $status_icon = match($t['status']) {
                                            'pending' => 'bx bx-time',
                                            'approved' => 'bx bx-check-circle',
                                            'in_transit' => 'bx bx-truck',
                                            'delivered' => 'bx bx-check-double',
                                            'cancelled' => 'bx bx-x-circle',
                                            default => 'bx bx-info-circle'
                                        };
                                        
                                        $status_text = ucwords(str_replace('_', ' ', $t['status']));
                                    ?>
                                    <tr class="transfer-row" data-id="<?= $t['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="avatar-title bg-primary bg-opacity-10 text-primary rounded">
                                                        <i class="bx bx-transfer-alt fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1 text-primary"><?= htmlspecialchars($t['transfer_number']) ?></strong>
                                                    <small class="text-muted">
                                                        <i class="bx bx-calendar me-1"></i>
                                                        <?= date('d M Y', strtotime($t['transfer_date'])) ?>
                                                    </small>
                                                    <?php if (!empty($t['notes'])): ?>
                                                    <br><small class="text-muted">
                                                        <?= htmlspecialchars(substr($t['notes'], 0, 60)) ?><?= strlen($t['notes']) > 60 ? '...' : '' ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column align-items-center">
                                                <div class="d-flex align-items-center mb-1">
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary px-2">
                                                        <?= htmlspecialchars($t['from_shop_name']) ?>
                                                    </span>
                                                    <i class="bx bx-arrow-right mx-2 text-muted"></i>
                                                    <span class="badge bg-primary bg-opacity-10 text-primary px-2">
                                                        <?= htmlspecialchars($t['to_shop_name']) ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($t['expected_delivery_date'])): ?>
                                                <small class="text-muted">
                                                    <i class="bx bx-calendar-event me-1"></i>
                                                    Expected: <?= date('d M', strtotime($t['expected_delivery_date'])) ?>
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex flex-column align-items-end">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-1 mb-1">
                                                    <i class="bx bx-package me-1"></i>
                                                    <?= $t['unique_items'] ?> items
                                                </span>
                                                <div>
                                                    <small class="text-muted me-2">Qty: <?= $t['item_count'] ?></small>
                                                    <?php if (!empty($t['estimated_value'])): ?>
                                                    <strong class="text-success">₹<?= number_format($t['estimated_value'], 0) ?></strong>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $badge_class ?> bg-opacity-10 text-<?= $badge_class ?> px-3 py-1">
                                                <i class="<?= $status_icon ?> me-1"></i><?= $status_text ?>
                                            </span>
                                            
                                            <?php if (in_array($user_role, ['admin', 'warehouse_manager', 'stock_manager']) && $t['status'] !== 'delivered' && $t['status'] !== 'cancelled'): ?>
                                            <div class="mt-2">
                                                <select class="form-select form-select-sm select2-status-dropdown" 
                                                        style="width: 120px;" 
                                                        data-transfer-id="<?= $t['id'] ?>"
                                                        data-current-status="<?= $t['status'] ?>">
                                                    <option value="">Change Status</option>
                                                    <?php 
                                                    $available_statuses = [];
                                                    if ($user_role === 'admin') {
                                                        $available_statuses = ['pending', 'approved', 'in_transit', 'delivered', 'cancelled'];
                                                    } elseif (in_array($user_role, ['warehouse_manager', 'stock_manager'])) {
                                                        if ($t['status'] === 'pending') $available_statuses = ['approved', 'cancelled'];
                                                        elseif ($t['status'] === 'approved') $available_statuses = ['in_transit', 'cancelled'];
                                                        elseif ($t['status'] === 'in_transit') $available_statuses = ['delivered'];
                                                    }
                                                    
                                                    foreach($available_statuses as $status): 
                                                        if ($status === $t['status']) continue;
                                                        $status_option_text = ucwords(str_replace('_', ' ', $status));
                                                    ?>
                                                    <option value="<?= $status ?>">
                                                        <?= $status_option_text ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex flex-column align-items-center">
                                                <small class="fw-medium"><?= htmlspecialchars($t['created_by_name'] ?? '—') ?></small>
                                                <small class="text-muted">
                                                    <?= date('h:i A', strtotime($t['created_at'])) ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="stock_transfer_view.php?id=<?= $t['id'] ?>"
                                                   class="btn btn-outline-info"
                                                   data-bs-toggle="tooltip"
                                                   title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </a>
                                                
                                                <?php if ($t['status'] === 'pending' && in_array($user_role, ['admin', 'warehouse_manager'])): ?>
                                                <a href="stock_transfer_edit.php?id=<?= $t['id'] ?>"
                                                   class="btn btn-outline-warning"
                                                   data-bs-toggle="tooltip"
                                                   title="Edit Transfer">
                                                    <i class="bx bx-edit"></i>
                                                </a>
                                                <?php endif; ?>
                                                
                                                <?php if (in_array($user_role, ['admin', 'warehouse_manager'])): ?>
                                                <button type="button"
                                                        class="btn btn-outline-danger delete-transfer-btn"
                                                        data-id="<?= $t['id'] ?>"
                                                        data-number="<?= htmlspecialchars($t['transfer_number']) ?>"
                                                        data-bs-toggle="tooltip"
                                                        title="Delete Transfer">
                                                    <i class="bx bx-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <div class="text-muted">
                                Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_transfers) ?> of <?= $total_transfers ?> transfers
                            </div>
                            <nav>
                                <ul class="pagination justify-content-center mb-0">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">
                                            <i class="bx bx-chevrons-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">
                                            <i class="bx bx-chevron-left"></i>
                                        </a>
                                    </li>
                                    
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">
                                            <i class="bx bx-chevron-right"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                                            <i class="bx bx-chevrons-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php include('includes/footer.php') ?>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusUpdateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Transfer Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="statusUpdateForm">
                    <input type="hidden" id="updateTransferId">
                    <input type="hidden" id="updateCurrentStatus">
                    
                    <div class="mb-3">
                        <label class="form-label">Transfer Number</label>
                        <input type="text" id="transferNumberDisplay" class="form-control bg-light" readonly>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">Current Status</label>
                            <input type="text" id="currentStatusDisplay" class="form-control bg-light" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label">New Status <span class="text-danger">*</span></label>
                            <select id="newStatusSelect" class="form-select" required>
                                <option value="">Select Status</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="in_transit">In Transit</option>
                                <option value="delivered">Delivered</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea id="statusNotes" class="form-control" rows="3" 
                                  placeholder="Add any notes about this status change..."></textarea>
                    </div>
                    
                    <div class="alert alert-warning d-none" id="deliveredWarning">
                        <i class="bx bx-error-circle me-2"></i>
                        <strong>Warning:</strong> Changing status to "Delivered" will update stock levels permanently!
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveStatusBtn">
                    <i class="bx bx-check me-1"></i> Update Status
                </button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<!-- Add DataTables and Select2 JS -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs5/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#transfersTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search transfers:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ transfers",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        },
        columnDefs: [
            {
                targets: [2], // Items/Value column
                className: 'dt-body-right'
            },
            {
                targets: [3, 4, 5], // Status, Created By, Actions columns
                className: 'dt-body-center'
            }
        ]
    });

    // Initialize Select2
    $('.select2-status').select2({
        placeholder: "Select status...",
        allowClear: true,
        width: '100%',
        theme: 'classic'
    });
    
    $('.select2-status-dropdown').select2({
        placeholder: "Change Status",
        width: '120px',
        minimumResultsForSearch: -1,
        theme: 'classic'
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Status dropdown change handler
    $(document).on('change', '.select2-status-dropdown', function() {
        const transferId = $(this).data('transfer-id');
        const currentStatus = $(this).data('current-status');
        const newStatus = $(this).val();
        
        if (!newStatus) return;
        
        // Get transfer details
        const transferRow = $(this).closest('.transfer-row');
        const transferNumber = transferRow.find('.text-primary').text().trim();
        
        // Set modal values
        $('#updateTransferId').val(transferId);
        $('#updateCurrentStatus').val(currentStatus);
        $('#transferNumberDisplay').val(transferNumber);
        $('#currentStatusDisplay').val(currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1));
        $('#newStatusSelect').val(newStatus).trigger('change');
        $('#statusNotes').val('');
        
        // Show/hide warning
        if (newStatus === 'delivered') {
            $('#deliveredWarning').removeClass('d-none');
        } else {
            $('#deliveredWarning').addClass('d-none');
        }
        
        // Show modal
        $('#statusUpdateModal').modal('show');
        
        // Reset dropdown
        $(this).val('').trigger('change');
    });

    // Save status button
    $('#saveStatusBtn').click(function() {
        const transferId = $('#updateTransferId').val();
        const newStatus = $('#newStatusSelect').val();
        const notes = $('#statusNotes').val();
        
        if (!newStatus) {
            showToast('error', 'Please select a new status');
            return;
        }
        
        // Show loading
        const originalText = $(this).html();
        $(this).html('<i class="bx bx-loader bx-spin me-1"></i> Updating...').prop('disabled', true);
        
        const formData = new FormData();
        formData.append('action', 'status_update');
        formData.append('id', transferId);
        formData.append('status', newStatus);
        formData.append('notes', notes);

        fetch('stock_transfer_action.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('success', 'Status updated successfully!');
                $('#statusUpdateModal').modal('hide');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', data.message || 'Error updating status');
                $(this).html(originalText).prop('disabled', false);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', 'Network error. Please try again.');
            $(this).html(originalText).prop('disabled', false);
        });
    });

    // Delete transfer button
    $('.delete-transfer-btn').click(function() {
        const transferId = $(this).data('id');
        const transferNumber = $(this).data('number');
        
        if (confirm(`Are you sure you want to delete transfer ${transferNumber}?\n\n⚠️ This action cannot be undone!`)) {
            deleteTransfer(transferId);
        }
    });

    // Real-time search debounce
    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => $('#filterForm').submit(), 500);
    });

    // Row hover effect
    $('.transfer-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // New status select change handler
    $('#newStatusSelect').change(function() {
        if ($(this).val() === 'delivered') {
            $('#deliveredWarning').removeClass('d-none');
        } else {
            $('#deliveredWarning').addClass('d-none');
        }
    });
});

function exportTransfers() {
    const exportBtn = document.querySelector('.btn-outline-secondary');
    const originalText = exportBtn.innerHTML;
    exportBtn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
    exportBtn.disabled = true;
    
    const params = new URLSearchParams(window.location.search);
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'export-transfers.php';
    
    params.forEach((value, key) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    });
    
    const exportType = document.createElement('input');
    exportType.type = 'hidden';
    exportType.name = 'export_type';
    exportType.value = 'csv';
    form.appendChild(exportType);
    
    document.body.appendChild(form);
    form.submit();
    
    setTimeout(() => {
        exportBtn.innerHTML = originalText;
        exportBtn.disabled = false;
    }, 3000);
}

function deleteTransfer(id) {
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    fetch('stock_transfer_action.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'Transfer deleted successfully!');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('error', data.message || 'Error deleting transfer');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('error', 'Network error. Please try again.');
    });
}

function showToast(type, message) {
    $('.toast').remove();
    const toast = $(`<div class="toast align-items-center text-bg-${type} border-0" role="alert"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
    if ($('.toast-container').length === 0) $('body').append('<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999"></div>');
    $('.toast-container').append(toast);
    new bootstrap.Toast(toast[0]).show();
}
</script>

<style>
.table-hover tbody tr:hover {
    background-color: rgba(91, 115, 232, 0.05) !important;
}
.border-start {
    border-left-width: 4px !important;
}
.card-hover {
    transition: all 0.3s ease;
}
.card-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}
.avatar-sm {
    width: 40px;
    height: 40px;
}
.avatar-title {
    display: flex;
    align-items: center;
    justify-content: center;
}
.empty-state {
    padding: 3rem 1rem;
    text-align: center;
}
.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
}
.empty-state h5 {
    margin-bottom: 0.5rem;
}
.empty-state p {
    margin-bottom: 1.5rem;
}
.btn-group .btn {
    border-radius: 0.25rem !important;
}
.btn-group .btn:first-child {
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
}
.btn-group .btn:last-child {
    border-top-left-radius: 0 !important;
    border-bottom-left-radius: 0 !important;
}
.select2-status-dropdown {
    font-size: 12px;
}
</style>
</body>
</html>