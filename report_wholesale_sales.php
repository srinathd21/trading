<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// ==================== LOGIN & ROLE CHECK ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;

// Only admin, shop_manager, and cashier can view reports
$can_view_reports = in_array($user_role, ['admin', 'shop_manager', 'cashier']);
if (!$can_view_reports) {
    $_SESSION['error'] = "Access denied. You don't have permission to view reports.";
    header('Location: dashboard.php');
    exit();
}

// ==================== FILTERS ====================
$selected_shop_id = $_GET['shop_id'] ?? 'all';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// For non-admin users, force their assigned shop
if ($user_role !== 'admin' && $current_shop_id) {
    $selected_shop_id = $current_shop_id;
}

// Date range filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$display_date_from = date('d M Y', strtotime($date_from));
$display_date_to = date('d M Y', strtotime($date_to));

// Get all shops for admin dropdown
$all_shops = [];
if ($user_role === 'admin') {
    $shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name");
    $shop_stmt->execute([$business_id]);
    $all_shops = $shop_stmt->fetchAll();
}

// Get shop name
$shop_name = 'All Branches';
if ($selected_shop_id !== 'all') {
    $stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
    $stmt->execute([$selected_shop_id, $business_id]);
    $shop = $stmt->fetch();
    $shop_name = $shop['shop_name'] ?? 'Shop';
}

// ==================== SIMPLE QUERY ====================
// Get all wholesale items with invoice details
$sql = "
    SELECT
    i.id as invoice_id,
    i.invoice_number,
    i.created_at,
    i.pending_amount,
    c.name as customer_name,
    c.phone as customer_phone,
    c.gstin,
    u.full_name as seller_name,
    s.shop_name,
    ii.id as item_id,
    ii.quantity,
    ii.return_qty,
    ii.unit_price,
    ii.discount_amount,
    ii.cgst_amount,
    ii.sgst_amount,
    ii.igst_amount,
    ii.profit,
    (ii.quantity * ii.unit_price) as item_subtotal,
    -- Only add GST if not included in price
    CASE
        WHEN ii.gst_inclusive = 0 THEN
            ((ii.quantity * ii.unit_price) - ii.discount_amount + COALESCE(ii.cgst_amount, 0) + COALESCE(ii.sgst_amount, 0) + COALESCE(ii.igst_amount, 0))
        ELSE
            ((ii.quantity * ii.unit_price) - ii.discount_amount)
    END as item_total,
    (ii.return_qty * ii.unit_price) as item_returns
FROM invoices i
INNER JOIN invoice_items ii ON i.id = ii.invoice_id AND ii.sale_type = 'wholesale'
LEFT JOIN customers c ON i.customer_id = c.id
LEFT JOIN users u ON i.seller_id = u.id
LEFT JOIN shops s ON i.shop_id = s.id
WHERE DATE(i.created_at) BETWEEN ? AND ?
  AND i.business_id = ?
";

$params = [$date_from, $date_to, $business_id];

if ($selected_shop_id !== 'all') {
    $sql .= " AND i.shop_id = ?";
    $params[] = $selected_shop_id;
}

$sql .= " ORDER BY i.created_at DESC, ii.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Calculate totals
$total_gross = 0;
$total_returns = 0;
$total_net = 0;
$total_profit = 0;
$total_quantity = 0;
$total_return_qty = 0;

foreach ($items as $item) {
    $total_gross += $item['item_total'];
    $total_returns += $item['item_returns'];
    $total_net += ($item['item_total'] - $item['item_returns']);
    $total_profit += $item['profit'];
    $total_quantity += $item['quantity'];
    $total_return_qty += $item['return_qty'];
}

// Count unique invoices
$invoice_ids = [];
foreach ($items as $item) {
    $invoice_ids[$item['invoice_id']] = true;
}
$invoice_count = count($invoice_ids);
$item_count = count($items);

$return_percentage = $total_gross > 0 ? ($total_returns / $total_gross) * 100 : 0;
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Wholesale Sales Report"; ?>
<?php include 'includes/head.php'; ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu"><div data-simplebar class="h-100">
        <?php include 'includes/sidebar.php'; ?>
    </div></div>
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-package me-2"></i>
                                    Wholesale Sales Report
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    Report from <?= $display_date_from ?> to <?= $display_date_to ?>
                                    <?php if ($selected_shop_id !== 'all'): ?>
                                        <span class="badge bg-info">Filtered by Branch: <?= htmlspecialchars($shop_name) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-info">All Branches</span>
                                    <?php endif; ?>
                                </p>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <small class="text-success">
                                        <i class="bx bx-check-circle me-1"></i>
                                        Net values shown after returns
                                    </small>
                                    <?php if ($return_percentage > 0): ?>
                                    <small class="text-warning">
                                        <i class="bx bx-undo me-1"></i>
                                        Return Rate: <?= number_format($return_percentage, 1) ?>%
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="?export=csv&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&shop_id=<?= $selected_shop_id ?>"
                                   class="btn btn-success">
                                    <i class="bx bx-download me-1"></i> Export CSV
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-2"></i> Filter Report
                        </h5>
                        <form method="GET" id="reportForm" class="row g-3">
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control"
                                    value="<?= $date_from ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control"
                                    value="<?= $date_to ?>" max="<?= date('Y-m-d') ?>">
                            </div>

                            <?php if ($user_role === 'admin'): ?>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">Select Branch</label>
                                <select class="form-select" name="shop_id">
                                    <option value="all" <?= $selected_shop_id === 'all' ? 'selected' : '' ?>>All Branches</option>
                                    <?php foreach ($all_shops as $shop): ?>
                                        <option value="<?= $shop['id'] ?>"
                                            <?= $selected_shop_id == $shop['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($shop['shop_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="col-lg-3 col-md-6 align-self-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bx bx-filter me-1"></i> Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Gross Wholesale Sales</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($total_gross, 2) ?></h3>
                                        <small class="text-muted">
                                            <?= $invoice_count ?> invoices | <?= $item_count ?> items
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-receipt text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Returns</h6>
                                        <h3 class="mb-0 text-danger">-₹<?= number_format($total_returns, 2) ?></h3>
                                        <small class="text-muted">
                                            <?= $total_return_qty ?> units returned
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-undo text-danger"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Net Wholesale Sales</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($total_net, 2) ?></h3>
                                        <small class="text-muted">
                                            After returns
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-trending-up text-success"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Profit</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($total_profit, 2) ?></h3>
                                        <small class="text-muted">
                                            <?= $total_quantity ?> units sold
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-line-chart text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bx bx-list-ul me-2"></i>
                            Wholesale Items Details
                        </h5>
                        <div>
                            <span class="badge bg-primary"><?= $item_count ?> items</span>
                            <span class="badge bg-info ms-2"><?= $invoice_count ?> invoices</span>
                            <?php if ($total_returns > 0): ?>
                            <span class="badge bg-danger ms-2">
                                Returns: ₹<?= number_format($total_returns, 2) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($items)): ?>
                            <div class="text-center py-5">
                                <i class="bx bx-package display-4 text-muted"></i>
                                <h5 class="mt-3">No wholesale sales found</h5>
                                <p class="text-muted">No wholesale items in the selected period</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Invoice No</th>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>GSTIN</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Subtotal</th>
                                            <th class="text-end">Discount</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">Returns</th>
                                            <th class="text-end">Net</th>
                                            <th class="text-end">Profit</th>
                                            <th>Seller</th>
                                            <th>Shop</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $i = 1;
                                        foreach ($items as $item): 
                                            $net = $item['item_total'] - $item['item_returns'];
                                        ?>
                                        <tr>
                                            <td><?= $i++ ?></td>
                                            <td>
                                                <a href="invoice_view.php?invoice_id=<?= $item['invoice_id'] ?>"
                                                   class="text-primary fw-bold">
                                                    <?= htmlspecialchars($item['invoice_number']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= date('d M Y', strtotime($item['created_at'])) ?></small><br>
                                                <small class="text-muted"><?= date('h:i A', strtotime($item['created_at'])) ?></small>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($item['customer_name'] ?? 'Walk-in') ?></strong><br>
                                                <?php if (!empty($item['customer_phone'])): ?>
                                                    <small class="text-muted"><?= htmlspecialchars($item['customer_phone']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?= htmlspecialchars($item['gstin'] ?? 'N/A') ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-2">
                                                    <?= $item['quantity'] ?>
                                                    <?php if ($item['return_qty'] > 0): ?>
                                                    <br>
                                                    <small class="text-danger">
                                                        <i class="bx bx-undo"></i> -<?= $item['return_qty'] ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="text-end">₹<?= number_format($item['unit_price'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($item['item_subtotal'], 2) ?></td>
                                            <td class="text-end">
                                                <?php if ($item['discount_amount'] > 0): ?>
                                                    <span class="text-danger">-₹<?= number_format($item['discount_amount'], 2) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold text-primary">
                                                ₹<?= number_format($item['item_total'], 2) ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($item['item_returns'] > 0): ?>
                                                    <span class="text-danger">
                                                        -₹<?= number_format($item['item_returns'], 2) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">₹0.00</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold <?= $item['item_returns'] > 0 ? 'text-success' : 'text-primary' ?>">
                                                ₹<?= number_format($net, 2) ?>
                                            </td>
                                            <td class="text-end">
                                                <span class="text-success fw-bold">₹<?= number_format($item['profit'], 2) ?></span>
                                            </td>
                                            <td><small><?= htmlspecialchars($item['seller_name'] ?? 'N/A') ?></small></td>
                                            <td><small><?= htmlspecialchars($item['shop_name'] ?? 'N/A') ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <!-- Totals Row -->
                                    <tfoot class="table-active fw-bold">
                                        <tr>
                                            <td colspan="7" class="text-end">TOTALS:</td>
                                            <td class="text-end">₹<?= number_format($total_gross + $total_returns, 2) ?></td>
                                            <td class="text-end text-danger">-₹<?= number_format(array_sum(array_column($items, 'discount_amount')), 2) ?></td>
                                            <td class="text-end text-primary">₹<?= number_format($total_gross, 2) ?></td>
                                            <td class="text-end text-danger">-₹<?= number_format($total_returns, 2) ?></td>
                                            <td class="text-end text-success">₹<?= number_format($total_net, 2) ?></td>
                                            <td class="text-end text-success">₹<?= number_format($total_profit, 2) ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
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
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    document.querySelectorAll('input[type="date"]').forEach(input => {
        input.max = today;
    });
});
</script>

<style>
.card-hover { transition: transform 0.2s ease; }
.card-hover:hover { transform: translateY(-2px); }
.border-start { border-left-width: 4px !important; }
.avatar-sm { width: 48px; height: 48px; }
.text-line-through { text-decoration: line-through; }
</style>
</body>
</html>