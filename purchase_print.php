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
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

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
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    $subtotal = 0;
    $total_cgst = 0;
    $total_sgst = 0;
    $total_igst = 0;
    $total_gst = 0;
    $total_items = count($items);
    $total_quantity = 0;

    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $unit_price = (float)($item['unit_price'] ?? 0);
        $item_total = $qty * $unit_price;

        $subtotal += $item_total;
        $total_quantity += $qty;

        $cgst_rate = (float)($item['cgst_rate'] ?? 0);
        $sgst_rate = (float)($item['sgst_rate'] ?? 0);
        $igst_rate = (float)($item['igst_rate'] ?? 0);

        if ($cgst_rate > 0 || $sgst_rate > 0 || $igst_rate > 0) {
            $cgst_amount = ($item_total * $cgst_rate) / 100;
            $sgst_amount = ($item_total * $sgst_rate) / 100;
            $igst_amount = ($item_total * $igst_rate) / 100;

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

// Derived values
$grand_total = (float)($purchase['total_amount'] ?? 0);
$paid_amount = (float)($purchase['paid_amount'] ?? 0);
$due_amount = max(0, $grand_total - $paid_amount);
$purchase_date = !empty($purchase['purchase_date']) ? date('d/m/Y', strtotime($purchase['purchase_date'])) : '-';
$created_at = !empty($purchase['created_at']) ? date('d/m/Y h:i A', strtotime($purchase['created_at'])) : '-';

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function qty_format($value)
{
    $value = (float)$value;
    return floor($value) == $value ? number_format($value, 0) : number_format($value, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_copy ? 'COPY - ' : '' ?>Print Purchase - <?= h($purchase['purchase_number']) ?></title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #222;
            background: #f3f5f7;
            font-size: 13px;
        }

        .container {
            background: #fff;
            max-width: 210mm;
            margin: 20px auto;
            padding: 12mm;
            box-shadow: 0 0 12px rgba(0,0,0,0.08);
            position: relative;
        }

        .no-print {
            text-align: center;
            margin: 20px auto 0;
            max-width: 210mm;
            padding: 14px;
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
            color: #fff;
        }

        .btn-print { background: #0d6efd; }
        .btn-back { background: #6c757d; }
        .btn-copy { background: #17a2b8; }
        .btn-pay { background: #198754; }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }

        .company-info {
            flex: 1;
        }

        .company-name {
            font-size: 25px;
            font-weight: 700;
            color: #1f2d3d;
            margin-bottom: 6px;
        }

        .company-address {
            font-size: 12px;
            line-height: 1.5;
            color: #555;
        }

        .document-info {
            min-width: 230px;
            text-align: right;
        }

        .document-title {
            font-size: 22px;
            font-weight: 700;
            color: #1f2d3d;
            margin-bottom: 10px;
        }

        .document-number {
            display: inline-block;
            font-size: 14px;
            font-weight: 700;
            background: #f4f6f8;
            border: 1px solid #dce3ea;
            padding: 6px 12px;
            border-radius: 4px;
        }

        .payment-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .status-unpaid { background: #dc3545; color: #fff; }
        .status-partial { background: #ffc107; color: #111; }
        .status-paid { background: #198754; color: #fff; }

        .summary-boxes {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }

        .summary-box {
            border: 1px solid #dfe5eb;
            border-radius: 6px;
            padding: 12px 10px;
            text-align: center;
            background: #fafbfc;
        }

        .summary-label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .summary-value {
            font-size: 20px;
            font-weight: 700;
            color: #1f2d3d;
            margin-top: 5px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 18px;
        }

        .detail-box {
            border: 1px solid #dfe5eb;
            border-radius: 6px;
            padding: 14px;
        }

        .detail-box h3 {
            margin: 0 0 12px;
            font-size: 14px;
            color: #1f2d3d;
            border-bottom: 1px solid #eceff3;
            padding-bottom: 6px;
        }

        .detail-row {
            display: flex;
            align-items: flex-start;
            margin-bottom: 8px;
            gap: 10px;
        }

        .detail-label {
            min-width: 140px;
            font-weight: 600;
            color: #4b5563;
        }

        .detail-value {
            flex: 1;
            word-break: break-word;
        }

        .section-title {
            margin: 0 0 10px;
            font-size: 15px;
            color: #1f2d3d;
            font-weight: 700;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 11px;
        }

        .items-table th {
            background: #233445;
            color: #fff;
            padding: 8px 6px;
            text-align: left;
            border: 1px solid #233445;
            font-size: 11px;
        }

        .items-table td {
            padding: 7px 6px;
            border: 1px solid #d9dee4;
            vertical-align: top;
        }

        .items-table tbody tr:nth-child(even) {
            background: #fafbfc;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }

        .totals-wrap {
            display: flex;
            justify-content: flex-end;
            gap: 16px;
            margin-top: 18px;
        }

        .gst-summary,
        .totals-section {
            width: 320px;
            border: 1px solid #dfe5eb;
            border-radius: 6px;
            padding: 14px;
            background: #fafbfc;
        }

        .gst-summary h3,
        .totals-section h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #1f2d3d;
        }

        .gst-row,
        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 6px 0;
            border-bottom: 1px solid #eceff3;
        }

        .gst-row.total,
        .total-row.grand-total {
            font-weight: 700;
            border-top: 2px solid #1f2d3d;
            border-bottom: 0;
            margin-top: 8px;
            padding-top: 10px;
            font-size: 15px;
        }

        .notes-section {
            margin-top: 24px;
            border-top: 2px solid #1f2d3d;
            padding-top: 16px;
        }

        .notes-box {
            border: 1px solid #dfe5eb;
            background: #fafbfc;
            padding: 14px;
            border-radius: 6px;
            line-height: 1.6;
        }

        .footer {
            margin-top: 28px;
            padding-top: 16px;
            border-top: 1px solid #dfe5eb;
            font-size: 11px;
            color: #555;
        }

        .terms-box {
            margin-bottom: 20px;
            font-size: 10px;
            color: #666;
            line-height: 1.6;
        }

        .signature-section {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-top: 34px;
        }

        .signature-box {
            width: 31%;
            text-align: center;
            padding-top: 30px;
        }

        .signature-line {
            border-top: 1px solid #333;
            width: 100%;
            margin-bottom: 6px;
        }

        .copy-watermark {
            position: fixed;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-35deg);
            font-size: 90px;
            color: rgba(220, 53, 69, 0.08);
            z-index: 9999;
            pointer-events: none;
            font-weight: 700;
            letter-spacing: 8px;
        }

        .watermark {
            position: fixed;
            top: 55%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-35deg);
            font-size: 76px;
            color: rgba(0,0,0,0.05);
            z-index: 9998;
            pointer-events: none;
            font-weight: 700;
        }

        .footer-meta {
            text-align: center;
            margin-top: 26px;
            color: #888;
            font-size: 10px;
        }

        @media print {
            body {
                background: #fff;
                font-size: 12px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .no-print {
                display: none !important;
            }

            .container {
                box-shadow: none;
                margin: 0;
                max-width: 100%;
                padding: 0;
            }
        }
    </style>
    <script>
        function printDocument() {
            window.print();
        }

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

    <?php if (($purchase['payment_status'] ?? '') === 'unpaid'): ?>
        <div class="watermark">UNPAID</div>
    <?php elseif (($purchase['payment_status'] ?? '') === 'partial'): ?>
        <div class="watermark">PARTIAL</div>
    <?php endif; ?>

    <div class="no-print">
        <button class="btn btn-print" onclick="printDocument()">Print Purchase</button>
        <a href="purchases.php" class="btn btn-back">Back to Purchases</a>
        <a href="purchase_print.php?id=<?= $purchase_id ?>&copy=1&autoprint=1" class="btn btn-copy" target="_blank">Print Copy</a>
        <?php if (($purchase['payment_status'] ?? '') !== 'paid'): ?>
            <a href="add_payment.php?type=purchase&id=<?= $purchase_id ?>" class="btn btn-pay">Add Payment</a>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="header">
            <div class="company-info">
                <div class="company-name"><?= h($purchase['business_name']) ?></div>
                <div class="company-address">
                    <?= nl2br(h($purchase['business_address'])) ?><br>
                    Phone: <?= h($purchase['business_phone']) ?><br>
                    Email: <?= h($purchase['business_email']) ?><br>
                    GSTIN: <?= h($purchase['business_gstin']) ?>
                </div>
            </div>

            <div class="document-info">
                <div class="document-title">PURCHASE ORDER</div>
                <div class="document-number"><?= h($purchase['purchase_number']) ?></div>
                <?php if ($is_copy): ?>
                    <div style="margin-top: 6px; color: #dc3545; font-weight: 700; font-size: 12px;">COPY</div>
                <?php endif; ?>
                <div style="margin-top: 10px;">
                    <span class="payment-status status-<?= h($purchase['payment_status']) ?>">
                        <?= strtoupper(h($purchase['payment_status'])) ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="summary-boxes">
            <div class="summary-box">
                <div class="summary-label">Total Items</div>
                <div class="summary-value"><?= number_format($total_items) ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Total Quantity</div>
                <div class="summary-value"><?= qty_format($total_quantity) ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Subtotal</div>
                <div class="summary-value">₹<?= number_format($subtotal, 2) ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Grand Total</div>
                <div class="summary-value">₹<?= number_format($grand_total, 2) ?></div>
            </div>
        </div>

        <div class="details-grid">
            <div class="detail-box">
                <h3>Purchase Details</h3>
                <div class="detail-row">
                    <span class="detail-label">Purchase Number:</span>
                    <span class="detail-value"><?= h($purchase['purchase_number']) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Purchase Date:</span>
                    <span class="detail-value"><?= $purchase_date ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Reference:</span>
                    <span class="detail-value"><?= h($purchase['reference'] ?: 'N/A') ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Status:</span>
                    <span class="detail-value">
                        <span class="payment-status status-<?= h($purchase['payment_status']) ?>">
                            <?= strtoupper(h($purchase['payment_status'])) ?>
                        </span>
                        <?php if (($purchase['payment_status'] ?? '') !== 'paid'): ?>
                            <br><small>Paid: ₹<?= number_format($paid_amount, 2) ?> | Due: ₹<?= number_format($due_amount, 2) ?></small>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Created By:</span>
                    <span class="detail-value"><?= h($purchase['created_by_name']) ?></span>
                </div>
                <?php if (!empty($purchase['received_by'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Received By:</span>
                        <span class="detail-value"><?= h($purchase['received_by_name']) ?></span>
                    </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span class="detail-label">Created At:</span>
                    <span class="detail-value"><?= $created_at ?></span>
                </div>
            </div>

            <div class="detail-box">
                <h3>Manufacturer Details</h3>
                <div class="detail-row">
                    <span class="detail-label">Manufacturer:</span>
                    <span class="detail-value"><?= h($purchase['manufacturer_name']) ?></span>
                </div>
                <?php if (!empty($purchase['manufacturer_contact'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Contact Person:</span>
                        <span class="detail-value"><?= h($purchase['manufacturer_contact']) ?></span>
                    </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value"><?= h($purchase['manufacturer_phone']) ?></span>
                </div>
                <?php if (!empty($purchase['manufacturer_email'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Email:</span>
                        <span class="detail-value"><?= h($purchase['manufacturer_email']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($purchase['manufacturer_gstin'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">GSTIN:</span>
                        <span class="detail-value"><?= h($purchase['manufacturer_gstin']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($purchase['manufacturer_address'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Address:</span>
                        <span class="detail-value"><?= nl2br(h($purchase['manufacturer_address'])) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top: 20px;">
            <h3 class="section-title">Purchase Items</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th width="30">#</th>
                        <th width="75">Code</th>
                        <th width="170">Product Name</th>
                        <th width="90">Category</th>
                        <th width="55" class="text-center">Qty</th>
                        <th width="55" class="text-center">Unit</th>
                        <th width="80" class="text-right">Unit Price</th>
                        <th width="80" class="text-right">Total</th>
                        <th width="70" class="text-center">HSN</th>
                        <th width="55" class="text-right">CGST</th>
                        <th width="55" class="text-right">SGST</th>
                        <th width="55" class="text-right">IGST</th>
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
                            <?php $item_total = (float)$item['quantity'] * (float)$item['unit_price']; ?>
                            <tr>
                                <td class="text-center"><?= $counter++ ?></td>
                                <td><?= h($item['product_code'] ?? 'N/A') ?></td>
                                <td>
                                    <strong><?= h($item['product_name']) ?></strong>
                                    <?php if (!empty($item['barcode'])): ?>
                                        <br><small style="color:#666;">Barcode: <?= h($item['barcode']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= h($item['category_name'] ?? 'N/A') ?>
                                    <?php if (!empty($item['subcategory_name'])): ?>
                                        <br><small><?= h($item['subcategory_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= qty_format($item['quantity']) ?></td>
                                <td class="text-center"><?= h($item['unit_of_measure']) ?></td>
                                <td class="text-right">₹<?= number_format((float)$item['unit_price'], 2) ?></td>
                                <td class="text-right">₹<?= number_format($item_total, 2) ?></td>
                                <td class="text-center"><?= h($item['hsn_code'] ?: $item['gst_hsn_code'] ?: '-') ?></td>
                                <td class="text-right"><?= ((float)$item['cgst_rate'] > 0) ? number_format((float)$item['cgst_rate'], 2) . '%' : '-' ?></td>
                                <td class="text-right"><?= ((float)$item['sgst_rate'] > 0) ? number_format((float)$item['sgst_rate'], 2) . '%' : '-' ?></td>
                                <td class="text-right"><?= ((float)$item['igst_rate'] > 0) ? number_format((float)$item['igst_rate'], 2) . '%' : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="totals-wrap">
            <?php if ($total_gst > 0): ?>
                <div class="gst-summary">
                    <h3>GST Summary</h3>
                    <div class="gst-row">
                        <span>Taxable Value</span>
                        <span>₹<?= number_format($subtotal, 2) ?></span>
                    </div>
                    <?php if ($total_cgst > 0): ?>
                        <div class="gst-row">
                            <span>CGST Total</span>
                            <span>₹<?= number_format($total_cgst, 2) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($total_sgst > 0): ?>
                        <div class="gst-row">
                            <span>SGST Total</span>
                            <span>₹<?= number_format($total_sgst, 2) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($total_igst > 0): ?>
                        <div class="gst-row">
                            <span>IGST Total</span>
                            <span>₹<?= number_format($total_igst, 2) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="gst-row total">
                        <span>Total GST</span>
                        <span>₹<?= number_format($total_gst, 2) ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="totals-section">
                <h3>Totals</h3>
                <div class="total-row">
                    <span>Subtotal</span>
                    <span>₹<?= number_format($subtotal, 2) ?></span>
                </div>
                <?php if ($total_gst > 0): ?>
                    <div class="total-row">
                        <span>Total GST</span>
                        <span>₹<?= number_format($total_gst, 2) ?></span>
                    </div>
                <?php endif; ?>
                <div class="total-row">
                    <span>Paid Amount</span>
                    <span>₹<?= number_format($paid_amount, 2) ?></span>
                </div>
                <?php if ($due_amount > 0): ?>
                    <div class="total-row" style="color:#dc3545;">
                        <span>Amount Due</span>
                        <span>₹<?= number_format($due_amount, 2) ?></span>
                    </div>
                <?php endif; ?>
                <div class="total-row grand-total">
                    <span>Grand Total</span>
                    <span>₹<?= number_format($grand_total, 2) ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($purchase['notes'])): ?>
            <div class="notes-section">
                <h3 class="section-title">Notes & Instructions</h3>
                <div class="notes-box">
                    <?= nl2br(h($purchase['notes'])) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="footer">
            <div class="terms-box">
                <strong>Terms & Conditions:</strong><br>
                1. Goods must be in perfect condition as per specifications.<br>
                2. Delivery must be made on or before the agreed date.<br>
                3. Payment terms apply as per purchase agreement.<br>
                4. Defective goods will be returned at supplier cost.<br>
                5. All disputes are subject to the jurisdiction of the business location.
            </div>

            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div style="font-weight: 700;">Prepared By</div>
                    <div style="font-size: 10px; color: #666;"><?= h($purchase['created_by_name']) ?></div>
                </div>

                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div style="font-weight: 700;">Approved By</div>
                    <div style="font-size: 10px; color: #666;">Authorized Signatory</div>
                </div>

                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div style="font-weight: 700;">Manufacturer Seal & Signature</div>
                    <div style="font-size: 10px; color: #666;">With Date</div>
                </div>
            </div>

            <div class="footer-meta">
                <?php if ($is_copy): ?>
                    <strong style="color:#dc3545;">THIS IS A COPY - NOT AN ORIGINAL DOCUMENT</strong><br>
                <?php endif; ?>
                Generated on: <?= date('d/m/Y H:i:s') ?> |
                Purchase ID: <?= $purchase_id ?> |
                Business ID: <?= $business_id ?> |
                Original Amount: ₹<?= number_format($grand_total, 2) ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            <?php if (isset($_GET['autoprint'])): ?>
            setTimeout(function () {
                window.print();
            }, 500);
            <?php endif; ?>
        });
    </script>
</body>
</html>