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

// Get current business ID from session
$current_business_id = $_SESSION['current_business_id'] ?? null;
if (!$current_business_id) {
    $_SESSION['error'] = "Please select a business first.";
    header('Location: select_shop.php');
    exit();
}

// Get query parameters
$preSelectedProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$preSelectedSource = isset($_GET['source']) ? (int)$_GET['source'] : 0;

// Get Warehouse - if source is provided in URL, use it, otherwise get default
if ($preSelectedSource > 0) {
    $warehouse_stmt = $pdo->prepare("
        SELECT id, shop_name 
        FROM shops 
        WHERE id = ? 
          AND business_id = ? 
          AND is_warehouse = 1 
          AND is_active = 1
    ");
    $warehouse_stmt->execute([$preSelectedSource, $current_business_id]);
    $warehouse = $warehouse_stmt->fetch();
} else {
    $warehouse_stmt = $pdo->prepare("
        SELECT id, shop_name 
        FROM shops 
        WHERE business_id = ? 
          AND is_warehouse = 1 
          AND is_active = 1 
        LIMIT 1
    ");
    $warehouse_stmt->execute([$current_business_id]);
    $warehouse = $warehouse_stmt->fetch();
}

if (!$warehouse) {
    $_SESSION['error'] = "No warehouse found for your business!";
    header('Location: dashboard.php');
    exit();
}
$warehouse_id = $warehouse['id'];

// Get all active branch shops from current business only
$shops_stmt = $pdo->prepare("
    SELECT id, shop_name, shop_code 
    FROM shops 
    WHERE business_id = ? 
      AND is_active = 1 
      AND is_warehouse = 0 
    ORDER BY shop_name
");
$shops_stmt->execute([$current_business_id]);
$shops = $shops_stmt->fetchAll();

// Handle form submission
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to_shop_id = (int)($_POST['to_shop_id'] ?? 0);
    $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];

    if ($to_shop_id <= 0) {
        $error = "Please select a destination shop.";
    } elseif (empty($items)) {
        $error = "Please add at least one product.";
    } else {
        try {
            $pdo->beginTransaction();

            // Generate transfer number
            $ym = date('Ym', strtotime($transfer_date));
            $count_stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM stock_transfers 
                WHERE business_id = ? 
                  AND transfer_number LIKE 'TRF{$ym}-%'
            ");
            $count_stmt->execute([$current_business_id]);
            $count = $count_stmt->fetchColumn();
            $transfer_number = "TRF{$ym}-" . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

            // Create transfer
            $stmt = $pdo->prepare("
                INSERT INTO stock_transfers 
                (transfer_number, from_shop_id, to_shop_id, transfer_date, notes, 
                 created_by, status, total_items, total_quantity, business_id)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
            ");
            $total_items = 0;
            $total_qty = 0;
            foreach ($items as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity'])) {
                    $total_items++;
                    $total_qty += (int)$item['quantity'];
                }
            }
            $stmt->execute([
                $transfer_number, 
                $warehouse_id, 
                $to_shop_id, 
                $transfer_date, 
                $notes, 
                $_SESSION['user_id'], 
                $total_items, 
                $total_qty,
                $current_business_id
            ]);
            $transfer_id = $pdo->lastInsertId();

            // Insert items
            $item_stmt = $pdo->prepare("
                INSERT INTO stock_transfer_items 
                (stock_transfer_id, product_id, quantity, business_id) 
                VALUES (?, ?, ?, ?)
            ");
            foreach ($items as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);
                if ($pid > 0 && $qty > 0) {
                    // Validate stock - check product belongs to current business
                    $avail = $pdo->prepare("
                        SELECT COALESCE(ps.quantity,0) 
                        FROM product_stocks ps
                        INNER JOIN products p ON ps.product_id = p.id
                        WHERE ps.product_id = ? 
                          AND ps.shop_id = ? 
                          AND p.business_id = ?
                    ");
                    $avail->execute([$pid, $warehouse_id, $current_business_id]);
                    $available = $avail->fetchColumn();
                    
                    if ($available === false) {
                        throw new Exception("Product not found in warehouse or doesn't belong to your business");
                    }
                    
                    // Allow zero quantity transfers for tracking purposes
                    if ($qty > $available) {
                        $pname_stmt = $pdo->prepare("
                            SELECT product_name 
                            FROM products 
                            WHERE id = ? 
                              AND business_id = ?
                        ");
                        $pname_stmt->execute([$pid, $current_business_id]);
                        $pname = $pname_stmt->fetchColumn();
                        throw new Exception("Not enough stock for '$pname' (Available: $available)");
                    }
                    
                    $item_stmt->execute([$transfer_id, $pid, $qty, $current_business_id]);
                }
            }

            $pdo->commit();
            $_SESSION['success'] = "Stock Transfer <strong>$transfer_number</strong> created successfully!";
            header('Location: stock_transfers.php');
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get ALL products from current business - even those with zero stock in warehouse
$products_stmt = $pdo->prepare("
    SELECT p.id, p.product_name, p.product_code, p.barcode, p.retail_price,
           COALESCE(ps.quantity, 0) AS warehouse_stock,
           c.category_name,
           s.subcategory_name
    FROM products p
    LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ?
    LEFT JOIN categories c ON p.category_id = c.id AND c.business_id = p.business_id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id AND s.business_id = p.business_id
    WHERE p.is_active = 1 
      AND p.business_id = ?
    ORDER BY p.product_name
");
$products_stmt->execute([$warehouse_id, $current_business_id]);
$products = $products_stmt->fetchAll();

// Get pre-selected product details if provided in URL - verify it belongs to current business
$preSelectedProduct = null;
if ($preSelectedProductId > 0) {
    $product_stmt = $pdo->prepare("
        SELECT p.id, p.product_name, p.product_code, p.barcode, p.retail_price,
               COALESCE(ps.quantity, 0) AS warehouse_stock,
               c.category_name,
               s.subcategory_name
        FROM products p
        LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ?
        LEFT JOIN categories c ON p.category_id = c.id AND c.business_id = p.business_id
        LEFT JOIN subcategories s ON p.subcategory_id = s.id AND s.business_id = p.business_id
        WHERE p.id = ? 
          AND p.is_active = 1
          AND p.business_id = ?
    ");
    $product_stmt->execute([$warehouse_id, $preSelectedProductId, $current_business_id]);
    $preSelectedProduct = $product_stmt->fetch();
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = "New Stock Transfer"; include 'includes/head.php'; ?>
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
.shop-stock-display {
    font-size: 0.85em;
    color: #6c757d;
}
</style>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu"><div data-simplebar class="h-100">
        <?php include 'includes/sidebar.php'; ?>
    </div></div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">New Stock Transfer</h4>
                            <a href="stock_transfers.php" class="btn btn-outline-secondary">Back to List</a>
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bx bx-check-circle me-2"></i><?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bx bx-error me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" id="transferForm">
                    <div class="row g-4">

                        <!-- Transfer Info -->
                        <div class="col-lg-4">
                            <div class="card shadow-sm h-100">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">Transfer Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">From (Source)</label>
                                        <input type="text" class="form-control bg-white" value="<?= htmlspecialchars($warehouse['shop_name']) ?> (Warehouse)" readonly>
                                        <input type="hidden" id="warehouseId" value="<?= $warehouse_id ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">To (Destination) <span class="text-danger">*</span></label>
                                        <select name="to_shop_id" class="form-select select2-destination" required id="destinationShop">
                                            <option value="">-- Select Shop --</option>
                                            <?php foreach($shops as $shop): ?>
                                            <option value="<?= $shop['id'] ?>">
                                                <?= htmlspecialchars($shop['shop_name']) ?> (<?= $shop['shop_code'] ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Transfer Date</label>
                                        <input type="date" name="transfer_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Notes (Optional)</label>
                                        <textarea name="notes" class="form-control" rows="4"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Products Section -->
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">Products to Transfer</h5>
                                        <span class="badge bg-primary" id="itemCount"><?= ($preSelectedProductId > 0) ? '1 Item' : '0 Items' ?></span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <!-- Common Product Selector -->
                                    <div class="row g-3 mb-4 p-3 border rounded bg-light">
                                        <div class="col-md-7">
                                            <label class="form-label">Add Product</label>
                                            <select class="form-select select2-products" id="commonProductSelect">
                                                <option value="">-- Select Product to Add --</option>
                                                <?php foreach($products as $p): ?>
                                                <?php 
                                                // Get shop stock for pre-selected shop if any
                                                $shop_stock_display = '';
                                                if ($preSelectedSource > 0 && $preSelectedSource != $warehouse_id) {
                                                    $shop_stock_stmt = $pdo->prepare("
                                                        SELECT COALESCE(quantity, 0) 
                                                        FROM product_stocks 
                                                        WHERE product_id = ? 
                                                          AND shop_id = ? 
                                                          AND business_id = ?
                                                    ");
                                                    $shop_stock_stmt->execute([$p['id'], $preSelectedSource, $current_business_id]);
                                                    $shop_stock = $shop_stock_stmt->fetchColumn();
                                                    $shop_stock_display = " | Shop: $shop_stock";
                                                }
                                                ?>
                                                <option value="<?= $p['id'] ?>" 
                                                        data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                                        data-code="<?= htmlspecialchars($p['product_code']) ?>"
                                                        data-stock="<?= $p['warehouse_stock'] ?>"
                                                        data-shop-stock="<?= $shop_stock ?? 0 ?>"
                                                        data-price="<?= $p['retail_price'] ?>"
                                                        data-category="<?= htmlspecialchars($p['category_name'] ?? '') ?>"
                                                        data-subcategory="<?= htmlspecialchars($p['subcategory_name'] ?? '') ?>"
                                                        <?= $preSelectedProductId == $p['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($p['product_name']) ?> 
                                                    <?php if (!empty($p['product_code'])): ?>
                                                    (<?= htmlspecialchars($p['product_code']) ?>)
                                                    <?php endif; ?>
                                                    - WH: <?= $p['warehouse_stock'] ?><?= $shop_stock_display ?>
                                                    - ₹<?= number_format($p['retail_price'], 2) ?>
                                                    <?php if (!empty($p['category_name'])): ?>
                                                    - <?= htmlspecialchars($p['category_name']) ?>
                                                    <?php endif; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">WH Stock</label>
                                            <input type="text" id="commonWarehouseStock" class="form-control bg-white" readonly value="<?= $preSelectedProduct ? $preSelectedProduct['warehouse_stock'] : '-' ?>">
                                        </div>
                                        
                                        <div class="col-md-2">
                                            <label class="form-label">Qty</label>
                                            <input type="number" id="commonQuantity" class="form-control" min="1" value="1">
                                        </div>
                                        <div class="col-md-1 d-flex align-items-end">
                                            <button type="button" id="addProductBtn" class="btn btn-primary w-100">
                                                <i class="bx bx-plus"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Selected Products Table -->
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="selectedProductsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="5%">#</th>
                                                    <th width="25%">Product</th>
                                                    <th width="15%">Category</th>
                                                    <th width="15%" class="text-end">Price</th>
                                                    <th width="15%" class="text-end">Stock</th>
                                                    <th width="15%" class="text-end">Quantity</th>
                                                    <th width="5%" class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="selectedProductsBody">
                                                <!-- Items will be added here dynamically -->
                                                <?php if ($preSelectedProductId > 0 && $preSelectedProduct): ?>
                                                <?php 
                                                // Get shop stock if destination is selected
                                                $shop_stock_display = '';
                                                if ($preSelectedSource > 0 && $preSelectedSource != $warehouse_id) {
                                                    $shop_stock_stmt = $pdo->prepare("
                                                        SELECT COALESCE(quantity, 0) 
                                                        FROM product_stocks 
                                                        WHERE product_id = ? 
                                                          AND shop_id = ? 
                                                          AND business_id = ?
                                                    ");
                                                    $shop_stock_stmt->execute([$preSelectedProductId, $preSelectedSource, $current_business_id]);
                                                    $shop_stock = $shop_stock_stmt->fetchColumn();
                                                    $shop_stock_display = "<br><small class='text-muted shop-stock-display'>Shop: $shop_stock</small>";
                                                }
                                                ?>
                                                <tr id="row-<?= $preSelectedProductId ?>">
                                                    <td>1</td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($preSelectedProduct['product_name']) ?></strong>
                                                        <?php if (!empty($preSelectedProduct['product_code'])): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($preSelectedProduct['product_code']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($preSelectedProduct['category_name'] ?? '') ?>
                                                        <?php if (!empty($preSelectedProduct['subcategory_name'])): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($preSelectedProduct['subcategory_name']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">₹<?= number_format($preSelectedProduct['retail_price'], 2) ?></td>
                                                    <td class="text-end">
                                                        <span class="badge bg-primary">WH: <?= $preSelectedProduct['warehouse_stock'] ?></span>
                                                        <?= $shop_stock_display ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <input type="hidden" name="items[0][product_id]" value="<?= $preSelectedProductId ?>">
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="items[0][quantity]" 
                                                                   class="form-control text-end quantity-input" 
                                                                   value="1" 
                                                                   min="1"
                                                                   data-product-id="<?= $preSelectedProductId ?>">
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" data-product-id="<?= $preSelectedProductId ?>">
                                                            <i class="bx bx-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr class="table-light">
                                                    <td colspan="5" class="text-end fw-bold">Total:</td>
                                                    <td class="text-end fw-bold" id="totalQuantity"><?= ($preSelectedProductId > 0) ? '1' : '0' ?></td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>

                                    <div class="alert alert-info mt-3">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Stock Summary:</strong>
                                        <span id="stockSummary">
                                            <?php if ($preSelectedProductId > 0): ?>
                                            Total Items: <strong>1</strong> | Total Quantity: <strong>1</strong>
                                            <?php else: ?>
                                            No products selected
                                            <?php endif; ?>
                                        </span>
                                    </div>

                                    <hr>

                                    <div class="text-end">
                                        <button type="submit" class="btn btn-success btn-lg px-5" id="submitBtn" <?= ($preSelectedProductId > 0) ? '' : 'disabled' ?>>
                                            Create Transfer
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

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>
<!-- Add Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Initialize Select2 for product dropdown
$(document).ready(function() {
    $('.select2-products').select2({
        placeholder: "Search and select product...",
        allowClear: true,
        width: '100%',
        theme: 'classic'
    });
    
    $('.select2-destination').select2({
        placeholder: "Select destination shop...",
        allowClear: false,
        width: '100%',
        theme: 'classic'
    });
});

let itemIndex = <?= ($preSelectedProductId > 0) ? '1' : '0' ?>;
let selectedProducts = new Map(); // Store selected products by ID

// Initialize with pre-selected product if exists
<?php if ($preSelectedProductId > 0 && $preSelectedProduct): ?>
selectedProducts.set('<?= $preSelectedProductId ?>', {
    id: '<?= $preSelectedProductId ?>',
    name: '<?= addslashes($preSelectedProduct['product_name']) ?>',
    code: '<?= addslashes($preSelectedProduct['product_code'] ?? '') ?>',
    stock: <?= $preSelectedProduct['warehouse_stock'] ?>,
    price: <?= $preSelectedProduct['retail_price'] ?>,
    category: '<?= addslashes($preSelectedProduct['category_name'] ?? '') ?>',
    subcategory: '<?= addslashes($preSelectedProduct['subcategory_name'] ?? '') ?>',
    quantity: 1,
    shopStock: <?= $shop_stock ?? 0 ?>
});
<?php endif; ?>

// Function to fetch shop stock for a product
async function getShopStock(productId, shopId) {
    if (!shopId) return 0;
    try {
        const response = await fetch(`ajax/get_shop_stock.php?product_id=${productId}&shop_id=${shopId}`);
        const data = await response.json();
        return data.stock || 0;
    } catch (error) {
        console.error('Error fetching shop stock:', error);
        return 0;
    }
}

// Update stock display when product selected
$(document).on('change', '#commonProductSelect', async function() {
    const option = $(this).find('option:selected');
    if (option.length && option.val()) {
        const stock = option.data('stock') || 0;
        $('#commonWarehouseStock').val(stock);
        $('#commonQuantity').val(Math.min(1, stock));
        $('#commonQuantity').attr('max', Math.max(0, stock));
    } else {
        $('#commonWarehouseStock').val('-');
        $('#commonQuantity').val(1);
        $('#commonQuantity').removeAttr('max');
    }
});

// Add product button
$(document).on('click', '#addProductBtn', async function() {
    const select = $('#commonProductSelect');
    const option = select.find('option:selected');
    const shopId = $('#destinationShop').val();
    
    if (!option.length || !option.val()) {
        alert('Please select a product first');
        return;
    }

    const productId = option.val();
    const productName = option.data('name');
    const productCode = option.data('code');
    const stock = parseInt(option.data('stock')) || 0;
    const price = parseFloat(option.data('price')) || 0;
    const category = option.data('category') || '';
    const subcategory = option.data('subcategory') || '';
    const quantity = parseInt($('#commonQuantity').val()) || 1;

    // Validate quantity
    if (quantity <= 0) {
        alert('Quantity must be greater than 0');
        return;
    }

    if (quantity > stock) {
        alert(`Cannot transfer more than available warehouse stock! Available: ${stock}`);
        return;
    }

    // Check if product already exists
    if (selectedProducts.has(productId)) {
        alert('This product is already in the list. You can update quantity in the table.');
        return;
    }

    // Get shop stock if destination selected
    let shopStock = 0;
    if (shopId) {
        shopStock = await getShopStock(productId, shopId);
    }

    // Add to selected products map
    selectedProducts.set(productId, {
        id: productId,
        name: productName,
        code: productCode,
        stock: stock,
        price: price,
        category: category,
        subcategory: subcategory,
        quantity: quantity,
        shopStock: shopStock
    });

    // Update table
    updateProductsTable();
    updateSummary();

    // Reset common selector
    select.val(null).trigger('change');
    $('#commonWarehouseStock').val('-');
    $('#commonQuantity').val(1);
});

// Update products table
async function updateProductsTable() {
    const tbody = $('#selectedProductsBody');
    tbody.empty();
    let totalQty = 0;
    let rowIndex = 0;
    const shopId = $('#destinationShop').val();

    for (const [productId, product] of selectedProducts.entries()) {
        totalQty += product.quantity;
        
        // Get shop stock if destination selected
        let currentShopStock = product.shopStock;
        if (shopId) {
            currentShopStock = await getShopStock(productId, shopId);
        }
        
        const row = $(`
            <tr id="row-${productId}">
                <td>${rowIndex + 1}</td>
                <td>
                    <strong>${product.name}</strong>
                    ${product.code ? `<br><small class="text-muted">${product.code}</small>` : ''}
                </td>
                <td>
                    ${product.category}
                    ${product.subcategory ? `<br><small class="text-muted">${product.subcategory}</small>` : ''}
                </td>
                <td class="text-end">₹${product.price.toFixed(2)}</td>
                <td class="text-end">
                    <div>
                        <span class="badge bg-primary">WH: ${product.stock}</span>
                        ${shopId ? `<br><small class="text-muted shop-stock-display" data-product-id="${productId}">Shop: ${currentShopStock}</small>` : ''}
                    </div>
                </td>
                <td class="text-end">
                    <input type="hidden" name="items[${rowIndex}][product_id]" value="${productId}">
                    <div class="input-group input-group-sm">
                        <input type="number" name="items[${rowIndex}][quantity]" 
                               class="form-control text-end quantity-input" 
                               value="${product.quantity}" 
                               min="1" 
                               data-product-id="${productId}"
                               max="${product.stock}">
                    </div>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-btn" data-product-id="${productId}">
                        <i class="bx bx-trash"></i>
                    </button>
                </td>
            </tr>
        `);
        tbody.append(row);
        rowIndex++;
    }

    $('#totalQuantity').text(totalQty);
    const itemCount = selectedProducts.size;
    $('#itemCount').text(`${itemCount} Item${itemCount !== 1 ? 's' : ''}`);
    
    // Enable/disable submit button
    $('#submitBtn').prop('disabled', itemCount === 0);
}

// Update stock summary
function updateSummary() {
    if (selectedProducts.size === 0) {
        $('#stockSummary').html('No products selected');
        return;
    }

    let totalQty = 0;
    selectedProducts.forEach((product) => {
        totalQty += product.quantity;
    });

    let summary = `Total Items: <strong>${selectedProducts.size}</strong> | Total Quantity: <strong>${totalQty}</strong>`;
    
    $('#stockSummary').html(summary);
}

// Handle quantity changes
$(document).on('input', '.quantity-input', function(e) {
    const productId = $(this).data('product-id');
    const newQty = parseInt($(this).val()) || 0;
    const maxQty = parseInt($(this).attr('max')) || 0;
    const product = selectedProducts.get(productId);
    
    if (product) {
        if (newQty > maxQty) {
            alert(`Cannot exceed warehouse stock of ${maxQty}`);
            $(this).val(product.quantity);
            return;
        }
        
        if (newQty <= 0) {
            alert('Quantity must be greater than 0');
            $(this).val(product.quantity);
            return;
        }
        
        product.quantity = newQty;
        selectedProducts.set(productId, product);
        updateSummary();
    }
});

// Handle remove button - FIXED VERSION
$(document).on('click', '.remove-btn', function(e) {
    e.preventDefault();
    
    const productId = $(this).data('product-id');
    
    if (confirm('Are you sure you want to remove this product?')) {
        // Remove from Map
        selectedProducts.delete(productId);
        
        // Remove from table
        $(`#row-${productId}`).remove();
        
        // Update counters and summary
        updateSummary();
        
        // Re-index table rows
        reindexTableRows();
        
        // Update item count
        const itemCount = selectedProducts.size;
        $('#itemCount').text(`${itemCount} Item${itemCount !== 1 ? 's' : ''}`);
        
        // Enable/disable submit button
        $('#submitBtn').prop('disabled', itemCount === 0);
    }
});

// Re-index table rows after deletion
function reindexTableRows() {
    $('#selectedProductsBody tr').each(function(index) {
        // Update serial number
        $(this).find('td:first').text(index + 1);
        
        // Update hidden input names
        const productId = $(this).find('.remove-btn').data('product-id');
        const quantityInput = $(this).find('.quantity-input');
        const hiddenInput = $(this).find('input[type="hidden"]');
        
        hiddenInput.attr('name', `items[${index}][product_id]`);
        quantityInput.attr('name', `items[${index}][quantity]`);
    });
}

// Form validation before submit
$('#transferForm').on('submit', function(e) {
    if (selectedProducts.size === 0) {
        e.preventDefault();
        alert('Please add at least one product to transfer');
        return;
    }

    // Validate all quantities
    let isValid = true;
    let errorMessage = '';
    
    selectedProducts.forEach((product, productId) => {
        if (product.quantity <= 0) {
            isValid = false;
            errorMessage = `Quantity for "${product.name}" must be greater than 0`;
        } else if (product.quantity > product.stock) {
            isValid = false;
            errorMessage = `Cannot transfer ${product.quantity} of "${product.name}" - only ${product.stock} available in warehouse`;
        }
    });

    if (!isValid) {
        e.preventDefault();
        alert(errorMessage);
    }
});

// Update when destination shop changes to show shop stock for all products
$(document).on('change', '#destinationShop', async function() {
    const shopId = $(this).val();
    
    // Update all existing products in table
    selectedProducts.forEach(async (product, productId) => {
        if (shopId) {
            const shopStock = await getShopStock(productId, shopId);
            // Find and update the shop stock display
            const row = $(`#row-${productId}`);
            if (row.length) {
                let shopStockDisplay = row.find('.shop-stock-display');
                if (shopStockDisplay.length === 0) {
                    // Add shop stock display if it doesn't exist
                    row.find('.badge').after(`<br><small class="text-muted shop-stock-display" data-product-id="${productId}">Shop: ${shopStock}</small>`);
                } else {
                    shopStockDisplay.text(`Shop: ${shopStock}`);
                }
            }
        } else {
            // Remove shop stock display
            $(`.shop-stock-display[data-product-id="${productId}"]`).remove();
        }
    });
});

// Initialize table on page load
$(document).ready(function() {
    <?php if ($preSelectedProductId > 0): ?>
    updateSummary();
    <?php endif; ?>
});
</script>
</body>
</html>