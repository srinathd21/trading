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

$today = date('Y-m-d');

$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

// if empty -> set defaults
if ($date_from === '') $date_from = date('Y-m-01');
if ($date_to === '')   $date_to   = $today;

// if invalid date format -> set defaults
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $today;
$display_date_from = date('d M Y', strtotime($date_from));
$display_date_to = date('d M Y', strtotime($date_to));

$category_filter = $_GET['category_id'] ?? 'all';
$sale_type_filter = $_GET['sale_type'] ?? 'all';
$top_n = isset($_GET['top_n']) && is_numeric($_GET['top_n']) ? (int)$_GET['top_n'] : 10;
$min_sales_qty = isset($_GET['min_sales_qty']) && is_numeric($_GET['min_sales_qty']) ? (int)$_GET['min_sales_qty'] : 0;

// Get all shops for admin dropdown
$all_shops = [];
if ($user_role === 'admin') {
    $shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name");
    $shop_stmt->execute([$business_id]);
    $all_shops = $shop_stmt->fetchAll();
}

// Get categories
$category_stmt = $pdo->prepare("SELECT id, category_name FROM categories WHERE business_id = ? ORDER BY category_name");
$category_stmt->execute([$business_id]);
$categories = $category_stmt->fetchAll();

// Get shop name
$shop_name = $selected_shop_id === 'all' ? 'All Branches' : 'Selected Branch';
if ($selected_shop_id !== 'all') {
    $stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
    $stmt->execute([$selected_shop_id, $business_id]);
    $shop = $stmt->fetch();
    $shop_name = $shop['shop_name'] ?? 'Selected Shop';
}

// ==================== HELPER FUNCTIONS ====================
function safeNumber($value) {
    return is_numeric($value) ? (float)$value : 0.00;
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

// ==================== PRODUCT WISE SALES QUERY ====================
$product_sales_sql = "
    SELECT 
        p.id,
        p.product_name,
        p.product_code,
        p.barcode,
        p.hsn_code,
        c.category_name,
        p.stock_price,
        p.retail_price,
        p.wholesale_price,
        p.unit_of_measure,
        
        -- Retail Sales
        COALESCE(SUM(CASE WHEN ii.sale_type = 'retail' THEN ii.quantity ELSE 0 END), 0) as retail_qty,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'retail' THEN ii.total_price ELSE 0 END), 0) as retail_amount,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'retail' AND ii.return_qty > 0 THEN ii.return_qty ELSE 0 END), 0) as retail_returns_qty,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'retail' AND ii.return_qty > 0 THEN ii.return_qty * ii.unit_price ELSE 0 END), 0) as retail_returns_amount,
        
        -- Wholesale Sales
        COALESCE(SUM(CASE WHEN ii.sale_type = 'wholesale' THEN ii.quantity ELSE 0 END), 0) as wholesale_qty,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'wholesale' THEN ii.total_price ELSE 0 END), 0) as wholesale_amount,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'wholesale' AND ii.return_qty > 0 THEN ii.return_qty ELSE 0 END), 0) as wholesale_returns_qty,
        COALESCE(SUM(CASE WHEN ii.sale_type = 'wholesale' AND ii.return_qty > 0 THEN ii.return_qty * ii.unit_price ELSE 0 END), 0) as wholesale_returns_amount,
        
        -- Combined Totals
        COALESCE(SUM(ii.quantity), 0) as total_qty,
        COALESCE(SUM(ii.total_price), 0) as total_amount,
        COALESCE(SUM(ii.return_qty), 0) as total_returns_qty,
        COALESCE(SUM(CASE WHEN ii.return_qty > 0 THEN ii.return_qty * ii.unit_price ELSE 0 END), 0) as total_returns_amount,
        
        -- Profit Calculation
        COALESCE(SUM(ii.profit), 0) as gross_profit,
        COALESCE(SUM(CASE WHEN ii.return_qty > 0 THEN (ii.return_qty * ii.profit / GREATEST(ii.quantity, 1)) ELSE 0 END), 0) as profit_lost_returns,
        
        -- Stock Information
        COALESCE(ps.quantity, 0) as current_stock,
        
        -- Additional metrics
        COUNT(DISTINCT i.id) as invoice_count,
        COUNT(DISTINCT i.customer_id) as customer_count
        
    FROM products p
    LEFT JOIN invoice_items ii ON p.id = ii.product_id
    LEFT JOIN invoices i ON ii.invoice_id = i.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ?
    
    WHERE p.business_id = ?
      AND p.is_active = 1
";

$params = [$selected_shop_id !== 'all' ? $selected_shop_id : 0, $business_id];

// Add date filter if we have invoice data
if ($date_from && $date_to) {
    $product_sales_sql .= " AND (i.id IS NULL OR DATE(i.created_at) BETWEEN ? AND ?)";
    array_push($params, $date_from, $date_to);
}

// Add category filter
if ($category_filter !== 'all') {
    $product_sales_sql .= " AND p.category_id = ?";
    $params[] = $category_filter;
}

$product_sales_sql .= " GROUP BY p.id, p.product_name, p.product_code";

// Filter by sale type
if ($sale_type_filter === 'retail') {
    $product_sales_sql .= " HAVING retail_qty > 0";
} elseif ($sale_type_filter === 'wholesale') {
    $product_sales_sql .= " HAVING wholesale_qty > 0";
} else {
    $product_sales_sql .= " HAVING total_qty > 0";
}

// Filter by minimum sales quantity
if ($min_sales_qty > 0) {
    $product_sales_sql .= " AND total_qty >= ?";
    $params[] = $min_sales_qty;
}

// Order by highest selling
$order_by = $_GET['order_by'] ?? 'total_amount';
$order_dir = $_GET['order_dir'] ?? 'desc';

$valid_order_columns = ['product_name', 'total_qty', 'total_amount', 'retail_qty', 'wholesale_qty'];
$order_by = in_array($order_by, $valid_order_columns) ? $order_by : 'total_amount';
$order_dir = strtolower($order_dir) === 'asc' ? 'ASC' : 'DESC';

$product_sales_sql .= " ORDER BY $order_by $order_dir";

// Limit for top products
if ($top_n > 0 && $order_by !== 'product_name') {
    $product_sales_sql .= " LIMIT ?";
    $params[] = $top_n;
}

$product_stmt = $pdo->prepare($product_sales_sql);
$product_stmt->execute($params);
$raw_product_sales = $product_stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================== CALCULATE DERIVED FIELDS ====================
$product_sales = [];
$summary_totals = [
    'total_products' => 0,
    'total_qty' => 0,
    'total_amount' => 0,
    'total_returns_qty' => 0,
    'total_returns_amount' => 0,
    'net_qty' => 0,
    'net_amount' => 0,
    'gross_profit' => 0,
    'profit_lost_returns' => 0,
    'net_profit' => 0,
    'retail_qty' => 0,
    'retail_amount' => 0,
    'retail_returns_qty' => 0,
    'retail_returns_amount' => 0,
    'wholesale_qty' => 0,
    'wholesale_amount' => 0,
    'wholesale_returns_qty' => 0,
    'wholesale_returns_amount' => 0,
    'avg_price_per_unit' => 0
];

if ($raw_product_sales) {
    $summary_totals['total_products'] = count($raw_product_sales);
    
    foreach ($raw_product_sales as $raw_product) {
        // Calculate derived fields
        $net_qty = $raw_product['total_qty'] - $raw_product['total_returns_qty'];
        $net_amount = $raw_product['total_amount'] - $raw_product['total_returns_amount'];
        $net_profit = $raw_product['gross_profit'] - $raw_product['profit_lost_returns'];
        $profit_margin = $net_amount > 0 ? ($net_profit / $net_amount) * 100 : 0;
        $avg_price_per_unit = $net_qty > 0 ? $net_amount / $net_qty : 0;
        
        // Build product array with all fields
        $product = array_merge($raw_product, [
            'net_qty' => $net_qty,
            'net_amount' => $net_amount,
            'net_profit' => $net_profit,
            'profit_margin' => $profit_margin,
            'avg_price_per_unit' => $avg_price_per_unit
        ]);
        
        $product_sales[] = $product;
        
        // Update totals
        $summary_totals['total_qty'] += $raw_product['total_qty'];
        $summary_totals['total_amount'] += $raw_product['total_amount'];
        $summary_totals['total_returns_qty'] += $raw_product['total_returns_qty'];
        $summary_totals['total_returns_amount'] += $raw_product['total_returns_amount'];
        $summary_totals['net_qty'] += $net_qty;
        $summary_totals['net_amount'] += $net_amount;
        $summary_totals['gross_profit'] += $raw_product['gross_profit'];
        $summary_totals['profit_lost_returns'] += $raw_product['profit_lost_returns'];
        $summary_totals['net_profit'] += $net_profit;
        $summary_totals['retail_qty'] += $raw_product['retail_qty'];
        $summary_totals['retail_amount'] += $raw_product['retail_amount'];
        $summary_totals['retail_returns_qty'] += $raw_product['retail_returns_qty'];
        $summary_totals['retail_returns_amount'] += $raw_product['retail_returns_amount'];
        $summary_totals['wholesale_qty'] += $raw_product['wholesale_qty'];
        $summary_totals['wholesale_amount'] += $raw_product['wholesale_amount'];
        $summary_totals['wholesale_returns_qty'] += $raw_product['wholesale_returns_qty'];
        $summary_totals['wholesale_returns_amount'] += $raw_product['wholesale_returns_amount'];
    }
    
    // Calculate average price per unit
    $summary_totals['avg_price_per_unit'] = $summary_totals['net_qty'] > 0 
        ? $summary_totals['net_amount'] / $summary_totals['net_qty'] 
        : 0;
    
    // Sort products for different views
    usort($product_sales, function($a, $b) {
        return ($b['net_amount'] ?? 0) <=> ($a['net_amount'] ?? 0);
    });
    
    // Get top 5 and bottom 5 selling products
    $top_selling = array_slice($product_sales, 0, 5);
    $low_selling = array_slice($product_sales, -5, 5);
    
    // Sort by profit margin for high/low profit analysis
    usort($product_sales, function($a, $b) {
        return ($b['profit_margin'] ?? 0) <=> ($a['profit_margin'] ?? 0);
    });
    
    $high_profit = array_slice($product_sales, 0, 5);
    $low_profit = array_slice($product_sales, -5, 5);
} else {
    $top_selling = [];
    $low_selling = [];
    $high_profit = [];
    $low_profit = [];
}

// ==================== CSV EXPORT ====================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "product_wise_sales_report_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');

    // Header rows
    fputcsv($output, ['Product Wise Sales Report', 'Period: ' . $display_date_from . ' to ' . $display_date_to]);
    fputcsv($output, ['Business: ' . ($_SESSION['current_business_name'] ?? 'Business')]);
    fputcsv($output, ['Branch: ' . $shop_name]);
    if ($category_filter !== 'all') {
        $cat_name = array_column($categories, 'category_name', 'id')[$category_filter] ?? 'All';
        fputcsv($output, ['Category: ' . $cat_name]);
    }
    fputcsv($output, ['Sale Type: ' . ucfirst($sale_type_filter)]);
    fputcsv($output, ['Generated on: ' . date('d M Y, h:i A')]);
    fputcsv($output, ['']);

    // Table headers
    fputcsv($output, [
        'Product Name',
        'Product Code',
        'Category',
        'HSN Code',
        'Unit',
        'Retail Price',
        'Wholesale Price',
        'Stock Price',
        'Current Stock',
        'Retail Qty',
        'Retail Amount',
        'Retail Returns Qty',
        'Retail Returns Amount',
        'Wholesale Qty',
        'Wholesale Amount',
        'Wholesale Returns Qty',
        'Wholesale Returns Amount',
        'Total Qty',
        'Total Amount',
        'Total Returns Qty',
        'Total Returns Amount',
        'Net Qty',
        'Net Amount',
        'Gross Profit',
        'Profit Lost (Returns)',
        'Net Profit',
        'Profit Margin %',
        'Avg Price/Unit',
        'Invoices',
        'Customers'
    ]);

    // Product data
    foreach ($product_sales as $product) {
        fputcsv($output, [
            $product['product_name'] ?? '',
            $product['product_code'] ?? '',
            $product['category_name'] ?? '',
            $product['hsn_code'] ?? '',
            $product['unit_of_measure'] ?? 'pcs',
            formatCurrency($product['retail_price'] ?? 0),
            formatCurrency($product['wholesale_price'] ?? 0),
            formatCurrency($product['stock_price'] ?? 0),
            $product['current_stock'] ?? 0,
            $product['retail_qty'] ?? 0,
            formatCurrency($product['retail_amount'] ?? 0),
            $product['retail_returns_qty'] ?? 0,
            formatCurrency($product['retail_returns_amount'] ?? 0),
            $product['wholesale_qty'] ?? 0,
            formatCurrency($product['wholesale_amount'] ?? 0),
            $product['wholesale_returns_qty'] ?? 0,
            formatCurrency($product['wholesale_returns_amount'] ?? 0),
            $product['total_qty'] ?? 0,
            formatCurrency($product['total_amount'] ?? 0),
            $product['total_returns_qty'] ?? 0,
            formatCurrency($product['total_returns_amount'] ?? 0),
            $product['net_qty'] ?? 0,
            formatCurrency($product['net_amount'] ?? 0),
            formatCurrency($product['gross_profit'] ?? 0),
            formatCurrency($product['profit_lost_returns'] ?? 0),
            formatCurrency($product['net_profit'] ?? 0),
            number_format($product['profit_margin'] ?? 0, 2) . '%',
            formatCurrency($product['avg_price_per_unit'] ?? 0),
            $product['invoice_count'] ?? 0,
            $product['customer_count'] ?? 0
        ]);
    }

    fclose($output);
    exit();
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Product Wise Sales Report"; ?>
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
                                    Product Wise Sales Report
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    Report from <?= $display_date_from ?> to <?= $display_date_to ?>
                                    <?php if ($selected_shop_id === 'all'): ?>
                                        <span class="badge bg-info">All Branches</span>
                                    <?php else: ?>
                                        <span class="badge bg-info"><?= htmlspecialchars($shop_name) ?></span>
                                    <?php endif; ?>
                                    <?php if ($category_filter !== 'all'): ?>
                                        <?php $cat_name = array_column($categories, 'category_name', 'id')[$category_filter] ?? ''; ?>
                                        <span class="badge bg-primary">Category: <?= htmlspecialchars($cat_name) ?></span>
                                    <?php endif; ?>
                                    <?php if ($sale_type_filter !== 'all'): ?>
                                        <span class="badge bg-<?= $sale_type_filter === 'retail' ? 'warning' : 'success' ?>">
                                            <?= ucfirst($sale_type_filter) ?> Only
                                        </span>
                                    <?php endif; ?>
                                </p>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <small class="text-success">
                                        <i class="bx bx-check-circle me-1"></i>
                                        Showing <?= count($product_sales) ?> products with sales
                                    </small>
                                    <?php if ($summary_totals['total_returns_qty'] > 0): ?>
                                    <small class="text-warning">
                                        <i class="bx bx-undo me-1"></i>
                                        Total Returns: <?= $summary_totals['total_returns_qty'] ?> units
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="?export=csv&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&shop_id=<?= $selected_shop_id ?>&category_id=<?= $category_filter ?>&sale_type=<?= $sale_type_filter ?>&top_n=<?= $top_n ?>&min_sales_qty=<?= $min_sales_qty ?>&order_by=<?= $order_by ?>&order_dir=<?= $order_dir ?>"
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
                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control"
                                    value="<?= $date_from ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control"
                                    value="<?= $date_to ?>" max="<?= date('Y-m-d') ?>">
                            </div>

                            <?php if ($user_role === 'admin'): ?>
                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">Branch</label>
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

                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="category_id">
                                    <option value="all" <?= $category_filter === 'all' ? 'selected' : '' ?>>All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['id'] ?>"
                                            <?= $category_filter == $category['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">Sale Type</label>
                                <select class="form-select" name="sale_type">
                                    <option value="all" <?= $sale_type_filter === 'all' ? 'selected' : '' ?>>All Sales</option>
                                    <option value="retail" <?= $sale_type_filter === 'retail' ? 'selected' : '' ?>>Retail Only</option>
                                    <option value="wholesale" <?= $sale_type_filter === 'wholesale' ? 'selected' : '' ?>>Wholesale Only</option>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">Show Top</label>
                                <select class="form-select" name="top_n">
                                    <option value="10" <?= $top_n == 10 ? 'selected' : '' ?>>Top 10</option>
                                    <option value="20" <?= $top_n == 20 ? 'selected' : '' ?>>Top 20</option>
                                    <option value="50" <?= $top_n == 50 ? 'selected' : '' ?>>Top 50</option>
                                    <option value="100" <?= $top_n == 100 ? 'selected' : '' ?>>Top 100</option>
                                    <option value="0" <?= $top_n == 0 ? 'selected' : '' ?>>All Products</option>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">Min Sales Qty</label>
                                <input type="number" name="min_sales_qty" class="form-control" min="0"
                                    value="<?= $min_sales_qty ?>" placeholder="Minimum quantity">
                            </div>

                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">Sort By</label>
                                <select class="form-select" name="order_by">
                                    <option value="total_amount" <?= $order_by === 'total_amount' ? 'selected' : '' ?>>Total Sales Value</option>
                                    <option value="total_qty" <?= $order_by === 'total_qty' ? 'selected' : '' ?>>Total Quantity</option>
                                    <option value="retail_qty" <?= $order_by === 'retail_qty' ? 'selected' : '' ?>>Retail Quantity</option>
                                    <option value="wholesale_qty" <?= $order_by === 'wholesale_qty' ? 'selected' : '' ?>>Wholesale Quantity</option>
                                    <option value="product_name" <?= $order_by === 'product_name' ? 'selected' : '' ?>>Product Name</option>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">Sort Order</label>
                                <select class="form-select" name="order_dir">
                                    <option value="desc" <?= $order_dir === 'DESC' ? 'selected' : '' ?>>Descending (High to Low)</option>
                                    <option value="asc" <?= $order_dir === 'ASC' ? 'selected' : '' ?>>Ascending (Low to High)</option>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-6">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary flex-grow-1">
                                        <i class="bx bx-filter me-1"></i> Apply Filters
                                    </button>
                                    <a href="product_wise_sale_report.php" class="btn btn-outline-secondary">
                                        <i class="bx bx-reset me-1"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Overall Summary -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Products Sold</h6>
                                        <h3 class="mb-0 text-primary"><?= $summary_totals['total_products'] ?></h3>
                                        <small class="text-muted">
                                            Net Qty: <?= number_format($summary_totals['net_qty']) ?>
                                            <?php if ($summary_totals['total_returns_qty'] > 0): ?>
                                            <br>
                                            <span class="text-danger">
                                                <i class="bx bx-undo me-1"></i>
                                                Returns: <?= number_format($summary_totals['total_returns_qty']) ?>
                                            </span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-package text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Net Sales Value</h6>
                                        <h3 class="mb-0 text-success"><?= formatCurrency($summary_totals['net_amount']) ?></h3>
                                        <small class="text-muted">
                                            Avg Price: <?= formatCurrency($summary_totals['avg_price_per_unit']) ?>/unit
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-success"></i>
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
                                        <h6 class="text-muted mb-1">Sales Distribution</h6>
                                        <h5 class="mb-1 text-info">Retail: <?= formatCurrency($summary_totals['retail_amount']) ?></h5>
                                        <h5 class="mb-0 text-success">Wholesale: <?= formatCurrency($summary_totals['wholesale_amount']) ?></h5>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-pie-chart text-info"></i>
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
                                        <h6 class="text-muted mb-1">Net Profit</h6>
                                        <h3 class="mb-0 text-warning"><?= formatCurrency($summary_totals['net_profit']) ?></h3>
                                        <small class="text-muted">
                                            Margin: <?= $summary_totals['net_amount'] > 0 ? number_format(($summary_totals['net_profit'] / $summary_totals['net_amount']) * 100, 2) : '0.00' ?>%
                                            <?php if ($summary_totals['profit_lost_returns'] > 0): ?>
                                            <br>
                                            <span class="text-danger">
                                                Lost: <?= formatCurrency($summary_totals['profit_lost_returns']) ?>
                                            </span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-trending-up text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top/Bottom Analysis Cards -->
                <?php if (count($product_sales) >= 5): ?>
                <div class="row mb-4">
                    <!-- Top Selling Products -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-success bg-opacity-10">
                                <h5 class="mb-0 text-success">
                                    <i class="bx bx-trophy me-2"></i>
                                    Top 5 Selling Products (By Value)
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php foreach ($top_selling as $index => $product): 
                                        $net_qty = $product['net_qty'] ?? 0;
                                        $net_amount = $product['net_amount'] ?? 0;
                                        $avg_price = $product['avg_price_per_unit'] ?? 0;
                                        $profit_margin = $product['profit_margin'] ?? 0;
                                    ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <span class="badge bg-success me-2">#<?= $index + 1 ?></span>
                                                <div>
                                                    <h6 class="mb-0"><?= htmlspecialchars($product['product_name'] ?? '') ?></h6>
                                                    <small class="text-muted">
                                                        <?= $net_qty ?> units | 
                                                        <?= formatCurrency($net_amount) ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-success bg-opacity-10 text-success">
                                                    <?= formatCurrency($avg_price) ?>/unit
                                                </span>
                                                <br>
                                                <small class="text-muted">
                                                    <?= number_format($profit_margin, 1) ?>% margin
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Low Selling Products -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger bg-opacity-10">
                                <h5 class="mb-0 text-danger">
                                    <i class="bx bx-down-arrow-alt me-2"></i>
                                    Bottom 5 Selling Products (By Value)
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php foreach (array_reverse($low_selling) as $index => $product): 
                                        $net_qty = $product['net_qty'] ?? 0;
                                        $net_amount = $product['net_amount'] ?? 0;
                                        $avg_price = $product['avg_price_per_unit'] ?? 0;
                                        $profit_margin = $product['profit_margin'] ?? 0;
                                    ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <span class="badge bg-danger me-2">#<?= count($product_sales) - $index ?></span>
                                                <div>
                                                    <h6 class="mb-0"><?= htmlspecialchars($product['product_name'] ?? '') ?></h6>
                                                    <small class="text-muted">
                                                        <?= $net_qty ?> units | 
                                                        <?= formatCurrency($net_amount) ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-danger bg-opacity-10 text-danger">
                                                    <?= formatCurrency($avg_price) ?>/unit
                                                </span>
                                                <br>
                                                <small class="text-muted">
                                                    <?= number_format($profit_margin, 1) ?>% margin
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profit Analysis -->
                <div class="row mb-4">
                    <!-- High Profit Margin Products -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-warning bg-opacity-10">
                                <h5 class="mb-0 text-warning">
                                    <i class="bx bx-trending-up me-2"></i>
                                    Top 5 Profit Margin Products
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php foreach ($high_profit as $index => $product): 
                                        $profit_margin = $product['profit_margin'] ?? 0;
                                        $net_profit = $product['net_profit'] ?? 0;
                                        $net_qty = $product['net_qty'] ?? 0;
                                    ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <span class="badge bg-warning me-2">#<?= $index + 1 ?></span>
                                                <div>
                                                    <h6 class="mb-0"><?= htmlspecialchars($product['product_name'] ?? '') ?></h6>
                                                    <small class="text-muted">
                                                        Profit: <?= formatCurrency($net_profit) ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2">
                                                    <?= number_format($profit_margin, 1) ?>%
                                                </span>
                                                <br>
                                                <small class="text-muted">
                                                    <?= $net_qty ?> units sold
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Low Profit Margin Products -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-info bg-opacity-10">
                                <h5 class="mb-0 text-info">
                                    <i class="bx bx-trending-down me-2"></i>
                                    Bottom 5 Profit Margin Products
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php foreach (array_reverse($low_profit) as $index => $product): 
                                        $profit_margin = $product['profit_margin'] ?? 0;
                                        $net_profit = $product['net_profit'] ?? 0;
                                        $net_qty = $product['net_qty'] ?? 0;
                                    ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <span class="badge bg-info me-2">#<?= count($product_sales) - $index ?></span>
                                                <div>
                                                    <h6 class="mb-0"><?= htmlspecialchars($product['product_name'] ?? '') ?></h6>
                                                    <small class="text-muted">
                                                        Profit: <?= formatCurrency($net_profit) ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-2">
                                                    <?= number_format($profit_margin, 1) ?>%
                                                </span>
                                                <br>
                                                <small class="text-muted">
                                                    <?= $net_qty ?> units sold
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Main Product Sales Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bx bx-table me-2"></i>
                                    Product Wise Sales Details (Net of Returns)
                                </h5>
                                <div>
                                    <span class="badge bg-primary"><?= count($product_sales) ?> products</span>
                                    <span class="badge bg-success ms-2">
                                        Total Net Sales: <?= formatCurrency($summary_totals['net_amount']) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($product_sales)): ?>
                                    <div class="text-center py-5">
                                        <i class="bx bx-package display-4 text-muted"></i>
                                        <h5 class="mt-3">No product sales data found</h5>
                                        <p class="text-muted">No sales recorded for the selected filters</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Product Details</th>
                                                    <th class="text-center">Price Details</th>
                                                    <th class="text-center">Retail Sales</th>
                                                    <th class="text-center">Wholesale Sales</th>
                                                    <th class="text-center">Total Sales</th>
                                                    <th class="text-center">Returns</th>
                                                    <th class="text-center">Net Sales</th>
                                                    <th class="text-center">Profit Analysis</th>
                                                    <th class="text-center">Stock</th>
                                                    <th class="text-center">Metrics</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $counter = 1;
                                                foreach($product_sales as $product):
                                                    $has_returns = ($product['total_returns_qty'] ?? 0) > 0;
                                                    $profit_margin = $product['profit_margin'] ?? 0;
                                                    $profit_margin_class = $profit_margin >= 30 ? 'text-success' : 
                                                                          ($profit_margin >= 20 ? 'text-warning' : 'text-danger');
                                                ?>
                                                <tr>
                                                    <td><?= $counter++ ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($product['product_name'] ?? '') ?></strong>
                                                        <div class="small text-muted">
                                                            <?php if (!empty($product['product_code'])): ?>
                                                                <span class="me-2">Code: <?= htmlspecialchars($product['product_code']) ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($product['hsn_code'])): ?>
                                                                <span class="me-2">HSN: <?= htmlspecialchars($product['hsn_code']) ?></span>
                                                            <?php endif; ?>
                                                            <br>
                                                            <span>Category: <?= htmlspecialchars($product['category_name'] ?? 'N/A') ?></span>
                                                            <span class="ms-2">Unit: <?= htmlspecialchars($product['unit_of_measure'] ?? 'pcs') ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="small">
                                                            <div>Retail: <?= formatCurrency($product['retail_price'] ?? 0) ?></div>
                                                            <div>Wholesale: <?= formatCurrency($product['wholesale_price'] ?? 0) ?></div>
                                                            <div class="text-muted">Cost: <?= formatCurrency($product['stock_price'] ?? 0) ?></div>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="fw-bold"><?= $product['retail_qty'] ?? 0 ?> units</div>
                                                        <div class="small text-primary"><?= formatCurrency($product['retail_amount'] ?? 0) ?></div>
                                                        <?php if (($product['retail_returns_qty'] ?? 0) > 0): ?>
                                                        <small class="text-danger">
                                                            <i class="bx bx-undo"></i> -<?= $product['retail_returns_qty'] ?> units
                                                        </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="fw-bold"><?= $product['wholesale_qty'] ?? 0 ?> units</div>
                                                        <div class="small text-success"><?= formatCurrency($product['wholesale_amount'] ?? 0) ?></div>
                                                        <?php if (($product['wholesale_returns_qty'] ?? 0) > 0): ?>
                                                        <small class="text-danger">
                                                            <i class="bx bx-undo"></i> -<?= $product['wholesale_returns_qty'] ?> units
                                                        </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="fw-bold"><?= $product['total_qty'] ?? 0 ?> units</div>
                                                        <div class="small"><?= formatCurrency($product['total_amount'] ?? 0) ?></div>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($has_returns): ?>
                                                        <div class="fw-bold text-danger">-<?= $product['total_returns_qty'] ?? 0 ?> units</div>
                                                        <div class="small text-danger"><?= formatCurrency($product['total_returns_amount'] ?? 0) ?></div>
                                                        <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="fw-bold <?= $has_returns ? 'text-success' : 'text-primary' ?>">
                                                            <?= $product['net_qty'] ?? 0 ?> units
                                                        </div>
                                                        <div class="<?= $has_returns ? 'text-success' : 'text-primary' ?>">
                                                            <?= formatCurrency($product['net_amount'] ?? 0) ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            Avg: <?= formatCurrency($product['avg_price_per_unit'] ?? 0) ?>/unit
                                                        </small>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="<?= $profit_margin_class ?> fw-bold">
                                                            <?= number_format($profit_margin, 1) ?>%
                                                        </div>
                                                        <div class="small text-success">
                                                            Profit: <?= formatCurrency($product['net_profit'] ?? 0) ?>
                                                        </div>
                                                        <?php if (($product['profit_lost_returns'] ?? 0) > 0): ?>
                                                        <small class="text-danger">
                                                            <i class="bx bx-undo"></i> Lost: <?= formatCurrency($product['profit_lost_returns'] ?? 0) ?>
                                                        </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge <?= ($product['current_stock'] ?? 0) > 10 ? 'bg-success' : (($product['current_stock'] ?? 0) > 0 ? 'bg-warning' : 'bg-danger') ?>">
                                                            <?= $product['current_stock'] ?? 0 ?> in stock
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <small class="text-muted">
                                                            Invoices: <?= $product['invoice_count'] ?? 0 ?><br>
                                                            Customers: <?= $product['customer_count'] ?? 0 ?>
                                                        </small>
                                                    </td>
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
.list-group-item:hover { background-color: rgba(0,0,0,0.02); }
</style>
</body>
</html>