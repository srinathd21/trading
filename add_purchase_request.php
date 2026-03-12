<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Authorization: Only admin & warehouse_manager
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$business_id = $_SESSION['business_id'] ?? 1;

if (!in_array($user_role, ['admin', 'warehouse_manager','stock_manager'])) {
    $_SESSION['error'] = "Access denied. You don't have permission to create purchase requests.";
    header('Location: dashboard.php');
    exit();
}

$success = $error = '';

// Generate Request Number for current business
$year = date('Y');
$month = date('m');
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM purchase_requests 
    WHERE YEAR(created_at) = ? 
      AND MONTH(created_at) = ?
      AND business_id = ?
");
$stmt->execute([$year, $month, $business_id]);
$request_number = "PR{$year}{$month}-" . str_pad($stmt->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);

// Fetch Data filtered by business_id
$manufacturers = $pdo->prepare("
    SELECT id, name 
    FROM manufacturers 
    WHERE business_id = ? 
      AND is_active = 1 
    ORDER BY name
");
$manufacturers->execute([$business_id]);
$manufacturers = $manufacturers->fetchAll();

// Get shops for current business
$shops = $pdo->prepare("
    SELECT id, shop_name, is_warehouse 
    FROM shops 
    WHERE business_id = ? 
      AND is_active = 1 
    ORDER BY is_warehouse DESC, shop_name
");
$shops->execute([$business_id]);
$shops = $shops->fetchAll();

// Get current shop for default selection
$current_shop_id = $_SESSION['current_shop_id'] ?? 1;

// Get warehouse for stock calculation
$warehouse = $pdo->prepare("
    SELECT id, shop_name 
    FROM shops 
    WHERE business_id = ? 
      AND is_warehouse = 1 
      AND is_active = 1 
    LIMIT 1
");
$warehouse->execute([$business_id]);
$warehouse = $warehouse->fetch();
$warehouse_id = $warehouse['id'] ?? 0;

// Fetch products with detailed info for current business - matching purchase_add.php style
$prodSql = "SELECT p.id, p.product_name, p.product_code, p.barcode,
                   p.stock_price, p.min_stock_level,
                   p.hsn_code, 
                   COALESCE(g.cgst_rate, 0) as cgst_rate, 
                   COALESCE(g.sgst_rate, 0) as sgst_rate, 
                   COALESCE(g.igst_rate, 0) as igst_rate,
                   c.category_name,
                   COALESCE(ps_shop.quantity, 0) as shop_stock,
                   COALESCE(ps_warehouse.quantity, 0) as warehouse_stock
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id AND c.business_id = p.business_id
            LEFT JOIN gst_rates g ON p.gst_id = g.id AND g.business_id = p.business_id
            LEFT JOIN product_stocks ps_shop ON p.id = ps_shop.product_id 
                AND ps_shop.shop_id = ? AND ps_shop.business_id = p.business_id
            LEFT JOIN product_stocks ps_warehouse ON p.id = ps_warehouse.product_id 
                AND ps_warehouse.shop_id = ? AND ps_warehouse.business_id = p.business_id
            WHERE p.is_active = 1 AND p.business_id = ?
            ORDER BY c.category_name, p.product_name";

$prodStmt = $pdo->prepare($prodSql);
$prodStmt->execute([$current_shop_id, $warehouse_id, $business_id]);
$prodRes = $prodStmt->fetchAll();

$jsProducts = [];
$barcodeMap = [];

foreach ($prodRes as $p) {
    $pid = (int)$p['id'];
    $name = htmlspecialchars($p['product_name']);
    $price = (float)$p['stock_price'];
    $code = $p['product_code'] ? htmlspecialchars($p['product_code']) : sprintf('P%06d', $pid);
    $barcode = htmlspecialchars($p['barcode'] ?? '');
    $shop_stock = (int)$p['shop_stock'];
    $warehouse_stock = (int)$p['warehouse_stock'];
    $total_stock = $shop_stock + $warehouse_stock;
    $min_stock = (int)$p['min_stock_level'];
    $is_low_stock = $total_stock <= $min_stock;
    $hsn = htmlspecialchars($p['hsn_code'] ?? '');
    $cgst = (float)($p['cgst_rate'] ?? 0);
    $sgst = (float)($p['sgst_rate'] ?? 0);
    $igst = (float)($p['igst_rate'] ?? 0);
    $total_gst = $cgst + $sgst + $igst;
    $category = htmlspecialchars($p['category_name'] ?? 'Uncategorized');
    
    $jsProducts[$pid] = [
        'id' => $pid,
        'name' => $name,
        'price' => $price,
        'code' => $code,
        'barcode' => $barcode,
        'shop_stock' => $shop_stock,
        'warehouse_stock' => $warehouse_stock,
        'total_stock' => $total_stock,
        'min_stock' => $min_stock,
        'is_low_stock' => $is_low_stock,
        'hsn' => $hsn,
        'cgst' => $cgst,
        'sgst' => $sgst,
        'igst' => $igst,
        'total_gst' => $total_gst,
        'category' => $category
    ];
    
    if ($p['barcode']) $barcodeMap[$p['barcode']] = $pid;
    $barcodeMap[$code] = $pid;
}

// Process Form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manufacturer_id = (int)($_POST['manufacturer_id'] ?? 0);
    $shop_id = (int)($_POST['shop_id'] ?? $current_shop_id);
    $request_date = $_POST['request_date'] ?? date('Y-m-d');
    $delivery_date = $_POST['delivery_date'] ?? null;
    $priority = $_POST['priority'] ?? 'medium';
    $reference = trim($_POST['reference'] ?? '');
    $request_notes = trim($_POST['request_notes'] ?? '');
    $items = $_POST['items'] ?? [];

    if ($manufacturer_id <= 0 || $shop_id <= 0 || empty($items)) {
        $error = "Please select a supplier, destination shop and add at least one product.";
    } else {
        try {
            $pdo->beginTransaction();

            // Fetch manufacturer details for email
            $manStmt = $pdo->prepare("SELECT name, contact_person, email, phone FROM manufacturers WHERE id = ? AND business_id = ?");
            $manStmt->execute([$manufacturer_id, $business_id]);
            $manufacturer = $manStmt->fetch();

            if (!$manufacturer || empty($manufacturer['email'])) {
                // Continue without email if not available
                $manufacturer_email = null;
            } else {
                $manufacturer_email = trim($manufacturer['email']);
                $manufacturer_name = htmlspecialchars($manufacturer['name']);
                $contact_person = htmlspecialchars($manufacturer['contact_person'] ?? 'Sir/Madam');
            }

            // Insert Purchase Request Record
            $stmt = $pdo->prepare("
                INSERT INTO purchase_requests
                (request_number, manufacturer_id, expected_delivery_date,
                 priority, reference, request_notes, requested_by, status,
                 total_estimated_amount, business_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', 0, ?, NOW())
            ");
            $stmt->execute([
                $request_number, $manufacturer_id, $delivery_date,
                $priority, $reference, $request_notes, $user_id, $business_id
            ]);
            $request_id = $pdo->lastInsertId();
            $estimated_total = 0;
            $item_index = 0;

            // Collect items for email
            $email_items_html = '';
            foreach ($items as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);
                $price = (float)($item['estimated_price'] ?? 0);
                $notes = trim($item['notes'] ?? '');

                if ($pid > 0 && $qty > 0 && $price >= 0) {
                    $estimated_amount = $qty * $price;
                    $product_name = $jsProducts[$pid]['name'] ?? 'Unknown Product';
                    $product_code = $jsProducts[$pid]['code'] ?? '';

                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_request_items
                        (purchase_request_id, product_id, quantity, estimated_price, notes)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$request_id, $pid, $qty, $price, $notes]);

                    $estimated_total += $estimated_amount;

                    // Build email item row
                    $email_items_html .= "
                    <tr>
                        <td style='padding:8px; border:1px solid #ddd; text-align:left;'>{$product_name}<br><small style='color:#666;'>(Code: {$product_code})</small></td>
                        <td style='padding:8px; border:1px solid #ddd; text-align:center;'>{$qty}</td>
                        <td style='padding:8px; border:1px solid #ddd; text-align:right;'>₹" . number_format($price, 2) . "</td>
                        <td style='padding:8px; border:1px solid #ddd; text-align:right;'>₹" . number_format($estimated_amount, 2) . "</td>
                    </tr>";
                }
            }

            // Update total amount
            $pdo->prepare("
                UPDATE purchase_requests
                SET total_estimated_amount = ?, status = 'sent', updated_at = NOW()
                WHERE id = ? AND business_id = ?
            ")->execute([$estimated_total, $request_id, $business_id]);

            $pdo->commit();

            // ========================
            // SEND EMAIL TO MANUFACTURER
            // ========================
            if ($manufacturer_email) {
                $business_name = htmlspecialchars($_SESSION['current_business_name'] ?? 'Our Company');
                $priority_badge = $priority === 'high' ? '🔴 High' : ($priority === 'medium' ? '🟡 Medium' : '🟢 Low');
                $delivery_text = $delivery_date ? date('d M Y', strtotime($delivery_date)) : 'Not specified';

                $subject = "Purchase Request {$request_number} - {$business_name}";

                $message = "
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                        .header { background: #0d6efd; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; }
                        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                        th { background: #f8f9fa; padding: 12px; text-align: left; }
                        .footer { margin-top: 30px; font-size: 0.9em; color: #666; }
                    </style>
                </head>
                <body>
                    <div class='header'>
                        <h1>Purchase Request Received</h1>
                        <h2>{$request_number}</h2>
                    </div>
                    <div class='content'>
                        <p>Dear <strong>{$contact_person}</strong> ({$manufacturer_name}),</p>
                        <p>We have raised a new purchase request for your reference. Please review the details below and provide us with your best quotation at the earliest.</p>

                        <p><strong>Request Date:</strong> " . date('d M Y') . "<br>
                           <strong>Expected Delivery:</strong> {$delivery_text}<br>
                           <strong>Priority:</strong> {$priority_badge}<br>
                           <strong>Reference:</strong> " . ($reference ?: 'N/A') . "</p>

                        <h3>Requested Items:</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th style='text-align:center;'>Qty</th>
                                    <th style='text-align:right;'>Est. Price</th>
                                    <th style='text-align:right;'>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$email_items_html}
                                <tr>
                                    <td colspan='3' style='text-align:right; padding:12px; font-weight:bold;'>Estimated Total</td>
                                    <td style='text-align:right; padding:12px; font-weight:bold;'>₹" . number_format($estimated_total, 2) . "</td>
                                </tr>
                            </tbody>
                        </table>

                        " . ($request_notes ? "<p><strong>Notes:</strong><br>" . nl2br(htmlspecialchars($request_notes)) . "</p>" : "") . "

                        <p>Please send your quotation (with GST breakdown, delivery timeline, and terms) to this email or contact us.</p>
                        <p>Thank you for your continued support!</p>
                    </div>
                    <div class='footer'>
                        <p>Regards,<br>
                        <strong>{$_SESSION['username']}</strong><br>
                        {$business_name}<br>
                        Sent on: " . date('d M Y h:i A') . "</p>
                    </div>
                </body>
                </html>";

                // Headers for HTML email
                $headers = [
                    "MIME-Version: 1.0",
                    "Content-type: text/html; charset=UTF-8",
                    "From: {$_SESSION['current_business_name']} <no-reply@" . $_SERVER['HTTP_HOST'] . ">",
                    "Reply-To: " . ($_SESSION['user_email'] ?? "no-reply@" . $_SERVER['HTTP_HOST'])

                ];

                $headers_str = implode("\r\n", $headers);

                // Send email
                if (mail($manufacturer_email, $subject, $message, $headers_str)) {
                    // Optional: log success if needed
                } else {
                    error_log("Failed to send purchase request email to: {$manufacturer_email} for PR {$request_number}");
                }
            }

            header("Location: purchase_requests.php?success=1&pr=" . urlencode($request_number));
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to create request: " . $e->getMessage();
            error_log("Purchase Request Error: " . $e->getMessage());
        }
    }
}
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "New Purchase Request"; 
include 'includes/head.php'; 
?>
<!-- Add Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
/* Product Search Section - Matching POS style */
.product-search-section {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}
.product-search-section h5 {
    color: #495057;
    font-size: 1rem;
    margin-bottom: 15px;
    font-weight: 600;
}

/* Stock badges */
.stock-badge {
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 3px;
    margin-right: 3px;
}
.shop-stock-badge {
    background: #17a2b8;
    color: white;
}
.warehouse-stock-badge {
    background: #6c757d;
    color: white;
}
.low-stock-badge {
    background: #dc3545;
    color: white;
}
.out-of-stock-badge {
    background: #343a40;
    color: white;
}
.gst-badge {
    background: #6f42c1;
    color: white;
}
.min-stock-badge {
    background: #fd7e14;
    color: white;
}

/* Priority badges */
.priority-high {
    background: #dc3545;
    color: white;
}
.priority-medium {
    background: #fd7e14;
    color: white;
}
.priority-low {
    background: #28a745;
    color: white;
}

/* Product details card */
.product-details-card {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 12px;
    margin-top: 10px;
    display: none;
}
.product-details-card.show {
    display: block;
}

/* Selected products table */
.selected-products-table {
    font-size: 0.875rem;
}
.selected-products-table th {
    background: #f8f9fa;
    font-weight: 600;
    white-space: nowrap;
}
.selected-products-table td {
    vertical-align: middle;
}

/* Select2 custom styling */
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
    border-radius: 0.375rem;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
    font-size: 0.875rem;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}
.select2-results__option {
    padding: 8px 12px;
    font-size: 0.875rem;
}
.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #0d6efd;
    color: white;
}
.select2-container--open .select2-dropdown--below {
    border-radius: 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

/* Product option in dropdown */
.product-option {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
}
.product-name {
    font-weight: 500;
    color: #333;
    font-size: 0.9rem;
}
.product-code {
    font-size: 0.8rem;
    color: #666;
}
.product-price {
    font-weight: 600;
    color: #dc3545;
    font-size: 0.85rem;
}
.product-stock-display {
    display: flex;
    gap: 5px;
    margin-top: 3px;
}

/* General styles */
.item-row {
    transition: all 0.3s ease;
}
.item-row:hover {
    background-color: #f8f9fa;
}
.low-stock-row {
    background-color: #fff3cd !important;
    border-left: 4px solid #ffc107;
}
</style>
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
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-file-find me-2"></i> New Purchase Request
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i> 
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">Create a new purchase request to send to suppliers</p>
                            </div>
                            <a href="purchase_requests.php" class="btn btn-outline-secondary">
                                <i class="bx bx-arrow-back me-1"></i> Back to Requests
                            </a>
                        </div>
                    </div>
                </div>

                <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bx bx-check-circle me-2"></i>
                    Purchase Request <strong><?= htmlspecialchars($_GET['pr'] ?? 'Request') ?></strong> created successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bx bx-error me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" id="purchaseRequestForm">
                    <div class="row g-4">
                        <!-- Purchase Request Details Card -->
                        <div class="col-lg-4">
                            <div class="card card-hover border-start border-info border-4 shadow-sm h-100">
                                <div class="card-header bg-info text-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-detail me-2"></i> Request Details
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Request Number</label>
                                        <input type="text" class="form-control bg-light" value="<?= $request_number ?>" readonly>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Request Date <span class="text-danger">*</span></label>
                                        <input type="date" name="request_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Expected Delivery Date</label>
                                        <input type="date" name="delivery_date" class="form-control">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Priority <span class="text-danger">*</span></label>
                                        <select name="priority" class="form-select" required>
                                            <option value="low">Low</option>
                                            <option value="medium" selected>Medium</option>
                                            <option value="high">High</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Supplier <span class="text-danger">*</span></label>
                                        <select name="manufacturer_id" class="form-select select2-supplier" required>
                                            <option value="">-- Select Supplier --</option>
                                            <?php foreach ($manufacturers as $m): ?>
                                            <option value="<?= $m['id'] ?>">
                                                <?= htmlspecialchars($m['name']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Stock Destination <span class="text-danger">*</span></label>
                                        <select name="shop_id" class="form-select select2-shop" required>
                                            <option value="">-- Select Location --</option>
                                            <?php foreach ($shops as $shop): ?>
                                            <option value="<?= $shop['id'] ?>" <?= $shop['id'] == $current_shop_id ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($shop['shop_name']) ?>
                                                <?= $shop['is_warehouse'] ? ' (Warehouse)' : '' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Reference No.</label>
                                        <input type="text" name="reference" class="form-control" placeholder="Optional">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Request Notes (Optional)</label>
                                        <textarea name="request_notes" class="form-control" rows="3" placeholder="Any special instructions, delivery requirements, etc."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Products Section -->
                        <div class="col-lg-8">
                            <div class="card shadow-sm h-100">
                                <div class="card-header bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <i class="bx bx-package me-2"></i> Request Items
                                        </h5>
                                        <span class="badge bg-primary" id="itemCount">0 Items</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <!-- Product Search Section - Matching POS style -->
                                    <div class="product-search-section">
                                        <h5><i class="bx bx-search me-2"></i> Add Products</h5>
                                        
                                        <div class="row g-3">
                                            <div class="col-md-12">
                                                <label class="form-label">Search Product</label>
                                                <select id="productSelect" class="form-control select2-products">
                                                    <option value="">-- Type to search product --</option>
                                                    <?php
                                                    foreach ($prodRes as $p): 
                                                        $pid = (int)$p['id'];
                                                        $name = htmlspecialchars($p['product_name']);
                                                        $price = (float)$p['stock_price'];
                                                        $code = $p['product_code'] ? htmlspecialchars($p['product_code']) : sprintf('P%06d', $pid);
                                                        $barcode = htmlspecialchars($p['barcode'] ?? '');
                                                        $shop_stock = (int)$p['shop_stock'];
                                                        $warehouse_stock = (int)$p['warehouse_stock'];
                                                        $total_stock = $shop_stock + $warehouse_stock;
                                                        $min_stock = (int)$p['min_stock_level'];
                                                        $is_low_stock = $total_stock <= $min_stock;
                                                        $hsn = htmlspecialchars($p['hsn_code'] ?? '');
                                                        $cgst = (float)($p['cgst_rate'] ?? 0);
                                                        $sgst = (float)($p['sgst_rate'] ?? 0);
                                                        $igst = (float)($p['igst_rate'] ?? 0);
                                                        $total_gst = $cgst + $sgst + $igst;
                                                        $category = htmlspecialchars($p['category_name'] ?? 'Uncategorized');
                                                        
                                                        // Create stock badges
                                                        $shop_badge_class = $shop_stock > 0 ? 
                                                            ($shop_stock < 10 ? 'low-stock-badge' : 'shop-stock-badge') : 
                                                            'out-of-stock-badge';
                                                        
                                                        $warehouse_badge_class = $warehouse_stock > 0 ? 
                                                            'warehouse-stock-badge' : 
                                                            'out-of-stock-badge';
                                                        
                                                        $min_badge_class = $total_stock <= $min_stock ? 
                                                            'low-stock-badge' : 'min-stock-badge';
                                                    ?>
                                                    <option value="<?= $pid ?>" 
                                                            data-name="<?= $name ?>"
                                                            data-code="<?= $code ?>"
                                                            data-barcode="<?= $barcode ?>"
                                                            data-price="<?= $price ?>"
                                                            data-shop-stock="<?= $shop_stock ?>"
                                                            data-warehouse-stock="<?= $warehouse_stock ?>"
                                                            data-total-stock="<?= $total_stock ?>"
                                                            data-min-stock="<?= $min_stock ?>"
                                                            data-is-low-stock="<?= $is_low_stock ? 'true' : 'false' ?>"
                                                            data-hsn="<?= $hsn ?>"
                                                            data-cgst="<?= $cgst ?>"
                                                            data-sgst="<?= $sgst ?>"
                                                            data-igst="<?= $igst ?>"
                                                            data-total-gst="<?= $total_gst ?>"
                                                            data-category="<?= $category ?>"
                                                            data-shop-badge-class="<?= $shop_badge_class ?>"
                                                            data-warehouse-badge-class="<?= $warehouse_badge_class ?>"
                                                            data-min-badge-class="<?= $min_badge_class ?>">
                                                        <?= $name ?> (<?= $code ?>)
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label class="form-label">Total Stock</label>
                                                <input type="text" id="stockDisplay" class="form-control bg-white" readonly value="0">
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label class="form-label">Min Stock Level</label>
                                                <input type="text" id="minStockDisplay" class="form-control bg-white" readonly value="0">
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label class="form-label">Estimated Price <span class="text-danger">*</span></label>
                                                <input type="number" step="0.01" min="0" id="estimatedPrice" class="form-control" value="0" required>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                                <input type="number" id="quantity" class="form-control" min="1" value="10">
                                            </div>
                                            
                                            <div class="col-md-12">
                                                <label class="form-label">Item Notes (Optional)</label>
                                                <input type="text" id="itemNotes" class="form-control" placeholder="Optional notes for this item">
                                            </div>
                                            
                                            <div class="col-md-12">
                                                <button type="button" id="addProductBtn" class="btn btn-primary w-100">
                                                    <i class="bx bx-plus me-1"></i> Add Product to Request
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- Product Details Card -->
                                        <div id="productDetails" class="product-details-card">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <strong id="productName" class="product-name"></strong><br>
                                                    <small class="text-muted">Code: <span id="productCode"></span></small><br>
                                                    <small class="text-muted" id="productHSN"></small>
                                                    <div class="product-stock-display mt-1" id="productStockInfo"></div>
                                                    <div class="product-stock-display mt-1" id="minStockInfo"></div>
                                                </div>
                                                <div class="col-md-6 text-end">
                                                    <small class="text-danger fw-bold">Current Price: ₹<span id="costPrice"></span></small><br>
                                                    <small class="text-muted" id="productGST"></small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Selected Products Table -->
                                    <div class="table-responsive mt-4">
                                        <table class="table table-hover selected-products-table" id="selectedProductsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="5%">#</th>
                                                    <th width="25%">Product</th>
                                                    <th width="10%" class="text-end">Current Stock</th>
                                                    <th width="10%" class="text-end">Req. Qty</th>
                                                    <th width="15%" class="text-end">Est. Price</th>
                                                    <th width="15%" class="text-end">Total</th>
                                                    <th width="10%" class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="selectedProductsBody">
                                                <tr id="emptyRow" class="text-center">
                                                    <td colspan="7" class="py-4">
                                                        <i class="bx bx-package fs-1 text-muted mb-3 d-block"></i>
                                                        <p class="text-muted">No products added yet</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr class="table-light">
                                                    <td colspan="5" class="text-end fw-bold">Estimated Total:</td>
                                                    <td class="text-end fw-bold" id="estimatedTotal">₹0.00</td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>

                                    <div class="alert alert-info mt-3">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Request Summary:</strong>
                                        <span id="stockSummary">
                                            No products selected
                                        </span>
                                    </div>

                                    <hr>

                                    <div class="text-end">
                                        <button type="submit" class="btn btn-success btn-lg px-5" id="submitBtn" disabled>
                                            <i class="bx bx-check me-2"></i> Create Purchase Request
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<!-- Add Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Global state - matching purchase_add.php
const PRODUCTS = <?php echo json_encode($jsProducts, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const BARCODE_MAP = <?php echo json_encode($barcodeMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
let selectedProducts = new Map();
let itemCounter = 0;

// Helper functions
function findProductById(id) {
    return PRODUCTS[id];
}

function formatMoney(n) { 
    return '₹' + parseFloat(n).toFixed(2);
}

// Calculate total for an item
function calculateItemTotal(price, quantity) {
    return price * quantity;
}

// Update product details when selected
function updateProductDetails(productId) {
    const product = findProductById(productId);
    if (product) {
        $('#stockDisplay').val(product.total_stock);
        $('#minStockDisplay').val(product.min_stock);
        $('#estimatedPrice').val(product.price);
        
        // Auto-calculate suggested quantity based on low stock
        if (product.is_low_stock) {
            const suggestedQty = Math.max(10, product.min_stock - product.total_stock + 10);
            $('#quantity').val(suggestedQty);
        } else {
            $('#quantity').val(10);
        }
        
        $('#itemNotes').val('');
        
        // Show product details
        $('#productDetails').addClass('show');
        $('#productName').text(product.name);
        $('#productCode').text(product.code);
        $('#productHSN').text(product.hsn ? 'HSN: ' + product.hsn : '');
        $('#costPrice').text(product.price.toFixed(2));
        
        // Show stock info
        let stockHtml = '';
        
        if (product.shop_stock > 0) {
            if (product.shop_stock < 10) {
                stockHtml += `<span class="stock-badge low-stock-badge">Shop: ${product.shop_stock}</span>`;
            } else {
                stockHtml += `<span class="stock-badge shop-stock-badge">Shop: ${product.shop_stock}</span>`;
            }
        } else {
            stockHtml += `<span class="stock-badge out-of-stock-badge">Shop: 0</span>`;
        }
        
        stockHtml += ' ';
        
        if (product.warehouse_stock > 0) {
            stockHtml += `<span class="stock-badge warehouse-stock-badge">WH: ${product.warehouse_stock}</span>`;
        } else {
            stockHtml += `<span class="stock-badge out-of-stock-badge">WH: 0</span>`;
        }
        
        $('#productStockInfo').html(stockHtml);
        
        // Show min stock info
        let minStockHtml = '';
        const stockStatus = product.total_stock <= product.min_stock ? 
            `<span class="stock-badge low-stock-badge">Low Stock!</span>` : 
            `<span class="stock-badge min-stock-badge">Min: ${product.min_stock}</span>`;
        minStockHtml = `<span class="me-2">${stockStatus}</span>`;
        
        $('#minStockInfo').html(minStockHtml);
        
        // Show GST info
        let gstText = '';
        if (product.total_gst > 0) {
            gstText = `GST: ${product.total_gst}%`;
            if (product.cgst > 0) gstText += ` (C:${product.cgst}%`;
            if (product.sgst > 0) gstText += ` S:${product.sgst}%`;
            if (product.igst > 0) gstText += ` I:${product.igst}%`;
            if (product.total_gst > 0) gstText += ')';
        } else {
            gstText = 'No GST';
        }
        $('#productGST').text(gstText);
        
        // Focus on quantity field
        $('#quantity').focus();
    }
}

// Add product to request
function addProductToRequest() {
    const select = $('#productSelect');
    const option = select.find('option:selected');
    
    if (!option.length || !option.val()) {
        alert('Please select a product first');
        select.focus();
        return;
    }

    const productId = option.val();
    const product = findProductById(productId);
    if (!product) {
        alert('Product not found');
        return;
    }

    const productName = option.data('name');
    const productCode = option.data('code');
    const shop_stock = parseInt(option.data('shop-stock')) || 0;
    const warehouse_stock = parseInt(option.data('warehouse-stock')) || 0;
    const total_stock = parseInt(option.data('total-stock')) || 0;
    const min_stock = parseInt(option.data('min-stock')) || 0;
    const is_low_stock = option.data('is-low-stock') === 'true';
    const price = parseFloat($('#estimatedPrice').val()) || 0;
    const quantity = parseInt($('#quantity').val()) || 1;
    const notes = $('#itemNotes').val().trim();
    const total_gst = parseFloat(option.data('total-gst')) || 0;
    const hsn = option.data('hsn') || '';

    if (price < 0) {
        alert('Estimated price cannot be negative');
        $('#estimatedPrice').focus();
        return;
    }

    if (quantity <= 0) {
        alert('Quantity must be greater than 0');
        $('#quantity').focus();
        return;
    }

    // Calculate total
    const total = calculateItemTotal(price, quantity);
    const itemId = ++itemCounter;

    // Add to selected products map
    selectedProducts.set(itemId, {
        id: productId,
        itemId: itemId,
        name: productName,
        code: productCode,
        shop_stock: shop_stock,
        warehouse_stock: warehouse_stock,
        total_stock: total_stock,
        min_stock: min_stock,
        is_low_stock: is_low_stock,
        price: price,
        quantity: quantity,
        notes: notes,
        total_gst: total_gst,
        hsn: hsn,
        total: total
    });

    // Update table
    updateProductsTable();
    updateSummary();

    // Reset fields
    select.val(null).trigger('change');
    $('#stockDisplay').val('0');
    $('#minStockDisplay').val('0');
    $('#estimatedPrice').val('0');
    $('#quantity').val(10);
    $('#itemNotes').val('');
    $('#productDetails').removeClass('show');
    
    // Focus back on product selection
    select.focus();
}

// Update products table
function updateProductsTable() {
    const tbody = $('#selectedProductsBody');
    tbody.empty();
    let totalAmount = 0;
    let rowIndex = 0;

    if (selectedProducts.size === 0) {
        tbody.append('<tr id="emptyRow" class="text-center"><td colspan="7" class="py-4"><i class="bx bx-package fs-1 text-muted mb-3 d-block"></i><p class="text-muted">No products added yet</p></td></tr>');
        $('#itemCount').text('0 Items');
        $('#submitBtn').prop('disabled', true);
        return;
    }

    selectedProducts.forEach((product, itemId) => {
        totalAmount += product.total;
        rowIndex++;
        
        const rowClass = product.is_low_stock ? 'low-stock-row' : '';
        
        const row = $(`
            <tr class="item-row ${rowClass}" data-item-id="${itemId}">
                <td>${rowIndex}</td>
                <td>
                    <div class="d-flex flex-column">
                        <strong>${product.name}</strong>
                        <small class="text-muted">${product.code}</small>
                        ${product.notes ? `<small class="text-info mt-1"><i class="bx bx-note me-1"></i>${product.notes}</small>` : ''}
                        ${product.is_low_stock ? '<span class="badge bg-danger low-stock-badge mt-1">Low Stock</span>' : ''}
                    </div>
                </td>
                <td class="text-end">
                    <div class="d-flex flex-column align-items-end">
                        <span class="text-dark">${product.total_stock}</span>
                        <small class="text-muted">WH: ${product.warehouse_stock}</small>
                    </div>
                </td>
                <td class="text-end">
                    <span class="badge bg-primary rounded-pill px-3 py-1">${product.quantity}</span>
                </td>
                <td class="text-end">${formatMoney(product.price)}</td>
                <td class="text-end fw-bold">${formatMoney(product.total)}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-outline-danger btn-sm delete-btn" 
                            data-item-id="${itemId}" title="Remove">
                        <i class="bx bx-trash"></i>
                    </button>
                </td>
            </tr>
        `);
        tbody.append(row);
    });

    // Update totals
    $('#estimatedTotal').text(formatMoney(totalAmount));
    $('#itemCount').text(`${selectedProducts.size} ${selectedProducts.size === 1 ? 'Item' : 'Items'}`);
    $('#submitBtn').prop('disabled', false);

    // Attach delete events
    $('.delete-btn').on('click', function(e) {
        e.preventDefault();
        const itemId = $(this).data('item-id');
        deleteProduct(itemId);
    });
}

// Delete product from request
function deleteProduct(itemId) {
    if (confirm('Are you sure you want to remove this item from the request?')) {
        selectedProducts.delete(itemId);
        updateProductsTable();
        updateSummary();
    }
}

// Update request summary
function updateSummary() {
    if (selectedProducts.size === 0) {
        $('#stockSummary').html('No products selected');
        return;
    }

    let totalQty = 0;
    let totalValue = 0;
    let itemCount = selectedProducts.size;
    let lowStockCount = 0;

    selectedProducts.forEach(product => {
        totalQty += product.quantity;
        totalValue += product.total;
        if (product.is_low_stock) {
            lowStockCount++;
        }
    });

    let summaryHtml = `
        <strong>${itemCount} products</strong> | 
        <strong>${totalQty} units</strong> | 
        <strong>${formatMoney(totalValue)} estimated total</strong>
    `;
    
    if (lowStockCount > 0) {
        summaryHtml += ` | <span class="text-danger"><i class="bx bx-error me-1"></i>${lowStockCount} low stock item${lowStockCount !== 1 ? 's' : ''}</span>`;
    }
    
    $('#stockSummary').html(summaryHtml);
}

// Submit form
function prepareFormForSubmit() {
    // Clear existing input fields
    $('input[name^="items["]').remove();
    
    // Add each product to form
    let index = 0;
    selectedProducts.forEach(product => {
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][product_id]`,
            value: product.id
        }).appendTo('#purchaseRequestForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][quantity]`,
            value: product.quantity
        }).appendTo('#purchaseRequestForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][estimated_price]`,
            value: product.price
        }).appendTo('#purchaseRequestForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][notes]`,
            value: product.notes
        }).appendTo('#purchaseRequestForm');
        
        index++;
    });
}

// Barcode scanning
function handleBarcodeScan(barcode) {
    if (BARCODE_MAP[barcode]) {
        const productId = BARCODE_MAP[barcode];
        $('#productSelect').val(productId).trigger('change');
        updateProductDetails(productId);
        setTimeout(() => $('#quantity').focus(), 100);
    }
}

// Initialize Select2 with custom template
function initializeSelect2() {
    // Supplier select
    $('.select2-supplier').select2({
        placeholder: '-- Select Supplier --',
        allowClear: false,
        width: '100%'
    });

    // Shop select
    $('.select2-shop').select2({
        placeholder: '-- Select Location --',
        allowClear: false,
        width: '100%'
    });

    // Product select with custom template
    $('.select2-products').select2({
        placeholder: '-- Type to search product --',
        allowClear: true,
        width: '100%',
        templateResult: function(product) {
            if (!product.id) return product.text;
            
            const productId = product.id;
            const prodData = PRODUCTS[productId];
            if (!prodData) return product.text;

            const $container = $(`
                <div class="product-option">
                    <div>
                        <div class="product-name">${prodData.name}</div>
                        <div class="product-code">${prodData.code} | 
                            <small class="text-muted">HSN: ${prodData.hsn || 'N/A'}</small>
                        </div>
                        <div class="product-stock-display">
                            <span class="stock-badge ${prodData.shop_stock > 0 ? 
                                (prodData.shop_stock < 10 ? 'low-stock-badge' : 'shop-stock-badge') : 
                                'out-of-stock-badge'}">
                                S:${prodData.shop_stock}
                            </span>
                            <span class="stock-badge ${prodData.warehouse_stock > 0 ? 
                                'warehouse-stock-badge' : 
                                'out-of-stock-badge'}">
                                W:${prodData.warehouse_stock}
                            </span>
                            <span class="stock-badge ${prodData.total_stock <= prodData.min_stock ? 
                                'low-stock-badge' : 'min-stock-badge'}">
                                Min:${prodData.min_stock}
                            </span>
                            <span class="stock-badge gst-badge">
                                GST:${prodData.total_gst}%
                            </span>
                        </div>
                    </div>
                    <div class="product-price">₹${prodData.price.toFixed(2)}</div>
                </div>
            `);
            
            return $container;
        },
        templateSelection: function(product) {
            if (!product.id) return product.text;
            const prodData = PRODUCTS[product.id];
            return prodData ? `${prodData.name} (${prodData.code})` : product.text;
        }
    });

    // Handle product selection
    $('#productSelect').on('select2:select', function(e) {
        const productId = e.params.data.id;
        updateProductDetails(productId);
    });

    // Clear product details when selection is cleared
    $('#productSelect').on('select2:clear', function() {
        $('#productDetails').removeClass('show');
        $('#stockDisplay').val('0');
        $('#minStockDisplay').val('0');
        $('#estimatedPrice').val('0');
        $('#quantity').val(10);
        $('#itemNotes').val('');
    });
}

// Keyboard shortcuts
function setupKeyboardShortcuts() {
    $(document).on('keydown', function(e) {
        // Alt+P to focus on product search
        if (e.altKey && e.key === 'p') {
            e.preventDefault();
            $('.select2-products').select2('open');
        }
        
        // Alt+A to add product
        if (e.altKey && e.key === 'a') {
            e.preventDefault();
            addProductToRequest();
        }
        
        // Enter in quantity field adds product
        if (e.key === 'Enter' && $('#quantity').is(':focus')) {
            e.preventDefault();
            addProductToRequest();
        }
        
        // Enter in estimated price field adds product
        if (e.key === 'Enter' && $('#estimatedPrice').is(':focus')) {
            e.preventDefault();
            addProductToRequest();
        }
        
        // Tab navigation from notes field adds product
        if (e.key === 'Tab' && !e.shiftKey && $('#itemNotes').is(':focus')) {
            e.preventDefault();
            addProductToRequest();
        }
    });
}

// Barcode scanning setup
function setupBarcodeScanning() {
    let barcodeBuffer = '';
    let lastKeyTime = 0;
    const barcodeDelay = 100; // ms between keystrokes
    
    $(document).on('keydown', function(e) {
        // If focused on a form field, don't capture barcode
        if ($(e.target).is('input, select, textarea')) return;
        
        const currentTime = new Date().getTime();
        
        // Reset buffer if too much time has passed
        if (currentTime - lastKeyTime > barcodeDelay) {
            barcodeBuffer = '';
        }
        
        // Only capture alphanumeric keys
        if (e.key.length === 1 && /[a-zA-Z0-9]/.test(e.key)) {
            barcodeBuffer += e.key;
            lastKeyTime = currentTime;
        }
        
        // Enter key indicates end of barcode
        if (e.key === 'Enter' && barcodeBuffer.length >= 3) {
            handleBarcodeScan(barcodeBuffer);
            barcodeBuffer = '';
            e.preventDefault();
        }
    });
}

// Form validation
function setupFormValidation() {
    $('#purchaseRequestForm').on('submit', function(e) {
        if (selectedProducts.size === 0) {
            e.preventDefault();
            alert('Please add at least one product to the request.');
            return false;
        }
        
        if (!$('select[name="manufacturer_id"]').val()) {
            e.preventDefault();
            alert('Please select a supplier.');
            $('select[name="manufacturer_id"]').focus();
            return false;
        }
        
        if (!$('select[name="shop_id"]').val()) {
            e.preventDefault();
            alert('Please select a destination for the stock.');
            $('select[name="shop_id"]').focus();
            return false;
        }
        
        prepareFormForSubmit();
        return true;
    });
}

// Initialize everything when document is ready
$(document).ready(function() {
    initializeSelect2();
    setupKeyboardShortcuts();
    setupBarcodeScanning();
    setupFormValidation();
    
    // Add product button click
    $('#addProductBtn').on('click', addProductToRequest);
    
    // Auto-focus on product search
    setTimeout(() => {
        $('.select2-products').select2('open');
    }, 500);
    
    // Listen for price changes to update displayed price
    $('#estimatedPrice').on('input', function() {
        const price = parseFloat($(this).val()) || 0;
        $('#costPrice').text(price.toFixed(2));
    });
    
    // Auto-calculate suggested quantity when stock display changes
    $('#stockDisplay, #minStockDisplay').on('change', function() {
        const totalStock = parseInt($('#stockDisplay').val()) || 0;
        const minStock = parseInt($('#minStockDisplay').val()) || 0;
        
        if (totalStock <= minStock) {
            const suggestedQty = Math.max(10, minStock - totalStock + 10);
            $('#quantity').val(suggestedQty);
        }
    });
});
</script>
</body>
</html>