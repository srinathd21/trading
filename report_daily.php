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
$can_view_reports = in_array($user_role, ['admin', 'shop_manager', 'cashier']);
if (!$can_view_reports) {
    $_SESSION['error'] = "Access denied. You don't have permission to view reports.";
    header('Location: dashboard.php');
    exit();
}

// Filters
$start_date = $_GET['date_from'] ?? date('Y-m-01');
$end_date = $_GET['date_to'] ?? date('Y-m-d');
$selected_shop_id = $_GET['shop_id'] ?? 'all';
if ($user_role !== 'admin' && $current_shop_id) {
    $selected_shop_id = $current_shop_id;
}
$is_single_day = ($start_date === $end_date);
$display_date_from = date('d M Y', strtotime($start_date));
$display_date_to = date('d M Y', strtotime($end_date));

// Get shops for admin
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

// Build parameters
$base_params = [$start_date, $end_date, $business_id];
$shop_filter = $selected_shop_id !== 'all' ? " AND inv.shop_id = ?" : "";
$shop_param = $selected_shop_id !== 'all' ? [$selected_shop_id] : [];

// ==================== ACCURATE SALES & PROFIT (After Returns) ====================
$accurate_sql = "
SELECT
    invAgg.total_invoices,
    invAgg.gross_sales,
    COALESCE(retAgg.total_returns, 0) AS total_returns,
    (invAgg.gross_sales - COALESCE(retAgg.total_returns, 0)) AS net_sales,

    COALESCE(itemAgg.gross_profit, 0) AS gross_profit,
    COALESCE(itemAgg.profit_lost, 0) AS profit_loss_from_returns,
    (COALESCE(itemAgg.gross_profit, 0) - COALESCE(itemAgg.profit_lost, 0)) AS net_profit_before_expenses,

    COALESCE(itemAgg.total_qty_sold, 0) AS total_quantity_sold,
    COALESCE(itemAgg.total_qty_returned, 0) AS total_quantity_returned,
    (COALESCE(itemAgg.total_qty_sold, 0) - COALESCE(itemAgg.total_qty_returned, 0)) AS net_quantity_sold

FROM
(
    SELECT
        COUNT(*) AS total_invoices,
        COALESCE(SUM(total), 0) AS gross_sales
    FROM invoices
    WHERE DATE(created_at) BETWEEN ? AND ?
      AND business_id = ?
      " . ($selected_shop_id !== 'all' ? " AND shop_id = ? " : "") . "
) invAgg

LEFT JOIN
(
    SELECT
        COALESCE(SUM(r.total_return_amount), 0) AS total_returns
    FROM returns r
    JOIN invoices inv ON inv.id = r.invoice_id
    WHERE DATE(r.return_date) BETWEEN ? AND ?
      AND r.business_id = ?
      " . ($selected_shop_id !== 'all' ? " AND inv.shop_id = ? " : "") . "
) retAgg ON 1=1

LEFT JOIN
(
    SELECT
        COALESCE(SUM(ii.profit), 0) AS gross_profit,
        COALESCE(SUM(ii.return_qty * (ii.profit / NULLIF(ii.quantity,0))), 0) AS profit_lost,
        COALESCE(SUM(ii.quantity), 0) AS total_qty_sold,
        COALESCE(SUM(ii.return_qty), 0) AS total_qty_returned
    FROM invoice_items ii
    JOIN invoices inv ON inv.id = ii.invoice_id
    WHERE DATE(inv.created_at) BETWEEN ? AND ?
      AND inv.business_id = ?
      " . ($selected_shop_id !== 'all' ? " AND inv.shop_id = ? " : "") . "
) itemAgg ON 1=1
";

$params = [];
// invAgg
$params = array_merge($params, [$start_date, $end_date, $business_id]);
if ($selected_shop_id !== 'all') $params[] = $selected_shop_id;

// retAgg
$params = array_merge($params, [$start_date, $end_date, $business_id]);
if ($selected_shop_id !== 'all') $params[] = $selected_shop_id;

// itemAgg
$params = array_merge($params, [$start_date, $end_date, $business_id]);
if ($selected_shop_id !== 'all') $params[] = $selected_shop_id;

$accurate_stmt = $pdo->prepare($accurate_sql);
$accurate_stmt->execute($params);
$accurate = $accurate_stmt->fetch();

// Calculate percentages
$return_percentage = $accurate['gross_sales'] > 0 ? ($accurate['total_returns'] / $accurate['gross_sales']) * 100 : 0;
$net_profit_margin = $accurate['net_sales'] > 0 ? ($accurate['net_profit_before_expenses'] / $accurate['net_sales']) * 100 : 0;

// Retail vs Wholesale (after returns)
$retail_sql = "
    SELECT
        COUNT(*) as invoices,
        COALESCE(SUM(inv.total), 0) as gross_sales,
        COALESCE(SUM(ret.returns_amount), 0) as returns,
        (COALESCE(SUM(inv.total), 0) - COALESCE(SUM(ret.returns_amount), 0)) as net_sales,
        COALESCE(SUM(it.gross_profit), 0) as gross_profit,
        COALESCE(SUM(it.return_profit_loss), 0) as profit_loss,
        (COALESCE(SUM(it.gross_profit), 0) - COALESCE(SUM(it.return_profit_loss), 0)) as net_profit
    FROM invoices inv
    LEFT JOIN (
        SELECT invoice_id, SUM(return_qty * unit_price) AS returns_amount
        FROM invoice_items
        GROUP BY invoice_id
    ) ret ON ret.invoice_id = inv.id
    LEFT JOIN (
        SELECT
            invoice_id,
            SUM(profit) AS gross_profit,
            SUM(return_qty * (profit / NULLIF(quantity,0))) AS return_profit_loss
        FROM invoice_items
        GROUP BY invoice_id
    ) it ON it.invoice_id = inv.id
    WHERE DATE(inv.created_at) BETWEEN ? AND ?
      AND inv.business_id = ?
      $shop_filter
      AND NOT EXISTS (
          SELECT 1 FROM invoice_items x
          WHERE x.invoice_id = inv.id
            AND (x.sale_type IS NULL OR x.sale_type != 'retail')
      )
";
$retail_stmt = $pdo->prepare($retail_sql);
$retail_stmt->execute(array_merge($base_params, $shop_param));
$retail_data = $retail_stmt->fetch();

$wholesale_sql = str_replace("'retail'", "'wholesale'", $retail_sql);
$wholesale_stmt = $pdo->prepare($wholesale_sql);
$wholesale_stmt->execute(array_merge($base_params, $shop_param));
$wholesale_data = $wholesale_stmt->fetch();

// Mixed invoices (both retail and wholesale)
$mixed_sql = "
    SELECT
        COUNT(DISTINCT inv.id) as invoices,
        COALESCE(SUM(inv.total), 0) as gross_sales,
        COALESCE(SUM(ii.return_qty * ii.unit_price), 0) as returns,
        (COALESCE(SUM(inv.total), 0) - COALESCE(SUM(ii.return_qty * ii.unit_price), 0)) as net_sales
    FROM invoices inv
    LEFT JOIN invoice_items ii ON inv.id = ii.invoice_id
    WHERE DATE(inv.created_at) BETWEEN ? AND ?
      AND inv.business_id = ?
      $shop_filter
      AND EXISTS (
          SELECT 1 FROM invoice_items ii2 WHERE ii2.invoice_id = inv.id AND ii2.sale_type = 'retail'
      )
      AND EXISTS (
          SELECT 1 FROM invoice_items ii3 WHERE ii3.invoice_id = inv.id AND ii3.sale_type = 'wholesale'
      )
";
$mixed_stmt = $pdo->prepare($mixed_sql);
$mixed_stmt->execute(array_merge($base_params, $shop_param));
$mixed_data = $mixed_stmt->fetch();

// Payment breakdown (net of returns)
$payment_sql = "
    SELECT
        COALESCE(SUM(cash_amount), 0) as cash,
        COALESCE(SUM(upi_amount), 0) as upi,
        COALESCE(SUM(bank_amount), 0) as bank,
        COALESCE(SUM(cheque_amount), 0) as cheque,
        COALESCE(SUM(change_given), 0) as change_given,
        COALESCE(SUM(pending_amount), 0) as pending
    FROM invoices inv
    WHERE DATE(inv.created_at) BETWEEN ? AND ?
      AND inv.business_id = ?
      $shop_filter
";
$payment_stmt = $pdo->prepare($payment_sql);
$payment_stmt->execute(array_merge($base_params, $shop_param));
$payment_data = $payment_stmt->fetch();

// Expenses
$expenses_sql = "
    SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count
    FROM expenses e
    WHERE DATE(e.date) BETWEEN ? AND ?
      AND e.status = 'approved'
      AND e.business_id = ?
      " . ($selected_shop_id !== 'all' ? " AND e.shop_id = ?" : "");
$expenses_stmt = $pdo->prepare($expenses_sql);
$expenses_stmt->execute(array_merge($base_params, $shop_param));
$expenses_data = $expenses_stmt->fetch();

// Return Details from returns table - FIXED: Join with invoices to get shop_id
$return_sql = "
    SELECT 
        COUNT(*) as return_count,
        COALESCE(SUM(r.total_return_amount), 0) as return_amount,
        COUNT(DISTINCT r.invoice_id) as invoices_with_returns
    FROM returns r
    JOIN invoices inv ON r.invoice_id = inv.id
    WHERE DATE(r.return_date) BETWEEN ? AND ?
      AND r.business_id = ?
      " . ($selected_shop_id !== 'all' ? " AND inv.shop_id = ?" : "");
$return_stmt = $pdo->prepare($return_sql);
$return_stmt->execute(array_merge($base_params, $shop_param));
$return_data = $return_stmt->fetch();

// Net Profit after expenses
$net_profit = $accurate['net_profit_before_expenses'] - ($expenses_data['total'] ?? 0);

// Top Selling Products (after returns)
$top_products_sql = "
    SELECT
        p.product_name,
        p.product_code,
        SUM(ii.quantity) as total_quantity,
        SUM(ii.return_qty) as returned_quantity,
        (SUM(ii.quantity) - COALESCE(SUM(ii.return_qty), 0)) as net_quantity_sold,
        SUM(ii.unit_price * ii.quantity) as gross_sales,
        COALESCE(SUM(ii.return_qty * ii.unit_price), 0) as returns,
        (SUM(ii.unit_price * ii.quantity) - COALESCE(SUM(ii.return_qty * ii.unit_price), 0)) as net_sales,
        SUM(ii.profit) as gross_profit,
        COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0) as profit_loss,
        (SUM(ii.profit) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) as net_profit,
        CASE
            WHEN MIN(ii.sale_type) = MAX(ii.sale_type) AND MIN(ii.sale_type) IS NOT NULL THEN MIN(ii.sale_type)
            ELSE 'Mixed'
        END as sale_type
    FROM invoice_items ii
    JOIN invoices inv ON ii.invoice_id = inv.id
    JOIN products p ON ii.product_id = p.id
    WHERE DATE(inv.created_at) BETWEEN ? AND ?
      AND inv.business_id = ?
      $shop_filter
    GROUP BY p.id
    HAVING (SUM(ii.quantity) - COALESCE(SUM(ii.return_qty), 0)) > 0
    ORDER BY net_quantity_sold DESC
    LIMIT 10
";
$top_stmt = $pdo->prepare($top_products_sql);
$top_stmt->execute(array_merge($base_params, $shop_param));
$top_products = $top_stmt->fetchAll();

// Unique customers
$customer_sql = "
    SELECT COUNT(DISTINCT inv.customer_id) as unique_customers
    FROM invoices inv
    WHERE DATE(inv.created_at) BETWEEN ? AND ?
      AND inv.business_id = ?
      $shop_filter
      AND inv.customer_id IS NOT NULL
";
$cust_stmt = $pdo->prepare($customer_sql);
$cust_stmt->execute(array_merge($base_params, $shop_param));
$customer_count = $cust_stmt->fetchColumn() ?? 0;

$is_today = ($start_date === date('Y-m-d') && $end_date === date('Y-m-d'));
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Business Report" . ($is_single_day ? " - $display_date_from" : " - $display_date_from to $display_date_to"); ?>
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
                                    <i class="bx bx-bar-chart-alt-2 me-2"></i>
                                    Business Report
                                    <small class="text-muted ms-2">
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="mb-0 text-muted">
                                    <?php if ($is_single_day): ?>
                                        Daily Report - <?= $display_date_from ?>
                                    <?php else: ?>
                                        Period: <?= $display_date_from ?> to <?= $display_date_to ?>
                                    <?php endif; ?>
                                    • Branch: <strong><?= htmlspecialchars($shop_name) ?></strong>
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
                                    <small class="text-info">
                                        <i class="bx bx-calculator me-1"></i>
                                        Net Profit Margin: <?= number_format($net_profit_margin, 1) ?>%
                                    </small>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" onclick="printReport()">
                                    <i class="bx bx-printer me-1"></i> Print
                                </button>
                                <button class="btn btn-success" onclick="exportExcel()">
                                    <i class="bx bx-download me-1"></i> Export Excel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters (Hidden in Print) -->
                <div class="card shadow-sm mb-4 no-print">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-lg-3">
                                <label>From Date</label>
                                <input type="date" name="date_from" class="form-control" value="<?= $start_date ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-lg-3">
                                <label>To Date</label>
                                <input type="date" name="date_to" class="form-control" value="<?= $end_date ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                            <?php if ($user_role === 'admin'): ?>
                            <div class="col-lg-3">
                                <label>Branch</label>
                                <select name="shop_id" class="form-select">
                                    <option value="all" <?= $selected_shop_id === 'all' ? 'selected' : '' ?>>All Branches</option>
                                    <?php foreach ($all_shops as $shop): ?>
                                        <option value="<?= $shop['id'] ?>" <?= $selected_shop_id == $shop['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($shop['shop_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-lg-3">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bx bx-filter me-1"></i> Apply
                                </button>
                                <?php if (!$is_today): ?>
                                    <button type="button" class="btn btn-outline-info w-100 mt-2" onclick="goToToday()">
                                        <i class="bx bx-calendar-today"></i> Today
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Print Header (Visible only in Print) -->
                <div class="print-only" style="display: none;">
                    <div class="text-center mb-4">
                        <h2 class="mb-1"><?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?></h2>
                        <h4 class="text-primary">BUSINESS PERFORMANCE REPORT</h4>
                        <p class="mb-0">
                            <?php if ($is_single_day): ?>
                                Date: <strong><?= $display_date_from ?></strong>
                            <?php else: ?>
                                Period: <strong><?= $display_date_from ?> to <?= $display_date_to ?></strong>
                            <?php endif; ?>
                            | Branch: <strong><?= htmlspecialchars($shop_name) ?></strong>
                        </p>
                        <p class="text-muted mb-3">Generated on: <?= date('d M Y, h:i A') ?></p>
                        <hr class="my-3" style="border-color: #000;">
                    </div>
                </div>

                <!-- Summary Cards with Returns Information -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Net Sales<br><small>(After Returns)</small></h6>
                                <h3 class="text-primary">₹<?= number_format($accurate['net_sales'], 2) ?></h3>
                                <small>
                                    <?= $accurate['total_invoices'] ?> invoices
                                    <?php if ($accurate['total_returns'] > 0): ?>
                                    <br>
                                    <span class="text-danger">
                                        <i class="bx bx-undo me-1"></i>
                                        Returns: ₹<?= number_format($accurate['total_returns'], 2) ?>
                                    </span>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Net Profit<br><small>(After Returns)</small></h6>
                                <h3 class="text-success">₹<?= number_format($accurate['net_profit_before_expenses'], 2) ?></h3>
                                <small>
                                    <?= number_format($accurate['net_quantity_sold']) ?> units sold
                                    <?php if ($accurate['profit_loss_from_returns'] > 0): ?>
                                    <br>
                                    <span class="text-danger">
                                        <i class="bx bx-undo me-1"></i>
                                        Profit lost: ₹<?= number_format($accurate['profit_loss_from_returns'], 2) ?>
                                    </span>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Returns Summary</h6>
                                <h3 class="text-danger">₹<?= number_format($accurate['total_returns'], 2) ?></h3>
                                <small>
                                    <?= $return_data['return_count'] ?? 0 ?> returns
                                    <br>
                                    <?= $return_data['invoices_with_returns'] ?? 0 ?> invoices affected
                                    <?php if ($return_percentage > 0): ?>
                                    <br>
                                    <span class="text-warning">
                                        <?= number_format($return_percentage, 1) ?>% of gross sales
                                    </span>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-<?= $net_profit >= 0 ? 'info' : 'dark' ?> border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Final Net Profit<br><small>(After Expenses)</small></h6>
                                <h3 class="<?= $net_profit >= 0 ? 'text-info' : 'text-dark' ?>">₹<?= number_format($net_profit, 2) ?></h3>
                                <small>
                                    Expenses: ₹<?= number_format($expenses_data['total'] ?? 0, 2) ?>
                                    <?php if ($net_profit_margin > 0): ?>
                                    <br>
                                    <span class="text-success">
                                        Margin: <?= number_format($net_profit_margin, 1) ?>%
                                    </span>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sales Breakdown -->
                <div class="row mb-4">
                    <div class="col-xl-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bx bx-shopping-bag me-2"></i> Sales by Type (Net of Returns)</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 p-4 border-end">
                                        <h5 class="text-info">Retail Sales</h5>
                                        <h3 class="text-primary">₹<?= number_format($retail_data['net_sales'] ?? 0, 2) ?></h3>
                                        <p class="mb-1"><small><?= $retail_data['invoices'] ?? 0 ?> invoices</small></p>
                                        <small class="text-success">
                                            Net Profit: ₹<?= number_format($retail_data['net_profit'] ?? 0, 2) ?>
                                            <?php if ($retail_data['returns'] > 0): ?>
                                            <br>
                                            <span class="text-danger">
                                                Returns: ₹<?= number_format($retail_data['returns'] ?? 0, 2) ?>
                                            </span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="col-6 p-4">
                                        <h5 class="text-success">Wholesale Sales</h5>
                                        <h3 class="text-success">₹<?= number_format($wholesale_data['net_sales'] ?? 0, 2) ?></h3>
                                        <p class="mb-1"><small><?= $wholesale_data['invoices'] ?? 0 ?> invoices</small></p>
                                        <small class="text-success">
                                            Net Profit: ₹<?= number_format($wholesale_data['net_profit'] ?? 0, 2) ?>
                                            <?php if ($wholesale_data['returns'] > 0): ?>
                                            <br>
                                            <span class="text-danger">
                                                Returns: ₹<?= number_format($wholesale_data['returns'] ?? 0, 2) ?>
                                            </span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?php if ($mixed_data['invoices'] > 0): ?>
                                    <div class="col-12 mt-3 pt-3 border-top">
                                        <h6 class="text-secondary">Mixed Invoices</h6>
                                        <div class="row">
                                            <div class="col-4">
                                                <small>Invoices: <?= $mixed_data['invoices'] ?></small>
                                            </div>
                                            <div class="col-4">
                                                <small>Net Sales: ₹<?= number_format($mixed_data['net_sales'] ?? 0, 2) ?></small>
                                            </div>
                                            <div class="col-4">
                                                <small>
                                                    Returns: ₹<?= number_format($mixed_data['returns'] ?? 0, 2) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="bx bx-credit-card me-2"></i> Payment Summary</h5>
                                    <?php if ($accurate['total_returns'] > 0): ?>
                                    <span class="badge bg-warning">
                                        <i class="bx bx-undo me-1"></i> After Returns
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <?php
                                    $payments = [
                                        ['name' => 'Cash', 'amount' => $payment_data['cash'] ?? 0, 'color' => 'success'],
                                        ['name' => 'UPI', 'amount' => $payment_data['upi'] ?? 0, 'color' => 'primary'],
                                        ['name' => 'Bank', 'amount' => $payment_data['bank'] ?? 0, 'color' => 'info'],
                                        ['name' => 'Cheque', 'amount' => $payment_data['cheque'] ?? 0, 'color' => 'warning']
                                    ];
                                    $total_received = $payment_data['cash'] + $payment_data['upi'] + $payment_data['bank'] + $payment_data['cheque'];
                                    foreach ($payments as $p):
                                        if ($p['amount'] > 0):
                                            $perc = $total_received > 0 ? ($p['amount'] / $total_received) * 100 : 0;
                                    ?>
                                    <div class="col-6">
                                        <div class="text-center p-3 border rounded bg-light">
                                            <h6 class="text-<?= $p['color'] ?> fw-bold"><?= $p['name'] ?></h6>
                                            <h4 class="text-<?= $p['color'] ?>">₹<?= number_format($p['amount'], 2) ?></h4>
                                            <small class="text-muted"><?= number_format($perc, 1) ?>% of received</small>
                                        </div>
                                    </div>
                                    <?php endif; endforeach; ?>
                                </div>
                                <?php if ($payment_data['pending'] > 0): ?>
                                <div class="mt-3 pt-3 border-top text-center">
                                    <span class="text-danger fw-bold fs-5">
                                        <i class="bx bx-time-five me-2"></i>
                                        Pending Collection: ₹<?= number_format($payment_data['pending'], 2) ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <div class="mt-3 text-center">
                                    <small class="text-muted">
                                        Total Received: ₹<?= number_format($total_received, 2) ?>
                                        | Change Given: ₹<?= number_format($payment_data['change_given'] ?? 0, 2) ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Products -->
                <?php if (!empty($top_products)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="bx bx-trending-up me-2"></i> Top 10 Selling Products (Net of Returns)</h5>
                                    <span class="badge bg-success">
                                        <?= number_format($accurate['net_quantity_sold']) ?> units sold (net)
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th>Type</th>
                                                <th class="text-center">Qty Sold (Net)</th>
                                                <th class="text-end">Net Sales</th>
                                                <th class="text-end">Net Profit</th>
                                                <th class="text-center">Margin</th>
                                                <th class="text-center">Returns</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($top_products as $i => $p):
                                                $has_returns = $p['returned_quantity'] > 0;
                                                $margin = $p['net_sales'] > 0 ? ($p['net_profit'] / $p['net_sales']) * 100 : 0;
                                                $margin_class = $margin >= 30 ? 'bg-success text-white' : ($margin >= 15 ? 'bg-warning text-dark' : 'bg-danger text-white');
                                                $return_percent = $p['total_quantity'] > 0 ? ($p['returned_quantity'] / $p['total_quantity']) * 100 : 0;
                                            ?>
                                            <tr>
                                                <td><?= $i + 1 ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($p['product_name']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($p['product_code']) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $p['sale_type'] === 'retail' ? 'primary' : ($p['sale_type'] === 'wholesale' ? 'success' : 'secondary') ?>">
                                                        <?= ucfirst($p['sale_type']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center fw-bold">
                                                    <?= number_format($p['net_quantity_sold']) ?>
                                                    <?php if ($has_returns): ?>
                                                    <br>
                                                    <small class="text-danger">
                                                        (-<?= number_format($p['returned_quantity']) ?>)
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    ₹<?= number_format($p['net_sales'], 2) ?>
                                                    <?php if ($has_returns): ?>
                                                    <br>
                                                    <small class="text-danger">
                                                        <s>₹<?= number_format($p['gross_sales'], 2) ?></s>
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end text-success fw-bold">
                                                    ₹<?= number_format($p['net_profit'], 2) ?>
                                                    <?php if ($has_returns): ?>
                                                    <br>
                                                    <small class="text-danger">
                                                        <i class="bx bx-undo"></i> -₹<?= number_format($p['profit_loss'], 2) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge <?= $margin_class ?> rounded-pill px-3 py-2">
                                                        <?= number_format($margin, 1) ?>%
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($has_returns): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger">
                                                        <?= number_format($return_percent, 1) ?>%
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success">
                                                        None
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Footer Summary -->
                <div class="card bg-gradient bg-primary text-white shadow-lg">
                    <div class="card-body text-center py-5">
                        <div class="row">
                            <div class="col">
                                <h5>Gross Sales</h5>
                                <h2 class="display-6">₹<?= number_format($accurate['gross_sales'], 2) ?></h2>
                                <small><?= $accurate['total_invoices'] ?> invoices</small>
                            </div>
                            <div class="col">
                                <h5>Returns</h5>
                                <h2 class="display-6 text-danger">-₹<?= number_format($accurate['total_returns'], 2) ?></h2>
                                <small><?= number_format($return_percentage, 1) ?>% of gross</small>
                            </div>
                            <div class="col">
                                <h5>Net Sales</h5>
                                <h2 class="display-6 text-warning">₹<?= number_format($accurate['net_sales'], 2) ?></h2>
                                <small>After returns</small>
                            </div>
                            <div class="col">
                                <h5>Final Net Profit</h5>
                                <h2 class="display-6 <?= $net_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                                    ₹<?= number_format($net_profit, 2) ?>
                                </h2>
                                <small>After returns & expenses</small>
                            </div>
                        </div>
                        <hr class="bg-white opacity-50 my-4">
                        <div class="row">
                            <div class="col">
                                <small class="fs-6">
                                    <i class="bx bx-user me-1"></i> Unique Customers: <?= $customer_count ?>
                                </small>
                            </div>
                            <div class="col">
                                <small class="fs-6">
                                    <i class="bx bx-cube me-1"></i> Units Sold (Net): <?= number_format($accurate['net_quantity_sold']) ?>
                                </small>
                            </div>
                            <div class="col">
                                <small class="fs-6">
                                    <i class="bx bx-undo me-1"></i> Units Returned: <?= number_format($accurate['total_quantity_returned']) ?>
                                </small>
                            </div>
                            <div class="col">
                                <small class="fs-6">
                                    <i class="bx bx-calendar me-1"></i> Report: <?= date('d M Y, h:i A') ?>
                                </small>
                            </div>
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
// Go to today
function goToToday() {
    const today = new Date().toISOString().split('T')[0];
    window.location.href = `?date_from=${today}&date_to=${today}&shop_id=<?= $selected_shop_id ?>`;
}

// Print with professional PDF layout
function printReport() {
    // Show print header
    $('.print-only').show();
    
    // Hide unnecessary elements for print
    $('.no-print, .vertical-menu, .topbar, .footer, .rightbar, .btn').hide();
    
    // Change layout for better PDF formatting
    $('.card').removeClass('shadow-sm card-hover').addClass('print-card');
    $('.card-header').addClass('print-card-header');
    $('.card-body').addClass('print-card-body');
    
    // Remove gradients and colors for better printing
    $('.bg-gradient').removeClass('bg-gradient bg-primary text-white').addClass('bg-light text-dark border');
    
    // Convert to standard PDF layout (remove card boxes)
    $('.card').css({
        'border': '1px solid #ddd',
        'border-radius': '0',
        'margin-bottom': '10px',
        'box-shadow': 'none'
    });
    
    // Remove colored borders and use black only
    $('.border-start').css('border-left-color', '#000 !important');
    
    // Add page break before tables if needed
    if ($('.table-responsive').length > 0) {
        $('.table-responsive').css('page-break-inside', 'avoid');
    }
    
    // Open print dialog
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Business Report - <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?></title>
            <style>
                @page {
                    size: A4 portrait;
                    margin: 20mm;
                }
                body {
                    font-family: Arial, sans-serif;
                    font-size: 11pt;
                    line-height: 1.4;
                    color: #000;
                    margin: 0;
                    padding: 0;
                }
                .print-container {
                    max-width: 100%;
                }
                .print-header {
                    text-align: center;
                    margin-bottom: 20px;
                    border-bottom: 2px solid #000;
                    padding-bottom: 15px;
                }
                .print-header h2 {
                    font-size: 18pt;
                    margin: 5px 0;
                }
                .print-header h4 {
                    font-size: 14pt;
                    margin: 5px 0;
                }
                .print-header p {
                    font-size: 10pt;
                    margin: 3px 0;
                }
                .print-section {
                    margin-bottom: 15px;
                    page-break-inside: avoid;
                }
                .print-section-title {
                    font-size: 12pt;
                    font-weight: bold;
                    background-color: #f5f5f5;
                    padding: 8px;
                    margin-bottom: 10px;
                    border-bottom: 1px solid #ddd;
                }
                .print-grid {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 10px;
                    margin-bottom: 15px;
                }
                .print-card {
                    border: 1px solid #ddd;
                    padding: 10px;
                    text-align: center;
                    page-break-inside: avoid;
                }
                .print-card h6 {
                    font-size: 10pt;
                    margin: 5px 0;
                    font-weight: normal;
                }
                .print-card h3 {
                    font-size: 14pt;
                    margin: 8px 0;
                    font-weight: bold;
                }
                .print-card small {
                    font-size: 9pt;
                }
                .print-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 10px 0;
                    page-break-inside: avoid;
                }
                .print-table th {
                    background-color: #f5f5f5;
                    border: 1px solid #ddd;
                    padding: 8px;
                    font-size: 10pt;
                    text-align: left;
                    font-weight: bold;
                }
                .print-table td {
                    border: 1px solid #ddd;
                    padding: 6px;
                    font-size: 10pt;
                }
                .print-table .text-end {
                    text-align: right;
                }
                .print-table .text-center {
                    text-align: center;
                }
                .print-footer {
                    margin-top: 20px;
                    padding-top: 10px;
                    border-top: 2px solid #000;
                    page-break-inside: avoid;
                }
                .print-footer-grid {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 10px;
                }
                .print-footer-item {
                    text-align: center;
                }
                .print-footer-item h5 {
                    font-size: 10pt;
                    margin: 5px 0;
                }
                .print-footer-item h2 {
                    font-size: 16pt;
                    margin: 8px 0;
                    font-weight: bold;
                }
                .print-footer-item small {
                    font-size: 9pt;
                }
                .print-summary-row {
                    display: flex;
                    justify-content: space-between;
                    margin-top: 10px;
                    font-size: 10pt;
                }
                .print-metrics {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 10px;
                    margin-top: 10px;
                    font-size: 9pt;
                }
                .text-primary { color: #000 !important; }
                .text-success { color: #000 !important; }
                .text-danger { color: #000 !important; }
                .text-warning { color: #000 !important; }
                .text-info { color: #000 !important; }
                .badge {
                    background-color: #f5f5f5 !important;
                    color: #000 !important;
                    border: 1px solid #ddd;
                    padding: 2px 6px;
                    font-size: 9pt;
                }
                @media print {
                    .no-print { display: none !important; }
                    .print-only { display: block !important; }
                }
            </style>
        </head>
        <body>
            <div class="print-container">
                ${$('.print-only').html()}
                ${generatePrintContent()}
            </div>
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 500);
                };
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

// Generate content for print
function generatePrintContent() {
    let content = `
        <div class="print-section">
            <div class="print-section-title">Summary Overview</div>
            <div class="print-grid">
                <div class="print-card">
                    <h6>Net Sales<br><small>(After Returns)</small></h6>
                    <h3>₹${formatNumber(<?= $accurate['net_sales'] ?>, 2)}</h3>
                    <small>${<?= $accurate['total_invoices'] ?>} invoices</small>
                </div>
                <div class="print-card">
                    <h6>Net Profit<br><small>(After Returns)</small></h6>
                    <h3>₹${formatNumber(<?= $accurate['net_profit_before_expenses'] ?>, 2)}</h3>
                    <small>${formatNumber(<?= $accurate['net_quantity_sold'] ?>)} units sold</small>
                </div>
                <div class="print-card">
                    <h6>Returns Summary</h6>
                    <h3>₹${formatNumber(<?= $accurate['total_returns'] ?>, 2)}</h3>
                    <small>${<?= $return_data['return_count'] ?? 0 ?>} returns</small>
                </div>
                <div class="print-card">
                    <h6>Final Net Profit<br><small>(After Expenses)</small></h6>
                    <h3>₹${formatNumber(<?= $net_profit ?>, 2)}</h3>
                    <small>Expenses: ₹${formatNumber(<?= $expenses_data['total'] ?? 0 ?>, 2)}</small>
                </div>
            </div>
        </div>
    `;
    
    // Sales by Type
    content += `
        <div class="print-section">
            <div class="print-section-title">Sales by Type (Net of Returns)</div>
            <div class="print-grid">
                <div class="print-card">
                    <h6>Retail Sales</h6>
                    <h3>₹${formatNumber(<?= $retail_data['net_sales'] ?? 0 ?>, 2)}</h3>
                    <small>${<?= $retail_data['invoices'] ?? 0 ?>} invoices</small>
                </div>
                <div class="print-card">
                    <h6>Wholesale Sales</h6>
                    <h3>₹${formatNumber(<?= $wholesale_data['net_sales'] ?? 0 ?>, 2)}</h3>
                    <small>${<?= $wholesale_data['invoices'] ?? 0 ?>} invoices</small>
                </div>
            </div>
        </div>
    `;
    
    // Payment Summary
    content += `
        <div class="print-section">
            <div class="print-section-title">Payment Summary</div>
            <div class="print-grid">
                <div class="print-card">
                    <h6>Cash</h6>
                    <h3>₹${formatNumber(<?= $payment_data['cash'] ?? 0 ?>, 2)}</h3>
                </div>
                <div class="print-card">
                    <h6>UPI</h6>
                    <h3>₹${formatNumber(<?= $payment_data['upi'] ?? 0 ?>, 2)}</h3>
                </div>
                <div class="print-card">
                    <h6>Bank</h6>
                    <h3>₹${formatNumber(<?= $payment_data['bank'] ?? 0 ?>, 2)}</h3>
                </div>
                <div class="print-card">
                    <h6>Cheque</h6>
                    <h3>₹${formatNumber(<?= $payment_data['cheque'] ?? 0 ?>, 2)}</h3>
                </div>
            </div>
            <div class="print-summary-row">
                <span>Total Received: ₹${formatNumber(<?= $payment_data['cash'] + $payment_data['upi'] + $payment_data['bank'] + $payment_data['cheque'] ?>, 2)}</span>
                <span>Pending: ₹${formatNumber(<?= $payment_data['pending'] ?? 0 ?>, 2)}</span>
            </div>
        </div>
    `;
    
    // Top Products Table
    <?php if (!empty($top_products)): ?>
    content += `
        <div class="print-section">
            <div class="print-section-title">Top 10 Selling Products (Net of Returns)</div>
            <table class="print-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th class="text-center">Qty Sold</th>
                        <th class="text-end">Net Sales</th>
                        <th class="text-end">Net Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_products as $i => $p): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars(substr($p['product_name'], 0, 30)) . (strlen($p['product_name']) > 30 ? '...' : '') ?></td>
                        <td><?= ucfirst($p['sale_type']) ?></td>
                        <td class="text-center"><?= number_format($p['net_quantity_sold']) ?></td>
                        <td class="text-end">₹<?= number_format($p['net_sales'], 2) ?></td>
                        <td class="text-end">₹<?= number_format($p['net_profit'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    `;
    <?php endif; ?>
    
    // Final Summary
    content += `
        <div class="print-footer">
            <div class="print-footer-grid">
                <div class="print-footer-item">
                    <h5>Gross Sales</h5>
                    <h2>₹${formatNumber(<?= $accurate['gross_sales'] ?>, 2)}</h2>
                    <small>${<?= $accurate['total_invoices'] ?>} invoices</small>
                </div>
                <div class="print-footer-item">
                    <h5>Returns</h5>
                    <h2>₹${formatNumber(<?= $accurate['total_returns'] ?>, 2)}</h2>
                    <small>${<?= number_format($return_percentage, 1) ?>}% of gross</small>
                </div>
                <div class="print-footer-item">
                    <h5>Net Sales</h5>
                    <h2>₹${formatNumber(<?= $accurate['net_sales'] ?>, 2)}</h2>
                    <small>After returns</small>
                </div>
                <div class="print-footer-item">
                    <h5>Final Net Profit</h5>
                    <h2>₹${formatNumber(<?= $net_profit ?>, 2)}</h2>
                    <small>After returns & expenses</small>
                </div>
            </div>
            <div class="print-metrics">
                <div>Unique Customers: <?= $customer_count ?></div>
                <div>Units Sold (Net): <?= number_format($accurate['net_quantity_sold']) ?></div>
                <div>Units Returned: <?= number_format($accurate['total_quantity_returned']) ?></div>
                <div>Report: <?= date('d M Y, h:i A') ?></div>
            </div>
        </div>
    `;
    
    return content;
}

// Format number function
function formatNumber(num, decimals = 2) {
    return parseFloat(num).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// Export Excel with simple Indian Rupees format
function exportExcel() {
    // Create CSV content
    let csv = 'Business Report (Net of Returns)\n';
    csv += `Business Name,${document.querySelector('.page-title-box small').textContent.trim()}\n`;
    csv += `Report Period,<?= $is_single_day ? $display_date_from : "$display_date_from to $display_date_to" ?>\n`;
    csv += `Branch,${$('.card-header strong').text() || '<?= htmlspecialchars($shop_name) ?>'}\n`;
    csv += `Generated On,${new Date().toLocaleString('en-IN')}\n\n`;
    
    // Summary
    csv += 'SUMMARY\n';
    csv += `Total Invoices,${<?= $accurate['total_invoices'] ?>}\n`;
    csv += `Gross Sales,${<?= $accurate['gross_sales'] ?>}\n`;
    csv += `Total Returns,${<?= $accurate['total_returns'] ?>}\n`;
    csv += `Return Percentage,<?= number_format($return_percentage, 2) ?>%\n`;
    csv += `Net Sales (After Returns),${<?= $accurate['net_sales'] ?>}\n`;
    csv += `Gross Profit,${<?= $accurate['gross_profit'] ?>}\n`;
    csv += `Profit Lost to Returns,${<?= $accurate['profit_loss_from_returns'] ?>}\n`;
    csv += `Net Profit (Before Expenses),${<?= $accurate['net_profit_before_expenses'] ?>}\n`;
    csv += `Expenses,${<?= $expenses_data['total'] ?? 0 ?>}\n`;
    csv += `Final Net Profit,${<?= $net_profit ?>}\n\n`;
    
    // Sales by Type
    csv += 'SALES BY TYPE\n';
    csv += `Retail Invoices,${<?= $retail_data['invoices'] ?? 0 ?>}\n`;
    csv += `Retail Gross Sales,${<?= $retail_data['gross_sales'] ?? 0 ?>}\n`;
    csv += `Retail Returns,${<?= $retail_data['returns'] ?? 0 ?>}\n`;
    csv += `Retail Net Sales,${<?= $retail_data['net_sales'] ?? 0 ?>}\n`;
    csv += `Wholesale Invoices,${<?= $wholesale_data['invoices'] ?? 0 ?>}\n`;
    csv += `Wholesale Gross Sales,${<?= $wholesale_data['gross_sales'] ?? 0 ?>}\n`;
    csv += `Wholesale Returns,${<?= $wholesale_data['returns'] ?? 0 ?>}\n`;
    csv += `Wholesale Net Sales,${<?= $wholesale_data['net_sales'] ?? 0 ?>}\n\n`;
    
    // Payment Summary
    csv += 'PAYMENT SUMMARY\n';
    csv += `Cash,${<?= $payment_data['cash'] ?? 0 ?>}\n`;
    csv += `UPI,${<?= $payment_data['upi'] ?? 0 ?>}\n`;
    csv += `Bank,${<?= $payment_data['bank'] ?? 0 ?>}\n`;
    csv += `Cheque,${<?= $payment_data['cheque'] ?? 0 ?>}\n`;
    csv += `Total Received,${<?= $payment_data['cash'] + $payment_data['upi'] + $payment_data['bank'] + $payment_data['cheque'] ?>}\n`;
    csv += `Pending Collection,${<?= $payment_data['pending'] ?? 0 ?>}\n`;
    csv += `Change Given,${<?= $payment_data['change_given'] ?? 0 ?>}\n\n`;
    
    // Key Metrics
    csv += 'KEY METRICS\n';
    csv += `Unique Customers,${<?= $customer_count ?>}\n`;
    csv += `Units Sold (Gross),${<?= $accurate['total_quantity_sold'] ?>}\n`;
    csv += `Units Returned,${<?= $accurate['total_quantity_returned'] ?>}\n`;
    csv += `Units Sold (Net),${<?= $accurate['net_quantity_sold'] ?>}\n`;
    csv += `Net Profit Margin,<?= number_format($net_profit_margin, 2) ?>%\n`;
    csv += `Expense Count,${<?= $expenses_data['count'] ?? 0 ?>}\n`;
    
    // Top Products
    <?php if (!empty($top_products)): ?>
    csv += '\nTOP SELLING PRODUCTS\n';
    csv += 'Rank,Product Name,Product Code,Sale Type,Gross Qty,Returned Qty,Net Qty,Gross Sales,Returns,Net Sales,Net Profit\n';
    <?php foreach ($top_products as $i => $p): ?>
    csv += `<?= $i + 1 ?>,<?= htmlspecialchars($p['product_name']) ?>,<?= htmlspecialchars($p['product_code']) ?>,<?= $p['sale_type'] ?>,<?= $p['total_quantity'] ?>,<?= $p['returned_quantity'] ?>,<?= $p['net_quantity_sold'] ?>,${<?= $p['gross_sales'] ?>},${<?= $p['returns'] ?>},${<?= $p['net_sales'] ?>},${<?= $p['net_profit'] ?>}\n`;
    <?php endforeach; ?>
    <?php endif; ?>
    
    // Convert CSV to blob and download
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const filename = `Business_Report_${new Date().toISOString().split('T')[0]}_<?= $is_single_day ? $start_date : $start_date . '_to_' . $end_date ?>.csv`;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Auto-refresh today
<?php if ($is_today): ?>
setInterval(() => location.reload(), 300000); // 5 minutes
<?php endif; ?>
</script>

<style>
/* Print-specific styles */
@media print {
    @page {
        size: A4 portrait;
        margin: 15mm;
    }
    
    body {
        background: white !important;
        font-family: "Times New Roman", Times, serif !important;
        font-size: 12pt !important;
        line-height: 1.4 !important;
        color: black !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Hide all non-print elements */
    .no-print,
    .vertical-menu,
    .topbar,
    .footer,
    .rightbar,
    .btn,
    .page-title-box .d-flex:last-child,
    .d-flex.align-items-center.gap-2,
    .card-hover:hover {
        display: none !important;
    }
    
    /* Show print-only elements */
    .print-only {
        display: block !important;
    }
    
    /* Remove all decorative elements */
    .card {
        box-shadow: none !important;
        border: 1px solid #000 !important;
        border-radius: 0 !important;
        margin-bottom: 10px !important;
        page-break-inside: avoid;
        background: white !important;
    }
    
    .card-header {
        background: #f0f0f0 !important;
        color: black !important;
        border-bottom: 1px solid #000 !important;
        font-weight: bold;
        padding: 8px !important;
    }
    
    .card-body {
        padding: 10px !important;
    }
    
    /* Remove colored borders */
    .border-start {
        border-left-width: 3px !important;
        border-left-color: #000 !important;
    }
    
    /* Remove all text colors */
    .text-primary,
    .text-success,
    .text-danger,
    .text-warning,
    .text-info,
    .text-white {
        color: black !important;
    }
    
    /* Headings */
    h1, h2, h3, h4, h5, h6 {
        color: black !important;
        margin-bottom: 8px !important;
    }
    
    h3 {
        font-size: 14pt !important;
    }
    
    h5, h6 {
        font-size: 12pt !important;
    }
    
    /* Tables */
    .table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    
    .table th {
        background: #f0f0f0 !important;
        color: black !important;
        border: 1px solid #000 !important;
        padding: 6px !important;
        font-weight: bold;
        font-size: 10pt !important;
    }
    
    .table td {
        border: 1px solid #000 !important;
        padding: 4px !important;
        font-size: 10pt !important;
    }
    
    /* Badges */
    .badge {
        background: #f0f0f0 !important;
        color: black !important;
        border: 1px solid #000 !important;
        padding: 2px 6px !important;
        border-radius: 0 !important;
    }
    
    /* Remove gradients */
    .bg-gradient {
        background: #f0f0f0 !important;
        border: 2px solid #000 !important;
    }
    
    /* Summary sections */
    .row {
        display: flex !important;
        flex-wrap: wrap !important;
        margin-bottom: 10px !important;
    }
    
    .col-xl-3, .col-xl-6, .col-md-6 {
        flex: 0 0 auto !important;
        width: 50% !important;
        padding: 5px !important;
    }
    
    /* Small text */
    small {
        font-size: 9pt !important;
    }
    
    /* Remove hover effects */
    .card-hover {
        transform: none !important;
    }
    
    /* Print header */
    .print-only h2 {
        font-size: 16pt !important;
        margin-bottom: 5px !important;
    }
    
    .print-only h4 {
        font-size: 14pt !important;
        margin-bottom: 10px !important;
    }
    
    .print-only p {
        font-size: 10pt !important;
        margin-bottom: 3px !important;
    }
}

/* Screen-only styles */
@media screen {
    .print-only {
        display: none !important;
    }
    
    .card-hover:hover { 
        transform: translateY(-5px); 
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        box-shadow: 0 10px 20px rgba(0,0,0,0.15); 
    }
}

.bg-gradient { 
    background: linear-gradient(135deg, #007bff, #0056b3) !important; 
}
.border-start { 
    border-left-width: 4px !important; 
}
</style>
</body>
</html>