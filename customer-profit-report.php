<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['current_business_id'] ?? $_SESSION['business_id'] ?? 1;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

if (!$business_id || !$current_shop_id) {
    header('Location: select_shop.php');
    exit();
}

$_SESSION['business_id'] = $business_id;

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$search = trim($_GET['search'] ?? '');
$customer_type = trim($_GET['customer_type'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$profit_mode = trim($_GET['profit_mode'] ?? 'final'); // final | base

$valid_profit_modes = ['final', 'base'];
if (!in_array($profit_mode, $valid_profit_modes, true)) {
    $profit_mode = 'final';
}

$where = ["i.business_id = ?"];
$params = [$business_id];

if ($current_shop_id) {
    $where[] = "i.shop_id = ?";
    $params[] = $current_shop_id;
}

if ($search !== '') {
    $where[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR i.invoice_number LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
}

if ($customer_type !== '' && in_array($customer_type, ['retail', 'wholesale'], true)) {
    $where[] = "c.customer_type = ?";
    $params[] = $customer_type;
}

if ($date_from !== '') {
    $where[] = "DATE(i.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to !== '') {
    $where[] = "DATE(i.created_at) <= ?";
    $params[] = $date_to;
}

$where_sql = implode(' AND ', $where);

/*
 Profit logic:
 base profit = ii.profit
 final profit = ii.profit + ii.new_batch_product_profit - ii.new_batch_product_loss

 Net quantity factor adjusts profit for returns:
 if qty=10 and return_qty=2 => keep 8/10 of profit
*/
$profit_expression = $profit_mode === 'base'
    ? "COALESCE(ii.profit, 0)"
    : "(COALESCE(ii.profit, 0) + COALESCE(ii.new_batch_product_profit, 0) - COALESCE(ii.new_batch_product_loss, 0))";

$net_profit_expression = "
    (
        {$profit_expression} *
        CASE
            WHEN COALESCE(ii.quantity, 0) > 0
                THEN GREATEST(ii.quantity - COALESCE(ii.return_qty, 0), 0) / ii.quantity
            ELSE 1
        END
    )
";

$report_sql = "
    SELECT
        c.id AS customer_id,
        c.name AS customer_name,
        c.phone,
        c.email,
        c.customer_type,
        COUNT(DISTINCT i.id) AS total_invoices,
        COALESCE(SUM(i.total), 0) AS gross_sales,
        COALESCE(SUM(i.paid_amount), 0) AS total_paid,
        COALESCE(SUM(i.pending_amount), 0) AS total_due,
        COALESCE(SUM(ii.quantity), 0) AS total_qty,
        COALESCE(SUM(ii.return_qty), 0) AS total_return_qty,
        COALESCE(SUM(ii.total_price), 0) AS line_sales_total,
        COALESCE(SUM({$net_profit_expression}), 0) AS total_profit,
        MAX(i.created_at) AS last_invoice_date
    FROM invoices i
    INNER JOIN customers c
        ON c.id = i.customer_id
       AND c.business_id = i.business_id
    LEFT JOIN invoice_items ii
        ON ii.invoice_id = i.id
    WHERE {$where_sql}
    GROUP BY c.id, c.name, c.phone, c.email, c.customer_type
    ORDER BY total_profit DESC, c.name ASC
";

$stmt = $pdo->prepare($report_sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'customers' => 0,
    'invoices' => 0,
    'sales' => 0.00,
    'profit' => 0.00,
    'avg_margin' => 0.00,
    'paid' => 0.00,
    'due' => 0.00,
    'qty' => 0,
    'returned_qty' => 0
];

foreach ($rows as $row) {
    $summary['customers']++;
    $summary['invoices'] += (int)($row['total_invoices'] ?? 0);
    $summary['sales'] += (float)($row['gross_sales'] ?? 0);
    $summary['profit'] += (float)($row['total_profit'] ?? 0);
    $summary['paid'] += (float)($row['total_paid'] ?? 0);
    $summary['due'] += (float)($row['total_due'] ?? 0);
    $summary['qty'] += (float)($row['total_qty'] ?? 0);
    $summary['returned_qty'] += (float)($row['total_return_qty'] ?? 0);
}

$summary['avg_margin'] = $summary['sales'] > 0
    ? ($summary['profit'] / $summary['sales']) * 100
    : 0;

$top_customer = null;
if (!empty($rows)) {
    $top_customer = $rows[0];
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<head>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .empty-state i { font-size: 4rem; opacity: 0.5; }
        .avatar-sm { width: 48px; height: 48px; }
        .table th { font-weight: 600; background-color: #f8f9fa; white-space: nowrap; }
        .card-hover { transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .card-hover:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important; }
        .border-start { border-left-width: 4px !important; }
        .summary-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
            background: rgba(13,110,253,.08);
            color: #0d6efd;
        }
        .profit-positive { color: #198754; }
        .profit-negative { color: #dc3545; }
        .profit-neutral { color: #6c757d; }
        .sticky-actions {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #fff;
        }
        @media (max-width: 768px) {
            .btn-group { flex-wrap: wrap; gap: 4px; }
            .btn-group .btn { flex: 1; min-width: 40px; }
            .page-title-box { flex-direction: column; align-items: flex-start !important; gap: 12px; }
            .mobile-scroll { overflow-x: auto; }
        }
    </style>
</head>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>

    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-line-chart me-2"></i> Customer Wise Profit Report
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i><?= e($_SESSION['current_business_name'] ?? $_SESSION['business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="mb-0 text-muted">
                                    Customer-level sales, payment, and profit analysis
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" onclick="window.print()">
                                    <i class="bx bx-printer me-1"></i> Print
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Customers</h6>
                                        <h3 class="mb-0 text-primary"><?= number_format($summary['customers']) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-user text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted"><?= number_format($summary['invoices']) ?> invoices</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Sales</h6>
                                        <h3 class="mb-0 text-info">₹<?= number_format($summary['sales'], 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-info"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted">Paid: ₹<?= number_format($summary['paid'], 2) ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Profit</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($summary['profit'], 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-trending-up text-success"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted">Margin: <?= number_format($summary['avg_margin'], 2) ?>%</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Outstanding</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($summary['due'], 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-time text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted">Returned Qty: <?= number_format($summary['returned_qty'], 0) ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($top_customer): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm border-0">
                            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                                <div>
                                    <h5 class="mb-1">
                                        <i class="bx bx-crown me-2 text-warning"></i>Top Profit Customer
                                    </h5>
                                    <div class="text-muted"><?= e($top_customer['customer_name']) ?></div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="summary-chip"><i class="bx bx-file"></i> <?= (int)$top_customer['total_invoices'] ?> invoices</span>
                                    <span class="summary-chip"><i class="bx bx-rupee"></i> Sales ₹<?= number_format((float)$top_customer['gross_sales'], 2) ?></span>
                                    <span class="summary-chip" style="background:rgba(25,135,84,.08);color:#198754;">
                                        <i class="bx bx-line-chart"></i> Profit ₹<?= number_format((float)$top_customer['total_profit'], 2) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="bx bx-filter me-1"></i> Filter Report
                        </h5>
                        <form method="GET">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-3">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bx bx-search"></i></span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Customer, phone, email, invoice..."
                                               value="<?= e($search) ?>">
                                    </div>
                                </div>

                                <div class="col-lg-2">
                                    <label class="form-label">Customer Type</label>
                                    <select name="customer_type" class="form-select">
                                        <option value="">All</option>
                                        <option value="retail" <?= $customer_type === 'retail' ? 'selected' : '' ?>>Retail</option>
                                        <option value="wholesale" <?= $customer_type === 'wholesale' ? 'selected' : '' ?>>Wholesale</option>
                                    </select>
                                </div>

                                <div class="col-lg-2">
                                    <label class="form-label">Date From</label>
                                    <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
                                </div>

                                <div class="col-lg-2">
                                    <label class="form-label">Date To</label>
                                    <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
                                </div>

                                <div class="col-lg-2">
                                    <label class="form-label">Profit Mode</label>
                                    <select name="profit_mode" class="form-select">
                                        <option value="final" <?= $profit_mode === 'final' ? 'selected' : '' ?>>Final Profit</option>
                                        <option value="base" <?= $profit_mode === 'base' ? 'selected' : '' ?>>Base Profit</option>
                                    </select>
                                </div>

                                <div class="col-lg-1">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bx bx-filter"></i>
                                    </button>
                                </div>
                            </div>

                            <?php if ($search || $customer_type || $date_from || $date_to || $profit_mode !== 'final'): ?>
                                <div class="mt-3">
                                    <a href="customer-profit-report.php" class="btn btn-outline-secondary btn-sm">
                                        <i class="bx bx-reset me-1"></i> Clear Filters
                                    </a>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="sticky-actions d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <h5 class="card-title mb-0">
                                <i class="bx bx-table me-1"></i> Customer Profit Summary
                            </h5>
                            <small class="text-muted">
                                Profit mode:
                                <strong><?= $profit_mode === 'final' ? 'Final Profit' : 'Base Profit' ?></strong>
                            </small>
                        </div>

                        <div class="table-responsive mobile-scroll">
                            <table class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Customer</th>
                                    <th class="text-center">Type</th>
                                    <th class="text-center">Invoices</th>
                                    <th class="text-end">Sales</th>
                                    <th class="text-end">Paid</th>
                                    <th class="text-end">Due</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Returned</th>
                                    <th class="text-end">Profit</th>
                                    <th class="text-end">Margin %</th>
                                    <th class="text-center">Last Invoice</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr>
                                        <td colspan="12" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bx bx-line-chart-down text-muted"></i>
                                                <h5 class="mt-3">No profit data found</h5>
                                                <p class="text-muted mb-0">Try changing the filters or date range.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $index => $row):
                                        $sales = (float)($row['gross_sales'] ?? 0);
                                        $profit = (float)($row['total_profit'] ?? 0);
                                        $margin = $sales > 0 ? ($profit / $sales) * 100 : 0;
                                        $profit_class = $profit > 0 ? 'profit-positive' : ($profit < 0 ? 'profit-negative' : 'profit-neutral');
                                    ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width:48px;height:48px;">
                                                        <i class="bx bx-user fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block"><?= e($row['customer_name']) ?></strong>
                                                    <?php if (!empty($row['phone'])): ?>
                                                        <small class="text-muted d-block"><i class="bx bx-phone me-1"></i><?= e($row['phone']) ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['email'])): ?>
                                                        <small class="text-muted d-block"><i class="bx bx-envelope me-1"></i><?= e($row['email']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $row['customer_type'] === 'wholesale' ? 'success' : 'primary' ?> bg-opacity-10 text-<?= $row['customer_type'] === 'wholesale' ? 'success' : 'primary' ?>">
                                                <?= ucfirst($row['customer_type'] ?: 'retail') ?>
                                            </span>
                                        </td>
                                        <td class="text-center"><?= (int)$row['total_invoices'] ?></td>
                                        <td class="text-end">₹<?= number_format($sales, 2) ?></td>
                                        <td class="text-end text-success">₹<?= number_format((float)$row['total_paid'], 2) ?></td>
                                        <td class="text-end text-warning">₹<?= number_format((float)$row['total_due'], 2) ?></td>
                                        <td class="text-end"><?= number_format((float)$row['total_qty'], 0) ?></td>
                                        <td class="text-end"><?= number_format((float)$row['total_return_qty'], 0) ?></td>
                                        <td class="text-end fw-bold <?= $profit_class ?>">₹<?= number_format($profit, 2) ?></td>
                                        <td class="text-end <?= $profit_class ?>"><?= number_format($margin, 2) ?>%</td>
                                        <td class="text-center">
                                            <?= !empty($row['last_invoice_date']) ? date('d-m-Y', strtotime($row['last_invoice_date'])) : '-' ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Base Profit = invoice item profit only. Final Profit = profit + new batch product profit - new batch product loss, adjusted for returned quantity. 
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>
</body>
</html>