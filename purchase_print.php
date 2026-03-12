<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check if purchase ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid purchase ID.");
}

$purchase_id = (int)$_GET['id'];
$business_id = $_SESSION['business_id'];

// Fetch purchase details
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            m.name as manufacturer_name,
            m.contact_person as manufacturer_contact,
            m.phone as manufacturer_phone,
            m.email as manufacturer_email,
            m.address as manufacturer_address,
            m.gstin as manufacturer_gstin,
            u.full_name as created_by_name,
            u_received.full_name as received_by_name,
            b.business_name,
            b.owner_name as business_owner,
            b.phone as business_phone,
            b.email as business_email,
            b.address as business_address,
            b.gstin as business_gstin
        FROM purchases p
        LEFT JOIN manufacturers m ON p.manufacturer_id = m.id AND p.business_id = m.business_id
        LEFT JOIN users u ON p.created_by = u.id
        LEFT JOIN users u_received ON p.received_by = u_received.id
        LEFT JOIN businesses b ON p.business_id = b.id
        WHERE p.id = ? AND p.business_id = ?
    ");
    $stmt->execute([$purchase_id, $business_id]);
    $purchase = $stmt->fetch();
    
    if (!$purchase) {
        die("Purchase not found or you don't have permission to view it.");
    }
    
    // Fetch purchase items with GST details
    $stmt = $pdo->prepare("
        SELECT 
            pi.*,
            p.product_name,
            p.product_code,
            p.barcode,
            p.hsn_code,
            p.unit_of_measure,
            p.stock_price,
            p.retail_price,
            p.wholesale_price,
            c.category_name,
            s.subcategory_name,
            g.hsn_code as gst_hsn_code,
            g.cgst_rate,
            g.sgst_rate,
            g.igst_rate
        FROM purchase_items pi
        LEFT JOIN products p ON pi.product_id = p.id AND p.business_id = ?
        LEFT JOIN categories c ON p.category_id = c.id AND c.business_id = ?
        LEFT JOIN subcategories s ON p.subcategory_id = s.id AND s.business_id = ?
        LEFT JOIN gst_rates g ON p.hsn_code = g.hsn_code AND g.business_id = ?
        WHERE pi.purchase_id = ?
        ORDER BY pi.id
    ");
    $stmt->execute([$business_id, $business_id, $business_id, $business_id, $purchase_id]);
    $items = $stmt->fetchAll();
    
    // Calculate totals
    $subtotal = 0;
    $total_cgst = 0;
    $total_sgst = 0;
    $total_igst = 0;
    $total_gst = 0;
    $total_items = count($items);
    $total_quantity = 0;
    
    foreach ($items as $item) {
        $item_total = $item['quantity'] * $item['unit_price'];
        $subtotal += $item_total;
        $total_quantity += $item['quantity'];
        
        // Calculate GST if applicable
        if ($item['cgst_rate'] > 0 || $item['sgst_rate'] > 0 || $item['igst_rate'] > 0) {
            $cgst_amount = ($item_total * $item['cgst_rate']) / 100;
            $sgst_amount = ($item_total * $item['sgst_rate']) / 100;
            $igst_amount = ($item_total * $item['igst_rate']) / 100;
            
            $total_cgst += $cgst_amount;
            $total_sgst += $sgst_amount;
            $total_igst += $igst_amount;
            $total_gst += ($cgst_amount + $sgst_amount + $igst_amount);
        }
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Check if this is a copy/duplicate print
$is_copy = isset($_GET['copy']) && $_GET['copy'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_copy ? 'COPY - ' : '' ?>Print Purchase - <?= htmlspecialchars($purchase['purchase_number']) ?></title>
    <style>
        @media print {
            body {
                margin: 0;
                padding: 0;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                font-size: 12px;
                color: #000;
            }
            
            .no-print {
                display: none !important;
            }
            
            .container {
                width: 100%;
                max-width: 210mm;
                margin: 0 auto;
                padding: 10mm;
            }
            
            .page-break {
                page-break-before: always;
            }
            
            .print-header {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                background: #fff;
                z-index: 1000;
            }
            
            .print-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: #fff;
                z-index: 1000;
            }
        }
        
        @media screen {
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                font-size: 14px;
                color: #333;
                background: #f5f5f5;
                padding: 20px;
            }
            
            .container {
                background: white;
                max-width: 210mm;
                margin: 0 auto;
                padding: 20px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
        }
        
        /* Common styles */
        .container {
            box-sizing: border-box;
        }
        
        /* Header section */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .company-info {
            flex: 1;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .company-address {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
        }
        
        .document-info {
            text-align: right;
        }
        
        .document-title {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .document-number {
            font-size: 14px;
            font-weight: bold;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
        }
        
        /* Copy watermark */
        .copy-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px;
            color: rgba(255,0,0,0.1);
            z-index: 9999;
            pointer-events: none;
            font-weight: bold;
            letter-spacing: 10px;
        }
        
        /* Details section */
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .detail-box {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
        }
        
        .detail-box h3 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 14px;
            color: #2c3e50;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .detail-label {
            font-weight: bold;
            min-width: 140px;
            color: #555;
        }
        
        .detail-value {
            flex: 1;
        }
        
        /* Items table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 11px;
        }
        
        .items-table th {
            background: #2c3e50;
            color: white;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #2c3e50;
        }
        
        .items-table td {
            padding: 6px 8px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        
        .items-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        /* GST Summary */
        .gst-summary {
            width: 50%;
            float: right;
            margin-top: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            background: #f8f9fa;
        }
        
        .gst-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        
        .gst-row.total {
            font-weight: bold;
            border-top: 2px solid #333;
            margin-top: 8px;
            padding-top: 8px;
        }
        
        /* Totals section */
        .totals-section {
            clear: both;
            width: 50%;
            float: right;
            margin-top: 20px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }
        
        .total-row.grand-total {
            font-weight: bold;
            font-size: 16px;
            border-top: 2px solid #333;
            margin-top: 10px;
            padding-top: 12px;
        }
        
        /* Payment status */
        .payment-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-unpaid { background: #dc3545; color: white; }
        .status-partial { background: #ffc107; color: #000; }
        .status-paid { background: #28a745; color: white; }
        
        /* Notes section */
        .notes-section {
            clear: both;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #333;
        }
        
        .notes-section h3 {
            margin-top: 0;
            color: #2c3e50;
        }
        
        /* Footer */
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 11px;
            color: #666;
        }
        
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }
        
        .signature-box {
            width: 250px;
            text-align: center;
            padding-top: 30px;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            width: 100%;
            margin-bottom: 5px;
        }
        
        /* Print controls */
        .print-controls {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: white;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            margin: 5px;
        }
        
        .btn-print {
            background: #007bff;
            color: white;
        }
        
        .btn-print:hover {
            background: #0056b3;
        }
        
        .btn-back {
            background: #6c757d;
            color: white;
        }
        
        .btn-back:hover {
            background: #545b62;
        }
        
        .btn-copy {
            background: #17a2b8;
            color: white;
        }
        
        .btn-copy:hover {
            background: #138496;
        }
        
        /* Page layout for print */
        @page {
            size: A4;
            margin: 10mm;
        }
        
        /* Watermark for unpaid */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(0,0,0,0.1);
            z-index: 9999;
            pointer-events: none;
            font-weight: bold;
        }
        
        .watermark.unpaid { content: "UNPAID"; }
        .watermark.partial { content: "PARTIAL"; }
        .watermark.paid { content: "PAID"; }
        
        /* Summary boxes */
        .summary-boxes {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .summary-box {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            text-align: center;
            background: #f8f9fa;
        }
        
        .summary-value {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
            margin: 5px 0;
        }
        
        .summary-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
    </style>
    <script>
        function printDocument() {
            window.print();
        }
        
        // Auto print if specified
        <?php if (isset($_GET['autoprint'])): ?>
        window.onload = function() {
            window.print();
        }
        <?php endif; ?>
    </script>
</head>
<body>
    <?php if ($is_copy): ?>
    <div class="copy-watermark">COPY</div>
    <?php endif; ?>
    
    <?php if ($purchase['payment_status'] === 'unpaid'): ?>
    <div class="watermark unpaid">UNPAID</div>
    <?php elseif ($purchase['payment_status'] === 'partial'): ?>
    <div class="watermark partial">PARTIAL</div>
    <?php endif; ?>
    
    <div class="no-print print-controls">
        <button class="btn btn-print" onclick="printDocument()">
            <i class="fas fa-print"></i> Print Purchase
        </button>
        <a href="purchases.php" class="btn btn-back">
            <i class="fas fa-arrow-left"></i> Back to Purchases
        </a>
        <a href="purchase_print.php?id=<?= $purchase_id ?>&copy=1&autoprint=1" class="btn btn-copy" target="_blank">
            <i class="fas fa-copy"></i> Print Copy
        </a>
        <?php if ($purchase['payment_status'] !== 'paid'): ?>
        <a href="add_payment.php?type=purchase&id=<?= $purchase_id ?>" class="btn" style="background: #28a745; color: white;">
            <i class="fas fa-money-bill-wave"></i> Add Payment
        </a>
        <?php endif; ?>
    </div>
    
    <div class="container">
        <!-- Header Section -->
        <div class="header">
            <div class="company-info">
                <div class="company-name"><?= htmlspecialchars($purchase['business_name']) ?></div>
                <div class="company-address">
                    <?= nl2br(htmlspecialchars($purchase['business_address'])) ?><br>
                    Phone: <?= htmlspecialchars($purchase['business_phone']) ?><br>
                    Email: <?= htmlspecialchars($purchase['business_email']) ?><br>
                    GSTIN: <?= htmlspecialchars($purchase['business_gstin']) ?>
                </div>
            </div>
            
            <div class="document-info">
                <div class="document-title">PURCHASE ORDER</div>
                <div class="document-number"><?= htmlspecialchars($purchase['purchase_number']) ?></div>
                <?php if ($is_copy): ?>
                <div style="margin-top: 5px; color: #dc3545; font-weight: bold; font-size: 12px;">
                    <i class="fas fa-copy"></i> COPY
                </div>
                <?php endif; ?>
                <div style="margin-top: 10px;">
                    <span class="payment-status status-<?= $purchase['payment_status'] ?>">
                        <?= strtoupper($purchase['payment_status']) ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Summary Boxes -->
        <div class="summary-boxes">
            <div class="summary-box">
                <div class="summary-label">Total Items</div>
                <div class="summary-value"><?= number_format($total_items) ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Total Quantity</div>
                <div class="summary-value"><?= number_format($total_quantity) ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Subtotal</div>
                <div class="summary-value">₹<?= number_format($subtotal, 2) ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Grand Total</div>
                <div class="summary-value">₹<?= number_format($purchase['total_amount'], 2) ?></div>
            </div>
        </div>
        
        <!-- Details Section -->
        <div class="details-grid">
            <div class="detail-box">
                <h3>Purchase Details</h3>
                <div class="detail-row">
                    <span class="detail-label">Purchase Number:</span>
                    <span class="detail-value"><?= htmlspecialchars($purchase['purchase_number']) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Purchase Date:</span>
                    <span class="detail-value"><?= date('d/m/Y', strtotime($purchase['purchase_date'])) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Reference:</span>
                    <span class="detail-value"><?= htmlspecialchars($purchase['reference'] ?: 'N/A') ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Status:</span>
                    <span class="detail-value">
                        <span class="payment-status status-<?= $purchase['payment_status'] ?>">
                            <?= strtoupper($purchase['payment_status']) ?>
                        </span>
                        <?php if ($purchase['payment_status'] !== 'paid'): ?>
                        <br><small>Paid: ₹<?= number_format($purchase['paid_amount'], 2) ?> | 
                        Due: ₹<?= number_format($purchase['total_amount'] - $purchase['paid_amount'], 2) ?></small>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Created By:</span>
                    <span class="detail-value"><?= htmlspecialchars($purchase['created_by_name']) ?></span>
                </div>
                <?php if ($purchase['received_by']): ?>
                <div class="detail-row">
                    <span class="detail-label">Received By:</span>
                    <span class="detail-value"><?= htmlspecialchars($purchase['received_by_name']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="detail-box">
                <h3>Manufacturer Details</h3>
                <div class="detail-row">
                    <span class="detail-label">Manufacturer:</span>
                    <span class="detail-value"><?= htmlspecialchars($purchase['manufacturer_name']) ?></span>
                </div>
                <?php if ($purchase['manufacturer_contact']): ?>
                <div class="detail-row">
                    <span class="detail-label">Contact Person:</span>
                    <span class="detail-value"><?= htmlspecialchars($purchase['manufacturer_contact']) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value"><?= htmlspecialchars($purchase['manufacturer_phone']) ?></span>
                </div>
                <?php if ($purchase['manufacturer_email']): ?>
                <div class="detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value"><?= htmlspecialchars($purchase['manufacturer_email']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($purchase['manufacturer_gstin']): ?>
                <div class="detail-row">
                    <span class="detail-label">GSTIN:</span>
                    <span class="detail-value"><?= htmlspecialchars($purchase['manufacturer_gstin']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($purchase['manufacturer_address']): ?>
                <div class="detail-row">
                    <span class="detail-label">Address:</span>
                    <span class="detail-value"><?= nl2br(htmlspecialchars($purchase['manufacturer_address'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Items Table -->
        <div style="margin-top: 20px;">
            <h3>Purchase Items</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th width="30">#</th>
                        <th width="80">Code</th>
                        <th width="150">Product Name</th>
                        <th width="100">Category</th>
                        <th width="80" class="text-center">Qty</th>
                        <th width="60">Unit</th>
                        <th width="80" class="text-right">Unit Price</th>
                        <th width="80" class="text-right">Total</th>
                        <th width="80">HSN Code</th>
                        <th width="70" class="text-right">CGST %</th>
                        <th width="70" class="text-right">SGST %</th>
                        <th width="70" class="text-right">IGST %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="12" class="text-center">No items found</td>
                    </tr>
                    <?php else: ?>
                    <?php $counter = 1; ?>
                    <?php foreach ($items as $item): ?>
                    <?php
                        $item_total = $item['quantity'] * $item['unit_price'];
                    ?>
                    <tr>
                        <td class="text-center"><?= $counter++ ?></td>
                        <td><?= htmlspecialchars($item['product_code'] ?? 'N/A') ?></td>
                        <td>
                            <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                            <?php if ($item['barcode']): ?>
                            <br><small style="color: #666;">Barcode: <?= htmlspecialchars($item['barcode']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($item['category_name'] ?? 'N/A') ?>
                            <?php if ($item['subcategory_name']): ?>
                            <br><small><?= htmlspecialchars($item['subcategory_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= number_format($item['quantity']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($item['unit_of_measure']) ?></td>
                        <td class="text-right">₹<?= number_format($item['unit_price'], 2) ?></td>
                        <td class="text-right">₹<?= number_format($item_total, 2) ?></td>
                        <td class="text-center"><?= htmlspecialchars($item['hsn_code'] ?: $item['gst_hsn_code'] ?: '-') ?></td>
                        <td class="text-right"><?= $item['cgst_rate'] > 0 ? number_format($item['cgst_rate'], 2) . '%' : '-' ?></td>
                        <td class="text-right"><?= $item['sgst_rate'] > 0 ? number_format($item['sgst_rate'], 2) . '%' : '-' ?></td>
                        <td class="text-right"><?= $item['igst_rate'] > 0 ? number_format($item['igst_rate'], 2) . '%' : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- GST Summary -->
        <?php if ($total_gst > 0): ?>
        <div class="gst-summary">
            <h3 style="margin-top: 0; margin-bottom: 10px;">GST Summary</h3>
            <div class="gst-row">
                <span>Taxable Value:</span>
                <span>₹<?= number_format($subtotal, 2) ?></span>
            </div>
            <?php if ($total_cgst > 0): ?>
            <div class="gst-row">
                <span>CGST Total:</span>
                <span>₹<?= number_format($total_cgst, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($total_sgst > 0): ?>
            <div class="gst-row">
                <span>SGST Total:</span>
                <span>₹<?= number_format($total_sgst, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($total_igst > 0): ?>
            <div class="gst-row">
                <span>IGST Total:</span>
                <span>₹<?= number_format($total_igst, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="gst-row total">
                <span>Total GST:</span>
                <span>₹<?= number_format($total_gst, 2) ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Totals Section -->
        <div class="totals-section">
            <div class="total-row">
                <span>Subtotal:</span>
                <span>₹<?= number_format($subtotal, 2) ?></span>
            </div>
            <?php if ($total_gst > 0): ?>
            <div class="total-row">
                <span>Total GST:</span>
                <span>₹<?= number_format($total_gst, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="total-row grand-total">
                <span>Grand Total:</span>
                <span>₹<?= number_format($purchase['total_amount'], 2) ?></span>
            </div>
            <?php if ($purchase['payment_status'] !== 'paid'): ?>
            <div class="total-row" style="color: #dc3545;">
                <span>Amount Due:</span>
                <span>₹<?= number_format($purchase['total_amount'] - $purchase['paid_amount'], 2) ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Notes Section -->
        <?php if ($purchase['notes']): ?>
        <div class="notes-section">
            <h3>Notes & Instructions</h3>
            <div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f8f9fa; margin-top: 10px;">
                <?= nl2br(htmlspecialchars($purchase['notes'])) ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Footer and Signatures -->
        <div class="footer">
            <div style="margin-bottom: 20px; font-size: 10px; color: #999;">
                <strong>Terms & Conditions:</strong><br>
                1. Goods must be in perfect condition as per specifications.<br>
                2. Delivery must be made on or before agreed date.<br>
                3. Payment terms as per purchase agreement.<br>
                4. Defective goods will be returned at supplier's cost.<br>
                5. All disputes subject to jurisdiction of the court at business location.
            </div>
            
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div style="font-weight: bold;">Prepared By</div>
                    <div style="font-size: 10px; color: #666;"><?= htmlspecialchars($purchase['created_by_name']) ?></div>
                </div>
                
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div style="font-weight: bold;">Approved By</div>
                    <div style="font-size: 10px; color: #666;">Authorized Signatory</div>
                </div>
                
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div style="font-weight: bold;">Manufacturer's Seal & Signature</div>
                    <div style="font-size: 10px; color: #666;">With Date</div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 30px; color: #999; font-size: 10px;">
                <?php if ($is_copy): ?>
                <strong style="color: #dc3545;">THIS IS A COPY - NOT AN ORIGINAL DOCUMENT</strong><br>
                <?php endif; ?>
                Generated on: <?= date('d/m/Y H:i:s') ?> | 
                Purchase ID: <?= $purchase_id ?> | 
                Business ID: <?= $business_id ?> |
                Original Amount: ₹<?= number_format($purchase['total_amount'], 2) ?>
            </div>
        </div>
    </div>
    
    <script>
        // Add page numbers for print
        document.addEventListener('DOMContentLoaded', function() {
            if (window.print) {
                // Auto-print if requested
                <?php if (isset($_GET['autoprint'])): ?>
                setTimeout(function() {
                    window.print();
                }, 500);
                <?php endif; ?>
            }
        });
    </script>
</body>
</html>