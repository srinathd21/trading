<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['invoice_id'])) {
    echo '<div class="alert alert-danger">Invalid request</div>';
    exit();
}

$invoice_id = (int)$_GET['invoice_id'];
$business_id = $_SESSION['business_id'] ?? 1;

try {
    // Get invoice details
    $sql = "SELECT i.*, 
                   u.full_name as seller_name,
                   s.shop_name,
                   c.name as customer_name,
                   c.phone as customer_phone
            FROM invoices i
            LEFT JOIN users u ON i.seller_id = u.id AND u.business_id = i.business_id
            LEFT JOIN shops s ON i.shop_id = s.id AND s.business_id = i.business_id
            LEFT JOIN customers c ON i.customer_id = c.id AND c.business_id = i.business_id
            WHERE i.id = ? AND i.business_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$invoice_id, $business_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        echo '<div class="alert alert-danger">Invoice not found</div>';
        exit();
    }
    
    // Get invoice items
    $items_sql = "SELECT ii.*, p.product_name, p.product_code 
                  FROM invoice_items ii
                  JOIN products p ON ii.product_id = p.id AND p.business_id = ?
                  WHERE ii.invoice_id = ?
                  ORDER BY ii.id";
    
    $items_stmt = $pdo->prepare($items_sql);
    $items_stmt->execute([$business_id, $invoice_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ?>
    <div data-invoice-id="<?= $invoice_id ?>">
        <!-- Invoice Header -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="mb-3">
                    <span class="fw-bold">Invoice Number:</span>
                    <span class="ms-2"><?= htmlspecialchars($invoice['invoice_number']) ?></span>
                </div>
                <div class="mb-3">
                    <span class="fw-bold">Invoice Type:</span>
                    <span class="ms-2 badge bg-<?= $invoice['invoice_type'] == 'tax_invoice' ? 'info' : 'secondary' ?>">
                        <?= $invoice['invoice_type'] == 'tax_invoice' ? 'Tax Invoice' : 'Retail Bill' ?>
                    </span>
                </div>
                <div class="mb-3">
                    <span class="fw-bold">Created:</span>
                    <span class="ms-2">
                        <?= date('d M Y, h:i A', strtotime($invoice['created_at'])) ?>
                    </span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <span class="fw-bold">Customer:</span>
                    <span class="ms-2"><?= htmlspecialchars($invoice['customer_name']) ?></span>
                </div>
                <?php if ($invoice['customer_phone']): ?>
                <div class="mb-3">
                    <span class="fw-bold">Phone:</span>
                    <span class="ms-2"><?= htmlspecialchars($invoice['customer_phone']) ?></span>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <span class="fw-bold">Seller:</span>
                    <span class="ms-2"><?= htmlspecialchars($invoice['seller_name'] ?? 'N/A') ?></span>
                </div>
                <div class="mb-3">
                    <span class="fw-bold">Shop:</span>
                    <span class="ms-2"><?= htmlspecialchars($invoice['shop_name'] ?? 'N/A') ?></span>
                </div>
            </div>
        </div>
        
        <!-- Invoice Items -->
        <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Code</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Total</th>
                        <th>Discount</th>
                        <th>GST</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $i = 1;
                    $total_items = 0;
                    $total_amount = 0;
                    foreach ($items as $item): 
                        $total_items += $item['quantity'];
                        $item_total = $item['total_price'] - $item['discount_amount'];
                        $total_amount += $item_total;
                    ?>
                    <tr>
                        <td><?= $i ?></td>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= htmlspecialchars($item['product_code'] ?? 'N/A') ?></td>
                        <td><?= $item['quantity'] ?></td>
                        <td>₹<?= number_format($item['unit_price'], 2) ?></td>
                        <td>₹<?= number_format($item['total_price'], 2) ?></td>
                        <td>₹<?= number_format($item['discount_amount'], 2) ?></td>
                        <td>
                            <?php if ($item['cgst_amount'] > 0 || $item['sgst_amount'] > 0): ?>
                            CGST: ₹<?= number_format($item['cgst_amount'], 2) ?><br>
                            SGST: ₹<?= number_format($item['sgst_amount'], 2) ?>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php 
                    $i++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
        
        <!-- Invoice Summary -->
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <span class="fw-bold">Payment Method:</span>
                    <span class="ms-2 badge bg-<?= 
                        $invoice['payment_method'] == 'cash' ? 'success' : 
                        ($invoice['payment_method'] == 'upi' ? 'primary' : 
                        ($invoice['payment_method'] == 'bank' ? 'info' : 
                        ($invoice['payment_method'] == 'cheque' ? 'warning' : 'secondary'))) 
                    ?>">
                        <?= ucfirst($invoice['payment_method']) ?>
                    </span>
                </div>
                <?php if ($invoice['notes']): ?>
                <div class="mb-3">
                    <span class="fw-bold">Notes:</span>
                    <p class="mt-1 mb-0"><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <th>Subtotal:</th>
                        <td class="text-end">₹<?= number_format($invoice['subtotal'], 2) ?></td>
                    </tr>
                    <?php if ($invoice['discount'] > 0): ?>
                    <tr>
                        <th>Discount:</th>
                        <td class="text-end text-danger">-₹<?= number_format($invoice['discount'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Total:</th>
                        <td class="text-end fw-bold text-success">₹<?= number_format($invoice['total'], 2) ?></td>
                    </tr>
                    <?php if ($invoice['pending_amount'] > 0): ?>
                    <tr>
                        <th>Pending:</th>
                        <td class="text-end fw-bold text-danger">₹<?= number_format($invoice['pending_amount'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <?php
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading invoice details: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>