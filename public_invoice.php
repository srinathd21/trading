<?php
// public_invoice.php - Public invoice view without login
require_once 'config/database.php';

$invoice_id = (int)($_GET['id'] ?? 0);
$token = $_GET['token'] ?? '';

if (!$invoice_id || !$token) {
    die('Invalid invoice link');
}

// Fetch invoice with token validation
$stmt = $pdo->prepare("
    SELECT i.*,
           c.name as customer_name, c.phone as customer_phone, c.gstin as customer_gstin,
           c.address as customer_address,
           s.shop_name, s.address as shop_address, s.phone as shop_phone, s.gstin as shop_gstin,
           s.id as shop_id,
           u.full_name as seller_name
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN shops s ON i.shop_id = s.id
    LEFT JOIN users u ON i.seller_id = u.id
    WHERE i.id = ? AND i.public_token = ?
");
$stmt->execute([$invoice_id, $token]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die('Invalid or expired invoice link');
}

// Check if token has expired
if (!empty($invoice['token_expiry']) && strtotime($invoice['token_expiry']) < time()) {
    die('This invoice link has expired. Please contact the store for a new link.');
}

// Get shop_id from invoice
$shop_id = $invoice['shop_id'] ?? null;
$business_id = $invoice['business_id'] ?? 1;

// Fetch invoice settings for this shop/business
$settings_stmt = $pdo->prepare("
    SELECT * FROM invoice_settings
    WHERE business_id = ? AND shop_id = ?
    LIMIT 1
");
$settings_stmt->execute([$business_id, $shop_id]);
$settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

// If no shop-specific settings, get business default
if (!$settings && $shop_id) {
    $settings_stmt = $pdo->prepare("
        SELECT * FROM invoice_settings
        WHERE business_id = ? AND shop_id IS NULL
        LIMIT 1
    ");
    $settings_stmt->execute([$business_id]);
    $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
}

// Fallback to business table if no settings
if (!$settings) {
    $business_stmt = $pdo->prepare("
        SELECT business_name, phone, address, gstin
        FROM businesses
        WHERE id = ?
        LIMIT 1
    ");
    $business_stmt->execute([$business_id]);
    $business = $business_stmt->fetch(PDO::FETCH_ASSOC);

    $settings = [
        'company_name' => $business['business_name'] ?? 'CLASSIC CAR CARE',
        'company_address' => $business['address'] ?? '111-J, SALEM MAIN ROAD, DHARMAPURI-636705',
        'company_phone' => $business['phone'] ?? '9943701430, 8489755755',
        'company_email' => '',
        'company_website' => '',
        'gst_number' => $business['gstin'] ?? '33AKDPY5436F1Z2',
        'pan_number' => '',
        'logo_path' => '',
        'qr_code_path' => '',
        'qr_code_data' => '',
        'invoice_terms' => "1. Goods Once Sold will not be taken back or exchanged.\n2. Seller is not responsible for any loss or damage of goods in transit\n3. Buyer Undertake to submit prescribed S.T.dech., to the seller on demand\n4. Dispute if any will be subject to Chennai Court jurisdiction Only.\n5. Certified that the particulars given above are true and correct",
        'invoice_footer' => 'Thank you for your business! Visit Again.',
        'invoice_prefix' => 'INV'
    ];
}

// Get company info from settings
$company_name = $settings['company_name'] ?? 'Ecommer';
$company_address = $settings['company_address'] ?? 'Sogathur X Road, Dharmapuri';
$company_phone = $settings['company_phone'] ?? '9003552650';
$company_gstin = $settings['gst_number'] ?? ($invoice['shop_gstin'] ?? '');
$company_logo = !empty($settings['logo_path']) && file_exists($settings['logo_path']) ? $settings['logo_path'] : '';

// ========== Fetch invoice items ==========
$items_stmt = $pdo->prepare("
    SELECT ii.*, 
           p.product_name, p.product_code, p.hsn_code, p.mrp, p.gst_id,
           g.cgst_rate, g.sgst_rate, g.igst_rate,
           ii.unit as product_unit
    FROM invoice_items ii
    JOIN products p ON ii.product_id = p.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id
");
$items_stmt->execute([$invoice_id]);
$items = $items_stmt->fetchAll();

// ========== Calculate totals ==========
$subtotal = $total_discount = $total_profit = 0;
$total_taxable = $total_cgst = $total_sgst = $total_igst = 0;

foreach ($items as $item) {
    $line_total = $item['unit_price'] * $item['quantity'];
    $discount = $item['discount_amount'] ?? 0;
    $net = $line_total - $discount;

    $subtotal += $line_total;
    $total_discount += $discount;
    $total_profit += $item['profit'] ?? 0;

    $total_taxable += $item['taxable_value'] ?? 0;
    $total_cgst += $item['cgst_amount'] ?? 0;
    $total_sgst += $item['sgst_amount'] ?? 0;
    $total_igst += $item['igst_amount'] ?? 0;
}

// Get overall discount from invoice
$overall_discount = $invoice['overall_discount'] ?? 0;
$grand_total = $invoice['total'];
$is_tax_invoice = !empty($invoice['customer_gstin']) || ($total_cgst + $total_sgst + $total_igst) > 0;
$invoice_date = date('d-m-Y', strtotime($invoice['created_at']));
$invoice_time = date('h:i A', strtotime($invoice['created_at']));

// Payment method from invoice
$payment_method = $invoice['payment_method'] ?? 'Cash';
$payment_status = $invoice['payment_status'] ?? 'Paid';

// Place of supply
$place_of_supply = 'Tamil Nadu (33)';

// Customer details
$customer_name = $invoice['customer_name'] ?? 'Walk-in Customer';
$customer_phone = $invoice['customer_phone'] ?? '';
$customer_address = $invoice['customer_address'] ?? '';
$customer_gstin = $invoice['customer_gstin'] ?? '';

// Helper functions
function money($v) { 
    return number_format((float)$v, 2, '.', ','); 
}

function format_quantity($v) {
    $v = (float)$v;
    if (floor($v) == $v) return number_format($v, 0, '.', '');
    return number_format($v, 2, '.', '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- jsPDF and html2canvas libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Arial', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 20px;
        }
        .invoice-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 30px;
        }
        .invoice-header {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        .invoice-title {
            color: #0d6efd;
            font-weight: 700;
            font-size: 24px;
            margin-bottom: 5px;
        }
        .company-name {
            font-size: 20px;
            font-weight: 700;
            color: #212529;
            margin-bottom: 8px;
        }
        .company-details {
            font-size: 12px;
            color: #6c757d;
            line-height: 1.5;
        }
        .invoice-details {
            font-size: 12px;
            color: #495057;
        }
        .customer-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 25px;
        }
        .customer-title {
            font-weight: 700;
            color: #495057;
            margin-bottom: 10px;
        }
        .table {
            font-size: 11px;
            margin-bottom: 0;
        }
        .table th {
            background-color: #e9ecef;
            font-weight: 700;
            color: #495057;
            padding: 10px 8px;
            vertical-align: middle;
        }
        .table td {
            padding: 8px;
            vertical-align: middle;
        }
        .amount-summary {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .total-row {
            font-size: 16px;
            font-weight: 700;
            border-top: 2px solid #dee2e6;
            padding-top: 10px;
            margin-top: 10px;
        }
        .security-badge {
            display: inline-block;
            padding: 8px 16px;
            background-color: #e8f5e9;
            color: #2e7d32;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
        }
        .footer-note {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            color: #6c757d;
            font-size: 11px;
        }
        .terms-section {
            margin-top: 20px;
            padding: 15px;
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
            font-size: 11px;
        }
        .logo-img {
            max-width: 60px;
            max-height: 60px;
            object-fit: contain;
        }
        .btn-download {
            background-color: #0d6efd;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            border: none;
        }
        .btn-download:hover {
            background-color: #0b5ed7;
            color: white;
        }
        .btn-print {
            background-color: #6c757d;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            border: none;
        }
        .btn-print:hover {
            background-color: #5a6268;
            color: white;
        }
        .gst-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        .badge-payment {
            font-size: 12px;
            padding: 6px 12px;
        }
        .amount-label {
            font-size: 12px;
            color: #6c757d;
        }
        .amount-value {
            font-size: 14px;
            font-weight: 600;
        }
        @media print {
            body {
                background-color: white;
                padding: 0;
            }
            .invoice-container {
                box-shadow: none;
                padding: 15px;
            }
            .btn-print, .btn-download {
                display: none;
            }
            .no-print {
                display: none;
            }
        }
        .text-success-custom {
            color: #198754;
        }
        .text-danger-custom {
            color: #dc3545;
        }
        .border-bottom-custom {
            border-bottom: 1px solid #dee2e6;
        }
        #pdf-loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .pdf-loading-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
        }
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
    </style>
</head>
<body>
    <!-- PDF Loading Overlay -->
    <div id="pdf-loading">
        <div class="pdf-loading-content">
            <div class="spinner-border text-primary mb-3" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h5>Generating PDF...</h5>
            <p class="text-muted mb-0">Please wait while we generate your invoice</p>
        </div>
    </div>

    <div class="invoice-container" id="invoice-content">
        <!-- Header Section - Same as invoice_print.php -->
        <div class="invoice-header">
            <div class="row">
                <div class="col-8">
                    <div class="d-flex align-items-start">
                        <?php if (!empty($company_logo)): ?>
                            <img src="<?= htmlspecialchars($company_logo) ?>" alt="Company Logo" class="logo-img me-3">
                        <?php endif; ?>
                        <div>
                            <h1 class="invoice-title">TAX INVOICE</h1>
                            <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
                            <div class="company-details">
                                <?= nl2br(htmlspecialchars($company_address)) ?><br>
                                Phone: <?= htmlspecialchars($company_phone) ?><br>
                                GSTIN: <?= htmlspecialchars($company_gstin) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="invoice-details text-end">
                        <div class="mb-2">
                            <strong class="text-primary">#<?= htmlspecialchars($invoice['invoice_number']) ?></strong>
                        </div>
                        <div class="mb-1">
                            <small class="text-muted">Invoice Date:</small><br>
                            <strong><?= $invoice_date ?></strong>
                        </div>
                        <div class="mb-1">
                            <small class="text-muted">Payment Mode:</small><br>
                            <strong><?= htmlspecialchars($payment_method) ?></strong>
                        </div>
                        <div class="mb-1">
                            <span class="badge bg-success badge-payment">
                                <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($payment_status) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- GSTIN and Place of Supply -->
            <div class="row mt-3">
                <div class="col-6">
                    <small class="text-muted">GSTIN:</small>
                    <strong><?= htmlspecialchars($company_gstin) ?></strong>
                </div>
                <div class="col-6 text-end">
                    <small class="text-muted">Place of Supply:</small>
                    <strong><?= htmlspecialchars($place_of_supply) ?></strong>
                </div>
            </div>
        </div>

        <!-- Customer Details Section -->
        <div class="customer-section">
            <h6 class="customer-title">
                <i class="bi bi-person-circle me-2"></i> Bill To
            </h6>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-1">
                        <strong><?= htmlspecialchars($customer_name) ?></strong>
                    </div>
                    <?php if (!empty($customer_phone)): ?>
                        <div class="mb-1">
                            <i class="bi bi-telephone me-1 text-muted"></i>
                            <?= htmlspecialchars($customer_phone) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($customer_gstin)): ?>
                        <div class="mb-1">
                            <i class="bi bi-building me-1 text-muted"></i>
                            GSTIN: <?= htmlspecialchars($customer_gstin) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <?php if (!empty($customer_address)): ?>
                        <div class="mb-1">
                            <i class="bi bi-geo-alt me-1 text-muted"></i>
                            <?= nl2br(htmlspecialchars($customer_address)) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th width="5%">SN</th>
                        <th width="25%">Item Description</th>
                        <th width="8%">HSN</th>
                        <th width="8%">GST(%)</th>
                        <th width="12%" class="text-end">Rate</th>
                        <th width="8%" class="text-center">Qty</th>
                        <th width="10%" class="text-center">Disc</th>
                        <th width="12%" class="text-end">GST Amt</th>
                        <th width="12%" class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sn = 1;
                    foreach ($items as $item): 
                        $unit_price = $item['unit_price'] ?? 0;
                        $quantity = $item['quantity'] ?? 0;
                        $discount_amount = $item['discount_amount'] ?? 0;
                        $discount_rate = $item['discount_rate'] ?? 0;
                        
                        $cgst_rate = $item['cgst_rate'] ?? 0;
                        $sgst_rate = $item['sgst_rate'] ?? 0;
                        $igst_rate = $item['igst_rate'] ?? 0;
                        
                        $total_gst_rate = $cgst_rate + $sgst_rate + $igst_rate;
                        $line_total_before_discount = $unit_price * $quantity;
                        $line_total_after_discount = $line_total_before_discount - $discount_amount;
                        
                        $taxable_value = $item['taxable_value'] ?? $line_total_after_discount;
                        $cgst_amount = $item['cgst_amount'] ?? 0;
                        $sgst_amount = $item['sgst_amount'] ?? 0;
                        $igst_amount = $item['igst_amount'] ?? 0;
                        $total_gst_amount = $cgst_amount + $sgst_amount + $igst_amount;
                        $total_with_gst = $taxable_value + $total_gst_amount;
                        $unit = !empty($item['product_unit']) ? $item['product_unit'] : (empty($item['unit']) ? 'PCS' : $item['unit']);
                    ?>
                    <tr>
                        <td class="text-center"><?= $sn++ ?></td>
                        <td>
                            <?= htmlspecialchars($item['product_name']) ?>
                            <?php if (!empty($item['product_code'])): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($item['product_code']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= htmlspecialchars($item['hsn_code'] ?? '-') ?></td>
                        <td class="text-center">
                            <?= $total_gst_rate > 0 ? number_format($total_gst_rate, 1) . '%' : '0%' ?>
                        </td>
                        <td class="text-end">₹<?= money($unit_price) ?></td>
                        <td class="text-center">
                            <?= format_quantity($quantity) ?> <?= htmlspecialchars($unit) ?>
                        </td>
                        <td class="text-center">
                            <?php if ($discount_amount > 0): ?>
                                ₹<?= money($discount_amount) ?><br>
                                <small class="text-muted">(<?= $discount_rate ?>%)</small>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($total_gst_amount > 0): ?>
                                ₹<?= money($total_gst_amount) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-bold">₹<?= money($total_with_gst) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Amount Summary -->
        <div class="amount-summary">
            <div class="row">
                <div class="col-md-6">
                    <div class="security-badge mb-2">
                        <i class="bi bi-shield-check me-1"></i> Secure Invoice Link
                    </div>
                    <?php if (!empty($invoice['token_expiry'])): ?>
                        <div class="text-muted small">
                            <i class="bi bi-clock me-1"></i>
                            Link expires: <?= date('d-m-Y', strtotime($invoice['token_expiry'])) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['invoice_terms'])): ?>
                        <div class="mt-3">
                            <strong class="small">Terms & Conditions:</strong>
                            <div class="text-muted small mt-1">
                                <?= nl2br(htmlspecialchars($settings['invoice_terms'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <div class="row mb-2">
                        <div class="col-7 text-end">Subtotal:</div>
                        <div class="col-5 text-end">₹<?= money($subtotal) ?></div>
                    </div>
                    <?php if ($total_discount > 0): ?>
                    <div class="row mb-2">
                        <div class="col-7 text-end text-danger-custom">Item Discounts:</div>
                        <div class="col-5 text-end text-danger-custom">- ₹<?= money($total_discount) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($overall_discount > 0): ?>
                    <div class="row mb-2">
                        <div class="col-7 text-end text-danger-custom">Overall Discount:</div>
                        <div class="col-5 text-end text-danger-custom">- ₹<?= money($overall_discount) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($total_taxable > 0): ?>
                    <div class="row mb-2">
                        <div class="col-7 text-end">Taxable Amount:</div>
                        <div class="col-5 text-end">₹<?= money($total_taxable) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($total_cgst > 0): ?>
                    <div class="row mb-2">
                        <div class="col-7 text-end">CGST:</div>
                        <div class="col-5 text-end">₹<?= money($total_cgst) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($total_sgst > 0): ?>
                    <div class="row mb-2">
                        <div class="col-7 text-end">SGST:</div>
                        <div class="col-5 text-end">₹<?= money($total_sgst) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($total_igst > 0): ?>
                    <div class="row mb-2">
                        <div class="col-7 text-end">IGST:</div>
                        <div class="col-5 text-end">₹<?= money($total_igst) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (($total_cgst + $total_sgst + $total_igst) > 0): ?>
                    <div class="row mb-2">
                        <div class="col-7 text-end">Total GST:</div>
                        <div class="col-5 text-end">₹<?= money($total_cgst + $total_sgst + $total_igst) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="row total-row">
                        <div class="col-7 text-end">
                            <strong>GRAND TOTAL:</strong>
                        </div>
                        <div class="col-5 text-end">
                            <strong class="text-primary">₹<?= money($grand_total) ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes Section -->
        <?php 
        $notes = trim($invoice['notes'] ?? '');
        if ($notes !== ''): 
        ?>
        <div class="mt-3 p-3 bg-light rounded">
            <strong class="small">Notes:</strong>
            <div class="text-muted small mt-1">
                <?= nl2br(htmlspecialchars($notes)) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Authorized Signatory -->
        <div class="row mt-5">
            <div class="col-12 text-end">
                <div class="mb-5">
                    <strong class="small">For <?= htmlspecialchars($company_name) ?></strong>
                </div>
                <div class="mt-4">
                    <span class="border-top pt-2">Authorized Signatory</span>
                </div>
                <?php if (!empty($invoice['seller_name'])): ?>
                    <div class="text-muted small mt-1">
                        Verified By: <?= htmlspecialchars($invoice['seller_name']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer Note -->
        <?php if (!empty($settings['invoice_footer'])): ?>
        <div class="footer-note">
            <?= nl2br(htmlspecialchars($settings['invoice_footer'])) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Action Buttons - No Print Section -->
    <div class="d-flex justify-content-end gap-2 mt-4 no-print">
        <button onclick="window.print()" class="btn-print">
            <i class="bi bi-printer me-1"></i> Print
        </button>
        <button onclick="downloadPDF()" class="btn-download">
            <i class="bi bi-file-pdf me-1"></i> Download PDF
        </button>
    </div>

    <!-- Footer -->
    <div class="footer-note no-print">
        This is a computer generated invoice - valid without signature.<br>
        For any queries, please contact: <?= htmlspecialchars($settings['company_phone'] ?? $company_phone) ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // PDF Download Function
        async function downloadPDF() {
            const { jsPDF } = window.jspdf;
            
            // Show loading overlay
            document.getElementById('pdf-loading').style.display = 'flex';
            
            try {
                const invoiceContent = document.getElementById('invoice-content');
                
                // Create canvas from invoice content
                const canvas = await html2canvas(invoiceContent, {
                    scale: 2,
                    backgroundColor: '#ffffff',
                    logging: false,
                    allowTaint: true,
                    useCORS: true
                });
                
                const imgData = canvas.toDataURL('image/png');
                
                // Calculate PDF dimensions
                const imgWidth = 210; // A4 width in mm
                const pageHeight = 297; // A4 height in mm
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;
                
                // Create PDF
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4'
                });
                
                // Add image to PDF
                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                
                // Add new pages if content overflows
                while (heightLeft >= 20) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }
                
                // Download PDF
                pdf.save('invoice_<?= htmlspecialchars($invoice['invoice_number']) ?>.pdf');
                
            } catch (error) {
                console.error('PDF generation error:', error);
                alert('Error generating PDF. Please try again or use Print option.');
            } finally {
                // Hide loading overlay
                document.getElementById('pdf-loading').style.display = 'none';
            }
        }
    </script>
</body>
</html>