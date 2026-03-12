<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager'])) {
    header('Location: dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success = $error = '';

// Generate Request Number
$month_year = date('Ym');
$stmt = $pdo->query("SELECT COUNT(*) + 1 FROM purchase_requests WHERE DATE_FORMAT(created_at, '%Y%m') = '$month_year'");
$next_num = str_pad($stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
$request_number = "PR-$month_year-$next_num";

// Fetch data
$manufacturers = $pdo->query("SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name")->fetchAll();
$products = $pdo->query("
    SELECT p.id, p.product_name, p.product_code, p.stock_price,
           COALESCE(SUM(ps.quantity), 0) as current_stock
    FROM products p
    LEFT JOIN product_stocks ps ON ps.product_id = p.id
    GROUP BY p.id
    ORDER BY p.product_name
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manufacturer_id = $_POST['manufacturer_id'] ?? 0;
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];

    if (!$manufacturer_id || empty($items)) {
        $error = "Please select manufacturer and add at least one product.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO purchase_requests 
                (request_number, manufacturer_id, requested_by, status, request_notes, created_at) 
                VALUES (?, ?, ?, 'draft', ?, NOW())");
            $stmt->execute([$request_number, $manufacturer_id, $user_id, $notes]);
            $request_id = $pdo->lastInsertId();

            $total_est = 0;
            foreach ($items as $item) {
                $pid = $item['product_id'];
                $qty = (int)($item['quantity'] ?? 0);
                $price = (float)($item['est_price'] ?? 0);
                if ($qty > 0 && $price > 0) {
                    $stmt = $pdo->prepare("INSERT INTO purchase_request_items 
                        (purchase_request_id, product_id, quantity, estimated_price) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$request_id, $pid, $qty, $price]);
                    $total_est += $qty * $price;
                }
            }

            $pdo->prepare("UPDATE purchase_requests SET total_estimated_amount = ? WHERE id = ?")
                ->execute([$total_est, $request_id]);

            $pdo->commit();
            $success = "Purchase Request <strong>$request_number</strong> created successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to save request. Try again.";
        }
    }
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "New Purchase Request"; ?>
<?php include('includes/head.php'); ?>

<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-plus-circle me-2"></i> New Purchase Request
                            </h4>
                            <a href="request_list.php" class="btn btn-outline-secondary">
                                <i class="bx bx-arrow-back"></i> Back
                            </a>
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
                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" id="requestForm">
                    <div class="card">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Request No.</strong></label>
                                    <input type="text" class="form-control form-control-lg" value="<?= $request_number ?>" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Manufacturer <span class="text-danger">*</span></strong></label>
                                    <select name="manufacturer_id" class="form-select" required>
                                        <option value="">-- Select --</option>
                                        <?php foreach($manufacturers as $m): ?>
                                        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Requested By</strong></label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['full_name']) ?>" readonly>
                                </div>
                            </div>

                            <hr class="my-4">

                            <h5 class="mb-3"><i class="bx bx-list-plus me-2"></i> Products</h5>
                            <div id="itemsContainer">
                                <div class="item-row border rounded p-3 mb-3 bg-light">
                                    <div class="row g-3">
                                        <div class="col-md-5">
                                            <select class="form-select product-select" name="items[0][product_id]" required>
                                                <option value="">-- Select Product --</option>
                                                <?php foreach($products as $p): ?>
                                                <option value="<?= $p['id'] ?>" 
                                                        data-price="<?= $p['stock_price'] ?>">
                                                    <?= htmlspecialchars($p['product_name']) ?> 
                                                    (Code: <?= $p['product_code'] ?> | Stock: <?= $p['current_stock'] ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" name="items[0][quantity]" class="form-control qty-input" min="1" value="1" required placeholder="Qty">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" step="0.01" name="items[0][est_price]" class="form-control price-input" required placeholder="Price">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control total-input" readonly placeholder="Total">
                                        </div>
                                        <div class="col-md-1 text-end">
                                            <button type="button" class="btn btn-danger btn-sm remove-item">
                                                <i class="bx bx-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <button type="button" id="addItem" class="btn btn-outline-primary btn-sm">
                                <i class="bx bx-plus"></i> Add Item
                            </button>

                            <hr class="my-4">

                            <div class="row">
                                <div class="col-md-8">
                                    <label class="form-label">Notes (Optional)</label>
                                    <textarea name="notes" class="form-control" rows="3" placeholder="Any special instructions..."></textarea>
                                </div>
                                <div class="col-md-4 text-end">
                                    <h4>Total Estimated: <span id="grandTotal" class="text-primary">₹0.00</span></h4>
                                </div>
                            </div>

                            <div class="text-end mt-4">
                                <button type="submit" class="btn btn-success btn-lg px-5">
                                    <i class="bx bx-save me-2"></i> Save Draft
                                </button>
                                <button type="submit" name="send" class="btn btn-primary btn-lg px-5 ms-2">
                                    <i class="bx bx-send me-2"></i> Save & Send
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
let itemIndex = 1;

document.getElementById('addItem').onclick = function() {
    const container = document.getElementById('itemsContainer');
    const row = container.children[0].cloneNode(true);
    row.querySelectorAll('input, select').forEach(el => {
        el.name = el.name.replace(/\[\d+\]/, '[' + itemIndex + ']');
        if (el.type !== 'button') el.value = '';
    });
    container.appendChild(row);
    itemIndex++;
};

document.getElementById('itemsContainer').addEventListener('click', e => {
    if (e.target.closest('.remove-item') && document.querySelectorAll('.item-row').length > 1) {
        e.target.closest('.item-row').remove();
        calculateTotal();
    }
});

document.getElementById('itemsContainer').addEventListener('input', calculateTotal);
document.getElementById('itemsContainer').addEventListener('change', e => {
    if (e.target.classList.contains('product-select')) {
        const price = e.target.selectedOptions[0]?.dataset.price || 0;
        e.target.closest('.item-row').querySelector('.price-input').value = price;
        calculateTotal();
    }
});

function calculateTotal() {
    let total = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        const itemTotal = qty * price;
        row.querySelector('.total-input').value = itemTotal > 0 ? '₹' + itemTotal.toFixed(2) : '';
        total += itemTotal;
    });
    document.getElementById('grandTotal').textContent = '₹' + total.toFixed(2);
}

calculateTotal();
</script>

<style>
.item-row { background: #f8f9fa; }
.form-control, .form-select { border-radius: 8px; }
</style>
</body>
</html>