<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Authorization
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? '';

if (!in_array($user_role, ['admin', 'warehouse_manager'])) {
    header('Location: dashboard.php');
    exit();
}

// Get filter parameters from URL
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

// === Build WHERE clause with filters ===
$where = ["p.business_id = ?"];
$params = [$business_id];

// Search filter
if (!empty($search)) {
    $where[] = "(p.purchase_number LIKE ? OR p.reference LIKE ? OR m.name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Status filter
if (!empty($status) && in_array($status, ['paid', 'partial', 'unpaid'])) {
    $where[] = "p.payment_status = ?";
    $params[] = $status;
}

// Date range filters
if (!empty($date_from)) {
    $where[] = "DATE(p.purchase_date) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where[] = "DATE(p.purchase_date) <= ?";
    $params[] = $date_to;
}

// Build WHERE clause string
$whereClause = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

// === Fetch Purchase Orders with filters ===
$purchases_sql = "
    SELECT
        p.id,
        p.purchase_number,
        p.purchase_date,
        p.total_amount,
        p.paid_amount,
        p.payment_status,
        p.reference,
        m.name AS manufacturer_name,
        u.full_name AS created_by_name,
        (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = p.id) AS item_count
    FROM purchases p
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    LEFT JOIN users u ON p.created_by = u.id
    $whereClause
    ORDER BY p.purchase_date DESC, p.id DESC
";

$purchases_stmt = $pdo->prepare($purchases_sql);
$purchases_stmt->execute($params);
$purchases = $purchases_stmt->fetchAll(PDO::FETCH_ASSOC);

// === Summary Stats with filters ===
$stats_sql = "
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(total_amount), 0) AS total_amount,
        COALESCE(SUM(paid_amount), 0) AS total_paid,
        COALESCE(SUM(total_amount - paid_amount), 0) AS pending_amount,
        SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count
    FROM purchases p
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    $whereClause
";

$stmt = $pdo->prepare($stats_sql);
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Set headers for Excel file download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="purchase_orders_' . date('Y-m-d_H-i-s') . '.xls"');
header('Cache-Control: max-age=0');

// Excel file content with UTF-8 BOM
echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Purchase Orders Export</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2 { color: #007bff; margin: 0; }
        .header h4 { color: #666; margin: 10px 0; }
        .summary { display: flex; justify-content: space-between; margin: 20px 0; }
        .summary-box { text-align: center; padding: 15px; border: 1px solid #ddd; border-radius: 5px; flex: 1; margin: 0 10px; }
        .summary-box h3 { margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background-color: #f2f2f2; font-weight: bold; text-align: left; }
        th, td { border: 1px solid #000; padding: 10px; text-align: left; }
        .total-row { background-color: #e8f4fd; font-weight: bold; }
        .status-paid { background-color: #d4edda; }
        .status-partial { background-color: #fff3cd; }
        .status-unpaid { background-color: #f8d7da; }
        .filter-info { background-color: #f8f9fa; padding: 15px; border: 1px solid #ddd; margin: 10px 0; }
        .filter-info h5 { margin: 0 0 10px 0; color: #666; }
        .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Purchase Orders Report</h2>
        <h4><?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?></h4>
        <p>Generated on: <?= date('d/m/Y h:i A') ?></p>
        
        <?php if ($search || $status || $date_from || $date_to): ?>
        <div class="filter-info">
            <h5>Applied Filters:</h5>
            <p>
                <?php if ($search): ?>
                    <strong>Search:</strong> <?= htmlspecialchars($search) ?> | 
                <?php endif; ?>
                <?php if ($status): ?>
                    <strong>Status:</strong> <?= ucfirst($status) ?> | 
                <?php endif; ?>
                <?php if ($date_from): ?>
                    <strong>From:</strong> <?= date('d M Y', strtotime($date_from)) ?> | 
                <?php endif; ?>
                <?php if ($date_to): ?>
                    <strong>To:</strong> <?= date('d M Y', strtotime($date_to)) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <div class="summary">
        <div class="summary-box">
            <h5>Total Purchases</h5>
            <h3>₹<?= number_format($stats['total_amount'], 2) ?></h3>
            <small><?= $stats['total_orders'] ?> orders</small>
        </div>
        <div class="summary-box">
            <h5>Total Paid</h5>
            <h3>₹<?= number_format($stats['total_paid'], 2) ?></h3>
            <small>
                <?= $stats['total_orders'] > 0 ? number_format(($stats['total_paid'] / $stats['total_amount']) * 100, 1) : '0' ?>% paid
            </small>
        </div>
        <div class="summary-box">
            <h5>Pending Amount</h5>
            <h3>₹<?= number_format($stats['pending_amount'], 2) ?></h3>
            <small><?= $stats['unpaid_count'] ?> unpaid orders</small>
        </div>
        <div class="summary-box">
            <h5>Payment Status</h5>
            <h3>
                <?= $stats['total_orders'] > 0 ? number_format(($stats['total_paid'] / $stats['total_amount']) * 100, 1) : '0' ?>%
            </h3>
            <small>Paid Percentage</small>
        </div>
    </div>

    <h4>Purchase Orders Details (<?= count($purchases) ?> records)</h4>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Purchase No.</th>
                <th>Date</th>
                <th>Supplier</th>
                <th class="text-center">Items</th>
                <th class="text-right">Total Amount</th>
                <th class="text-right">Paid Amount</th>
                <th class="text-right">Balance</th>
                <th>Status</th>
                <th>Reference</th>
                <th>Created By</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($purchases)): ?>
            <tr>
                <td colspan="11" class="text-center">No purchase orders found</td>
            </tr>
            <?php else: ?>
            <?php 
            $counter = 1;
            $grand_total = 0;
            $grand_paid = 0;
            $grand_balance = 0;
            foreach ($purchases as $p): 
                $balance = $p['total_amount'] - $p['paid_amount'];
                $grand_total += $p['total_amount'];
                $grand_paid += $p['paid_amount'];
                $grand_balance += $balance;
                
                $status_class = '';
                switch($p['payment_status']) {
                    case 'paid': $status_class = 'status-paid'; break;
                    case 'partial': $status_class = 'status-partial'; break;
                    case 'unpaid': $status_class = 'status-unpaid'; break;
                }
            ?>
            <tr>
                <td><?= $counter++ ?></td>
                <td><?= htmlspecialchars($p['purchase_number']) ?></td>
                <td><?= date('d M Y', strtotime($p['purchase_date'])) ?></td>
                <td><?= htmlspecialchars($p['manufacturer_name'] ?? '—') ?></td>
                <td class="text-center"><?= $p['item_count'] ?></td>
                <td class="text-right">₹<?= number_format($p['total_amount'], 2) ?></td>
                <td class="text-right">₹<?= number_format($p['paid_amount'], 2) ?></td>
                <td class="text-right">₹<?= number_format($balance, 2) ?></td>
                <td class="<?= $status_class ?>"><?= ucfirst($p['payment_status']) ?></td>
                <td><?= htmlspecialchars($p['reference']) ?></td>
                <td><?= htmlspecialchars($p['created_by_name']) ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Grand Total Row -->
            <tr class="total-row">
                <td colspan="5" class="text-right"><strong>GRAND TOTAL:</strong></td>
                <td class="text-right"><strong>₹<?= number_format($grand_total, 2) ?></strong></td>
                <td class="text-right"><strong>₹<?= number_format($grand_paid, 2) ?></strong></td>
                <td class="text-right"><strong>₹<?= number_format($grand_balance, 2) ?></strong></td>
                <td colspan="3"></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Payment Status Summary -->
    <h4>Payment Status Summary</h4>
    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th>Count</th>
                <th class="text-right">Total Amount</th>
                <th class="text-right">Paid Amount</th>
                <th class="text-right">Balance</th>
                <th>Percentage</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Calculate status summary
            $status_summary = [
                'paid' => ['count' => 0, 'total' => 0, 'paid' => 0, 'balance' => 0],
                'partial' => ['count' => 0, 'total' => 0, 'paid' => 0, 'balance' => 0],
                'unpaid' => ['count' => 0, 'total' => 0, 'paid' => 0, 'balance' => 0]
            ];
            
            foreach ($purchases as $p) {
                $status = $p['payment_status'];
                if (isset($status_summary[$status])) {
                    $status_summary[$status]['count']++;
                    $status_summary[$status]['total'] += $p['total_amount'];
                    $status_summary[$status]['paid'] += $p['paid_amount'];
                    $status_summary[$status]['balance'] += ($p['total_amount'] - $p['paid_amount']);
                }
            }
            
            foreach ($status_summary as $status => $data):
                if ($data['count'] > 0):
                    $percentage = $stats['total_amount'] > 0 ? ($data['total'] / $stats['total_amount']) * 100 : 0;
            ?>
            <tr class="status-<?= $status ?>">
                <td><?= ucfirst($status) ?></td>
                <td><?= $data['count'] ?></td>
                <td class="text-right">₹<?= number_format($data['total'], 2) ?></td>
                <td class="text-right">₹<?= number_format($data['paid'], 2) ?></td>
                <td class="text-right">₹<?= number_format($data['balance'], 2) ?></td>
                <td><?= number_format($percentage, 1) ?>%</td>
            </tr>
            <?php endif; endforeach; ?>
            
            <!-- Total Row -->
            <tr class="total-row">
                <td><strong>TOTAL</strong></td>
                <td><strong><?= $stats['total_orders'] ?></strong></td>
                <td class="text-right"><strong>₹<?= number_format($stats['total_amount'], 2) ?></strong></td>
                <td class="text-right"><strong>₹<?= number_format($stats['total_paid'], 2) ?></strong></td>
                <td class="text-right"><strong>₹<?= number_format($stats['pending_amount'], 2) ?></strong></td>
                <td><strong>100%</strong></td>
            </tr>
        </tbody>
    </table>

    <!-- Top Suppliers -->
    <?php
    $suppliers_sql = "
        SELECT 
            m.name AS supplier_name,
            COUNT(p.id) AS order_count,
            SUM(p.total_amount) AS total_purchased,
            SUM(p.paid_amount) AS total_paid,
            SUM(p.total_amount - p.paid_amount) AS total_balance
        FROM purchases p
        LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
        $whereClause
        GROUP BY p.manufacturer_id
        ORDER BY total_purchased DESC
        LIMIT 10
    ";
    
    $suppliers_stmt = $pdo->prepare($suppliers_sql);
    $suppliers_stmt->execute($params);
    $top_suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($top_suppliers)):
    ?>
    <h4>Top 10 Suppliers</h4>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Supplier Name</th>
                <th>Orders</th>
                <th class="text-right">Total Purchased</th>
                <th class="text-right">Total Paid</th>
                <th class="text-right">Balance</th>
                <th>Percentage</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $counter = 1;
            foreach ($top_suppliers as $supplier):
                $percentage = $stats['total_amount'] > 0 ? ($supplier['total_purchased'] / $stats['total_amount']) * 100 : 0;
            ?>
            <tr>
                <td><?= $counter++ ?></td>
                <td><?= htmlspecialchars($supplier['supplier_name']) ?></td>
                <td><?= $supplier['order_count'] ?></td>
                <td class="text-right">₹<?= number_format($supplier['total_purchased'], 2) ?></td>
                <td class="text-right">₹<?= number_format($supplier['total_paid'], 2) ?></td>
                <td class="text-right">₹<?= number_format($supplier['total_balance'], 2) ?></td>
                <td><?= number_format($percentage, 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="footer">
        <p>Report generated by: <?= htmlspecialchars($_SESSION['full_name'] ?? 'System') ?></p>
        <p>Business: <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?></p>
        <p>© <?= date('Y') ?> - All Rights Reserved</p>
    </div>
</body>
</html>