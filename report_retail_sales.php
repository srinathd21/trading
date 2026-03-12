<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Shop name
$shop_name = 'All Branches';
if ($selected_shop_id !== 'all') {
    $stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
    $stmt->execute([$selected_shop_id, $business_id]);
    $shop = $stmt->fetch();
    $shop_name = $shop['shop_name'] ?? 'Shop';
}

// Build parameters
$params = [$start_date, $end_date, $business_id];
$shop_filter = $selected_shop_id !== 'all' ? " AND i.shop_id = ?" : "";
$shop_param = $selected_shop_id !== 'all' ? [$selected_shop_id] : [];

// ==================== CSV EXPORT (With Returns) ====================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="retail_sales_report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // Headers
    fputcsv($output, ['Retail Sales Report']);
    fputcsv($output, ['Period', "$display_date_from to $display_date_to"]);
    fputcsv($output, ['Business', $_SESSION['current_business_name'] ?? 'Business']);
    fputcsv($output, ['Branch', $shop_name]);
    fputcsv($output, ['']);
    fputcsv($output, ['Invoice No', 'Date', 'Customer', 'Items', 'Subtotal', 'Discount', 'Total', 'Returns', 'Net Amount', 'Payment Method', 'Cash', 'UPI', 'Bank', 'Cheque', 'Seller', 'Shop']);

    // Retail invoices only (with returns handled correctly)
    $csv_sql = "
    SELECT
        i.id,
        i.invoice_number,
        DATE(i.created_at) as invoice_date,
        c.name as customer_name,
        (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.id) as items_count,
        i.subtotal,
        (i.discount + COALESCE(i.overall_discount, 0)) as discount,
        i.total,
        (SELECT COALESCE(SUM(return_qty * unit_price), 0) FROM invoice_items WHERE invoice_id = i.id) as total_returns,
        i.payment_method,
        i.cash_amount,
        i.upi_amount,
        i.bank_amount,
        i.cheque_amount,
        u.full_name as seller_name,
        s.shop_name
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.seller_id = u.id
    LEFT JOIN shops s ON i.shop_id = s.id
    WHERE DATE(i.created_at) BETWEEN ? AND ?
      AND i.business_id = ?
      $shop_filter
      AND NOT EXISTS (
          SELECT 1 FROM invoice_items ii2
          WHERE ii2.invoice_id = i.id
          AND (ii2.sale_type IS NULL OR ii2.sale_type != 'retail')
      )
    ORDER BY i.created_at DESC";
    
    $csv_stmt = $pdo->prepare($csv_sql);
    $all_csv_params = array_merge($params, $shop_param);
    $csv_stmt->execute($all_csv_params);
    $sales = $csv_stmt->fetchAll();

    foreach ($sales as $sale) {
        $net_amount = $sale['total'] - $sale['total_returns'];
        fputcsv($output, [
            $sale['invoice_number'],
            $sale['invoice_date'],
            $sale['customer_name'] ?? 'Walk-in Customer',
            $sale['items_count'],
            number_format($sale['subtotal'], 2),
            number_format($sale['discount'], 2),
            number_format($sale['total'], 2),
            number_format($sale['total_returns'], 2),
            number_format($net_amount, 2),
            ucfirst($sale['payment_method']),
            number_format($sale['cash_amount'], 2),
            number_format($sale['upi_amount'], 2),
            number_format($sale['bank_amount'], 2),
            number_format($sale['cheque_amount'], 2),
            $sale['seller_name'] ?? 'N/A',
            $sale['shop_name'] ?? 'N/A'
        ]);
    }
    
    // Summary with returns
    $summary_sql = "
    SELECT
        COUNT(DISTINCT i.id) as total_invoices,
        COUNT(DISTINCT i.customer_id) as total_customers,
        SUM(i.total) as total_sales,
        SUM(i.discount + COALESCE(i.overall_discount, 0)) as total_discount,
        SUM(i.cash_amount) as total_cash,
        SUM(i.upi_amount) as total_upi,
        SUM(i.bank_amount) as total_bank,
        SUM(i.cheque_amount) as total_cheque,
        SUM(i.pending_amount) as total_pending,
        (SELECT COALESCE(SUM(profit), 0) FROM invoice_items ii WHERE ii.invoice_id IN 
            (SELECT id FROM invoices i2 WHERE DATE(i2.created_at) BETWEEN ? AND ? 
             AND i2.business_id = ? $shop_filter 
             AND NOT EXISTS (SELECT 1 FROM invoice_items ii2 WHERE ii2.invoice_id = i2.id AND (ii2.sale_type IS NULL OR ii2.sale_type != 'retail')))
        ) as total_gross_profit,
        (SELECT COALESCE(SUM(return_qty * unit_price), 0) FROM invoice_items ii WHERE ii.invoice_id IN 
            (SELECT id FROM invoices i2 WHERE DATE(i2.created_at) BETWEEN ? AND ? 
             AND i2.business_id = ? $shop_filter 
             AND NOT EXISTS (SELECT 1 FROM invoice_items ii2 WHERE ii2.invoice_id = i2.id AND (ii2.sale_type IS NULL OR ii2.sale_type != 'retail')))
        ) as total_returns,
        (SELECT COALESCE(SUM(return_qty * profit / NULLIF(quantity, 0)), 0) FROM invoice_items ii WHERE ii.invoice_id IN 
            (SELECT id FROM invoices i2 WHERE DATE(i2.created_at) BETWEEN ? AND ? 
             AND i2.business_id = ? $shop_filter 
             AND NOT EXISTS (SELECT 1 FROM invoice_items ii2 WHERE ii2.invoice_id = i2.id AND (ii2.sale_type IS NULL OR ii2.sale_type != 'retail')))
        ) as total_profit_loss_returns
    FROM invoices i
    WHERE DATE(i.created_at) BETWEEN ? AND ?
      AND i.business_id = ?
      $shop_filter
      AND NOT EXISTS (
          SELECT 1 FROM invoice_items ii2
          WHERE ii2.invoice_id = i.id
          AND (ii2.sale_type IS NULL OR ii2.sale_type != 'retail')
      )";
    
    $all_params = array_merge($params, $shop_param);
    $summary_params = array_merge($all_params, $all_params, $all_params, $all_params, $all_params, $all_params, $all_params, $all_params);
    
    // Debug: Check if parameters count matches
    $stmt_check = $pdo->prepare($summary_sql);
    $param_count = substr_count($summary_sql, '?');
    if (count($summary_params) != $param_count) {
        die("Parameter count mismatch: SQL has $param_count placeholders, but " . count($summary_params) . " parameters provided");
    }
    
    $summary_stmt = $pdo->prepare($summary_sql);
    $summary_stmt->execute($summary_params);
    $summary = $summary_stmt->fetch();

    $net_sales = ($summary['total_sales'] ?? 0) - ($summary['total_returns'] ?? 0);

    fputcsv($output, ['']);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Invoices', $summary['total_invoices'] ?? 0]);
    fputcsv($output, ['Gross Sales', '₹' . number_format($summary['total_sales'] ?? 0, 2)]);
    fputcsv($output, ['Total Returns', '₹' . number_format($summary['total_returns'] ?? 0, 2)]);
    fputcsv($output, ['Net Sales', '₹' . number_format($net_sales, 2)]);
    fputcsv($output, ['Total Discount', '₹' . number_format($summary['total_discount'] ?? 0, 2)]);
    fputcsv($output, ['Cash', '₹' . number_format($summary['total_cash'] ?? 0, 2)]);
    fputcsv($output, ['UPI', '₹' . number_format($summary['total_upi'] ?? 0, 2)]);
    fputcsv($output, ['Bank', '₹' . number_format($summary['total_bank'] ?? 0, 2)]);
    fputcsv($output, ['Cheque', '₹' . number_format($summary['total_cheque'] ?? 0, 2)]);

    fclose($output);
    exit();
}

// ==================== MAIN RETAIL SALES QUERY (With Returns) ====================
$retail_sales_sql = "
    SELECT
        i.id,
        i.invoice_number,
        i.created_at,
        i.subtotal,
        i.discount,
        i.total,
        i.cash_amount,
        i.upi_amount,
        i.bank_amount,
        i.cheque_amount,
        i.payment_method,
        i.pending_amount,
        c.name as customer_name,
        c.phone as customer_phone,
        u.full_name as seller_name,
        s.shop_name,
        -- Get items count from a subquery to avoid duplication
        (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.id) as items_count,
        -- Get total profit from a subquery
        (SELECT COALESCE(SUM(profit), 0) FROM invoice_items WHERE invoice_id = i.id) as total_profit,
        -- Get total returns from a subquery
        (SELECT COALESCE(SUM(return_qty * unit_price), 0) FROM invoice_items WHERE invoice_id = i.id) as total_returns,
        -- Get profit loss from returns from a subquery
        (SELECT COALESCE(SUM(return_qty * profit / NULLIF(quantity, 0)), 0) FROM invoice_items WHERE invoice_id = i.id) as profit_loss_returns
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.seller_id = u.id
    LEFT JOIN shops s ON i.shop_id = s.id
    WHERE DATE(i.created_at) BETWEEN ? AND ?
      AND i.business_id = ?
      $shop_filter
      AND NOT EXISTS (
          SELECT 1 FROM invoice_items ii2
          WHERE ii2.invoice_id = i.id
          AND (ii2.sale_type IS NULL OR ii2.sale_type != 'retail')
      )
    ORDER BY i.created_at DESC
";

$retail_stmt = $pdo->prepare($retail_sales_sql);
$all_params = array_merge($params, $shop_param);
$retail_stmt->execute($all_params);
$retail_sales = $retail_stmt->fetchAll();

// ==================== RETAIL SUMMARY (With Returns) ====================
$retail_summary_sql = "
    SELECT
        COUNT(DISTINCT i.id) as total_invoices,
        COUNT(DISTINCT i.customer_id) as total_customers,
        SUM(i.total) as total_sales,
        SUM(i.discount + COALESCE(i.overall_discount, 0)) as total_discount,
        SUM(i.cash_amount) as total_cash,
        SUM(i.upi_amount) as total_upi,
        SUM(i.bank_amount) as total_bank,
        SUM(i.cheque_amount) as total_cheque,
        SUM(i.pending_amount) as total_pending
    FROM invoices i
    WHERE DATE(i.created_at) BETWEEN ? AND ?
      AND i.business_id = ?
      $shop_filter
      AND NOT EXISTS (
          SELECT 1 FROM invoice_items ii2
          WHERE ii2.invoice_id = i.id
          AND (ii2.sale_type IS NULL OR ii2.sale_type != 'retail')
      )
";

$retail_summary_stmt = $pdo->prepare($retail_summary_sql);
$retail_summary_stmt->execute($all_params);
$retail_summary = $retail_summary_stmt->fetch();

// Get returns and profit data separately
$returns_sql = "
    SELECT 
        COALESCE(SUM(ii.return_qty * ii.unit_price), 0) as total_returns,
        COALESCE(SUM(ii.profit), 0) as total_gross_profit,
        COALESCE(SUM(ii.return_qty * ii.profit / NULLIF(ii.quantity, 0)), 0) as total_profit_loss_returns
    FROM invoice_items ii
    WHERE ii.invoice_id IN (
        SELECT i.id 
        FROM invoices i 
        WHERE DATE(i.created_at) BETWEEN ? AND ?
          AND i.business_id = ?
          $shop_filter
          AND NOT EXISTS (
              SELECT 1 FROM invoice_items ii2
              WHERE ii2.invoice_id = i.id
              AND (ii2.sale_type IS NULL OR ii2.sale_type != 'retail')
          )
    )
";

$returns_stmt = $pdo->prepare($returns_sql);
$returns_stmt->execute($all_params);
$returns_data = $returns_stmt->fetch();

// Merge the data
$retail_summary['total_returns'] = $returns_data['total_returns'] ?? 0;
$retail_summary['total_gross_profit'] = $returns_data['total_gross_profit'] ?? 0;
$retail_summary['total_profit_loss_returns'] = $returns_data['total_profit_loss_returns'] ?? 0;

// Calculate net values
$net_sales = ($retail_summary['total_sales'] ?? 0) - ($retail_summary['total_returns'] ?? 0);
$net_profit = ($retail_summary['total_gross_profit'] ?? 0) - ($retail_summary['total_profit_loss_returns'] ?? 0);

// Correct calculation of total received amounts
$total_received = ($retail_summary['total_cash'] ?? 0) +
                  ($retail_summary['total_upi'] ?? 0) +
                  ($retail_summary['total_bank'] ?? 0) +
                  ($retail_summary['total_cheque'] ?? 0);
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Retail Sales Report"; include 'includes/head.php'; ?>
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
                                <h4 class="mb-1">
                                    <i class="bx bx-store me-2"></i>
                                    Retail Sales Report
                                    <small class="text-muted ms-2">
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    Report from <?= $display_date_from ?> to <?= $display_date_to ?>
                                    • Branch: <strong><?= htmlspecialchars($shop_name) ?></strong>
                                </p>
                                <small class="text-info">
                                    <i class="bx bx-info-circle me-1"></i>
                                    Only invoices with all items sold at retail price (Returns deducted)
                                </small>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="?export=csv&date_from=<?= $start_date ?>&date_to=<?= $end_date ?>&shop_id=<?= $selected_shop_id ?>"
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
                                <label>Select Branch</label>
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
                                    <i class="bx bx-filter me-1"></i> Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Gross Retail Sales</h6>
                                <h3 class="text-primary">₹<?= number_format($retail_summary['total_sales'] ?? 0, 2) ?></h3>
                                <small>Before returns</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Returns</h6>
                                <h3 class="text-danger">-₹<?= number_format($retail_summary['total_returns'] ?? 0, 2) ?></h3>
                                <small>Products returned</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Net Retail Sales</h6>
                                <h3 class="text-success">₹<?= number_format($net_sales, 2) ?></h3>
                                <small>After returns</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Net Profit</h6>
                                <h3 class="text-warning">₹<?= number_format($net_profit, 2) ?></h3>
                                <small>After return adjustments</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Breakdown -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bx bx-credit-card me-2"></i> Payment Method Breakdown</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <?php
                                    $payments = [
                                        'Cash' => ['amount' => $retail_summary['total_cash'] ?? 0, 'color' => 'success'],
                                        'UPI' => ['amount' => $retail_summary['total_upi'] ?? 0, 'color' => 'primary'],
                                        'Bank' => ['amount' => $retail_summary['total_bank'] ?? 0, 'color' => 'info'],
                                        'Cheque' => ['amount' => $retail_summary['total_cheque'] ?? 0, 'color' => 'warning']
                                    ];
                                    foreach ($payments as $name => $p):
                                        if ($p['amount'] > 0):
                                            $perc = $total_received > 0 ? ($p['amount'] / $total_received) * 100 : 0;
                                    ?>
                                    <div class="col-md-3">
                                        <div class="text-center p-4 border rounded bg-light">
                                            <h6 class="text-<?= $p['color'] ?>"><?= $name ?></h6>
                                            <h3>₹<?= number_format($p['amount'], 2) ?></h3>
                                            <small class="text-muted"><?= number_format($perc, 1) ?>%</small>
                                        </div>
                                    </div>
                                    <?php endif; endforeach; ?>
                                </div>
                                <?php if ($retail_summary['total_pending'] > 0): ?>
                                <div class="mt-4 text-center">
                                    <span class="text-danger fw-bold fs-4">
                                        <i class="bx bx-time-five me-2"></i>
                                        Pending: ₹<?= number_format($retail_summary['total_pending'], 2) ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Retail Sales Table -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-list-ul me-2"></i>
                            Retail Sales Details
                            <span class="badge bg-primary ms-2"><?= count($retail_sales) ?> invoices</span>
                            <span class="badge bg-danger ms-2">Returns: ₹<?= number_format($retail_summary['total_returns'] ?? 0, 2) ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($retail_sales)): ?>
                        <div class="text-center py-5">
                            <i class="bx bx-package display-4 text-muted mb-3"></i>
                            <h5>No retail sales found</h5>
                            <p class="text-muted">No invoices with all items sold at retail price in the selected period.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Invoice No</th>
                                        <th>Date & Time</th>
                                        <th>Customer</th>
                                        <th class="text-center">Items</th>
                                        <th class="text-end">Subtotal</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Total</th>
                                        <th class="text-end">Returns</th>
                                        <th class="text-end">Net Amount</th>
                                        <th>Payment</th>
                                        <th class="text-end">Profit</th>
                                        <th>Seller</th>
                                        <th>Shop</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $counter = 1;
                                    $table_total_subtotal = 0;
                                    $table_total_discount = 0;
                                    $table_total_gross = 0;
                                    $table_total_returns = 0;
                                    $table_total_net = 0;
                                    $table_total_net_profit = 0;
                                    
                                    foreach ($retail_sales as $sale):
                                        $net_amount = $sale['total'] - $sale['total_returns'];
                                        $net_profit_row = $sale['total_profit'] - $sale['profit_loss_returns'];
                                        
                                        $table_total_subtotal += $sale['subtotal'];
                                        $table_total_discount += $sale['discount'];
                                        $table_total_gross += $sale['total'];
                                        $table_total_returns += $sale['total_returns'];
                                        $table_total_net += $net_amount;
                                        $table_total_net_profit += $net_profit_row;
                                    ?>
                                    <tr>
                                        <td><?= $counter++ ?></td>
                                        <td>
                                            <a href="invoice_view.php?invoice_id=<?= $sale['id'] ?>" class="text-primary fw-bold">
                                                <?= htmlspecialchars($sale['invoice_number']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?= date('d M Y', strtotime($sale['created_at'])) ?><br>
                                            <small class="text-muted"><?= date('h:i A', strtotime($sale['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in') ?></strong><br>
                                            <?php if (!empty($sale['customer_phone'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($sale['customer_phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary bg-opacity-10 text-primary">
                                                <?= $sale['items_count'] ?>
                                            </span>
                                        </td>
                                        <td class="text-end">₹<?= number_format($sale['subtotal'], 2) ?></td>
                                        <td class="text-end text-danger">-₹<?= number_format($sale['discount'], 2) ?></td>
                                        <td class="text-end fw-bold text-primary">₹<?= number_format($sale['total'], 2) ?></td>
                                        <td class="text-end text-danger">
                                            <?php if ($sale['total_returns'] > 0): ?>
                                            -₹<?= number_format($sale['total_returns'], 2) ?>
                                            <?php else: ?>
                                            <span class="text-muted">₹0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold text-success">₹<?= number_format($net_amount, 2) ?></td>
                                        <td>
                                            <?php
                                            $pm = strtoupper($sale['payment_method']);
                                            $badge = '';
                                            if ($sale['payment_method'] == 'cash') $badge = 'success';
                                            else if ($sale['payment_method'] == 'upi') $badge = 'primary';
                                            else if ($sale['payment_method'] == 'bank') $badge = 'info';
                                            else if ($sale['payment_method'] == 'cheque') $badge = 'warning';
                                            else $badge = 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $badge ?> bg-opacity-10 text-<?= $badge ?>">
                                                <?= $pm ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($sale['total_returns'] > 0): ?>
                                                <span class="text-success fw-bold">₹<?= number_format($net_profit_row, 2) ?></span><br>
                                                <small class="text-muted">was ₹<?= number_format($sale['total_profit'], 2) ?></small>
                                            <?php else: ?>
                                                <span class="text-success fw-bold">₹<?= number_format($sale['total_profit'], 2) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($sale['seller_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($sale['shop_name'] ?? 'N/A') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <!-- Summary Row -->
                                    <tr class="table-active fw-bold">
                                        <td colspan="5" class="text-end">TOTALS:</td>
                                        <td class="text-end">₹<?= number_format($table_total_subtotal, 2) ?></td>
                                        <td class="text-end text-danger">-₹<?= number_format($table_total_discount, 2) ?></td>
                                        <td class="text-end text-primary">₹<?= number_format($table_total_gross, 2) ?></td>
                                        <td class="text-end text-danger">-₹<?= number_format($table_total_returns, 2) ?></td>
                                        <td class="text-end text-success">₹<?= number_format($table_total_net, 2) ?></td>
                                        <td></td>
                                        <td class="text-end text-success">Net Profit: ₹<?= number_format($table_total_net_profit, 2) ?></td>
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
.card-hover:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
.border-start { border-left-width: 5px !important; }
</style>
</body>
</html>