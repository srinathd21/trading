<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
include('includes/functions.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$can_edit = in_array($user_role, ['admin', 'shop_manager', 'stock_manager']);
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$current_business_id = $_SESSION['current_business_id'] ?? null;

// Get product ID
$product_id = $_GET['id'] ?? 0;
if (!$product_id || !is_numeric($product_id)) {
    set_flash_message('error', 'Invalid product');
    header('Location: products.php');
    exit();
}

try {
    // Main product query with all fields including GST type
    $stmt = $pdo->prepare("
        SELECT p.*, c.category_name, g.hsn_code as gst_hsn_code, g.cgst_rate, g.sgst_rate, g.igst_rate,
               (COALESCE(g.cgst_rate, 0) + COALESCE(g.sgst_rate, 0) + COALESCE(g.igst_rate, 0)) AS gst_total,
               s.subcategory_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories s ON p.subcategory_id = s.id
        LEFT JOIN gst_rates g ON p.gst_id = g.id
        WHERE p.id = ? AND p.business_id = ?
    ");
    $stmt->execute([$product_id, $current_business_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        set_flash_message('error', 'Product not found');
        header('Location: products.php');
        exit();
    }
    
    // Ensure MRP is properly set - FIX THE MRP ISSUE HERE
    if (!isset($product['mrp']) || $product['mrp'] == 0 || $product['mrp'] === null) {
        // If MRP is not set or zero, use retail price or calculate from stock price
        if (isset($product['retail_price']) && $product['retail_price'] > 0) {
            $product['mrp'] = $product['retail_price'];
        } elseif (isset($product['stock_price']) && $product['stock_price'] > 0) {
            $product['mrp'] = $product['stock_price'] * 1.2; // 20% markup as fallback
        } else {
            $product['mrp'] = 0;
        }
    }
    
    // Handle old pricing structure migration
    $product['cost_price'] = $product['stock_price'] ?? 0;
    
    // Initialize pricing fields if they don't exist
    $pricing_fields = [
        'discount_type' => 'percentage',
        'discount_value' => 0,
        'retail_price_type' => 'percentage',
        'retail_price_value' => 0,
        'wholesale_price_type' => 'percentage',
        'wholesale_price_value' => 0,
        'gst_type' => 'inclusive',
        'gst_amount' => 0,
        'unit_of_measure' => 'pcs',
        'secondary_unit' => null,
        'sec_unit_conversion' => 0,
        'sec_unit_price_type' => 'fixed',
        'sec_unit_extra_charge' => 0
    ];
    
    foreach ($pricing_fields as $field => $default_value) {
        if (!isset($product[$field]) || $product[$field] === '') {
            $product[$field] = $default_value;
        }
    }
    
    // Calculate retail markup if retail_price exists and cost_price > 0
    if (isset($product['retail_price']) && $product['retail_price'] > 0 && $product['cost_price'] > 0) {
        $product['retail_price_value'] = (($product['retail_price'] - $product['cost_price']) / $product['cost_price']) * 100;
    }
    
    // Calculate wholesale markup if wholesale_price exists and cost_price > 0
    if (isset($product['wholesale_price']) && $product['wholesale_price'] > 0 && $product['cost_price'] > 0) {
        $product['wholesale_price_value'] = (($product['wholesale_price'] - $product['cost_price']) / $product['cost_price']) * 100;
    }
    
    // Calculate GST details for display
    $gst_type = $product['gst_type'] ?? 'inclusive';
    $gst_amount = $product['gst_amount'] ?? 0;
    $gst_percentage = $product['gst_total'] ?? 0;
    
    // Calculate GST breakdown for display
    $cgst_amount = 0;
    $sgst_amount = 0;
    $igst_amount = 0;
    
    if ($gst_amount > 0 && $gst_percentage > 0) {
        $total_gst_rate = ($product['cgst_rate'] ?? 0) + ($product['sgst_rate'] ?? 0) + ($product['igst_rate'] ?? 0);
        if ($total_gst_rate > 0) {
            $cgst_amount = $gst_amount * (($product['cgst_rate'] ?? 0) / $total_gst_rate);
            $sgst_amount = $gst_amount * (($product['sgst_rate'] ?? 0) / $total_gst_rate);
            $igst_amount = $gst_amount * (($product['igst_rate'] ?? 0) / $total_gst_rate);
        }
    }
    
    // Get stock in current shop
    $shop_stock = 0;
    $shop_secondary_units = 0;
    if ($current_shop_id) {
        $stmt = $pdo->prepare("SELECT quantity, total_secondary_units FROM product_stocks WHERE product_id = ? AND shop_id = ? AND business_id = ?");
        $stmt->execute([$product_id, $current_shop_id, $current_business_id]);
        $shop_stock_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $shop_stock = $shop_stock_data['quantity'] ?? 0;
        $shop_secondary_units = $shop_stock_data['total_secondary_units'] ?? 0;
    }

    // Get warehouse stock
    $warehouse_stmt = $pdo->prepare("SELECT id FROM shops WHERE is_warehouse = 1 AND business_id = ? LIMIT 1");
    $warehouse_stmt->execute([$current_business_id]);
    $warehouse_id = $warehouse_stmt->fetchColumn();

    $warehouse_stock = 0;
    $warehouse_secondary_units = 0;
    if ($warehouse_id) {
        $stmt = $pdo->prepare("SELECT quantity, total_secondary_units FROM product_stocks WHERE product_id = ? AND shop_id = ? AND business_id = ?");
        $stmt->execute([$product_id, $warehouse_id, $current_business_id]);
        $warehouse_stock_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $warehouse_stock = $warehouse_stock_data['quantity'] ?? 0;
        $warehouse_secondary_units = $warehouse_stock_data['total_secondary_units'] ?? 0;
    }

    $total_stock = $shop_stock + $warehouse_stock;
    $total_secondary_units = $shop_secondary_units + $warehouse_secondary_units;
    
    // Calculate secondary unit stock if conversion exists
    $display_secondary_units = 0;
    if ($product['sec_unit_conversion'] > 0) {
        $calculated_secondary_units = $total_stock * $product['sec_unit_conversion'];
        // Use stored secondary units if available, otherwise calculate
        $display_secondary_units = $total_secondary_units > 0 ? $total_secondary_units : $calculated_secondary_units;
    }
    
    // Calculate profit using new pricing structure
    $cost_price = $product['cost_price'] ?? $product['stock_price'] ?? 0;
    $retail_price = $product['retail_price'] ?? 0;
    $wholesale_price = $product['wholesale_price'] ?? 0;
    $mrp = $product['mrp'] ?? 0;
    
    $retail_profit_per_unit = $retail_price - $cost_price;
    $wholesale_profit_per_unit = $wholesale_price - $cost_price;
    
    $retail_profit_margin = $cost_price > 0 ? round(($retail_profit_per_unit / $cost_price) * 100, 1) : 0;
    $wholesale_profit_margin = $cost_price > 0 ? round(($wholesale_profit_per_unit / $cost_price) * 100, 1) : 0;
    
    // Calculate discount amount and percentage
    $discount_amount = 0;
    $discount_percentage = 0;
    if ($product['discount_value'] > 0) {
        if ($product['discount_type'] == 'percentage') {
            $discount_percentage = $product['discount_value'];
            $discount_amount = $mrp * ($discount_percentage / 100);
        } else {
            $discount_amount = $product['discount_value'];
            $discount_percentage = $mrp > 0 ? ($discount_amount / $mrp) * 100 : 0;
        }
    }
    
    // Format discount display
    $discount_display = '';
    if ($product['discount_value'] > 0) {
        if ($product['discount_type'] == 'percentage') {
            $discount_display = $product['discount_value'] . '%';
        } else {
            $discount_display = '₹' . number_format($product['discount_value'], 2);
        }
    }
    
    // Calculate price without GST (for GST exclusive display)
    $price_without_gst = $mrp;
    if ($gst_type == 'inclusive' && $mrp > 0 && $gst_percentage > 0) {
        // For inclusive: Price without GST = MRP / (1 + GST%)
        $price_without_gst = $mrp / (1 + ($gst_percentage / 100));
    } elseif ($gst_type == 'exclusive' && $mrp > 0 && $gst_amount > 0) {
        // For exclusive: Price without GST = MRP - GST
        $price_without_gst = $mrp - $gst_amount;
    }

} catch (Exception $e) {
    error_log("Product view error: " . $e->getMessage());
    set_flash_message('error', 'Error loading product: ' . $e->getMessage());
    header('Location: products.php');
    exit();
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Product: " . htmlspecialchars($product['product_name']); include 'includes/head.php'; ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu"><div data-simplebar class="h-100">
        <?php include 'includes/sidebar.php'; ?>
    </div></div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Breadcrumb -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <a href="products.php" class="text-muted"><i class="bx bx-arrow-back"></i> Products</a>
                                <span class="mx-2 text-muted">/</span>
                                <span><?= htmlspecialchars($product['product_name']) ?></span>
                            </h4>
                            <div>
                                <?php if ($can_edit): ?>
                                <a href="product_edit.php?id=<?= $product['id'] ?>" class="btn btn-warning">
                                    <i class="bx bx-edit"></i> Edit Product
                                </a>
                                <?php endif; ?>
                               
                            </div>
                        </div>
                    </div>
                </div>

                <?php display_flash_message(); ?>

                <div class="row">
                    <!-- Product Info -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <!-- Product Image -->
                                    <div class="col-md-4 text-center">
                                        <div class="bg-light rounded p-4">
                                            <?php if ($product['image_thumbnail_path']): ?>
                                                <img src="<?= htmlspecialchars($product['image_thumbnail_path']) ?>" 
                                                     alt="<?= htmlspecialchars($product['image_alt_text'] ?? $product['product_name']) ?>"
                                                     class="img-fluid rounded mb-3" style="max-height: 200px; object-fit: contain;">
                                            <?php else: ?>
                                                <div class="avatar-lg mx-auto mb-3 bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center">
                                                    <i class="bx bx-package font-size-48 text-primary"></i>
                                                </div>
                                            <?php endif; ?>
                                            <h4><?= htmlspecialchars($product['product_name']) ?></h4>
                                            <p class="text-muted">
                                                <strong>Code:</strong> <?= htmlspecialchars($product['product_code'] ?: '—') ?><br>
                                                <strong>Barcode:</strong> <?= htmlspecialchars($product['barcode'] ?: '—') ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- Product Details -->
                                    <div class="col-md-8">
                                        <div class="table-responsive">
                                            <table class="table table-borderless">
                                                <tr><th width="180">Category</th><td><?= htmlspecialchars($product['category_name'] ?: '—') ?></td></tr>
                                                <?php if ($product['subcategory_name']): ?>
                                                <tr><th>Subcategory</th><td><?= htmlspecialchars($product['subcategory_name']) ?></td></tr>
                                                <?php endif; ?>
                                                <tr><th>HSN Code</th><td><?= htmlspecialchars($product['hsn_code'] ?: '—') ?></td></tr>
                                                <tr><th>GST Rate</th>
                                                    <td>
                                                        <?= $gst_percentage ?: 0 ?>%
                                                        <?php if ($gst_percentage > 0): ?>
                                                            <br>
                                                            <small class="text-muted">
                                                                CGST: <?= $product['cgst_rate'] ?? 0 ?>%, 
                                                                SGST: <?= $product['sgst_rate'] ?? 0 ?>%, 
                                                                IGST: <?= $product['igst_rate'] ?? 0 ?>%
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <tr><th>GST Type</th>
                                                    <td>
                                                        <span class="badge bg-<?= $gst_type == 'inclusive' ? 'success' : 'info' ?>">
                                                            GST <?= ucfirst($gst_type) ?>
                                                        </span>
                                                        <?php if ($gst_amount > 0): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            GST Amount: ₹<?= number_format($gst_amount, 2) ?>
                                                        </small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                
                                                <!-- Unit of Measure -->
                                                <tr>
                                                    <th>Primary Unit</th>
                                                    <td>
                                                        <?= htmlspecialchars($product['unit_of_measure']) ?>
                                                        <?php if ($product['unit_of_measure'] && $product['secondary_unit']): ?>
                                                            <span class="text-muted">(Primary)</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                
                                                <!-- Secondary Unit Details -->
                                                <?php if ($product['secondary_unit']): ?>
                                                <tr>
                                                    <th>Secondary Unit</th>
                                                    <td>
                                                        <?= htmlspecialchars($product['secondary_unit']) ?>
                                                        <span class="text-muted">(Secondary)</span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th>Conversion Rate</th>
                                                    <td>
                                                        1 <?= htmlspecialchars($product['unit_of_measure']) ?> = 
                                                        <?= number_format($product['sec_unit_conversion'], 4) ?> 
                                                        <?= htmlspecialchars($product['secondary_unit']) ?>
                                                    </td>
                                                </tr>
                                                <?php if ($product['sec_unit_extra_charge'] > 0): ?>
                                                <tr>
                                                    <th>Secondary Unit Price</th>
                                                    <td>
                                                        Extra charge: 
                                                        <?php if ($product['sec_unit_price_type'] == 'percentage'): ?>
                                                            <?= number_format($product['sec_unit_extra_charge'], 2) ?>%
                                                        <?php else: ?>
                                                            ₹<?= number_format($product['sec_unit_extra_charge'], 2) ?>
                                                        <?php endif; ?>
                                                        per <?= htmlspecialchars($product['secondary_unit']) ?>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <tr><th>Min Stock Level</th><td><?= $product['min_stock_level'] ?> <?= htmlspecialchars($product['unit_of_measure']) ?></td></tr>
                                                <tr><th>Status</th>
                                                    <td>
                                                        <span class="badge bg-<?= $product['is_active'] ? 'success' : 'danger' ?>">
                                                            <?= $product['is_active'] ? 'Active' : 'Inactive' ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php if ($product['description']): ?>
                                                <tr><th>Description</th><td><?= nl2br(htmlspecialchars($product['description'])) ?></td></tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing Card -->
                        <div class="card mt-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bx bx-rupee me-2"></i> Pricing Details</h5>
                            </div>
                            <div class="card-body">
                                <!-- GST Details Section -->
                                <div class="row mb-4">
                                    <div class="col-md-12">
                                        <div class="p-3 border rounded bg-light">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <h6 class="text-muted mb-2">GST Type</h6>
                                                    <h5 class="<?= $gst_type == 'inclusive' ? 'text-success' : 'text-info' ?>">
                                                        GST <?= ucfirst($gst_type) ?>
                                                    </h5>
                                                </div>
                                                <div class="col-md-4">
                                                    <h6 class="text-muted mb-2">GST Rate</h6>
                                                    <h5 class="text-dark"><?= number_format($gst_percentage, 2) ?>%</h5>
                                                    <?php if ($gst_percentage > 0): ?>
                                                    <small class="text-muted">
                                                        CGST: <?= $product['cgst_rate'] ?? 0 ?>%,
                                                        SGST: <?= $product['sgst_rate'] ?? 0 ?>%,
                                                        IGST: <?= $product['igst_rate'] ?? 0 ?>%
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-4">
                                                    <h6 class="text-muted mb-2">GST Amount</h6>
                                                    <h5 class="text-danger">₹<?= number_format($gst_amount, 2) ?></h5>
                                                    <?php if ($gst_amount > 0 && $gst_percentage > 0): ?>
                                                    <small class="text-muted">
                                                        Included in MRP
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($gst_type == 'inclusive' && $gst_amount > 0): ?>
                                            <div class="row mt-3">
                                                <div class="col-md-12">
                                                    <small class="text-muted">
                                                        <i class="bx bx-info-circle"></i> 
                                                        MRP includes GST of ₹<?= number_format($gst_amount, 2) ?> (<?= number_format($gst_percentage, 2) ?>%)
                                                    </small>
                                                </div>
                                            </div>
                                            <?php elseif ($gst_type == 'exclusive' && $gst_amount > 0): ?>
                                            <div class="row mt-3">
                                                <div class="col-md-12">
                                                    <small class="text-muted">
                                                        <i class="bx bx-info-circle"></i> 
                                                        GST of ₹<?= number_format($gst_amount, 2) ?> (<?= number_format($gst_percentage, 2) ?>%) is added to base price
                                                    </small>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- MRP and Discount Section -->
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="p-3 border rounded">
                                            <h6 class="text-muted mb-2">MRP (Maximum Retail Price)</h6>
                                            <h4 class="text-dark">₹<?= number_format($mrp, 2) ?></h4>
                                            <?php if ($gst_type == 'inclusive' && $gst_amount > 0): ?>
                                            <small class="text-muted">
                                                Price without GST: ₹<?= number_format($price_without_gst, 2) ?>
                                            </small>
                                            <?php endif; ?>
                                            <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                                            <br>
                                            <small class="text-muted">
                                                Per <?= htmlspecialchars($product['secondary_unit']) ?>: 
                                                ₹<?= number_format($mrp / $product['sec_unit_conversion'], 4) ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="p-3 border rounded">
                                            <h6 class="text-muted mb-2">Discount</h6>
                                            <h4 class="<?= $discount_amount > 0 ? 'text-success' : 'text-muted' ?>">
                                                <?= $discount_display ?: 'No Discount' ?>
                                            </h4>
                                            <?php if ($discount_amount > 0): ?>
                                            <small class="text-muted">You save: ₹<?= number_format($discount_amount, 2) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Cost Price Section -->
                                <div class="row mb-4">
                                    <div class="col-md-12">
                                        <div class="p-3 border rounded bg-light">
                                            <h6 class="text-muted mb-2">Cost Price</h6>
                                            <h3 class="text-primary">₹<?= number_format($cost_price, 2) ?></h3>
                                            <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                                            <small class="text-muted">
                                                Per <?= htmlspecialchars($product['secondary_unit']) ?>: 
                                                ₹<?= number_format($cost_price / $product['sec_unit_conversion'], 4) ?>
                                            </small>
                                            <?php endif; ?>
                                            <?php if ($discount_amount > 0): ?>
                                            <small class="text-muted">
                                                Calculated from MRP (₹<?= number_format($mrp, 2) ?>) 
                                                <?php if ($product['discount_type'] == 'percentage'): ?>
                                                with <?= $product['discount_value'] ?>% discount
                                                <?php else: ?>
                                                less ₹<?= number_format($product['discount_value'], 2) ?>
                                                <?php endif; ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Retail Price Section -->
                                <div class="row mb-4">
                                    <div class="col-md-12">
                                        <h6 class="border-bottom pb-2 mb-3">
                                            <i class="bx bx-store-alt me-1"></i> Retail Price (For Retail Customers)
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="p-3 border rounded text-center">
                                                    <h6 class="text-muted mb-2">Markup</h6>
                                                    <h5 class="text-info">
                                                        <?php if ($product['retail_price_type'] == 'percentage'): ?>
                                                            <?= number_format($product['retail_price_value'], 1) ?>%
                                                        <?php else: ?>
                                                            ₹<?= number_format($product['retail_price_value'], 2) ?>
                                                        <?php endif; ?>
                                                    </h5>
                                                    <small class="text-muted">
                                                        <?= $product['retail_price_type'] == 'percentage' ? 'Percentage' : 'Fixed' ?> markup
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="p-3 border rounded text-center">
                                                    <h6 class="text-muted mb-2">Retail Price</h6>
                                                    <h3 class="text-success">₹<?= number_format($retail_price, 2) ?></h3>
                                                    <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                                                    <small class="text-muted">
                                                        Per <?= htmlspecialchars($product['secondary_unit']) ?>: 
                                                        ₹<?= number_format($retail_price / $product['sec_unit_conversion'], 4) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="p-3 border rounded text-center">
                                                    <h6 class="text-muted mb-2">Profit Margin</h6>
                                                    <h4 class="text-success"><?= number_format($retail_profit_margin, 1) ?>%</h4>
                                                    <small class="text-muted">
                                                        Profit: ₹<?= number_format($retail_profit_per_unit, 2) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Wholesale Price Section -->
                                <div class="row">
                                    <div class="col-md-12">
                                        <h6 class="border-bottom pb-2 mb-3">
                                            <i class="bx bx-building-house me-1"></i> Wholesale Price (For Wholesale Customers)
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="p-3 border rounded text-center">
                                                    <h6 class="text-muted mb-2">Markup</h6>
                                                    <h5 class="text-info">
                                                        <?php if ($product['wholesale_price_type'] == 'percentage'): ?>
                                                            <?= number_format($product['wholesale_price_value'], 1) ?>%
                                                        <?php else: ?>
                                                            ₹<?= number_format($product['wholesale_price_value'], 2) ?>
                                                        <?php endif; ?>
                                                    </h5>
                                                    <small class="text-muted">
                                                        <?= $product['wholesale_price_type'] == 'percentage' ? 'Percentage' : 'Fixed' ?> markup
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="p-3 border rounded text-center">
                                                    <h6 class="text-muted mb-2">Wholesale Price</h6>
                                                    <h3 class="text-info">₹<?= number_format($wholesale_price, 2) ?></h3>
                                                    <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                                                    <small class="text-muted">
                                                        Per <?= htmlspecialchars($product['secondary_unit']) ?>: 
                                                        ₹<?= number_format($wholesale_price / $product['sec_unit_conversion'], 4) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="p-3 border rounded text-center">
                                                    <h6 class="text-muted mb-2">Profit Margin</h6>
                                                    <h4 class="text-primary"><?= number_format($wholesale_profit_margin, 1) ?>%</h4>
                                                    <small class="text-muted">
                                                        Profit: ₹<?= number_format($wholesale_profit_per_unit, 2) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Summary Section -->
                                <div class="row mt-4">
                                    <div class="col-md-12">
                                        <div class="p-3 bg-light rounded">
                                            <div class="row text-center">
                                                <div class="col-md-3">
                                                    <h6>GST Status</h6>
                                                    <h5 class="<?= $gst_type == 'inclusive' ? 'text-success' : 'text-info' ?>">
                                                        <?= ucfirst($gst_type) ?>
                                                    </h5>
                                                    <small class="text-muted">Type</small>
                                                </div>
                                                <div class="col-md-3">
                                                    <h6>Retail vs Wholesale</h6>
                                                    <h5 class="text-dark">
                                                        ₹<?= number_format(($retail_price - $wholesale_price), 2) ?>
                                                    </h5>
                                                    <small class="text-muted">Difference</small>
                                                </div>
                                                <div class="col-md-3">
                                                    <h6>Best Margin</h6>
                                                    <h5 class="<?= $retail_profit_margin >= $wholesale_profit_margin ? 'text-success' : 'text-primary' ?>">
                                                        <?= max($retail_profit_margin, $wholesale_profit_margin) ?>%
                                                    </h5>
                                                    <small class="text-muted">
                                                        <?= $retail_profit_margin >= $wholesale_profit_margin ? 'Retail' : 'Wholesale' ?>
                                                    </small>
                                                </div>
                                                <div class="col-md-3">
                                                    <h6>Price Ratio</h6>
                                                    <h5 class="text-dark">
                                                        <?= $wholesale_price > 0 ? number_format(($retail_price / $wholesale_price), 2) : '0.00' ?>:1
                                                    </h5>
                                                    <small class="text-muted">Retail:Wholesale</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stock Summary -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header bg-dark text-white">
                                <h5 class="mb-0"><i class="bx bx-box me-2"></i> Stock Summary</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($current_shop_id): ?>
                                <div class="mb-4 p-3 bg-light rounded text-center">
                                    <h3 class="mb-1 <?= $shop_stock == 0 ? 'text-danger' : ($shop_stock < $product['min_stock_level'] ? 'text-warning' : 'text-success') ?>">
                                        <?= number_format($shop_stock, 4) ?>
                                    </h3>
                                    <p class="mb-0 text-muted">Current Shop Stock</p>
                                    <small>
                                        <?= htmlspecialchars($_SESSION['current_shop_name'] ?? 'Current Shop') ?>
                                        <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                                        <br>
                                        <?= number_format($shop_secondary_units > 0 ? $shop_secondary_units : ($shop_stock * $product['sec_unit_conversion']), 2) ?> 
                                        <?= htmlspecialchars($product['secondary_unit']) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <?php endif; ?>

                                <div class="text-center mb-4">
                                    <h2 class="<?= $total_stock == 0 ? 'text-danger' : ($total_stock < $product['min_stock_level'] ? 'text-warning' : 'text-success') ?>">
                                        <?= number_format($total_stock, 4) ?>
                                    </h2>
                                    <p class="mb-1">Total Available Stock</p>
                                    <small class="text-muted">Minimum Required: <?= $product['min_stock_level'] ?> <?= htmlspecialchars($product['unit_of_measure']) ?></small>
                                    
                                    <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                                    <div class="mt-2">
                                        <h5 class="text-info">
                                            <?= number_format($display_secondary_units, 2) ?> 
                                            <?= htmlspecialchars($product['secondary_unit']) ?>
                                        </h5>
                                        <small class="text-muted">
                                            Total in secondary units
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <hr>

                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="p-2">
                                            <h5><?= number_format($shop_stock, 2) ?></h5>
                                            <small class="text-muted">In Shop</small>
                                            <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                                            <br>
                                            <small class="text-muted">
                                                <?= number_format($shop_secondary_units > 0 ? $shop_secondary_units : ($shop_stock * $product['sec_unit_conversion']), 2) ?> 
                                                <?= htmlspecialchars($product['secondary_unit']) ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-2">
                                            <h5><?= number_format($warehouse_stock, 2) ?></h5>
                                            <small class="text-muted">In Warehouse</small>
                                            <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                                            <br>
                                            <small class="text-muted">
                                                <?= number_format($warehouse_secondary_units > 0 ? $warehouse_secondary_units : ($warehouse_stock * $product['sec_unit_conversion']), 2) ?> 
                                                <?= htmlspecialchars($product['secondary_unit']) ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($total_stock < $product['min_stock_level']): ?>
                                <div class="alert alert-warning mt-3 mb-0">
                                    <i class="bx bx-error"></i> <strong>Low stock alert!</strong> Below minimum level.
                                </div>
                                <?php elseif ($total_stock == 0): ?>
                                <div class="alert alert-danger mt-3 mb-0">
                                    <i class="bx bx-box"></i> <strong>Out of stock!</strong> No units available.
                                </div>
                                <?php endif; ?>

                                <!-- Stock Value -->
                                <div class="mt-4 p-3 border rounded">
                                    <h6 class="text-muted mb-2">Stock Value (at Cost Price)</h6>
                                    <h4 class="text-primary">₹<?= number_format($total_stock * $cost_price, 2) ?></h4>
                                    <small class="text-muted">
                                        Potential Retail Value: ₹<?= number_format($total_stock * $retail_price, 2) ?>
                                        <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                                        <br>
                                        Per <?= htmlspecialchars($product['secondary_unit']) ?>: 
                                        ₹<?= number_format(($total_stock * $cost_price) / $display_secondary_units, 4) ?>
                                        (cost)
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- GST Details -->
                        <?php if ($gst_percentage > 0): ?>
                        <div class="card mt-3">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bx bx-receipt me-2"></i> GST Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="140">GST Type</th>
                                            <td>
                                                <span class="badge bg-<?= $gst_type == 'inclusive' ? 'success' : 'info' ?>">
                                                    GST <?= ucfirst($gst_type) ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Total GST Rate</th>
                                            <td><?= number_format($gst_percentage, 2) ?>%</td>
                                        </tr>
                                        <tr>
                                            <th>CGST Rate</th>
                                            <td><?= $product['cgst_rate'] ?? 0 ?>%</td>
                                        </tr>
                                        <tr>
                                            <th>SGST Rate</th>
                                            <td><?= $product['sgst_rate'] ?? 0 ?>%</td>
                                        </tr>
                                        <tr>
                                            <th>IGST Rate</th>
                                            <td><?= $product['igst_rate'] ?? 0 ?>%</td>
                                        </tr>
                                        <?php if ($gst_amount > 0): ?>
                                        <tr>
                                            <th>GST Amount</th>
                                            <td>₹<?= number_format($gst_amount, 2) ?></td>
                                        </tr>
                                        <?php if ($gst_percentage > 0): ?>
                                        <tr>
                                            <th>CGST Amount</th>
                                            <td>₹<?= number_format($cgst_amount, 2) ?></td>
                                        </tr>
                                        <tr>
                                            <th>SGST Amount</th>
                                            <td>₹<?= number_format($sgst_amount, 2) ?></td>
                                        </tr>
                                        <tr>
                                            <th>IGST Amount</th>
                                            <td>₹<?= number_format($igst_amount, 2) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr>
                                            <th>Price without GST</th>
                                            <td>₹<?= number_format($price_without_gst, 2) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($gst_type == 'inclusive'): ?>
                                        <tr>
                                            <th>Calculation</th>
                                            <td>
                                                <small class="text-muted">
                                                    MRP includes GST: ₹<?= number_format($mrp, 2) ?> = 
                                                    ₹<?= number_format($price_without_gst, 2) ?> + 
                                                    ₹<?= number_format($gst_amount, 2) ?> GST
                                                </small>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <tr>
                                            <th>Calculation</th>
                                            <td>
                                                <small class="text-muted">
                                                    MRP = Base Price + GST: 
                                                    ₹<?= number_format($price_without_gst, 2) ?> + 
                                                    ₹<?= number_format($gst_amount, 2) ?> = 
                                                    ₹<?= number_format($mrp, 2) ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Unit Conversion Info -->
                        <?php if ($product['secondary_unit'] && $product['sec_unit_conversion'] > 0): ?>
                        <div class="card mt-3">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bx bx-transfer me-2"></i> Unit Conversion</h5>
                            </div>
                            <div class="card-body">
                                <div class="text-center">
                                    <div class="display-4 mb-3">
                                        1 <?= htmlspecialchars($product['unit_of_measure']) ?>
                                        <i class="bx bx-right-arrow-alt mx-2 text-muted"></i>
                                        <?= number_format($product['sec_unit_conversion'], 4) ?> <?= htmlspecialchars($product['secondary_unit']) ?>
                                    </div>
                                    
                                    <?php if ($product['sec_unit_extra_charge'] > 0): ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="bx bx-info-circle"></i>
                                        <strong>Secondary unit pricing:</strong><br>
                                        Extra charge of 
                                        <?php if ($product['sec_unit_price_type'] == 'percentage'): ?>
                                            <?= number_format($product['sec_unit_extra_charge'], 2) ?>% 
                                            (of primary unit price)
                                        <?php else: ?>
                                            ₹<?= number_format($product['sec_unit_extra_charge'], 2) ?>
                                        <?php endif; ?>
                                        per <?= htmlspecialchars($product['secondary_unit']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            <i class="bx bx-calculator"></i> Conversion is used for sales in secondary units
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Additional Information -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bx bx-info-circle me-2"></i> Additional Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="140">Product ID</th>
                                        <td><?= $product['id'] ?></td>
                                    </tr>
                                    <tr>
                                        <th>Created</th>
                                        <td><?= date('d M Y', strtotime($product['created_at'])) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Last Updated</th>
                                        <td><?= date('d M Y', strtotime($product['updated_at'] ?? $product['created_at'])) ?></td>
                                    </tr>
                                    <?php if ($product['referral_enabled']): ?>
                                    <tr>
                                        <th>Referral Commission</th>
                                        <td>
                                            <span class="badge bg-success">Enabled</span>
                                            <?= $product['referral_value'] ?>
                                            <?= $product['referral_type'] == 'percentage' ? '%' : '₹' ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($product['image_alt_text']): ?>
                                    <tr>
                                        <th>Image Alt Text</th>
                                        <td><?= htmlspecialchars($product['image_alt_text']) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($product['secondary_unit']): ?>
                                    <tr>
                                        <th>Measurement Type</th>
                                        <td>
                                            <span class="badge bg-info">Dual Units</span>
                                            <?= htmlspecialchars($product['unit_of_measure']) ?> + 
                                            <?= htmlspecialchars($product['secondary_unit']) ?>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <tr>
                                        <th>Measurement Type</th>
                                        <td>
                                            <span class="badge bg-secondary">Single Unit</span>
                                            <?= htmlspecialchars($product['unit_of_measure']) ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bx bx-rocket me-2"></i> Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <?php if ($current_shop_id && in_array($user_role, ['admin', 'shop_manager', 'stock_manager'])): ?>
                                    <a href="stock_adjustment.php?product_id=<?= $product['id'] ?>" class="btn btn-outline-primary">
                                        <i class="bx bx-adjust"></i> Adjust Stock
                                    </a>
                                    <?php endif; ?>
                                   
                                    <?php if ($can_edit): ?>
                                    <a href="product_edit.php?id=<?= $product['id'] ?>" class="btn btn-outline-warning">
                                        <i class="bx bx-edit"></i> Edit Product Details
                                    </a>
                                    <?php endif; ?>
                                    <a href="stock_history.php?product_id=<?= $product['id'] ?>" class="btn btn-outline-info">
                                        <i class="bx bx-history"></i> View Stock History
                                    </a>
                                    <?php if ($product['secondary_unit']): ?>
                                    <a href="#" class="btn btn-outline-secondary" onclick="showUnitConversion()">
                                        <i class="bx bx-calculator"></i> Show Unit Calculator
                                    </a>
                                    <?php endif; ?>
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

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
// Add any JavaScript functionality if needed
document.addEventListener('DOMContentLoaded', function() {
    // Optional: Add any interactivity here
});

function showUnitConversion() {
    const primaryUnit = '<?= htmlspecialchars($product['unit_of_measure']) ?>';
    const secondaryUnit = '<?= htmlspecialchars($product['secondary_unit']) ?>';
    const conversionRate = <?= $product['sec_unit_conversion'] ?: 1 ?>;
    
    Swal.fire({
        title: 'Unit Conversion Calculator',
        html: `
            <div class="text-center">
                <div class="mb-4">
                    <h4>1 ${primaryUnit} = ${conversionRate} ${secondaryUnit}</h4>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>${primaryUnit} to ${secondaryUnit}</label>
                        <input type="number" id="primaryToSecondary" class="form-control" placeholder="Enter ${primaryUnit}" step="0.01" oninput="calculateSecondary(this.value)">
                        <div class="mt-2 text-muted" id="secondaryResult"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>${secondaryUnit} to ${primaryUnit}</label>
                        <input type="number" id="secondaryToPrimary" class="form-control" placeholder="Enter ${secondaryUnit}" step="0.01" oninput="calculatePrimary(this.value)">
                        <div class="mt-2 text-muted" id="primaryResult"></div>
                    </div>
                </div>
                <?php if ($product['sec_unit_extra_charge'] > 0): ?>
                <div class="alert alert-info mt-3">
                    <strong>Pricing Note:</strong><br>
                    Extra charge of 
                    <?php if ($product['sec_unit_price_type'] == 'percentage'): ?>
                        <?= number_format($product['sec_unit_extra_charge'], 2) ?>% 
                        applied per ${secondaryUnit}
                    <?php else: ?>
                        ₹<?= number_format($product['sec_unit_extra_charge'], 2) ?> 
                        per ${secondaryUnit}
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        `,
        showConfirmButton: true,
        confirmButtonText: 'Close',
        showCloseButton: true,
        width: 600
    });
    
    window.calculateSecondary = function(value) {
        if (value && !isNaN(value)) {
            const result = value * conversionRate;
            document.getElementById('secondaryResult').innerHTML = 
                `<strong>${result.toFixed(4)} ${secondaryUnit}</strong>`;
            document.getElementById('secondaryToPrimary').value = '';
        } else {
            document.getElementById('secondaryResult').innerHTML = '';
        }
    };
    
    window.calculatePrimary = function(value) {
        if (value && !isNaN(value)) {
            const result = value / conversionRate;
            document.getElementById('primaryResult').innerHTML = 
                `<strong>${result.toFixed(4)} ${primaryUnit}</strong>`;
            document.getElementById('primaryToSecondary').value = '';
        } else {
            document.getElementById('primaryResult').innerHTML = '';
        }
    };
}
</script>

<style>
.avatar-lg {
    width: 120px;
    height: 120px;
}
.border-dashed {
    border-style: dashed;
}
.text-summary {
    font-size: 0.9rem;
}
.card-header {
    border-bottom: 2px solid rgba(0,0,0,.125);
}
.table-borderless th {
    font-weight: 600;
    color: #495057;
}
.bg-light {
    background-color: #f8f9fa !important;
}
.display-4 {
    font-size: 2rem;
}
</style>
</body>
</html>