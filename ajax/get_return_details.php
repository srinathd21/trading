<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$business_id = $_SESSION['current_business_id'] ?? 1;
$return_id = (int)($_GET['return_id'] ?? 0);

if (!$return_id) {
    echo '<div class="alert alert-danger">Invalid return ID.</div>';
    exit();
}

// Get return details
$stmt = $pdo->prepare("
    SELECT 
        r.*,
        i.invoice_number,
        i.total as invoice_total,
        i.created_at as invoice_date,
        c.name as customer_name,
        c.phone as customer_phone,
        c.email as customer_email,
        u.full_name as processed_by_name
    FROM returns r
    JOIN invoices i ON r.invoice_id = i.id
    JOIN customers c ON r.customer_id = c.id
    LEFT JOIN users u ON r.processed_by = u.id
    WHERE r.id = ? AND r.business_id = ?
");
$stmt->execute([$return_id, $business_id]);
$return = $stmt->fetch();

if (!$return) {
    echo '<div class="alert alert-danger">Return not found.</div>';
    exit();
}

// Get return items
$stmt = $pdo->prepare("
    SELECT 
        ri.*,
        p.product_name,
        p.product_code,
        ii.quantity as original_qty,
        ii.unit_price as original_price
    FROM return_items ri
    JOIN invoice_items ii ON ri.invoice_item_id = ii.id
    JOIN products p ON ri.product_id = p.id
    WHERE ri.return_id = ?
    ORDER BY ri.id ASC
");
$stmt->execute([$return_id]);
$items = $stmt->fetchAll();
?>

<div class="row">
    <div class="col-md-6">
        <h5>Return Information</h5>
        <table class="table table-sm">
            <tr>
                <th>Return ID:</th>
                <td>#<?= $return['id'] ?></td>
            </tr>
            <tr>
                <th>Date:</th>
                <td><?= date('d M Y, h:i A', strtotime($return['return_date'])) ?></td>
            </tr>
            <tr>
                <th>Invoice:</th>
                <td>
                    <a href="invoice_view.php?id=<?= $return['invoice_id'] ?>" target="_blank" class="text-primary">
                        <?= htmlspecialchars($return['invoice_number']) ?>
                    </a>
                    <br>
                    <small>Invoice Date: <?= date('d M Y', strtotime($return['invoice_date'])) ?></small>
                </td>
            </tr>
            <tr>
                <th>Total Amount:</th>
                <td class="text-danger fw-bold">-₹<?= number_format($return['total_return_amount'], 2) ?></td>
            </tr>
            <tr>
                <th>Refund Type:</th>
                <td>
                    <?= $return['refund_to_cash'] ? 
                        '<span class="badge bg-success"><i class="bx bx-money"></i> Cash Refund</span>' : 
                        '<span class="badge bg-info">Credit/Digital</span>' ?>
                </td>
            </tr>
        </table>
    </div>
    
    <div class="col-md-6">
        <h5>Customer Details</h5>
        <table class="table table-sm">
            <tr>
                <th>Name:</th>
                <td><?= htmlspecialchars($return['customer_name']) ?></td>
            </tr>
            <tr>
                <th>Phone:</th>
                <td><?= htmlspecialchars($return['customer_phone']) ?></td>
            </tr>
            <?php if ($return['customer_email']): ?>
            <tr>
                <th>Email:</th>
                <td><?= htmlspecialchars($return['customer_email']) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Processed By:</th>
                <td><?= htmlspecialchars($return['processed_by_name'] ?? 'System') ?></td>
            </tr>
        </table>
    </div>
</div>

<div class="row mt-3">
    <div class="col-12">
        <h5>Return Reason & Notes</h5>
        <div class="card bg-light">
            <div class="card-body">
                <p><strong>Reason:</strong> <?= htmlspecialchars($return['return_reason']) ?></p>
                <?php if ($return['notes']): ?>
                <p><strong>Notes:</strong> <?= htmlspecialchars($return['notes']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($items)): ?>
<div class="row mt-3">
    <div class="col-12">
        <h5>Returned Items</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th class="text-center">Original Qty</th>
                        <th class="text-center">Returned Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Return Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sr = 1;
                    $total_returned = 0;
                    foreach ($items as $item): 
                        $total_returned += $item['return_value'];
                    ?>
                    <tr>
                        <td><?= $sr++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                            <?php if ($item['product_code']): ?>
                            <br><small class="text-muted">Code: <?= htmlspecialchars($item['product_code']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= $item['original_qty'] ?></td>
                        <td class="text-center text-danger"><?= $item['quantity'] ?></td>
                        <td class="text-end">₹<?= number_format($item['unit_price'], 2) ?></td>
                        <td class="text-end text-danger">-₹<?= number_format($item['return_value'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-secondary">
                        <td colspan="5" class="text-end"><strong>Total:</strong></td>
                        <td class="text-end text-danger fw-bold">-₹<?= number_format($total_returned, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>