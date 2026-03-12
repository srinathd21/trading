<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'warehouse_manager'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: purchase_requests.php');
    exit();
}

// Fetch Request with business filter
$stmt = $pdo->prepare("
    SELECT pr.*, m.name AS manufacturer_name,
           u.full_name as requested_by_name
    FROM purchase_requests pr
    LEFT JOIN manufacturers m ON pr.manufacturer_id = m.id
    LEFT JOIN users u ON pr.requested_by = u.id
    WHERE pr.id = ? AND pr.business_id = ? AND pr.status IN ('draft', 'sent')
");
$stmt->execute([$id, $business_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    die('<div class="alert alert-danger m-4">Request not found or cannot be edited (status: ' . htmlspecialchars($request['status'] ?? 'unknown') . ')</div>');
}

$success = $error = '';

// Fetch Products & Stock with business filter
$products = $pdo->prepare("
    SELECT p.id, p.product_name, p.product_code, p.stock_price,
           COALESCE(SUM(ps.quantity), 0) AS current_stock,
           p.min_stock_level,
           (COALESCE(SUM(ps.quantity), 0) <= p.min_stock_level) as is_low_stock
    FROM products p
    LEFT JOIN product_stocks ps ON ps.product_id = p.id AND ps.business_id = p.business_id
    WHERE p.business_id = ?
      AND p.is_active = 1
    GROUP BY p.id 
    ORDER BY is_low_stock DESC, p.product_name
")->execute([$business_id]) ? $pdo->query("SELECT p.id, p.product_name, p.product_code, p.stock_price, COALESCE(SUM(ps.quantity), 0) AS current_stock, p.min_stock_level, (COALESCE(SUM(ps.quantity), 0) <= p.min_stock_level) as is_low_stock FROM products p LEFT JOIN product_stocks ps ON ps.product_id = p.id AND ps.business_id = p.business_id WHERE p.business_id = $business_id AND p.is_active = 1 GROUP BY p.id ORDER BY is_low_stock DESC, p.product_name")->fetchAll() : [];

$manufacturers = $pdo->prepare("
    SELECT id, name 
    FROM manufacturers 
    WHERE business_id = ? 
      AND is_active = 1 
    ORDER BY name
")->execute([$business_id]) ? $pdo->query("SELECT id, name FROM manufacturers WHERE business_id = $business_id AND is_active = 1 ORDER BY name")->fetchAll() : [];

// Fetch Current Items
$items = $pdo->prepare("
    SELECT pri.*, p.product_name, p.product_code
    FROM purchase_request_items pri
    JOIN products p ON pri.product_id = p.id
    WHERE pri.purchase_request_id = ?
    ORDER BY pri.id
")->execute([$id]) ? $pdo->query("SELECT pri.*, p.product_name, p.product_code FROM purchase_request_items pri JOIN products p ON pri.product_id = p.id WHERE pri.purchase_request_id = $id ORDER BY pri.id")->fetchAll() : [];

// Process Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manufacturer_id = (int)($_POST['manufacturer_id'] ?? 0);
    $request_notes   = trim($_POST['request_notes'] ?? '');
    $new_items       = $_POST['items'] ?? [];

    if ($manufacturer_id <= 0 || empty($new_items)) {
        $error = "Please select supplier and add at least one product.";
    } else {
        try {
            $pdo->beginTransaction();

            // Update main request
            $pdo->prepare("
                UPDATE purchase_requests 
                SET manufacturer_id = ?, request_notes = ?, updated_at = NOW()
                WHERE id = ? AND business_id = ?
            ")->execute([$manufacturer_id, $request_notes, $id, $business_id]);

            // Delete old items
            $pdo->prepare("DELETE FROM purchase_request_items WHERE purchase_request_id = ?")->execute([$id]);

            $total_estimated = 0;
            foreach ($new_items as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);
                $price = (float)($item['estimated_price'] ?? 0);
                $notes = trim($item['notes'] ?? '');

                if ($pid > 0 && $qty > 0 && $price >= 0) {
                    $amount = $qty * $price;
                    $total_estimated += $amount;

                    $pdo->prepare("
                        INSERT INTO purchase_request_items 
                        (purchase_request_id, product_id, quantity, estimated_price, notes)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([$id, $pid, $qty, $price, $notes]);
                }
            }

            // Update total
            $pdo->prepare("UPDATE purchase_requests SET total_estimated_amount = ? WHERE id = ?")
                ->execute([$total_estimated, $id]);

            $pdo->commit();
            header("Location: purchase_request_edit.php?id=$id&success=1");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Update failed: " . $e->getMessage();
        }
    }
}
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Edit Purchase Request #{$request['request_number']}"; 
include 'includes/head.php'; 
?>
<!-- Add Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
    border-radius: 0.375rem;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}
.item-row {
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.item-row:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}
.low-stock-badge {
    font-size: 0.75rem;
    padding: 2px 6px;
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
                                    <i class="bx bx-edit me-2"></i> Edit Purchase Request
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-hash me-1"></i> <?= htmlspecialchars($request['request_number']) ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="purchase_request_view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to View
                                </a>
                                <a href="purchase_requests.php" class="btn btn-outline-primary">
                                    <i class="bx bx-list-ul me-1"></i> All Requests
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i>
                    Purchase request <strong><?= htmlspecialchars($request['request_number']) ?></strong> updated successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" id="editForm">
                    <div class="row g-4">
                        <!-- Request Details Card -->
                        <div class="col-lg-4">
                            <div class="card card-hover border-start border-primary border-4 shadow-sm h-100">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-detail me-2"></i> Edit Request Details
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Request Number</label>
                                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($request['request_number']) ?>" readonly>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Status</label>
                                        <?php 
                                        $status_color = $request['status'] === 'sent' ? 'success' : 'warning';
                                        $status_icon = $request['status'] === 'sent' ? 'bx-send' : 'bx-edit';
                                        ?>
                                        <div class="d-flex align-items-center">
                                            <span class="badge bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> px-3 py-2 me-2">
                                                <i class="bx <?= $status_icon ?> me-1"></i><?= ucfirst($request['status']) ?>
                                            </span>
                                            <small class="text-muted">
                                                <?= $request['status'] === 'sent' ? 'Sent to supplier' : 'Draft mode' ?>
                                            </small>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Supplier <span class="text-danger">*</span></label>
                                        <select name="manufacturer_id" class="form-select select2-supplier" required>
                                            <option value="">-- Select Supplier --</option>
                                            <?php foreach ($manufacturers as $m): ?>
                                            <option value="<?= $m['id'] ?>" <?= $m['id'] == $request['manufacturer_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($m['name']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Requested By</label>
                                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($request['requested_by_name']) ?>" readonly>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Created On</label>
                                        <input type="text" class="form-control bg-light" value="<?= date('d M Y, h:i A', strtotime($request['created_at'])) ?>" readonly>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Request Notes (Optional)</label>
                                        <textarea name="request_notes" class="form-control" rows="4" placeholder="Any special instructions, delivery urgency, etc."><?= htmlspecialchars($request['request_notes'] ?? '') ?></textarea>
                                    </div>

                                    <div class="alert alert-info">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <small>
                                            <strong>Note:</strong> You can edit this request while it's in draft or sent status.
                                            Once converted to a purchase order, editing will be restricted.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Products Section -->
                        <div class="col-lg-8">
                            <div class="card shadow-sm">
                                <div class="card-header bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <i class="bx bx-package me-2"></i> Request Items
                                            <small class="text-muted ms-2">(Edit quantities, prices, or add/remove items)</small>
                                        </h5>
                                        <span class="badge bg-primary" id="itemCount"><?= count($items) ?> Items</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <!-- Add Product Section -->
                                    <div class="row g-3 mb-4 p-3 border rounded bg-light">
                                        <div class="col-md-6">
                                            <label class="form-label">Add New Product</label>
                                            <select class="form-select select2-products" id="commonProductSelect">
                                                <option value="">-- Select Product to Add --</option>
                                                <?php foreach ($products as $p): 
                                                    $low_stock = $p['is_low_stock'] ? 'text-danger' : '';
                                                ?>
                                                <option value="<?= $p['id'] ?>" 
                                                        data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                                        data-code="<?= htmlspecialchars($p['product_code']) ?>"
                                                        data-price="<?= $p['stock_price'] ?>"
                                                        data-stock="<?= $p['current_stock'] ?>"
                                                        data-min="<?= $p['min_stock_level'] ?>"
                                                        class="<?= $low_stock ?>">
                                                    <?= htmlspecialchars($p['product_name']) ?> 
                                                    <?php if (!empty($p['product_code'])): ?>
                                                    (<?= htmlspecialchars($p['product_code']) ?>)
                                                    <?php endif; ?>
                                                    - Stock: <?= $p['current_stock'] ?>
                                                    - ₹<?= number_format($p['stock_price'], 2) ?>
                                                    <?php if ($p['is_low_stock']): ?>
                                                    <span class="text-danger"> - LOW STOCK</span>
                                                    <?php endif; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Est. Price</label>
                                            <input type="number" step="0.01" id="commonPrice" class="form-control" value="0">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Qty</label>
                                            <input type="number" id="commonQuantity" class="form-control" min="1" value="10">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">&nbsp;</label>
                                            <button type="button" id="addProductBtn" class="btn btn-primary w-100">
                                                <i class="bx bx-plus me-1"></i> Add
                                            </button>
                                        </div>
                                        <div class="col-md-12">
                                            <label class="form-label">Notes (Optional)</label>
                                            <input type="text" id="commonNotes" class="form-control" placeholder="e.g. Urgent, specific requirements">
                                        </div>
                                    </div>

                                    <!-- Selected Products Table -->
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="selectedProductsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="5%">#</th>
                                                    <th width="30%">Product</th>
                                                    <th width="10%" class="text-center">Current Stock</th>
                                                    <th width="10%" class="text-center">Req. Qty</th>
                                                    <th width="15%" class="text-center">Est. Price</th>
                                                    <th width="15%" class="text-end">Total</th>
                                                    <th width="15%" class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="selectedProductsBody">
                                                <?php if (empty($items)): ?>
                                                <tr id="emptyRow" class="text-center">
                                                    <td colspan="7" class="py-4">
                                                        <i class="bx bx-package fs-1 text-muted mb-3 d-block"></i>
                                                        <p class="text-muted">No products added yet</p>
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                <?php foreach ($items as $i => $item): 
                                                    $total = $item['estimated_price'] * $item['quantity'];
                                                    $product_info = array_filter($products, fn($p) => $p['id'] == $item['product_id']);
                                                    $product = $product_info ? array_values($product_info)[0] : null;
                                                    $is_low_stock = $product && $product['is_low_stock'];
                                                ?>
                                                <tr class="item-row <?= $is_low_stock ? 'low-stock' : '' ?>" data-id="<?= $item['product_id'] ?>" data-index="<?= $i ?>">
                                                    <td class="text-center fw-bold"><?= $i + 1 ?></td>
                                                    <td>
                                                        <div class="d-flex flex-column">
                                                            <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                                            <?php if ($item['product_code']): ?>
                                                            <small class="text-muted"><?= htmlspecialchars($item['product_code']) ?></small>
                                                            <?php endif; ?>
                                                            <?php if ($item['notes']): ?>
                                                            <small class="text-info mt-1"><i class="bx bx-note me-1"></i><?= htmlspecialchars($item['notes']) ?></small>
                                                            <?php endif; ?>
                                                            <?php if ($is_low_stock): ?>
                                                            <span class="badge bg-danger low-stock-badge mt-1"><i class="bx bx-error-alt me-1"></i>Low Stock</span>
                                                            <?php endif; ?>
                                                            <input type="hidden" name="items[<?= $i ?>][product_id]" value="<?= $item['product_id'] ?>">
                                                            <input type="hidden" name="items[<?= $i ?>][quantity]" class="qty-hidden" value="<?= $item['quantity'] ?>">
                                                            <input type="hidden" name="items[<?= $i ?>][estimated_price]" class="price-hidden" value="<?= $item['estimated_price'] ?>">
                                                            <input type="hidden" name="items[<?= $i ?>][notes]" class="notes-hidden" value="<?= htmlspecialchars($item['notes']) ?>">
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($product): ?>
                                                        <span class="badge bg-<?= $is_low_stock ? 'danger' : 'success' ?> bg-opacity-10 text-<?= $is_low_stock ? 'danger' : 'success' ?> px-3 py-1">
                                                            <?= $product['current_stock'] ?>
                                                        </span>
                                                        <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="d-flex align-items-center justify-content-center">
                                                            <button type="button" class="btn btn-sm btn-outline-secondary qty-btn" data-action="decrease">
                                                                <i class="bx bx-minus"></i>
                                                            </button>
                                                            <span class="badge bg-primary rounded-pill px-3 py-1 mx-2 qty-display"><?= $item['quantity'] ?></span>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary qty-btn" data-action="increase">
                                                                <i class="bx bx-plus"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="input-group input-group-sm justify-content-center">
                                                            <span class="input-group-text">₹</span>
                                                            <input type="number" step="0.01" 
                                                                   class="form-control price-input text-end" 
                                                                   value="<?= $item['estimated_price'] ?>"
                                                                   style="width: 100px;">
                                                        </div>
                                                    </td>
                                                    <td class="text-end fw-bold item-total">
                                                        ₹<span class="total-display"><?= number_format($total, 2) ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn">
                                                            <i class="bx bx-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr>
                                                    <td colspan="5" class="text-end fw-bold">Estimated Total:</td>
                                                    <td class="text-end fw-bold" id="estimatedTotal">
                                                        ₹<span id="estimatedTotalValue"><?= number_format($request['total_estimated_amount'], 2) ?></span>
                                                    </td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>

                                    <div class="alert alert-info mt-3">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Request Summary:</strong>
                                        <span id="stockSummary">
                                            <?= count($items) ?> items | 
                                            Total: ₹<?= number_format($request['total_estimated_amount'], 2) ?> | 
                                            Low Stock Items: <span id="lowStockCount"><?= array_reduce($items, function($count, $item) use ($products) {
                                                $product_info = array_filter($products, fn($p) => $p['id'] == $item['product_id']);
                                                $product = $product_info ? array_values($product_info)[0] : null;
                                                return $count + ($product && $product['is_low_stock'] ? 1 : 0);
                                            }, 0) ?></span>
                                        </span>
                                    </div>

                                    <hr>

                                    <div class="text-end">
                                        <a href="purchase_request_view.php?id=<?= $id ?>" class="btn btn-outline-secondary me-2">
                                            <i class="bx bx-x me-1"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                                            <i class="bx bx-save me-2"></i> Update Purchase Request
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
$(document).ready(function() {
    // Initialize Select2
    $('.select2-products, .select2-supplier').select2({
        placeholder: "Select...",
        allowClear: true,
        width: '100%',
        theme: 'classic'
    });

    let selectedProducts = new Map();
    let itemIndex = <?= count($items) ?>;
    
    // Initialize with existing items
    <?php foreach ($items as $item): 
        $product_info = array_filter($products, fn($p) => $p['id'] == $item['product_id']);
        $product = $product_info ? array_values($product_info)[0] : null;
    ?>
    selectedProducts.set('<?= $item['product_id'] ?>', {
        id: '<?= $item['product_id'] ?>',
        name: '<?= addslashes($item['product_name']) ?>',
        code: '<?= addslashes($item['product_code']) ?>',
        stock: <?= $product ? $product['current_stock'] : 0 ?>,
        min_stock: <?= $product ? $product['min_stock_level'] : 10 ?>,
        price: <?= $item['estimated_price'] ?>,
        quantity: <?= $item['quantity'] ?>,
        notes: '<?= addslashes($item['notes']) ?>',
        is_low_stock: <?= $product && $product['is_low_stock'] ? 'true' : 'false' ?>,
        total: <?= $item['estimated_price'] * $item['quantity'] ?>
    });
    <?php endforeach; ?>

    // Update common fields when product selected
    $(document).on('change', '#commonProductSelect', function() {
        const option = $(this).find('option:selected');
        if (option.length && option.val()) {
            $('#commonPrice').val(option.data('price') || 0);
            const totalStock = option.data('stock') || 0;
            const minStock = option.data('min') || 10;
            
            // Auto-set quantity for low stock items
            if (totalStock < minStock) {
                $('#commonQuantity').val(minStock - totalStock);
            }
        }
    });

    // Add product button
    $(document).on('click', '#addProductBtn', function() {
        const select = $('#commonProductSelect');
        const option = select.find('option:selected');
        
        if (!option.length || !option.val()) {
            alert('Please select a product first');
            return;
        }

        const productId = option.val();
        const productName = option.data('name');
        const productCode = option.data('code');
        const currentStock = parseFloat(option.data('stock')) || 0;
        const minStock = parseFloat(option.data('min')) || 10;
        const price = parseFloat($('#commonPrice').val()) || 0;
        const quantity = parseInt($('#commonQuantity').val()) || 1;
        const notes = $('#commonNotes').val();
        const isLowStock = currentStock < minStock;

        if (price <= 0) {
            alert('Estimated price must be greater than 0');
            return;
        }

        if (quantity <= 0) {
            alert('Quantity must be greater than 0');
            return;
        }

        // Check if product already added
        if (selectedProducts.has(productId)) {
            alert('This product is already in the list');
            return;
        }

        // Calculate total
        const total = price * quantity;

        // Add to selected products
        selectedProducts.set(productId, {
            id: productId,
            name: productName,
            code: productCode,
            stock: currentStock,
            min_stock: minStock,
            price: price,
            quantity: quantity,
            notes: notes,
            is_low_stock: isLowStock,
            total: total
        });

        // Update table
        updateProductsTable();
        updateSummary();

        // Reset common selector
        select.val(null).trigger('change');
        $('#commonPrice').val('0');
        $('#commonQuantity').val(10);
        $('#commonNotes').val('');
    });

    // Update products table
    function updateProductsTable() {
        const tbody = $('#selectedProductsBody');
        tbody.empty();
        let totalAmount = 0;
        let lowStockCount = 0;
        let rowIndex = 0;

        if (selectedProducts.size === 0) {
            tbody.append('<tr id="emptyRow" class="text-center"><td colspan="7" class="py-4"><i class="bx bx-package fs-1 text-muted mb-3 d-block"></i><p class="text-muted">No products added yet</p></td></tr>');
            $('#estimatedTotalValue').text('0.00');
            $('#itemCount').text('0 Items');
            $('#lowStockCount').text('0');
            $('#submitBtn').prop('disabled', true);
            return;
        }

        selectedProducts.forEach((product, productId) => {
            totalAmount += product.total;
            if (product.is_low_stock) lowStockCount++;
            
            const row = $(`
                <tr class="item-row ${product.is_low_stock ? 'low-stock' : ''}" data-key="${productId}">
                    <td class="text-center fw-bold">${rowIndex + 1}</td>
                    <td>
                        <div class="d-flex flex-column">
                            <strong>${product.name}</strong>
                            ${product.code ? `<small class="text-muted">${product.code}</small>` : ''}
                            ${product.notes ? `<small class="text-info mt-1"><i class="bx bx-note me-1"></i>${product.notes}</small>` : ''}
                            ${product.is_low_stock ? '<span class="badge bg-danger low-stock-badge mt-1"><i class="bx bx-error-alt me-1"></i>Low Stock</span>' : ''}
                            <input type="hidden" name="items[${rowIndex}][product_id]" value="${productId}">
                            <input type="hidden" name="items[${rowIndex}][quantity]" class="qty-hidden" value="${product.quantity}">
                            <input type="hidden" name="items[${rowIndex}][estimated_price]" class="price-hidden" value="${product.price}">
                            <input type="hidden" name="items[${rowIndex}][notes]" class="notes-hidden" value="${product.notes}">
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-${product.is_low_stock ? 'danger' : 'success'} bg-opacity-10 text-${product.is_low_stock ? 'danger' : 'success'} px-3 py-1">
                            ${product.stock}
                        </span>
                    </td>
                    <td class="text-center">
                        <div class="d-flex align-items-center justify-content-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary qty-btn" data-action="decrease">
                                <i class="bx bx-minus"></i>
                            </button>
                            <span class="badge bg-primary rounded-pill px-3 py-1 mx-2 qty-display">${product.quantity}</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary qty-btn" data-action="increase">
                                <i class="bx bx-plus"></i>
                            </button>
                        </div>
                    </td>
                    <td class="text-center">
                        <div class="input-group input-group-sm justify-content-center">
                            <span class="input-group-text">₹</span>
                            <input type="number" step="0.01" 
                                   class="form-control price-input text-end" 
                                   value="${product.price.toFixed(2)}"
                                   style="width: 100px;">
                        </div>
                    </td>
                    <td class="text-end fw-bold item-total">
                        ₹<span class="total-display">${product.total.toFixed(2)}</span>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn">
                            <i class="bx bx-trash"></i>
                        </button>
                    </td>
                </tr>
            `);
            tbody.append(row);
            rowIndex++;
        });

        $('#estimatedTotalValue').text(totalAmount.toFixed(2));
        $('#lowStockCount').text(lowStockCount);
        const itemCount = selectedProducts.size;
        $('#itemCount').text(`${itemCount} Item${itemCount !== 1 ? 's' : ''}`);
        $('#submitBtn').prop('disabled', itemCount === 0);
    }

    // Update summary
    function updateSummary() {
        if (selectedProducts.size === 0) {
            $('#stockSummary').html('No products selected');
            return;
        }

        let totalQty = 0;
        let totalAmount = 0;
        let lowStockCount = 0;

        selectedProducts.forEach((product) => {
            totalQty += product.quantity;
            totalAmount += product.total;
            if (product.is_low_stock) lowStockCount++;
        });

        const summary = `Total Items: <strong>${selectedProducts.size}</strong> | Total Quantity: <strong>${totalQty}</strong> | Estimated Amount: <strong>₹${totalAmount.toFixed(2)}</strong> | Low Stock Items: <strong>${lowStockCount}</strong>`;
        $('#stockSummary').html(summary);
    }

    // Handle quantity buttons for EXISTING items (delegated event)
    $(document).on('click', '.qty-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const row = $(this).closest('tr');
        const productId = row.data('id') || row.data('key');
        
        if (!productId) {
            console.error('Product ID not found');
            return;
        }
        
        const product = selectedProducts.get(productId.toString());
        if (!product) {
            console.error('Product not found in selectedProducts');
            return;
        }
        
        const action = $(this).data('action');
        
        if (action === 'increase') {
            product.quantity += 1;
        } else if (action === 'decrease' && product.quantity > 1) {
            product.quantity -= 1;
        }
        
        // Update total
        product.total = product.price * product.quantity;
        selectedProducts.set(productId.toString(), product);
        
        // Update row display
        row.find('.qty-display').text(product.quantity);
        row.find('.qty-hidden').val(product.quantity);
        row.find('.total-display').text(product.total.toFixed(2));
        
        // Update form hidden inputs (for existing items)
        const rowIndex = row.data('index');
        if (rowIndex !== undefined) {
            row.find('input[name="items[' + rowIndex + '][quantity]"]').val(product.quantity);
        }
        
        // Update totals and summary
        updateProductsTable();
        updateSummary();
    });

    // Handle price input changes
    $(document).on('change', '.price-input', function() {
        const row = $(this).closest('tr');
        const productKey = row.data('key');
        const product = selectedProducts.get(productKey);
        
        product.price = parseFloat($(this).val()) || 0;
        if (product.price < 0) product.price = 0;
        
        // Update total
        product.total = product.price * product.quantity;
        selectedProducts.set(productKey, product);
        
        // Update row
        row.find('.price-hidden').val(product.price);
        row.find('.total-display').text(product.total.toFixed(2));
        
        updateProductsTable();
        updateSummary();
    });

    // Handle remove button
    $(document).on('click', '.remove-btn', function() {
        const row = $(this).closest('tr');
        const productKey = row.data('key');
        
        if (confirm('Are you sure you want to remove this item from the request?')) {
            selectedProducts.delete(productKey);
            updateProductsTable();
            updateSummary();
        }
    });

    // Form validation
    $('#editForm').on('submit', function(e) {
        if (selectedProducts.size === 0) {
            e.preventDefault();
            alert('Please add at least one product to the request');
            return;
        }

        // Validate required fields
        if (!$('select[name="manufacturer_id"]').val()) {
            e.preventDefault();
            alert('Please select a supplier');
            return;
        }
    });
});
</script>
</body>
</html>