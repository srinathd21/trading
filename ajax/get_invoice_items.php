<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$business_id = $_SESSION['current_business_id'] ?? 1;
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$for_return = isset($_GET['for_return']) && $_GET['for_return'] == 1;

if (!$invoice_id) {
    echo '<div class="alert alert-danger">Invalid invoice ID.</div>';
    exit();
}

// Get invoice items with return information
$stmt = $pdo->prepare("
    SELECT 
        ii.id,
        ii.product_id,
        ii.quantity,
        ii.unit_price,
        ii.total_price,
        ii.total_with_gst,
        ii.return_qty,
        p.product_name,
        p.product_code,
        (SELECT SUM(quantity) FROM return_items WHERE invoice_item_id = ii.id) as already_returned
    FROM invoice_items ii
    JOIN products p ON ii.product_id = p.id
    WHERE ii.invoice_id = ? AND p.business_id = ?
    ORDER BY ii.id ASC
");
$stmt->execute([$invoice_id, $business_id]);
$items = $stmt->fetchAll();

if (empty($items)) {
    echo '<div class="alert alert-info">No items found in this invoice.</div>';
    exit();
}
?>

<div id="returnItemsContainer">
    <h6>Select Items to Return</h6>
    <div class="table-responsive">
        <table class="table table-sm" id="returnItemsTable">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="text-center">Original Qty</th>
                    <th class="text-center">Already Returned</th>
                    <th class="text-center">Available to Return</th>
                    <th class="text-center">Return Qty</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Return Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($items as $item): 
                    $already_returned = (int)($item['already_returned'] ?? $item['return_qty'] ?? 0);
                    $available_qty = $item['quantity'] - $already_returned;
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                        <?php if ($item['product_code']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($item['product_code']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= $item['quantity'] ?></td>
                    <td class="text-center text-danger"><?= $already_returned ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $available_qty > 0 ? 'success' : 'danger' ?>">
                            <?= $available_qty ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <input type="number" 
                               name="return_qty_<?= $item['id'] ?>"
                               class="form-control form-control-sm return-qty-input text-center"
                               min="0"
                               max="<?= $available_qty ?>"
                               value="0"
                               style="width: 80px;"
                               data-unit-price="<?= $item['unit_price'] ?>"
                               oninput="updateReturnValue(this, <?= $item['id'] ?>)"
                               <?= $available_qty <= 0 ? 'disabled' : '' ?>>
                    </td>
                    <td class="text-end">₹<?= number_format($item['unit_price'], 2) ?></td>
                    <td class="text-end" id="return-value-<?= $item['id'] ?>">₹0.00</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-secondary">
                    <td colspan="6" class="text-end"><strong>Total Return:</strong></td>
                    <td class="text-end"><strong id="totalReturnValue">₹0.00</strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
function updateReturnValue(input, itemId) {
    const qty = parseInt(input.value) || 0;
    const unitPrice = parseFloat(input.dataset.unitPrice) || 0;
    const returnValue = qty * unitPrice;
    
    // Update individual item return value
    $(`#return-value-${itemId}`).text('₹' + returnValue.toFixed(2));
    
    // Update total
    updateTotalReturn();
}

function updateTotalReturn() {
    let total = 0;
    $('.return-qty-input').each(function() {
        const qty = parseInt($(this).val()) || 0;
        const unitPrice = parseFloat($(this).data('unit-price')) || 0;
        total += qty * unitPrice;
    });
    
    $('#totalReturnValue').text('₹' + total.toFixed(2));
}

// Initialize on document ready
$(document).ready(function() {
    // Attach change event to all quantity inputs
    $('.return-qty-input').on('input', function() {
        const itemId = $(this).attr('name').replace('return_qty_', '');
        const qty = parseInt($(this).val()) || 0;
        const unitPrice = parseFloat($(this).data('unit-price')) || 0;
        const returnValue = qty * unitPrice;
        
        $(`#return-value-${itemId}`).text('₹' + returnValue.toFixed(2));
        updateTotalReturn();
    });
    
    // Initial calculation
    updateTotalReturn();
});
</script>