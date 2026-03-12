<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check if purchase request ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid purchase request ID.");
}

$request_id = (int)$_GET['id'];
$business_id = $_SESSION['business_id'];

// Fetch purchase request details
try {
    $stmt = $pdo->prepare("
        SELECT 
            pr.*,
            m.name as manufacturer_name,
            m.contact_person as manufacturer_contact,
            m.phone as manufacturer_phone,
            m.email as manufacturer_email,
            m.address as manufacturer_address,
            m.gstin as manufacturer_gstin,
            u.full_name as requested_by_name,
            b.business_name,
            b.owner_name as business_owner,
            b.phone as business_phone,
            b.email as business_email,
            b.address as business_address,
            b.gstin as business_gstin
        FROM purchase_requests pr
        LEFT JOIN manufacturers m ON pr.manufacturer_id = m.id AND pr.business_id = m.business_id
        LEFT JOIN users u ON pr.requested_by = u.id
        LEFT JOIN businesses b ON pr.business_id = b.id
        WHERE pr.id = ? AND pr.business_id = ?
    ");
    $stmt->execute([$request_id, $business_id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        die("Purchase request not found or you don't have permission to view it.");
    }
    
    // Fetch purchase request items
    $stmt = $pdo->prepare("
        SELECT 
            pri.*,
            p.product_name,
            p.product_code,
            p.hsn_code,
            p.unit_of_measure,
            p.stock_price,
            p.retail_price,
            p.wholesale_price,
            c.category_name,
            s.subcategory_name
        FROM purchase_request_items pri
        LEFT JOIN products p ON pri.product_id = p.id AND p.business_id = ?
        LEFT JOIN categories c ON p.category_id = c.id AND c.business_id = ?
        LEFT JOIN subcategories s ON p.subcategory_id = s.id AND s.business_id = ?
        WHERE pri.purchase_request_id = ?
        ORDER BY pri.id
    ");
    $stmt->execute([$business_id, $business_id, $business_id, $request_id]);
    $items = $stmt->fetchAll();
    
    // Calculate totals
    $total_estimated = 0;
    $total_items = count($items);
    foreach ($items as $item) {
        if ($item['estimated_price']) {
            $total_estimated += $item['estimated_price'] * $item['quantity'];
        }
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Fetch any attached quotations
try {
    $stmt = $pdo->prepare("
        SELECT * FROM manufacturer_quotations 
        WHERE purchase_request_id = ? AND business_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$request_id, $business_id]);
    $quotations = $stmt->fetchAll();
} catch (PDOException $e) {
    $quotations = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Purchase Request - <?= htmlspecialchars($request['request_number']) ?></title>
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
            min-width: 120px;
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
            font-size: 12px;
        }
        
        .items-table th {
            background: #2c3e50;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #2c3e50;
        }
        
        .items-table td {
            padding: 8px 10px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        
        .items-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .items-table tr:hover {
            background: #e9ecef;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        /* Totals section */
        .totals {
            float: right;
            width: 300px;
            margin-top: 20px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }
        
        .total-row.total {
            font-weight: bold;
            font-size: 16px;
            border-top: 2px solid #333;
            margin-top: 5px;
            padding-top: 10px;
        }
        
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
        
        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-draft { background: #6c757d; color: white; }
        .status-sent { background: #17a2b8; color: white; }
        .status-quotation_received { background: #007bff; color: white; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
        
        /* Quotations section */
        .quotations-section {
            margin-top: 40px;
            page-break-before: always;
        }
        
        .quotations-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 11px;
        }
        
        .quotations-table th {
            background: #495057;
            color: white;
            padding: 8px;
            text-align: left;
            border: 1px solid #495057;
        }
        
        .quotations-table td {
            padding: 6px 8px;
            border: 1px solid #ddd;
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
            margin-left: 10px;
        }
        
        .btn-back:hover {
            background: #545b62;
        }
        
        /* Watermark for draft */
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
        
        .watermark.draft { content: "DRAFT"; }
        .watermark.sent { content: "SENT"; }
        .watermark.approved { content: "APPROVED"; }
        
        /* Page layout for print */
        @page {
            size: A4;
            margin: 10mm;
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
    <?php if ($request['status'] === 'draft'): ?>
    <div class="watermark draft">DRAFT</div>
    <?php elseif ($request['status'] === 'approved'): ?>
    <div class="watermark approved">APPROVED</div>
    <?php endif; ?>
    
    <div class="no-print print-controls">
        <button class="btn btn-print" onclick="printDocument()">
            <i class="fas fa-print"></i> Print Document
        </button>
        <a href="purchase_requests.php" class="btn btn-back">
            <i class="fas fa-arrow-left"></i> Back to Purchase Requests
        </a>
        <?php if ($request['status'] === 'sent'): ?>
        <a href="add_quotation.php?request_id=<?= $request_id ?>" class="btn" style="background: #28a745; color: white; margin-left: 10px;">
            <i class="fas fa-file-invoice"></i> Add Quotation
        </a>
        <?php endif; ?>
    </div>
    
    <div class="container">
        <!-- Header Section -->
        <div class="header">
            <div class="company-info">
                <div class="company-name"><?= htmlspecialchars($request['business_name']) ?></div>
                <div class="company-address">
                    <?= nl2br(htmlspecialchars($request['business_address'])) ?><br>
                    Phone: <?= htmlspecialchars($request['business_phone']) ?><br>
                    Email: <?= htmlspecialchars($request['business_email']) ?><br>
                    GSTIN: <?= htmlspecialchars($request['business_gstin']) ?>
                </div>
            </div>
            
            <div class="document-info">
                <div class="document-title">PURCHASE REQUEST</div>
                <div class="document-number"><?= htmlspecialchars($request['request_number']) ?></div>
                <div style="margin-top: 10px;">
                    <span class="status-badge status-<?= $request['status'] ?>">
                        <?= str_replace('_', ' ', $request['status']) ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Details Section -->
        <div class="details-grid">
            <div class="detail-box">
                <h3>Request Details</h3>
                <div class="detail-row">
                    <span class="detail-label">Request Number:</span>
                    <span class="detail-value"><?= htmlspecialchars($request['request_number']) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date:</span>
                    <span class="detail-value"><?= date('d/m/Y', strtotime($request['created_at'])) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span class="status-badge status-<?= $request['status'] ?>">
                            <?= str_replace('_', ' ', $request['status']) ?>
                        </span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Requested By:</span>
                    <span class="detail-value"><?= htmlspecialchars($request['requested_by_name']) ?></span>
                </div>
                <?php if ($request['expected_delivery_date']): ?>
                <div class="detail-row">
                    <span class="detail-label">Expected Delivery:</span>
                    <span class="detail-value"><?= date('d/m/Y', strtotime($request['expected_delivery_date'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="detail-box">
                <h3>Manufacturer Details</h3>
                <div class="detail-row">
                    <span class="detail-label">Manufacturer:</span>
                    <span class="detail-value"><?= htmlspecialchars($request['manufacturer_name']) ?></span>
                </div>
                <?php if ($request['manufacturer_contact']): ?>
                <div class="detail-row">
                    <span class="detail-label">Contact Person:</span>
                    <span class="detail-value"><?= htmlspecialchars($request['manufacturer_contact']) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value"><?= htmlspecialchars($request['manufacturer_phone']) ?></span>
                </div>
                <?php if ($request['manufacturer_email']): ?>
                <div class="detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value"><?= htmlspecialchars($request['manufacturer_email']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($request['manufacturer_gstin']): ?>
                <div class="detail-row">
                    <span class="detail-label">GSTIN:</span>
                    <span class="detail-value"><?= htmlspecialchars($request['manufacturer_gstin']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Items Table -->
        <div style="margin-top: 20px;">
            <h3>Requested Items</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th width="40">#</th>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th width="80" class="text-center">Quantity</th>
                        <th width="80">Unit</th>
                        <th width="100" class="text-right">Est. Price</th>
                        <th width="120" class="text-right">Total Amount</th>
                        <th width="150">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="9" class="text-center">No items found</td>
                    </tr>
                    <?php else: ?>
                    <?php $counter = 1; ?>
                    <?php foreach ($items as $item): ?>
                    <?php
                        $item_total = $item['estimated_price'] * $item['quantity'];
                        $total_estimated += $item_total;
                    ?>
                    <tr>
                        <td class="text-center"><?= $counter++ ?></td>
                        <td><?= htmlspecialchars($item['product_code'] ?? 'N/A') ?></td>
                        <td>
                            <?= htmlspecialchars($item['product_name']) ?><br>
                            <small style="color: #666;">
                                <?php if ($item['category_name']): ?>
                                Cat: <?= htmlspecialchars($item['category_name']) ?>
                                <?php endif; ?>
                                <?php if ($item['subcategory_name']): ?>
                                > <?= htmlspecialchars($item['subcategory_name']) ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <?= htmlspecialchars($item['category_name'] ?? 'N/A') ?>
                            <?php if ($item['subcategory_name']): ?>
                            <br><small><?= htmlspecialchars($item['subcategory_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= number_format($item['quantity']) ?></td>
                        <td><?= htmlspecialchars($item['unit_of_measure']) ?></td>
                        <td class="text-right">₹<?= number_format($item['estimated_price'] ?? 0, 2) ?></td>
                        <td class="text-right">₹<?= number_format($item_total, 2) ?></td>
                        <td><?= htmlspecialchars($item['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7" class="text-right"><strong>Total Estimated Amount:</strong></td>
                        <td class="text-right"><strong>₹<?= number_format($total_estimated, 2) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <!-- Notes Section -->
        <?php if ($request['request_notes']): ?>
        <div class="notes-section">
            <h3>Notes & Instructions</h3>
            <div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f8f9fa; margin-top: 10px;">
                <?= nl2br(htmlspecialchars($request['request_notes'])) ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Quotations Section (if any) -->
        <?php if (!empty($quotations)): ?>
        <div class="quotations-section">
            <h3>Received Quotations</h3>
            <table class="quotations-table">
                <thead>
                    <tr>
                        <th width="150">Quotation No.</th>
                        <th width="100">Date</th>
                        <th width="100">Valid Until</th>
                        <th width="120" class="text-right">Total Amount</th>
                        <th width="100">Status</th>
                        <th width="150">Document</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quotations as $quotation): ?>
                    <tr>
                        <td><?= htmlspecialchars($quotation['quotation_number']) ?></td>
                        <td><?= date('d/m/Y', strtotime($quotation['quotation_date'])) ?></td>
                        <td><?= date('d/m/Y', strtotime($quotation['valid_until'])) ?></td>
                        <td class="text-right">₹<?= number_format($quotation['total_amount'], 2) ?></td>
                        <td>
                            <span style="font-size: 10px; padding: 2px 6px; border-radius: 3px; background: 
                                <?= $quotation['status'] === 'approved' ? '#28a745' : 
                                   ($quotation['status'] === 'pending' ? '#ffc107' : '#dc3545') ?>; 
                                color: white;">
                                <?= ucfirst($quotation['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($quotation['quotation_file']): ?>
                            <a href="<?= htmlspecialchars($quotation['quotation_file']) ?>" target="_blank" style="font-size: 11px;">
                                <i class="fas fa-file-pdf"></i> View Document
                            </a>
                            <?php else: ?>
                            No file attached
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Footer and Signatures -->
        <div class="footer">
            <div style="margin-bottom: 20px; font-size: 10px; color: #999;">
                <strong>Terms & Conditions:</strong><br>
                1. Prices are subject to change without prior notice.<br>
                2. Delivery time is approximate and may vary.<br>
                3. Payment terms as per agreement.<br>
                4. Goods once delivered cannot be returned unless defective.<br>
                5. All disputes subject to jurisdiction of the court at business location.
            </div>
            
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div style="font-weight: bold;">Prepared By</div>
                    <div style="font-size: 10px; color: #666;"><?= htmlspecialchars($request['requested_by_name']) ?></div>
                </div>
                
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div style="font-weight: bold;">Approved By</div>
                    <div style="font-size: 10px; color: #666;">Authorized Signatory</div>
                </div>
                
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div style="font-weight: bold;">Manufacturer's Acceptance</div>
                    <div style="font-size: 10px; color: #666;">Stamp & Signature with Date</div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 30px; color: #999; font-size: 10px;">
                Generated on: <?= date('d/m/Y H:i:s') ?> | 
                Purchase Request ID: <?= $request_id ?> | 
                Business ID: <?= $business_id ?> |
                Page 1 of 1
            </div>
        </div>
    </div>
    
    <script>
        // Add page numbers for print
        document.addEventListener('DOMContentLoaded', function() {
            if (window.print) {
                // Add page numbers for print
                const totalPages = Math.ceil(document.body.scrollHeight / window.innerHeight);
                console.log('Estimated pages:', totalPages);
            }
        });
    </script>
</body>
</html>