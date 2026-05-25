<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$business_id = (int)($_SESSION['current_business_id'] ?? ($_SESSION['business_id'] ?? 1));
$user_role = $_SESSION['role'] ?? '';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$for_return = isset($_GET['for_return']) ? (int)$_GET['for_return'] : 0;
$quick = isset($_GET['quick']) ? (int)$_GET['quick'] : 0;

if ($invoice_id <= 0) {
    http_response_code(400);
    echo "Invoice ID required";
    exit();
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return number_format((float)$value, 2);
}

try {
    $invoice_check = $pdo->prepare("
        SELECT 
            i.*,
            c.name AS customer_name,
            c.phone AS customer_phone
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.id = ?
          AND i.business_id = ?
        LIMIT 1
    ");
    $invoice_check->execute([$invoice_id, $business_id]);
    $invoice = $invoice_check->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        http_response_code(404);
        echo "Invoice not found";
        exit();
    }

    if ($user_role !== 'admin' && !empty($current_shop_id)) {
        if ((int)($invoice['shop_id'] ?? 0) !== (int)$current_shop_id) {
            http_response_code(403);
            echo "This invoice does not belong to your shop.";
            exit();
        }
    }

    $items_sql = "
        SELECT 
            ii.*,
            COALESCE(p.product_name, 'Unknown Product') AS product_name,
            COALESCE(p.product_code, '') AS product_code,
            COALESCE(p.unit_of_measure, ii.unit, 'pcs') AS unit_of_measure
        FROM invoice_items ii
        LEFT JOIN products p ON ii.product_id = p.id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id ASC
    ";

    $items_stmt = $pdo->prepare($items_sql);
    $items_stmt->execute([$invoice_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($for_return == 1) {
        $has_items = false;

        ob_start();
        ?>

        <?php if (!$quick): ?>
        <div class="p-3">
            <div class="mb-3">
                <h6 class="mb-2">Customer: <?= h($invoice['customer_name'] ?? 'Walk-in') ?></h6>
                <p class="text-muted small mb-0">Invoice #<?= h($invoice['invoice_number']) ?></p>
                <p class="text-muted small mb-0">Date: <?= date('d M Y, h:i A', strtotime($invoice['created_at'])) ?></p>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bx bx-info-circle me-1"></i>
                    Returned products will be moved to <strong>Return Management</strong>. Stock will not be updated directly.
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$quick): ?>
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
        <?php endif; ?>

        <?php foreach ($items as $item): 
            $sold_qty = (int)($item['quantity'] ?? 0);
            $returned_qty = (int)($item['return_qty'] ?? 0);
            $available_qty = max(0, $sold_qty - $returned_qty);

            if ($available_qty <= 0) {
                continue;
            }

            $has_items = true;

            $item_id = (int)$item['id'];
            $unit_price = (float)($item['unit_price'] ?? 0);
            $product_name = trim(($item['product_code'] ? $item['product_code'] . ' - ' : '') . $item['product_name']);
            $unit = $item['unit_of_measure'] ?: 'pcs';
        ?>

            <tr>
                <td>
                    <div class="d-flex flex-column">
                        <strong><?= h($product_name) ?></strong>
                        <?php if (!empty($item['product_code'])): ?>
                            <small class="text-muted"><?= h($item['product_code']) ?></small>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="text-center"><?= $sold_qty ?> <?= h($unit) ?></td>
                <td class="text-center">
                    <?php if ($returned_qty > 0): ?>
                        <span class="text-danger fw-bold"><?= $returned_qty ?></span>
                    <?php else: ?>
                        <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <span class="badge bg-success">
                        <?= $available_qty ?>
                    </span>
                </td>
                <td class="text-center">
                    <input type="number"
                           name="return_qty_<?= $item_id ?>"
                           class="form-control form-control-sm return-qty-input text-center"
                           min="0"
                           max="<?= $available_qty ?>"
                           value="0"
                           data-price="<?= h($unit_price) ?>"
                           data-unit-price="<?= h($unit_price) ?>"
                           data-max="<?= $available_qty ?>"
                           data-item-id="<?= $item_id ?>"
                           style="width: 85px; margin: 0 auto;">
                    <small class="text-muted">Max: <?= $available_qty ?></small>
                </td>
                <td class="text-end">₹<?= money($unit_price) ?></td>
                <td class="text-end return-value" id="return-value-<?= $item_id ?>">₹0.00</td>
            </tr>

        <?php endforeach; ?>

        <?php if (!$has_items): ?>
            <tr>
                <td colspan="7" class="text-center py-4">
                    <i class="bx bx-info-circle fs-3 text-muted d-block mb-2"></i>
                    <p class="text-muted mb-0">No items available for return</p>
                </td>
            </tr>
        <?php endif; ?>

        <?php if (!$quick): ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="6" class="text-end">Total Return Value:</th>
                        <th class="text-end text-danger" id="total-return-value">₹0.00</th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!$quick && $has_items): ?>
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

        <?php if (!$quick): ?>
        </div>
        <?php endif; ?>

        <?php if ($has_items): ?>
        <script>
        $(document).ready(function() {
            function updateReturnCalculation() {
                let total = 0;

                $('.return-qty-input').each(function() {
                    let qty = parseInt($(this).val(), 10);
                    let maxQty = parseInt($(this).attr('max'), 10);
                    let price = parseFloat($(this).data('price'));
                    let itemId = $(this).data('item-id');

                    if (isNaN(qty)) qty = 0;
                    if (isNaN(maxQty)) maxQty = parseInt($(this).data('max'), 10) || 0;
                    if (isNaN(price)) price = 0;

                    if (qty < 0) qty = 0;
                    if (qty > maxQty) qty = maxQty;

                    $(this).val(qty);

                    let returnValue = qty * price;
                    total += returnValue;

                    $('#return-value-' + itemId).text('₹' + returnValue.toLocaleString('en-IN', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }));
                });

                $('#total-return-value').text('₹' + total.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }));

                let hasReason = $('select[name="return_reason"]').val() !== '';
                $('#processReturnBtn').prop('disabled', !(total > 0 && hasReason));
                $('#quickReturnSubmitBtn').prop('disabled', !(total > 0 && hasReason));
            }

            $('.return-qty-input').off('input change keyup').on('input change keyup', function() {
                updateReturnCalculation();
            });

            $('select[name="return_reason"]').off('change').on('change', function() {
                updateReturnCalculation();
            });

            updateReturnCalculation();
        });
        </script>
        <?php endif; ?>

        <?php
        echo ob_get_clean();
        exit();
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'invoice' => $invoice,
        'items' => $items
    ]);
    exit();

} catch (Exception $e) {
    http_response_code(500);

    if ($for_return == 1) {
        echo '<div class="alert alert-danger m-3">Error: ' . h($e->getMessage()) . '</div>';
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit();
}
?>