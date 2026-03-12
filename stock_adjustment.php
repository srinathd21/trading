<!-- stock_adjustment.php -->
<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) { 
    header('Location: login.php'); 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['admin', 'warehouse_manager', 'stock_manager', 'shop_manager'])) {
    $_SESSION['error'] = "Access denied.";
    header('Location: dashboard.php');
    exit();
}

$current_business_id = (int) $_SESSION['current_business_id'];
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
if (!$current_shop_id) { 
    header('Location: select_shop.php'); 
    exit(); 
}

$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
$shop_stmt->execute([$current_shop_id, $current_business_id]);
$shop_name = $shop_stmt->fetchColumn();

// Fetch products for dropdown
$products_stmt = $pdo->prepare("
    SELECT p.id, p.product_name, p.product_code, p.barcode, 
           COALESCE(ps.quantity, 0) as current_stock,
           c.category_name
    FROM products p
    LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ?
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.business_id = ? AND p.is_active = 1
    ORDER BY p.product_name
");
$products_stmt->execute([$current_shop_id, $current_business_id]);
$products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent adjustments for history
$adjustments_stmt = $pdo->prepare("
    SELECT sa.*, p.product_name, p.product_code, u.full_name as adjusted_by_name
    FROM stock_adjustments sa
    LEFT JOIN products p ON sa.product_id = p.id
    LEFT JOIN users u ON sa.adjusted_by = u.id
    WHERE sa.shop_id = ? AND p.business_id = ?
    ORDER BY sa.adjusted_at DESC
    LIMIT 50
");
$adjustments_stmt->execute([$current_shop_id, $current_business_id]);
$recent_adjustments = $adjustments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_stock'])) {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $errors = [];

    // Validate
    if ($product_id <= 0) {
        $errors[] = "Please select a product";
    }
    if (!in_array($type, ['add', 'remove', 'set'])) {
        $errors[] = "Invalid adjustment type";
    }
    if ($quantity <= 0) {
        $errors[] = "Quantity must be greater than 0";
    }
    if (empty($reason)) {
        $errors[] = "Reason is required";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Get current stock
            $stock_stmt = $pdo->prepare("
                SELECT COALESCE(quantity, 0) as current_qty 
                FROM product_stocks 
                WHERE product_id = ? AND shop_id = ?
            ");
            $stock_stmt->execute([$product_id, $current_shop_id]);
            $current_stock = $stock_stmt->fetchColumn();

            // Calculate new stock
            if ($type === 'add') {
                $new_stock = $current_stock + $quantity;
            } elseif ($type === 'remove') {
                if ($quantity > $current_stock) {
                    throw new Exception("Cannot remove $quantity units. Only $current_stock units available.");
                }
                $new_stock = $current_stock - $quantity;
            } elseif ($type === 'set') {
                $new_stock = $quantity;
                $quantity = $quantity - $current_stock; // For adjustment record
            }

            if ($new_stock < 0) {
                throw new Exception("Stock cannot be negative!");
            }

            // Generate adjustment number
            $prefix = "ADJ" . date('Ymd');
            $count_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM stock_adjustments 
                WHERE adjustment_number LIKE ? AND shop_id = ?
            ");
            $count_stmt->execute([$prefix . '%', $current_shop_id]);
            $count = $count_stmt->fetchColumn() + 1;
            $adjustment_number = $prefix . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

            // Insert adjustment record
            $adj_stmt = $pdo->prepare("
                INSERT INTO stock_adjustments 
                (adjustment_number, product_id, shop_id, adjustment_type, quantity, 
                 old_stock, new_stock, reason, notes, adjusted_by, adjusted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $adj_stmt->execute([
                $adjustment_number,
                $product_id,
                $current_shop_id,
                $type,
                $quantity,
                $current_stock,
                $new_stock,
                $reason,
                $notes,
                $_SESSION['user_id']
            ]);

            // Update stock
            $upsert_stmt = $pdo->prepare("
                INSERT INTO product_stocks (product_id, shop_id, business_id, quantity, last_updated) 
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                quantity = VALUES(quantity),
                last_updated = NOW()
            ");
            $upsert_stmt->execute([$product_id, $current_shop_id, $current_business_id, $new_stock]);

            $pdo->commit();

            $_SESSION['success'] = "Stock adjusted successfully! Adjustment #$adjustment_number";
            header('Location: stock_adjustment.php');
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Stock Adjustment - " . htmlspecialchars($shop_name); 
include 'includes/head.php'; 
?>
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
                                    <i class="bx bx-transfer me-2"></i> Stock Adjustment
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-store me-1"></i><?= htmlspecialchars($shop_name) ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">Adjust stock quantities for this location</p>
                            </div>
                            <div>
                                <a href="products.php" class="btn btn-outline-primary">
                                    <i class="bx bx-package me-1"></i> View Products
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alerts -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>

                <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i>
                    <ul class="mb-0">
                        <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Adjustment Form -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm mb-4">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-edit me-1"></i> Make Adjustment
                                </h5>
                                <form method="POST" id="adjustmentForm">
                                    <input type="hidden" name="adjust_stock" value="1">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Select Product <span class="text-danger">*</span></label>
                                        <select class="form-select select2" name="product_id" id="productSelect" required data-placeholder="Search product...">
                                            <option value=""></option>
                                            <?php foreach ($products as $product): ?>
                                            <option value="<?= $product['id'] ?>" 
                                                    data-stock="<?= $product['current_stock'] ?>"
                                                    data-code="<?= htmlspecialchars($product['product_code'] ?? '') ?>"
                                                    data-category="<?= htmlspecialchars($product['category_name'] ?? '') ?>">
                                                <?= htmlspecialchars($product['product_name']) ?> 
                                                <?php if (!empty($product['product_code'])): ?>
                                                (<?= htmlspecialchars($product['product_code']) ?>)
                                                <?php endif; ?>
                                                - Stock: <?= $product['current_stock'] ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="mt-2">
                                            <small class="text-muted" id="selectedProductInfo"></small>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Adjustment Type <span class="text-danger">*</span></label>
                                            <select class="form-select" name="type" id="adjustmentType" required>
                                                <option value="add">Add Stock</option>
                                                <option value="remove">Remove Stock</option>
                                                <option value="set">Set to Specific Value</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" id="quantityLabel">Quantity to Add <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="number" name="quantity" class="form-control" 
                                                       id="adjustmentQuantity" min="1" value="1" required>
                                                <span class="input-group-text">units</span>
                                            </div>
                                            <small class="text-muted" id="quantityHelp"></small>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                                        <select class="form-select" name="reason" id="reasonSelect" required>
                                            <option value="">Select reason</option>
                                            <option value="Purchase">Purchase Order</option>
                                            <option value="Return">Customer Return</option>
                                            <option value="Damage">Damaged Goods</option>
                                            <option value="Count Error">Stock Count Error</option>
                                            <option value="Transfer">Warehouse Transfer</option>
                                            <option value="Promotion">Promotion/Gift</option>
                                            <option value="Expiry">Expired Stock</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Notes (Optional)</label>
                                        <textarea name="notes" class="form-control" rows="3" 
                                                  placeholder="Additional details, reference numbers, etc."></textarea>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="bx bx-save me-2"></i> Adjust Stock
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions & Info -->
                    <div class="col-lg-6">
                        <!-- Current Selection Info -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-info-circle me-1"></i> Product Information
                                </h5>
                                <div class="text-center" id="noSelection">
                                    <div class="avatar-lg mx-auto mb-3">
                                        <div class="avatar-title bg-light text-primary rounded-circle">
                                            <i class="bx bx-package fs-1"></i>
                                        </div>
                                    </div>
                                    <p class="text-muted mb-0">Select a product to view details</p>
                                </div>
                                <div id="productDetails" style="display: none;">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="avatar-sm me-3">
                                            <div class="avatar-title bg-light text-primary rounded">
                                                <i class="bx bx-package fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h5 class="mb-0" id="detailProductName"></h5>
                                            <p class="text-muted mb-0">
                                                <span id="detailProductCode"></span>
                                                • <span id="detailCategory"></span>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="card card-hover border-start border-primary border-4">
                                                <div class="card-body py-2">
                                                    <h6 class="text-muted mb-1">Current Stock</h6>
                                                    <h3 class="mb-0 text-primary" id="detailCurrentStock">0</h3>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="card card-hover border-start border-warning border-4">
                                                <div class="card-body py-2">
                                                    <h6 class="text-muted mb-1">After Adjustment</h6>
                                                    <h3 class="mb-0 text-warning" id="detailNewStock">0</h3>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Adjustments -->
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-history me-1"></i> Recent Adjustments
                                </h5>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th>Type</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">New</th>
                                                <th>By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recent_adjustments)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bx bx-package display-4 mb-3"></i>
                                                        <p>No adjustments yet</p>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($recent_adjustments as $adj): 
                                                $type_class = $adj['adjustment_type'] === 'add' ? 'success' : 'danger';
                                                $type_icon = $adj['adjustment_type'] === 'add' ? 'bx-up-arrow-alt' : 'bx-down-arrow-alt';
                                            ?>
                                            <tr>
                                                <td>
                                                    <small class="text-muted"><?= htmlspecialchars(substr($adj['adjustment_number'], -6)) ?></small>
                                                </td>
                                                <td>
                                                    <div>
                                                        <div class="fw-medium"><?= htmlspecialchars($adj['product_name']) ?></div>
                                                        <small class="text-muted"><?= htmlspecialchars($adj['product_code'] ?? '') ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $type_class ?> bg-opacity-10 text-<?= $type_class ?>">
                                                        <i class="bx <?= $type_icon ?> me-1"></i>
                                                        <?= ucfirst($adj['adjustment_type']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($adj['adjustment_type'] === 'add'): ?>
                                                    <span class="text-success">+<?= $adj['quantity'] ?></span>
                                                    <?php else: ?>
                                                    <span class="text-danger">-<?= abs($adj['quantity']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="fw-medium"><?= $adj['new_stock'] ?></span>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?= htmlspecialchars($adj['adjusted_by_name'] ?? 'User') ?></small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?= date('d M H:i', strtotime($adj['adjusted_at'])) ?>
                                                    </small>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (!empty($recent_adjustments)): ?>
                                <div class="text-center mt-3">
                                    <a href="adjustment_history.php" class="btn btn-outline-secondary btn-sm">
                                        <i class="bx bx-list-ul me-1"></i> View All History
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<!-- Select2 for better dropdown -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });

    // Update product details when selection changes
    $('#productSelect').on('change', function() {
        const option = $(this).find('option:selected');
        const productId = option.val();
        const currentStock = parseInt(option.data('stock')) || 0;
        const productCode = option.data('code') || 'No Code';
        const category = option.data('category') || 'Uncategorized';
        const productName = option.text().split(' - Stock:')[0].trim();

        if (productId) {
            // Show product details
            $('#noSelection').hide();
            $('#productDetails').show();
            
            // Update details
            $('#detailProductName').text(productName);
            $('#detailProductCode').text(productCode);
            $('#detailCategory').text(category);
            $('#detailCurrentStock').text(currentStock);
            
            // Update current stock in form
            $('#currentStock').text(currentStock);
            $('#selectedProductInfo').html(`
                <span class="badge bg-primary">${category}</span>
                <span class="badge bg-light text-dark">${productCode}</span>
                <span class="badge bg-info">Stock: ${currentStock}</span>
            `);
            
            // Update quantity validation for remove type
            updateQuantityValidation(currentStock);
            calculateNewStock();
        } else {
            // Hide details
            $('#noSelection').show();
            $('#productDetails').hide();
            $('#selectedProductInfo').html('');
        }
    });

    // Update quantity label based on adjustment type
    function updateQuantityLabel() {
        const type = $('#adjustmentType').val();
        const labels = {
            'add': 'Quantity to Add',
            'remove': 'Quantity to Remove',
            'set': 'New Stock Value'
        };
        $('#quantityLabel').text(labels[type] + ' *');
    }

    // Update quantity validation
    function updateQuantityValidation(currentStock) {
        const type = $('#adjustmentType').val();
        
        if (type === 'remove') {
            $('#adjustmentQuantity').attr('max', currentStock);
            $('#quantityHelp').text(`Maximum you can remove: ${currentStock} units`);
        } else if (type === 'set') {
            $('#adjustmentQuantity').removeAttr('max');
            $('#quantityHelp').text('Set the exact stock quantity');
        } else {
            $('#adjustmentQuantity').removeAttr('max');
            $('#quantityHelp').text('Enter the quantity to add to current stock');
        }
    }

    // Calculate new stock
    function calculateNewStock() {
        const type = $('#adjustmentType').val();
        const currentStock = parseInt($('#detailCurrentStock').text()) || 0;
        const quantity = parseInt($('#adjustmentQuantity').val()) || 0;
        
        let newStock = currentStock;
        if (type === 'add') {
            newStock = currentStock + quantity;
        } else if (type === 'remove') {
            newStock = currentStock - quantity;
        } else if (type === 'set') {
            newStock = quantity;
        }
        
        $('#detailNewStock').text(newStock);
        
        // Color code based on change
        if (newStock > currentStock) {
            $('#detailNewStock').removeClass('text-warning text-danger').addClass('text-success');
        } else if (newStock < currentStock) {
            $('#detailNewStock').removeClass('text-warning text-success').addClass('text-danger');
        } else {
            $('#detailNewStock').removeClass('text-success text-danger').addClass('text-warning');
        }
    }

    // Event listeners
    $('#adjustmentType').change(function() {
        updateQuantityLabel();
        const currentStock = parseInt($('#detailCurrentStock').text()) || 0;
        updateQuantityValidation(currentStock);
        calculateNewStock();
    });

    $('#adjustmentQuantity').on('input', calculateNewStock);

    // Initialize
    updateQuantityLabel();
    updateQuantityValidation(0);

    // Form validation
    $('#adjustmentForm').submit(function(e) {
        const type = $('#adjustmentType').val();
        const quantity = parseInt($('#adjustmentQuantity').val()) || 0;
        const currentStock = parseInt($('#detailCurrentStock').text()) || 0;
        const productId = $('#productSelect').val();
        
        if (!productId) {
            e.preventDefault();
            alert('Please select a product');
            $('#productSelect').focus();
            return false;
        }
        
        if (type === 'remove' && quantity > currentStock) {
            e.preventDefault();
            alert(`Cannot remove ${quantity} units. Only ${currentStock} units available.`);
            $('#adjustmentQuantity').focus();
            return false;
        }
        
        if (quantity <= 0) {
            e.preventDefault();
            alert('Quantity must be greater than 0');
            $('#adjustmentQuantity').focus();
            return false;
        }
        
        return true;
    });
});
</script>
<style>
.select2-container--bootstrap-5 .select2-selection {
    min-height: 38px;
    border: 1px solid #ced4da;
}
.select2-container--bootstrap-5 .select2-selection:focus {
    border-color: #86b7fe;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}
.card-hover:hover {
    transform: translateY(-2px);
    transition: transform 0.2s;
}
</style>
</body>
</html>