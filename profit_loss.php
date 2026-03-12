<?php
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
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$current_shop_name = $_SESSION['current_shop_name'] ?? 'All Shops';
$current_business_name = $_SESSION['current_business_name'] ?? 'Business';

// Only admin, shop_manager, and cashier can view reports
$can_view_reports = in_array($user_role, ['admin', 'shop_manager', 'cashier']);
if (!$can_view_reports) {
    $_SESSION['error'] = "Access denied. You don't have permission to view reports.";
    header('Location: dashboard.php');
    exit();
}

// ==================== FILTERS ====================
$selected_shop_id = $_GET['shop_id'] ?? 'all';

// For non-admin users, force their assigned shop
if ($user_role !== 'admin' && $current_shop_id) {
    $selected_shop_id = $current_shop_id;
}

// Date range filters - default to current month
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$period_type = $_GET['period'] ?? 'monthly';

// Get all shops for admin dropdown
$all_shops = [];
if ($user_role === 'admin') {
    $shop_query = "SELECT id, shop_name FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name";
    $shop_stmt = $pdo->prepare($shop_query);
    $shop_stmt->execute([$business_id]);
    $all_shops = $shop_stmt->fetchAll();
}

// ==================== GET SHOP NAME ====================
$shop_name = '';
if ($selected_shop_id !== 'all' && $selected_shop_id) {
    $shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
    $shop_stmt->execute([$selected_shop_id, $business_id]);
    $shop = $shop_stmt->fetch();
    $shop_name = $shop['shop_name'] ?? 'Selected Shop';
}

// Build shop filter for queries
$shop_filter = $selected_shop_id !== 'all' ? " AND i.shop_id = ?" : "";
$shop_param = $selected_shop_id !== 'all' ? [$selected_shop_id] : [];


// ==================== CALCULATE REVENUE (NO DUPLICATE) ====================
$revenue_sql = "
SELECT
    invAgg.total_gross_revenue,
    COALESCE(retAgg.total_returns, 0) AS total_returns,
    (invAgg.total_gross_revenue - COALESCE(retAgg.total_returns, 0)) AS total_net_revenue,

    invAgg.cash_revenue,
    invAgg.upi_revenue,
    invAgg.bank_revenue,
    invAgg.cheque_revenue,

    invAgg.total_invoices,
    invAgg.unique_customers
FROM
(
    SELECT
        COALESCE(SUM(i.total), 0) AS total_gross_revenue,
        COALESCE(SUM(i.cash_amount), 0) AS cash_revenue,
        COALESCE(SUM(i.upi_amount), 0) AS upi_revenue,
        COALESCE(SUM(i.bank_amount), 0) AS bank_revenue,
        COALESCE(SUM(i.cheque_amount), 0) AS cheque_revenue,
        COUNT(*) AS total_invoices,
        COUNT(DISTINCT i.customer_id) AS unique_customers
    FROM invoices i
    WHERE i.business_id = ?
      AND DATE(i.created_at) BETWEEN ? AND ?
      " . ($selected_shop_id !== 'all' ? " AND i.shop_id = ? " : "") . "
) invAgg
LEFT JOIN
(
    SELECT
        COALESCE(SUM(ii.return_qty * ii.unit_price), 0) AS total_returns
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.business_id = ?
      AND DATE(i.created_at) BETWEEN ? AND ?
      " . ($selected_shop_id !== 'all' ? " AND i.shop_id = ? " : "") . "
) retAgg ON 1=1
";

$revenue_params = [$business_id, $date_from, $date_to];
if ($selected_shop_id !== 'all') $revenue_params[] = $selected_shop_id;

// retAgg params again
$revenue_params = array_merge($revenue_params, [$business_id, $date_from, $date_to]);
if ($selected_shop_id !== 'all') $revenue_params[] = $selected_shop_id;

$revenue_stmt = $pdo->prepare($revenue_sql);
$revenue_stmt->execute($revenue_params);
$revenue_data = $revenue_stmt->fetch(PDO::FETCH_ASSOC);

$total_gross_revenue = $revenue_data['total_gross_revenue'] ?? 0;
$total_returns       = $revenue_data['total_returns'] ?? 0;
$total_net_revenue   = $revenue_data['total_net_revenue'] ?? 0;


// ==================== CALCULATE COST OF GOODS SOLD (COGS) WITH RETURNS ====================
$cogs_sql = "
    SELECT 
        COALESCE(SUM(ii.quantity * p.stock_price), 0) as total_gross_cogs,
        COALESCE(SUM(ii.return_qty * p.stock_price), 0) as cogs_returns,
        (COALESCE(SUM(ii.quantity * p.stock_price), 0) - COALESCE(SUM(ii.return_qty * p.stock_price), 0)) as total_net_cogs,
        COALESCE(SUM(ii.profit), 0) as gross_profit,
        COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0) as profit_loss_returns,
        (COALESCE(SUM(ii.profit), 0) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) as net_profit_before_expenses,
        COUNT(DISTINCT ii.product_id) as unique_products_sold
    FROM invoice_items ii
    JOIN invoices i ON ii.invoice_id = i.id
    JOIN products p ON ii.product_id = p.id
    WHERE i.business_id = ? AND DATE(i.created_at) BETWEEN ? AND ?
";

if ($selected_shop_id !== 'all') {
    $cogs_sql .= " AND i.shop_id = ?";
}

$cogs_stmt = $pdo->prepare($cogs_sql);
$cogs_params = [$business_id, $date_from, $date_to];
if ($selected_shop_id !== 'all') {
    $cogs_params[] = $selected_shop_id;
}
$cogs_stmt->execute($cogs_params);
$cogs_data = $cogs_stmt->fetch();

$total_gross_cogs = $cogs_data['total_gross_cogs'] ?? 0;
$cogs_returns = $cogs_data['cogs_returns'] ?? 0;
$total_net_cogs = $cogs_data['total_net_cogs'] ?? 0;
$gross_profit = $cogs_data['gross_profit'] ?? 0;
$profit_loss_returns = $cogs_data['profit_loss_returns'] ?? 0;
$net_profit_before_expenses = $cogs_data['net_profit_before_expenses'] ?? 0;

// ==================== CALCULATE REFERRAL PAYMENTS ====================
$referral_sql = "
    SELECT 
        COALESCE(SUM(amount), 0) as total_referral_payments,
        COUNT(*) as referral_payment_count,
        GROUP_CONCAT(DISTINCT referral_id) as referral_ids
    FROM referral_payments 
    WHERE business_id = ? 
      AND DATE(paid_at) BETWEEN ? AND ?
";

$referral_params = [$business_id, $date_from, $date_to];

$referral_stmt = $pdo->prepare($referral_sql);
$referral_stmt->execute($referral_params);
$referral_data = $referral_stmt->fetch();

$total_referral_payments = $referral_data['total_referral_payments'] ?? 0;
$referral_payment_count = $referral_data['referral_payment_count'] ?? 0;

// Get referral payment details
$referral_details_sql = "
    SELECT 
        rp.*,
        rp.referral_id,
        ref.full_name as referral_name,
        ref.referral_code
    FROM referral_payments rp
    LEFT JOIN referral_person ref ON rp.referral_id = ref.id AND ref.business_id = rp.business_id
    WHERE rp.business_id = ? 
      AND DATE(rp.paid_at) BETWEEN ? AND ?
    ORDER BY rp.paid_at DESC
";

$referral_details_stmt = $pdo->prepare($referral_details_sql);
$referral_details_stmt->execute([$business_id, $date_from, $date_to]);
$referral_payments_list = $referral_details_stmt->fetchAll();

// ==================== CALCULATE EXPENSES BY CATEGORY ====================
$expenses_sql = "
    SELECT 
        category,
        COUNT(*) as expense_count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM expenses 
    WHERE business_id = ? AND DATE(date) BETWEEN ? AND ? AND status = 'approved'
";

if ($selected_shop_id !== 'all') {
    $expenses_sql .= " AND shop_id = ?";
}

$expenses_sql .= " GROUP BY category ORDER BY total_amount DESC";

$expenses_stmt = $pdo->prepare($expenses_sql);
$expenses_params = [$business_id, $date_from, $date_to];
if ($selected_shop_id !== 'all') {
    $expenses_params[] = $selected_shop_id;
}
$expenses_stmt->execute($expenses_params);
$expenses_by_category = $expenses_stmt->fetchAll();

// Total expenses (including referral payments as an expense)
$total_expenses = 0;
foreach ($expenses_by_category as $expense) {
    $total_expenses += $expense['total_amount'];
}
$total_expenses += $total_referral_payments;

// ==================== CALCULATE PROFIT/LOSS WITH RETURNS AND REFERRALS ====================
$gross_profit_margin = ($total_gross_revenue > 0) ? ($gross_profit / $total_gross_revenue) * 100 : 0;
$net_profit_after_returns = $net_profit_before_expenses - $total_expenses;
$net_profit_margin = ($total_net_revenue > 0) ? ($net_profit_after_returns / $total_net_revenue) * 100 : 0;
$expense_percentage = ($total_net_revenue > 0) ? ($total_expenses / $total_net_revenue) * 100 : 0;
$referral_percentage = ($total_net_revenue > 0) ? ($total_referral_payments / $total_net_revenue) * 100 : 0;

// ==================== MONTHLY COMPARISON WITH RETURNS ====================
$previous_month_from = date('Y-m-01', strtotime($date_from . ' -1 month'));
$previous_month_to = date('Y-m-t', strtotime($date_to . ' -1 month'));

// Previous month revenue with returns
$prev_revenue_sql = "
    SELECT 
        COALESCE(SUM(i.total), 0) as total_gross_revenue,
        COALESCE(SUM(ii.return_qty * ii.unit_price), 0) as total_returns,
        (COALESCE(SUM(i.total), 0) - COALESCE(SUM(ii.return_qty * ii.unit_price), 0)) as total_net_revenue
    FROM invoices i
    LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
    WHERE i.business_id = ? AND DATE(i.created_at) BETWEEN ? AND ?
";

if ($selected_shop_id !== 'all') {
    $prev_revenue_sql .= " AND i.shop_id = ?";
}

$prev_revenue_stmt = $pdo->prepare($prev_revenue_sql);
$prev_revenue_params = [$business_id, $previous_month_from, $previous_month_to];
if ($selected_shop_id !== 'all') {
    $prev_revenue_params[] = $selected_shop_id;
}
$prev_revenue_stmt->execute($prev_revenue_params);
$prev_revenue_data = $prev_revenue_stmt->fetch();
$prev_month_revenue = $prev_revenue_data['total_net_revenue'] ?? 0;

// Revenue growth
$revenue_growth = 0;
if ($prev_month_revenue > 0) {
    $revenue_growth = (($total_net_revenue - $prev_month_revenue) / $prev_month_revenue) * 100;
}

// ==================== TOP PRODUCTS BY PROFIT (AFTER RETURNS) ====================
$top_products_sql = "
    SELECT 
        p.product_name,
        p.product_code,
        SUM(ii.quantity) as total_quantity,
        SUM(ii.return_qty) as returned_quantity,
        (SUM(ii.quantity) - COALESCE(SUM(ii.return_qty), 0)) as net_quantity,
        SUM(ii.total_price) as total_sales,
        SUM(ii.return_qty * ii.unit_price) as total_returns,
        (SUM(ii.total_price) - COALESCE(SUM(ii.return_qty * ii.unit_price), 0)) as net_sales,
        SUM(ii.profit) as gross_profit,
        COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0) as profit_loss_returns,
        (SUM(ii.profit) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) as net_profit,
        ROUND(((SUM(ii.profit) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) / 
               NULLIF((SUM(ii.total_price) - COALESCE(SUM(ii.return_qty * ii.unit_price), 0)), 0) * 100), 1) as net_profit_margin
    FROM invoice_items ii
    JOIN invoices i ON ii.invoice_id = i.id
    JOIN products p ON ii.product_id = p.id
    WHERE i.business_id = ? AND DATE(i.created_at) BETWEEN ? AND ?
";

if ($selected_shop_id !== 'all') {
    $top_products_sql .= " AND i.shop_id = ?";
}

$top_products_sql .= " GROUP BY p.id HAVING (SUM(ii.profit) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) > 0 
                       ORDER BY (SUM(ii.profit) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) DESC LIMIT 5";

$top_products_stmt = $pdo->prepare($top_products_sql);
$top_products_params = [$business_id, $date_from, $date_to];
if ($selected_shop_id !== 'all') {
    $top_products_params[] = $selected_shop_id;
}
$top_products_stmt->execute($top_products_params);
$top_products = $top_products_stmt->fetchAll();

// ==================== LOWEST PROFIT PRODUCTS (AFTER RETURNS) ====================
$low_profit_sql = "
    SELECT 
        p.product_name,
        p.product_code,
        SUM(ii.quantity) as total_quantity,
        SUM(ii.return_qty) as returned_quantity,
        (SUM(ii.quantity) - COALESCE(SUM(ii.return_qty), 0)) as net_quantity,
        SUM(ii.total_price) as total_sales,
        SUM(ii.return_qty * ii.unit_price) as total_returns,
        (SUM(ii.total_price) - COALESCE(SUM(ii.return_qty * ii.unit_price), 0)) as net_sales,
        SUM(ii.profit) as gross_profit,
        COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0) as profit_loss_returns,
        (SUM(ii.profit) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) as net_profit,
        ROUND(((SUM(ii.profit) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) / 
               NULLIF((SUM(ii.total_price) - COALESCE(SUM(ii.return_qty * ii.unit_price), 0)), 0) * 100), 1) as net_profit_margin
    FROM invoice_items ii
    JOIN invoices i ON ii.invoice_id = i.id
    JOIN products p ON ii.product_id = p.id
    WHERE i.business_id = ? AND DATE(i.created_at) BETWEEN ? AND ?
";

if ($selected_shop_id !== 'all') {
    $low_profit_sql .= " AND i.shop_id = ?";
}

$low_profit_sql .= " GROUP BY p.id HAVING (SUM(ii.profit) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) > 0 
                     ORDER BY (SUM(ii.profit) - COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0)) ASC LIMIT 5";

$low_profit_stmt = $pdo->prepare($low_profit_sql);
$low_profit_params = [$business_id, $date_from, $date_to];
if ($selected_shop_id !== 'all') {
    $low_profit_params[] = $selected_shop_id;
}
$low_profit_stmt->execute($low_profit_params);
$low_profit_products = $low_profit_stmt->fetchAll();

// Format dates for display
$display_date_from = date('d M Y', strtotime($date_from));
$display_date_to = date('d M Y', strtotime($date_to));

// Check if period is current month
$is_current_month = (date('Y-m') === date('Y-m', strtotime($date_from)));

// Return summary
$return_summary = [
    'total_returns' => $total_returns,
    'cogs_returns' => $cogs_returns,
    'profit_loss_returns' => $profit_loss_returns,
    'return_percentage' => ($total_gross_revenue > 0) ? ($total_returns / $total_gross_revenue) * 100 : 0
];
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Profit & Loss Statement";
if ($selected_shop_id !== 'all' && $shop_name) {
    $page_title .= " - " . htmlspecialchars($shop_name);
} elseif ($selected_shop_id === 'all') {
    $page_title .= " - All Shops";
}
?>
<?php include 'includes/head.php'; ?>
<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
.performance-card {
    transition: all 0.3s ease;
    border-left: 4px solid;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.performance-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}
.profit-indicator {
    height: 6px;
    background: #e0e0e0;
    border-radius: 3px;
    overflow: hidden;
}
.profit-fill {
    height: 100%;
    border-radius: 3px;
}
.metric-badge {
    font-size: 0.75rem;
    padding: 2px 8px;
    border-radius: 12px;
}
.return-badge {
    font-size: 0.7rem;
    padding: 1px 6px;
    border-radius: 10px;
}
.referral-badge {
    font-size: 0.75rem;
    padding: 2px 8px;
    border-radius: 12px;
    background-color: #6f42c1;
    color: white;
}
@media print {
    .no-print, .vertical-menu, .topbar, .page-title-box > div:last-child, .btn { 
        display: none !important; 
    }
    body { background: white !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    .card-header { background: #f8f9fa !important; border-bottom: 2px solid #000 !important; }
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
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-line-chart me-2"></i> Profit & Loss Statement
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-calendar me-1"></i> <?= $display_date_from ?> - <?= $display_date_to ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($current_business_name) ?>
                                    <?php if ($selected_shop_id === 'all'): ?>
                                        | <i class="bx bx-store me-1"></i> All Shops
                                    <?php else: ?>
                                        | <i class="bx bx-store me-1"></i> <?= htmlspecialchars($shop_name) ?>
                                    <?php endif; ?>
                                    <?php if ($total_returns > 0): ?>
                                        | <span class="text-danger">
                                            <i class="bx bx-undo me-1"></i> Returns: ₹<?= number_format($total_returns, 2) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($total_referral_payments > 0): ?>
                                        | <span class="text-purple" style="color: #6f42c1;">
                                            <i class="bx bx-user-voice me-1"></i> Referrals: ₹<?= number_format($total_referral_payments, 2) ?>
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-dark" onclick="printPnlStatement()">
                                    <i class="bx bx-printer me-1"></i> Print
                                </button>
                                <button type="button" class="btn btn-outline-primary" onclick="exportToExcel()">
                                    <i class="bx bx-download me-1"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter P&L Statement
                        </h5>
                        <form method="GET" id="pnlForm" class="row g-3">
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">From Date</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bx bx-calendar"></i>
                                    </span>
                                    <input type="date" name="date_from" class="form-control" 
                                        value="<?= $date_from ?>" max="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">To Date</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bx bx-calendar"></i>
                                    </span>
                                    <input type="date" name="date_to" class="form-control" 
                                        value="<?= $date_to ?>" max="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            
                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">Quick Period</label>
                                <select class="form-select" onchange="quickPeriod(this.value)">
                                    <option value="">Select Period</option>
                                    <option value="today">Today</option>
                                    <option value="yesterday">Yesterday</option>
                                    <option value="this_week">This Week</option>
                                    <option value="last_week">Last Week</option>
                                    <option value="this_month" selected>This Month</option>
                                    <option value="last_month">Last Month</option>
                                    <option value="this_quarter">This Quarter</option>
                                    <option value="last_quarter">Last Quarter</option>
                                    <option value="this_year">This Year</option>
                                    <option value="last_year">Last Year</option>
                                </select>
                            </div>
                            
                            <?php if ($user_role === 'admin'): ?>
                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">Select Shop</label>
                                <select class="form-select" name="shop_id">
                                    <option value="all" <?= $selected_shop_id === 'all' ? 'selected' : '' ?>>All Shops</option>
                                    <?php foreach ($all_shops as $shop): ?>
                                        <option value="<?= $shop['id'] ?>" 
                                            <?= $selected_shop_id == $shop['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($shop['shop_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-lg-2 col-md-12">
                                <label class="form-label d-none d-md-block">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary flex-grow-1">
                                        <i class="bx bx-filter me-1"></i> Apply Filters
                                    </button>
                                    <?php if ($date_from != date('Y-m-01') || $date_to != date('Y-m-t') || $selected_shop_id != 'all'): ?>
                                    <a href="profit_loss.php" class="btn btn-outline-secondary">
                                        <i class="bx bx-reset me-1"></i> Clear
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Net Revenue</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($total_net_revenue, 0) ?></h3>
                                        <small class="text-muted"><?= $revenue_data['total_invoices'] ?? 0 ?> invoices</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                                <?php if ($total_returns > 0): ?>
                                <div class="mt-3">
                                    <small class="text-danger">
                                        <i class="bx bx-undo me-1"></i>
                                        Returns: -₹<?= number_format($total_returns, 2) ?>
                                        <span class="text-muted">(<?= number_format($return_summary['return_percentage'], 1) ?>%)</span>
                                    </small>
                                </div>
                                <?php endif; ?>
                                <?php if ($revenue_growth != 0): ?>
                                <div class="mt-2">
                                    <small class="text-<?= $revenue_growth >= 0 ? 'success' : 'danger' ?>">
                                        <i class="bx bx-<?= $revenue_growth >= 0 ? 'up-arrow-alt' : 'down-arrow-alt' ?> me-1"></i>
                                        <?= number_format(abs($revenue_growth), 1) ?>% from previous period
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Net Profit Before Expenses</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($net_profit_before_expenses, 0) ?></h3>
                                        <small class="text-muted">After returns</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-trending-up text-success"></i>
                                        </span>
                                    </div>
                                </div>
                                <?php if ($profit_loss_returns > 0): ?>
                                <div class="mt-3">
                                    <small class="text-danger">
                                        <i class="bx bx-undo me-1"></i>
                                        Profit lost to returns: -₹<?= number_format($profit_loss_returns, 2) ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                                <div class="mt-3">
                                    <div class="profit-indicator">
                                        <div class="profit-fill bg-success" style="width: <?= min($gross_profit_margin, 100) ?>%"></div>
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
                                        <h6 class="text-muted mb-1">Total Expenses</h6>
                                        <h3 class="mb-0 text-danger">₹<?= number_format($total_expenses, 0) ?></h3>
                                        <small class="text-muted"><?= count($expenses_by_category) + ($total_referral_payments > 0 ? 1 : 0) ?> categories</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-money-withdraw text-danger"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <small class="text-danger">Expense Ratio: <?= number_format($expense_percentage, 1) ?>% of net revenue</small>
                                </div>
                                <?php if ($total_referral_payments > 0): ?>
                                <div class="mt-2">
                                    <small style="color: #6f42c1;">
                                        <i class="bx bx-user-voice me-1"></i>
                                        Referral Payments: ₹<?= number_format($total_referral_payments, 2) ?>
                                        <span class="text-muted">(<?= number_format($referral_percentage, 1) ?>%)</span>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-<?= $net_profit_after_returns >= 0 ? 'warning' : 'dark' ?> border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Net Profit/Loss</h6>
                                        <h3 class="mb-0 text-<?= $net_profit_after_returns >= 0 ? 'warning' : 'dark' ?>">₹<?= number_format($net_profit_after_returns, 0) ?></h3>
                                        <small class="text-muted">Final after returns & expenses</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-<?= $net_profit_after_returns >= 0 ? 'warning' : 'dark' ?> bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-<?= $net_profit_after_returns >= 0 ? 'like' : 'dislike' ?> text-<?= $net_profit_after_returns >= 0 ? 'warning' : 'dark' ?>"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <div class="profit-indicator">
                                        <div class="profit-fill bg-<?= $net_profit_after_returns >= 0 ? 'warning' : 'dark' ?>" style="width: <?= min(abs($net_profit_margin), 100) ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Return Summary Card -->
                <?php if ($total_returns > 0 || $total_referral_payments > 0): ?>
                <div class="card shadow-sm border-<?= $total_returns > 0 ? 'danger' : 'purple' ?> mb-4">
                    <div class="card-header bg-<?= $total_returns > 0 ? 'danger' : 'purple' ?> bg-opacity-10 text-<?= $total_returns > 0 ? 'danger' : 'purple' ?> border-<?= $total_returns > 0 ? 'danger' : 'purple' ?>">
                        <h5 class="mb-0">
                            <?php if ($total_returns > 0): ?>
                            <i class="bx bx-undo me-2"></i> Returns Summary
                            <span class="badge bg-danger float-end"><?= number_format($return_summary['return_percentage'], 1) ?>% of gross revenue</span>
                            <?php else: ?>
                            <i class="bx bx-user-voice me-2" style="color: #6f42c1;"></i> Referral Payments Summary
                            <span class="badge float-end" style="background-color: #6f42c1;"><?= $referral_payment_count ?> payments</span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php if ($total_returns > 0): ?>
                            <div class="col-md-4">
                                <div class="text-center p-3 border border-danger rounded bg-light">
                                    <h3 class="text-danger mb-2">₹<?= number_format($total_returns, 2) ?></h3>
                                    <small class="text-muted">Total Returns Value</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-3 border border-danger rounded bg-light">
                                    <h3 class="text-danger mb-2">₹<?= number_format($cogs_returns, 2) ?></h3>
                                    <small class="text-muted">COGS Recovered</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-3 border border-danger rounded bg-light">
                                    <h3 class="text-danger mb-2">₹<?= number_format($profit_loss_returns, 2) ?></h3>
                                    <small class="text-muted">Profit Lost</small>
                                </div>
                            </div>
                            <?php elseif ($total_referral_payments > 0): ?>
                            <div class="col-md-6">
                                <div class="text-center p-3 border rounded bg-light" style="border-color: #6f42c1 !important;">
                                    <h3 style="color: #6f42c1; margin-bottom: 0.5rem;">₹<?= number_format($total_referral_payments, 2) ?></h3>
                                    <small class="text-muted">Total Referral Payments</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-center p-3 border rounded bg-light" style="border-color: #6f42c1 !important;">
                                    <h3 style="color: #6f42c1; margin-bottom: 0.5rem;"><?= $referral_payment_count ?></h3>
                                    <small class="text-muted">Number of Payments</small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($referral_payments_list)): ?>
                        <div class="mt-4">
                            <h6 class="mb-3"><i class="bx bx-list-ul me-2" style="color: #6f42c1;"></i> Referral Payment Details</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Referral Name</th>
                                            <th>Code</th>
                                            <th>Amount</th>
                                            <th>Payment Method</th>
                                            <th>Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($referral_payments_list as $payment): ?>
                                        <tr>
                                            <td><?= date('d M Y', strtotime($payment['paid_at'])) ?></td>
                                            <td><?= htmlspecialchars($payment['referral_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($payment['referral_code'] ?? 'N/A') ?></td>
                                            <td class="text-end fw-bold" style="color: #6f42c1;">₹<?= number_format($payment['amount'], 2) ?></td>
                                            <td><?= ucfirst($payment['payment_method']) ?></td>
                                            <td><?= htmlspecialchars($payment['reference'] ?? '-') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Main P&L Statement -->
                    <div class="col-lg-8">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bx bx-file me-2"></i> Profit & Loss Statement
                                        <small class="text-muted ms-2">
                                            (<?= $display_date_from ?> to <?= $display_date_to ?>)
                                        </small>
                                    </h5>
                                    <div class="d-flex gap-2">
                                        <?php if ($net_profit_after_returns >= 0): ?>
                                        <span class="badge bg-success">
                                            <i class="bx bx-check-circle me-1"></i> PROFIT
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-danger">
                                            <i class="bx bx-x-circle me-1"></i> LOSS
                                        </span>
                                        <?php endif; ?>
                                        <span class="badge bg-primary">
                                            <?= $revenue_data['unique_customers'] ?? 0 ?> Customers
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Revenue Section with Returns -->
                                <div class="mb-4">
                                    <h6 class="text-primary mb-3">
                                        <i class="bx bx-rupee me-2"></i> Revenue (Net of Returns)
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <tbody>
                                                <tr>
                                                    <td width="70%" class="ps-4">
                                                        <strong>Gross Sales Revenue</strong>
                                                        <div class="text-muted small">Before returns</div>
                                                    </td>
                                                    <td width="30%" class="text-end text-primary">
                                                        ₹<?= number_format($total_gross_revenue, 2) ?>
                                                    </td>
                                                </tr>
                                                <?php if ($total_returns > 0): ?>
                                                <tr>
                                                    <td class="ps-5">
                                                        <i class="bx bx-undo me-2 text-danger"></i>Less: Returns
                                                    </td>
                                                    <td class="text-end text-danger">
                                                        -₹<?= number_format($total_returns, 2) ?>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr class="border-top">
                                                    <td class="ps-4 pt-3">
                                                        <strong>Net Sales Revenue</strong>
                                                        <div class="text-muted small">After returns</div>
                                                    </td>
                                                    <td class="text-end pt-3 fw-bold text-primary fs-5">
                                                        ₹<?= number_format($total_net_revenue, 2) ?>
                                                    </td>
                                                </tr>
                                                
                                                <!-- Payment Methods Breakdown -->
                                                <tr class="border-top">
                                                    <td colspan="2" class="pt-2">
                                                        <small class="text-muted">Payment Methods Breakdown:</small>
                                                    </td>
                                                </tr>
                                                <?php if ($revenue_data['cash_revenue'] > 0): ?>
                                                <tr>
                                                    <td class="ps-5">
                                                        <i class="bx bx-money me-2 text-success"></i>Cash
                                                    </td>
                                                    <td class="text-end">
                                                        ₹<?= number_format($revenue_data['cash_revenue'] ?? 0, 2) ?>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($revenue_data['upi_revenue'] > 0): ?>
                                                <tr>
                                                    <td class="ps-5">
                                                        <i class="bx bx-qr-scan me-2 text-info"></i>UPI
                                                    </td>
                                                    <td class="text-end">
                                                        ₹<?= number_format($revenue_data['upi_revenue'] ?? 0, 2) ?>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($revenue_data['bank_revenue'] > 0): ?>
                                                <tr>
                                                    <td class="ps-5">
                                                        <i class="bx bx-credit-card me-2 text-primary"></i>Bank Transfer
                                                    </td>
                                                    <td class="text-end">
                                                        ₹<?= number_format($revenue_data['bank_revenue'] ?? 0, 2) ?>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($revenue_data['cheque_revenue'] > 0): ?>
                                                <tr>
                                                    <td class="ps-5">
                                                        <i class="bx bx-receipt me-2 text-warning"></i>Cheque
                                                    </td>
                                                    <td class="text-end">
                                                        ₹<?= number_format($revenue_data['cheque_revenue'] ?? 0, 2) ?>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Cost of Goods Sold with Returns -->
                                <div class="mb-4">
                                    <h6 class="text-danger mb-3">
                                        <i class="bx bx-package me-2"></i> Cost of Goods Sold (Net of Returns)
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <tbody>
                                                <tr>
                                                    <td width="70%" class="ps-4">
                                                        <strong>Gross Cost of Goods Sold</strong>
                                                        <div class="text-muted small">Before returns</div>
                                                    </td>
                                                    <td width="30%" class="text-end text-danger">
                                                        ₹<?= number_format($total_gross_cogs, 2) ?>
                                                    </td>
                                                </tr>
                                                <?php if ($cogs_returns > 0): ?>
                                                <tr>
                                                    <td class="ps-5">
                                                        <i class="bx bx-undo me-2 text-success"></i>Less: COGS from Returns
                                                        <small class="text-muted d-block">(Cost recovered from returns)</small>
                                                    </td>
                                                    <td class="text-end text-success">
                                                        -₹<?= number_format($cogs_returns, 2) ?>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr class="border-top">
                                                    <td class="ps-4 pt-3">
                                                        <strong>Net Cost of Goods Sold</strong>
                                                        <div class="text-muted small">After returns</div>
                                                    </td>
                                                    <td class="text-end pt-3 fw-bold text-danger fs-5">
                                                        ₹<?= number_format($total_net_cogs, 2) ?>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Gross Profit Section with Returns -->
                                <div class="mb-4 border-top pt-4">
                                    <div class="d-flex justify-content-between align-items-center bg-light rounded p-3">
                                        <div>
                                            <h5 class="text-success mb-1">
                                                <i class="bx bx-trending-up me-2"></i> Gross Profit (Net of Returns)
                                            </h5>
                                            <small class="text-muted">
                                                Net Gross Profit Margin: <?= number_format($gross_profit_margin, 1) ?>%
                                                <?php if ($profit_loss_returns > 0): ?>
                                                <span class="text-danger ms-2">
                                                    <i class="bx bx-undo me-1"></i>Profit lost to returns: -₹<?= number_format($profit_loss_returns, 2) ?>
                                                </span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <h2 class="text-success mb-0">₹<?= number_format($net_profit_before_expenses, 2) ?></h2>
                                    </div>
                                </div>

                                <!-- Expenses -->
                                <div class="mb-4">
                                    <h6 class="text-danger mb-3">
                                        <i class="bx bx-money-withdraw me-2"></i> Operating Expenses
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <tbody>
                                                <?php if (empty($expenses_by_category) && $total_referral_payments == 0): ?>
                                                <tr class="text-center">
                                                    <td colspan="2" class="py-4">
                                                        <i class="bx bx-money text-muted" style="font-size: 2rem;"></i>
                                                        <p class="text-muted mt-2">No expenses recorded</p>
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                    <?php foreach ($expenses_by_category as $expense): ?>
                                                    <tr>
                                                        <td width="70%" class="ps-4">
                                                            <strong><?= htmlspecialchars($expense['category']) ?></strong>
                                                            <div class="text-muted small"><?= $expense['expense_count'] ?> transactions</div>
                                                        </td>
                                                        <td width="30%" class="text-end fw-bold text-danger">
                                                            (₹<?= number_format($expense['total_amount'], 2) ?>)
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    
                                                    <?php if ($total_referral_payments > 0): ?>
                                                    <tr>
                                                        <td width="70%" class="ps-4" style="color: #6f42c1;">
                                                            <strong><i class="bx bx-user-voice me-1"></i> Referral Payments</strong>
                                                            <div class="text-muted small"><?= $referral_payment_count ?> payments to referrals</div>
                                                        </td>
                                                        <td width="30%" class="text-end fw-bold" style="color: #6f42c1;">
                                                            (₹<?= number_format($total_referral_payments, 2) ?>)
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                    
                                                    <tr class="border-top fw-bold">
                                                        <td class="ps-4 pt-3">Total Operating Expenses</td>
                                                        <td class="text-end pt-3 text-danger fs-5">
                                                            (₹<?= number_format($total_expenses, 2) ?>)
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Net Profit/Loss -->
                                <div class="border-top pt-4">
                                    <div class="d-flex justify-content-between align-items-center bg-<?= $net_profit_after_returns >= 0 ? 'warning' : 'dark' ?> text-white rounded p-4">
                                        <div>
                                            <h4 class="mb-1">
                                                <i class="bx bx-<?= $net_profit_after_returns >= 0 ? 'like' : 'dislike' ?> me-2"></i>
                                                Net <?= $net_profit_after_returns >= 0 ? 'Profit' : 'Loss' ?>
                                            </h4>
                                            <small>After Returns, Referral Payments & Expenses | Net Profit Margin: <?= number_format($net_profit_margin, 1) ?>%</small>
                                        </div>
                                        <h1 class="mb-0">₹<?= number_format(abs($net_profit_after_returns), 2) ?></h1>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Key Financial Metrics -->
                        <div class="card shadow-sm mt-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-stats me-2"></i> Key Financial Metrics (After Returns)
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border border-primary rounded">
                                            <h3 class="text-primary mb-2"><?= number_format($gross_profit_margin, 1) ?>%</h3>
                                            <small class="text-muted">Gross Profit Margin</small>
                                            <div class="profit-indicator mt-2">
                                                <div class="profit-fill bg-primary" style="width: <?= min($gross_profit_margin, 100) ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border border-<?= $net_profit_margin >= 0 ? 'success' : 'danger' ?> rounded">
                                            <h3 class="text-<?= $net_profit_margin >= 0 ? 'success' : 'danger' ?> mb-2"><?= number_format($net_profit_margin, 1) ?>%</h3>
                                            <small class="text-muted">Net Profit Margin</small>
                                            <div class="profit-indicator mt-2">
                                                <div class="profit-fill bg-<?= $net_profit_margin >= 0 ? 'success' : 'danger' ?>" style="width: <?= min(abs($net_profit_margin), 100) ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border border-danger rounded">
                                            <h3 class="text-danger mb-2"><?= number_format($expense_percentage, 1) ?>%</h3>
                                            <small class="text-muted">Expense Ratio</small>
                                            <div class="profit-indicator mt-2">
                                                <div class="profit-fill bg-danger" style="width: <?= min($expense_percentage, 100) ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border border-<?= $return_summary['return_percentage'] > 5 ? 'danger' : 'warning' ?> rounded">
                                            <h3 class="text-<?= $return_summary['return_percentage'] > 5 ? 'danger' : 'warning' ?> mb-2"><?= number_format($return_summary['return_percentage'], 1) ?>%</h3>
                                            <small class="text-muted">Return Rate</small>
                                            <div class="profit-indicator mt-2">
                                                <div class="profit-fill bg-<?= $return_summary['return_percentage'] > 5 ? 'danger' : 'warning' ?>" style="width: <?= min($return_summary['return_percentage'], 100) ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border border-info rounded">
                                            <h3 class="text-info mb-2"><?= $revenue_data['unique_customers'] ?? 0 ?></h3>
                                            <small class="text-muted">Unique Customers</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border border-info rounded">
                                            <h3 class="text-info mb-2"><?= $cogs_data['unique_products_sold'] ?? 0 ?></h3>
                                            <small class="text-muted">Products Sold</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border border-info rounded">
                                            <h3 class="text-info mb-2"><?= $revenue_data['total_invoices'] ?? 0 ?></h3>
                                            <small class="text-muted">Total Invoices</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border rounded" style="border-color: #6f42c1 !important;">
                                            <h3 style="color: #6f42c1; margin-bottom: 0.5rem;"><?= $referral_payment_count ?></h3>
                                            <small class="text-muted">Referral Payments</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Analysis -->
                    <div class="col-lg-4">
                        <!-- Top Products by Net Profit -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bx bx-up-arrow-circle me-2"></i> Top Products by Net Profit
                                    </h5>
                                    <span class="badge bg-success">After Returns</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($top_products)): ?>
                                <div class="text-center py-3">
                                    <i class="bx bx-package fs-1 text-muted mb-3 d-block"></i>
                                    <p class="text-muted">No products sold in this period</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-end">Net Profit</th>
                                                <th class="text-end">Margin</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($top_products as $product): 
                                                $margin_class = $product['net_profit_margin'] >= 30 ? 'success' : 
                                                               ($product['net_profit_margin'] >= 20 ? 'warning' : 'danger');
                                                $has_returns = $product['returned_quantity'] > 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="small"><?= htmlspecialchars($product['product_name']) ?></strong>
                                                        <small class="text-muted">
                                                            <?= $product['net_quantity'] ?> net sold
                                                            <?php if ($has_returns): ?>
                                                            <span class="text-danger return-badge bg-danger bg-opacity-10 ms-1">
                                                                -<?= $product['returned_quantity'] ?> returned
                                                            </span>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td class="text-end fw-bold text-success">
                                                    ₹<?= number_format($product['net_profit'], 2) ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-<?= $margin_class ?> metric-badge">
                                                        <?= number_format($product['net_profit_margin'], 1) ?>%
                                                    </span>
                                                    <?php if ($has_returns): ?>
                                                    <br>
                                                    <small class="text-danger">
                                                        <i class="bx bx-undo"></i>
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Low Net Profit Products -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bx bx-down-arrow-circle me-2"></i> Low Margin Products
                                    </h5>
                                    <span class="badge bg-warning">After Returns</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($low_profit_products)): ?>
                                <div class="text-center py-3">
                                    <i class="bx bx-package fs-1 text-muted mb-3 d-block"></i>
                                    <p class="text-muted">All products performing well</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-end">Net Profit</th>
                                                <th class="text-end">Margin</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($low_profit_products as $product): 
                                                $margin_class = $product['net_profit_margin'] >= 10 ? 'warning' : 'danger';
                                                $has_returns = $product['returned_quantity'] > 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="small"><?= htmlspecialchars($product['product_name']) ?></strong>
                                                        <small class="text-muted">
                                                            <?= $product['net_quantity'] ?> net sold
                                                            <?php if ($has_returns): ?>
                                                            <span class="text-danger return-badge bg-danger bg-opacity-10 ms-1">
                                                                -<?= $product['returned_quantity'] ?> returned
                                                            </span>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td class="text-end fw-bold text-danger">
                                                    ₹<?= number_format($product['net_profit'], 2) ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-<?= $margin_class ?> metric-badge">
                                                        <?= number_format($product['net_profit_margin'], 1) ?>%
                                                    </span>
                                                    <?php if ($has_returns): ?>
                                                    <br>
                                                    <small class="text-danger">
                                                        <i class="bx bx-undo"></i>
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Expense Breakdown Chart -->
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-pie-chart-alt me-2"></i> Expense Breakdown
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($expenses_by_category) && $total_referral_payments == 0): ?>
                                <div class="text-center py-3">
                                    <i class="bx bx-money fs-1 text-muted mb-3 d-block"></i>
                                    <p class="text-muted">No expenses recorded</p>
                                </div>
                                <?php else: ?>
                                <div style="height: 250px;">
                                    <canvas id="expenseChart"></canvas>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Footer -->
                <div class="card shadow-sm mt-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3">
                                    <i class="bx bx-info-circle me-2 text-primary"></i> Performance Summary (After Returns)
                                </h6>
                                <p class="text-muted small mb-2">
                                    Period: <?= $display_date_from ?> to <?= $display_date_to ?>
                                </p>
                                <p class="text-muted small mb-2">
                                    <?php if ($selected_shop_id === 'all'): ?>
                                        <i class="bx bx-store me-1"></i> All Shops
                                    <?php else: ?>
                                        <i class="bx bx-store me-1"></i> Shop: <?= htmlspecialchars($shop_name) ?>
                                    <?php endif; ?>
                                </p>
                                <?php if ($total_returns > 0): ?>
                                <p class="text-muted small mb-2">
                                    <i class="bx bx-undo me-1 text-danger"></i> 
                                    Returns: ₹<?= number_format($total_returns, 2) ?> 
                                    (<?= number_format($return_summary['return_percentage'], 1) ?>% of gross revenue)
                                </p>
                                <?php endif; ?>
                                <?php if ($total_referral_payments > 0): ?>
                                <p class="text-muted small mb-2">
                                    <i class="bx bx-user-voice me-1" style="color: #6f42c1;"></i> 
                                    Referral Payments: ₹<?= number_format($total_referral_payments, 2) ?>
                                    (<?= number_format($referral_percentage, 1) ?>% of net revenue)
                                </p>
                                <?php endif; ?>
                                <p class="text-muted small">
                                    The business <?= $net_profit_after_returns >= 0 ? 'generated a net profit' : 'incurred a net loss' ?> of 
                                    <span class="fw-bold <?= $net_profit_after_returns >= 0 ? 'text-success' : 'text-danger' ?>">
                                        ₹<?= number_format(abs($net_profit_after_returns), 2) ?>
                                    </span> 
                                    during this period after accounting for returns, referral payments, and expenses.
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3">
                                    <i class="bx bx-bulb me-2 text-warning"></i> Recommendations
                                </h6>
                                <ul class="text-muted small mb-0" style="list-style-type: none; padding: 0;">
                                    <?php if ($net_profit_margin < 10): ?>
                                    <li class="mb-1">
                                        <i class="bx bx-info-circle me-2 text-warning"></i> Consider reducing operating expenses
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($gross_profit_margin < 20): ?>
                                    <li class="mb-1">
                                        <i class="bx bx-info-circle me-2 text-warning"></i> Review product pricing strategy
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($return_summary['return_percentage'] > 5): ?>
                                    <li class="mb-1">
                                        <i class="bx bx-info-circle me-2 text-danger"></i> High return rate needs attention
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($total_referral_payments > 0 && $referral_percentage > 5): ?>
                                    <li class="mb-1">
                                        <i class="bx bx-info-circle me-2" style="color: #6f42c1;"></i> Referral costs are <?= number_format($referral_percentage, 1) ?>% of net revenue
                                    </li>
                                    <?php endif; ?>
                                    <?php if (!empty($low_profit_products)): ?>
                                    <li class="mb-1">
                                        <i class="bx bx-info-circle me-2 text-warning"></i> Some products have low profit margins
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($net_profit_margin >= 20): ?>
                                    <li class="mb-1">
                                        <i class="bx bx-check-circle me-2 text-success"></i> Excellent profit performance
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="text-center mt-4 pt-3 border-top">
                            <small class="text-muted">
                                <i class="bx bx-time me-1"></i> Report generated on <?= date('d/m/Y h:i A') ?>
                            </small>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
// Quick period selector
function quickPeriod(period) {
    let today = new Date();
    let dateFrom, dateTo;
    
    switch(period) {
        case 'today':
            dateFrom = dateTo = today.toISOString().split('T')[0];
            break;
        case 'yesterday':
            let yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            dateFrom = dateTo = yesterday.toISOString().split('T')[0];
            break;
        case 'this_week':
            let startOfWeek = new Date(today);
            startOfWeek.setDate(today.getDate() - today.getDay());
            dateFrom = startOfWeek.toISOString().split('T')[0];
            dateTo = today.toISOString().split('T')[0];
            break;
        case 'last_week':
            let lastWeekStart = new Date(today);
            lastWeekStart.setDate(today.getDate() - today.getDay() - 7);
            let lastWeekEnd = new Date(lastWeekStart);
            lastWeekEnd.setDate(lastWeekStart.getDate() + 6);
            dateFrom = lastWeekStart.toISOString().split('T')[0];
            dateTo = lastWeekEnd.toISOString().split('T')[0];
            break;
        case 'this_month':
            dateFrom = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
            dateTo = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
            break;
        case 'last_month':
            dateFrom = new Date(today.getFullYear(), today.getMonth() - 1, 1).toISOString().split('T')[0];
            dateTo = new Date(today.getFullYear(), today.getMonth(), 0).toISOString().split('T')[0];
            break;
        case 'this_quarter':
            let quarter = Math.floor(today.getMonth() / 3);
            dateFrom = new Date(today.getFullYear(), quarter * 3, 1).toISOString().split('T')[0];
            dateTo = new Date(today.getFullYear(), (quarter + 1) * 3, 0).toISOString().split('T')[0];
            break;
        case 'last_quarter':
            let lastQuarter = Math.floor(today.getMonth() / 3) - 1;
            if (lastQuarter < 0) {
                lastQuarter = 3;
                today.setFullYear(today.getFullYear() - 1);
            }
            dateFrom = new Date(today.getFullYear(), lastQuarter * 3, 1).toISOString().split('T')[0];
            dateTo = new Date(today.getFullYear(), (lastQuarter + 1) * 3, 0).toISOString().split('T')[0];
            break;
        case 'this_year':
            dateFrom = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
            dateTo = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0];
            break;
        case 'last_year':
            dateFrom = new Date(today.getFullYear() - 1, 0, 1).toISOString().split('T')[0];
            dateTo = new Date(today.getFullYear() - 1, 11, 31).toISOString().split('T')[0];
            break;
        default:
            return;
    }
    
    // Update form and submit
    document.querySelector('input[name="date_from"]').value = dateFrom;
    document.querySelector('input[name="date_to"]').value = dateTo;
    document.getElementById('pnlForm').submit();
}

// Export to Excel
function exportToExcel() {
    // Collect data including return and referral information
    let data = [
        ['Profit & Loss Statement (After Returns & Referrals)'],
        ['Period:', '<?= $display_date_from ?> to <?= $display_date_to ?>'],
        ['Business:', '<?= htmlspecialchars($current_business_name) ?>'],
        ['Shop:', '<?= $selected_shop_id === 'all' ? 'All Shops' : htmlspecialchars($shop_name) ?>'],
        [''],
        ['REVENUE', 'AMOUNT (₹)'],
        ['Gross Sales Revenue', <?= $total_gross_revenue ?>],
        ['Less: Returns', <?= -$total_returns ?>],
        ['Net Sales Revenue', <?= $total_net_revenue ?>],
        ['Cash', <?= $revenue_data['cash_revenue'] ?? 0 ?>],
        ['UPI', <?= $revenue_data['upi_revenue'] ?? 0 ?>],
        ['Bank Transfer', <?= $revenue_data['bank_revenue'] ?? 0 ?>],
        ['Cheque', <?= $revenue_data['cheque_revenue'] ?? 0 ?>],
        [''],
        ['COST OF GOODS SOLD', 'AMOUNT (₹)'],
        ['Gross COGS', <?= $total_gross_cogs ?>],
        ['Less: COGS from Returns', <?= -$cogs_returns ?>],
        ['Net COGS', <?= $total_net_cogs ?>],
        [''],
        ['Gross Profit (After Returns)', <?= $net_profit_before_expenses ?>],
        ['Gross Profit Margin', '<?= number_format($gross_profit_margin, 1) ?>%'],
        ['Profit Lost to Returns', <?= -$profit_loss_returns ?>],
        [''],
        ['OPERATING EXPENSES', 'AMOUNT (₹)'],
        <?php foreach ($expenses_by_category as $expense): ?>
        ['<?= str_replace("'", "''", $expense['category']) ?>', <?= $expense['total_amount'] ?>],
        <?php endforeach; ?>
        <?php if ($total_referral_payments > 0): ?>
        ['Referral Payments', <?= $total_referral_payments ?>],
        <?php endif; ?>
        ['Total Expenses', <?= $total_expenses ?>],
        [''],
        ['NET PROFIT/LOSS (After Returns & Referrals)', <?= $net_profit_after_returns ?>],
        ['Net Profit Margin', '<?= number_format($net_profit_margin, 1) ?>%'],
        ['Return Rate', '<?= number_format($return_summary['return_percentage'], 1) ?>%'],
        ['Referral Cost Ratio', '<?= number_format($referral_percentage, 1) ?>%'],
        [''],
        ['Generated on:', '<?= date('d/m/Y h:i A') ?>']
    ];
    
    // Create CSV content
    let csvContent = "data:text/csv;charset=utf-8,";
    data.forEach(row => {
        csvContent += row.join(",") + "\r\n";
    });
    
    // Create download link
    let encodedUri = encodeURI(csvContent);
    let link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "profit_loss_after_returns_<?= date('Y-m-d') ?>.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Print P&L Statement
function printPnlStatement() {
    let printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Profit & Loss Statement (After Returns & Referrals)</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .header h2 { margin: 0; color: #333; }
                .header h4 { margin: 10px 0; color: #666; }
                .statement-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                .statement-table th, .statement-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
                .statement-table .section-header { background-color: #f5f5f5; font-weight: bold; }
                .statement-table .total-row { font-weight: bold; background-color: #f9f9f9; }
                .statement-table .amount { text-align: right; }
                .statement-table .profit { color: green; }
                .statement-table .loss { color: red; }
                .statement-table .return-item { color: #dc3545; font-style: italic; }
                .statement-table .referral-item { color: #6f42c1; font-style: italic; }
                .metrics { display: flex; justify-content: space-between; margin: 30px 0; }
                .metric-box { text-align: center; padding: 15px; border: 1px solid #ddd; border-radius: 5px; flex: 1; margin: 0 10px; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
                @media print {
                    @page { margin: 0.5cm; }
                    body { font-size: 11pt; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Profit & Loss Statement (After Returns & Referrals)</h2>
                <h4><?= $display_date_from ?> to <?= $display_date_to ?></h4>
                <p><?= htmlspecialchars($current_business_name) ?></p>
                <p><?= $selected_shop_id === 'all' ? 'All Shops' : 'Shop: ' . htmlspecialchars($shop_name) ?></p>
                <?php if ($total_returns > 0): ?>
                <p style="color: #dc3545;">
                    Returns: ₹<?= number_format($total_returns, 2) ?> 
                    (<?= number_format($return_summary['return_percentage'], 1) ?>% of gross revenue)
                </p>
                <?php endif; ?>
                <?php if ($total_referral_payments > 0): ?>
                <p style="color: #6f42c1;">
                    Referral Payments: ₹<?= number_format($total_referral_payments, 2) ?>
                    (<?= number_format($referral_percentage, 1) ?>% of net revenue)
                </p>
                <?php endif; ?>
            </div>
            
            <table class="statement-table">
                <tr class="section-header">
                    <th>REVENUE</th>
                    <th class="amount">Amount (₹)</th>
                </tr>
                <tr>
                    <td>Gross Sales Revenue</td>
                    <td class="amount"><?= number_format($total_gross_revenue, 2) ?></td>
                </tr>
                <?php if ($total_returns > 0): ?>
                <tr class="return-item">
                    <td>&nbsp;&nbsp;&nbsp;&nbsp;Less: Returns</td>
                    <td class="amount">(<?= number_format($total_returns, 2) ?>)</td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td>Net Sales Revenue (After Returns)</td>
                    <td class="amount"><?= number_format($total_net_revenue, 2) ?></td>
                </tr>
                
                <tr class="section-header">
                    <th>COST OF GOODS SOLD</th>
                    <th class="amount">Amount (₹)</th>
                </tr>
                <tr>
                    <td>Gross Cost of Goods Sold</td>
                    <td class="amount">(<?= number_format($total_gross_cogs, 2) ?>)</td>
                </tr>
                <?php if ($cogs_returns > 0): ?>
                <tr class="return-item">
                    <td>&nbsp;&nbsp;&nbsp;&nbsp;Less: COGS from Returns</td>
                    <td class="amount"><?= number_format($cogs_returns, 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td>Net COGS (After Returns)</td>
                    <td class="amount">(<?= number_format($total_net_cogs, 2) ?>)</td>
                </tr>
                
                <tr class="total-row">
                    <td>Gross Profit (After Returns)</td>
                    <td class="amount profit"><?= number_format($net_profit_before_expenses, 2) ?></td>
                </tr>
                <tr>
                    <td colspan="2" style="text-align: right; font-size: 12px;">
                        Gross Profit Margin: <?= number_format($gross_profit_margin, 1) ?>%
                        <?php if ($profit_loss_returns > 0): ?>
                        <br><span style="color: #dc3545;">Profit lost to returns: ₹<?= number_format($profit_loss_returns, 2) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr class="section-header">
                    <th>OPERATING EXPENSES</th>
                    <th class="amount">Amount (₹)</th>
                </tr>
                <?php foreach ($expenses_by_category as $expense): ?>
                <tr>
                    <td><?= htmlspecialchars($expense['category']) ?></td>
                    <td class="amount">(<?= number_format($expense['total_amount'], 2) ?>)</td>
                </tr>
                <?php endforeach; ?>
                <?php if ($total_referral_payments > 0): ?>
                <tr class="referral-item">
                    <td>Referral Payments</td>
                    <td class="amount">(<?= number_format($total_referral_payments, 2) ?>)</td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td>Total Expenses</td>
                    <td class="amount">(<?= number_format($total_expenses, 2) ?>)</td>
                </tr>
                
                <tr class="total-row" style="border-top: 2px solid #333;">
                    <td>NET PROFIT / LOSS (After Returns, Referrals & Expenses)</td>
                    <td class="amount <?= $net_profit_after_returns >= 0 ? 'profit' : 'loss' ?>"><?= number_format($net_profit_after_returns, 2) ?></td>
                </tr>
                <tr>
                    <td colspan="2" style="text-align: right; font-size: 12px;">
                        Net Profit Margin: <?= number_format($net_profit_margin, 1) ?>%
                    </td>
                </tr>
            </table>
            
            <div class="metrics">
                <div class="metric-box">
                    <h4><?= number_format($gross_profit_margin, 1) ?>%</h4>
                    <small>Gross Margin</small>
                </div>
                <div class="metric-box">
                    <h4 class="<?= $net_profit_margin >= 0 ? 'profit' : 'loss' ?>"><?= number_format($net_profit_margin, 1) ?>%</h4>
                    <small>Net Margin</small>
                </div>
                <div class="metric-box">
                    <h4><?= number_format($expense_percentage, 1) ?>%</h4>
                    <small>Expense Ratio</small>
                </div>
                <div class="metric-box">
                    <h4 class="<?= $return_summary['return_percentage'] > 5 ? 'loss' : '' ?>"><?= number_format($return_summary['return_percentage'], 1) ?>%</h4>
                    <small>Return Rate</small>
                </div>
            </div>
            
            <div class="footer">
                <p>Report generated on <?= date('d/m/Y h:i A') ?></p>
                <p><?= htmlspecialchars($current_business_name) ?> - Business Intelligence Report</p>
            </div>
            
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 500);
                }
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

// Initialize Expense Chart
<?php if (!empty($expenses_by_category) || $total_referral_payments > 0): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('expenseChart').getContext('2d');
    
    // Prepare data including referral payments
    let categories = <?= json_encode(array_column($expenses_by_category, 'category')) ?>;
    let amounts = <?= json_encode(array_column($expenses_by_category, 'total_amount')) ?>;
    
    <?php if ($total_referral_payments > 0): ?>
    categories.push('Referral Payments');
    amounts.push(<?= $total_referral_payments ?>);
    <?php endif; ?>
    
    // Generate colors
    const colors = [
        '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0', 
        '#9966ff', '#ff9f40', '#8ac926', '#1982c4',
        '#6a4c93', '#ff595e', '#6f42c1'
    ];
    
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: categories,
            datasets: [{
                data: amounts,
                backgroundColor: colors.slice(0, categories.length),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.raw || 0;
                            let total = <?= $total_expenses ?>;
                            let percentage = total > 0 ? (value / total * 100).toFixed(1) : 0;
                            return `${label}: ₹${value.toFixed(2)} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
});
<?php endif; ?>

// Auto-refresh for current month
<?php if ($is_current_month): ?>
setTimeout(function() {
    location.reload();
}, 300000); // Refresh every 5 minutes
<?php endif; ?>
</script>
</body>
</html>