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

$can_view_reports = in_array($user_role, ['admin', 'shop_manager', 'cashier']);
if (!$can_view_reports) {
    $_SESSION['error'] = "Access denied. You don't have permission to view reports.";
    header('Location: dashboard.php');
    exit();
}

// ==================== FILTERS ====================
$selected_shop_id = $_GET['shop_id'] ?? 'all';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

if ($user_role !== 'admin' && $current_shop_id) {
    $selected_shop_id = $current_shop_id;
}

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

// Build parameters
$params = [$date_from, $date_to, $business_id];
$shop_filter = $selected_shop_id !== 'all' ? " AND i.shop_id = ?" : "";
$shop_param = $selected_shop_id !== 'all' ? [$selected_shop_id] : [];

// ==================== CSV EXPORT ====================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_summary_report_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');

    fputcsv($output, ['Sales Summary Report', 'Period: ' . $display_date_from . ' to ' . $display_date_to]);
    fputcsv($output, ['Business: ' . ($_SESSION['current_business_name'] ?? 'Business')]);
    fputcsv($output, ['Branch: ' . $shop_name]);
    fputcsv($output, ['']);
    
    // Shop-wise summary
    $csv_sql = "
        SELECT
            s.shop_name,
            COUNT(DISTINCT i.id) as total_invoices,
            COUNT(DISTINCT i.customer_id) as total_customers,
            SUM(i.total) as gross_sales,
            SUM(i.discount + COALESCE(i.overall_discount, 0)) as total_discount,
            SUM(i.pending_amount) as total_pending,
            SUM(i.cash_amount) as total_cash,
            SUM(i.upi_amount) as total_upi,
            SUM(i.bank_amount) as total_bank,
            SUM(i.cheque_amount) as total_cheque
        FROM invoices i
        LEFT JOIN shops s ON i.shop_id = s.id
        WHERE DATE(i.created_at) BETWEEN ? AND ?
          AND i.business_id = ?
    ";

    $csv_params = [$date_from, $date_to, $business_id];
    if ($selected_shop_id !== 'all') {
        $csv_sql .= " AND i.shop_id = ?";
        $csv_params[] = $selected_shop_id;
    }
    $csv_sql .= " GROUP BY i.shop_id ORDER BY s.shop_name";

    $csv_stmt = $pdo->prepare($csv_sql);
    $csv_stmt->execute($csv_params);
    $shops = $csv_stmt->fetchAll();

    // Get returns data separately
    $returns_sql = "
        SELECT 
            i.shop_id,
            COALESCE(SUM(ii.return_qty * ii.unit_price), 0) as total_returns,
            COALESCE(SUM(ii.profit), 0) as gross_profit,
            COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0) as profit_loss_from_returns,
            COALESCE(SUM(CASE WHEN ii.sale_type = 'retail' THEN ii.quantity * ii.unit_price ELSE 0 END), 0) as retail_gross,
            COALESCE(SUM(CASE WHEN ii.sale_type = 'wholesale' THEN ii.quantity * ii.unit_price ELSE 0 END), 0) as wholesale_gross,
            COALESCE(SUM(CASE WHEN ii.sale_type = 'retail' AND ii.return_qty > 0 THEN ii.return_qty * ii.unit_price ELSE 0 END), 0) as retail_returns,
            COALESCE(SUM(CASE WHEN ii.sale_type = 'wholesale' AND ii.return_qty > 0 THEN ii.return_qty * ii.unit_price ELSE 0 END), 0) as wholesale_returns
        FROM invoice_items ii
        JOIN invoices i ON ii.invoice_id = i.id
        WHERE DATE(i.created_at) BETWEEN ? AND ?
          AND i.business_id = ?
    ";

    $returns_params = [$date_from, $date_to, $business_id];
    if ($selected_shop_id !== 'all') {
        $returns_sql .= " AND i.shop_id = ?";
        $returns_params[] = $selected_shop_id;
    }
    $returns_sql .= " GROUP BY i.shop_id";

    $returns_stmt = $pdo->prepare($returns_sql);
    $returns_stmt->execute($returns_params);
    $returns_data = [];
    while ($row = $returns_stmt->fetch()) {
        $returns_data[$row['shop_id']] = $row;
    }

    fputcsv($output, ['Shop', 'Invoices', 'Customers', 'Gross Sales', 'Returns', 'Net Sales', 'Retail Net', 'Wholesale Net', 'Discount', 'Cash', 'UPI', 'Bank', 'Cheque', 'Pending', 'Gross Profit', 'Net Profit']);

    $grand = [
        'invoices' => 0, 'customers' => 0, 'gross' => 0, 'returns' => 0, 'net' => 0,
        'retail_net' => 0, 'wholesale_net' => 0, 'discount' => 0, 'cash' => 0, 'upi' => 0,
        'bank' => 0, 'cheque' => 0, 'pending' => 0, 'gross_profit' => 0, 'net_profit' => 0
    ];

    foreach ($shops as $shop) {
        $shop_id = $shop['shop_id'] ?? 0;
        $ret = $returns_data[$shop_id] ?? ['total_returns' => 0, 'gross_profit' => 0, 'profit_loss_from_returns' => 0, 'retail_gross' => 0, 'wholesale_gross' => 0, 'retail_returns' => 0, 'wholesale_returns' => 0];
        
        $net_sales = $shop['gross_sales'] - $ret['total_returns'];
        $retail_net = $ret['retail_gross'] - $ret['retail_returns'];
        $wholesale_net = $ret['wholesale_gross'] - $ret['wholesale_returns'];
        $net_profit = $ret['gross_profit'] - $ret['profit_loss_from_returns'];

        fputcsv($output, [
            $shop['shop_name'] ?? 'Unknown',
            $shop['total_invoices'],
            $shop['total_customers'],
            '₹' . number_format($shop['gross_sales'], 2),
            '₹' . number_format($ret['total_returns'], 2),
            '₹' . number_format($net_sales, 2),
            '₹' . number_format($retail_net, 2),
            '₹' . number_format($wholesale_net, 2),
            '₹' . number_format($shop['total_discount'], 2),
            '₹' . number_format($shop['total_cash'], 2),
            '₹' . number_format($shop['total_upi'], 2),
            '₹' . number_format($shop['total_bank'], 2),
            '₹' . number_format($shop['total_cheque'], 2),
            '₹' . number_format($shop['total_pending'], 2),
            '₹' . number_format($ret['gross_profit'], 2),
            '₹' . number_format($net_profit, 2)
        ]);

        $grand['invoices'] += $shop['total_invoices'];
        $grand['customers'] += $shop['total_customers'];
        $grand['gross'] += $shop['gross_sales'];
        $grand['returns'] += $ret['total_returns'];
        $grand['net'] += $net_sales;
        $grand['retail_net'] += $retail_net;
        $grand['wholesale_net'] += $wholesale_net;
        $grand['discount'] += $shop['total_discount'];
        $grand['cash'] += $shop['total_cash'];
        $grand['upi'] += $shop['total_upi'];
        $grand['bank'] += $shop['total_bank'];
        $grand['cheque'] += $shop['total_cheque'];
        $grand['pending'] += $shop['total_pending'];
        $grand['gross_profit'] += $ret['gross_profit'];
        $grand['net_profit'] += $net_profit;
    }

    fputcsv($output, ['']);
    fputcsv($output, ['GRAND TOTAL', $grand['invoices'], $grand['customers'], 
        '₹' . number_format($grand['gross'], 2), '₹' . number_format($grand['returns'], 2),
        '₹' . number_format($grand['net'], 2), '₹' . number_format($grand['retail_net'], 2),
        '₹' . number_format($grand['wholesale_net'], 2), '₹' . number_format($grand['discount'], 2),
        '₹' . number_format($grand['cash'], 2), '₹' . number_format($grand['upi'], 2),
        '₹' . number_format($grand['bank'], 2), '₹' . number_format($grand['cheque'], 2),
        '₹' . number_format($grand['pending'], 2), '₹' . number_format($grand['gross_profit'], 2),
        '₹' . number_format($grand['net_profit'], 2)
    ]);

    fclose($output);
    exit();
}

// ==================== MAIN SHOP-WISE SUMMARY ====================
// Get invoice totals per shop
$shop_sql = "
    SELECT
        i.shop_id,
        s.shop_name,
        COUNT(DISTINCT i.id) as total_invoices,
        COUNT(DISTINCT i.customer_id) as total_customers,
        SUM(i.total) as gross_sales,
        SUM(i.discount + COALESCE(i.overall_discount, 0)) as total_discount,
        SUM(i.pending_amount) as total_pending,
        SUM(i.cash_amount) as total_cash,
        SUM(i.upi_amount) as total_upi,
        SUM(i.bank_amount) as total_bank,
        SUM(i.cheque_amount) as total_cheque
    FROM invoices i
    LEFT JOIN shops s ON i.shop_id = s.id
    WHERE DATE(i.created_at) BETWEEN ? AND ?
      AND i.business_id = ?
";

$shop_params = [$date_from, $date_to, $business_id];
if ($selected_shop_id !== 'all') {
    $shop_sql .= " AND i.shop_id = ?";
    $shop_params[] = $selected_shop_id;
}
$shop_sql .= " GROUP BY i.shop_id ORDER BY s.shop_name";

$shop_stmt = $pdo->prepare($shop_sql);
$shop_stmt->execute($shop_params);
$shops_data = $shop_stmt->fetchAll();

// Get returns and profit data per shop
$returns_sql = "
    SELECT 
        i.shop_id,
        COALESCE(SUM(ii.return_qty * ii.unit_price), 0) as total_returns,
        COALESCE(SUM(ii.profit), 0) as gross_profit,
        COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0) as profit_loss_from_returns,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'retail' THEN ii.quantity * ii.unit_price ELSE 0 END), 0) as retail_gross,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'wholesale' THEN ii.quantity * ii.unit_price ELSE 0 END), 0) as wholesale_gross,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'retail' AND ii.return_qty > 0 THEN ii.return_qty * ii.unit_price ELSE 0 END), 0) as retail_returns,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'wholesale' AND ii.return_qty > 0 THEN ii.return_qty * ii.unit_price ELSE 0 END), 0) as wholesale_returns
    FROM invoice_items ii
    JOIN invoices i ON ii.invoice_id = i.id
    WHERE DATE(i.created_at) BETWEEN ? AND ?
      AND i.business_id = ?
";

$returns_params = [$date_from, $date_to, $business_id];
if ($selected_shop_id !== 'all') {
    $returns_sql .= " AND i.shop_id = ?";
    $returns_params[] = $selected_shop_id;
}
$returns_sql .= " GROUP BY i.shop_id";

$returns_stmt = $pdo->prepare($returns_sql);
$returns_stmt->execute($returns_params);
$returns_map = [];
while ($row = $returns_stmt->fetch()) {
    $returns_map[$row['shop_id']] = $row;
}

// Build shop summary with calculated values
$shop_summary = [];
$grand_totals = [
    'invoices' => 0, 'customers' => 0, 'gross_sales' => 0, 'total_returns' => 0,
    'net_sales' => 0, 'retail_net' => 0, 'wholesale_net' => 0, 'total_discount' => 0,
    'total_cash' => 0, 'total_upi' => 0, 'total_bank' => 0, 'total_cheque' => 0,
    'total_pending' => 0, 'gross_profit' => 0, 'profit_loss' => 0, 'net_profit' => 0
];

foreach ($shops_data as $shop) {
    $shop_id = $shop['shop_id'] ?? 0;
    $ret = $returns_map[$shop_id] ?? [
        'total_returns' => 0, 'gross_profit' => 0, 'profit_loss_from_returns' => 0,
        'retail_gross' => 0, 'wholesale_gross' => 0, 'retail_returns' => 0, 'wholesale_returns' => 0
    ];
    
    $net_sales = $shop['gross_sales'] - $ret['total_returns'];
    $retail_net = $ret['retail_gross'] - $ret['retail_returns'];
    $wholesale_net = $ret['wholesale_gross'] - $ret['wholesale_returns'];
    $net_profit = $ret['gross_profit'] - $ret['profit_loss_from_returns'];
    
    $shop_summary[] = [
        'shop_name' => $shop['shop_name'] ?? 'Unknown Shop',
        'shop_id' => $shop_id,
        'total_invoices' => $shop['total_invoices'],
        'total_customers' => $shop['total_customers'],
        'gross_sales' => $shop['gross_sales'],
        'total_returns' => $ret['total_returns'],
        'net_sales' => $net_sales,
        'retail_net' => $retail_net,
        'wholesale_net' => $wholesale_net,
        'total_discount' => $shop['total_discount'],
        'total_cash' => $shop['total_cash'],
        'total_upi' => $shop['total_upi'],
        'total_bank' => $shop['total_bank'],
        'total_cheque' => $shop['total_cheque'],
        'total_pending' => $shop['total_pending'],
        'gross_profit' => $ret['gross_profit'],
        'profit_loss_from_returns' => $ret['profit_loss_from_returns'],
        'net_profit' => $net_profit
    ];
    
    $grand_totals['invoices'] += $shop['total_invoices'];
    $grand_totals['customers'] += $shop['total_customers'];
    $grand_totals['gross_sales'] += $shop['gross_sales'];
    $grand_totals['total_returns'] += $ret['total_returns'];
    $grand_totals['net_sales'] += $net_sales;
    $grand_totals['retail_net'] += $retail_net;
    $grand_totals['wholesale_net'] += $wholesale_net;
    $grand_totals['total_discount'] += $shop['total_discount'];
    $grand_totals['total_cash'] += $shop['total_cash'];
    $grand_totals['total_upi'] += $shop['total_upi'];
    $grand_totals['total_bank'] += $shop['total_bank'];
    $grand_totals['total_cheque'] += $shop['total_cheque'];
    $grand_totals['total_pending'] += $shop['total_pending'];
    $grand_totals['gross_profit'] += $ret['gross_profit'];
    $grand_totals['profit_loss'] += $ret['profit_loss_from_returns'];
    $grand_totals['net_profit'] += $net_profit;
}

// Calculate percentages
$retail_percentage = $grand_totals['net_sales'] > 0 ? ($grand_totals['retail_net'] / $grand_totals['net_sales']) * 100 : 0;
$wholesale_percentage = $grand_totals['net_sales'] > 0 ? ($grand_totals['wholesale_net'] / $grand_totals['net_sales']) * 100 : 0;
$return_percentage = $grand_totals['gross_sales'] > 0 ? ($grand_totals['total_returns'] / $grand_totals['gross_sales']) * 100 : 0;

$total_received = $grand_totals['total_cash'] + $grand_totals['total_upi'] + $grand_totals['total_bank'] + $grand_totals['total_cheque'];
$cash_percentage = $grand_totals['net_sales'] > 0 ? ($grand_totals['total_cash'] / $grand_totals['net_sales']) * 100 : 0;
$upi_percentage = $grand_totals['net_sales'] > 0 ? ($grand_totals['total_upi'] / $grand_totals['net_sales']) * 100 : 0;
$bank_percentage = $grand_totals['net_sales'] > 0 ? ($grand_totals['total_bank'] / $grand_totals['net_sales']) * 100 : 0;
$cheque_percentage = $grand_totals['net_sales'] > 0 ? ($grand_totals['total_cheque'] / $grand_totals['net_sales']) * 100 : 0;
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Sales Summary Report"; ?>
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
                                    <i class="bx bx-stats me-2"></i>
                                    Sales Summary Report
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    Report from <?= $display_date_from ?> to <?= $display_date_to ?>
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

                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bx bx-filter me-1"></i> Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Overall Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Net Sales</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($grand_totals['net_sales'], 2) ?></h3>
                                        <small class="text-muted">
                                            <?= $grand_totals['invoices'] ?> invoices
                                            <?php if ($grand_totals['total_returns'] > 0): ?>
                                            <br>
                                            <span class="text-danger">
                                                <i class="bx bx-undo me-1"></i>
                                                Returns: ₹<?= number_format($grand_totals['total_returns'], 2) ?>
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
                                        <h3 class="mb-0 text-success">₹<?= number_format($grand_totals['net_profit'], 2) ?></h3>
                                        <small class="text-muted">
                                            After returns
                                            <?php if ($grand_totals['profit_loss'] > 0): ?>
                                            <br>
                                            <span class="text-danger">
                                                <i class="bx bx-undo me-1"></i>
                                                Profit lost: ₹<?= number_format($grand_totals['profit_loss'], 2) ?>
                                            </span>
                                            <?php endif; ?>
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
                        <div class="card card-hover border-start border-info border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Customers</h6>
                                        <h3 class="mb-0 text-info"><?= $grand_totals['customers'] ?></h3>
                                        <small class="text-muted">Unique customers</small>
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
                                        <h6 class="text-muted mb-1">Pending Amount</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($grand_totals['total_pending'], 2) ?></h3>
                                        <small class="text-muted">To be collected</small>
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

                <!-- Sales Type & Payment Distribution -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-pie-chart me-2"></i>
                                    Sales Type Distribution (Net)
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="text-center">
                                            <h1 class="display-4 text-primary mb-0">
                                                <?= number_format($retail_percentage, 1) ?>%
                                            </h1>
                                            <p class="text-muted">Retail Net Sales</p>
                                            <small class="text-muted">
                                                ₹<?= number_format($grand_totals['retail_net'], 2) ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-center">
                                            <h1 class="display-4 text-success mb-0">
                                                <?= number_format($wholesale_percentage, 1) ?>%
                                            </h1>
                                            <p class="text-muted">Wholesale Net Sales</p>
                                            <small class="text-muted">
                                                ₹<?= number_format($grand_totals['wholesale_net'], 2) ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-primary">Retail Net Sales</span>
                                        <span class="fw-bold">₹<?= number_format($grand_totals['retail_net'], 2) ?></span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-primary" style="width: <?= $retail_percentage ?>%"></div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-success">Wholesale Net Sales</span>
                                        <span class="fw-bold">₹<?= number_format($grand_totals['wholesale_net'], 2) ?></span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-success" style="width: <?= $wholesale_percentage ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-credit-card me-2"></i>
                                    Payment Method Distribution
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center mb-3">
                                    <div class="col-6 mb-3">
                                        <div class="p-3 border rounded">
                                            <h6 class="text-success mb-1">Cash</h6>
                                            <h4 class="mb-0">₹<?= number_format($grand_totals['total_cash'], 2) ?></h4>
                                            <small class="text-muted"><?= number_format($cash_percentage, 1) ?>%</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="p-3 border rounded">
                                            <h6 class="text-primary mb-1">UPI</h6>
                                            <h4 class="mb-0">₹<?= number_format($grand_totals['total_upi'], 2) ?></h4>
                                            <small class="text-muted"><?= number_format($upi_percentage, 1) ?>%</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-3 border rounded">
                                            <h6 class="text-info mb-1">Bank</h6>
                                            <h4 class="mb-0">₹<?= number_format($grand_totals['total_bank'], 2) ?></h4>
                                            <small class="text-muted"><?= number_format($bank_percentage, 1) ?>%</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-3 border rounded">
                                            <h6 class="text-warning mb-1">Cheque</h6>
                                            <h4 class="mb-0">₹<?= number_format($grand_totals['total_cheque'], 2) ?></h4>
                                            <small class="text-muted"><?= number_format($cheque_percentage, 1) ?>%</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-center mt-2">
                                    <small class="text-muted">
                                        Total Received: ₹<?= number_format($total_received, 2) ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shop/Branch Wise Summary Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bx bx-building me-2"></i>
                                    Shop/Branch Wise Summary (Net of Returns)
                                </h5>
                                <div>
                                    <span class="badge bg-primary"><?= count($shop_summary) ?> locations</span>
                                    <?php if ($grand_totals['total_returns'] > 0): ?>
                                    <span class="badge bg-danger ms-2">
                                        Returns: ₹<?= number_format($grand_totals['total_returns'], 2) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($shop_summary)): ?>
                                    <div class="text-center py-5">
                                        <i class="bx bx-building display-4 text-muted"></i>
                                        <h5 class="mt-3">No sales data found</h5>
                                        <p class="text-muted">No sales recorded for the selected period</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Shop/Branch</th>
                                                    <th class="text-center">Invoices</th>
                                                    <th class="text-center">Customers</th>
                                                    <th class="text-end">Gross Sales</th>
                                                    <th class="text-end">Returns</th>
                                                    <th class="text-end">Net Sales</th>
                                                    <th class="text-end">Retail Net</th>
                                                    <th class="text-end">Wholesale Net</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Gross Profit</th>
                                                    <th class="text-end">Net Profit</th>
                                                    <th class="text-end">Pending</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $counter = 1;
                                                foreach($shop_summary as $shop):
                                                    $has_returns = $shop['total_returns'] > 0;
                                                    $shop_percentage = $grand_totals['net_sales'] > 0 ? ($shop['net_sales'] / $grand_totals['net_sales']) * 100 : 0;
                                                ?>
                                                <tr>
                                                    <td><?= $counter++ ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($shop['shop_name']) ?></strong>
                                                        <?php if ($has_returns): ?>
                                                        <br>
                                                        <small class="text-danger">
                                                            <i class="bx bx-undo"></i>
                                                            Returns: ₹<?= number_format($shop['total_returns'], 2) ?>
                                                        </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center"><?= $shop['total_invoices'] ?></td>
                                                    <td class="text-center"><?= $shop['total_customers'] ?></td>
                                                    <td class="text-end">₹<?= number_format($shop['gross_sales'], 2) ?></td>
                                                    <td class="text-end text-danger">-₹<?= number_format($shop['total_returns'], 2) ?></td>
                                                    <td class="text-end fw-bold text-primary">
                                                        ₹<?= number_format($shop['net_sales'], 2) ?>
                                                        <div class="progress mt-1" style="height: 3px;">
                                                            <div class="progress-bar bg-primary" style="width: <?= $shop_percentage ?>%"></div>
                                                        </div>
                                                        <small class="text-muted"><?= number_format($shop_percentage, 1) ?>%</small>
                                                    </td>
                                                    <td class="text-end">
                                                        <small class="text-primary">₹<?= number_format($shop['retail_net'], 2) ?></small>
                                                    </td>
                                                    <td class="text-end">
                                                        <small class="text-success">₹<?= number_format($shop['wholesale_net'], 2) ?></small>
                                                    </td>
                                                    <td class="text-end text-danger">-₹<?= number_format($shop['total_discount'], 2) ?></td>
                                                    <td class="text-end">
                                                        ₹<?= number_format($shop['gross_profit'], 2) ?>
                                                        <?php if ($shop['profit_loss_from_returns'] > 0): ?>
                                                        <br>
                                                        <small class="text-danger">
                                                            <i class="bx bx-undo"></i> -₹<?= number_format($shop['profit_loss_from_returns'], 2) ?>
                                                        </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end text-success fw-bold">
                                                        ₹<?= number_format($shop['net_profit'], 2) ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if ($shop['total_pending'] > 0): ?>
                                                            <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2">
                                                                ₹<?= number_format($shop['total_pending'], 2) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success bg-opacity-10 text-success px-3 py-2">Paid</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-light fw-bold">
                                                <tr>
                                                    <td colspan="2">GRAND TOTAL</td>
                                                    <td class="text-center"><?= $grand_totals['invoices'] ?></td>
                                                    <td class="text-center"><?= $grand_totals['customers'] ?></td>
                                                    <td class="text-end">₹<?= number_format($grand_totals['gross_sales'], 2) ?></td>
                                                    <td class="text-end text-danger">-₹<?= number_format($grand_totals['total_returns'], 2) ?></td>
                                                    <td class="text-end text-primary">₹<?= number_format($grand_totals['net_sales'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($grand_totals['retail_net'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($grand_totals['wholesale_net'], 2) ?></td>
                                                    <td class="text-end text-danger">-₹<?= number_format($grand_totals['total_discount'], 2) ?></td>
                                                    <td class="text-end">
                                                        ₹<?= number_format($grand_totals['gross_profit'], 2) ?>
                                                        <?php if ($grand_totals['profit_loss'] > 0): ?>
                                                        <br>
                                                        <small class="text-danger">
                                                            Lost: ₹<?= number_format($grand_totals['profit_loss'], 2) ?>
                                                        </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end text-success">₹<?= number_format($grand_totals['net_profit'], 2) ?></td>
                                                    <td class="text-end text-warning">₹<?= number_format($grand_totals['total_pending'], 2) ?></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php endif; ?>
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
</style>
</body>
</html>