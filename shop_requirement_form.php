<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'field_executive') {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
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
        header('Location: store_visits_list.php');
        exit();
    }

    $edit_mode = true;

    // Load existing requirements
    $items_stmt = $pdo->prepare("
        SELECT sr.*, p.id AS product_id, p.product_name, p.product_code, p.wholesale_price,
               COALESCE(ps.quantity, 0) AS warehouse_stock
        FROM store_requirements sr 
        JOIN products p ON sr.product_id = p.id 
        LEFT JOIN product_stocks ps ON ps.product_id = p.id 
          AND ps.shop_id = (SELECT id FROM shops WHERE is_warehouse = 1 LIMIT 1)
        WHERE sr.store_visit_id = ?
    ");
    $items_stmt->execute([$visit_id]);
    $existing_items = $items_stmt->fetchAll();
}

// Fetch stores (assigned to this executive or unassigned)
$stores = $pdo->prepare("
    SELECT id, store_code, store_name, owner_name, phone, whatsapp_number, address, gstin 
    FROM stores 
    WHERE is_active = 1 
      AND (field_executive_id = ? OR field_executive_id IS NULL)
    ORDER BY store_name
");
$stores->execute([$user_id]);
$stores = $stores->fetchAll();

// Fetch products with warehouse stock
$products = $pdo->query("
    SELECT p.id, p.product_name, p.product_code, p.retail_price, p.wholesale_price,
           COALESCE(ps.quantity, 0) AS warehouse_stock
    FROM products p
    LEFT JOIN product_stocks ps ON ps.product_id = p.id 
      AND ps.shop_id = (SELECT id FROM shops WHERE is_warehouse = 1 LIMIT 1)
    WHERE p.is_active = 1
    ORDER BY p.product_name
")->fetchAll();

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
                        collected_requirements = ?, updated_at = NOW()
                    WHERE id = ? AND field_executive_id = ?
                ");
                $update_visit->execute([
                    $store_id, $visit_date, $contact_person, $phone, 
                    $visit_notes, $visit_type, $next_followup_date ?: null, 
                    $collected_requirements, $visit_id, $user_id
                ]);

                // Delete old requirements only if not converted to invoice
                $pdo->prepare("
                    DELETE FROM store_requirements 
                    WHERE store_visit_id = ? AND invoice_id IS NULL
                ")->execute([$visit_id]);
            } else {
                // Insert new visit
                $stmt = $pdo->prepare("
                    INSERT INTO store_visits 
                    (store_id, field_executive_id, visit_date, contact_person, phone, 
                     visit_notes, visit_type, next_followup_date, collected_requirements, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $store_id, $user_id, $visit_date, $contact_person, $phone, 
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
                    $pdo->prepare("
                        INSERT INTO store_requirements 
                        (store_visit_id, product_id, field_executive_id, required_quantity, 
                         urgency, notes, status, requirement_status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())
                    ")->execute([$visit_id, $product_id, $user_id, $qty, $urgency, $notes]);
                    $valid_items++;
                }
            }

            if ($valid_items > 0) {
                // Update store's field executive if not already assigned
                $pdo->prepare("
                    UPDATE stores 
                    SET field_executive_id = ? 
                    WHERE id = ? AND (field_executive_id IS NULL OR field_executive_id = ?)
                ")->execute([$user_id, $store_id, $user_id]);
            }

            $pdo->commit();
            $success = $edit_mode ? "Visit updated successfully!" : "Visit and requirements submitted successfully!";
            
            if (!$edit_mode) {
                header("Location: store_visits_list.php");
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
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

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
                                <a href="store_requirements_dashboard.php" class="btn btn-info btn-sm me-2">
                                    <i class="bx bx-pie-chart-alt"></i> Dashboard
                                </a>
                                <a href="store_visits_list.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back"></i> Back to List
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
                            <div class="alert alert-info mb-3">
                                <i class="bx bx-info-circle fs-4 me-2"></i>
                                Collect store requirements. These will be reviewed by sales team and converted to invoices.
                            </div>
                            
                            <div id="itemsContainer">
                                <?php 
                                $items_to_load = $edit_mode && !empty($existing_items) ? $existing_items : [['product_id'=>0, 'required_quantity'=>10, 'urgency'=>'medium', 'notes'=>'']];
                                foreach ($items_to_load as $index => $item): 
                                ?>
                                <div class="item-row border rounded p-4 mb-3 bg-light position-relative">
                                    <div class="row g-3 align-items-end">
                                        <div class="col-md-5">
                                            <label class="form-label">Product <span class="text-danger">*</span></label>
                                            <select class="form-select product-select" name="items[<?= $index ?>][product_id]" required 
                                                    data-stock="<?= $item['warehouse_stock'] ?? 0 ?>">
                                                <option value="">Select Product</option>
                                                <?php foreach ($products as $p): ?>
                                                <option value="<?= $p['id'] ?>" 
                                                        data-price="<?= $p['wholesale_price'] ?>"
                                                        data-stock="<?= $p['warehouse_stock'] ?>"
                                                        data-retail="<?= $p['retail_price'] ?>"
                                                        <?= $item['product_id'] == $p['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($p['product_name']) ?> 
                                                    (Code: <?= $p['product_code'] ?> | 
                                                    Stock: <span class="stock-badge <?= $p['warehouse_stock'] < 10 ? 'bg-danger' : 'bg-success' ?>">
                                                        <?= $p['warehouse_stock'] ?>
                                                    </span> | 
                                                    Wholesale: ₹<?= number_format($p['wholesale_price'], 2) ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted stock-info" style="display: none;"></small>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                            <input type="number" name="items[<?= $index ?>][required_qty]" 
                                                   class="form-control quantity-input" min="1" max="999" required
                                                   value="<?= $item['required_quantity'] ?? 10 ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Urgency</label>
                                            <select name="items[<?= $index ?>][urgency]" class="form-select">
                                                <option value="low" <?= ($item['urgency'] ?? 'medium') == 'low' ? 'selected' : '' ?>>Low</option>
                                                <option value="medium" <?= ($item['urgency'] ?? 'medium') == 'medium' ? 'selected' : '' ?>>Medium</option>
                                                <option value="high" <?= ($item['urgency'] ?? 'medium') == 'high' ? 'selected' : '' ?>>High</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Special Notes</label>
                                            <input type="text" name="items[<?= $index ?>][notes]" class="form-control"
                                                   value="<?= $item['notes'] ?? '' ?>" placeholder="Color, size, delivery preference">
                                        </div>
                                    </div>
                                    
                                    <?php if ($index > 0 || $edit_mode): ?>
                                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-2 remove-item">
                                        <i class="bx bx-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <div class="mt-2 row g-2">
                                        <div class="col-auto">
                                            <small class="text-muted">
                                                Stock Available: <span class="available-stock fw-bold">0</span>
                                            </small>
                                        </div>
                                        <div class="col-auto">
                                            <small class="text-muted">
                                                | Est. Value: ₹<span class="estimated-value">0.00</span>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <button type="button" id="addItem" class="btn btn-outline-success">
                                    <i class="bx bx-plus"></i> Add Another Product
                                </button>
                                
                                <div class="text-end">
                                    <div class="mb-1">
                                        <small class="text-muted">Total Items: <span id="itemCount">1</span></small>
                                    </div>
                                    <div class="h5">
                                        Total Estimated Value: ₹<span id="totalEstimatedValue">0.00</span>
                                    </div>
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
                                            <li>Packing - Warehouse staff packs the order</li>
                                            <li>Shipping - Order dispatched to store</li>
                                            <li>Delivery - Order delivered to store</li>
                                        </ol>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-light border">
                                        <h6 class="alert-heading">Instructions:</h6>
                                        <ul class="mb-0 ps-3">
                                            <li>Verify stock availability before adding items</li>
                                            <li>Mark urgency based on store's timeline</li>
                                            <li>Note any special requirements (color, size, etc.)</li>
                                            <li>Set follow-up date for next visit</li>
                                            <li>Requirements will be visible to sales team immediately</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-end mt-4">
                        <a href="store_visits_list.php" class="btn btn-secondary me-2 px-4">
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

<script>
let itemIndex = <?= count($items_to_load) ?>;
let productsData = <?= json_encode(array_column($products, null, 'id')) ?>;

function updateTotals() {
    const items = document.querySelectorAll('.item-row');
    let totalItems = 0;
    let totalValue = 0;
    
    items.forEach(item => {
        const productSelect = item.querySelector('.product-select');
        const quantityInput = item.querySelector('.quantity-input');
        const productId = productSelect.value;
        const quantity = parseInt(quantityInput.value) || 0;
        
        if (productId && quantity > 0) {
            totalItems++;
            const product = productsData[productId];
            if (product) {
                totalValue += product.wholesale_price * quantity;
            }
        }
    });
    
    document.getElementById('itemCount').textContent = totalItems;
    document.getElementById('totalItemsCount').textContent = totalItems + ' item' + (totalItems !== 1 ? 's' : '');
    document.getElementById('totalEstimatedValue').textContent = totalValue.toFixed(2);
}

function updateItemInfo(itemRow) {
    const productSelect = itemRow.querySelector('.product-select');
    const quantityInput = itemRow.querySelector('.quantity-input');
    const stockSpan = itemRow.querySelector('.available-stock');
    const valueSpan = itemRow.querySelector('.estimated-value');
    const productId = productSelect.value;
    const quantity = parseInt(quantityInput.value) || 0;
    
    if (productId && productsData[productId]) {
        const product = productsData[productId];
        const availableStock = parseInt(productSelect.options[productSelect.selectedIndex].dataset.stock) || 0;
        const wholesalePrice = parseFloat(productSelect.options[productSelect.selectedIndex].dataset.price) || 0;
        const estimatedValue = wholesalePrice * quantity;
        
        stockSpan.textContent = availableStock;
        stockSpan.className = availableStock < 10 ? 'text-danger fw-bold' : 'text-success fw-bold';
        valueSpan.textContent = estimatedValue.toFixed(2);
        
        // Show stock warning
        const stockInfo = itemRow.querySelector('.stock-info');
        if (quantity > availableStock) {
            stockInfo.textContent = `⚠️ Insufficient stock (Need: ${quantity}, Have: ${availableStock})`;
            stockInfo.className = 'text-danger';
            stockInfo.style.display = 'block';
        } else if (availableStock < 20) {
            stockInfo.textContent = `ℹ️ Low stock (${availableStock} units available)`;
            stockInfo.className = 'text-warning';
            stockInfo.style.display = 'block';
        } else {
            stockInfo.style.display = 'none';
        }
    } else {
        stockSpan.textContent = '0';
        valueSpan.textContent = '0.00';
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Auto-fill store info
    const storeSelect = document.getElementById('storeSelect');
    const contactPerson = document.getElementById('contactPerson');
    const storePhone = document.getElementById('storePhone');
    const hiddenPhone = document.getElementById('hiddenPhone');
    const storeInfo = document.getElementById('storeInfo');
    
    function updateStoreInfo() {
        const opt = storeSelect.options[storeSelect.selectedIndex];
        if (opt && opt.value) {
            contactPerson.value = opt.dataset.owner || '';
            const phone = opt.dataset.whatsapp || opt.dataset.phone || '';
            storePhone.value = phone;
            hiddenPhone.value = phone;
            
            let info = [];
            if (opt.dataset.owner) info.push(`Owner: ${opt.dataset.owner}`);
            if (opt.dataset.gstin) info.push(`GSTIN: ${opt.dataset.gstin}`);
            if (opt.dataset.address) info.push(`Address: ${opt.dataset.address}`);
            storeInfo.textContent = info.join(' | ');
        }
    }
    
    storeSelect.addEventListener('change', updateStoreInfo);
    <?php if ($edit_mode): ?>updateStoreInfo();<?php endif; ?>
    
    // Add new item
    document.getElementById('addItem').addEventListener('click', () => {
        const container = document.getElementById('itemsContainer');
        const firstItem = container.querySelector('.item-row');
        const clone = firstItem.cloneNode(true);
        
        // Clear values
        clone.querySelectorAll('select').forEach(el => {
            if (el.name) el.name = el.name.replace(/\[\d+\]/, '[' + itemIndex + ']');
            el.selectedIndex = 0;
        });
        
        clone.querySelectorAll('input').forEach(el => {
            if (el.name) {
                el.name = el.name.replace(/\[\d+\]/, '[' + itemIndex + ']');
                if (el.type === 'number') el.value = '10';
                else el.value = '';
            }
        });
        
        // Add remove button
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-danger btn-sm position-absolute top-0 end-0 m-2 remove-item';
        removeBtn.innerHTML = '<i class="bx bx-trash"></i>';
        removeBtn.onclick = () => {
            clone.remove();
            updateTotals();
        };
        clone.querySelector('.remove-item')?.remove();
        clone.appendChild(removeBtn);
        
        // Add event listeners
        const productSelect = clone.querySelector('.product-select');
        const quantityInput = clone.querySelector('.quantity-input');
        
        productSelect.addEventListener('change', () => updateItemInfo(clone));
        quantityInput.addEventListener('input', () => {
            updateItemInfo(clone);
            updateTotals();
        });
        
        container.appendChild(clone);
        itemIndex++;
        
        // Update info for new item
        updateItemInfo(clone);
        updateTotals();
    });
    
    // Remove item
    document.addEventListener('click', e => {
        if (e.target.closest('.remove-item')) {
            e.target.closest('.item-row').remove();
            updateTotals();
        }
    });
    
    // Initialize event listeners for existing items
    document.querySelectorAll('.item-row').forEach(item => {
        const productSelect = item.querySelector('.product-select');
        const quantityInput = item.querySelector('.quantity-input');
        
        productSelect.addEventListener('change', () => {
            updateItemInfo(item);
            updateTotals();
        });
        
        quantityInput.addEventListener('input', () => {
            updateItemInfo(item);
            updateTotals();
        });
        
        // Add remove button if not present
        if (!item.querySelector('.remove-item') && item !== document.querySelector('.item-row:first-child')) {
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-danger btn-sm position-absolute top-0 end-0 m-2 remove-item';
            removeBtn.innerHTML = '<i class="bx bx-trash"></i>';
            removeBtn.onclick = () => {
                item.remove();
                updateTotals();
            };
            item.appendChild(removeBtn);
        }
        
        // Initialize item info
        updateItemInfo(item);
    });
    
    // Initial totals calculation
    updateTotals();
    
    // Form validation
    document.getElementById('visitForm').addEventListener('submit', function(e) {
        const items = document.querySelectorAll('.item-row');
        let valid = false;
        
        items.forEach(item => {
            const productId = item.querySelector('.product-select').value;
            const quantity = parseInt(item.querySelector('.quantity-input').value) || 0;
            if (productId && quantity > 0) {
                valid = true;
            }
        });
        
        if (!valid) {
            e.preventDefault();
            alert('Please add at least one product requirement with quantity.');
        }
    });
});
</script>

<style>
.item-row { 
    position: relative; 
    border-left: 4px solid #28a745 !important;
}
.item-row:nth-child(even) {
    background-color: #f8f9fa !important;
}
.card { 
    border-radius: 12px; 
    overflow: hidden; 
    border: none;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
}
.card-header {
    border-radius: 12px 12px 0 0 !important;
}
.form-control, .form-select { 
    border-radius: 8px; 
    border: 1px solid #dee2e6;
}
.btn { 
    border-radius: 8px; 
    font-weight: 500;
}
.stock-badge {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: bold;
}
.remove-item {
    width: 32px;
    height: 32px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.estimated-value {
    color: #198754;
    font-weight: bold;
}
#totalEstimatedValue {
    color: #198754;
    font-weight: bold;
}
</style>
</body>
</html>