<?php
// sales-summary-report-date-invoices.php
// Date based invoice-wise sales report with Invoice ID rows, item count and total quantity.

date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// ==================== LOGIN & ROLE CHECK ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$business_id = (int)($_SESSION['business_id'] ?? 1);
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

$can_view_reports = in_array($user_role, ['admin', 'shop_manager', 'cashier'], true);
if (!$can_view_reports) {
    $_SESSION['error'] = "Access denied. You don't have permission to view reports.";
    header('Location: dashboard.php');
    exit();
}

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function validDate($date)
{
    $d = DateTime::createFromFormat('Y-m-d', (string)$date);
    return $d && $d->format('Y-m-d') === $date;
}

function money($amount)
{
    return '₹' . number_format((float)$amount, 2);
}

// ==================== FILTERS ====================
$selected_shop_id = $_GET['shop_id'] ?? 'all';
if ($user_role !== 'admin' && $current_shop_id) {
    $selected_shop_id = $current_shop_id;
}
if ($selected_shop_id !== 'all') {
    $selected_shop_id = (int)$selected_shop_id;
}

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

if (!validDate($date_from)) {
    $date_from = date('Y-m-d');
}
if (!validDate($date_to)) {
    $date_to = date('Y-m-d');
}
if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}

$date_from_time = $date_from . ' 00:00:00';
$date_to_time = $date_to . ' 23:59:59';
$display_date_from = date('d M Y', strtotime($date_from));
$display_date_to = date('d M Y', strtotime($date_to));

// ==================== SHOPS ====================
$all_shops = [];
if ($user_role === 'admin') {
    $shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name");
    $shop_stmt->execute([$business_id]);
    $all_shops = $shop_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$shop_name = 'All Branches';
if ($selected_shop_id !== 'all') {
    $stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ? LIMIT 1");
    $stmt->execute([$selected_shop_id, $business_id]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    $shop_name = $shop['shop_name'] ?? 'Shop';
}

$shop_filter = $selected_shop_id !== 'all' ? " AND i.shop_id = ?" : "";
$base_params = [$date_from_time, $date_to_time, $business_id];
if ($selected_shop_id !== 'all') {
    $base_params[] = $selected_shop_id;
}

// ==================== INVOICE-WISE QUERY ====================
$invoice_sql = "
    SELECT
        i.id AS invoice_id,
        i.invoice_number,
        i.created_at,
        i.customer_type,
        i.subtotal,
        i.discount,
        i.overall_discount,
        i.total,
        i.paid_amount,
        i.pending_amount,
        i.payment_status,
        i.payment_method,
        i.cash_amount,
        i.upi_amount,
        i.bank_amount,
        i.cheque_amount,
        i.credit_amount,
        c.name AS customer_name,
        c.phone AS customer_phone,
        s.shop_name,
        u.full_name AS seller_name,
        ii.id AS item_id,
        COALESCE(NULLIF(ii.product_name_snapshot, ''), p.product_name, 'Item') AS item_name,
        ii.sale_type,
        ii.quantity,
        ii.return_qty,
        ii.unit,
        ii.unit_price,
        ii.total_price,
        ii.profit
    FROM invoices i
    LEFT JOIN customers c ON c.id = i.customer_id
    LEFT JOIN shops s ON s.id = i.shop_id
    LEFT JOIN users u ON u.id = i.seller_id
    LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
    LEFT JOIN products p ON p.id = ii.product_id
    WHERE i.created_at BETWEEN ? AND ?
      AND i.business_id = ?
      $shop_filter
    ORDER BY DATE(i.created_at) ASC, i.created_at ASC, i.id ASC, ii.id ASC
";

$stmt = $pdo->prepare($invoice_sql);
$stmt->execute($base_params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$invoice_rows = [];
foreach ($rows as $row) {
    $invoice_id = (int)$row['invoice_id'];

    if (!isset($invoice_rows[$invoice_id])) {
        $invoice_rows[$invoice_id] = [
            'invoice_id' => $invoice_id,
            'invoice_number' => $row['invoice_number'],
            'created_at' => $row['created_at'],
            'invoice_date' => date('Y-m-d', strtotime($row['created_at'])),
            'customer_type' => $row['customer_type'] ?? '',
            'customer_name' => $row['customer_name'] ?? 'Walk-in Customer',
            'customer_phone' => $row['customer_phone'] ?? '',
            'shop_name' => $row['shop_name'] ?? 'N/A',
            'seller_name' => $row['seller_name'] ?? 'N/A',
            'subtotal' => (float)($row['subtotal'] ?? 0),
            'discount' => (float)($row['discount'] ?? 0) + (float)($row['overall_discount'] ?? 0),
            'total' => (float)($row['total'] ?? 0),
            'paid_amount' => (float)($row['paid_amount'] ?? 0),
            'pending_amount' => (float)($row['pending_amount'] ?? 0),
            'payment_status' => $row['payment_status'] ?? '',
            'payment_method' => $row['payment_method'] ?? '',
            'cash_amount' => (float)($row['cash_amount'] ?? 0),
            'upi_amount' => (float)($row['upi_amount'] ?? 0),
            'bank_amount' => (float)($row['bank_amount'] ?? 0),
            'cheque_amount' => (float)($row['cheque_amount'] ?? 0),
            'credit_amount' => (float)($row['credit_amount'] ?? 0),
            'items' => [],
            'items_count' => 0,
            'total_quantity' => 0.0,
            'return_amount' => 0.0,
            'gross_profit' => 0.0,
            'net_profit' => 0.0,
        ];
    }

    if (!empty($row['item_id'])) {
        $qty = (float)($row['quantity'] ?? 0);
        $return_qty = (float)($row['return_qty'] ?? 0);
        $unit_price = (float)($row['unit_price'] ?? 0);
        $total_price = (float)($row['total_price'] ?? 0);
        $profit = (float)($row['profit'] ?? 0);
        $return_amount = $return_qty * $unit_price;
        $profit_loss = $qty > 0 ? ($return_qty * $profit / $qty) : 0;

        $invoice_rows[$invoice_id]['items'][] = [
            'item_name' => $row['item_name'],
            'sale_type' => $row['sale_type'] ?? '',
            'quantity' => $qty,
            'return_qty' => $return_qty,
            'unit' => $row['unit'] ?? '',
            'unit_price' => $unit_price,
            'total_price' => $total_price,
            'profit' => $profit,
            'return_amount' => $return_amount,
        ];

        $invoice_rows[$invoice_id]['items_count']++;
        $invoice_rows[$invoice_id]['total_quantity'] += $qty;
        $invoice_rows[$invoice_id]['return_amount'] += $return_amount;
        $invoice_rows[$invoice_id]['gross_profit'] += $profit;
        $invoice_rows[$invoice_id]['net_profit'] += ($profit - $profit_loss);
    }
}

// ==================== GRAND TOTALS ====================
$grand = [
    'invoices' => count($invoice_rows),
    'customers' => 0,
    'total_quantity' => 0.0,
    'gross_sales' => 0,
    'returns' => 0,
    'net_sales' => 0,
    'discount' => 0,
    'cash' => 0,
    'upi' => 0,
    'bank' => 0,
    'cheque' => 0,
    'credit' => 0,
    'paid' => 0,
    'pending' => 0,
    'gross_profit' => 0,
    'net_profit' => 0,
];

$unique_customers = [];
foreach ($invoice_rows as $inv) {
    $net_amount = $inv['total'] - $inv['return_amount'];
    $grand['total_quantity'] += $inv['total_quantity'];
    $grand['gross_sales'] += $inv['total'];
    $grand['returns'] += $inv['return_amount'];
    $grand['net_sales'] += $net_amount;
    $grand['discount'] += $inv['discount'];
    $grand['cash'] += $inv['cash_amount'];
    $grand['upi'] += $inv['upi_amount'];
    $grand['bank'] += $inv['bank_amount'];
    $grand['cheque'] += $inv['cheque_amount'];
    $grand['credit'] += $inv['credit_amount'];
    $grand['paid'] += $inv['paid_amount'];
    $grand['pending'] += $inv['pending_amount'];
    $grand['gross_profit'] += $inv['gross_profit'];
    $grand['net_profit'] += $inv['net_profit'];
    $unique_customers[strtolower(trim($inv['customer_name'] . '|' . $inv['customer_phone']))] = true;
}
$grand['customers'] = count($unique_customers);
$total_received = $grand['cash'] + $grand['upi'] + $grand['bank'] + $grand['cheque'];
$return_percentage = $grand['gross_sales'] > 0 ? ($grand['returns'] / $grand['gross_sales']) * 100 : 0;

// ==================== CSV EXPORT - INVOICE ROWS ====================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoice_wise_sales_report_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Invoice Wise Sales Report']);
    fputcsv($output, ['Period', $display_date_from . ' to ' . $display_date_to]);
    fputcsv($output, ['Business', $_SESSION['current_business_name'] ?? 'Business']);
    fputcsv($output, ['Branch', $shop_name]);
    fputcsv($output, []);
    fputcsv($output, [
        'Date', 'Invoice ID', 'Customer', 'Phone', 'Shop', 'Items', 'Total Quantity', 'Gross Sales',
        'Returns', 'Net Sales', 'Discount', 'Paid', 'Pending', 'Payment Method', 'Seller'
    ]);

    foreach ($invoice_rows as $inv) {
        $total_qty = rtrim(rtrim(number_format((float)$inv['total_quantity'], 2), '0'), '.');

        fputcsv($output, [
            date('d-m-Y h:i A', strtotime($inv['created_at'])),
            $inv['invoice_number'],
            $inv['customer_name'],
            $inv['customer_phone'],
            $inv['shop_name'],
            (int)$inv['items_count'],
            $total_qty,
            number_format($inv['total'], 2, '.', ''),
            number_format($inv['return_amount'], 2, '.', ''),
            number_format($inv['total'] - $inv['return_amount'], 2, '.', ''),
            number_format($inv['discount'], 2, '.', ''),
            number_format($inv['paid_amount'], 2, '.', ''),
            number_format($inv['pending_amount'], 2, '.', ''),
            strtoupper($inv['payment_method']),
            $inv['seller_name'],
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['TOTAL', $grand['invoices'], 'Customers', $grand['customers'], '', '',
        rtrim(rtrim(number_format((float)$grand['total_quantity'], 2), '0'), '.'),
        number_format($grand['gross_sales'], 2, '.', ''),
        number_format($grand['returns'], 2, '.', ''),
        number_format($grand['net_sales'], 2, '.', ''),
        number_format($grand['discount'], 2, '.', ''),
        number_format($grand['paid'], 2, '.', ''),
        number_format($grand['pending'], 2, '.', ''),
    ]);

    fclose($output);
    exit();
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Invoice Wise Sales Report"; ?>
<?php include 'includes/head.php'; ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include 'includes/sidebar.php'; ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="row mb-4 no-print">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-receipt me-2"></i>
                                    Invoice Wise Sales Report
                                    <small class="text-muted ms-2"><?= e($_SESSION['current_business_name'] ?? 'Business') ?></small>
                                </h4>
                                <p class="text-muted mb-0">
                                    Report from <?= e($display_date_from) ?> to <?= e($display_date_to) ?>
                                    • Branch: <strong><?= e($shop_name) ?></strong>
                                </p>
                                <small class="text-info">
                                    <i class="bx bx-info-circle me-1"></i>
                                    Select date and click Apply Filters. Invoices will display row by row with Invoice ID, items count and total quantity.
                                </small>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary" onclick="window.print()">
                                    <i class="bx bx-printer me-1"></i> Print Report
                                </button>
                                <a href="?export=csv&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&shop_id=<?= urlencode((string)$selected_shop_id) ?>" class="btn btn-success">
                                    <i class="bx bx-download me-1"></i> Export CSV
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4 no-print">
                    <div class="card-body">
                        <h5 class="card-title mb-4"><i class="bx bx-filter-alt me-2"></i> Filter Report</h5>
                        <form method="GET" id="reportForm" class="row g-3">
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                            <?php if ($user_role === 'admin'): ?>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Select Branch</label>
                                    <select class="form-select" name="shop_id">
                                        <option value="all" <?= $selected_shop_id === 'all' ? 'selected' : '' ?>>All Branches</option>
                                        <?php foreach ($all_shops as $shop): ?>
                                            <option value="<?= (int)$shop['id'] ?>" <?= $selected_shop_id == $shop['id'] ? 'selected' : '' ?>>
                                                <?= e($shop['shop_name']) ?>
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
                <div class="row mb-4 no-print">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Total Invoices</h6>
                                <h3 class="text-primary mb-0"><?= (int)$grand['invoices'] ?></h3>
                                <small>Rows shown below</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Net Sales</h6>
                                <h3 class="text-success mb-0"><?= money($grand['net_sales']) ?></h3>
                                <small>After returns</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-4">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Returns</h6>
                                <h3 class="text-danger mb-0">-<?= money($grand['returns']) ?></h3>
                                <small><?= number_format($return_percentage, 1) ?>% return rate</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Pending</h6>
                                <h3 class="text-warning mb-0"><?= money($grand['pending']) ?></h3>
                                <small>To be collected</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Printable Header -->
                <div class="print-only print-header">
                    <h2><?= e($_SESSION['current_business_name'] ?? 'Business') ?></h2>
                    <h4>Invoice Wise Sales Report</h4>
                    <p>Period: <?= e($display_date_from) ?> to <?= e($display_date_to) ?> | Branch: <?= e($shop_name) ?></p>
                </div>

                <!-- Invoice Wise Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bx bx-list-ul me-2"></i>
                                    Date Based Invoice Rows
                                </h5>
                                <span class="badge bg-primary"><?= (int)$grand['invoices'] ?> invoices</span>
                            </div>
                            <div class="card-body">
                                <?php if (empty($invoice_rows)): ?>
                                    <div class="text-center py-5">
                                        <i class="bx bx-receipt display-4 text-muted"></i>
                                        <h5 class="mt-3">No invoices found</h5>
                                        <p class="text-muted">No invoices recorded for the selected date.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle invoice-report-table">
                                            <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Date</th>
                                                <th>Invoice ID</th>
                                                <th>Customer</th>
                                                <th class="text-center">Items</th>
                                                <th class="text-end">Total Quantity</th>
                                                <th>Shop</th>
                                                <th class="text-end">Gross</th>
                                                <th class="text-end">Returns</th>
                                                <th class="text-end">Net Sales</th>
                                                <th class="text-end">Paid</th>
                                                <th class="text-end">Pending</th>
                                                <th>Payment</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php
                                            $counter = 1;
                                            $current_date = '';
                                            foreach ($invoice_rows as $inv):
                                                $row_date = date('Y-m-d', strtotime($inv['created_at']));
                                                if ($current_date !== $row_date):
                                                    $current_date = $row_date;
                                            ?>
                                                <tr class="table-secondary date-group-row">
                                                    <td colspan="13" class="fw-bold">
                                                        <i class="bx bx-calendar me-1"></i>
                                                        <?= date('d M Y', strtotime($current_date)) ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php
                                                $net_amount = $inv['total'] - $inv['return_amount'];
                                                $status_class = 'secondary';
                                                if ($inv['payment_status'] === 'paid') {
                                                    $status_class = 'success';
                                                } elseif ($inv['payment_status'] === 'partial') {
                                                    $status_class = 'warning';
                                                } elseif ($inv['payment_status'] === 'pending') {
                                                    $status_class = 'danger';
                                                }
                                            ?>
                                                <tr>
                                                    <td><?= $counter++ ?></td>
                                                    <td>
                                                        <?= date('d M Y', strtotime($inv['created_at'])) ?><br>
                                                        <small class="text-muted"><?= date('h:i A', strtotime($inv['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <a href="invoice_view.php?invoice_id=<?= (int)$inv['invoice_id'] ?>" class="fw-bold text-primary">
                                                            <?= e($inv['invoice_number']) ?>
                                                        </a>
                                                    </td>
                                                    <td>
                                                        <strong><?= e($inv['customer_name']) ?></strong>
                                                        <?php if (!empty($inv['customer_phone'])): ?>
                                                            <br><small class="text-muted"><?= e($inv['customer_phone']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="items-count-badge"><?= (int)$inv['items_count'] ?></span>
                                                    </td>
                                                    <td class="text-end fw-semibold">
                                                        <?= rtrim(rtrim(number_format((float)$inv['total_quantity'], 2), '0'), '.') ?>
                                                    </td>
                                                    <td><?= e($inv['shop_name']) ?></td>
                                                    <td class="text-end fw-semibold"><?= money($inv['total']) ?></td>
                                                    <td class="text-end text-danger">-<?= money($inv['return_amount']) ?></td>
                                                    <td class="text-end fw-bold text-success"><?= money($net_amount) ?></td>
                                                    <td class="text-end text-primary"><?= money($inv['paid_amount']) ?></td>
                                                    <td class="text-end text-warning fw-semibold"><?= money($inv['pending_amount']) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $status_class ?> bg-opacity-10 text-<?= $status_class ?>">
                                                            <?= e(ucfirst($inv['payment_status'])) ?>
                                                        </span><br>
                                                        <small><?= e(strtoupper($inv['payment_method'])) ?></small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-light fw-bold">
                                            <tr>
                                                <td colspan="5" class="text-end">GRAND TOTAL</td>
                                                <td class="text-end"><?= rtrim(rtrim(number_format((float)$grand['total_quantity'], 2), '0'), '.') ?></td>
                                                <td></td>
                                                <td class="text-end"><?= money($grand['gross_sales']) ?></td>
                                                <td class="text-end text-danger">-<?= money($grand['returns']) ?></td>
                                                <td class="text-end text-success"><?= money($grand['net_sales']) ?></td>
                                                <td class="text-end text-primary"><?= money($grand['paid']) ?></td>
                                                <td class="text-end text-warning"><?= money($grand['pending']) ?></td>
                                                <td><?= (int)$grand['invoices'] ?> invoices</td>
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
document.addEventListener('DOMContentLoaded', function () {
    const today = new Date().toISOString().split('T')[0];
    document.querySelectorAll('input[type="date"]').forEach(function (input) {
        input.max = today;
    });
});
</script>

<style>
.card-hover { transition: transform 0.2s ease; }
.card-hover:hover { transform: translateY(-2px); }
.border-start { border-left-width: 4px !important; }
.items-cell { min-width: 120px; max-width: 220px; }
.item-line { display: flex; align-items: center; justify-content: space-between; gap: 8px; border-bottom: 1px dashed #e5e7eb; padding: 3px 0; }
.item-line:last-child { border-bottom: 0; }
.items-count-badge { background: #eef5ff; color: #1d4ed8; border-radius: 6px; padding: 4px 10px; font-size: 12px; font-weight: 700; display: inline-block; }
.qty-badge { background: #eef5ff; color: #1d4ed8; border-radius: 6px; padding: 2px 7px; font-size: 12px; white-space: nowrap; }
.return-badge { background: #fff1f2; color: #dc2626; border-radius: 6px; padding: 2px 7px; font-size: 12px; white-space: nowrap; }
.print-only { display: none; }
.invoice-report-table th, .invoice-report-table td { vertical-align: top; }

@media print {
    @page { size: A4 landscape; margin: 8mm; }
    body { background: #fff !important; color: #000 !important; font-size: 10px; }
    .no-print,
    .vertical-menu,
    .navbar-header,
    .topnav,
    .right-bar,
    .rightbar-overlay,
    footer,
    .footer,
    script {
        display: none !important;
    }
    #layout-wrapper,
    .main-content,
    .page-content,
    .container-fluid {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    .print-only { display: block !important; }
    .print-header { text-align: center; margin-bottom: 10px; border-bottom: 2px solid #000; padding-bottom: 6px; }
    .print-header h2 { margin: 0; font-size: 17px; text-transform: uppercase; }
    .print-header h4 { margin: 4px 0; font-size: 14px; }
    .print-header p { margin: 2px 0; }
    .card { border: 0 !important; box-shadow: none !important; }
    .card-header { border: 0 !important; background: #fff !important; padding: 0 0 6px 0 !important; }
    .card-body { padding: 0 !important; }
    .table-responsive { overflow: visible !important; }
    table { width: 100% !important; border-collapse: collapse !important; }
    th, td { border: 1px solid #555 !important; padding: 4px !important; }
    thead th { background: #f1f1f1 !important; color: #000 !important; }
    .date-group-row td { background: #e9ecef !important; font-weight: bold; }
    .items-cell { max-width: none; min-width: 120px; }
    .item-line { display: block; border-bottom: 1px dotted #999; }
    .qty-badge, .return-badge, .badge { border: 1px solid #777; background: transparent !important; color: #000 !important; }
    a[href]:after { content: none !important; }
}
</style>
</body>
</html>