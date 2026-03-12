<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['current_business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// Get quotation ID from URL
$quotation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($quotation_id <= 0) {
    $_SESSION['error'] = 'Invalid quotation ID';
    header('Location: quotations.php');
    exit();
}

// Fetch quotation details
$stmt = $pdo->prepare("
    SELECT q.*, 
           s.shop_name as shop_name,
           s.address as shop_address,
           s.phone as shop_phone,
          
           s.gstin as shop_gstin,
           u.username as created_by_name
    FROM quotations q
    LEFT JOIN shops s ON q.shop_id = s.id
    LEFT JOIN users u ON q.created_by = u.id
    WHERE q.id = ? AND q.business_id = ?
");

$stmt->execute([$quotation_id, $business_id]);
$quotation = $stmt->fetch();

if (!$quotation) {
    $_SESSION['error'] = 'Quotation not found or you do not have permission to view it';
    header('Location: quotations.php');
    exit();
}

// Check if user has permission to view this quotation
if ($user_role !== 'admin' && $quotation['shop_id'] != $current_shop_id) {
    $_SESSION['error'] = 'You do not have permission to view this quotation';
    header('Location: quotations.php');
    exit();
}

// Fetch quotation items
$items_stmt = $pdo->prepare("
    SELECT qi.*, 
           p.product_name as product_name,
       
           p.hsn_code as product_hsn
    FROM quotation_items qi
    LEFT JOIN products p ON qi.product_id = p.id
    WHERE qi.quotation_id = ?
    ORDER BY qi.id
");
$items_stmt->execute([$quotation_id]);
$items = $items_stmt->fetchAll();



// Calculate tax breakdown
$cgst_total = 0;
$sgst_total = 0;
$igst_total = 0;

foreach ($items as $item) {
    $item_total = $item['quantity'] * $item['unit_price'];
    $discount_amount = $item['discount_amount'];
    
    if ($item['discount_type'] == 'percent') {
        $discount_amount = ($item_total * $discount_amount) / 100;
    }
    
    $item_total_after_discount = $item_total - $discount_amount;
    
    if ($item['igst_rate'] > 0) {
        $igst_total += ($item_total_after_discount * $item['igst_rate']) / 100;
    } else {
        $cgst_total += ($item_total_after_discount * $item['cgst_rate']) / 100;
        $sgst_total += ($item_total_after_discount * $item['sgst_rate']) / 100;
    }
}

// Check permissions
$is_admin = ($user_role === 'admin');
$is_shop_manager = in_array($user_role, ['admin', 'shop_manager']);
$can_edit = ($quotation['status'] == 'draft' && in_array($user_role, ['admin', 'shop_manager', 'seller']));
$can_convert = ($quotation['status'] == 'accepted' && !$quotation['converted_to_invoice_id'] && in_array($user_role, ['admin', 'shop_manager', 'seller']));
$can_delete = ($quotation['status'] == 'draft' && in_array($user_role, ['admin', 'shop_manager']));

// Check if quotation is expired
$is_expired = strtotime($quotation['valid_until']) < time() && 
              $quotation['status'] !== 'accepted' && 
              $quotation['status'] !== 'rejected';
?>

<!doctype html>
<html lang="en">
<?php $page_title = "View Quotation - " . $quotation['quotation_number']; include 'includes/head.php'; ?>
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
                        <div class="page-title-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-file me-2"></i> View Quotation
                                    <small class="text-muted ms-2">
                                        <?= htmlspecialchars($quotation['quotation_number']) ?>
                                    </small>
                                </h4>
                                <small class="text-muted">
                                    Created: <?= date('d M Y, h:i A', strtotime($quotation['created_at'])) ?>
                                    by <?= htmlspecialchars($quotation['created_by_name']) ?>
                                </small>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                
                                
                                <a type="button" href="quotation_print.php?id=<?=$quotation_id?>" class="btn btn-success" target="_blank">
                                    <i class="bx bx-printer me-1"></i> Print
                                </a>
                               
                                
                                <?php if ($can_delete): ?>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                    <i class="bx bx-trash me-1"></i> Delete
                                </button>
                                <?php endif; ?>
                                
                                <a href="quotations.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to List
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status Alert -->
                <?php 
                $status_class = [
                    'draft' => 'secondary',
                    'sent' => 'warning',
                    'accepted' => 'success',
                    'rejected' => 'danger',
                    'expired' => 'dark'
                ][$quotation['status']] ?? 'secondary';
                
                $status_text = ucfirst($quotation['status']);
                if ($is_expired && $quotation['status'] !== 'expired') {
                    $status_class = 'danger';
                    $status_text = 'Expired';
                }
                ?>
                
                <div class="alert alert-<?= $status_class ?> bg-<?= $status_class ?> bg-opacity-10 border-<?= $status_class ?> border-start border-4 mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="alert-heading mb-1">
                                <i class="bx bx-<?= $quotation['status'] == 'accepted' ? 'check-circle' : ($quotation['status'] == 'rejected' ? 'x-circle' : 'info-circle') ?> me-2"></i>
                                Quotation Status: <?= $status_text ?>
                            </h5>
                            <p class="mb-0">
                                <?php if ($is_expired): ?>
                                <i class="bx bx-time me-1"></i> This quotation expired on <?= date('d M Y', strtotime($quotation['valid_until'])) ?>
                                <?php else: ?>
                                <i class="bx bx-calendar me-1"></i> Valid until: <?= date('d M Y', strtotime($quotation['valid_until'])) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if ($quotation['converted_to_invoice_id']): ?>
                        <div class="text-end">
                            <a href="invoice_view.php?invoice_id=<?= $quotation['converted_to_invoice_id'] ?>" class="btn btn-success btn-sm">
                                <i class="bx bx-receipt me-1"></i> View Invoice
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <!-- Quotation Details Card -->
                <div class="row">
                    <!-- Company Details -->
                    <div class="col-lg-4">
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="bx bx-building me-2"></i> Your Business
                                </h5>
                            </div>
                            <div class="card-body">
                                <h6 class="text-primary mb-2"><?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?></h6>
                                <?php if ($quotation['shop_name']): ?>
                                <p class="mb-1">
                                    <strong>Shop:</strong> <?= htmlspecialchars($quotation['shop_name']) ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($quotation['shop_address']): ?>
                                <p class="mb-1">
                                    <i class="bx bx-map me-1 text-muted"></i>
                                    <?= htmlspecialchars($quotation['shop_address']) ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($quotation['shop_phone']): ?>
                                <p class="mb-1">
                                    <i class="bx bx-phone me-1 text-muted"></i>
                                    <?= htmlspecialchars($quotation['shop_phone']) ?>
                                </p>
                                <?php endif; ?>
                                
                                <?php if ($quotation['shop_gstin']): ?>
                                <p class="mb-0">
                                    <i class="bx bx-id-card me-1 text-muted"></i>
                                    <strong>GSTIN:</strong> <?= htmlspecialchars($quotation['shop_gstin']) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Customer Details -->
                    <div class="col-lg-4">
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="bx bx-user-circle me-2"></i> Customer Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <h6 class="text-primary mb-2"><?= htmlspecialchars($quotation['customer_name']) ?></h6>
                                <?php if ($quotation['customer_phone']): ?>
                                <p class="mb-1">
                                    <i class="bx bx-phone me-1 text-muted"></i>
                                    <?= htmlspecialchars($quotation['customer_phone']) ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($quotation['customer_email']): ?>
                                <p class="mb-1">
                                    <i class="bx bx-envelope me-1 text-muted"></i>
                                    <?= htmlspecialchars($quotation['customer_email']) ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($quotation['customer_address']): ?>
                                <p class="mb-1">
                                    <i class="bx bx-map me-1 text-muted"></i>
                                    <?= nl2br(htmlspecialchars($quotation['customer_address'])) ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($quotation['customer_gstin']): ?>
                                <p class="mb-0">
                                    <i class="bx bx-id-card me-1 text-muted"></i>
                                    <strong>GSTIN:</strong> <?= htmlspecialchars($quotation['customer_gstin']) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Quotation Info -->
                    <div class="col-lg-4">
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="bx bx-info-circle me-2"></i> Quotation Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%"><strong>Quotation No:</strong></td>
                                        <td class="text-end"><?= htmlspecialchars($quotation['quotation_number']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Date:</strong></td>
                                        <td class="text-end"><?= date('d M Y', strtotime($quotation['quotation_date'])) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Valid Until:</strong></td>
                                        <td class="text-end <?= $is_expired ? 'text-danger' : 'text-success' ?>">
                                            <?= date('d M Y', strtotime($quotation['valid_until'])) ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Items:</strong></td>
                                        <td class="text-end"><?= count($items) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Created By:</strong></td>
                                        <td class="text-end"><?= htmlspecialchars($quotation['created_by_name']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Shop:</strong></td>
                                        <td class="text-end"><?= htmlspecialchars($quotation['shop_name']) ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bx bx-package me-2"></i> Quotation Items
                        </h5>
                        <span class="badge bg-primary"><?= count($items) ?> items</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">#</th>
                                        <th>Product Details</th>
                                        <th class="text-center">HSN Code</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-center">Unit Price</th>
                                        <th class="text-center">Discount</th>
                                        <th class="text-center">Tax Rate</th>
                                        <th class="text-end pe-4">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bx bx-package display-4 text-muted mb-4"></i>
                                                <h5 class="text-muted mb-3">No items in this quotation</h5>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php 
                                    $counter = 1;
                                    foreach ($items as $item): 
                                        $item_total = $item['quantity'] * $item['unit_price'];
                                        $discount_amount = $item['discount_amount'];
                                        $discount_text = '';
                                        
                                        if ($discount_amount > 0) {
                                            if ($item['discount_type'] == 'percent') {
                                                $discount_text = number_format($discount_amount, 1) . '%';
                                                $discount_amount = ($item_total * $discount_amount) / 100;
                                            } else {
                                                $discount_text = '₹' . number_format($discount_amount, 2);
                                            }
                                            $item_total_after_discount = $item_total - $discount_amount;
                                        } else {
                                            $item_total_after_discount = $item_total;
                                        }
                                        
                                        // Calculate tax for this item
                                        $tax_amount = $item['tax_amount'];
                                        $tax_rate = '';
                                        if ($item['igst_rate'] > 0) {
                                            $tax_rate = 'IGST: ' . number_format($item['igst_rate'], 2) . '%';
                                        } else {
                                            $tax_rate = 'CGST: ' . number_format($item['cgst_rate'], 2) . '%<br>';
                                            $tax_rate .= 'SGST: ' . number_format($item['sgst_rate'], 2) . '%';
                                        }
                                    ?>
                                    <tr>
                                        <td class="ps-4"><?= $counter++ ?></td>
                                        <td>
                                            <strong class="d-block"><?= htmlspecialchars($item['product_name']) ?></strong>
                                           
                                        </td>
                                        <td class="text-center"><?= htmlspecialchars($item['hsn_code'] ?? $item['product_hsn'] ?? 'N/A') ?></td>
                                        <td class="text-center"><?= $item['quantity'] ?></td>
                                        <td class="text-center">₹<?= number_format($item['unit_price'], 2) ?></td>
                                        <td class="text-center">
                                            <?php if ($discount_text): ?>
                                            <span class="badge bg-warning bg-opacity-10 text-warning">
                                                <?= $discount_text ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <small><?= $tax_rate ?></small>
                                        </td>
                                        <td class="text-end pe-4">
                                            <strong>₹<?= number_format($item['total_price'], 2) ?></strong>
                                            <?php if ($discount_amount > 0): ?>
                                            <br>
                                            <small class="text-muted">
                                                <del>₹<?= number_format($item_total, 2) ?></del>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Totals and Notes -->
                <div class="row">
                    <!-- Notes -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="bx bx-note me-2"></i> Terms & Notes
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if ($quotation['notes']): ?>
                                <div class="mb-4">
                                    <h6 class="text-primary mb-2">Quotation Notes:</h6>
                                    <p class="mb-0"><?= nl2br(htmlspecialchars($quotation['notes'])) ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <h6 class="text-primary mb-2">Standard Terms:</h6>
                                    <ul class="mb-0">
                                        <li>This quotation is valid until <?= date('d M Y', strtotime($quotation['valid_until'])) ?></li>
                                        <li>Prices are subject to change without prior notice</li>
                                        <li>Delivery charges may apply based on location</li>
                                        <li>Payment terms: 50% advance, 50% before delivery</li>
                                        <li>Taxes extra as applicable</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Totals -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="bx bx-calculator me-2"></i> Amount Summary
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td width="60%"><strong>Subtotal:</strong></td>
                                            <td class="text-end">₹<?= number_format($quotation['subtotal'], 2) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Total Discount:</strong></td>
                                            <td class="text-end">₹<?= number_format($quotation['total_discount'], 2) ?></td>
                                        </tr>
                                        
                                        <!-- Tax Breakdown -->
                                        <?php if ($cgst_total > 0 || $sgst_total > 0): ?>
                                        <tr>
                                            <td><strong>CGST (<?= number_format($items[0]['cgst_rate'] ?? 0, 2) ?>%):</strong></td>
                                            <td class="text-end">₹<?= number_format($cgst_total, 2) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>SGST (<?= number_format($items[0]['sgst_rate'] ?? 0, 2) ?>%):</strong></td>
                                            <td class="text-end">₹<?= number_format($sgst_total, 2) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        
                                        <?php if ($igst_total > 0): ?>
                                        <tr>
                                            <td><strong>IGST (<?= number_format($items[0]['igst_rate'] ?? 0, 2) ?>%):</strong></td>
                                            <td class="text-end">₹<?= number_format($igst_total, 2) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        
                                        <tr>
                                            <td><strong>Total Tax:</strong></td>
                                            <td class="text-end">₹<?= number_format($quotation['total_tax'], 2) ?></td>
                                        </tr>
                                        
                                        <tr class="border-top">
                                            <td><strong class="fs-5">Grand Total:</strong></td>
                                            <td class="text-end">
                                                <span class="fs-4 text-primary">₹<?= number_format($quotation['grand_total'], 2) ?></span>
                                            </td>
                                        </tr>
                                        
                                        <?php if ($quotation['total_discount'] > 0): ?>
                                        <tr class="border-top">
                                            <td colspan="2" class="pt-3">
                                                <small class="text-muted">
                                                    <i class="bx bx-info-circle me-1"></i>
                                                    Total savings: ₹<?= number_format($quotation['total_discount'], 2) ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                                
                                <!-- Amount in Words -->
                                <div class="mt-4 pt-3 border-top">
                                    <small class="text-muted d-block mb-1">Amount in Words:</small>
                                    <p class="mb-0">
                                        <strong id="amountInWords"></strong>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <a type="button" href="quotation_print.php?id=<?=$quotation_id?>" target="_blank">
                                            <i class="bx bx-printer me-1"></i> Print Quotation
                                        </a>
                                        
                                    </div>
                                    <div>
                                       
                                        
                                        
                                        <a href="quotations.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-arrow-back me-1"></i> Back to List
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Convert to Invoice Modal -->
<div class="modal fade" id="convertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bx bx-receipt me-2"></i> Convert to Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="convert_quotation_to_invoice.php">
                <input type="hidden" name="quotation_id" value="<?= $quotation_id ?>">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bx bx-info-circle me-2"></i>
                        This will create a new invoice from quotation #<?= htmlspecialchars($quotation['quotation_number']) ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Invoice Date</label>
                        <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="">-- Select Method --</option>
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="upi">UPI</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="credit">Credit</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Status</label>
                        <select name="payment_status" class="form-select" required>
                            <option value="pending">Pending</option>
                            <option value="partial">Partial</option>
                            <option value="paid" selected>Paid</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="include_all_items" id="include_all_items" class="form-check-input" checked>
                            <label class="form-check-label" for="include_all_items">
                                Include all quotation items
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bx bx-check me-1"></i> Convert
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<?php if ($can_delete): ?>
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bx bx-trash me-2"></i> Delete Quotation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="ajax/delete_quotation.php">
                <input type="hidden" name="quotation_id" value="<?= $quotation_id ?>">
                <div class="modal-body">
                    <p>Are you sure you want to delete quotation <strong><?= htmlspecialchars($quotation['quotation_number']) ?></strong>?</p>
                    <div class="alert alert-danger">
                        <i class="bx bx-error-circle me-2"></i>
                        This action cannot be undone. All quotation items will be permanently deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bx bx-trash me-1"></i> Delete Quotation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>

<script>
// Function to convert number to words
function numberToWords(num) {
    const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 
                  'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 
                  'Eighteen', 'Nineteen'];
    const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    const scales = ['', 'Thousand', 'Lakh', 'Crore'];
    
    function convertHundreds(n) {
        let result = '';
        if (n >= 100) {
            result += ones[Math.floor(n / 100)] + ' Hundred ';
            n %= 100;
        }
        if (n >= 20) {
            result += tens[Math.floor(n / 10)] + ' ';
            n %= 10;
        }
        if (n > 0) {
            result += ones[n] + ' ';
        }
        return result.trim();
    }
    
    if (num === 0) return 'Zero Rupees';
    
    let result = '';
    let scaleIndex = 0;
    
    // Handle decimal part
    let decimalPart = Math.round((num - Math.floor(num)) * 100);
    let integerPart = Math.floor(num);
    
    // Convert integer part
    while (integerPart > 0) {
        let chunk = integerPart % 1000;
        if (chunk !== 0) {
            let chunkWords = convertHundreds(chunk);
            if (scaleIndex > 0) {
                chunkWords += ' ' + scales[scaleIndex];
            }
            result = chunkWords + ' ' + result;
        }
        integerPart = Math.floor(integerPart / 1000);
        scaleIndex++;
    }
    
    result = result.trim() + ' Rupees';
    
    // Add paise if exists
    if (decimalPart > 0) {
        result += ' and ' + convertHundreds(decimalPart) + ' Paise';
    }
    
    return result;
}

// Display amount in words
document.addEventListener('DOMContentLoaded', function() {
    const grandTotal = <?= $quotation['grand_total'] ?>;
    document.getElementById('amountInWords').textContent = numberToWords(grandTotal);
    
    // Auto-hide alerts
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Print styles
    const printStyles = `
        @media print {
            .vertical-menu, .topbar, .footer, .page-title-box .d-flex > div:last-child,
            .card-header .btn, .modal, .right-bar, .alert {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .card {
                border: 1px solid #dee2e6 !important;
                box-shadow: none !important;
            }
            .card-header {
                background-color: #f8f9fa !important;
                color: #000 !important;
            }
            .table th {
                background-color: #f8f9fa !important;
            }
            .text-primary {
                color: #000 !important;
            }
            .border-start {
                border-left: 1px solid #dee2e6 !important;
            }
            body {
                background: white !important;
                color: black !important;
            }
        }
    `;
    
    const styleSheet = document.createElement("style");
    styleSheet.type = "text/css";
    styleSheet.innerText = printStyles;
    document.head.appendChild(styleSheet);
});
</script>

<style>
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}
.empty-state i {
    font-size: 4rem;
    opacity: 0.5;
}
.card-header {
    padding: 1rem 1.25rem;
}
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
}
.badge.bg-opacity-10 {
    opacity: 0.9;
}
.alert {
    border-left-width: 4px;
}
</style>
</body>
</html>