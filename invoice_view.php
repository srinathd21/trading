<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$business_id = $_SESSION['business_id'] ?? 1;
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if (!$invoice_id) {
    header('Location: invoices.php');
    exit();
}

// Fetch invoice
$stmt = $pdo->prepare("
    SELECT i.*,
           c.name as customer_name, c.phone as customer_phone, c.gstin as customer_gstin,
           u.full_name as seller_name,
           s.shop_name, s.address as shop_address, s.phone as shop_phone, s.gstin as shop_gstin
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.seller_id = u.id
    LEFT JOIN shops s ON i.shop_id = s.id
    WHERE i.id = ? AND i.business_id = ?
");
$stmt->execute([$invoice_id, $business_id]);
$invoice = $stmt->fetch();
if (!$invoice) {
    $_SESSION['error'] = "Invoice not found!";
    header('Location: invoices.php');
    exit();
}

// Debug: First check what's in invoice_items for this invoice
$debug_stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
$debug_stmt->execute([$invoice_id]);
$debug_items = $debug_stmt->fetchAll();

// Fetch items with product details
$items_stmt = $pdo->prepare("
    SELECT 
        ii.id as item_id,
        ii.product_id,
        ii.quantity,
        ii.return_qty,
        ii.unit_price,
        ii.discount_amount,
        ii.sale_type,
        ii.unit as item_unit,
        ii.hsn_code as item_hsn,
        p.product_name,
        p.product_code,
        p.hsn_code as product_hsn,
        p.unit_of_measure as product_unit
    FROM invoice_items ii
    LEFT JOIN products p ON ii.product_id = p.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id
");
$items_stmt->execute([$invoice_id]);
$items = $items_stmt->fetchAll();

// Get returned quantities
$returned_qty = [];
$ret_stmt = $pdo->prepare("
    SELECT invoice_item_id, SUM(quantity) as returned_qty
    FROM return_items
    WHERE invoice_item_id IN (SELECT id FROM invoice_items WHERE invoice_id = ?)
    GROUP BY invoice_item_id
");
$ret_stmt->execute([$invoice_id]);
foreach ($ret_stmt->fetchAll() as $ret) {
    $returned_qty[$ret['invoice_item_id']] = (int)$ret['returned_qty'];
}

// Calculate totals
$subtotal = 0;
$total_discount = 0;
$total_profit = 0;
$active_total = 0;
$processed_items = [];

foreach ($items as &$item) {
    $sold_qty = $item['quantity'];
    $returned_this = $returned_qty[$item['item_id']] ?? 0;
    $remaining_qty = $sold_qty - $returned_this;
    
    // MRP × Qty (GST Inclusive)
    $line_total = $item['unit_price'] * $sold_qty;
    $discount = $item['discount_amount'] ?? 0;
    $net_total = $line_total - $discount;
    
    // Active total after return
    $active_amount = $remaining_qty > 0 ? ($remaining_qty / $sold_qty) * $net_total : 0;
    $active_total += $active_amount;
    $subtotal += $line_total;
    $total_discount += $discount;
    
    // Get unit - prioritize item_unit from invoice_items, fall back to product_unit from products
    $unit = !empty($item['item_unit']) ? $item['item_unit'] : 
            (!empty($item['product_unit']) ? $item['product_unit'] : 'PCS');
    
    // Get HSN code
    $hsn_code = !empty($item['item_hsn']) ? $item['item_hsn'] : 
               (!empty($item['product_hsn']) ? $item['product_hsn'] : '');
    
    // Store for display
    $processed_items[] = [
        'item_id' => $item['item_id'],
        'product_id' => $item['product_id'],
        'product_name' => $item['product_name'] ?? 'Unknown Product',
        'product_code' => $item['product_code'] ?? '',
        'hsn_code' => $hsn_code,
        'sale_type' => $item['sale_type'],
        'unit' => $unit,
        'sold_qty' => $sold_qty,
        'returned_qty' => $returned_this,
        'remaining_qty' => $remaining_qty,
        'unit_price' => $item['unit_price'],
        'discount_amount' => $discount,
        'line_total_inclusive' => $net_total,
        'active_amount' => $active_amount
    ];
}

// Fetch payment history
$payment_stmt = $pdo->prepare("
    SELECT 
        p.payment_amount AS amount,
        p.payment_method,
        p.reference_no,
        p.payment_date,
        p.notes AS payment_note,
        p.created_at AS payment_recorded_at,
        u.full_name AS collected_by
    FROM invoice_payments p
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.invoice_id = ?
    ORDER BY 
        COALESCE(p.payment_date, p.created_at) DESC
");
$payment_stmt->execute([$invoice_id]);
$payment_history = $payment_stmt->fetchAll();

// Calculate total payments received
$total_payments = array_sum(array_column($payment_history, 'amount'));

// Payment status
$pending = $invoice['pending_amount'] ?? 0;
$paid = $invoice['total'] - $pending;
$payment_status = $pending == 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
$status_class = ['paid' => 'success', 'partial' => 'warning', 'unpaid' => 'danger'][$payment_status];

// Get overall discount from invoice
$overall_discount = $invoice['overall_discount'] ?? 0;

// Get shipping details
$shipping_name = $invoice['shipping_name'] ?? '';
$shipping_contact = $invoice['shipping_contact'] ?? '';
$shipping_gstin = $invoice['shipping_gstin'] ?? '';
$shipping_address = $invoice['shipping_address'] ?? '';
$shipping_vehicle_number = $invoice['shipping_vehicle_number'] ?? '';
$shipping_charges = $invoice['shipping_charges'] ?? 0;
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Invoice #" . htmlspecialchars($invoice['invoice_number']); include 'includes/head.php'; ?>
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
                <!-- Debug Section (remove in production) -->
                <?php if(false): /* Set to true to debug */ ?>
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">Debug Info - Raw Data</h5>
                    </div>
                    <div class="card-body">
                        <h6>Raw invoice_items data for invoice_id=<?= $invoice_id ?>:</h6>
                        <pre><?php print_r($debug_items); ?></pre>
                        
                        <h6>Joined items with product data:</h6>
                        <pre><?php print_r($items); ?></pre>
                        
                        <h6>Processed items for display:</h6>
                        <pre><?php print_r($processed_items); ?></pre>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-receipt me-2"></i>
                                    Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?>
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    <i class="bx bx-calendar me-1"></i>
                                    <?= date('d M Y, h:i A', strtotime($invoice['created_at'])) ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="invoice_print.php?invoice_id=<?php echo $invoice['id']; ?>" 
                                   class="btn btn-outline-secondary" 
                                   target="_blank">
                                    <i class="bx bx-printer me-1"></i> Print
                                </a>
                                <a href="invoices.php" class="btn btn-outline-primary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Invoices
                                </a>
                            </div>
                        </div>
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

                <!-- Stats Cards -->
                <div class="row mb-4 g-3">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Invoice Total<br><small>(GST Inclusive)</small></h6>
                                <h3 class="mb-0 text-primary">₹<?= number_format($invoice['total'], 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Active Total<br><small>(After Returns)</small></h6>
                                <h3 class="mb-0 text-success">₹<?= number_format($active_total, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Pending Amount</h6>
                                <h3 class="mb-0 text-warning">₹<?= number_format($pending, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Overall Discount</h6>
                                <h3 class="mb-0 text-info">₹<?= number_format($overall_discount, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Summary -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-3">Customer</h6>
                                <strong><?= htmlspecialchars($invoice['customer_name'] ?? 'Walk-in Customer') ?></strong><br>
                                <?php if ($invoice['customer_phone']): ?>
                                <i class="bx bx-phone me-1"></i><?= htmlspecialchars($invoice['customer_phone']) ?><br>
                                <?php endif; ?>
                                <?php if ($invoice['customer_gstin']): ?>
                                GSTIN: <?= htmlspecialchars($invoice['customer_gstin']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <h6 class="fw-bold mb-3">Shop & Seller</h6>
                                <strong><?= htmlspecialchars($invoice['shop_name'] ?? 'Main Shop') ?></strong><br>
                                Seller: <?= htmlspecialchars($invoice['seller_name'] ?? 'N/A') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shipping Details Card -->
                <?php if (!empty($shipping_name) || !empty($shipping_contact) || !empty($shipping_address) || !empty($shipping_vehicle_number) || $shipping_charges > 0): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info bg-opacity-10 border-bottom border-info">
                        <h5 class="mb-0">
                            <i class="bx bx-truck me-2 text-info"></i> 
                            Shipping Details
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php if (!empty($shipping_name)): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="d-flex align-items-start gap-2 p-2 bg-light rounded">
                                    <i class="bx bx-user fs-4 text-info"></i>
                                    <div>
                                        <small class="text-muted d-block">Receiver Name</small>
                                        <strong><?= htmlspecialchars($shipping_name) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($shipping_contact)): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="d-flex align-items-start gap-2 p-2 bg-light rounded">
                                    <i class="bx bx-phone fs-4 text-info"></i>
                                    <div>
                                        <small class="text-muted d-block">Contact Number</small>
                                        <strong><?= htmlspecialchars($shipping_contact) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($shipping_vehicle_number)): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="d-flex align-items-start gap-2 p-2 bg-light rounded">
                                    <i class="bx bx-truck fs-4 text-info"></i>
                                    <div>
                                        <small class="text-muted d-block">Vehicle Number</small>
                                        <strong><?= htmlspecialchars($shipping_vehicle_number) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($shipping_gstin)): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="d-flex align-items-start gap-2 p-2 bg-light rounded">
                                    <i class="bx bx-id-card fs-4 text-info"></i>
                                    <div>
                                        <small class="text-muted d-block">Shipping GSTIN</small>
                                        <strong><?= htmlspecialchars($shipping_gstin) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($shipping_address)): ?>
                            <div class="col-md-12">
                                <div class="d-flex align-items-start gap-2 p-2 bg-light rounded">
                                    <i class="bx bx-map fs-4 text-info"></i>
                                    <div class="flex-grow-1">
                                        <small class="text-muted d-block">Shipping Address</small>
                                        <strong><?= nl2br(htmlspecialchars($shipping_address)) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($shipping_charges > 0): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="d-flex align-items-start gap-2 p-2 bg-success bg-opacity-10 rounded border border-success">
                                    <i class="bx bx-rupee fs-4 text-success"></i>
                                    <div>
                                        <small class="text-muted d-block">Shipping Charges</small>
                                        <strong class="text-success fs-5">₹<?= number_format($shipping_charges, 2) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment History Card -->
                <?php if (!empty($payment_history)): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bx bx-history me-2"></i> Payment History
                            <span class="badge bg-primary ms-2"><?= count($payment_history) ?> payments</span>
                        </h5>
                        <div class="text-end">
                            <span class="text-success fw-bold">Total Paid: ₹<?= number_format($total_payments, 2) ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Payment Mode</th>
                                        <th>Amount</th>
                                        <th>Collected By</th>
                                        <th>Notes</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment_history as $payment):
                                        $payment_mode = $payment['payment_method'] ?? 'cash';
                                        $mode_class = [
                                            'cash' => 'success',
                                            'upi' => 'primary',
                                            'bank' => 'info',
                                            'cheque' => 'warning',
                                            'other' => 'secondary'
                                        ][$payment_mode] ?? 'secondary';

                                        $pay_date = $payment['payment_date']
                                            ? date('d M Y', strtotime($payment['payment_date']))
                                            : date('d M Y', strtotime($payment['payment_recorded_at']));
                                        $pay_time = $payment['payment_date']
                                            ? date('h:i A', strtotime($payment['payment_date']))
                                            : date('h:i A', strtotime($payment['payment_recorded_at']));
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= $pay_date ?></strong><br>
                                            <small class="text-muted"><?= $pay_time ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $mode_class ?> bg-opacity-10 text-<?= $mode_class ?> px-3 py-1">
                                                <i class="bx bx-<?= $payment_mode == 'upi' ? 'qr' : ($payment_mode == 'bank' ? 'credit-card' : $payment_mode) ?> me-1"></i>
                                                <?= ucfirst(str_replace('_', ' ', $payment_mode)) ?>
                                            </span>
                                        </td>
                                        <td class="text-success fw-bold fs-5">
                                            ₹<?= number_format($payment['amount'], 2) ?>
                                        </td>
                                        <td><?= htmlspecialchars($payment['collected_by'] ?? 'System') ?></td>
                                        <td>
                                            <?php if ($payment['payment_note']): ?>
                                            <small class="text-muted"><?= htmlspecialchars($payment['payment_note']) ?></small>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-success bg-opacity-10 text-success px-3 py-1">
                                                <i class="bx bx-check-circle me-1"></i> Completed
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment Summary Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bx bx-credit-card me-2"></i> Payment Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3">Initial Payment (at invoice time):</h6>
                                <div class="ps-3">
                                    <?php if($invoice['cash_amount'] > 0): ?>
                                    <div class="d-flex justify-content-between py-1">
                                        <span>Cash</span>
                                        <strong class="text-success">₹<?= number_format($invoice['cash_amount'], 2) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if($invoice['upi_amount'] > 0): ?>
                                    <div class="d-flex justify-content-between py-1">
                                        <span>UPI</span>
                                        <strong class="text-primary">₹<?= number_format($invoice['upi_amount'], 2) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if($invoice['bank_amount'] > 0): ?>
                                    <div class="d-flex justify-content-between py-1">
                                        <span>Bank Transfer</span>
                                        <strong class="text-info">₹<?= number_format($invoice['bank_amount'], 2) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if($invoice['cheque_amount'] > 0): ?>
                                    <div class="d-flex justify-content-between py-1">
                                        <span>Cheque</span>
                                        <strong class="text-warning">₹<?= number_format($invoice['cheque_amount'], 2) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if($shipping_charges > 0): ?>
                                    <div class="d-flex justify-content-between py-1 border-top mt-1 pt-1">
                                        <span>Shipping Charges</span>
                                        <strong class="text-secondary">₹<?= number_format($shipping_charges, 2) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if(($invoice['change_given'] ?? 0) > 0): ?>
                                    <div class="d-flex justify-content-between py-1">
                                        <span>Change Given</span>
                                        <strong class="text-success">₹<?= number_format($invoice['change_given'], 2) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3">Payment Overview:</h6>
                                <div class="ps-3">
                                    <div class="d-flex justify-content-between py-2">
                                        <span>Invoice Total:</span>
                                        <strong>₹<?= number_format($invoice['total'], 2) ?></strong>
                                    </div>
                                    <?php if($shipping_charges > 0): ?>
                                    <div class="d-flex justify-content-between py-2 text-secondary">
                                        <span>Including Shipping:</span>
                                        <strong>₹<?= number_format($invoice['total'] - $shipping_charges, 2) ?> + ₹<?= number_format($shipping_charges, 2) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if($total_payments > 0): ?>
                                    <div class="d-flex justify-content-between py-2 text-success">
                                        <span>Additional Payments:</span>
                                        <strong>₹<?= number_format($total_payments, 2) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if($pending > 0): ?>
                                    <div class="d-flex justify-content-between py-2 text-danger">
                                        <span>Pending Amount:</span>
                                        <strong>₹<?= number_format($pending, 2) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <hr>
                                    <div class="d-flex justify-content-between py-2 fw-bold fs-5">
                                        <span>Total Received:</span>
                                        <strong class="text-primary">₹<?= number_format($invoice['total'] - $pending, 2) ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                       
                        <?php if($pending > 0): ?>
                        <div class="alert alert-warning mt-3">
                            <div class="d-flex align-items-center">
                                <i class="bx bx-alarm-exclamation fs-4 me-3"></i>
                                <div>
                                    <strong>Pending Payment: ₹<?= number_format($pending, 2) ?></strong>
                                    <p class="mb-0 mt-1">This invoice has pending amount. You can collect payment using the button below.</p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <a href="collect_payment.php?invoice_id=<?= $invoice_id ?>" class="btn btn-warning">
                                    <i class="bx bx-money me-1"></i> Collect Payment
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-list-ul me-2"></i> Invoice Items (GST Inclusive in MRP)
                            <span class="badge bg-primary ms-2"><?= count($processed_items) ?> items</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th class="text-center">Item ID</th>
                                        <th class="text-center">Quantity & Unit</th>
                                        <th class="text-center">Sold</th>
                                        <th class="text-center">Returned</th>
                                        <th class="text-center">Remaining</th>
                                        <th class="text-end">Rate</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Total (Inclusive)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $item_count = 1;
                                    foreach ($processed_items as $item):
                                        $is_fully_returned = $item['remaining_qty'] <= 0;
                                        $is_partially_returned = $item['returned_qty'] > 0 && $item['remaining_qty'] > 0;
                                        $row_class = $is_fully_returned ? 'table-danger' : ($is_partially_returned ? 'table-warning' : '');
                                    ?>
                                    <tr class="<?= $row_class ?>">
                                        <td><?= $item_count++ ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($item['product_name']) ?></strong><br>
                                            <?php if ($item['product_code']): ?>
                                            <small class="text-muted">Code: <?= htmlspecialchars($item['product_code']) ?></small><br>
                                            <?php endif; ?>
                                            <?php if (!empty($item['hsn_code'])): ?>
                                            <small class="text-muted">HSN: <?= htmlspecialchars($item['hsn_code']) ?></small>
                                            <?php endif; ?>
                                            <?php if ($item['sale_type'] == 'wholesale'): ?>
                                            <br><small class="badge bg-info bg-opacity-10 text-info">Wholesale</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <small class="text-muted">#<?= $item['item_id'] ?></small><br>
                                            <small class="text-muted">PID: <?= $item['product_id'] ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="fw-bold"><?= $item['sold_qty'] ?></span><br>
                                            <small class="text-muted"><?= htmlspecialchars(strtoupper($item['unit'])) ?></small>
                                        </td>
                                        <td class="text-center fw-bold"><?= $item['sold_qty'] ?> <?= htmlspecialchars(strtoupper($item['unit'])) ?></td>
                                        <td class="text-center text-danger fw-bold"><?= $item['returned_qty'] ?> <?= htmlspecialchars(strtoupper($item['unit'])) ?></td>
                                        <td class="text-center text-success fw-bold"><?= $item['remaining_qty'] ?> <?= htmlspecialchars(strtoupper($item['unit'])) ?></td>
                                        <td class="text-end">
                                            ₹<?= number_format($item['unit_price'], 2) ?><br>
                                            <small class="text-muted">per <?= htmlspecialchars(strtoupper($item['unit'])) ?></small>
                                        </td>
                                        <td class="text-end text-danger">-₹<?= number_format($item['discount_amount'] ?? 0, 2) ?></td>
                                        <td class="text-end fw-bold text-primary">₹<?= number_format($item['line_total_inclusive'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-light fw-bold">
                                        <td colspan="9" class="text-end">Subtotal:</td>
                                        <td class="text-end">₹<?= number_format($subtotal, 2) ?></td>
                                    </tr>
                                    <?php if ($overall_discount > 0): ?>
                                    <tr class="table-danger fw-bold">
                                        <td colspan="9" class="text-end">Overall Discount:</td>
                                        <td class="text-end text-danger">-₹<?= number_format($overall_discount, 2) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($shipping_charges > 0): ?>
                                    <tr class="table-info fw-bold">
                                        <td colspan="9" class="text-end">Shipping Charges:</td>
                                        <td class="text-end text-info">+₹<?= number_format($shipping_charges, 2) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr class="table-light fw-bold">
                                        <td colspan="9" class="text-end">Original Invoice Total:</td>
                                        <td class="text-end text-primary">₹<?= number_format($invoice['total'], 2) ?></td>
                                    </tr>
                                    <tr class="table-success fw-bold fs-5">
                                        <td colspan="9" class="text-end">Active Total (After Returns):</td>
                                        <td class="text-end text-success">₹<?= number_format($active_total, 2) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center text-muted mt-3">
                            <small><i class="bx bx-info-circle me-1"></i> All prices are inclusive of GST (as per MRP)</small>
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
<style>
.card-hover {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.card-hover:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important;
}
.border-start {
    border-left-width: 4px !important;
}
.table-danger { background-color: rgba(248, 215, 218, 0.3) !important; }
.table-warning { background-color: rgba(255, 243, 205, 0.3) !important; }
.table-info { background-color: rgba(13, 202, 240, 0.1) !important; }
.avatar-sm {
    width: 48px;
    height: 48px;
}
.badge.bg-opacity-10 {
    opacity: 0.9;
}
.bg-info.bg-opacity-10 {
    background-color: rgba(13, 202, 240, 0.1) !important;
}
.border-bottom.border-info {
    border-bottom-color: #0dcaf0 !important;
    border-bottom-width: 2px !important;
}
.bg-light.rounded {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.bg-light.rounded:hover {
    transform: translateX(5px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
</style>
<script>
$(document).ready(function() {
    // Auto-close alerts
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});
</script>
</body>
</html>