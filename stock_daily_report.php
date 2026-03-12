<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$allowed_roles = ['admin', 'warehouse_manager', 'shop_manager', 'stock_manager'];
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. You don't have permission to view stock reports.";
    header('Location: dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_shop_id = $_SESSION['current_shop_id'] ?? $_SESSION['shop_id'] ?? null;
$selected_date = $_GET['date'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

// Fetch shops for current business
$shop_query = "SELECT id, shop_name FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name";
$shop_stmt = $pdo->prepare($shop_query);
$shop_stmt->execute([$business_id]);
$shops = $shop_stmt->fetchAll();

// === SHOP-WISE REPORT ===
$shop_report = [];
$total_opening = $total_inward = $total_outward = $total_sales = $total_closing = 0;

foreach ($shops as $shop) {
    $shop_id = $shop['id'];
    
    // Skip if user is not admin and shop doesn't match their assigned shop
    if ($user_role !== 'admin' && $user_shop_id && $shop_id != $user_shop_id) {
        continue;
    }

    // Opening = Closing of previous day (using product_stocks)
    $prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
    $opening_query = "
        SELECT COALESCE(SUM(ps.quantity), 0)
        FROM product_stocks ps
        JOIN products p ON ps.product_id = p.id
        WHERE ps.shop_id = ? 
          AND p.business_id = ?
          AND DATE(ps.last_updated) <= ?
    ";
    $opening_stmt = $pdo->prepare($opening_query);
    $opening_stmt->execute([$shop_id, $business_id, $prev_date]);
    $opening = (float)$opening_stmt->fetchColumn();

    // Inward from stock_adjustments (add, transfer_in) on selected date
    $inward_query = "
        SELECT COALESCE(SUM(sa.quantity), 0)
        FROM stock_adjustments sa
        JOIN products p ON sa.product_id = p.id
        WHERE sa.shop_id = ? 
          AND p.business_id = ?
          AND sa.adjustment_type IN ('add', 'transfer_in')
          AND DATE(sa.adjusted_at) = ?
    ";
    $inward_stmt = $pdo->prepare($inward_query);
    $inward_stmt->execute([$shop_id, $business_id, $selected_date]);
    $inward = (float)$inward_stmt->fetchColumn();

    // Outward from stock_adjustments (remove, transfer_out, damage, expiry) on selected date
    $outward_query = "
        SELECT COALESCE(SUM(sa.quantity), 0)
        FROM stock_adjustments sa
        JOIN products p ON sa.product_id = p.id
        WHERE sa.shop_id = ? 
          AND p.business_id = ?
          AND sa.adjustment_type IN ('remove', 'transfer_out', 'damage', 'expiry')
          AND DATE(sa.adjusted_at) = ?
    ";
    $outward_stmt = $pdo->prepare($outward_query);
    $outward_stmt->execute([$shop_id, $business_id, $selected_date]);
    $outward = (float)$outward_stmt->fetchColumn();

    // Sales from invoices (outward stock movement) on selected date
    $sales_query = "
        SELECT COALESCE(SUM(ii.quantity), 0)
        FROM invoices i
        JOIN invoice_items ii ON i.id = ii.invoice_id
        JOIN products p ON ii.product_id = p.id
        WHERE i.shop_id = ? 
          AND p.business_id = ?
          AND DATE(i.created_at) = ?
          AND ii.return_qty = 0
    ";
    $sales_stmt = $pdo->prepare($sales_query);
    $sales_stmt->execute([$shop_id, $business_id, $selected_date]);
    $sales = (float)$sales_stmt->fetchColumn();

    // Add sales to outward movement for total stock reduction
    $total_outward_shop = $outward + $sales;
    $closing = $opening + $inward - $total_outward_shop;

    $shop_report[] = [
        'shop_id' => $shop_id,
        'shop_name' => $shop['shop_name'],
        'opening' => $opening,
        'inward' => $inward,
        'outward' => $outward,
        'sales' => $sales,
        'total_outward' => $total_outward_shop,
        'closing' => $closing
    ];

    $total_opening += $opening;
    $total_inward += $inward;
    $total_outward += $outward;
    $total_sales += $sales;
    $total_closing += $closing;
}

// === PRODUCT-WISE REPORT (All Shops Combined) ===
$product_query = "
    SELECT 
        p.id,
        p.product_name,
        p.product_code,
        COALESCE((
            SELECT SUM(ps.quantity)
            FROM product_stocks ps
            WHERE ps.product_id = p.id
        ), 0) AS current_stock,
        COALESCE((
            SELECT SUM(sa.quantity)
            FROM stock_adjustments sa
            WHERE sa.product_id = p.id
              AND sa.adjustment_type IN ('add', 'transfer_in')
              AND DATE(sa.adjusted_at) = ?
        ), 0) AS inward,
        COALESCE((
            SELECT SUM(sa.quantity)
            FROM stock_adjustments sa
            WHERE sa.product_id = p.id
              AND sa.adjustment_type IN ('remove', 'transfer_out', 'damage', 'expiry')
              AND DATE(sa.adjusted_at) = ?
        ), 0) AS outward,
        COALESCE((
            SELECT SUM(ii.quantity)
            FROM invoices i
            JOIN invoice_items ii ON i.id = ii.invoice_id
            WHERE ii.product_id = p.id
              AND DATE(i.created_at) = ?
              AND ii.return_qty = 0
        ), 0) AS sales
    FROM products p
    WHERE p.business_id = ?
    ORDER BY p.product_name
";

$product_stmt = $pdo->prepare($product_query);
$product_stmt->execute([$selected_date, $selected_date, $selected_date, $business_id]);
$product_report = $product_stmt->fetchAll();

// Calculate opening and filter products with no activity/stock
$filtered_product_report = [];
foreach ($product_report as $prod) {
    $prod['inward'] = (float)$prod['inward'];
    $prod['outward'] = (float)$prod['outward'];
    $prod['sales'] = (float)$prod['sales'];
    $prod['total_outward'] = $prod['outward'] + $prod['sales'];
    $prod['opening'] = $prod['current_stock'] - $prod['inward'] + $prod['total_outward'];
    $prod['closing'] = $prod['current_stock'];
    
    // Only include products that have movement or stock
    if ($prod['inward'] > 0 || $prod['outward'] > 0 || $prod['sales'] > 0 || $prod['current_stock'] > 0) {
        $filtered_product_report[] = $prod;
    }
}
$product_report = $filtered_product_report;

// Messages
$success = $_SESSION['success'] ?? ''; unset($_SESSION['success']);
$error = $_SESSION['error'] ?? ''; unset($_SESSION['error']);
?>
<!doctype html>
<html lang="en">
<?php 
$page_title = "Daily Stock Report - " . date('d M Y', strtotime($selected_date)); 
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
                                <i class="bx bx-calendar-check me-2"></i> Daily Stock Report
                                <small class="text-muted ms-2">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <form method="GET" class="d-inline">
                                    <input type="date" name="date" class="form-control" 
                                           value="<?= $selected_date ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
                                </form>
                                <?php if (!empty($shop_report) || !empty($product_report)): ?>
                                <button onclick="exportDailyReport()" class="btn btn-primary">
                                    <i class="bx bx-download me-1"></i> Export Excel
                                </button>
                                <button onclick="window.print()" class="btn btn-outline-secondary">
                                    <i class="bx bx-printer me-1"></i> Print
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

                <!-- Date Navigation -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1">
                                    <i class="bx bx-calendar me-2"></i><?= date('d M Y', strtotime($selected_date)) ?>
                                </h5>
                                <small class="text-muted">
                                    <?= date('l', strtotime($selected_date)) ?> • 
                                    Week <?= date('W', strtotime($selected_date)) ?>
                                </small>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="?date=<?= date('Y-m-d', strtotime($selected_date . ' -1 day')) ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="bx bx-chevron-left me-1"></i> Previous Day
                                </a>
                                <?php if ($selected_date != date('Y-m-d')): ?>
                                <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-outline-primary">
                                    <i class="bx bx-calendar me-1"></i> Today
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($shop_report)): ?>
                <!-- Empty State -->
                <div class="empty-state text-center py-5">
                    <i class="bx bx-store-alt fs-1 text-muted mb-3"></i>
                    <h5>No Stock Data Found</h5>
                    <p class="text-muted">No stock data available for <?= date('d M Y', strtotime($selected_date)) ?></p>
                    <div class="mt-4">
                        <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-primary">
                            <i class="bx bx-calendar me-1"></i> View Today's Report
                        </a>
                    </div>
                </div>
                <?php else: ?>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Opening Stock</h6>
                                        <h3 class="mb-0 text-primary"><?= number_format($total_opening) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-box text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Inward (+) </h6>
                                        <h3 class="mb-0 text-success">+<?= number_format($total_inward) ?></h3>
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
                                        <h6 class="text-muted mb-1">Sales (-)</h6>
                                        <h3 class="mb-0 text-danger">-<?= number_format($total_sales) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-cart text-danger"></i>
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
                                        <h6 class="text-muted mb-1">Closing Stock</h6>
                                        <h3 class="mb-0 text-info"><?= number_format($total_closing) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-check-circle text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shop-wise Report -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-store me-2"></i> Shop-wise Stock Movement
                            <small class="text-muted ms-2"><?= count($shop_report) ?> Locations</small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="shopReportTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Shop/Location</th>
                                        <th class="text-center">Opening</th>
                                        <th class="text-center">Inward (+) </th>
                                        <th class="text-center">Sales (-)</th>
                                        <th class="text-center">Adjustments</th>
                                        <th class="text-center">Total Outward</th>
                                        <th class="text-center">Closing</th>
                                        <th class="text-center">Net Change</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($shop_report as $row): 
                                        $net_change = $row['inward'] - $row['total_outward'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 40px; height: 40px;">
                                                        <i class="bx bx-store fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block"><?= htmlspecialchars($row['shop_name']) ?></strong>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center fw-bold"><?= number_format($row['opening']) ?></td>
                                        <td class="text-center text-success fw-bold">+<?= number_format($row['inward']) ?></td>
                                        <td class="text-center text-danger fw-bold">-<?= number_format($row['sales']) ?></td>
                                        <td class="text-center text-warning fw-bold"><?= number_format($row['outward']) ?></td>
                                        <td class="text-center text-danger fw-bold">-<?= number_format($row['total_outward']) ?></td>
                                        <td class="text-center text-info fw-bold"><?= number_format($row['closing']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $net_change > 0 ? 'success' : ($net_change < 0 ? 'danger' : 'secondary') ?> bg-opacity-10 text-<?= $net_change > 0 ? 'success' : ($net_change < 0 ? 'danger' : 'secondary') ?> px-3 py-2">
                                                <?= $net_change > 0 ? '+' : '' ?><?= number_format($net_change) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <th class="text-end">TOTAL</th>
                                        <th class="text-center"><?= number_format($total_opening) ?></th>
                                        <th class="text-center text-success">+<?= number_format($total_inward) ?></th>
                                        <th class="text-center text-danger">-<?= number_format($total_sales) ?></th>
                                        <th class="text-center text-warning"><?= number_format($total_outward) ?></th>
                                        <th class="text-center text-danger">-<?= number_format($total_outward + $total_sales) ?></th>
                                        <th class="text-center text-primary"><?= number_format($total_closing) ?></th>
                                        <th class="text-center <?= ($total_inward - ($total_outward + $total_sales)) > 0 ? 'text-success' : (($total_inward - ($total_outward + $total_sales)) < 0 ? 'text-danger' : 'text-muted') ?>">
                                            <?= ($total_inward - ($total_outward + $total_sales)) > 0 ? '+' : '' ?><?= number_format($total_inward - ($total_outward + $total_sales)) ?>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Product-wise Report -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-package me-2"></i> Product-wise Movement
                            <small class="text-muted ms-2"><?= count($product_report) ?> Products</small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($product_report)): ?>
                        <div class="empty-state text-center py-4">
                            <i class="bx bx-package fs-1 text-muted mb-3"></i>
                            <p class="text-muted">No product movement on <?= date('d M Y', strtotime($selected_date)) ?></p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table id="productReportTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Product Details</th>
                                        <th class="text-center">Opening</th>
                                        <th class="text-center">Inward (+) </th>
                                        <th class="text-center">Sales (-)</th>
                                        <th class="text-center">Adjustments</th>
                                        <th class="text-center">Total Outward</th>
                                        <th class="text-center">Closing</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($product_report as $i => $prod): 
                                        $has_movement = $prod['inward'] > 0 || $prod['sales'] > 0 || $prod['outward'] > 0;
                                    ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-light text-dark rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 40px; height: 40px;">
                                                        <i class="bx bx-package fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($prod['product_name']) ?></strong>
                                                    <?php if ($prod['product_code']): ?>
                                                    <small class="text-muted">
                                                        <i class="bx bx-hash me-1"></i><?= htmlspecialchars($prod['product_code']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center fw-bold"><?= number_format($prod['opening']) ?></td>
                                        <td class="text-center text-success fw-bold">+<?= number_format($prod['inward']) ?></td>
                                        <td class="text-center text-danger fw-bold">-<?= number_format($prod['sales']) ?></td>
                                        <td class="text-center text-warning fw-bold"><?= number_format($prod['outward']) ?></td>
                                        <td class="text-center text-danger fw-bold">-<?= number_format($prod['total_outward']) ?></td>
                                        <td class="text-center text-info fw-bold"><?= number_format($prod['closing']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Report Summary -->
                <div class="card shadow-sm mt-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="bx bx-info-circle me-2"></i> Report Summary</h6>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted d-block">Report Date</small>
                                        <strong><?= date('d M Y, l', strtotime($selected_date)) ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Generated On</small>
                                        <strong><?= date('d M Y, h:i A') ?></strong>
                                    </div>
                                    <div class="col-6 mt-3">
                                        <small class="text-muted d-block">Total Locations</small>
                                        <strong><?= count($shop_report) ?></strong>
                                    </div>
                                    <div class="col-6 mt-3">
                                        <small class="text-muted d-block">Active Products</small>
                                        <strong><?= count($product_report) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="bx bx-bar-chart me-2"></i> Daily Summary</h6>
                                <div class="row">
                                    <div class="col-3">
                                        <div class="text-center p-2 border rounded">
                                            <small class="text-muted d-block">Opening</small>
                                            <h5 class="mb-0 text-primary"><?= number_format($total_opening) ?></h5>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="text-center p-2 border rounded">
                                            <small class="text-muted d-block">Inward</small>
                                            <h5 class="mb-0 text-success">+<?= number_format($total_inward) ?></h5>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="text-center p-2 border rounded">
                                            <small class="text-muted d-block">Sales</small>
                                            <h5 class="mb-0 text-danger">-<?= number_format($total_sales) ?></h5>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="text-center p-2 border rounded">
                                            <small class="text-muted d-block">Closing</small>
                                            <h5 class="mb-0 text-info"><?= number_format($total_closing) ?></h5>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php endif; ?>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    <?php if (!empty($shop_report)): ?>
    $('#shopReportTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search shops:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ shops",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($product_report)): ?>
    $('#productReportTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search products:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ products",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });
    <?php endif; ?>

    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

function exportDailyReport() {
    <?php if (empty($shop_report) && empty($product_report)): ?>
    Swal.fire({
        icon: 'info',
        title: 'No Data',
        text: 'No stock data to export for the selected date.',
        timer: 3000
    });
    return;
    <?php endif; ?>
    
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
    btn.disabled = true;
    
    const exportUrl = 'stock_daily_report_export.php?date=<?= $selected_date ?>&business_id=<?= $business_id ?>';
    window.location = exportUrl;
    
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
    width: 40px;
    height: 40px;
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
</style>
</body>
</html>