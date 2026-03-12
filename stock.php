<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Fetch all stock with product & location details
$stock_query = "
    SELECT 
        p.id as product_id,
        p.product_name,
        p.product_code,
        p.min_stock_level,
        l.location_name,
        l.location_type,
        COALESCE(ps.quantity, 0) as quantity
    FROM products p
    CROSS JOIN stock_locations l
    LEFT JOIN product_stocks ps ON ps.product_id = p.id AND ps.location_id = l.id
    WHERE p.is_active = 1 AND l.is_active = 1
    ORDER BY p.product_name, l.location_name
";
$stock_result = $pdo->query($stock_query);
$stock_data = $stock_result->fetchAll();

// Group by product
$products_stock = [];
foreach ($stock_data as $row) {
    $pid = $row['product_id'];
    if (!isset($products_stock[$pid])) {
        $products_stock[$pid] = [
            'name' => $row['product_name'],
            'code' => $row['product_code'],
            'min_level' => $row['min_stock_level'],
            'total' => 0,
            'locations' => []
        ];
    }
    $qty = (int)$row['quantity'];
    $products_stock[$pid]['total'] += $qty;
    $products_stock[$pid]['locations'][] = [
        'name' => $row['location_name'],
        'type' => $row['location_type'],
        'qty' => $qty
    ];
}

// Stats
$total_products = count($products_stock);
$low_stock = 0;
$out_of_stock = 0;
foreach ($products_stock as $p) {
    if ($p['total'] == 0) $out_of_stock++;
    elseif ($p['total'] < $p['min_level']) $low_stock++;
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Stock Management"; ?>
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
                                Stock Management
                            </h4>
                            <button class="btn btn-outline-secondary" onclick="exportStock()">
                                Export Stock
                            </button>
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
                                        <h5 class="text-white">Total Products</h5>
                                        <h4 class="mt-2"><?= $total_products ?></h4>
                                    </div>
                                    <i class="fas fa-boxes fs-1 opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card mini-stat bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="text-white">In Stock</h5>
                                        <h4 class="mt-2"><?= $total_products - $low_stock - $out_of_stock ?></h4>
                                    </div>
                                    <i class="fas fa-check-circle fs-1 opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card mini-stat bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="text-white">Low Stock</h5>
                                        <h4 class="mt-2"><?= $low_stock ?></h4>
                                    </div>
                                    <i class="fas fa-exclamation-triangle fs-1 opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card mini-stat bg-danger text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="text-white">Out of Stock</h5>
                                        <h4 class="mt-2"><?= $out_of_stock ?></h4>
                                    </div>
                                    <i class="fas fa-times-circle fs-1 opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-4">Current Stock by Product & Location</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th>Code</th>
                                        <th class="text-center">Total Stock</th>
                                        <th class="text-center">Min Level</th>
                                        <th>Warehouse / Shop Stock</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products_stock as $p): 
                                        $total = $p['total'];
                                        $status_class = $total == 0 ? 'danger' : ($total < $p['min_level'] ? 'warning' : 'success');
                                        $status_text = $total == 0 ? 'Out of Stock' : ($total < $p['min_level'] ? 'Low Stock' : 'In Stock');
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($p['name']) ?></strong>
                                        </td>
                                        <td><code><?= htmlspecialchars($p['code']) ?></code></td>
                                        <td class="text-center fw-bold"><?= $total ?></td>
                                        <td class="text-center"><?= $p['min_level'] ?></td>
                                        <td>
                                            <?php foreach ($p['locations'] as $loc): ?>
                                            <div class="d-flex justify-content-between mb-1">
                                                <small><?= htmlspecialchars($loc['name']) ?></small>
                                                <strong class="<?= $loc['qty'] == 0 ? 'text-danger' : '' ?>">
                                                    <?= $loc['qty'] ?>
                                                </strong>
                                            </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $status_class ?> fs-6">
                                                <?= $status_text ?>
                                            </span>
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

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function exportStock() {
    const btn = event.target;
    const original = btn.innerHTML;
    btn.innerHTML = 'Exporting...';
    btn.disabled = true;
    window.location = 'stock_export.php';
    setTimeout(() => {
        btn.innerHTML = original;
        btn.disabled = false;
    }, 2000);
}
</script>

<style>
.mini-stat .card-body { padding: 1.5rem; }
.fs-1 { font-size: 3rem !important; }
</style>
</body>
</html>