<?php
session_start();
require_once 'config/database.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['current_business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// Check permissions
$can_process_returns = in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier']);
if (!$can_process_returns) {
    $_SESSION['error'] = 'You do not have permission to process returns.';
    header('Location: invoices.php');
    exit();
}

// Get invoice ID and customer ID from URL
$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if (!$invoice_id) {
    $_SESSION['error'] = 'Invalid invoice ID.';
    header('Location: invoices.php');
    exit();
}

// Fetch invoice details
$stmt = $pdo->prepare("
    SELECT i.*, c.name as customer_name, c.phone as customer_phone, 
           s.name as shop_name, u.name as seller_name
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN shops s ON i.shop_id = s.id
    LEFT JOIN users u ON i.seller_id = u.id
    WHERE i.id = ? AND i.business_id = ?
");
$stmt->execute([$invoice_id, $business_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    $_SESSION['error'] = 'Invoice not found.';
    header('Location: invoices.php');
    exit();
}

// Verify shop access for non-admin users
if ($user_role !== 'admin' && $invoice['shop_id'] != $current_shop_id) {
    $_SESSION['error'] = 'You do not have access to this invoice.';
    header('Location: invoices.php');
    exit();
}

// Get invoice items for return
$stmt = $pdo->prepare("
    SELECT ii.*, p.name as product_name, p.stock_price,
           (ii.quantity - ii.return_qty) as available_for_return
    FROM invoice_items ii
    LEFT JOIN products p ON ii.product_id = p.id
    WHERE ii.invoice_id = ? 
      AND (ii.quantity - ii.return_qty) > 0
    ORDER BY ii.id
");
$stmt->execute([$invoice_id]);
$invoice_items = $stmt->fetchAll();

if (empty($invoice_items)) {
    $_SESSION['error'] = 'No items available for return in this invoice.';
    header('Location: invoices.php');
    exit();
}

// Calculate already returned amount
$stmt = $pdo->prepare("SELECT SUM(total_return_amount) as total_returned FROM returns WHERE invoice_id = ?");
$stmt->execute([$invoice_id]);
$already_returned = $stmt->fetchColumn() ?? 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate return quantities
    $return_items = [];
    $total_return_amount = 0;
    $total_return_qty = 0;
    $has_valid_return = false;
    
    foreach ($invoice_items as $item) {
        $item_id = $item['id'];
        $return_qty = isset($_POST['return_qty'][$item_id]) ? (int)$_POST['return_qty'][$item_id] : 0;
        
        if ($return_qty > 0) {
            // Validate quantity doesn't exceed available
            $available = $item['available_for_return'];
            if ($return_qty > $available) {
                $_SESSION['error'] = "Return quantity for {$item['product_name']} exceeds available quantity.";
                header('Location: invoice_items_return.php?invoice_id=' . $invoice_id . '&customer_id=' . $customer_id);
                exit();
            }
            
            $return_items[] = [
                'item_id' => $item_id,
                'product_id' => $item['product_id'],
                'quantity' => $return_qty,
                'unit_price' => $item['unit_price'],
                'return_value' => $item['unit_price'] * $return_qty
            ];
            
            $total_return_amount += $item['unit_price'] * $return_qty;
            $total_return_qty += $return_qty;
            $has_valid_return = true;
        }
    }
    
    if (!$has_valid_return) {
        $_SESSION['error'] = 'Please select at least one item to return.';
        header('Location: invoice_items_return.php?invoice_id=' . $invoice_id . '&customer_id=' . $customer_id);
        exit();
    }
    
    $return_reason = $_POST['return_reason'] ?? '';
    $return_notes = $_POST['return_notes'] ?? '';
    $return_date = $_POST['return_date'] ?? date('Y-m-d');
    $refund_to_cash = isset($_POST['refund_to_cash']) ? 1 : 0;
    
    if (empty($return_reason)) {
        $_SESSION['error'] = 'Please select a return reason.';
        header('Location: invoice_items_return.php?invoice_id=' . $invoice_id . '&customer_id=' . $customer_id);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. Insert return record
        $stmt = $pdo->prepare("
            INSERT INTO returns 
            (invoice_id, customer_id, return_date, total_return_amount, 
             return_reason, notes, refund_to_cash, processed_by, business_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoice_id,
            $customer_id,
            $return_date,
            $total_return_amount,
            $return_reason,
            $return_notes,
            $refund_to_cash,
            $user_id,
            $business_id
        ]);
        
        $return_id = $pdo->lastInsertId();
        
        // 2. Insert return items
        foreach ($return_items as $return_item) {
            // Insert into return_items
            $stmt = $pdo->prepare("
                INSERT INTO return_items 
                (return_id, invoice_item_id, product_id, quantity, unit_price, return_value)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $return_id,
                $return_item['item_id'],
                $return_item['product_id'],
                $return_item['quantity'],
                $return_item['unit_price'],
                $return_item['return_value']
            ]);
            
            // Update invoice_items return_qty
            $stmt = $pdo->prepare("
                UPDATE invoice_items 
                SET return_qty = return_qty + ?
                WHERE id = ?
            ");
            $stmt->execute([
                $return_item['quantity'],
                $return_item['item_id']
            ]);
            
            // Restore stock if applicable
            $stmt = $pdo->prepare("
                UPDATE products 
                SET current_stock = current_stock + ?
                WHERE id = ?
            ");
            $stmt->execute([
                $return_item['quantity'],
                $return_item['product_id']
            ]);
        }
        
        // 3. Update invoice pending amount if refunding to cash
        if ($refund_to_cash && $invoice['pending_amount'] > 0) {
            $new_pending = max(0, $invoice['pending_amount'] - $total_return_amount);
            $stmt = $pdo->prepare("
                UPDATE invoices 
                SET pending_amount = ?, 
                    paid_amount = total - ?,
                    payment_status = CASE 
                        WHEN ? = 0 THEN 'paid' 
                        WHEN ? < total THEN 'partial' 
                        ELSE 'pending' 
                    END
                WHERE id = ?
            ");
            $stmt->execute([
                $new_pending,
                $new_pending,
                $new_pending,
                $new_pending,
                $invoice_id
            ]);
        }
        
        $pdo->commit();
        
        // Handle loyalty points adjustment if applicable
        if ($total_return_amount > 0) {
            // Check if loyalty settings exist
            $stmt = $pdo->prepare("SELECT is_active, points_per_amount FROM loyalty_settings WHERE business_id = ?");
            $stmt->execute([$business_id]);
            $loyalty_settings = $stmt->fetch();
            
            if ($loyalty_settings && $loyalty_settings['is_active'] == 1 && $loyalty_settings['points_per_amount'] > 0) {
                $points_to_deduct = floor($total_return_amount * $loyalty_settings['points_per_amount'] * 100) / 100;
                
                if ($points_to_deduct > 0) {
                    // Deduct points from customer
                    $stmt = $pdo->prepare("
                        UPDATE customer_points 
                        SET available_points = GREATEST(0, available_points - ?),
                            total_points_deducted = total_points_deducted + ?,
                            last_updated = CURRENT_TIMESTAMP
                        WHERE customer_id = ? AND business_id = ?
                    ");
                    $stmt->execute([$points_to_deduct, $points_to_deduct, $customer_id, $business_id]);
                    
                    // Log point deduction transaction
                    $stmt = $pdo->prepare("
                        INSERT INTO point_transactions 
                        (customer_id, business_id, invoice_id, transaction_type, points, amount_basis, created_by, notes)
                        VALUES (?, ?, ?, 'return_deduction', ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $customer_id,
                        $business_id,
                        $invoice_id,
                        $points_to_deduct,
                        $total_return_amount,
                        $user_id,
                        "Points deducted for return #{$return_id}"
                    ]);
                }
            }
        }
        
        $_SESSION['success'] = "Return processed successfully for ₹" . number_format($total_return_amount, 2) . " ($total_return_qty items).";
        header('Location: invoices.php');
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Failed to process return: ' . $e->getMessage();
        header('Location: invoice_items_return.php?invoice_id=' . $invoice_id . '&customer_id=' . $customer_id);
        exit();
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Return Items - Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Return items for invoice" name="description">
    <?php include 'includes/head.php'; ?>
</head>
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
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-undo me-2"></i> Return Items
                                    <small class="text-muted">Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></small>
                                </h4>
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb mb-2">
                                        <li class="breadcrumb-item"><a href="invoices.php">Invoices</a></li>
                                        <li class="breadcrumb-item active">Return Items</li>
                                    </ol>
                                </nav>
                            </div>
                            <div>
                                <a href="invoices.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Invoices
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>

                <!-- Invoice Summary -->
                <div class="row mb-4">
                    <div class="col-lg-4">
                        <div class="card border-primary">
                            <div class="card-body">
                                <h6 class="card-title text-primary mb-3">
                                    <i class="bx bx-info-circle me-2"></i>Invoice Details
                                </h6>
                                <table class="table table-sm">
                                    <tr>
                                        <td width="40%"><strong>Invoice No:</strong></td>
                                        <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Date:</strong></td>
                                        <td><?= date('d M Y, h:i A', strtotime($invoice['created_at'])) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Customer:</strong></td>
                                        <td>
                                            <?= htmlspecialchars($invoice['customer_name']) ?><br>
                                            <small class="text-muted"><?= htmlspecialchars($invoice['customer_phone']) ?></small>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Shop:</strong></td>
                                        <td><?= htmlspecialchars($invoice['shop_name']) ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card border-info">
                            <div class="card-body">
                                <h6 class="card-title text-info mb-3">
                                    <i class="bx bx-money me-2"></i>Payment Details
                                </h6>
                                <table class="table table-sm">
                                    <tr>
                                        <td width="40%"><strong>Total Amount:</strong></td>
                                        <td class="text-primary fw-bold">₹<?= number_format($invoice['total'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Paid Amount:</strong></td>
                                        <td class="text-success">₹<?= number_format($invoice['paid_amount'] ?? 0, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Due Amount:</strong></td>
                                        <td class="text-danger">₹<?= number_format($invoice['pending_amount'] ?? 0, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Status:</strong></td>
                                        <td>
                                            <?php 
                                            $status_class = $invoice['payment_status'] == 'paid' ? 'success' : 
                                                          ($invoice['payment_status'] == 'partial' ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge bg-<?= $status_class ?>"><?= ucfirst($invoice['payment_status']) ?></span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card border-warning">
                            <div class="card-body">
                                <h6 class="card-title text-warning mb-3">
                                    <i class="bx bx-refresh me-2"></i>Return Summary
                                </h6>
                                <table class="table table-sm">
                                    <tr>
                                        <td width="40%"><strong>Already Returned:</strong></td>
                                        <td class="text-danger">₹<?= number_format($already_returned, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Items Count:</strong></td>
                                        <td><?= count($invoice_items) ?> items available</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Processed By:</strong></td>
                                        <td><?= htmlspecialchars($invoice['seller_name']) ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Return Form -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-cart me-2"></i> Select Items to Return
                        </h5>
                    </div>
                    <form method="POST" action="" id="returnForm">
                        <div class="card-body">
                            <!-- Items Table -->
                            <div class="table-responsive mb-4">
                                <table class="table table-hover table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th>Product Name</th>
                                            <th class="text-center">Sold Qty</th>
                                            <th class="text-center">Already Returned</th>
                                            <th class="text-center">Available for Return</th>
                                            <th class="text-center">Return Qty</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Return Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_potential_return = 0;
                                        foreach ($invoice_items as $index => $item): 
                                            $sold_qty = (int)$item['quantity'];
                                            $already_returned = (int)$item['return_qty'];
                                            $available = (int)$item['available_for_return'];
                                            $unit_price = (float)$item['unit_price'];
                                        ?>
                                        <tr>
                                            <td><?= $index + 1 ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                                <?php if ($item['hsn_code']): ?>
                                                <br><small class="text-muted">HSN: <?= htmlspecialchars($item['hsn_code']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?= $sold_qty ?></td>
                                            <td class="text-center text-danger"><?= $already_returned ?></td>
                                            <td class="text-center text-success fw-bold"><?= $available ?></td>
                                            <td class="text-center">
                                                <input type="number" 
                                                       name="return_qty[<?= $item['id'] ?>]" 
                                                       class="form-control form-control-sm return-qty-input text-center"
                                                       min="0" 
                                                       max="<?= $available ?>"
                                                       value="0"
                                                       data-unit-price="<?= $unit_price ?>"
                                                       style="width: 80px; margin: 0 auto;">
                                            </td>
                                            <td class="text-end">₹<?= number_format($unit_price, 2) ?></td>
                                            <td class="text-end return-value-cell" id="return_value_<?= $item['id'] ?>">
                                                ₹0.00
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="7" class="text-end fw-bold">Total Return Value:</td>
                                            <td class="text-end fw-bold text-danger" id="total_return_value">₹0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Return Details -->
                            <div class="row g-3">
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
                                    <label class="form-label">Return Date <span class="text-danger">*</span></label>
                                    <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                
                                <div class="col-md-12">
                                    <label class="form-label">Notes (Optional)</label>
                                    <textarea name="return_notes" class="form-control" rows="3" placeholder="Any additional notes about the return..."></textarea>
                                </div>
                                
                                <div class="col-md-12">
                                    <div class="form-check mb-3">
                                        <input type="checkbox" name="refund_to_cash" id="refund_to_cash" class="form-check-input" value="1">
                                        <label class="form-check-label text-success fw-bold" for="refund_to_cash">
                                            <i class="bx bx-money me-1"></i> Refund as cash payment (reduce pending amount)
                                        </label>
                                        <small class="text-muted d-block">
                                            If checked, the return amount will be deducted from the pending amount. 
                                            Current pending: ₹<?= number_format($invoice['pending_amount'] ?? 0, 2) ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-footer bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <button type="button" class="btn btn-secondary" onclick="history.back()">
                                        <i class="bx bx-arrow-back me-1"></i> Cancel
                                    </button>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-danger" id="submitReturnBtn" disabled>
                                        <i class="bx bx-check me-2"></i> Process Return
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>

<script>
$(document).ready(function() {
    // Calculate return values
    function calculateReturnValues() {
        let totalReturnValue = 0;
        let hasReturnItems = false;
        
        $('.return-qty-input').each(function() {
            const qty = parseInt($(this).val()) || 0;
            const unitPrice = parseFloat($(this).data('unit-price'));
            const itemId = $(this).attr('name').match(/\[(\d+)\]/)[1];
            const maxQty = parseInt($(this).attr('max')) || 0;
            
            // Validate quantity doesn't exceed max
            if (qty > maxQty) {
                $(this).val(maxQty);
                qty = maxQty;
            }
            
            const returnValue = qty * unitPrice;
            $(`#return_value_${itemId}`).text('₹' + returnValue.toFixed(2));
            
            if (qty > 0) {
                totalReturnValue += returnValue;
                hasReturnItems = true;
            }
        });
        
        $('#total_return_value').text('₹' + totalReturnValue.toFixed(2));
        
        // Enable/disable submit button
        const hasReason = $('select[name="return_reason"]').val() !== '';
        $('#submitReturnBtn').prop('disabled', !(hasReturnItems && hasReason));
    }
    
    // Initialize calculations
    calculateReturnValues();
    
    // Update calculations on input
    $(document).on('input', '.return-qty-input', function() {
        calculateReturnValues();
    });
    
    // Update calculations on reason change
    $('select[name="return_reason"]').change(function() {
        calculateReturnValues();
    });
    
    // Form submission
    $('#returnForm').submit(function(e) {
        e.preventDefault();
        
        // Validate at least one return item
        let totalQty = 0;
        $('.return-qty-input').each(function() {
            totalQty += parseInt($(this).val()) || 0;
        });
        
        if (totalQty === 0) {
            alert('Please select at least one item to return.');
            return false;
        }
        
        // Validate return reason
        const returnReason = $('select[name="return_reason"]').val();
        if (!returnReason) {
            alert('Please select a return reason.');
            return false;
        }
        
        // Calculate total return value
        let totalReturnValue = 0;
        $('.return-qty-input').each(function() {
            const qty = parseInt($(this).val()) || 0;
            const unitPrice = parseFloat($(this).data('unit-price'));
            totalReturnValue += qty * unitPrice;
        });
        
        // Confirm return
        if (!confirm(`Are you sure you want to return ${totalQty} item(s) for ₹${totalReturnValue.toFixed(2)}? This action cannot be undone.`)) {
            return false;
        }
        
        // Show processing
        const btn = $('#submitReturnBtn');
        const originalText = btn.html();
        btn.html('<i class="bx bx-loader bx-spin me-2"></i> Processing...');
        btn.prop('disabled', true);
        
        // Submit form
        this.submit();
    });
    
    // Auto-hide alerts
    setTimeout(() => {
        $('.alert').fadeOut();
    }, 5000);
});
</script>

<style>
.card {
    border-radius: 10px;
    overflow: hidden;
}
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
}
.return-qty-input {
    text-align: center;
    font-weight: 500;
}
.return-qty-input:focus {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
}
.form-check-input:checked {
    background-color: #198754;
    border-color: #198754;
}
</style>
</body>
</html>