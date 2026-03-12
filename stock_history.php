<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Authorization (Admin + Warehouse + Shop Managers + Stock Managers)
$allowed_roles = ['admin', 'warehouse_manager', 'shop_manager', 'stock_manager'];
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. You don't have permission to view stock history.";
    header('Location: dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_shop_id = $_SESSION['current_shop_id'] ?? $_SESSION['shop_id'] ?? null;

// === FILTERS ===
$search     = trim($_GET['search'] ?? '');
$location   = (int)($_GET['location'] ?? 0);
$reason     = $_GET['reason'] ?? '';
$date_from  = $_GET['date_from'] ?? '';
$date_to    = $_GET['date_to'] ?? '';

// Default date range: last 30 days
if (empty($date_from)) $date_from = date('Y-m-d', strtotime('-30 days'));
if (empty($date_to)) $date_to = date('Y-m-d');

// Build WHERE conditions
$where = "WHERE p.business_id = ?";
$params = [$business_id];

// If user is not admin and has a shop, filter by their shop
if ($user_role !== 'admin' && $user_shop_id) {
    $where .= " AND sa.shop_id = ?";
    $params[] = $user_shop_id;
    // Override location filter to user's shop only
    $location = $user_shop_id;
}

if ($search) {
    $where .= " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR s.shop_name LIKE ?)";
    $like = "%$search%";
    $params[] = $like; 
    $params[] = $like;
    $params[] = $like;
}

if ($location > 0 && ($user_role === 'admin' || $user_role === 'warehouse_manager')) {
    $where .= " AND sa.shop_id = ?";
    $params[] = $location;
}

if ($reason && $reason !== 'all') {
    $where .= " AND sa.adjustment_type = ?";
    $params[] = $reason;
}

if ($date_from) { 
    $where .= " AND DATE(sa.adjusted_at) >= ?"; 
    $params[] = $date_from; 
}

if ($date_to) { 
    $where .= " AND DATE(sa.adjusted_at) <= ?"; 
    $params[] = $date_to; 
}

// Fetch History
$query = "
    SELECT
        sa.id,
        sa.adjusted_at,
        sa.adjustment_type,
        sa.quantity,
        sa.old_stock,
        sa.new_stock,
        sa.reason,
        sa.notes,
        p.product_name,
        p.product_code,
        s.shop_name,
        u.full_name AS adjusted_by_name
    FROM stock_adjustments sa
    JOIN products p ON sa.product_id = p.id AND p.business_id = ?
    JOIN shops s ON sa.shop_id = s.id AND s.business_id = ?
    JOIN users u ON sa.adjusted_by = u.id AND u.business_id = ?
    $where
    ORDER BY sa.adjusted_at DESC
    LIMIT 1000
";

// Add business_id params for joins
$full_params = array_merge([$business_id, $business_id, $business_id], $params);

$stmt = $pdo->prepare($query);
$stmt->execute($full_params);
$history = $stmt->fetchAll();

// Fetch Shops for Filter (only from current business)
$shop_query = "SELECT id, shop_name FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name";
$shop_stmt = $pdo->prepare($shop_query);
$shop_stmt->execute([$business_id]);
$shops = $shop_stmt->fetchAll();

// Adjustment Types (for filter)
$adjustment_types = [
    'add'           => 'Stock Added',
    'remove'        => 'Stock Removed',
    'damage'        => 'Damaged',
    'expiry'        => 'Expired',
    'correction'    => 'Correction',
    'transfer_in'   => 'Transfer In',
    'transfer_out'  => 'Transfer Out'
];

// Stats calculation
$total_adjustments = count($history);
$stock_added = 0;
$stock_removed = 0;

foreach ($history as $h) {
    if (in_array($h['adjustment_type'], ['add', 'transfer_in'])) {
        $stock_added += $h['quantity'];
    } elseif (in_array($h['adjustment_type'], ['remove', 'damage', 'expiry', 'transfer_out'])) {
        $stock_removed += $h['quantity'];
    }
}

$unique_products = count(array_unique(array_column($history, 'product_name')));
$unique_shops = count(array_unique(array_column($history, 'shop_name')));

// Messages
$success = $_SESSION['success'] ?? ''; unset($_SESSION['success']);
$error = $_SESSION['error'] ?? ''; unset($_SESSION['error']);
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Stock Movement History"; 
include 'includes/head.php'; 
?>
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
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-history me-2"></i> Stock Movement History
                                <small class="text-muted ms-2">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <?php if (!empty($history)): ?>
                                <button onclick="window.print()" class="btn btn-outline-secondary">
                                    <i class="bx bx-printer me-1"></i> Print Report
                                </button>
                                <button onclick="exportStockHistory()" class="btn btn-primary">
                                    <i class="bx bx-download me-1"></i> Export Excel
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Stock Movements
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
                                               placeholder="Product name, code, or location..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <?php if ($user_role === 'admin' || $user_role === 'warehouse_manager'): ?>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Location</label>
                                    <select name="location" class="form-select">
                                        <option value="">All Locations</option>
                                        <?php foreach ($shops as $shop): ?>
                                        <option value="<?= $shop['id'] ?>" <?= $location == $shop['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($shop['shop_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                <input type="hidden" name="location" value="<?= $user_shop_id ?>">
                                <?php endif; ?>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Adjustment Type</label>
                                    <select name="reason" class="form-select">
                                        <option value="all">All Types</option>
                                        <?php foreach ($adjustment_types as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= $reason === $key ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>" max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>" max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-lg-1 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply
                                        </button>
                                        <?php if ($search || $location || ($reason && $reason !== 'all') || $date_from != date('Y-m-d', strtotime('-30 days')) || $date_to != date('Y-m-d')): ?>
                                        <a href="stock_history.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Adjustments</h6>
                                        <h3 class="mb-0 text-primary"><?= number_format($total_adjustments) ?></h3>
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
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Stock Added</h6>
                                        <h3 class="mb-0 text-success">+<?= number_format($stock_added) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-up-arrow-alt text-success"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Stock Removed</h6>
                                        <h3 class="mb-0 text-danger">-<?= number_format($stock_removed) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-down-arrow-alt text-danger"></i>
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
                                        <h6 class="text-muted mb-1">Date Range</h6>
                                        <h5 class="mb-0 text-info">
                                            <?= date('d M', strtotime($date_from)) ?> - <?= date('d M Y', strtotime($date_to)) ?>
                                        </h5>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-calendar text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Stats -->
                <?php if ($total_adjustments > 0): ?>
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-0 bg-light">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted">Affected Products</small>
                                        <h5 class="mb-0"><?= number_format($unique_products) ?></h5>
                                    </div>
                                    <i class="bx bx-package fs-4 text-muted"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 bg-light">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted">Locations</small>
                                        <h5 class="mb-0"><?= number_format($unique_shops) ?></h5>
                                    </div>
                                    <i class="bx bx-store fs-4 text-muted"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 bg-light">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted">Net Change</small>
                                        <h5 class="mb-0 <?= ($stock_added - $stock_removed) >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= ($stock_added - $stock_removed) >= 0 ? '+' : '' ?><?= number_format($stock_added - $stock_removed) ?>
                                        </h5>
                                    </div>
                                    <i class="bx bx-trending-up fs-4 text-muted"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- History Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="historyTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Product Details</th>
                                        <th class="text-center">Location</th>
                                        <th class="text-center">Adjustment Type</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-center">Stock Change</th>
                                        <th class="text-center">Adjusted By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($history)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bx bx-package display-1 text-muted"></i>
                                                <h5 class="mt-3">No stock movements found</h5>
                                                <p class="text-muted">No stock adjustments recorded for the selected criteria</p>
                                                <?php if ($search || $location || ($reason && $reason !== 'all') || $date_from != date('Y-m-d', strtotime('-30 days')) || $date_to != date('Y-m-d')): ?>
                                                <a href="stock_history.php" class="btn btn-primary mt-3">
                                                    <i class="bx bx-reset me-1"></i> Clear Filters
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($history as $h): 
                                        $is_add = in_array($h['adjustment_type'], ['add', 'transfer_in']);
                                        $type_color = $is_add ? 'success' : 'danger';
                                        $type_icon = $is_add ? 'bx-up-arrow-alt' : 'bx-down-arrow-alt';
                                        $adjustment_label = $adjustment_types[$h['adjustment_type']] ?? ucfirst(str_replace('_', ' ', $h['adjustment_type']));
                                    ?>
                                    <tr class="movement-row" data-id="<?= $h['id'] ?>">
                                        <td>
                                            <div class="mb-2">
                                                <strong class="d-block"><?= date('d M Y', strtotime($h['adjusted_at'])) ?></strong>
                                                <small class="text-muted"><?= date('h:i A', strtotime($h['adjusted_at'])) ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-light text-dark rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx bx-package fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($h['product_name']) ?></strong>
                                                    <?php if ($h['product_code']): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-hash me-1"></i><?= htmlspecialchars($h['product_code']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                    <?php if ($h['reason']): ?>
                                                    <small class="text-muted">
                                                        <i class="bx bx-info-circle me-1"></i><?= htmlspecialchars($h['reason']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1">
                                                <i class="bx bx-store me-1"></i><?= htmlspecialchars($h['shop_name']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-<?= $type_color ?> bg-opacity-10 text-<?= $type_color ?> px-3 py-2">
                                                    <i class="bx <?= $type_icon ?> me-1"></i><?= $adjustment_label ?>
                                                </span>
                                            </div>
                                            <?php if ($h['notes']): ?>
                                            <small class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars($h['notes']) ?>">
                                                <i class="bx bx-note me-1"></i>Note
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-<?= $type_color ?> bg-opacity-10 text-<?= $type_color ?> rounded-pill px-3 py-2 fs-6">
                                                    <i class="bx <?= $type_icon ?> me-1"></i> 
                                                    <?= $is_add ? '+' : '-' ?><?= number_format($h['quantity']) ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="stock-change">
                                                <div class="d-flex align-items-center justify-content-center mb-1">
                                                    <span class="text-muted me-2"><?= number_format($h['old_stock']) ?></span>
                                                    <i class="bx bx-right-arrow-alt text-primary"></i>
                                                    <span class="fw-bold ms-2"><?= number_format($h['new_stock']) ?></span>
                                                </div>
                                                <div class="progress" style="height: 6px; width: 120px; margin: 0 auto;">
                                                    <?php 
                                                    $max_stock = max($h['old_stock'], $h['new_stock']);
                                                    if ($max_stock > 0) {
                                                        $old_percent = ($h['old_stock'] / $max_stock) * 100;
                                                        $new_percent = ($h['new_stock'] / $max_stock) * 100;
                                                    } else {
                                                        $old_percent = 0;
                                                        $new_percent = 0;
                                                    }
                                                    ?>
                                                    <div class="progress-bar bg-secondary" style="width: <?= $old_percent ?>%"></div>
                                                    <div class="progress-bar bg-<?= $type_color ?>" style="width: <?= $new_percent ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                    <i class="bx bx-user me-1"></i><?= htmlspecialchars($h['adjusted_by_name']) ?>
                                                </span>
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

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTables only if there's data
    <?php if (!empty($history)): ?>
    $('#historyTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search in history:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ movements",
            infoFiltered: "(filtered from <?= $total_adjustments ?> total movements)",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });
    <?php endif; ?>

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Auto-submit on filter change (debounced)
    let filterTimer;
    $('select[name="location"], select[name="reason"], input[name="date_from"], input[name="date_to"]').on('change', function() {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(() => {
            $('#filterForm').submit();
        }, 500);
    });

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Row hover effect
    $('.movement-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );
});

function exportStockHistory() {
    <?php if (empty($history)): ?>
    Swal.fire({
        icon: 'info',
        title: 'No Data',
        text: 'No stock movements to export for the selected criteria.',
        timer: 3000
    });
    return;
    <?php endif; ?>
    
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
    btn.disabled = true;
    
    // Build export URL with current search parameters
    const params = new URLSearchParams(window.location.search);
    
    // Add business_id to export parameters
    params.append('business_id', '<?= $business_id ?>');
    
    const exportUrl = 'stock_history_export.php' + (params.toString() ? '?' + params.toString() : '');
    
    window.location = exportUrl;
    
    // Reset button after 3 seconds
    setTimeout(() => {
        btn.innerHTML = original;
        btn.disabled = false;
    }, 3000);
}
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
}
.badge.bg-opacity-10 {
    opacity: 0.9;
}
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
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
.stock-change .progress {
    width: 120px;
    margin: 0 auto;
}
.movement-row:hover .avatar-sm .rounded-circle {
    transform: scale(1.1);
    transition: transform 0.3s ease;
}
@media (max-width: 768px) {
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
    .stock-change .progress {
        width: 100px;
    }
}
</style>
</body>
</html>