<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$for_return = isset($_GET['for_return']) ? (int)$_GET['for_return'] : 0;

if (!$invoice_id) {
    http_response_code(400);
    echo "Invoice ID required";
    exit();
}

// Verify invoice belongs to this business
$invoice_check = $pdo->prepare("
    SELECT i.*, c.name as customer_name, c.phone as customer_phone
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE i.id = ? AND i.business_id = ?
");
$invoice_check->execute([$invoice_id, $business_id]);
$invoice = $invoice_check->fetch();

if (!$invoice) {
    http_response_code(404);
    echo "Invoice not found";
    exit();
}

// Get invoice items with product details
$items_sql = "
    SELECT 
        ii.*,
        p.product_name,
        p.product_code,
        p.unit_of_measure
    FROM invoice_items ii
    JOIN products p ON ii.product_id = p.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id
";

$items_stmt = $pdo->prepare($items_sql);
$items_stmt->execute([$invoice_id]);
$items = $items_stmt->fetchAll();

if ($for_return == 1) {
    // Return HTML for return modal
    ?>
    <div class="p-3">
        <div class="mb-3">
            <h6 class="mb-2">Customer: <?= htmlspecialchars($invoice['customer_name'] ?? 'Walk-in') ?></h6>
            <p class="text-muted small mb-0">Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></p>
            <p class="text-muted small">Date: <?= date('d M Y, h:i A', strtotime($invoice['created_at'])) ?></p>
        </div>
        
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th class="text-center">Sold</th>
                        <th class="text-center">Returned</th>
                        <th class="text-center">Available</th>
                        <th class="text-center">Return Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Return Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_return_value = 0;
                    $has_items = false;
                    foreach ($items as $item): 
                        $available_qty = $item['quantity'] - ($item['return_qty'] ?? 0);
                        $max_return = $available_qty;
                        
                        if ($max_return <= 0) continue; // Skip fully returned items
                        $has_items = true;
                    ?>
                    <tr>
                        <td>
                            <div class="d-flex flex-column">
                                <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                <small class="text-muted"><?= htmlspecialchars($item['product_code']) ?></small>
                            </div>
                        </td>
                        <td class="text-center"><?= $item['quantity'] ?></td>
                        <td class="text-center">
                            <?php if ($item['return_qty'] > 0): ?>
                            <span class="text-danger"><?= $item['return_qty'] ?></span>
                            <?php else: ?>
                            <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $max_return > 0 ? 'success' : 'secondary' ?> bg-opacity-10 text-<?= $max_return > 0 ? 'success' : 'secondary' ?>">
                                <?= $max_return ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <input type="number" 
                                   name="return_qty_<?= $item['id'] ?>" 
                                   class="form-control form-control-sm return-qty-input" 
                                   min="0" 
                                   max="<?= $max_return ?>"
                                   value="0"
                                   data-price="<?= $item['unit_price'] ?>"
                                   data-item-id="<?= $item['id'] ?>"
                                   style="width: 80px; margin: 0 auto;">
                        </td>
                        <td class="text-end">₹<?= number_format($item['unit_price'], 2) ?></td>
                        <td class="text-end return-value" id="return-value-<?= $item['id'] ?>">₹0.00</td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (!$has_items): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <i class="bx bx-info-circle fs-3 text-muted d-block mb-2"></i>
                            <p class="text-muted">No items available for return</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="6" class="text-end">Total Return Value:</th>
                        <th class="text-end text-danger" id="total-return-value">₹0.00</th>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <?php if ($has_items): ?>
        <div class="row g-3 mt-2">
            <div class="col-md-6">
                <label class="form-label">Return Reason <span class="text-danger">*</span></label>
                <select name="return_reason" class="form-select" required>
                    <option value="">-- Select Reason --</option>
                    <option value="defective">Defective Product</option>
                    <option value="wrong_item">Wrong Item Delivered</option>
                    <option value="not_needed">Not Needed Anymore</option>
                    <option value="size_issue">Size/Fit Issue</option>
                    <option value="damaged">Damaged in Transit</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Return Date</label>
                <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-12">
                <label class="form-label">Notes (Optional)</label>
                <textarea name="return_notes" class="form-control" rows="2" placeholder="Any additional notes..."></textarea>
            </div>
            <div class="col-md-12">
                <div class="form-check">
                    <input type="checkbox" name="refund_to_cash" id="refund_to_cash_modal" class="form-check-input" value="1">
                    <label class="form-check-label text-success" for="refund_to_cash_modal">
                        <i class="bx bx-money me-1"></i> Refund as cash payment
                    </label>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if ($has_items): ?>
    <script>
    $(document).ready(function() {
        // Remove any existing event handlers
        $('.return-qty-input').off('input');
        $('select[name="return_reason"]').off('change');
        
        // Calculate return values
        $('.return-qty-input').on('input', function() {
            const qty = parseInt($(this).val()) || 0;
            const price = parseFloat($(this).data('price'));
            const itemId = $(this).data('item-id');
            const maxQty = parseInt($(this).attr('max'));
            
            // Validate
            if (qty > maxQty) {
                $(this).val(maxQty);
                qty = maxQty;
            }
            
            const returnValue = qty * price;
            $('#return-value-' + itemId).text('₹' + returnValue.toFixed(2));
            
            // Calculate total
            let total = 0;
            $('.return-qty-input').each(function() {
                const itemQty = parseInt($(this).val()) || 0;
                const itemPrice = parseFloat($(this).data('price'));
                total += itemQty * itemPrice;
            });
            $('#total-return-value').text('₹' + total.toFixed(2));
            
            // Enable/disable submit button
            const hasReason = $('select[name="return_reason"]').val() !== '';
            $('#processReturnBtn').prop('disabled', !(total > 0 && hasReason));
        });
        
        // Return reason validation
        $('select[name="return_reason"]').change(function() {
            const hasReason = $(this).val() !== '';
            const totalText = $('#total-return-value').text();
            const total = parseFloat(totalText.replace('₹', '')) || 0;
            $('#processReturnBtn').prop('disabled', !(total > 0 && hasReason));
        });
    });
    </script>
    <?php endif; ?>
    
    <?php
} else {
    // Return JSON for API usage
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'invoice' => $invoice,
        'items' => $items
    ]);
}
?>