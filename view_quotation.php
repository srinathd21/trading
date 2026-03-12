<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$quotation_id = $_GET['id'] ?? 0;
$business_id = $_SESSION['business_id'] ?? $_SESSION['current_business_id'] ?? 1;

if (!$quotation_id) {
    header('Location: pos.php');
    exit();
}

// Get quotation details with shop and business info
$stmt = $pdo->prepare("
    SELECT q.*, 
           s.shop_name, s.address as shop_address, s.phone as shop_phone, s.gstin as shop_gstin,
           u.full_name as created_by_name,
           b.business_name, b.address as business_address, b.phone as business_phone, b.gstin as business_gstin
    FROM quotations q
    LEFT JOIN shops s ON q.shop_id = s.id
    LEFT JOIN users u ON q.created_by = u.id
    LEFT JOIN businesses b ON q.business_id = b.id
    WHERE q.id = ? AND q.business_id = ?
");

$stmt->execute([$quotation_id, $business_id]);
$quotation = $stmt->fetch();

if (!$quotation) {
    echo "<script>alert('Quotation not found'); window.location.href='pos.php';</script>";
    exit();
}

// Get quotation items
$itemsStmt = $pdo->prepare("
    SELECT qi.*, 
           p.product_code,
           p.barcode,
           p.hsn_code,
           p.product_name
    FROM quotation_items qi
    LEFT JOIN products p ON qi.product_id = p.id
    WHERE qi.quotation_id = ?
    ORDER BY qi.id
");

$itemsStmt->execute([$quotation_id]);
$items = $itemsStmt->fetchAll();

// Calculate totals from items
$subtotal = 0;
$total_discount = 0;
$total_tax = 0;

foreach ($items as $item) {
    $line_total = $item['quantity'] * $item['unit_price'];
    $discount = $item['discount_type'] == 'percent' 
        ? ($line_total * $item['discount_amount'] / 100)
        : $item['discount_amount'];
    $net = $line_total - $discount;
    
    $subtotal += $line_total;
    $total_discount += $discount;
    
    // Calculate tax if you have tax fields
    if (isset($item['tax_amount'])) {
        $total_tax += $item['tax_amount'];
    }
}

$grand_total = $quotation['grand_total'];
$quotation_date = date('d-m-Y', strtotime($quotation['quotation_date']));
$valid_until = date('d-m-Y', strtotime($quotation['valid_until']));
$created_time = date('h:i A', strtotime($quotation['created_at']));

// Get settings for this shop/business
$shop_id = $quotation['shop_id'] ?? null;

// First try shop-specific settings
$settings_stmt = $pdo->prepare("
    SELECT * FROM invoice_settings 
    WHERE business_id = ? AND shop_id = ?
    LIMIT 1
");
$settings_stmt->execute([$business_id, $shop_id]);
$settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

// If no shop-specific settings, get business default settings
if (!$settings && $shop_id) {
    $settings_stmt = $pdo->prepare("
        SELECT * FROM invoice_settings 
        WHERE business_id = ? AND shop_id IS NULL
        LIMIT 1
    ");
    $settings_stmt->execute([$business_id]);
    $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
}

// If still no settings, get business info as fallback
if (!$settings) {
    $settings = [
        'company_name' => $quotation['business_name'] ?? 'CLASSIC CAR CARE',
        'company_address' => $quotation['business_address'] ?? ($quotation['shop_address'] ?? '111-J, SALEM MAIN ROAD, DHARMAPURI-636705'),
        'company_phone' => $quotation['business_phone'] ?? ($quotation['shop_phone'] ?? '9943701430, 8489755755'),
        'company_email' => '',
        'company_website' => '',
        'gst_number' => $quotation['business_gstin'] ?? ($quotation['shop_gstin'] ?? '33AKDPY5436F1Z2'),
        'pan_number' => '',
        'bank_name' => '',
        'account_number' => '',
        'ifsc_code' => '',
        'branch_name' => '',
        'logo_path' => '',
        'qr_code_path' => '',
        'qr_code_data' => '',
        'invoice_terms' => "1. This quotation is valid until $valid_until\n2. Prices are subject to change without prior notice\n3. Taxes as applicable will be charged extra\n4. Delivery timeline will be confirmed upon order confirmation\n5. Payment terms: 50% advance, 50% before delivery\n6. Goods once sold will not be taken back",
        'invoice_footer' => 'Thank you for your business! We look forward to serving you.',
        'invoice_prefix' => 'QTN'
    ];
}

// Use the quotation notes if available
if (!empty($quotation['notes'])) {
    $settings['invoice_terms'] = htmlspecialchars($quotation['notes']) . "\n\n" . $settings['invoice_terms'];
}

// Prepare shop details
$shop_address = !empty($settings['company_address']) ? $settings['company_address'] : ($quotation['shop_address'] ?? '');
$shop_phone = !empty($settings['company_phone']) ? $settings['company_phone'] : ($quotation['shop_phone'] ?? '');
$shop_gstin = !empty($settings['gst_number']) ? $settings['gst_number'] : ($quotation['shop_gstin'] ?? '');
$company_name = !empty($settings['company_name']) ? $settings['company_name'] : ($quotation['shop_name'] ?? 'CLASSIC CAR CARE');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation - <?php echo htmlspecialchars($quotation['quotation_number']); ?></title>
    <style>
        @media print {
            @page {
                size: A4;
                margin: 10mm;
            }
            body {
                margin: 0;
                padding: 0;
                font-family: 'Arial', sans-serif;
                font-size: 11px;
                line-height: 1.2;
            }
            .no-print {
                display: none !important;
            }
            .invoice-container {
                width: 210mm;
                min-height: 287mm !important;
                margin: 0 auto;
                padding: 4mm;
                box-sizing: border-box;
            }
        }
        
        body {
            font-family: 'Arial', sans-serif;
            font-size: 11px;
            line-height: 1.2;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .invoice-container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 3mm;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            box-sizing: border-box;
            position: relative;
        }
        
        /* Main border around entire invoice */
        .invoice-border {
            border: 2px solid #000;
            padding: 8px;
            height: 100%;
            box-sizing: border-box;
            position: relative;
        }
        
        /* Company header with border and logo */
        .company-header {
            border: 1px solid #000;
            padding: 8px 12px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .logo-section {
            flex-shrink: 0;
            margin-right: 15px;
        }
        
        .company-logo {
            max-height: 70px;
            max-width: 120px;
            display: block;
        }
        
        .company-info {
            flex-grow: 1;
            text-align: center;
        }
        
        .company-name {
            font-size: 22px;
            font-weight: bold;
            color: #000;
            margin-bottom: 4px;
        }
        
        .company-address {
            font-size: 10px;
            color: #333;
            line-height: 1.1;
        }
        
        .company-contact {
            font-size: 10px;
            color: #333;
        }
        
        /* QR code section in header */
        .qr-section {
            flex-shrink: 0;
            margin-left: 15px;
            text-align: center;
        }
        
        .qr-code {
            max-height: 60px;
            max-width: 60px;
            display: block;
        }
        
        /* Invoice info box with border */
        .invoice-info-box {
            border: 1px solid #000;
            padding: 6px 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            background-color: #f8f8f8;
        }
        
        .invoice-info-left,
        .invoice-info-right {
            flex: 1;
        }
        
        .invoice-info-right {
            text-align: right;
        }
        
        .invoice-number {
            font-size: 12px;
            font-weight: bold;
            color: #000;
        }
        
        .invoice-date {
            font-size: 10px;
            color: #333;
        }
        
        /* Title box with border */
        .title-box {
            border: 1px solid #000;
            padding: 6px;
            text-align: center;
            margin-bottom: 10px;
            background-color: #f0f0f0;
        }
        
        .title-text {
            font-size: 16px;
            font-weight: bold;
            color: #000;
        }
        
        /* Details boxes with borders */
        .details-container {
            display: flex;
            margin-bottom: 10px;
        }
        
        .details-box {
            border: 1px solid #000;
            padding: 8px;
            flex: 1;
            min-height: 80px;
        }
        
        .company-details {
            margin-right: 5px;
        }
        
        .customer-details {
            margin-left: 5px;
        }
        
        .section-title {
            font-weight: bold;
            margin-bottom: 6px;
            font-size: 11px;
            color: #000;
            border-bottom: 1px dashed #666;
            padding-bottom: 2px;
        }
        
        .detail-line {
            margin-bottom: 3px;
            line-height: 1.1;
        }
        
        /* Items table with clean borders */
        .items-container {
            border: 1px solid #000;
            margin-bottom: 10px;
            overflow: hidden;
        }
        
        .items-header {
            display: flex;
            border-bottom: 1px solid #000;
            background-color: #f0f0f0;
            font-weight: bold;
        }
        
        .items-body {
            border-top: none;
        }
        
        .item-row {
            display: flex;
            border-bottom: 1px solid #ccc;
        }
        
        .item-row:last-child {
            border-bottom: none;
        }
        
        .header-cell,
        .item-cell {
            padding: 4px 6px;
            text-align: center;
            border-right: 1px solid #ccc;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .header-cell:last-child,
        .item-cell:last-child {
            border-right: none;
        }
        
        .col-1 { width: 4%; }
        .col-2 { width: 30%; text-align: left; }
        .col-3 { width: 8%; }
        .col-4 { width: 6%; }
        .col-5 { width: 10%; }
        .col-6 { width: 10%; }
        .col-7 { width: 8%; }
        .col-8 { width: 12%; }
        .col-9 { width: 12%; }
        
        /* Totals section */
        .totals-container {
            padding: 8px;
            margin-bottom: 10px;
        }
        
        .totals-row {
            display: flex;
            margin-bottom: 4px;
        }
        
        .total-label {
            flex: 3;
            font-weight: bold;
            padding-right: 10px;
            text-align: right;
        }
        
        .total-value {
            flex: 2;
            text-align: right;
            padding-right: 20px;
        }
        
        .total-final {
            font-weight: bold;
            font-size: 12px;
            background-color: #e8f4e8;
            padding: 4px 0;
        }
        
        /* Summary section with borders */
        .summary-container {
            display: flex;
            margin-bottom: 10px;
            gap: 10px;
        }
        
        .summary-left {
            flex: 1;
        }
        
        .summary-right {
            width: 45%;
        }
        
        .summary-box {
            padding: 8px;
        }
        
        .bank-details-box {
            min-height: 60px;
        }
        
        .terms-box {
            min-height: 100px;
        }
        
        /* Signature box with border */
        .signature-box {
            padding: 10px;
            text-align: center;
            margin-top: 15px;
            position: relative;
            min-height: 100px;
        }
        
        .signature-line {
            margin-top: 25px;
            border-top: 1px solid #000;
            width: 200px;
            display: inline-block;
            padding-top: 4px;
        }
        
        /* Merchant QR Code in bottom right corner */
        .merchant-qr {
            position: absolute;
            bottom: 10px;
            right: 10px;
            text-align: center;
        }
        
        .merchant-qr-img {
            max-width: 120px;
            max-height: 120px;
            border: 1px solid #ccc;
            padding: 2px;
            background: white;
        }
        
        .merchant-qr-label {
            font-size: 8px;
            margin-top: 2px;
            font-weight: bold;
        }
        
        /* Footer box with border */
        .footer-box {
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        /* Control buttons */
        .control-buttons {
            text-align: center;
            margin: 20px 0;
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
            background: white;
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .btn {
            padding: 8px 15px;
            margin: 0 5px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #0056b3;
        }
        
        .btn-close {
            background: #dc3545;
        }
        
        .btn-close:hover {
            background: #c82333;
        }
        
        .btn-pos {
            background: #28a745;
        }
        
        .btn-pos:hover {
            background: #218838;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .status-draft { background: #ffc107; color: #000; }
        .status-sent { background: #17a2b8; color: #fff; }
        .status-accepted { background: #28a745; color: #fff; }
        .status-rejected { background: #dc3545; color: #fff; }
        .status-expired { background: #6c757d; color: #fff; }
        
        .validity-info {
            background: #f0f8ff;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #b3d4fc;
            border-radius: 4px;
            text-align: center;
        }
        
        .validity-date {
            font-weight: bold;
            color: <?= strtotime($quotation['valid_until']) < time() ? '#dc3545' : '#28a745' ?>;
        }
        
        .expired-warning {
            color: #dc3545;
            font-weight: bold;
        }
        
        .invoice-footer-container {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-top: 30px;
        }
        
        .left-section {
            flex: 1;
        }
        
        .right-section {
            flex: 1;
            max-width: 400px;
        }
        
        @media (max-width: 768px) {
            .invoice-footer-container {
                flex-direction: column;
            }
            
            .right-section {
                max-width: 100%;
            }
        }
    </style>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body onload="setTimeout(() => window.print(), 800)">
    <div class="control-buttons no-print">
        <button class="btn" onclick="window.print()">
            <i class="fas fa-print"></i> Print Quotation
        </button>
        <a href="pos.php" class="btn btn-pos">
            <i class="fas fa-plus"></i> New Sale
        </a>
        <a href="quotations.php" class="btn" style="background: #17a2b8;">
            <i class="fas fa-list"></i> View Quotations
        </a>
        <button class="btn btn-close" onclick="window.close()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
    
    <div class="invoice-container">
        <div class="invoice-border">
            <!-- Company Header with Logo and QR -->
            <div class="company-header">
                <?php if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])): ?>
                <div class="logo-section">
                    <img src="<?php echo htmlspecialchars($settings['logo_path']); ?>" alt="Company Logo" class="company-logo">
                </div>
                <?php endif; ?>
                
                <div class="company-info">
                    <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                    <div class="company-address"><?php echo htmlspecialchars($shop_address); ?></div>
                    <div class="company-contact">
                        Ph: <?php echo htmlspecialchars($shop_phone); ?>
                        <?php if (!empty($settings['company_email'])): ?>
                            | Email: <?php echo htmlspecialchars($settings['company_email']); ?>
                        <?php endif; ?>
                        | GSTIN: <?php echo htmlspecialchars($shop_gstin); ?>
                    </div>
                </div>
                
                <?php if (!empty($settings['qr_code_path']) && file_exists($settings['qr_code_path'])): ?>
                <div class="qr-section">
                    <img src="<?php echo htmlspecialchars($settings['qr_code_path']); ?>" alt="QR Code" class="qr-code">
                </div>
                <?php endif; ?>
            </div>

            <!-- Quotation Information -->
            <div class="invoice-info-box">
                <div class="invoice-info-left">
                    <div class="invoice-number">Quotation No: <?php echo htmlspecialchars($quotation['quotation_number']); ?></div>
                    <div class="invoice-date">Date: <?php echo $quotation_date; ?> | Time: <?php echo $created_time; ?></div>
                </div>
                <div class="invoice-info-right">
                    <div>Prepared by: <?php echo htmlspecialchars($quotation['created_by_name']); ?></div>
                    <div>
                        Status: 
                        <span class="status-badge status-<?php echo $quotation['status']; ?>">
                            <?php echo strtoupper($quotation['status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Validity Information -->
            <div class="validity-info">
                <div><strong>QUOTATION VALID UNTIL:</strong> 
                    <span class="validity-date">
                        <?php echo $valid_until; ?>
                        <?php if (strtotime($quotation['valid_until']) < time()): ?>
                            <span class="expired-warning"> (EXPIRED)</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Title -->
            <div class="title-box">
                <div class="title-text">QUOTATION</div>
            </div>

            <!-- Company and Customer Details -->
            <div class="details-container">
                <div class="details-box company-details">
                    <div class="section-title">COMPANY DETAILS</div>
                    <div class="detail-line"><strong>Name:</strong> <?php echo htmlspecialchars($company_name); ?></div>
                    <div class="detail-line"><strong>Address:</strong> <?php echo htmlspecialchars($shop_address); ?></div>
                    <div class="detail-line"><strong>Phone:</strong> <?php echo htmlspecialchars($shop_phone); ?></div>
                    <?php if (!empty($settings['company_email'])): ?>
                    <div class="detail-line"><strong>Email:</strong> <?php echo htmlspecialchars($settings['company_email']); ?></div>
                    <?php endif; ?>
                    <div class="detail-line"><strong>GSTIN:</strong> <?php echo htmlspecialchars($shop_gstin); ?></div>
                </div>
                
                <div class="details-box customer-details">
                    <div class="section-title">CUSTOMER DETAILS</div>
                    <div class="detail-line"><strong>Name:</strong> <?php echo htmlspecialchars($quotation['customer_name'] ?? 'Walk-in Customer'); ?></div>
                    <?php if (!empty($quotation['customer_address'])): ?>
                    <div class="detail-line"><strong>Address:</strong> <?php echo nl2br(htmlspecialchars($quotation['customer_address'])); ?></div>
                    <?php endif; ?>
                    <div class="detail-line"><strong>Phone:</strong> <?php echo htmlspecialchars($quotation['customer_phone'] ?? ''); ?></div>
                    <?php if (!empty($quotation['customer_email'])): ?>
                    <div class="detail-line"><strong>Email:</strong> <?php echo htmlspecialchars($quotation['customer_email']); ?></div>
                    <?php endif; ?>
                    <div class="detail-line"><strong>GSTIN:</strong> <?php echo htmlspecialchars($quotation['customer_gstin'] ?? 'N/A'); ?></div>
                </div>
            </div>

            <!-- Items Table -->
            <div class="items-container">
                <div class="items-header">
                    <div class="header-cell col-1">No</div>
                    <div class="header-cell col-2">Product Description</div>
                    <div class="header-cell col-3">HSN</div>
                    <div class="header-cell col-4">Qty</div>
                    <div class="header-cell col-5">Rate</div>
                    <div class="header-cell col-6">Discount</div>
                    <div class="header-cell col-7">Tax</div>
                    <div class="header-cell col-8">Amount</div>
                </div>
                
                <div class="items-body">
                    <?php if (!empty($items)): ?>
                        <?php $counter = 1; ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $line_total = $item['unit_price'] * $item['quantity'];
                            $discount = $item['discount_type'] == 'percent' 
                                ? ($line_total * $item['discount_amount'] / 100)
                                : $item['discount_amount'];
                            $tax_amount = $item['tax_amount'] ?? 0;
                            $item_total = $line_total - $discount + $tax_amount;
                            ?>
                            <div class="item-row">
                                <div class="item-cell col-1"><?php echo $counter++; ?></div>
                                <div class="item-cell col-2" style="text-align: left;">
                                    <strong><?php echo htmlspecialchars($item['product_name']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($item['product_code'] ?? 'N/A'); ?></small>
                                </div>
                                <div class="item-cell col-3"><?php echo htmlspecialchars($item['hsn_code'] ?? ''); ?></div>
                                <div class="item-cell col-4"><?php echo $item['quantity']; ?></div>
                                <div class="item-cell col-5">₹<?php echo number_format($item['unit_price'], 2); ?></div>
                                <div class="item-cell col-6">
                                    <?php if ($discount > 0): ?>
                                        -₹<?php echo number_format($discount, 2); ?>
                                        <?php if ($item['discount_type'] == 'percent'): ?>
                                            <br><small>(<?php echo $item['discount_amount']; ?>%)</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                                <div class="item-cell col-7">
                                    <?php if ($tax_amount > 0): ?>
                                        ₹<?php echo number_format($tax_amount, 2); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                                <div class="item-cell col-8">₹<?php echo number_format($item_total, 2); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="item-row">
                            <div class="item-cell" colspan="8" style="text-align: center; padding: 20px;">
                                No items found in this quotation
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Summary Section -->
            <div class="invoice-footer-container">
                <div class="left-section">
                    <!-- Bank Details -->
                    <?php if (!empty($settings['bank_name']) || !empty($settings['account_number'])): ?>
                    <div class="summary-box bank-details-box">
                        <div class="section-title">Bank Details</div>
                        <?php if (!empty($settings['bank_name'])): ?>
                            <div class="detail-line"><strong>BANK NAME:</strong> <?php echo htmlspecialchars($settings['bank_name']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($settings['account_number'])): ?>
                            <div class="detail-line"><strong>A/C No:</strong> <?php echo htmlspecialchars($settings['account_number']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($settings['ifsc_code'])): ?>
                            <div class="detail-line"><strong>IFSC CODE:</strong> <?php echo htmlspecialchars($settings['ifsc_code']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($settings['branch_name'])): ?>
                            <div class="detail-line"><strong>BRANCH:</strong> <?php echo htmlspecialchars($settings['branch_name']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Terms and Conditions -->
                    <div class="summary-box terms-box">
                        <div class="section-title">Terms and Conditions</div>
                        <?php echo nl2br(htmlspecialchars($settings['invoice_terms'])); ?>
                    </div>
                </div>
                
                <div class="right-section">
                    <!-- Totals -->
                    <div class="totals-container">
                        <div class="totals-row">
                            <div class="total-label">Subtotal:</div>
                            <div class="total-value">₹<?php echo number_format($subtotal, 2); ?></div>
                        </div>
                        <div class="totals-row">
                            <div class="total-label">Total Discount:</div>
                            <div class="total-value">- ₹<?php echo number_format($total_discount, 2); ?></div>
                        </div>
                        <?php if ($total_tax > 0): ?>
                        <div class="totals-row">
                            <div class="total-label">Total Tax:</div>
                            <div class="total-value">₹<?php echo number_format($total_tax, 2); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="totals-row total-final">
                            <div class="total-label">Total Amount:</div>
                            <div class="total-value">₹<?php echo number_format($grand_total, 2); ?></div>
                        </div>
                        
                        <!-- Amount in Words -->
                        <div style="margin-top: 15px; padding: 8px; background: #f9f9f9; border-radius: 4px;">
                            <div style="font-weight: bold; margin-bottom: 5px;">Amount in Words:</div>
                            <div style="font-size: 10px;">
                                <?php
                                function numberToWords($num) {
                                    $ones = array("", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine");
                                    $tens = array("", "Ten", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety");
                                    $teens = array("Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen");
                                    
                                    $words = "";
                                    $num = (int)$num;
                                    
                                    if ($num == 0) return "Zero";
                                    
                                    if ($num >= 1000) {
                                        $thousands = floor($num / 1000);
                                        $words .= numberToWords($thousands) . " Thousand ";
                                        $num %= 1000;
                                    }
                                    
                                    if ($num >= 100) {
                                        $hundreds = floor($num / 100);
                                        $words .= $ones[$hundreds] . " Hundred ";
                                        $num %= 100;
                                    }
                                    
                                    if ($num >= 20) {
                                        $tensDigit = floor($num / 10);
                                        $words .= $tens[$tensDigit] . " ";
                                        $num %= 10;
                                    } elseif ($num >= 10) {
                                        $words .= $teens[$num - 10] . " ";
                                        $num = 0;
                                    }
                                    
                                    if ($num > 0) {
                                        $words .= $ones[$num] . " ";
                                    }
                                    
                                    return trim($words) . " Rupees";
                                }
                                
                                echo ucfirst(numberToWords($grand_total));
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Signature with Merchant QR -->
            <div class="signature-box">
                <div>for <?php echo htmlspecialchars($company_name); ?></div>
                <div class="signature-line">Authorised Signatory</div>
                <div style="margin-top: 4px; font-size: 10px;">E.&.O.E.</div>
                
                <!-- Merchant QR Code in bottom right corner -->
                <?php if (!empty($settings['qr_code_path']) && file_exists($settings['qr_code_path'])): ?>
                <div class="merchant-qr">
                    <img src="<?php echo htmlspecialchars($settings['qr_code_path']); ?>" alt="Merchant QR Code" class="merchant-qr-img">
                    <div class="merchant-qr-label">Scan for Details</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="footer-box">
                <?php echo nl2br(htmlspecialchars($settings['invoice_footer'])); ?><br>
                <small>
                    Quotation generated on: <?php echo date('d-m-Y H:i:s'); ?> | 
                    Shop: <?php echo htmlspecialchars($quotation['shop_name'] ?? 'Main Shop'); ?> |
                    Reference: <?php echo htmlspecialchars($quotation['quotation_number']); ?>
                </small>
            </div>
        </div>
    </div>

    <script>
        // Auto print when page loads
        window.onload = function() {
            setTimeout(() => {
                window.print();
            }, 800);
        };

        // Handle after print event
        window.onafterprint = function() {
            console.log('Printing completed or cancelled');
            // Optionally close window after print
            window.close();
        };
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P or Cmd+P for print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
                return false;
            }
            // Escape key to close
            if (e.key === 'Escape') {
                window.close();
            }
        });
        
        // Make control buttons visible when printing is cancelled
        window.matchMedia('print').addListener(function(mql) {
            if (!mql.matches) {
                // Print dialog closed without printing
                if (document.querySelector('.control-buttons')) {
                    document.querySelector('.control-buttons').style.display = 'block';
                }
            }
        });
    </script>
</body>
</html>