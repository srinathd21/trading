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

$can_view_reports = in_array($user_role, ['admin', 'shop_manager']);
if (!$can_view_reports) {
    $_SESSION['error'] = "Access denied. You don't have permission to view seller reports.";
    header('Location: dashboard.php');
    exit();
}

// ==================== FILTERS ====================
$selected_seller_id = $_GET['seller_id'] ?? 'all';
$selected_shop_id = $_GET['shop_id'] ?? 'all';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// Branch-based access control
if ($user_role === 'shop_manager') {
    $selected_shop_id = $current_shop_id;
} elseif ($user_role !== 'admin' && $current_shop_id) {
    $selected_shop_id = $current_shop_id;
}

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Get all sellers
$sellers = [];
$seller_stmt = $pdo->prepare("
    SELECT 
        u.id, 
        u.full_name, 
        u.username, 
        u.role,
        u.shop_id as assigned_shop_id,
        s.shop_name as assigned_shop_name
    FROM users u
    LEFT JOIN shops s ON u.shop_id = s.id AND s.business_id = u.business_id
    WHERE u.business_id = ?
    AND u.role IN ('admin', 'seller', 'cashier', 'shop_manager', 'stock_manager')
    AND u.is_active = 1
    ORDER BY u.full_name
");
$seller_stmt->execute([$business_id]);
$sellers = $seller_stmt->fetchAll();

// Get all shops for admin
$all_shops = [];
if (in_array($user_role, ['admin', 'shop_manager'])) {
    if ($user_role === 'admin') {
        $shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name");
        $shop_stmt->execute([$business_id]);
        $all_shops = $shop_stmt->fetchAll();
    } elseif ($user_role === 'shop_manager') {
        $shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE id = ? AND business_id = ? AND is_active = 1");
        $shop_stmt->execute([$current_shop_id, $business_id]);
        $all_shops = $shop_stmt->fetchAll();
    }
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
$base_params = [$date_from, $date_to, $business_id];
$shop_filter = $selected_shop_id !== 'all' ? " AND i.shop_id = ?" : "";
$seller_filter = $selected_seller_id !== 'all' ? " AND i.seller_id = ?" : "";
$all_params = $base_params;
if ($selected_shop_id !== 'all') $all_params[] = $selected_shop_id;
if ($selected_seller_id !== 'all') $all_params[] = $selected_seller_id;

// ==================== GET SELLER INVOICE DATA (NO ITEMS JOIN) ====================
$seller_invoice_sql = "
    SELECT
        i.seller_id,
        i.id as invoice_id,
        i.total,
        i.cash_amount,
        i.upi_amount,
        i.bank_amount,
        i.cheque_amount,
        i.pending_amount,
        i.created_at,
        i.customer_id,
        i.shop_id
    FROM invoices i
    WHERE DATE(i.created_at) BETWEEN ? AND ?
    AND i.business_id = ?
    $shop_filter
    $seller_filter
";

$invoice_stmt = $pdo->prepare($seller_invoice_sql);
$invoice_stmt->execute($all_params);
$invoices = $invoice_stmt->fetchAll();

// Group invoices by seller
$seller_invoices = [];
$invoice_ids = [];
foreach ($invoices as $inv) {
    $seller_invoices[$inv['seller_id']][] = $inv;
    $invoice_ids[] = $inv['invoice_id'];
}

// ==================== GET RETURNS PER INVOICE ====================
$returns_data = [];
if (!empty($invoice_ids)) {
    $placeholders = implode(',', array_fill(0, count($invoice_ids), '?'));
    $returns_sql = "
        SELECT 
            invoice_id,
            COALESCE(SUM(return_qty * unit_price), 0) as total_returns,
            COALESCE(SUM(profit), 0) as profit,
            COALESCE(SUM(return_qty * profit / NULLIF(quantity, 0)), 0) as profit_loss,
            COUNT(DISTINCT CASE WHEN sale_type = 'retail' THEN id END) as retail_items,
            COUNT(DISTINCT CASE WHEN sale_type = 'wholesale' THEN id END) as wholesale_items
        FROM invoice_items
        WHERE invoice_id IN ($placeholders)
        GROUP BY invoice_id
    ";
    
    $returns_stmt = $pdo->prepare($returns_sql);
    $returns_stmt->execute($invoice_ids);
    while ($row = $returns_stmt->fetch()) {
        $returns_data[$row['invoice_id']] = $row;
    }
}

// ==================== GET CUSTOMER DATA ====================
$customer_ids = array_column($invoices, 'customer_id');
$customer_types = [];
if (!empty($customer_ids)) {
    $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
    $customer_sql = "SELECT id, customer_type FROM customers WHERE id IN ($placeholders)";
    $customer_stmt = $pdo->prepare($customer_sql);
    $customer_stmt->execute($customer_ids);
    while ($row = $customer_stmt->fetch()) {
        $customer_types[$row['id']] = $row['customer_type'];
    }
}

// ==================== PROCESS SELLER DATA ====================
$seller_data = [];
$all_seller_stats = [];

foreach ($sellers as $seller) {
    $seller_id = $seller['id'];
    $seller_inv_list = $seller_invoices[$seller_id] ?? [];
    
    $stats = [
        'seller_id' => $seller_id,
        'seller_name' => $seller['full_name'],
        'role' => $seller['role'],
        'assigned_shop_name' => $seller['assigned_shop_name'] ?? 'Not Assigned',
        'total_invoices' => count($seller_inv_list),
        'total_customers' => 0,
        'gross_sales' => 0,
        'total_returns' => 0,
        'gross_profit' => 0,
        'profit_loss' => 0,
        'cash_sales' => 0,
        'upi_sales' => 0,
        'bank_sales' => 0,
        'cheque_sales' => 0,
        'pending_amount' => 0,
        'retail_sales' => 0,
        'wholesale_sales' => 0,
        'active_days' => [],
        'first_sale' => null,
        'last_sale' => null,
        'shop_sales' => []
    ];
    
    $customers = [];
    
    foreach ($seller_inv_list as $inv) {
        $returns = $returns_data[$inv['invoice_id']] ?? ['total_returns' => 0, 'profit' => 0, 'profit_loss' => 0, 'retail_items' => 0, 'wholesale_items' => 0];
        $customer_type = $customer_types[$inv['customer_id']] ?? 'retail';
        
        $stats['gross_sales'] += $inv['total'];
        $stats['total_returns'] += $returns['total_returns'];
        $stats['gross_profit'] += $returns['profit'];
        $stats['profit_loss'] += $returns['profit_loss'];
        $stats['cash_sales'] += $inv['cash_amount'] ?? 0;
        $stats['upi_sales'] += $inv['upi_amount'] ?? 0;
        $stats['bank_sales'] += $inv['bank_amount'] ?? 0;
        $stats['cheque_sales'] += $inv['cheque_amount'] ?? 0;
        $stats['pending_amount'] += $inv['pending_amount'] ?? 0;
        
        if ($customer_type == 'retail') {
            $stats['retail_sales'] += $inv['total'];
        } else {
            $stats['wholesale_sales'] += $inv['total'];
        }
        
        $customers[$inv['customer_id']] = true;
        $date = date('Y-m-d', strtotime($inv['created_at']));
        $stats['active_days'][$date] = true;
        
        if (!$stats['first_sale'] || strtotime($inv['created_at']) < strtotime($stats['first_sale'])) {
            $stats['first_sale'] = $inv['created_at'];
        }
        if (!$stats['last_sale'] || strtotime($inv['created_at']) > strtotime($stats['last_sale'])) {
            $stats['last_sale'] = $inv['created_at'];
        }
        
        // Track per-shop sales
        $shop_id = $inv['shop_id'];
        if (!isset($stats['shop_sales'][$shop_id])) {
            $stats['shop_sales'][$shop_id] = ['gross' => 0, 'returns' => 0];
        }
        $stats['shop_sales'][$shop_id]['gross'] += $inv['total'];
        $stats['shop_sales'][$shop_id]['returns'] += $returns['total_returns'];
    }
    
    $stats['total_customers'] = count($customers);
    $stats['active_days'] = count($stats['active_days']);
    $stats['net_sales'] = $stats['gross_sales'] - $stats['total_returns'];
    $stats['net_profit'] = $stats['gross_profit'] - $stats['profit_loss'];
    $stats['avg_invoice'] = $stats['total_invoices'] > 0 ? $stats['net_sales'] / $stats['total_invoices'] : 0;
    
    if ($stats['total_invoices'] > 0) {
        $seller_data[] = $stats;
    }
    $all_seller_stats[$seller_id] = $stats;
}

// ==================== CALCULATE TOTALS ====================
$total_sellers = count($seller_data);
$total_invoices = array_sum(array_column($seller_data, 'total_invoices'));
$total_gross_sales = array_sum(array_column($seller_data, 'gross_sales'));
$total_returns = array_sum(array_column($seller_data, 'total_returns'));
$total_net_sales = array_sum(array_column($seller_data, 'net_sales'));
$total_gross_profit = array_sum(array_column($seller_data, 'gross_profit'));
$total_net_profit = array_sum(array_column($seller_data, 'net_profit'));
$total_customers = array_sum(array_column($seller_data, 'total_customers'));

$avg_per_seller = $total_sellers > 0 ? $total_net_sales / $total_sellers : 0;
$profit_margin = $total_net_sales > 0 ? ($total_net_profit / $total_net_sales) * 100 : 0;
$returns_percentage = $total_gross_sales > 0 ? ($total_returns / $total_gross_sales) * 100 : 0;

// ==================== TOP SELLERS ====================
usort($seller_data, function($a, $b) {
    return $b['net_sales'] <=> $a['net_sales'];
});
$top_sellers = array_slice($seller_data, 0, 5);

// ==================== CSV EXPORT ====================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="seller_performance_report_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');

    fputcsv($output, ['Seller Performance Report', 'Period: ' . $date_from . ' to ' . $date_to]);
    fputcsv($output, ['Business: ' . ($_SESSION['current_business_name'] ?? 'Business')]);
    if ($selected_shop_id !== 'all') {
        fputcsv($output, ['Branch: ' . $shop_name]);
    }
    fputcsv($output, ['']);

    fputcsv($output, ['Seller Name', 'Role', 'Assigned Branch', 'Invoices', 'Customers', 
                     'Gross Sales', 'Returns', 'Net Sales', 'Retail Sales', 'Wholesale Sales',
                     'Gross Profit', 'Net Profit', 'Avg Invoice', 'Cash', 'UPI', 'Bank', 'Cheque']);

    foreach ($seller_data as $seller) {
        fputcsv($output, [
            $seller['seller_name'],
            ucfirst($seller['role']),
            $seller['assigned_shop_name'],
            $seller['total_invoices'],
            $seller['total_customers'],
            '₹' . number_format($seller['gross_sales'], 2),
            '₹' . number_format($seller['total_returns'], 2),
            '₹' . number_format($seller['net_sales'], 2),
            '₹' . number_format($seller['retail_sales'], 2),
            '₹' . number_format($seller['wholesale_sales'], 2),
            '₹' . number_format($seller['gross_profit'], 2),
            '₹' . number_format($seller['net_profit'], 2),
            '₹' . number_format($seller['avg_invoice'], 2),
            '₹' . number_format($seller['cash_sales'], 2),
            '₹' . number_format($seller['upi_sales'], 2),
            '₹' . number_format($seller['bank_sales'], 2),
            '₹' . number_format($seller['cheque_sales'], 2)
        ]);
    }

    fclose($output);
    exit();
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Seller Performance Report"; ?>
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
                                    <i class="bx bx-user-circle me-2"></i>
                                    Seller Performance Report
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    Report from <?= date('d M Y', strtotime($date_from)) ?> to <?= date('d M Y', strtotime($date_to)) ?>
                                    • Branch: <strong><?= htmlspecialchars($shop_name) ?></strong>
                                </p>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <small class="text-success">
                                        <i class="bx bx-check-circle me-1"></i>
                                        Net values shown after returns
                                    </small>
                                    <?php if ($returns_percentage > 0): ?>
                                    <small class="text-warning">
                                        <i class="bx bx-undo me-1"></i>
                                        Return Rate: <?= number_format($returns_percentage, 1) ?>%
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="?export=csv&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&seller_id=<?= $selected_seller_id ?>&shop_id=<?= $selected_shop_id ?>"
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

                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">Select Seller</label>
                                <select class="form-select" name="seller_id">
                                    <option value="all" <?= $selected_seller_id === 'all' ? 'selected' : '' ?>>All Sellers</option>
                                    <?php foreach ($sellers as $seller): ?>
                                        <option value="<?= $seller['id'] ?>"
                                            <?= $selected_seller_id == $seller['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($seller['full_name']) ?> 
                                            (<?= ucfirst($seller['role']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if (in_array($user_role, ['admin', 'shop_manager'])): ?>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">Select Branch</label>
                                <select class="form-select" name="shop_id">
                                    <?php if ($user_role === 'admin'): ?>
                                        <option value="all" <?= $selected_shop_id === 'all' ? 'selected' : '' ?>>All Branches</option>
                                        <?php foreach ($all_shops as $shop): ?>
                                            <option value="<?= $shop['id'] ?>"
                                                <?= $selected_shop_id == $shop['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($shop['shop_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php elseif ($user_role === 'shop_manager'): ?>
                                        <?php foreach ($all_shops as $shop): ?>
                                            <option value="<?= $shop['id'] ?>" selected>
                                                <?= htmlspecialchars($shop['shop_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="col-lg-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bx bx-filter me-1"></i> Apply Filters
                                </button>
                                <button type="button" class="btn btn-outline-secondary ms-2" onclick="resetFilters()">
                                    <i class="bx bx-reset me-1"></i> Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Overall Stats -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Net Sales</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($total_net_sales, 2) ?></h3>
                                        <small class="text-muted">
                                            <?= $total_invoices ?> invoices
                                            <?php if ($total_returns > 0): ?>
                                            <br>
                                            <span class="text-danger">
                                                <i class="bx bx-undo"></i>
                                                Returns: ₹<?= number_format($total_returns, 2) ?>
                                            </span>
                                            <?php endif; ?>
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
                        <div class="card card-hover border-start border-success border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Net Profit</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($total_net_profit, 2) ?></h3>
                                        <small class="text-muted"><?= number_format($profit_margin, 1) ?>% margin</small>
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
                        <div class="card card-hover border-start border-info border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Active Sellers</h6>
                                        <h3 class="mb-0 text-info"><?= $total_sellers ?></h3>
                                        <small class="text-muted">Avg: ₹<?= number_format($avg_per_seller, 2) ?>/seller</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-group text-info"></i>
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
                                        <h6 class="text-muted mb-1">Unique Customers</h6>
                                        <h3 class="mb-0 text-warning"><?= $total_customers ?></h3>
                                        <small class="text-muted">Served by sellers</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-user text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Performers -->
                <?php if (!empty($top_sellers) && $selected_seller_id === 'all'): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-trophy me-2"></i>
                                    Top 5 Sellers by Net Sales
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php
                                    $rank = 1;
                                    foreach($top_sellers as $seller):
                                        $percentage = $total_net_sales > 0 ? ($seller['net_sales'] / $total_net_sales) * 100 : 0;
                                        $badge_class = ['danger', 'warning', 'info', 'primary', 'success'][$rank-1];
                                    ?>
                                    <div class="col-md-12 mb-3">
                                        <div class="card border">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center justify-content-between">
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm me-3">
                                                            <span class="avatar-title bg-<?= $badge_class ?> bg-opacity-10 text-<?= $badge_class ?> rounded-circle fs-4 fw-bold">
                                                                <?= $rank ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-1"><?= htmlspecialchars($seller['seller_name']) ?></h6>
                                                            <div class="d-flex gap-3">
                                                                <small class="text-muted">
                                                                    <i class="bx bx-receipt"></i> <?= $seller['total_invoices'] ?> invoices
                                                                </small>
                                                                <small class="text-muted">
                                                                    <i class="bx bx-user"></i> <?= $seller['total_customers'] ?> customers
                                                                </small>
                                                                <small class="text-warning">
                                                                    <i class="bx bx-briefcase"></i> <?= ucfirst($seller['role']) ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="text-end">
                                                        <h5 class="mb-1 text-primary">₹<?= number_format($seller['net_sales'], 2) ?></h5>
                                                        <div class="progress mt-2" style="height: 6px; width: 150px;">
                                                            <div class="progress-bar bg-<?= $badge_class ?>" style="width: <?= $percentage ?>%"></div>
                                                        </div>
                                                        <small class="text-muted"><?= number_format($percentage, 1) ?>% of total</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                        $rank++;
                                    endforeach;
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Seller Performance Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-list-ul me-2"></i>
                                    Seller Performance Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($seller_data)): ?>
                                    <div class="text-center py-5">
                                        <i class="bx bx-user display-4 text-muted"></i>
                                        <h5 class="mt-3">No sales data found</h5>
                                        <p class="text-muted">No sellers have sales in the selected period</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Seller & Role</th>
                                                    <th>Assigned Branch</th>
                                                    <th class="text-center">Invoices</th>
                                                    <th class="text-center">Customers</th>
                                                    <th class="text-end">Gross Sales</th>
                                                    <th class="text-end">Returns</th>
                                                    <th class="text-end">Net Sales</th>
                                                    <th class="text-end">Net Profit</th>
                                                    <th class="text-end">Avg Invoice</th>
                                                    <th>Performance</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $counter = 1;
                                                foreach($seller_data as $seller):
                                                    $has_returns = $seller['total_returns'] > 0;
                                                    $margin = $seller['net_sales'] > 0 ? ($seller['net_profit'] / $seller['net_sales']) * 100 : 0;
                                                    $seller_percentage = $total_net_sales > 0 ? ($seller['net_sales'] / $total_net_sales) * 100 : 0;
                                                    
                                                    $performance = match(true) {
                                                        $margin >= 30 => ['label' => 'Excellent', 'color' => 'success'],
                                                        $margin >= 20 => ['label' => 'Good', 'color' => 'primary'],
                                                        $margin >= 10 => ['label' => 'Average', 'color' => 'warning'],
                                                        default => ['label' => 'Needs Work', 'color' => 'danger']
                                                    };
                                                ?>
                                                <tr>
                                                    <td><?= $counter++ ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar-sm me-3">
                                                                <span class="avatar-title bg-primary bg-opacity-10 text-primary rounded-circle">
                                                                    <?= strtoupper(substr($seller['seller_name'], 0, 1)) ?>
                                                                </span>
                                                            </div>
                                                            <div>
                                                                <strong><?= htmlspecialchars($seller['seller_name']) ?></strong><br>
                                                                <small class="text-warning"><?= ucfirst($seller['role']) ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <small class="text-info">
                                                            <i class="bx bx-store-alt"></i>
                                                            <?= htmlspecialchars($seller['assigned_shop_name']) ?>
                                                        </small>
                                                    </td>
                                                    <td class="text-center"><?= $seller['total_invoices'] ?></td>
                                                    <td class="text-center"><?= $seller['total_customers'] ?></td>
                                                    <td class="text-end">₹<?= number_format($seller['gross_sales'], 2) ?></td>
                                                    <td class="text-end">
                                                        <?php if ($has_returns): ?>
                                                            <span class="text-danger">-₹<?= number_format($seller['total_returns'], 2) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">₹0.00</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end fw-bold <?= $has_returns ? 'text-success' : 'text-primary' ?>">
                                                        ₹<?= number_format($seller['net_sales'], 2) ?>
                                                        <div class="progress mt-1" style="height: 3px;">
                                                            <div class="progress-bar bg-primary" style="width: <?= $seller_percentage ?>%"></div>
                                                        </div>
                                                        <small class="text-muted"><?= number_format($seller_percentage, 1) ?>%</small>
                                                    </td>
                                                    <td class="text-end">
                                                        <span class="fw-bold text-success">₹<?= number_format($seller['net_profit'], 2) ?></span>
                                                        <br>
                                                        <small class="text-muted"><?= number_format($margin, 1) ?>% margin</small>
                                                    </td>
                                                    <td class="text-end">₹<?= number_format($seller['avg_invoice'], 2) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $performance['color'] ?> bg-opacity-10 text-<?= $performance['color'] ?> px-3 py-2">
                                                            <?= $performance['label'] ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>

                                                <tr class="table-light fw-bold">
                                                    <td colspan="5" class="text-end">TOTALS:</td>
                                                    <td class="text-end">₹<?= number_format($total_gross_sales, 2) ?></td>
                                                    <td class="text-end text-danger">-₹<?= number_format($total_returns, 2) ?></td>
                                                    <td class="text-end text-primary">₹<?= number_format($total_net_sales, 2) ?></td>
                                                    <td class="text-end text-success">₹<?= number_format($total_net_profit, 2) ?></td>
                                                    <td colspan="2"></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Breakdown for Selected Seller -->
                <?php if ($selected_seller_id !== 'all' && isset($all_seller_stats[$selected_seller_id]) && $all_seller_stats[$selected_seller_id]['total_invoices'] > 0):
                    $seller = $all_seller_stats[$selected_seller_id];
                ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-credit-card me-2"></i>
                                    Payment Breakdown: <?= htmlspecialchars($seller['seller_name']) ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border rounded text-center bg-light">
                                            <h6 class="text-success">Cash</h6>
                                            <h4>₹<?= number_format($seller['cash_sales'], 2) ?></h4>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border rounded text-center bg-light">
                                            <h6 class="text-primary">UPI</h6>
                                            <h4>₹<?= number_format($seller['upi_sales'], 2) ?></h4>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border rounded text-center bg-light">
                                            <h6 class="text-info">Bank</h6>
                                            <h4>₹<?= number_format($seller['bank_sales'], 2) ?></h4>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="p-3 border rounded text-center bg-light">
                                            <h6 class="text-warning">Cheque</h6>
                                            <h4>₹<?= number_format($seller['cheque_sales'], 2) ?></h4>
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
function resetFilters() {
    const today = new Date().toISOString().split('T')[0];
    const firstDay = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0];

    document.querySelector('input[name="date_from"]').value = firstDay;
    document.querySelector('input[name="date_to"]').value = today;
    document.querySelector('select[name="seller_id"]').value = 'all';
    <?php if (in_array($user_role, ['admin', 'shop_manager'])): ?>
    document.querySelector('select[name="shop_id"]').value = '<?= $user_role === 'admin' ? 'all' : $selected_shop_id ?>';
    <?php endif; ?>

    document.getElementById('reportForm').submit();
}

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
.progress { border-radius: 10px; }
</style>
</body>
</html>