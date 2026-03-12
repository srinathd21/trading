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

// Get all shops for admin dropdown (filtered by business_id)
$all_shops = [];
if ($user_role === 'admin') {
    $shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name");
    $shop_stmt->execute([$business_id]);
    $all_shops = $shop_stmt->fetchAll();
}

// Build base parameters
$base_params = [$date_from, $date_to, $business_id];
$shop_filter = $selected_shop_id !== 'all' ? " AND shop_id = ?" : "";
$shop_param = $selected_shop_id !== 'all' ? [$selected_shop_id] : [];
$all_params = array_merge($base_params, $shop_param);

// ==================== GET INVOICE TOTALS FIRST (NO JOIN WITH ITEMS) ====================
$invoice_sql = "
    SELECT 
        id,
        payment_method,
        total,
        cash_amount,
        upi_amount,
        bank_amount,
        cheque_amount,
        pending_amount,
        customer_id
    FROM invoices
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND business_id = ?
";

$invoice_sql .= $shop_filter;

$invoice_stmt = $pdo->prepare($invoice_sql);
$invoice_stmt->execute($all_params);
$invoices = $invoice_stmt->fetchAll();

// ==================== GET RETURNS PER INVOICE ====================
$invoice_ids = array_column($invoices, 'id');
$returns_data = [];

if (!empty($invoice_ids)) {
    $placeholders = implode(',', array_fill(0, count($invoice_ids), '?'));
    $returns_sql = "
        SELECT 
            invoice_id,
            COALESCE(SUM(return_qty * unit_price), 0) as total_returns,
            COALESCE(SUM(CASE WHEN sale_type = 'retail' THEN return_qty * unit_price ELSE 0 END), 0) as retail_returns,
            COALESCE(SUM(CASE WHEN sale_type = 'wholesale' THEN return_qty * unit_price ELSE 0 END), 0) as wholesale_returns
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

// ==================== GET CUSTOMER TYPES ====================
$customer_ids = array_column($invoices, 'customer_id');
$customer_data = [];

if (!empty($customer_ids)) {
    $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
    $customer_sql = "
        SELECT id, customer_type
        FROM customers
        WHERE id IN ($placeholders)
    ";
    
    $customer_stmt = $pdo->prepare($customer_sql);
    $customer_stmt->execute($customer_ids);
    while ($row = $customer_stmt->fetch()) {
        $customer_data[$row['id']] = $row['customer_type'];
    }
}

// ==================== PROCESS PAYMENT METHOD DATA ====================
$payment_methods = [];
$total_sales = 0;
$total_returns = 0;
$total_cash = 0;
$total_upi = 0;
$total_bank = 0;
$total_cheque = 0;
$total_pending = 0;
$total_invoices = count($invoices);
$total_retail = 0;
$total_wholesale = 0;

foreach ($invoices as $invoice) {
    $method = $invoice['payment_method'] ?? 'cash';
    $returns = $returns_data[$invoice['id']]['total_returns'] ?? 0;
    $customer_type = $customer_data[$invoice['customer_id']] ?? 'retail';
    
    if (!isset($payment_methods[$method])) {
        $payment_methods[$method] = [
            'invoice_count' => 0,
            'total_sales' => 0,
            'total_returns' => 0,
            'retail_sales' => 0,
            'wholesale_sales' => 0,
            'cash_amount' => 0,
            'upi_amount' => 0,
            'bank_amount' => 0,
            'cheque_amount' => 0,
            'pending_amount' => 0
        ];
    }
    
    $payment_methods[$method]['invoice_count']++;
    $payment_methods[$method]['total_sales'] += $invoice['total'];
    $payment_methods[$method]['total_returns'] += $returns;
    
    if ($customer_type === 'retail') {
        $payment_methods[$method]['retail_sales'] += $invoice['total'];
    } else {
        $payment_methods[$method]['wholesale_sales'] += $invoice['total'];
    }
    
    $payment_methods[$method]['cash_amount'] += $invoice['cash_amount'] ?? 0;
    $payment_methods[$method]['upi_amount'] += $invoice['upi_amount'] ?? 0;
    $payment_methods[$method]['bank_amount'] += $invoice['bank_amount'] ?? 0;
    $payment_methods[$method]['cheque_amount'] += $invoice['cheque_amount'] ?? 0;
    $payment_methods[$method]['pending_amount'] += $invoice['pending_amount'] ?? 0;
    
    $total_sales += $invoice['total'];
    $total_returns += $returns;
    $total_cash += $invoice['cash_amount'] ?? 0;
    $total_upi += $invoice['upi_amount'] ?? 0;
    $total_bank += $invoice['bank_amount'] ?? 0;
    $total_cheque += $invoice['cheque_amount'] ?? 0;
    $total_pending += $invoice['pending_amount'] ?? 0;
    
    if ($customer_type === 'retail') {
        $total_retail += $invoice['total'];
    } else {
        $total_wholesale += $invoice['total'];
    }
}

$net_sales = $total_sales - $total_returns;

// Sort payment methods by total sales
uasort($payment_methods, function($a, $b) {
    return $b['total_sales'] <=> $a['total_sales'];
});

// ==================== CALCULATE PERCENTAGES ====================
$cash_percentage = $net_sales > 0 ? ($total_cash / $net_sales) * 100 : 0;
$upi_percentage = $net_sales > 0 ? ($total_upi / $net_sales) * 100 : 0;
$bank_percentage = $net_sales > 0 ? ($total_bank / $net_sales) * 100 : 0;
$cheque_percentage = $net_sales > 0 ? ($total_cheque / $net_sales) * 100 : 0;
$returns_percentage = $total_sales > 0 ? ($total_returns / $total_sales) * 100 : 0;
$pending_percentage = $net_sales > 0 ? ($total_pending / $net_sales) * 100 : 0;

// ==================== DAILY PAYMENT TREND ====================
$daily_sql = "
    SELECT 
        DATE(created_at) as sale_date,
        SUM(total) as daily_total,
        SUM(cash_amount) as daily_cash,
        SUM(upi_amount) as daily_upi,
        SUM(bank_amount) as daily_bank,
        SUM(cheque_amount) as daily_cheque,
        COUNT(DISTINCT id) as daily_invoices
    FROM invoices
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND business_id = ?
";

$daily_sql .= $shop_filter;
$daily_sql .= " GROUP BY DATE(created_at) ORDER BY sale_date DESC";

$daily_stmt = $pdo->prepare($daily_sql);
$daily_stmt->execute($all_params);
$daily_data = $daily_stmt->fetchAll();

// Get daily returns
$daily_returns = [];
if (!empty($daily_data)) {
    $date_placeholders = implode(',', array_fill(0, count($daily_data), '?'));
    $dates = array_column($daily_data, 'sale_date');
    
    $daily_returns_sql = "
        SELECT 
            DATE(i.created_at) as sale_date,
            COALESCE(SUM(ii.return_qty * ii.unit_price), 0) as daily_returns
        FROM invoice_items ii
        JOIN invoices i ON ii.invoice_id = i.id
        WHERE DATE(i.created_at) IN ($date_placeholders)
        AND i.business_id = ?
    ";
    
    $daily_returns_params = array_merge($dates, [$business_id]);
    if ($selected_shop_id !== 'all') {
        $daily_returns_sql .= " AND i.shop_id = ?";
        $daily_returns_params[] = $selected_shop_id;
    }
    $daily_returns_sql .= " GROUP BY DATE(i.created_at)";
    
    $daily_returns_stmt = $pdo->prepare($daily_returns_sql);
    $daily_returns_stmt->execute($daily_returns_params);
    while ($row = $daily_returns_stmt->fetch()) {
        $daily_returns[$row['sale_date']] = $row['daily_returns'];
    }
}

// ==================== CSV EXPORT ====================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payment_methods_report_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Payment Methods Report (Net of Returns)', 'Period: ' . $date_from . ' to ' . $date_to]);
    fputcsv($output, ['Business: ' . ($_SESSION['current_business_name'] ?? 'Business')]);
    fputcsv($output, ['']);
    
    fputcsv($output, ['Payment Method', 'Invoices', 'Gross Sales', 'Returns', 'Net Sales', '% of Net', 'Retail', 'Wholesale']);
    
    foreach ($payment_methods as $method => $data) {
        $net_method_sales = $data['total_sales'] - $data['total_returns'];
        $method_percentage = $net_sales > 0 ? ($net_method_sales / $net_sales) * 100 : 0;
        
        fputcsv($output, [
            ucfirst($method),
            $data['invoice_count'],
            number_format($data['total_sales'], 2),
            number_format($data['total_returns'], 2),
            number_format($net_method_sales, 2),
            number_format($method_percentage, 1) . '%',
            number_format($data['retail_sales'], 2),
            number_format($data['wholesale_sales'], 2)
        ]);
    }
    
    fclose($output);
    exit();
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Payment Methods Report"; ?>
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
                                    <i class="bx bx-credit-card me-2"></i>
                                    Payment Methods Report
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i> 
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    Report from <?= date('d M Y', strtotime($date_from)) ?> to <?= date('d M Y', strtotime($date_to)) ?>
                                    <?php if ($selected_shop_id !== 'all'): ?>
                                        <span class="badge bg-info">Filtered by Branch</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">All Branches</span>
                                    <?php endif; ?>
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

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Cash Received</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($total_cash, 2) ?></h3>
                                        <small class="text-muted"><?= number_format($cash_percentage, 1) ?>% of net</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-money text-success"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">UPI Received</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($total_upi, 2) ?></h3>
                                        <small class="text-muted"><?= number_format($upi_percentage, 1) ?>% of net</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-credit-card text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Bank Transfer</h6>
                                        <h3 class="mb-0 text-info">₹<?= number_format($total_bank, 2) ?></h3>
                                        <small class="text-muted"><?= number_format($bank_percentage, 1) ?>% of net</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-bank text-info"></i>
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
                                        <h6 class="text-muted mb-1">Cheque Received</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($total_cheque, 2) ?></h3>
                                        <small class="text-muted"><?= number_format($cheque_percentage, 1) ?>% of net</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-receipt text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods Breakdown -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bx bx-pie-chart-alt me-2"></i>
                                    Payment Method Distribution
                                </h5>
                                <div>
                                    <span class="badge bg-primary"><?= $total_invoices ?> invoices</span>
                                    <?php if ($total_returns > 0): ?>
                                    <span class="badge bg-danger ms-2">
                                        Returns: ₹<?= number_format($total_returns, 2) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Payment Method</th>
                                                        <th class="text-center">Invoices</th>
                                                        <th class="text-end">Gross Sales</th>
                                                        <th class="text-end">Returns</th>
                                                        <th class="text-end">Net Sales</th>
                                                        <th class="text-center">% of Net</th>
                                                        <th class="text-end">Avg. Invoice</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    foreach($payment_methods as $method => $data): 
                                                        $net_method_sales = $data['total_sales'] - $data['total_returns'];
                                                        $method_percentage = $net_sales > 0 ? ($net_method_sales / $net_sales) * 100 : 0;
                                                        $average = $data['invoice_count'] > 0 ? $net_method_sales / $data['invoice_count'] : 0;
                                                        $method_color = [
                                                            'cash' => 'success',
                                                            'upi' => 'primary',
                                                            'bank' => 'info',
                                                            'cheque' => 'warning'
                                                        ][$method] ?? 'secondary';
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-<?= $method_color ?> bg-opacity-10 text-<?= $method_color ?> px-3 py-2">
                                                                <?= strtoupper($method) ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-center"><?= $data['invoice_count'] ?></td>
                                                        <td class="text-end">₹<?= number_format($data['total_sales'], 2) ?></td>
                                                        <td class="text-end">
                                                            <?php if ($data['total_returns'] > 0): ?>
                                                            <span class="text-danger">-₹<?= number_format($data['total_returns'], 2) ?></span>
                                                            <?php else: ?>
                                                            <span class="text-muted">₹0.00</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end fw-bold text-<?= $method_color ?>">
                                                            ₹<?= number_format($net_method_sales, 2) ?>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="progress flex-grow-1" style="height: 8px;">
                                                                    <div class="progress-bar bg-<?= $method_color ?>" style="width: <?= $method_percentage ?>%"></div>
                                                                </div>
                                                                <small class="ms-2"><?= number_format($method_percentage, 1) ?>%</small>
                                                            </div>
                                                        </td>
                                                        <td class="text-end">
                                                            <small class="text-muted">₹<?= number_format($average, 2) ?></small>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    
                                                    <tr class="table-light fw-bold">
                                                        <td>TOTAL</td>
                                                        <td class="text-center"><?= $total_invoices ?></td>
                                                        <td class="text-end">₹<?= number_format($total_sales, 2) ?></td>
                                                        <td class="text-end text-danger">-₹<?= number_format($total_returns, 2) ?></td>
                                                        <td class="text-end text-primary">₹<?= number_format($net_sales, 2) ?></td>
                                                        <td>100%</td>
                                                        <td class="text-end">
                                                            ₹<?= number_format($total_invoices > 0 ? $net_sales / $total_invoices : 0, 2) ?>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6 class="mb-4">Payment Summary</h6>
                                                
                                                <div class="mb-3">
                                                    <div class="d-flex justify-content-between mb-1">
                                                        <span class="text-success">Cash</span>
                                                        <span class="fw-bold">₹<?= number_format($total_cash, 2) ?></span>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar bg-success" style="width: <?= $cash_percentage ?>%"></div>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <div class="d-flex justify-content-between mb-1">
                                                        <span class="text-primary">UPI</span>
                                                        <span class="fw-bold">₹<?= number_format($total_upi, 2) ?></span>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar bg-primary" style="width: <?= $upi_percentage ?>%"></div>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <div class="d-flex justify-content-between mb-1">
                                                        <span class="text-info">Bank Transfer</span>
                                                        <span class="fw-bold">₹<?= number_format($total_bank, 2) ?></span>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar bg-info" style="width: <?= $bank_percentage ?>%"></div>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <div class="d-flex justify-content-between mb-1">
                                                        <span class="text-warning">Cheque</span>
                                                        <span class="fw-bold">₹<?= number_format($total_cheque, 2) ?></span>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar bg-warning" style="width: <?= $cheque_percentage ?>%"></div>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($total_pending > 0): ?>
                                                <div class="mt-3 pt-3 border-top">
                                                    <div class="d-flex justify-content-between">
                                                        <span class="text-danger">
                                                            <i class="bx bx-time me-1"></i>
                                                            Pending
                                                        </span>
                                                        <span class="fw-bold text-danger">₹<?= number_format($total_pending, 2) ?></span>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($total_returns > 0): ?>
                                                <div class="mt-3 pt-3 border-top">
                                                    <div class="d-flex justify-content-between">
                                                        <span class="text-danger">
                                                            <i class="bx bx-undo me-1"></i>
                                                            Total Returns
                                                        </span>
                                                        <span class="fw-bold text-danger">₹<?= number_format($total_returns, 2) ?></span>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daily Payment Trend -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-calendar me-2"></i>
                                    Daily Payment Trend
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($daily_data)): ?>
                                    <div class="text-center py-4">
                                        <i class="bx bx-line-chart display-4 text-muted"></i>
                                        <p class="text-muted mt-3">No payment data available</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th class="text-center">Invoices</th>
                                                    <th class="text-end">Cash</th>
                                                    <th class="text-end">UPI</th>
                                                    <th class="text-end">Bank</th>
                                                    <th class="text-end">Cheque</th>
                                                    <th class="text-end">Returns</th>
                                                    <th class="text-end">Net Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($daily_data as $day): 
                                                    $daily_return = $daily_returns[$day['sale_date']] ?? 0;
                                                    $daily_net = $day['daily_total'] - $daily_return;
                                                ?>
                                                <tr>
                                                    <td><?= date('d M Y', strtotime($day['sale_date'])) ?></td>
                                                    <td class="text-center"><?= $day['daily_invoices'] ?></td>
                                                    <td class="text-end text-success">₹<?= number_format($day['daily_cash'], 2) ?></td>
                                                    <td class="text-end text-primary">₹<?= number_format($day['daily_upi'], 2) ?></td>
                                                    <td class="text-end text-info">₹<?= number_format($day['daily_bank'], 2) ?></td>
                                                    <td class="text-end text-warning">₹<?= number_format($day['daily_cheque'], 2) ?></td>
                                                    <td class="text-end text-danger">-₹<?= number_format($daily_return, 2) ?></td>
                                                    <td class="text-end fw-bold text-success">₹<?= number_format($daily_net, 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
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