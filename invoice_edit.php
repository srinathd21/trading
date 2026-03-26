<?php
// invoice_edit.php - Edit existing invoice
session_start();
require_once 'config/database.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// Check if user can edit invoices (admin or shop_manager only)
$can_edit = in_array($user_role, ['admin', 'shop_manager']);

if (!$can_edit) {
    $_SESSION['error'] = "You don't have permission to edit invoices";
    header('Location: invoices.php');
    exit();
}

$invoice_id = (int)($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    $_SESSION['error'] = "Invalid invoice ID";
    header('Location: invoices.php');
    exit();
}

// Fetch invoice details
$stmt = $pdo->prepare("
    SELECT i.*,
           c.name as customer_name,
           c.phone as customer_phone,
           c.address as customer_address,
           c.gstin as customer_gstin,
           c.id as customer_id,
           u.full_name as seller_name
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.seller_id = u.id
    WHERE i.id = ? AND i.business_id = ?
");
$stmt->execute([$invoice_id, $business_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    $_SESSION['error'] = "Invoice not found";
    header('Location: invoices.php');
    exit();
}

// Check shop permission
if ($user_role !== 'admin' && $invoice['shop_id'] != $current_shop_id) {
    $_SESSION['error'] = "You don't have permission to edit this invoice";
    header('Location: invoices.php');
    exit();
}

// Fetch invoice items with correct GST joins
$items_stmt = $pdo->prepare("
    SELECT 
        ii.*,
        p.product_name,
        p.product_code,
        p.unit_of_measure,
        p.secondary_unit,
        p.sec_unit_conversion,
        p.sec_unit_extra_charge,
        p.sec_unit_price_type,
        p.stock_price,
        p.retail_price,
        p.wholesale_price,
        p.hsn_code,
        g.cgst_rate,
        g.sgst_rate,
        g.igst_rate
    FROM invoice_items ii
    LEFT JOIN products p ON ii.product_id = p.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id
");
$items_stmt->execute([$invoice_id]);
$invoice_items = $items_stmt->fetchAll();

// Fetch all products for adding new items
$products_stmt = $pdo->prepare("
    SELECT p.id, p.product_name, p.product_code, p.unit_of_measure, 
           p.retail_price, p.wholesale_price, p.hsn_code,
           g.cgst_rate, g.sgst_rate, g.igst_rate
    FROM products p
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    WHERE p.business_id = ? AND p.is_active = 1
    ORDER BY p.product_name
");
$products_stmt->execute([$business_id]);
$all_products = $products_stmt->fetchAll();

// Fetch customers for dropdown
$customers_stmt = $pdo->prepare("SELECT id, name, phone, address, gstin FROM customers WHERE business_id = ? ORDER BY name");
$customers_stmt->execute([$business_id]);
$customers = $customers_stmt->fetchAll();

// Fetch referrals
$referrals_stmt = $pdo->prepare("SELECT id, full_name FROM referral_person WHERE business_id = ? AND is_active = 1 ORDER BY full_name");
$referrals_stmt->execute([$business_id]);
$referrals = $referrals_stmt->fetchAll();

// Get business settings for GST
$gst_settings = ['is_gst_enabled' => 0, 'is_inclusive' => 1];
try {
    $stmt = $pdo->prepare("
        SELECT is_gst_enabled, is_inclusive
        FROM gst_settings
        WHERE business_id = ? AND (shop_id = ? OR shop_id IS NULL)
        AND status = 'active'
        ORDER BY shop_id DESC
        LIMIT 1
    ");
    $stmt->execute([$business_id, $invoice['shop_id']]);
    $gst = $stmt->fetch();
    if ($gst) {
        $gst_settings['is_gst_enabled'] = (int)$gst['is_gst_enabled'];
        $gst_settings['is_inclusive'] = (int)$gst['is_inclusive'];
    }
} catch (Exception $e) {
    // Use defaults
}

// Calculate totals for display
$subtotal = 0;
$total_discount = 0;
$total_cgst = 0;
$total_sgst = 0;
$total_igst = 0;

foreach ($invoice_items as $item) {
    $line_total = $item['unit_price'] * $item['quantity'];
    $discount = $item['discount_amount'] ?? 0;
    $subtotal += $line_total;
    $total_discount += $discount;
    $total_cgst += $item['cgst_amount'] ?? 0;
    $total_sgst += $item['sgst_amount'] ?? 0;
    $total_igst += $item['igst_amount'] ?? 0;
}

$grand_total = $invoice['total'];
$overall_discount = $invoice['overall_discount'] ?? 0;
$gst_type = $invoice['gst_type'] ?? 'gst';
$price_type = $invoice['price_type'] ?? 'retail';

$page_title = "Edit Invoice #" . htmlspecialchars($invoice['invoice_number']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - POS System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            padding: 0px;
            margin: 0px;
            box-sizing: border-box;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
        }

        body {
            padding: 1px;
            background-color: #f5f7fa;
            padding-bottom: 70px;
        }

        .main-border {
            min-height: 100vh;
            width: 100%;
            border: 1px solid rgb(228, 228, 228);
            padding: 0px 2px 0px;
        }
        
        .main-border > div {
            width: 100%;
            border: 1px solid rgb(223, 223, 223);
            margin-bottom: 1px;
        }

        .center-section {
            min-height: 50vh;
        }

        .bottom-section {
            min-height: 28vh;
            padding: 10px;
        }
        
        .top-section {
            display: flex;
            flex-wrap: wrap;
        }

        .left-container {
            padding: 5px;
            width: 100%;
        }
        
        .right-container {
            padding: 5px;
            width: 100%;
        }
        
        @media (min-width: 992px) {
            .left-container {
                width: 80vw;
            }
            .right-container {
                width: 20vw;
            }
            .top-section {
                flex-wrap: nowrap;
            }
        }
        
        .left-container > div {
            display: flex;
            flex-wrap: wrap;
        }
        
        .invoice-section,
        .customer-section {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .invoice-section > div,
        .customer-section > div {
            flex: 1;
            min-width: 18vw;
        }

        @media (max-width: 768px) {
            .invoice-section > div,
            .customer-section > div {
                min-width: 40vw;
            }
        }
        
        @media (max-width: 576px) {
            .invoice-section > div,
            .customer-section > div {
                min-width: 100%;
            }
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #2c3e50;
        }

        input, select {
            width: 100%;
            padding: 4px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            font-size: 14px;
            background-color: white;
            transition: all 0.2s;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 10px;
        }
        
        .action-buttons > button {
            border: none;
            padding: 4px 6px;
            border-radius: 2px;
            font-size: 10px;
            flex: 1;
            min-width: 60px;
            cursor: pointer;
        }
        
        .action-buttons button:nth-child(1) { background-color: #8b5cf6; color: white; }
        .action-buttons button:nth-child(2) { background-color: #10b981; color: white; }
        .action-buttons button:nth-child(3) { background-color: #3b82f6; color: white; }
        .action-buttons button:nth-child(4) { background-color: #f59e0b; color: white; }
        
        .action-buttons button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .cart-table-container {
            max-height: 40vh;
            width: 100%;
            overflow-x: auto;
            overflow-y: scroll;
        }
        
        .cart-table {
            width: 100%;
            min-width: 1200px;
        }
        
        .table-head {
            background-color: rgb(229, 229, 229);
            font-size: 12px;
            position: sticky;
            top: 0px;
        }
        
        th, td {
            border: 1px solid rgb(225, 225, 225) !important;
            text-align: center;
            display: table-cell;
        }
        
        th { padding: 0px 10px; }
        td { height: 30px; }
        
        .cart-table td > input {
            border: none;
            width: 100%;
            border-radius: 0px;
            padding: 0px 10px;
            height: 100%;
        }
        
        td > select {
            border: none;
            height: 100%;
            border-radius: 0px;
        }
        
        .colm-1 { width: 2vw; padding: 5px; }
        .colm-2 { width: 28vw; }
        .colm-3 { width: 7vw; }
        .colm-4 { width: 7vw; }
        .colm-5 { width: 10vw; }
        .colm-6 { width: 10vw; }
        .colm-7 { width: 5vw; }
        .colm-8 { width: 5vw; }
        .colm-9 { width: 10vw; }
        .colm-10 { width: 10vw; }
        
        /* Add Product Section */
        .add-product-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }
        
        .add-product-section h6 {
            margin-bottom: 10px;
            color: #495057;
            font-size: 13px;
            font-weight: 600;
        }
        
        .product-search-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .product-search-row > div {
            flex: 1;
            min-width: 150px;
        }
        
        .product-search-row label {
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #495057;
        }
        
        .additional-discount {
            display: flex;
            margin-bottom: 5px;
            width: 20vw;
        }
        
        .payment-details {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .payment-method-checkbox {
            display: flex;
            align-items: center;
            width: auto;
            min-width: 70px;
        }
        
        .payment-method-checkbox input[type='checkbox'] {
            width: 15px !important;
            height: 15px;
            margin-right: 5px;
            cursor: pointer;
        }
        
        .payment-method-checkbox label {
            display: inline-block;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            margin-bottom: 0;
        }
        
        .payment-inputs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .payment-input-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            display: none;
        }
        
        .payment-input-card.active {
            display: block;
        }
        
        .total-summary-box {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .total-summary-box .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed #dee2e6;
        }
        
        .total-summary-box .grand-total {
            font-size: 18px;
            color: #0d6efd;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #0d6efd;
        }
        
        .fixed-bottom-buttons {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 10px 15px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            border-top: 1px solid #dee2e6;
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .fixed-bottom-buttons .btn {
            flex: 1;
            max-width: 200px;
            padding: 10px 15px;
            font-size: 14px;
            font-weight: 600;
        }
        
        #btnClose {
            display: inline-block;
            text-decoration: none;
            position: fixed;
            right: 3px;
            top: 3px;
            z-index: 100;
        }
        
        #btnClose button {
            border: none;
            border-radius: 3px;
            padding: 5px 20px;
            background: red;
            color: white;
        }
        
        #required-star {
            color: red;
            font-size: 18px;
            position: absolute;
            margin-top: -5px;
        }
        
        .shipping-summary {
            background-color: #f8f9fa;
            border-left: 3px solid #17a2b8;
            padding: 10px 15px;
            margin-top: 8px;
            border-radius: 6px;
            display: none;
        }
        
        .shipping-summary.show {
            display: block;
        }
        
        .shipping-info-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin: 10px 0;
            border: 1px solid #e9ecef;
        }
        
        .shipping-details-horizontal {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            min-height: 40px;
        }
        
        .shipping-badge-horizontal {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: white;
            border-radius: 30px;
            border: 1px solid #e0e0e0;
            font-size: 12px;
        }
        
        .shipping-charge-badge-horizontal {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-color: #81c784;
        }
        
        .shipping-empty-state {
            color: #adb5bd;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .cart-actions button {
            width: 28px;
            height: 28px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-add-product {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }
        
        .btn-add-product:hover {
            background-color: #218838;
        }
        
        .product-price-info {
            font-size: 10px;
            color: #6c757d;
            margin-top: 3px;
        }
    </style>
</head>
<body>
    <div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999;"></div>
    <a id="btnClose" href="invoices.php"><button><i class="fas fa-x"></i> Close</button></a>
    
    <div class="main-border">
        <div class="top-section">
            <div class="left-container">
                <div class="invoice-section">
                    <div>
                        <label for="invoice-type"><i class="fas fa-file-invoice"></i> Invoice Type</label>
                        <select name="invoice-type" id="invoice-type">
                            <option value="gst" <?= $gst_type == 'gst' ? 'selected' : '' ?>>GST</option>
                            <option value="non-gst" <?= $gst_type == 'non-gst' ? 'selected' : '' ?>>NON GST</option>
                        </select>
                    </div>
                    <div>
                        <label for="invoice-number"><i class="fas fa-hashtag"></i> Invoice Number</label>
                        <input type="text" name="invoice-number" id="invoice-number" value="<?= htmlspecialchars($invoice['invoice_number']) ?>" readonly>
                    </div>
                    <div>
                        <label for="price-type"><i class="fas fa-tag"></i> Price Type</label>
                        <select name="price-type" id="price-type">
                            <option value="retail" <?= $price_type == 'retail' ? 'selected' : '' ?>>Retail</option>
                            <option value="wholesale" <?= $price_type == 'wholesale' ? 'selected' : '' ?>>Wholesale</option>
                        </select>
                    </div>
                    <div>
                        <label for="date"><i class="fas fa-calendar-alt"></i> Date</label>
                        <input type="date" id="date" name="date" value="<?= date('Y-m-d', strtotime($invoice['created_at'])) ?>">
                    </div>
                </div>
                <br>
                <div class="customer-section">
                    <div>
                        <label for="customer-name"><i class="fas fa-user"></i> Customer name <span id="required-star">*</span></label>
                        <input type="text" id="customer-name" name="customer-name" value="<?= htmlspecialchars($invoice['customer_name'] ?? 'Walk-in Customer') ?>" required>
                    </div>
                    <div>
                        <label for="customer-contact"><i class="fas fa-phone"></i> Customer contact <span id="required-star">*</span></label>
                        <select id="customer-contact" name="customer-contact" required>
                            <option value="">-- Select phone --</option>
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?= htmlspecialchars($customer['phone']) ?>" 
                                    data-customer-id="<?= $customer['id'] ?>"
                                    data-name="<?= htmlspecialchars($customer['name']) ?>"
                                    data-address="<?= htmlspecialchars($customer['address'] ?? '') ?>"
                                    data-gstin="<?= htmlspecialchars($customer['gstin'] ?? '') ?>"
                                    <?= ($invoice['customer_phone'] == $customer['phone']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($customer['phone']) ?> - <?= htmlspecialchars($customer['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="customer-address">
                            <i class="fas fa-map-marker-alt"></i> Address
                            <button type="button" id="btnShippingDetails" class="btn btn-sm btn-outline-info ms-2" style="padding: 0px 8px; font-size: 10px;">
                                <i class="fas fa-truck"></i> Shipping
                            </button>
                        </label>
                        <input type="text" id="customer-address" name="customer-address" value="<?= htmlspecialchars($invoice['customer_address'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="customer-gstin"><i class="fas fa-id-card"></i> Gstin</label>
                        <input type="text" name="customer-gstin" id="customer-gstin" value="<?= htmlspecialchars($invoice['customer_gstin'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <div class="right-container">
                <div>
                    <label for="referral"><i class="fas fa-user-friends"></i> Referral</label>
                    <select id="referral" name="referral">
                        <option value="">-- No referral --</option>
                        <?php foreach ($referrals as $referral): ?>
                        <option value="<?= $referral['id'] ?>" <?= ($invoice['referral_id'] == $referral['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($referral['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="action-buttons mt-2">
                    <button id="btnClearCart" class="bg-danger text-white"><i class="fas fa-trash me-1"></i> Clear All</button>
                    <button id="btnRemoveSelected" class="bg-warning text-white"><i class="fas fa-trash-alt me-1"></i> Remove Selected</button>
                </div>
            </div>
        </div>
        
        <div class="center-section">
            <!-- Add Product Section -->
            <div class="add-product-section">
                <h6><i class="fas fa-plus-circle me-2"></i> Add New Product</h6>
                <div class="product-search-row">
                    <div style="flex: 2;">
                        <label for="new-product-search">Search Product</label>
                        <select id="new-product-search" class="form-select" style="width: 100%;">
                            <option value="">-- Search and select product --</option>
                            <?php foreach ($all_products as $product): ?>
                            <option value="<?= $product['id'] ?>"
                                    data-name="<?= htmlspecialchars($product['product_name']) ?>"
                                    data-code="<?= htmlspecialchars($product['product_code'] ?? '') ?>"
                                    data-unit="<?= htmlspecialchars($product['unit_of_measure'] ?? 'PCS') ?>"
                                    data-retail="<?= $product['retail_price'] ?>"
                                    data-wholesale="<?= $product['wholesale_price'] ?>"
                                    data-hsn="<?= htmlspecialchars($product['hsn_code'] ?? '') ?>"
                                    data-cgst="<?= $product['cgst_rate'] ?? 0 ?>"
                                    data-sgst="<?= $product['sgst_rate'] ?? 0 ?>"
                                    data-igst="<?= $product['igst_rate'] ?? 0 ?>">
                                <?= htmlspecialchars($product['product_name']) ?> (<?= htmlspecialchars($product['product_code'] ?? 'No Code') ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="new-product-qty">Quantity</label>
                        <input type="number" id="new-product-qty" class="form-control" value="1" min="0.01" step="0.01">
                    </div>
                    <div>
                        <label for="new-product-unit">Unit</label>
                        <input type="text" id="new-product-unit" class="form-control" readonly>
                    </div>
                    <div>
                        <label for="new-product-price">Price (₹)</label>
                        <input type="number" id="new-product-price" class="form-control" step="0.01">
                    </div>
                    <div>
                        <label for="new-product-discount">Discount</label>
                        <div class="d-flex gap-1">
                            <input type="number" id="new-product-discount" class="form-control" value="0" step="0.01" style="width: 70px;">
                            <select id="new-product-discount-type" class="form-select" style="width: 60px;">
                                <option value="percentage">%</option>
                                <option value="fixed">₹</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <button id="btnAddNewProduct" class="btn-add-product">
                            <i class="fas fa-plus me-1"></i> Add
                        </button>
                    </div>
                </div>
                <div id="new-product-info" class="product-price-info mt-2" style="display: none;"></div>
            </div>
            
            <h6>Edit Cart Items</h6>
            <div class="products-section">
                <div class="cart-table-container" id="cartTableContainer">
                    <table class="cart-table" id="cartTable">
                        <thead>
                            <tr class="table-head">
                                <th class="colm-1"><input type="checkbox" id="selectAllCheckbox"></th>
                                <th class="colm-2">Product</th>
                                <th class="colm-3">Qty</th>
                                <th class="colm-4">Unit</th>
                                <th class="colm-5">Price type</th>
                                <th class="colm-6">Discount</th>
                                <th class="colm-7">Price</th>
                                <th class="colm-8">GST</th>
                                <th class="colm-9">Total</th>
                                <th class="colm-10">Action</th>
                              </tr>
                        </thead>
                        <tbody id="cartBody">
                            <?php 
                            $item_index = 0;
                            foreach ($invoice_items as $item):
                                $item_index++;
                                $item_total = $item['unit_price'] * $item['quantity'];
                                $discount_amount = $item['discount_amount'] ?? 0;
                                $total_gst_rate = ($item['cgst_rate'] ?? 0) + ($item['sgst_rate'] ?? 0) + ($item['igst_rate'] ?? 0);
                            ?>
                            <tr class="cart-item-row" data-item-id="<?= $item['id'] ?>" data-product-id="<?= $item['product_id'] ?>">
                                <td class="text-center"><input type="checkbox" class="item-checkbox" value="<?= $item['id'] ?>"></td>
                                <td class="text-start">
                                    <strong><?= htmlspecialchars($item['product_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($item['product_code'] ?? '') ?></small>
                                    <input type="hidden" class="product-name" value="<?= htmlspecialchars($item['product_name']) ?>">
                                    <input type="hidden" class="product-code" value="<?= htmlspecialchars($item['product_code'] ?? '') ?>">
                                    <input type="hidden" class="hsn-code" value="<?= htmlspecialchars($item['hsn_code'] ?? '') ?>">
                                    <input type="hidden" class="cgst-rate" value="<?= $item['cgst_rate'] ?? 0 ?>">
                                    <input type="hidden" class="sgst-rate" value="<?= $item['sgst_rate'] ?? 0 ?>">
                                    <input type="hidden" class="igst-rate" value="<?= $item['igst_rate'] ?? 0 ?>">
                                </td>
                                <td>
                                    <input type="number" class="form-control form-control-sm text-center item-qty" 
                                           value="<?= $item['quantity'] ?>" 
                                           min="0.01" step="0.01"
                                           data-original-qty="<?= $item['quantity'] ?>">
                                </td>
                                <td>
                                    <select class="form-select form-select-sm item-unit">
                                        <option value="<?= htmlspecialchars($item['unit'] ?? 'PCS') ?>" selected>
                                            <?= htmlspecialchars($item['unit'] ?? 'PCS') ?>
                                        </option>
                                    </select>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm item-price-type">
                                        <option value="retail" <?= ($item['sale_type'] ?? 'retail') == 'retail' ? 'selected' : '' ?>>Retail</option>
                                        <option value="wholesale" <?= ($item['sale_type'] ?? 'retail') == 'wholesale' ? 'selected' : '' ?>>Wholesale</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <input type="number" class="form-control form-control-sm text-center item-discount" 
                                               value="<?= $discount_amount ?>" 
                                               min="0" step="0.01"
                                               style="width: 70px;">
                                        <select class="form-select form-select-sm item-discount-type" style="width: 70px;">
                                            <option value="percentage" <?= ($item['discount_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>%</option>
                                            <option value="fixed" <?= ($item['discount_type'] ?? 'percentage') == 'fixed' ? 'selected' : '' ?>>₹</option>
                                        </select>
                                    </div>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm text-center item-price" 
                                           value="<?= number_format($item['unit_price'], 2) ?>" readonly
                                           style="background: #f8f9fa;">
                                </td>
                                <td class="text-center"><?= number_format($total_gst_rate, 2) ?>%</td>
                                <td class="text-end item-total">₹<?= number_format($item_total - $discount_amount, 2) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger remove-item" title="Remove item">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($invoice_items)): ?>
                            <tr id="emptyCartRow">
                                <td colspan="10" class="cart-empty text-center py-5">No items in cart</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="bottom-section d-flex justify-content-between">
            <div class="bottom-left-section" style="width: 100%;">
                <div>
                    <h6>Additional Discount</h6>
                    <div class="additional-discount">
                        <input type="number" name="additional-dis" id="additional-dis" value="<?= $overall_discount ?>" min="0" step="0.01">
                        <select name="overall-discount-type" id="overall-discount-type">
                            <option value="rupees" <?= $invoice['discount_type'] == 'rupees' ? 'selected' : '' ?>>₹</option>
                            <option value="percentage" <?= $invoice['discount_type'] == 'percentage' ? 'selected' : '' ?>>%</option>
                        </select>
                    </div>
                </div>
                
                <div class="shipping-info-section" id="shippingInfoSection">
                    <h6>
                        <i class="fas fa-truck"></i> Shipping Details
                        <button type="button" id="btnEditShippingFromDiscount" class="btn btn-sm btn-outline-info ms-2" style="padding: 2px 8px; font-size: 10px;">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button type="button" id="btnClearShippingFromDiscount" class="btn btn-sm btn-outline-danger ms-1" style="padding: 2px 8px; font-size: 10px;">
                            <i class="fas fa-trash"></i> Clear
                        </button>
                    </h6>
                    <div id="shippingDetailsHorizontal" class="shipping-details-horizontal">
                        <?php 
                        $has_shipping = !empty($invoice['shipping_name']) || !empty($invoice['shipping_address']) || ($invoice['shipping_charges'] ?? 0) > 0;
                        if ($has_shipping):
                        ?>
                        <?php if (!empty($invoice['shipping_name'])): ?>
                        <div class="shipping-badge-horizontal">
                            <i class="fas fa-user"></i>
                            <span class="badge-label">Receiver:</span>
                            <span class="badge-value"><?= htmlspecialchars($invoice['shipping_name']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['shipping_contact'])): ?>
                        <div class="shipping-badge-horizontal">
                            <i class="fas fa-phone"></i>
                            <span class="badge-value"><?= htmlspecialchars($invoice['shipping_contact']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['shipping_vehicle_number'])): ?>
                        <div class="shipping-badge-horizontal">
                            <i class="fas fa-truck"></i>
                            <span class="badge-value"><?= htmlspecialchars($invoice['shipping_vehicle_number']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['shipping_gstin'])): ?>
                        <div class="shipping-badge-horizontal">
                            <i class="fas fa-id-card"></i>
                            <span class="badge-value"><?= htmlspecialchars($invoice['shipping_gstin']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['shipping_address'])): ?>
                        <div class="shipping-badge-horizontal" title="<?= htmlspecialchars($invoice['shipping_address']) ?>">
                            <i class="fas fa-map-marker-alt"></i>
                            <span class="badge-value"><?= htmlspecialchars(substr($invoice['shipping_address'], 0, 40)) ?>...</span>
                        </div>
                        <?php endif; ?>
                        <?php if (($invoice['shipping_charges'] ?? 0) > 0): ?>
                        <div class="shipping-badge-horizontal shipping-charge-badge-horizontal">
                            <i class="fas fa-rupee-sign"></i>
                            <span class="badge-value">₹ <?= number_format($invoice['shipping_charges'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="shipping-empty-state">
                            <i class="fas fa-info-circle"></i> No shipping details added
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div>
                    <h6>Payment Methods</h6>
                    <div class="payment-details">
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="cash-checkbox" value="cash" checked>
                            <label for="cash-checkbox">Cash</label>
                        </div>
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="upi-checkbox" value="upi">
                            <label for="upi-checkbox">UPI</label>
                        </div>
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="bank-checkbox" value="bank">
                            <label for="bank-checkbox">Bank</label>
                        </div>
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="cheque-checkbox" value="cheque">
                            <label for="cheque-checkbox">Cheque</label>
                        </div>
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="credit-checkbox" value="credit">
                            <label for="credit-checkbox">Credit</label>
                        </div>
                    </div>
                </div>
                
                <div class="payment-inputs-grid" id="paymentInputsGrid">
                    <div class="payment-input-card active" id="cash-input-card">
                        <h6><i class="fas fa-money-bill-wave"></i> Cash Payment</h6>
                        <label for="cash-amount">Amount (₹)</label>
                        <input type="number" id="cash-amount" name="cash-amount" value="<?= $invoice['cash_amount'] ?? 0 ?>" min="0" step="0.01">
                    </div>
                    <div class="payment-input-card" id="upi-input-card">
                        <h6><i class="fas fa-mobile-alt"></i> UPI Payment</h6>
                        <label for="upi-amount">Amount (₹)</label>
                        <input type="number" id="upi-amount" name="upi-amount" value="<?= $invoice['upi_amount'] ?? 0 ?>" min="0" step="0.01">
                        <label for="upi-reference" class="mt-2">Reference</label>
                        <input type="text" id="upi-reference" name="upi-reference" value="<?= htmlspecialchars($invoice['upi_reference'] ?? '') ?>">
                    </div>
                    <div class="payment-input-card" id="bank-input-card">
                        <h6><i class="fas fa-university"></i> Bank Transfer</h6>
                        <label for="bank-amount">Amount (₹)</label>
                        <input type="number" id="bank-amount" name="bank-amount" value="<?= $invoice['bank_amount'] ?? 0 ?>" min="0" step="0.01">
                        <label for="bank-reference" class="mt-2">Reference</label>
                        <input type="text" id="bank-reference" name="bank-reference" value="<?= htmlspecialchars($invoice['bank_reference'] ?? '') ?>">
                    </div>
                    <div class="payment-input-card" id="cheque-input-card">
                        <h6><i class="fas fa-money-check"></i> Cheque Payment</h6>
                        <label for="cheque-amount">Amount (₹)</label>
                        <input type="number" id="cheque-amount" name="cheque-amount" value="<?= $invoice['cheque_amount'] ?? 0 ?>" min="0" step="0.01">
                        <label for="cheque-number" class="mt-2">Cheque Number</label>
                        <input type="text" id="cheque-number" name="cheque-number" value="<?= htmlspecialchars($invoice['cheque_number'] ?? '') ?>">
                    </div>
                    <div class="payment-input-card" id="credit-input-card">
                        <h6><i class="fas fa-credit-card"></i> Credit Payment</h6>
                        <label for="credit-amount">Amount (₹)</label>
                        <input type="number" id="credit-amount" name="credit-amount" value="<?= $invoice['credit_amount'] ?? 0 ?>" min="0" step="0.01">
                        <label for="credit-reference" class="mt-2">Reference</label>
                        <input type="text" id="credit-reference" name="credit-reference" value="<?= htmlspecialchars($invoice['credit_reference'] ?? '') ?>">
                    </div>
                </div>
                <div id="paymentDistribution"></div>
            </div>
            
            <div class="bottom-right-section d-flex">
                <div>
                    <div class="total-summary-box">
                        <div class="summary-row">
                            <span class="summary-label">Sub Total:</span>
                            <span class="summary-value" id="subtotal-display">₹ <?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="summary-row" id="item-discount-row" <?= $total_discount > 0 ? '' : 'style="display: none;"' ?>>
                            <span class="summary-label">Item Discount:</span>
                            <span class="summary-value" id="item-discount-display">₹ <?= number_format($total_discount, 2) ?></span>
                        </div>
                        <div class="summary-row" id="overall-discount-row" <?= $overall_discount > 0 ? '' : 'style="display: none;"' ?>>
                            <span class="summary-label">Overall Discount:</span>
                            <span class="summary-value" id="overall-discount-display">₹ <?= number_format($overall_discount, 2) ?></span>
                        </div>
                        <div class="summary-row" id="shipping-charges-row" <?= ($invoice['shipping_charges'] ?? 0) > 0 ? '' : 'style="display: none;"' ?>>
                            <span class="summary-label"><i class="fas fa-truck me-1"></i> Shipping Charges:</span>
                            <span class="summary-value" id="shipping-charges-display">₹ <?= number_format($invoice['shipping_charges'] ?? 0, 2) ?></span>
                        </div>
                        <div class="summary-row grand-total">
                            <span class="summary-label">Grand Total:</span>
                            <span class="summary-value" id="grand-total-display">₹ <?= number_format($grand_total, 2) ?></span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="mb-2">
                            <label for="total-paid">Total Paid</label>
                            <input type="text" id="total-paid" name="total-paid" readonly class="form-control" value="₹ <?= number_format($invoice['paid_amount'] ?? 0, 2) ?>">
                        </div>
                        <div class="mb-2">
                            <label for="change-given">Change Given</label>
                            <input type="text" id="change-given" name="change-given" readonly class="form-control" value="₹ <?= number_format($invoice['change_given'] ?? 0, 2) ?>">
                        </div>
                        <div>
                            <label for="pending-amount">Pending Amount</label>
                            <input type="text" id="pending-amount" name="pending-amount" readonly class="form-control" value="₹ <?= number_format($invoice['pending_amount'] ?? 0, 2) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="fixed-bottom-buttons">
        <button id="btnUpdateInvoice" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> Update Invoice
        </button>
        <button id="btnCancel" class="btn btn-secondary">
            <i class="fas fa-times me-1"></i> Cancel
        </button>
    </div>
    
    <!-- Shipping Details Modal -->
    <div class="modal fade" id="shippingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-truck me-2"></i> Shipping Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="shippingForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="shipping-name" class="form-label"><i class="fas fa-user me-1"></i> Receiver Name</label>
                                <input type="text" class="form-control" id="shipping-name" value="<?= htmlspecialchars($invoice['shipping_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="shipping-contact" class="form-label"><i class="fas fa-phone me-1"></i> Contact Number</label>
                                <input type="text" class="form-control" id="shipping-contact" value="<?= htmlspecialchars($invoice['shipping_contact'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="shipping-gstin" class="form-label"><i class="fas fa-id-card me-1"></i> GSTIN (if any)</label>
                                <input type="text" class="form-control" id="shipping-gstin" value="<?= htmlspecialchars($invoice['shipping_gstin'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="shipping-vehicle" class="form-label"><i class="fas fa-truck me-1"></i> Vehicle Number</label>
                                <input type="text" class="form-control" id="shipping-vehicle" value="<?= htmlspecialchars($invoice['shipping_vehicle_number'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="shipping-address" class="form-label"><i class="fas fa-map-marker-alt me-1"></i> Shipping Address</label>
                            <textarea class="form-control" id="shipping-address" rows="3"><?= htmlspecialchars($invoice['shipping_address'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="shipping-charges" class="form-label"><i class="fas fa-rupee-sign me-1"></i> Shipping Charges</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" class="form-control" id="shipping-charges" value="<?= $invoice['shipping_charges'] ?? 0 ?>" min="0" step="0.01">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-info" id="btnSaveShipping">Save Shipping Details</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
$(document).ready(function() {
    // Initialize Select2 for customer dropdown
    $('#customer-contact').select2({
        placeholder: 'Select or type phone',
        tags: true,
        allowClear: true,
        width: '100%'
    });
    
    $('#referral').select2({
        placeholder: 'Select referral...',
        allowClear: true,
        width: '100%'
    });
    
    // Initialize Select2 for product search
    $('#new-product-search').select2({
        placeholder: 'Search product...',
        allowClear: true,
        width: '100%'
    });
    
    // Product selection handler
    $('#new-product-search').on('change', function() {
        const selected = $(this).find('option:selected');
        const priceType = $('#price-type').val();
        
        if (selected.val()) {
            const productName = selected.data('name');
            const unit = selected.data('unit');
            // Parse prices as floats
            const retailPrice = parseFloat(selected.data('retail')) || 0;
            const wholesalePrice = parseFloat(selected.data('wholesale')) || 0;
            const price = priceType === 'wholesale' ? wholesalePrice : retailPrice;
            
            $('#new-product-unit').val(unit);
            $('#new-product-price').val(price.toFixed(2));
            
            // Show product info
            $('#new-product-info').html(`
                <i class="fas fa-info-circle me-1"></i> 
                ${productName} - ${priceType === 'wholesale' ? 'Wholesale' : 'Retail'} Price: ₹${price.toFixed(2)} per ${unit}
            `).show();
        } else {
            $('#new-product-unit').val('');
            $('#new-product-price').val('');
            $('#new-product-info').hide();
        }
    });
    
    // Update price when price type changes
    $('#price-type').on('change', function() {
        const selected = $('#new-product-search').find('option:selected');
        if (selected.val()) {
            const priceType = $(this).val();
            const retailPrice = parseFloat(selected.data('retail')) || 0;
            const wholesalePrice = parseFloat(selected.data('wholesale')) || 0;
            const price = priceType === 'wholesale' ? wholesalePrice : retailPrice;
            $('#new-product-price').val(price.toFixed(2));
            
            $('#new-product-info').html(`
                <i class="fas fa-info-circle me-1"></i> 
                ${selected.data('name')} - ${priceType === 'wholesale' ? 'Wholesale' : 'Retail'} Price: ₹${price.toFixed(2)} per ${selected.data('unit')}
            `).show();
        }
    });
    
    // Add new product to cart
    $('#btnAddNewProduct').on('click', function() {
        const selected = $('#new-product-search').find('option:selected');
        if (!selected.val()) {
            showToast('Please select a product', 'warning');
            return;
        }
        
        const qty = parseFloat($('#new-product-qty').val()) || 0;
        if (qty <= 0) {
            showToast('Please enter a valid quantity', 'warning');
            return;
        }
        
        const priceType = $('#price-type').val();
        const unitPrice = parseFloat($('#new-product-price').val()) || 0;
        const discountAmount = parseFloat($('#new-product-discount').val()) || 0;
        const discountType = $('#new-product-discount-type').val();
        
        // Calculate final price after discount
        let finalPrice = unitPrice;
        if (discountAmount > 0) {
            if (discountType === 'percentage') {
                finalPrice = unitPrice * (1 - (discountAmount / 100));
            } else {
                finalPrice = unitPrice - discountAmount;
            }
        }
        if (finalPrice < 0) finalPrice = 0;
        
        // Parse GST rates as floats
        const cgstRate = parseFloat(selected.data('cgst')) || 0;
        const sgstRate = parseFloat(selected.data('sgst')) || 0;
        const igstRate = parseFloat(selected.data('igst')) || 0;
        const totalGstRate = cgstRate + sgstRate + igstRate;
        const itemTotal = finalPrice * qty;
        
        // Escape data for HTML
        const productName = escapeHtml(selected.data('name') || '');
        const productCode = escapeHtml(selected.data('code') || '');
        const hsnCode = escapeHtml(selected.data('hsn') || '');
        const unit = escapeHtml(selected.data('unit') || 'PCS');
        
        // Create new row
        const newRow = `
            <tr class="cart-item-row" data-item-id="0" data-product-id="${selected.val()}">
                <td class="text-center"><input type="checkbox" class="item-checkbox" value="0"></td>
                <td class="text-start">
                    <strong>${productName}</strong><br>
                    <small class="text-muted">${productCode}</small>
                    <input type="hidden" class="product-name" value="${productName}">
                    <input type="hidden" class="product-code" value="${productCode}">
                    <input type="hidden" class="hsn-code" value="${hsnCode}">
                    <input type="hidden" class="cgst-rate" value="${cgstRate}">
                    <input type="hidden" class="sgst-rate" value="${sgstRate}">
                    <input type="hidden" class="igst-rate" value="${igstRate}">
                </td>
                <td class="text-center">
                    <input type="number" class="form-control form-control-sm text-center item-qty" 
                           value="${qty}" min="0.01" step="0.01">
                </td>
                <td class="text-center">
                    <select class="form-select form-select-sm item-unit">
                        <option value="${unit}" selected>${unit}</option>
                    </select>
                </td>
                <td class="text-center">
                    <select class="form-select form-select-sm item-price-type">
                        <option value="retail" ${priceType === 'retail' ? 'selected' : ''}>Retail</option>
                        <option value="wholesale" ${priceType === 'wholesale' ? 'selected' : ''}>Wholesale</option>
                    </select>
                </td>
                <td class="text-center">
                    <div class="d-flex align-items-center gap-1">
                        <input type="number" class="form-control form-control-sm text-center item-discount" 
                               value="${discountAmount}" min="0" step="0.01" style="width: 70px;">
                        <select class="form-select form-select-sm item-discount-type" style="width: 70px;">
                            <option value="percentage" ${discountType === 'percentage' ? 'selected' : ''}>%</option>
                            <option value="fixed" ${discountType === 'fixed' ? 'selected' : ''}>₹</option>
                        </select>
                    </div>
                </td>
                <td class="text-center">
                    <input type="text" class="form-control form-control-sm text-center item-price" 
                           value="${finalPrice.toFixed(2)}" readonly style="background: #f8f9fa;">
                </td>
                <td class="text-center">${totalGstRate.toFixed(2)}%</td>
                <td class="text-end item-total">₹${itemTotal.toFixed(2)}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-danger remove-item" title="Remove item">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        
        // Remove empty row if exists
        if ($('#emptyCartRow').length > 0) {
            $('#emptyCartRow').remove();
        }
        
        // Append new row
        $('#cartBody').append(newRow);
        
        // Reset form
        $('#new-product-search').val('').trigger('change');
        $('#new-product-qty').val('1');
        $('#new-product-discount').val('0');
        $('#new-product-discount-type').val('percentage');
        $('#new-product-unit').val('');
        $('#new-product-price').val('');
        $('#new-product-info').hide();
        
        // Update totals
        updateTotals();
        showToast('Product added successfully', 'success');
    });
    
    // Customer selection handler
    $('#customer-contact').on('change', function() {
        const selected = $(this).find('option:selected');
        if (selected.val()) {
            $('#customer-name').val(selected.data('name') || '');
            $('#customer-address').val(selected.data('address') || '');
            $('#customer-gstin').val(selected.data('gstin') || '');
        }
    });
    
    // Trigger change to load customer data
    $('#customer-contact').trigger('change');
    
    // ==================== CART ITEM FUNCTIONS ====================
    
    // Update item total when quantity changes
    $(document).on('input', '.item-qty', function() {
        updateItemTotal($(this).closest('tr'));
    });
    
    // Update item total when price type changes
    $(document).on('change', '.item-price-type', function() {
        updateItemTotal($(this).closest('tr'));
    });
    
    // Update item total when discount changes
    $(document).on('input', '.item-discount', function() {
        updateItemTotal($(this).closest('tr'));
    });
    
    $(document).on('change', '.item-discount-type', function() {
        updateItemTotal($(this).closest('tr'));
    });
    
    function updateItemTotal(row) {
        const qty = parseFloat(row.find('.item-qty').val()) || 0;
        const discountAmount = parseFloat(row.find('.item-discount').val()) || 0;
        const discountType = row.find('.item-discount-type').val();
        
        // Get base price
        let basePrice = parseFloat(row.find('.item-price').val()) || 0;
        
        // Apply discount
        let finalPrice = basePrice;
        if (discountAmount > 0) {
            if (discountType === 'percentage') {
                finalPrice = basePrice * (1 - (discountAmount / 100));
            } else {
                finalPrice = basePrice - discountAmount;
            }
        }
        
        if (finalPrice < 0) finalPrice = 0;
        
        const total = finalPrice * qty;
        row.find('.item-price').val(finalPrice.toFixed(2));
        row.find('.item-total').text('₹' + total.toFixed(2));
        
        updateTotals();
    }
    
    // Remove single item
    $(document).on('click', '.remove-item', function() {
        const row = $(this).closest('tr');
        Swal.fire({
            title: 'Remove Item?',
            text: 'Are you sure you want to remove this item?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, remove it!'
        }).then((result) => {
            if (result.isConfirmed) {
                row.remove();
                updateTotals();
                checkEmptyCart();
            }
        });
    });
    
    // Select all checkbox
    $('#selectAllCheckbox').on('change', function() {
        $('.item-checkbox').prop('checked', $(this).is(':checked'));
    });
    
    // Remove selected items
    $('#btnRemoveSelected').on('click', function() {
        const selected = $('.item-checkbox:checked');
        if (selected.length === 0) {
            showToast('No items selected', 'warning');
            return;
        }
        
        Swal.fire({
            title: 'Remove Selected Items?',
            text: `Are you sure you want to remove ${selected.length} item(s)?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, remove them!'
        }).then((result) => {
            if (result.isConfirmed) {
                selected.each(function() {
                    $(this).closest('tr').remove();
                });
                updateTotals();
                checkEmptyCart();
                showToast(`${selected.length} item(s) removed`, 'success');
            }
        });
    });
    
    // Clear all items
    $('#btnClearCart').on('click', function() {
        if ($('.cart-item-row').length === 0) {
            showToast('Cart is already empty', 'warning');
            return;
        }
        
        Swal.fire({
            title: 'Clear All Items?',
            text: 'Are you sure you want to remove all items from the invoice?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, clear all!'
        }).then((result) => {
            if (result.isConfirmed) {
                $('.cart-item-row').remove();
                updateTotals();
                checkEmptyCart();
                showToast('All items cleared', 'success');
            }
        });
    });
    
    function checkEmptyCart() {
        if ($('.cart-item-row').length === 0 && $('#emptyCartRow').length === 0) {
            $('#cartBody').append('<tr id="emptyCartRow"><td colspan="10" class="cart-empty text-center py-5">No items in cart</td></tr>');
        } else if ($('.cart-item-row').length > 0 && $('#emptyCartRow').length > 0) {
            $('#emptyCartRow').remove();
        }
    }
    
    // Update totals
    function updateTotals() {
        let subtotal = 0;
        let totalDiscount = 0;
        
        $('.cart-item-row').each(function() {
            const qty = parseFloat($(this).find('.item-qty').val()) || 0;
            const price = parseFloat($(this).find('.item-price').val()) || 0;
            const discountAmount = parseFloat($(this).find('.item-discount').val()) || 0;
            const discountType = $(this).find('.item-discount-type').val();
            const itemTotal = price * qty;
            
            subtotal += itemTotal;
            
            if (discountAmount > 0) {
                if (discountType === 'percentage') {
                    totalDiscount += (price * qty * (discountAmount / 100));
                } else {
                    totalDiscount += discountAmount * qty;
                }
            }
        });
        
        $('#subtotal-display').text('₹ ' + subtotal.toFixed(2));
        
        const overallDiscVal = parseFloat($('#additional-dis').val()) || 0;
        const overallDiscType = $('#overall-discount-type').val();
        let overallDiscount = 0;
        
        const subtotalAfterItems = subtotal - totalDiscount;
        
        if (overallDiscVal > 0) {
            if (overallDiscType === 'percentage') {
                overallDiscount = subtotalAfterItems * (overallDiscVal / 100);
            } else {
                overallDiscount = overallDiscVal;
            }
        }
        
        const shippingCharges = parseFloat($('#shipping-charges-display').text().replace('₹', '')) || 0;
        const grandTotal = subtotalAfterItems - overallDiscount + shippingCharges;
        
        if (totalDiscount > 0) {
            $('#item-discount-row').show();
            $('#item-discount-display').text('₹ ' + totalDiscount.toFixed(2));
        } else {
            $('#item-discount-row').hide();
        }
        
        if (overallDiscount > 0) {
            $('#overall-discount-row').show();
            $('#overall-discount-display').text('₹ ' + overallDiscount.toFixed(2));
        } else {
            $('#overall-discount-row').hide();
        }
        
        $('#grand-total-display').text('₹ ' + grandTotal.toFixed(2));
        
        // Update payment summary
        updatePaymentSummary(grandTotal);
    }
    
    function updatePaymentSummary(grandTotal) {
        const cashAmount = parseFloat($('#cash-amount').val()) || 0;
        const upiAmount = parseFloat($('#upi-amount').val()) || 0;
        const bankAmount = parseFloat($('#bank-amount').val()) || 0;
        const chequeAmount = parseFloat($('#cheque-amount').val()) || 0;
        const creditAmount = parseFloat($('#credit-amount').val()) || 0;
        
        const totalPaid = cashAmount + upiAmount + bankAmount + chequeAmount + creditAmount;
        const changeGiven = totalPaid > grandTotal ? totalPaid - grandTotal : 0;
        const pendingAmount = totalPaid < grandTotal ? grandTotal - totalPaid : 0;
        
        $('#total-paid').val('₹ ' + totalPaid.toFixed(2));
        $('#change-given').val('₹ ' + changeGiven.toFixed(2));
        $('#pending-amount').val('₹ ' + pendingAmount.toFixed(2));
    }
    
    // Payment method checkboxes
    $('input[name="payment-method"]').on('change', function() {
        const method = $(this).val();
        const isChecked = $(this).is(':checked');
        const cardId = `${method}-input-card`;
        
        if (isChecked) {
            $(`#${cardId}`).addClass('active');
        } else {
            $(`#${cardId}`).removeClass('active');
            $(`#${method}-amount`).val(0);
        }
        updateTotals();
    });
    
    // Payment amount inputs
    $('#cash-amount, #upi-amount, #bank-amount, #cheque-amount, #credit-amount').on('input', function() {
        updateTotals();
    });
    
    // Additional discount changes
    $('#additional-dis, #overall-discount-type').on('input change', function() {
        updateTotals();
    });
    
    // ==================== SHIPPING FUNCTIONS ====================
    let SHIPPING_DETAILS = {
        name: '<?= addslashes($invoice['shipping_name'] ?? '') ?>',
        contact: '<?= addslashes($invoice['shipping_contact'] ?? '') ?>',
        gstin: '<?= addslashes($invoice['shipping_gstin'] ?? '') ?>',
        address: '<?= addslashes($invoice['shipping_address'] ?? '') ?>',
        vehicle_number: '<?= addslashes($invoice['shipping_vehicle_number'] ?? '') ?>',
        charges: <?= $invoice['shipping_charges'] ?? 0 ?>
    };
    
    function updateShippingDisplay() {
        const hasShipping = SHIPPING_DETAILS.name || SHIPPING_DETAILS.contact || 
                           SHIPPING_DETAILS.address || SHIPPING_DETAILS.vehicle_number || 
                           SHIPPING_DETAILS.charges > 0;
        
        let html = '';
        if (hasShipping) {
            if (SHIPPING_DETAILS.name) {
                html += `<div class="shipping-badge-horizontal">
                    <i class="fas fa-user"></i>
                    <span class="badge-label">Receiver:</span>
                    <span class="badge-value">${escapeHtml(SHIPPING_DETAILS.name)}</span>
                </div>`;
            }
            if (SHIPPING_DETAILS.contact) {
                html += `<div class="shipping-badge-horizontal">
                    <i class="fas fa-phone"></i>
                    <span class="badge-value">${escapeHtml(SHIPPING_DETAILS.contact)}</span>
                </div>`;
            }
            if (SHIPPING_DETAILS.vehicle_number) {
                html += `<div class="shipping-badge-horizontal">
                    <i class="fas fa-truck"></i>
                    <span class="badge-value">${escapeHtml(SHIPPING_DETAILS.vehicle_number)}</span>
                </div>`;
            }
            if (SHIPPING_DETAILS.gstin) {
                html += `<div class="shipping-badge-horizontal">
                    <i class="fas fa-id-card"></i>
                    <span class="badge-value">${escapeHtml(SHIPPING_DETAILS.gstin)}</span>
                </div>`;
            }
            if (SHIPPING_DETAILS.address) {
                const shortAddress = SHIPPING_DETAILS.address.length > 40 ? 
                    SHIPPING_DETAILS.address.substring(0, 40) + '...' : 
                    SHIPPING_DETAILS.address;
                html += `<div class="shipping-badge-horizontal" title="${escapeHtml(SHIPPING_DETAILS.address)}">
                    <i class="fas fa-map-marker-alt"></i>
                    <span class="badge-value">${escapeHtml(shortAddress)}</span>
                </div>`;
            }
            if (SHIPPING_DETAILS.charges > 0) {
                html += `<div class="shipping-badge-horizontal shipping-charge-badge-horizontal">
                    <i class="fas fa-rupee-sign"></i>
                    <span class="badge-value">₹ ${SHIPPING_DETAILS.charges.toFixed(2)}</span>
                </div>`;
                $('#shipping-charges-display').text(`₹ ${SHIPPING_DETAILS.charges.toFixed(2)}`);
                $('#shipping-charges-row').show();
            } else {
                $('#shipping-charges-row').hide();
            }
        } else {
            html = `<div class="shipping-empty-state">
                <i class="fas fa-info-circle"></i> No shipping details added
            </div>`;
            $('#shipping-charges-row').hide();
        }
        
        $('#shippingDetailsHorizontal').html(html);
        updateTotals();
    }
    
    $('#btnShippingDetails, #btnEditShippingFromDiscount').on('click', function() {
        $('#shipping-name').val(SHIPPING_DETAILS.name);
        $('#shipping-contact').val(SHIPPING_DETAILS.contact);
        $('#shipping-gstin').val(SHIPPING_DETAILS.gstin);
        $('#shipping-address').val(SHIPPING_DETAILS.address);
        $('#shipping-vehicle').val(SHIPPING_DETAILS.vehicle_number);
        $('#shipping-charges').val(SHIPPING_DETAILS.charges);
        
        const modal = new bootstrap.Modal(document.getElementById('shippingModal'));
        modal.show();
    });
    
    $('#btnSaveShipping').on('click', function() {
        SHIPPING_DETAILS = {
            name: $('#shipping-name').val().trim(),
            contact: $('#shipping-contact').val().trim(),
            gstin: $('#shipping-gstin').val().trim().toUpperCase(),
            address: $('#shipping-address').val().trim(),
            vehicle_number: $('#shipping-vehicle').val().trim(),
            charges: parseFloat($('#shipping-charges').val()) || 0
        };
        
        updateShippingDisplay();
        
        const modal = bootstrap.Modal.getInstance(document.getElementById('shippingModal'));
        modal.hide();
        
        showToast('Shipping details saved', 'success');
    });
    
    $('#btnClearShippingFromDiscount').on('click', function() {
        Swal.fire({
            title: 'Clear Shipping Details?',
            text: 'Are you sure you want to remove all shipping details?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, clear it!'
        }).then((result) => {
            if (result.isConfirmed) {
                SHIPPING_DETAILS = {
                    name: '',
                    contact: '',
                    gstin: '',
                    address: '',
                    vehicle_number: '',
                    charges: 0
                };
                updateShippingDisplay();
                showToast('Shipping details cleared', 'success');
            }
        });
    });
    
    // Update shipping display on load
    updateShippingDisplay();
    
    // ==================== UPDATE INVOICE ====================
    $('#btnUpdateInvoice').on('click', function() {
        // Collect cart items
        const items = [];
        $('.cart-item-row').each(function() {
            const row = $(this);
            items.push({
                id: row.data('item-id'),
                product_id: row.data('product-id'),
                product_name: row.find('.product-name').val(),
                product_code: row.find('.product-code').val(),
                quantity: parseFloat(row.find('.item-qty').val()) || 0,
                unit: row.find('.item-unit').val(),
                price_type: row.find('.item-price-type').val(),
                unit_price: parseFloat(row.find('.item-price').val()) || 0,
                discount_amount: parseFloat(row.find('.item-discount').val()) || 0,
                discount_type: row.find('.item-discount-type').val(),
                hsn_code: row.find('.hsn-code').val(),
                cgst_rate: parseFloat(row.find('.cgst-rate').val()) || 0,
                sgst_rate: parseFloat(row.find('.sgst-rate').val()) || 0,
                igst_rate: parseFloat(row.find('.igst-rate').val()) || 0
            });
        });
        
        if (items.length === 0) {
            showToast('Cannot update empty invoice. Please add items or cancel.', 'warning');
            return;
        }
        
        const totals = {
            subtotal: parseFloat($('#subtotal-display').text().replace('₹', '')) || 0,
            total_discount: parseFloat($('#item-discount-display').text().replace('₹', '')) || 0,
            overall_discount: parseFloat($('#additional-dis').val()) || 0,
            overall_discount_type: $('#overall-discount-type').val(),
            grand_total: parseFloat($('#grand-total-display').text().replace('₹', '')) || 0
        };
        
        const paymentMethods = [];
        if ($('#cash-checkbox').is(':checked') && parseFloat($('#cash-amount').val()) > 0) paymentMethods.push('cash');
        if ($('#upi-checkbox').is(':checked') && parseFloat($('#upi-amount').val()) > 0) paymentMethods.push('upi');
        if ($('#bank-checkbox').is(':checked') && parseFloat($('#bank-amount').val()) > 0) paymentMethods.push('bank');
        if ($('#cheque-checkbox').is(':checked') && parseFloat($('#cheque-amount').val()) > 0) paymentMethods.push('cheque');
        if ($('#credit-checkbox').is(':checked') && parseFloat($('#credit-amount').val()) > 0) paymentMethods.push('credit');
        
        const paymentData = {
            cash: parseFloat($('#cash-amount').val()) || 0,
            upi: parseFloat($('#upi-amount').val()) || 0,
            bank: parseFloat($('#bank-amount').val()) || 0,
            cheque: parseFloat($('#cheque-amount').val()) || 0,
            credit: parseFloat($('#credit-amount').val()) || 0,
            upi_reference: $('#upi-reference').val(),
            bank_reference: $('#bank-reference').val(),
            cheque_number: $('#cheque-number').val(),
            credit_reference: $('#credit-reference').val()
        };
        
        const invoiceData = {
            invoice_id: <?= $invoice_id ?>,
            customer_name: $('#customer-name').val(),
            customer_phone: $('#customer-contact').val(),
            customer_address: $('#customer-address').val(),
            customer_gstin: $('#customer-gstin').val(),
            invoice_type: $('#invoice-type').val(),
            price_type: $('#price-type').val(),
            date: $('#date').val(),
            referral_id: $('#referral').val(),
            overall_discount: totals.overall_discount,
            overall_discount_type: totals.overall_discount_type,
            shipping_details: SHIPPING_DETAILS,
            payment_methods: paymentMethods,
            payment_details: paymentData,
            items: items,
            totals: totals
        };
        
        Swal.fire({
            title: 'Update Invoice?',
            text: 'Are you sure you want to update this invoice?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'Yes, update it!'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Updating...',
                    text: 'Please wait while we update the invoice.',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.ajax({
                    url: 'update_invoice.php',
                    method: 'POST',
                    data: invoiceData,
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        if (response.success) {
                            Swal.fire({
                                title: 'Success!',
                                text: 'Invoice updated successfully',
                                icon: 'success',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                window.location.href = 'invoice_view.php?invoice_id=<?= $invoice_id ?>';
                            });
                        } else {
                            Swal.fire({
                                title: 'Error!',
                                text: response.message || 'Failed to update invoice',
                                icon: 'error'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.close();
                        Swal.fire({
                            title: 'Error!',
                            text: 'Failed to update invoice. Please try again.',
                            icon: 'error'
                        });
                        console.error('Error:', error);
                    }
                });
            }
        });
    });
    
    $('#btnCancel').on('click', function() {
        window.location.href = 'invoice_view.php?invoice_id=<?= $invoice_id ?>';
    });
    
    function showToast(message, type = 'success') {
        const toastId = 'toast-' + Date.now();
        const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
        
        const toastHtml = `
            <div id="${toastId}" class="toast custom-toast align-items-center border-0 bg-white shadow-sm mb-2" role="alert">
                <div class="d-flex">
                    <div class="toast-body d-flex align-items-center">
                        <i class="fas ${icon} text-${type === 'success' ? 'success' : (type === 'error' ? 'danger' : 'info')} me-2 fs-5"></i>
                        <span class="flex-grow-1">${escapeHtml(message)}</span>
                        <button type="button" class="btn-close btn-close-sm ms-2" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>
        `;
        
        $('#toastContainer').append(toastHtml);
        const toastElement = $(`#${toastId}`);
        setTimeout(() => {
            toastElement.fadeOut(300, function() { $(this).remove(); });
        }, 3000);
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>
</body>
</html>