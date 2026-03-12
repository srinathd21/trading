<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['admin', 'field_executive'])
) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$executive_name = $_SESSION['full_name'] ?? 'Field Executive';
$edit_mode = false;
$visit_id = (int)($_GET['edit'] ?? 0);
$visit = null;
$existing_items = [];

// === EDIT MODE: Load existing visit ===
if ($visit_id > 0) {
    $stmt = $pdo->prepare("
        SELECT sv.*, s.id AS store_id, s.store_code, s.store_name, s.owner_name, s.phone, s.whatsapp_number
        FROM store_visits sv
        JOIN stores s ON sv.store_id = s.id
        WHERE sv.id = ? AND sv.field_executive_id = ?
    ");
    $stmt->execute([$visit_id, $user_id]);
    $visit = $stmt->fetch();
    if (!$visit) {
        $_SESSION['error'] = "Visit not found or you don't have permission.";
        header('Location: store_requirements.php');
        exit();
    }
    $edit_mode = true;
    // Load existing requirements
    $items_stmt = $pdo->prepare("
        SELECT sr.*, p.id AS product_id, p.product_name, p.product_code, p.wholesale_price,
               COALESCE(ps.quantity, 0) AS warehouse_stock,
               ps.shop_id as warehouse_id
        FROM store_requirements sr
        JOIN products p ON sr.product_id = p.id
        LEFT JOIN product_stocks ps ON ps.product_id = p.id
          AND ps.shop_id = (SELECT id FROM shops WHERE is_warehouse = 1 AND business_id = ? LIMIT 1)
        WHERE sr.store_visit_id = ? AND sr.business_id = ?
    ");
    $items_stmt->execute([$business_id, $visit_id, $business_id]);
    $existing_items = $items_stmt->fetchAll();
}

// Fetch all active stores (no field executive filter)
$stores_stmt = $pdo->prepare("
    SELECT id, store_code, store_name, owner_name, phone, whatsapp_number, address, gstin
    FROM stores
    WHERE business_id = ? AND is_active = 1
    ORDER BY store_name
");
$stores_stmt->execute([$business_id]);
$stores = $stores_stmt->fetchAll();
if (empty($stores)) {
    error_log("No active stores found in database at " . date('Y-m-d H:i:s'));
    $_SESSION['error'] = "No active stores available. Please contact the administrator.";
}

// Get warehouse ID for this business
$warehouse_stmt = $pdo->prepare("
    SELECT id, shop_name FROM shops 
    WHERE business_id = ? AND is_warehouse = 1 
    LIMIT 1
");
$warehouse_stmt->execute([$business_id]);
$warehouse = $warehouse_stmt->fetch();
$warehouse_id = $warehouse['id'] ?? null;

// Fetch products with warehouse stock (once)
$products_query = "
    SELECT p.id, p.product_name, p.product_code, p.retail_price, p.wholesale_price,
           p.stock_price, p.min_stock_level,
           COALESCE(ps.quantity, 0) AS warehouse_stock
    FROM products p
    LEFT JOIN product_stocks ps ON ps.product_id = p.id
      AND ps.shop_id = ? AND ps.business_id = ?
    WHERE p.business_id = ? AND p.is_active = 1
    ORDER BY p.product_name
";
$products_stmt = $pdo->prepare($products_query);
$products_stmt->execute([$warehouse_id, $business_id, $business_id]);
$products = $products_stmt->fetchAll();

// Process submission (Add or Update)
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $store_id = (int)($_POST['store_id'] ?? 0);
    $visit_date = $_POST['visit_date'] ?? date('Y-m-d');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $visit_notes = trim($_POST['visit_notes'] ?? '');
    $visit_type = trim($_POST['visit_type'] ?? 'requirement_collection');
    $next_followup_date = trim($_POST['next_followup_date'] ?? '');
    $items = $_POST['items'] ?? [];
    
    if ($store_id <= 0) {
        $error = "Please select a store.";
    } else if (empty($items)) {
        $error = "Please add at least one product requirement.";
    } else {
        try {
            $pdo->beginTransaction();
            $collected_requirements = !empty($items) ? 1 : 0;
          
            if ($edit_mode) {
                // Update visit
                $update_visit = $pdo->prepare("
                    UPDATE store_visits
                    SET store_id = ?, visit_date = ?, contact_person = ?, phone = ?,
                        visit_notes = ?, visit_type = ?, next_followup_date = ?,
                        collected_requirements = ?
                    WHERE id = ? AND field_executive_id = ? AND business_id = ?
                ");
                $update_visit->execute([
                    $store_id, $visit_date, $contact_person, $phone,
                    $visit_notes, $visit_type, $next_followup_date ?: null,
                    $collected_requirements, $visit_id, $user_id, $business_id
                ]);
                // Delete old requirements only if not converted to invoice
                $pdo->prepare("
                    DELETE FROM store_requirements
                    WHERE store_visit_id = ? AND invoice_id IS NULL AND business_id = ?
                ")->execute([$visit_id, $business_id]);
            } else {
                // Insert new visit
                $stmt = $pdo->prepare("
                    INSERT INTO store_visits
                    (store_id, field_executive_id, business_id, visit_date, contact_person, phone,
                     visit_notes, visit_type, next_followup_date, collected_requirements, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $store_id, $user_id, $business_id, $visit_date, $contact_person, $phone,
                    $visit_notes, $visit_type, $next_followup_date ?: null, $collected_requirements
                ]);
                $visit_id = $pdo->lastInsertId();
            }
            
            // Insert new requirements
            $valid_items = 0;
            foreach ($items as $item) {
                $product_id = (int)($item['product_id'] ?? 0);
                $qty = (int)($item['required_qty'] ?? 0);
                $urgency = $item['urgency'] ?? 'medium';
                $notes = trim($item['notes'] ?? '');
                
                if ($product_id > 0 && $qty > 0) {
                    // Check warehouse stock before adding requirement
                    $stock_stmt = $pdo->prepare("
                        SELECT COALESCE(ps.quantity, 0) as warehouse_stock
                        FROM products p
                        LEFT JOIN product_stocks ps ON ps.product_id = p.id 
                            AND ps.shop_id = ? AND ps.business_id = ?
                        WHERE p.id = ? AND p.business_id = ?
                    ");
                    $stock_stmt->execute([$warehouse_id, $business_id, $product_id, $business_id]);
                    $stock = $stock_stmt->fetch();
                    
                    $warehouse_stock = $stock['warehouse_stock'] ?? 0;
                    $stock_status = 'pending';
                    
                    if ($warehouse_stock >= $qty) {
                        $stock_status = 'available';
                    } elseif ($warehouse_stock > 0) {
                        $stock_status = 'partial';
                    } else {
                        $stock_status = 'out_of_stock';
                    }
                    
                    $pdo->prepare("
                        INSERT INTO store_requirements
                        (store_visit_id, product_id, field_executive_id, business_id, required_quantity,
                         urgency, notes, status, requirement_status,  created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'pending',  NOW())
                    ")->execute([
                        $visit_id, $product_id, $user_id, $business_id, $qty, 
                        $urgency, $notes, 
                    ]);
                    $valid_items++;
                }
            }
            
            if ($valid_items > 0) {
                // Update store's field executive if not already assigned
                $pdo->prepare("
                    UPDATE stores
                    SET field_executive_id = ?
                    WHERE id = ? AND business_id = ? 
                    AND (field_executive_id IS NULL OR field_executive_id = ?)
                ")->execute([$user_id, $store_id, $business_id, $user_id]);
            }
            
            $pdo->commit();
            $success = $edit_mode ? "Visit updated successfully!" : "Visit and requirements submitted successfully!";
          
            if (!$edit_mode) {
                header("Location: store_requirements.php");
                exit();
            } else {
                header("Location: store_requirements.php?visit_id=" . $visit_id);
                exit();
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<!doctype html>
<html lang="en">
<?php
$page_title = $edit_mode ? "Edit Store Visit" : "New Store Visit & Requirements";
include 'includes/head.php';
?>
<body data-sidebar="dark">
<!-- Include Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm">
                    <i class="bx bx-check-circle fs-4 me-2"></i>
                    <?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm">
                    <i class="bx bx-error fs-4 me-2"></i>
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Warehouse Info Alert -->
                <?php if ($warehouse_id): ?>
                <div class="alert alert-info alert-dismissible fade show mb-4 shadow-sm">
                    <div class="d-flex align-items-center">
                        <i class="bx bx-building-house fs-4 me-2"></i>
                        <div>
                            <strong>Warehouse:</strong> <?= htmlspecialchars($warehouse['shop_name'] ?? 'Main Warehouse') ?>
                            | <strong>Stock Source:</strong> All requirements will be fulfilled from warehouse inventory
                            | <strong>Stock Check:</strong> Real-time stock availability shown for each product
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">
                                    <?= $edit_mode ? 'Edit Store Visit' : 'New Store Visit & Requirements' ?>
                                </h4>
                                <p class="text-muted mb-0">
                                    Field Executive: <strong><?= htmlspecialchars($executive_name) ?></strong> |
                                    Status: <span class="badge bg-warning">Collecting Requirements</span>
                                </p>
                            </div>
                            <div>
                                
                                <a href="store_requirements.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back"></i> Back
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <form method="POST" id="visitForm">
                    <div class="card shadow-sm">
                        <div class="card-header <?= $edit_mode ? 'bg-warning' : 'bg-primary' ?> text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Store Visit Details</h5>
                                <?php if ($edit_mode && isset($visit['collected_requirements']) && $visit['collected_requirements']): ?>
                                <span class="badge bg-success">Requirements Collected</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Visit Date <span class="text-danger">*</span></label>
                                    <input type="date" name="visit_date" class="form-control" required
                                           value="<?= $visit['visit_date'] ?? date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Visit Type</label>
                                    <select name="visit_type" class="form-select">
                                        <option value="regular" <?= ($visit['visit_type'] ?? 'requirement_collection') == 'regular' ? 'selected' : '' ?>>Regular Visit</option>
                                        <option value="requirement_collection" <?= ($visit['visit_type'] ?? 'requirement_collection') == 'requirement_collection' ? 'selected' : '' ?>>Requirement Collection</option>
                                        <option value="delivery" <?= ($visit['visit_type'] ?? '') == 'delivery' ? 'selected' : '' ?>>Delivery</option>
                                        <option value="followup" <?= ($visit['visit_type'] ?? '') == 'followup' ? 'selected' : '' ?>>Follow-up</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Store <span class="text-danger">*</span></label>
                                    <select name="store_id" id="storeSelect" class="form-select" required>
                                        <option value="">Choose Store</option>
                                        <?php foreach ($stores as $store): ?>
                                        <option value="<?= $store['id'] ?>"
                                            data-phone="<?= htmlspecialchars($store['phone']) ?>"
                                            data-whatsapp="<?= htmlspecialchars($store['whatsapp_number']) ?>"
                                            data-owner="<?= htmlspecialchars($store['owner_name']) ?>"
                                            data-gstin="<?= htmlspecialchars($store['gstin']) ?>"
                                            data-address="<?= htmlspecialchars($store['address']) ?>"
                                            <?= ($visit['store_id'] ?? 0) == $store['id'] ? 'selected' : '' ?>>
                                            [<?= $store['store_code'] ?>] <?= htmlspecialchars($store['store_name']) ?>
                                            <?= $store['gstin'] ? " (GST: {$store['gstin']})" : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Contact Person</label>
                                    <input type="text" name="contact_person" id="contactPerson" class="form-control"
                                           value="<?= $visit['contact_person'] ?? '' ?>" placeholder="Store owner/manager">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Phone / WhatsApp</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bx bx-phone"></i></span>
                                        <input type="text" id="storePhone" class="form-control"
                                               value="<?= $visit['phone'] ?? $visit['whatsapp_number'] ?? '' ?>" readonly>
                                        <input type="hidden" name="phone" id="hiddenPhone"
                                               value="<?= $visit['phone'] ?? $visit['whatsapp_number'] ?? '' ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Next Follow-up Date</label>
                                    <input type="date" name="next_followup_date" class="form-control"
                                           value="<?= $visit['next_followup_date'] ?? '' ?>" min="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Visit Notes / Observations</label>
                                    <textarea name="visit_notes" class="form-control" rows="3"
                                              placeholder="Store condition, customer feedback, payment issues, etc."><?= $visit['visit_notes'] ?? '' ?></textarea>
                                </div>
                                <div class="col-12">
                                    <div class="card bg-light border">
                                        <div class="card-body py-2">
                                            <small class="text-muted">
                                                <i class="bx bx-info-circle me-1"></i>
                                                Store Info:
                                                <span id="storeInfo">Select a store to view details</span>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card shadow-sm mt-4">
                        <div class="card-header bg-success text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Stock Requirements Collection</h5>
                                <span class="badge bg-light text-dark" id="totalItemsCount">0 items</span>
                            </div>
                        </div>
                        <div class="card-body">
    <div class="alert alert-primary border-0 mb-4">
        <i class="bx bx-info-circle fs-4 me-2"></i>
        <strong>Add products one by one.</strong> Real-time warehouse stock is displayed. Requirements will be reviewed and converted into orders.
    </div>

    <!-- Add Product Section -->
    <div class="add-product-section mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-lg-5 col-md-6">
                <label class="form-label fw-bold">Product <span class="text-danger">*</span></label>
                <select id="productSelect" class="form-select product-select"></select>
            </div>
            <div class="col-lg-2 col-md-3 col-6">
                <label class="form-label fw-bold">Qty <span class="text-danger">*</span></label>
                <input type="number" id="quantityInput" class="form-control text-center fw-bold" min="1" value="1">
            </div>
            <div class="col-lg-2 col-md-3 col-6">
                <label class="form-label fw-bold">Urgency</label>
                <select id="urgencySelect" class="form-select">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label fw-bold">Notes</label>
                <input type="text" id="notesInput" class="form-control" placeholder="e.g. 6500K, 9W">
            </div>
            <div class="col-lg-1 col-md-3 col-12">
                <button type="button" id="addItemBtn" class="btn btn-success w-100 h-100 d-flex align-items-center justify-content-center">
                    <i class="bx bx-plus fs-4"></i>
                </button>
            </div>
        </div>

        <div class="stock-info-row mt-3 px-2">
            <small class="text-muted me-4">
                Warehouse Stock: <strong id="availableStock" class="text-primary">0</strong> units
            </small>
            <small class="text-muted me-4">
                Est. Value: <strong class="text-success" id="estimatedValue">₹0.00</strong>
            </small>
            <small class="text-muted me-4">
                Min Level: <strong id="minStock">0</strong>
            </small>
            <div id="stockWarning" class="stock-warning d-none"></div>
        </div>
    </div>

    <!-- Items Table -->
    <div id="itemsContainer">
        <table class="table table-bordered table-hover mb-0" id="itemsTable">
            <thead>
                <tr>
                    <th width="30%">Product</th>
                    <th width="10%">Qty</th>
                    <th width="12%">Urgency</th>
                    <th width="15%">Warehouse Stock</th>
                    <th width="18%">Notes</th>
                    <th width="10%">Value</th>
                    <th width="5%" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="itemsTableBody">
                <!-- Filled by JS -->
            </tbody>
        </table>
    </div>

    <!-- Total Summary (Sticky Bottom) -->
    <div class="total-summary-bar mt-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex flex-wrap gap-4">
            <div>
                <small class="text-muted">Total Items</small><br>
                <strong class="fs-5 text-primary" id="itemCount">0</strong>
            </div>
            <div>
                <small class="text-muted">Total Qty</small><br>
                <strong class="fs-5 text-info" id="totalQuantity">0</strong>
            </div>
            <div>
                <small class="text-muted">Total Items Added</small><br>
                <strong class="fs-5" id="totalItemsCount">0 items</strong>
            </div>
        </div>
        <div class="text-end">
            <small class="text-muted d-block">Total Estimated Value</small>
            <h4 class="text-success mb-0" id="totalEstimatedValue">₹0.00</h4>
        </div>
    </div>
</div>
                    </div>
                    
                    <div class="card shadow-sm mt-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0">Requirement Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="alert alert-warning">
                                        <h6 class="alert-heading">Workflow Status:</h6>
                                        <ol class="mb-0 ps-3">
                                            <li><strong class="text-primary">✓ Collection</strong> - You are collecting requirements</li>
                                            <li>Review - Sales team will review requirements</li>
                                            <li>Approval - Manager approves the order</li>
                                            <li>Stock Adjustment - Warehouse stock will be adjusted</li>
                                            <li>Packing - Warehouse staff packs the order</li>
                                            <li>Shipping - Order dispatched to store</li>
                                            <li>Delivery - Order delivered to store</li>
                                        </ol>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-light border">
                                        <h6 class="alert-heading">Stock Adjustment Notes:</h6>
                                        <ul class="mb-0 ps-3">
                                            <li>Warehouse stock is checked in real-time</li>
                                            <li>When requirements are approved, stock will be deducted from warehouse</li>
                                            <li>If stock is insufficient, requirement will be marked as "partial" or "out of stock"</li>
                                            <li>You will receive notification when stock is adjusted</li>
                                            <li>Track stock status in requirements dashboard</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end mt-4">
                        <a href="store_requirements.php" class="btn btn-secondary me-2 px-4">
                            <i class="bx bx-x"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="bx bx-save"></i>
                            <?= $edit_mode ? 'Update Visit' : 'Submit Requirements' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>
<?php include 'includes/scripts.php'; ?>
<!-- Include Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let itemIndex = 0;
let productsData = <?= json_encode(array_column($products, null, 'id')) ?>;
let items = <?= json_encode($edit_mode ? $existing_items : []) ?>;

// Initialize Select2
function initializeSelect2(element) {
    $(element).select2({
        placeholder: 'Select Product',
        allowClear: true,
        width: '100%',
        data: Object.values(productsData).map(product => ({
            id: product.id,
            text: `${product.product_name} (Code: ${product.product_code} | Warehouse Stock: ${product.warehouse_stock} | Wholesale: ₹${parseFloat(product.wholesale_price).toFixed(2)})`,
            price: product.wholesale_price,
            stock: product.warehouse_stock,
            retail: product.retail_price,
            cost: product.stock_price,
            min_stock: product.min_stock_level || 10
        })),
        templateResult: formatProduct,
        templateSelection: formatProduct
    });
}

function formatProduct(product) {
    if (!product.id) return product.text;
    const stockClass = product.stock < (product.min_stock || 10) ? 'bg-danger' : (product.stock < 20 ? 'bg-warning' : 'bg-success');
    return $(`
        <div>
            ${product.text.split(' (')[0]}
            <small class="text-muted">
                (Code: ${product.text.split('Code: ')[1].split(' |')[0]} |
                Warehouse: <span class="stock-badge ${stockClass}">${product.stock}</span> |
                Wholesale: ₹${parseFloat(product.price).toFixed(2)})
            </small>
        </div>
    `);
}

function updateStockInfo() {
    const productSelect = $('#productSelect');
    const quantityInput = $('#quantityInput');
    const stockInfo = $('.stock-info');
    const stockWarning = $('#stockWarning');
    const availableStockSpan = $('#availableStock');
    const estimatedValueSpan = $('#estimatedValue');
    const costPriceSpan = $('#costPrice');
    const minStockSpan = $('#minStock');
    const productId = productSelect.val();
    const quantity = parseInt(quantityInput.val()) || 0;

    if (productId && productsData[productId]) {
        const product = productsData[productId];
        const availableStock = parseInt(product.warehouse_stock) || 0;
        const wholesalePrice = parseFloat(product.wholesale_price) || 0;
        const costPrice = parseFloat(product.stock_price) || 0;
        const minStock = parseInt(product.min_stock_level) || 10;
        const estimatedValue = wholesalePrice * quantity;
        const totalCost = costPrice * quantity;

        availableStockSpan.text(availableStock);
        availableStockSpan.removeClass().addClass(availableStock < minStock ? 'text-danger fw-bold' : (availableStock < minStock * 2 ? 'text-warning fw-bold' : 'text-success fw-bold'));
        
        estimatedValueSpan.text(estimatedValue.toFixed(2));
        costPriceSpan.text(totalCost.toFixed(2));
        minStockSpan.text(minStock);

        // Stock warning messages
        if (quantity > availableStock) {
            stockWarning.text(`⚠️ Insufficient warehouse stock! Need: ${quantity}, Available: ${availableStock}`);
            stockWarning.removeClass().addClass('text-danger fw-bold');
            stockWarning.show();
            $('#addItemBtn').prop('disabled', false).text('Add (Force)');
        } else if (availableStock < minStock) {
            stockWarning.text(`⚠️ Low warehouse stock! Current: ${availableStock}, Min. Required: ${minStock}`);
            stockWarning.removeClass().addClass('text-warning fw-bold');
            stockWarning.show();
            $('#addItemBtn').prop('disabled', false).text('Add');
        } else if (availableStock - quantity < minStock) {
            stockWarning.text(`ℹ️ Stock will go below minimum after this requirement`);
            stockWarning.removeClass().addClass('text-info fw-bold');
            stockWarning.show();
            $('#addItemBtn').prop('disabled', false).text('Add');
        } else {
            stockWarning.text('✓ Sufficient warehouse stock available');
            stockWarning.removeClass().addClass('text-success fw-bold');
            stockWarning.show();
            $('#addItemBtn').prop('disabled', false).text('Add');
        }
    } else {
        availableStockSpan.text('0');
        estimatedValueSpan.text('0.00');
        costPriceSpan.text('0.00');
        minStockSpan.text('0');
        stockWarning.hide();
        $('#addItemBtn').prop('disabled', true);
    }
}

function updateTotals() {
    let totalItems = 0;
    let totalQuantity = 0;
    let totalValue = 0;

    items.forEach(item => {
        if (item.product_id && item.required_qty > 0) {
            totalItems++;
            totalQuantity += item.required_qty;
            const product = productsData[item.product_id];
            if (product) {
                totalValue += product.wholesale_price * item.required_qty;
            }
        }
    });

    $('#itemCount').text(totalItems);
    $('#totalQuantity').text(totalQuantity);
    $('#totalItemsCount').text(totalItems + ' item' + (totalItems !== 1 ? 's' : ''));
    $('#totalEstimatedValue').text(totalValue.toFixed(2));
}

function renderItems() {
    const tbody = $('#itemsTableBody');
    tbody.empty();

    items.forEach((item, index) => {
        if (item.product_id && productsData[item.product_id]) {
            const product = productsData[item.product_id];
            const estimatedValue = (product.wholesale_price * item.required_qty).toFixed(2);
            const stockStatus = item.warehouse_stock >= item.required_qty ? 'success' : (item.warehouse_stock > 0 ? 'warning' : 'danger');
            const stockText = item.warehouse_stock >= item.required_qty ? 
                `✓ ${item.warehouse_stock}` : 
                (item.warehouse_stock > 0 ? `⚠ ${item.warehouse_stock} (partial)` : `✗ ${item.warehouse_stock}`);
            
            const row = `
                <tr>
                    <td>
                        ${product.product_name} <br>
                        <small class="text-muted">Code: ${product.product_code}</small>
                    </td>
                    <td>${item.required_qty}</td>
                    <td>
                        <span class="badge bg-${item.urgency === 'high' ? 'danger' : (item.urgency === 'medium' ? 'warning' : 'info')}">
                            ${item.urgency.charAt(0).toUpperCase() + item.urgency.slice(1)}
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-${stockStatus}">
                            ${stockText}
                        </span>
                    </td>
                    <td>${item.notes || '-'}</td>
                    <td>₹${estimatedValue}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-warning btn-sm edit-item" data-index="${index}">
                            <i class="bx bx-edit"></i>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm remove-item" data-index="${index}">
                            <i class="bx bx-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            tbody.append(row);

            // Add hidden inputs for form submission
            const hiddenInputs = `
                <input type="hidden" name="items[${index}][product_id]" value="${item.product_id}">
                <input type="hidden" name="items[${index}][required_qty]" value="${item.required_qty}">
                <input type="hidden" name="items[${index}][urgency]" value="${item.urgency}">
                <input type="hidden" name="items[${index}][notes]" value="${item.notes}">
            `;
            tbody.append(hiddenInputs);
        }
    });

    updateTotals();
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Auto-fill store info
    const storeSelect = $('#storeSelect');
    const contactPerson = $('#contactPerson');
    const storePhone = $('#storePhone');
    const hiddenPhone = $('#hiddenPhone');
    const storeInfo = $('#storeInfo');

    function updateStoreInfo() {
        const opt = storeSelect[0].options[storeSelect[0].selectedIndex];
        if (opt && opt.value) {
            contactPerson.val(opt.dataset.owner || '');
            const phone = opt.dataset.whatsapp || opt.dataset.phone || '';
            storePhone.val(phone);
            hiddenPhone.val(phone);

            let info = [];
            if (opt.dataset.owner) info.push(`Owner: ${opt.dataset.owner}`);
            if (opt.dataset.gstin) info.push(`GSTIN: ${opt.dataset.gstin}`);
            if (opt.dataset.address) info.push(`Address: ${opt.dataset.address}`);
            storeInfo.text(info.join(' | '));
        }
    }

    storeSelect.on('change', updateStoreInfo);
    <?php if ($edit_mode): ?>updateStoreInfo();<?php endif; ?>

    // Initialize Select2 for product select
    initializeSelect2('#productSelect');

    // Update stock info on product or quantity change
    $('#productSelect').on('change', updateStockInfo);
    $('#quantityInput').on('input', updateStockInfo);

    // Add item
    $('#addItemBtn').on('click', function() {
        const productId = $('#productSelect').val();
        const quantity = parseInt($('#quantityInput').val()) || 0;
        const urgency = $('#urgencySelect').val();
        const notes = $('#notesInput').val();

        if (!productId || quantity <= 0) {
            alert('Please select a product and enter a valid quantity.');
            return;
        }

        // Check if already exists
        const existingIndex = items.findIndex(item => item.product_id == productId);
        if (existingIndex >= 0 && !$(this).hasClass('edit-mode')) {
            if (confirm('This product is already added. Do you want to update the quantity instead?')) {
                // Edit existing item
                $('#addItemBtn').addClass('edit-mode').text('Update').data('edit-index', existingIndex);
                const item = items[existingIndex];
                $('#productSelect').val(item.product_id).trigger('change');
                $('#quantityInput').val(item.required_qty);
                $('#urgencySelect').val(item.urgency);
                $('#notesInput').val(item.notes || '');
                updateStockInfo();
            }
            return;
        }

        const item = {
            product_id: productId,
            required_qty: quantity,
            urgency: urgency,
            notes: notes,
            warehouse_stock: productsData[productId]?.warehouse_stock || 0
        };

        if ($(this).hasClass('edit-mode')) {
            const editIndex = parseInt($(this).data('edit-index'));
            items[editIndex] = item;
            $(this).removeClass('edit-mode').text('Add').removeData('edit-index');
        } else {
            items.push(item);
            itemIndex++;
        }

        // Reset form
        $('#productSelect').val('').trigger('change');
        $('#quantityInput').val('1');
        $('#urgencySelect').val('medium');
        $('#notesInput').val('');
        updateStockInfo();

        renderItems();
    });

    // Edit item
    $(document).on('click', '.edit-item', function() {
        const index = $(this).data('index');
        const item = items[index];

        $('#productSelect').val(item.product_id).trigger('change');
        $('#quantityInput').val(item.required_qty);
        $('#urgencySelect').val(item.urgency);
        $('#notesInput').val(item.notes || '');

        $('#addItemBtn').addClass('edit-mode').text('Update').data('edit-index', index);
        updateStockInfo();
    });

    // Remove item
    $(document).on('click', '.remove-item', function() {
        const index = $(this).data('index');
        if (confirm('Are you sure you want to remove this item?')) {
            items.splice(index, 1);
            renderItems();
        }
    });

    // Initial render for edit mode
    renderItems();

    // Form validation
    $('#visitForm').on('submit', function(e) {
        if (items.length === 0) {
            e.preventDefault();
            alert('Please add at least one product requirement.');
            return false;
        }
        
        // Check for insufficient stock items
        let insufficientStockItems = [];
        items.forEach((item, index) => {
            if (item.product_id && productsData[item.product_id]) {
                const product = productsData[item.product_id];
                if (item.required_qty > product.warehouse_stock) {
                    insufficientStockItems.push({
                        name: product.product_name,
                        required: item.required_qty,
                        available: product.warehouse_stock
                    });
                }
            }
        });
        
        if (insufficientStockItems.length > 0) {
            let warningMessage = "Some items have insufficient warehouse stock:\n\n";
            insufficientStockItems.forEach(item => {
                warningMessage += `• ${item.name}: Need ${item.required}, Available ${item.available}\n`;
            });
            warningMessage += "\nDo you still want to submit? These will require special handling.";
            
            if (!confirm(warningMessage)) {
                e.preventDefault();
                return false;
            }
        }
        
        return true;
    });
});
</script>
<style>
    :root {
        --primary: #5b73e8;
        --success: #198754;
        --warning: #ffc107;
        --danger: #dc3545;
        --info: #0dcaf0;
        --light: #f8f9fa;
        --dark: #343a40;
    }

    body {
        background-color: #f4f6f9;
    }

    .card {
        border: none;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
    }

    .card-header {
        border-bottom: none;
        padding: 1.25rem 1.5rem;
        font-weight: 600;
    }

    .form-control, .form-select {
        border-radius: 12px;
        padding: 0.65rem 1rem;
        font-size: 0.95rem;
        border: 1px solid #ced4da;
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 0.2rem rgba(91, 115, 232, 0.15);
    }

    .select2-container--default .select2-selection--single {
        border-radius: 12px !important;
        height: 48px !important;
        border: 1px solid #ced4da !important;
        padding: 0.5rem 0 !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 36px !important;
        padding-left: 1rem;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 46px !important;
        right: 8px !important;
    }

    .btn {
        border-radius: 12px;
        padding: 0.65rem 1.5rem;
        font-weight: 500;
        transition: all 0.2s ease;
    }

    .btn:hover {
        transform: translateY(-2px);
    }

    .btn-lg {
        padding: 0.85rem 2rem;
        font-size: 1.1rem;
    }

    .stock-badge {
        padding: 4px 10px;
        border-radius: 50px;
        font-size: 0.8rem;
        font-weight: bold;
        color: white;
    }

    .bg-low { background-color: #ffc107; }
    .bg-critical { background-color: #dc3545; }
    .bg-good { background-color: #198754; }

    #itemsTable {
        background: white;
        border-radius: 12px;
        overflow: hidden;
    }

    #itemsTable thead {
        background-color: #f1f3f5;
    }

    #itemsTable tbody tr {
        transition: background 0.2s ease;
    }

    #itemsTable tbody tr:hover {
        background-color: #f8fbff !important;
    }

    #itemsTable tbody tr:nth-child(even) {
        background-color: #fdfdfe;
    }

    .add-product-section {
        background: linear-gradient(135deg, #f8f9ff 0%, #f1f5ff 100%);
        border: 2px dashed #d0d7ff;
        border-radius: 16px;
        padding: 1.5rem;
    }

    .stock-info-row small {
        font-size: 0.85rem;
    }

    .stock-warning {
        font-weight: 600;
        font-size: 0.95rem;
        padding: 8px 12px;
        border-radius: 10px;
        display: inline-block;
        margin-top: 8px;
    }

    .total-summary-bar {
        background: white;
        border-top: 1px solid #dee2e6;
        padding: 1rem 1.5rem;
        position: sticky;
        bottom: 0;
        z-index: 10;
        box-shadow: 0 -4px 15px rgba(0,0,0,0.05);
        border-radius: 0 0 16px 16px;
    }

    @media (max-width: 768px) {
        .add-product-section .row > div {
            margin-bottom: 1rem;
        }
        .total-summary-bar {
            position: relative;
        }
    }
</style>
</body>
</html>