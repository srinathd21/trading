<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$shop_id = (int)($_GET['id'] ?? 0);
if (!$shop_id) {
    header('Location: shops.php');
    exit();
}

// Fetch shop details
$stmt = $pdo->prepare("SELECT * FROM shops WHERE id = ?");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch();

if (!$shop) {
    die("Shop not found");
}

// Fetch stock for this shop
$stock = $pdo->prepare("
    SELECT 
        p.id as product_id,
        p.product_name,
        p.product_code,
        p.retail_price,
        p.min_stock_level,
        COALESCE(sp.quantity, 0) as quantity,
        COALESCE(sp.min_stock_level, p.min_stock_level, 5) as shop_min_level
    FROM products p
    LEFT JOIN shops_products sp ON sp.product_id = p.id AND sp.shop_id = ?
    WHERE p.is_active = 1
    ORDER BY p.product_name
");
$stock->execute([$shop_id]);
$products = $stock->fetchAll();

// Stats
$total_items = count($products);
$in_stock = 0;
$low_stock = 0;
$out_of_stock = 0;

foreach ($products as $p) {
    $qty = $p['quantity'];
    $min = $p['shop_min_level'];
    if ($qty > 0) $in_stock++;
    if ($qty == 0) $out_of_stock++;
    elseif ($qty < $min) $low_stock++;
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Stock - {$shop['shop_name']}"; ?>
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
                            <div>
                                <h4 class="mb-1">
                                    Stock at <?= htmlspecialchars($shop['shop_name']) ?>
                                    <span class="badge bg-<?= $shop['is_active'] ? 'success' : 'secondary' ?> ms-2">
                                        <?= $shop['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                                    </span>
                                </h4>
                                <p class="text-muted mb-0">
                                    <?= htmlspecialchars($shop['address']) ?> • 
                                    <a href="tel:<?= $shop['phone'] ?>"><?= $shop['phone'] ?></a>
                                    <?php if ($shop['gstin']): ?> • GSTIN: <?= $shop['gstin'] ?><?php endif; ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="stock_transfers.php?to=<?= $shop_id ?>" class="btn btn-success">
                                    Transfer to this Shop
                                </a>
                                <a href="shop_stock_export.php?id=<?= $shop_id ?>" class="btn btn-outline-primary">
                                    Export Stock
                                </a>
                                <a href="shops.php" class="btn btn-outline-secondary">
                                    Back to Shops
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card mini-stat bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5>Total Products</h5>
                                        <h4><?= $total_items ?></h4>
                                    </div>
                                    <i class="bx bx-package fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card mini-stat bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5>In Stock</h5>
                                        <h4><?= $in_stock ?></h4>
                                    </div>
                                    <i class="bx bx-check-circle fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card mini-stat bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5>Low Stock</h5>
                                        <h4><?= $low_stock ?></h4>
                                    </div>
                                    <i class="bx bx-error fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card mini-stat bg-danger text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5>Out of Stock</h5>
                                        <h4><?= $out_of_stock ?></h4>
                                    </div>
                                    <i class="bx bx-x-circle fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="card-title mb-0">Current Stock in Shop</h5>
                            <div>
                                <input type="text" id="searchInput" class="form-control" placeholder="Search product..." style="width: 300px;">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="stockTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Code</th>
                                        <th class="text-center">Current Qty</th>
                                        <th class="text-center">Min Level</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-end">Retail Price</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($products as $i => $p): 
                                        $qty = $p['quantity'];
                                        $min = $p['shop_min_level'];
                                        $status = $qty == 0 ? 'danger' : ($qty < $min ? 'warning' : 'success');
                                        $status_text = $qty == 0 ? 'OUT OF STOCK' : ($qty < $min ? 'LOW STOCK' : 'IN STOCK');
                                    ?>
                                    <tr class="status-<?= $status ?>">
                                        <td><?= $i + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($p['product_name']) ?></strong></td>
                                        <td><code><?= htmlspecialchars($p['product_code']) ?></code></td>
                                        <td class="text-center fw-bold <?= $status === 'danger' ? 'text-danger' : '' ?>">
                                            <?= $qty ?>
                                        </td>
                                        <td class="text-center"><?= $min ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $status ?> fs-6"><?= $status_text ?></span>
                                        </td>
                                        <td class="text-end">₹<?= number_format($p['retail_price'], 2) ?></td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary" onclick="adjustStock(<?= $p['product_id'] ?>, <?= $shop_id ?>)">
                                                Adjust
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Quick Stock Adjustment Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="shop_stock_adjust.php">
            <input type="hidden" name="shop_id" id="adj_shop_id">
            <input type="hidden" name="product_id" id="adj_product_id">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Quick Stock Adjustment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p><strong id="adj_product_name"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select name="type" class="form-select" required>
                            <option value="add">Add Stock</option>
                            <option value="remove">Remove Stock</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" class="form-control" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <select name="reason" class="form-select" required>
                            <option value="correction">Physical Count Correction</option>
                            <option value="damage">Damaged</option>
                            <option value="lost">Lost/Missing</option>
                            <option value="found">Found</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save Adjustment</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
// Quick search
document.getElementById('searchInput').addEventListener('input', function() {
    const term = this.value.toLowerCase();
    document.querySelectorAll('#stockTable tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
});

// Open adjustment modal
function adjustStock(productId, shopId) {
    const row = event.target.closest('tr');
    const name = row.cells[1].textContent.trim();
    
    document.getElementById('adj_product_name').textContent = name;
    document.getElementById('adj_product_id').value = productId;
    document.getElementById('adj_shop_id').value = shopId;
    
    new bootstrap.Modal(document.getElementById('adjustModal')).show();
}
</script>

<style>
.mini-stat { border-radius: 12px; }
.table td, .table th { vertical-align: middle; }
.status-danger { background-color: #fee !important; }
.status-warning { background-color: #fff3cd !important; }
</style>
</body>
</html>