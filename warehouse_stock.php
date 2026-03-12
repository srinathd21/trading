<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'];
$allowed_roles = ['admin', 'warehouse_manager', 'stock_manager', 'shop_manager'];
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "You don't have permission to view warehouse stock.";
    header('Location: dashboard.php');
    exit();
}

// ==================== BUSINESS & SHOP CONTEXT ====================
$current_business_id = $_SESSION['current_business_id'] ?? null;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$current_business_name = $_SESSION['current_business_name'] ?? 'Business';

// Define role variables (similar to dashboard.php)
$is_admin = ($user_role === 'admin');
$is_shop_manager = in_array($user_role, ['admin', 'shop_manager']);
$is_seller = in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier']);
$is_stock_manager = in_array($user_role, ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager']);
$is_field_executive = ($user_role === 'field_executive');

// Non-admin must have a business and shop selected
if (!$is_admin && (!$current_business_id || !$current_shop_id)) {
    header('Location: select_shop.php');
    exit();
}

// ==================== GET WAREHOUSE ====================
if ($current_business_id) {
    // Get warehouses for the current business
    $warehouse_stmt = $pdo->prepare("
        SELECT id, shop_name 
        FROM shops 
        WHERE business_id = ? 
          AND is_warehouse = 1 
          AND is_active = 1
        ORDER BY shop_name 
        LIMIT 1
    ");
    $warehouse_params = [$current_business_id];
    
    $warehouse_stmt->execute($warehouse_params);
    $warehouse = $warehouse_stmt->fetch();
} else {
    // No business selected (admin case)
    if ($is_admin) {
        // Admin without business context - get any warehouse
        $warehouse_stmt = $pdo->prepare("
            SELECT id, shop_name 
            FROM shops 
            WHERE is_warehouse = 1 
              AND is_active = 1
            ORDER BY shop_name 
            LIMIT 1
        ");
        $warehouse_stmt->execute();
        $warehouse = $warehouse_stmt->fetch();
    } else {
        $warehouse = null;
    }
}

if (!$warehouse) {
    $_SESSION['error'] = "No warehouse configured or you don't have access!";
    header('Location: dashboard.php');
    exit();
}

$warehouse_id = $warehouse['id'];
$warehouse_name = $warehouse['shop_name'];

// Debug: Check warehouse ID
error_log("Warehouse ID: " . $warehouse_id . ", Warehouse Name: " . $warehouse_name);

// ==================== FILTERS ====================
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$subcategory = $_GET['subcategory'] ?? 'all';

$where = ["p.is_active = 1"];
$params = [];

// Add business_id filter if we have a business context
if ($current_business_id) {
    $where[] = "p.business_id = ?";
    $params[] = $current_business_id;
}

// Search filter
if ($search) {
    $where[] = "(p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ? OR p.hsn_code LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

// Category filter
if ($category !== 'all' && is_numeric($category)) {
    $where[] = "p.category_id = ?";
    $params[] = $category;
}

// Subcategory filter
if ($subcategory !== 'all' && is_numeric($subcategory)) {
    $where[] = "p.subcategory_id = ?";
    $params[] = $subcategory;
}

// Stock Status filter (based on warehouse stock only)
if ($status === 'low') {
    $where[] = "COALESCE(ps.quantity, 0) > 0 AND COALESCE(ps.quantity, 0) < p.min_stock_level";
} elseif ($status === 'out') {
    $where[] = "COALESCE(ps.quantity, 0) = 0";
} elseif ($status === 'in_stock') {
    $where[] = "COALESCE(ps.quantity, 0) >= p.min_stock_level";
}

// ==================== MAIN QUERY ====================
// Simplified query to show ONLY warehouse stock
$sql = "
    SELECT 
        p.id, p.product_name, p.product_code, p.barcode, 
        p.retail_price, p.wholesale_price, p.stock_price,
        p.min_stock_level, p.hsn_code, p.unit_of_measure,
        p.description, p.image_path, p.image_thumbnail_path, p.image_alt_text,
        c.id as category_id, c.category_name,
        sc.id as subcategory_id, sc.subcategory_name,
        COALESCE(ps.quantity, 0) AS warehouse_stock,
        CASE 
            WHEN COALESCE(ps.quantity, 0) = 0 THEN 'out_of_stock'
            WHEN COALESCE(ps.quantity, 0) < p.min_stock_level THEN 'low_stock'
            ELSE 'in_stock'
        END AS stock_status
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id AND p.business_id = c.business_id
    LEFT JOIN subcategories sc ON p.subcategory_id = sc.id AND p.business_id = sc.business_id
    LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ? AND ps.business_id = p.business_id
    " . (count($where) ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY p.product_name ASC
";

// Add warehouse ID to params
array_unshift($params, $warehouse_id);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Debug: Check what products we got
error_log("Products found: " . count($products));
foreach ($products as $p) {
    error_log("Product: " . $p['product_name'] . ", Warehouse Stock: " . $p['warehouse_stock']);
}

// ==================== STATISTICS ====================
$stats_sql = "
    SELECT 
        COUNT(*) AS total_products,
        SUM(COALESCE(ps.quantity, 0)) AS total_warehouse_stock,
        SUM(CASE WHEN COALESCE(ps.quantity, 0) = 0 THEN 1 ELSE 0 END) AS out_of_stock,
        SUM(CASE WHEN COALESCE(ps.quantity, 0) > 0 AND COALESCE(ps.quantity, 0) < p.min_stock_level THEN 1 ELSE 0 END) AS low_stock,
        SUM(CASE WHEN COALESCE(ps.quantity, 0) >= p.min_stock_level THEN 1 ELSE 0 END) AS in_stock,
        SUM(COALESCE(ps.quantity, 0) * p.stock_price) AS warehouse_stock_cost_value,
        SUM(COALESCE(ps.quantity, 0) * p.retail_price) AS warehouse_stock_retail_value
    FROM products p
    LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ? AND ps.business_id = p.business_id
    WHERE p.is_active = 1 
";

$stats_params = [$warehouse_id];

if ($current_business_id) {
    $stats_sql .= " AND p.business_id = ?";
    $stats_params[] = $current_business_id;
}

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch() ?: [
    'total_products' => 0,
    'total_warehouse_stock' => 0,
    'out_of_stock' => 0,
    'low_stock' => 0,
    'in_stock' => 0,
    'warehouse_stock_cost_value' => 0,
    'warehouse_stock_retail_value' => 0
];

// ==================== FILTER DATA ====================
// Categories for filter
if ($current_business_id) {
    $categories_stmt = $pdo->prepare("
        SELECT id, category_name 
        FROM categories 
        WHERE business_id = ? AND status = 'active' 
        ORDER BY category_name
    ");
    $categories_stmt->execute([$current_business_id]);
    $categories = $categories_stmt->fetchAll();
} else {
    $categories = [];
}

// Subcategories for filter (based on selected category)
$subcategories = [];
if ($category !== 'all' && is_numeric($category) && $current_business_id) {
    $subcategories_stmt = $pdo->prepare("
        SELECT id, subcategory_name 
        FROM subcategories 
        WHERE business_id = ? AND category_id = ? AND status = 'active'
        ORDER BY subcategory_name
    ");
    $subcategories_stmt->execute([$current_business_id, $category]);
    $subcategories = $subcategories_stmt->fetchAll();
}

// Page title
$page_title = "Warehouse Stock - " . htmlspecialchars($warehouse_name);
?>

<!doctype html>
<html lang="en">
<?php include 'includes/head.php'; ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include 'includes/sidebar.php'; ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                
                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">Warehouse Stock Management</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Warehouse Stock</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Warehouse Info Card -->
                <div class="card mb-4 bg-light border-0">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="card-title mb-1">
                                    <i class="bx bx-warehouse me-2 text-primary"></i>
                                    <?= htmlspecialchars($warehouse_name) ?>
                                    <span class="badge bg-primary">ID: <?= $warehouse_id ?></span>
                                </h5>
                                <p class="text-muted mb-0">
                                    <i class="bx bx-building me-1"></i> <?= htmlspecialchars($current_business_name) ?>
                                    <span class="mx-2">|</span>
                                    <i class="bx bx-calendar me-1"></i> <?= date('F j, Y') ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <?php if (in_array($user_role, ['admin', 'warehouse_manager', 'stock_manager'])): ?>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkAdjustModal">
                                    <i class="bx bx-plus-circle me-1"></i> Bulk Adjust
                                </button>
                                <a href="new_transfer.php?source=<?= $warehouse_id ?>" class="btn btn-success">
                                    <i class="bx bx-transfer me-1"></i> Transfer Stock
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Products</h6>
                                        <h3 class="mb-0"><?= number_format($stats['total_products'] ?? 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm flex-shrink-0">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-package text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Warehouse Stock</h6>
                                        <h3 class="mb-0 text-success"><?= number_format($stats['total_warehouse_stock'] ?? 0) ?></h3>
                                        <small class="text-muted">Items in <?= htmlspecialchars($warehouse_name) ?></small>
                                    </div>
                                    <div class="avatar-sm flex-shrink-0">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-warehouse text-success"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Low Stock</h6>
                                        <h3 class="mb-0 text-warning"><?= number_format($stats['low_stock'] ?? 0) ?></h3>
                                        <small class="text-muted">Below minimum level</small>
                                    </div>
                                    <div class="avatar-sm flex-shrink-0">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-error text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Out of Stock</h6>
                                        <h3 class="mb-0 text-danger"><?= number_format($stats['out_of_stock'] ?? 0) ?></h3>
                                        <small class="text-muted">Zero in warehouse</small>
                                    </div>
                                    <div class="avatar-sm flex-shrink-0">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-x-circle text-danger"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Product name, code, barcode..." 
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select" id="categorySelect">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" 
                                            <?= $category == $cat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Subcategory</label>
                                <select name="subcategory" class="form-select" id="subcategorySelect">
                                    <option value="all">All Subcategories</option>
                                    <?php foreach ($subcategories as $subcat): ?>
                                        <option value="<?= $subcat['id'] ?>" 
                                            <?= $subcategory == $subcat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($subcat['subcategory_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Stock Status</label>
                                <select name="status" class="form-select">
                                    <option value="all">All Status</option>
                                    <option value="in_stock" <?= $status == 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                                    <option value="low" <?= $status == 'low' ? 'selected' : '' ?>>Low Stock</option>
                                    <option value="out" <?= $status == 'out' ? 'selected' : '' ?>>Out of Stock</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="btn-group w-100">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bx bx-filter-alt me-1"></i> Filter
                                    </button>
                                    <a href="warehouse_stock.php" class="btn btn-outline-secondary">
                                        <i class="bx bx-reset me-1"></i> Reset
                                    </a>
                                    <?php if (in_array($user_role, ['admin', 'stock_manager'])): ?>
                                    <a href="export_warehouse_stock.php" class="btn btn-outline-success">
                                        <i class="bx bx-download me-1"></i> Export
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="warehouseTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th>Code / Barcode</th>
                                        <th>Category</th>
                                        <th class="text-end">Warehouse Stock</th>
                                        <th class="text-end">Min Level</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-end">Retail Price</th>
                                        <th class="text-end">Wholesale Price</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($products)): ?>
                                    
                                    <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <?php
                                        // Stock status styling
                                        $current_stock = $product['warehouse_stock'];
                                        $min_stock = $product['min_stock_level'] ?: 10;
                                        $stock_percentage = $min_stock > 0 ? ($current_stock / $min_stock) * 100 : 0;
                                        
                                        if ($current_stock == 0) {
                                            $stock_class = 'danger';
                                            $stock_text = 'Out of Stock';
                                        } elseif ($stock_percentage < 25) {
                                            $stock_class = 'danger';
                                            $stock_text = 'Critical';
                                        } elseif ($stock_percentage < 100) {
                                            $stock_class = 'warning';
                                            $stock_text = 'Low';
                                        } else {
                                            $stock_class = 'success';
                                            $stock_text = 'In Stock';
                                        }
                                        
                                        $has_image = !empty($product['image_thumbnail_path']);
                                        $image_src = $has_image ? htmlspecialchars($product['image_thumbnail_path']) : '';
                                        $full_image_src = $has_image ? htmlspecialchars($product['image_path'] ?? $product['image_thumbnail_path']) : '';
                                        $alt_text = htmlspecialchars($product['image_alt_text'] ?? $product['product_name']);
                                        
                                        $profit_margin = $product['retail_price'] > 0 && $product['stock_price'] > 0 ?
                                            (($product['retail_price'] - $product['stock_price']) / $product['stock_price']) * 100 : 0;
                                        ?>
                                        <tr class="product-row" data-id="<?= $product['id'] ?>">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($has_image): ?>
                                                        <div class="avatar-sm me-3 position-relative">
                                                            <img src="<?= $image_src ?>"
                                                                 alt="<?= $alt_text ?>"
                                                                 class="rounded img-thumbnail product-image"
                                                                 data-full-image="<?= $full_image_src ?>"
                                                                 data-product-name="<?= htmlspecialchars($product['product_name']) ?>"
                                                                 style="width: 48px; height: 48px; object-fit: cover; cursor: pointer;">
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="avatar-sm me-3">
                                                            <div class="avatar-title bg-light text-primary rounded">
                                                                <i class="bx bx-package fs-4"></i>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <strong class="d-block mb-1"><?= htmlspecialchars($product['product_name']) ?></strong>
                                                        <?php if (!empty($product['description'])): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars(substr($product['description'], 0, 80)) ?><?= strlen($product['description']) > 80 ? '...' : '' ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div>
                                                    <?php if (!empty($product['product_code'])): ?>
                                                    <span class="badge bg-light text-dark"><?= htmlspecialchars($product['product_code']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($product['barcode'])): ?>
                                                    <br><small class="text-muted"><i class="bx bx-barcode me-1"></i><?= htmlspecialchars($product['barcode']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?>
                                                <?php if (!empty($product['subcategory_name'])): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($product['subcategory_name']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <strong class="<?= $current_stock == 0 ? 'text-danger' : ($current_stock < $min_stock ? 'text-warning' : 'text-primary') ?>">
                                                    <?= $current_stock ?>
                                                </strong>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($product['unit_of_measure']) ?></small>
                                                <?php if ($current_stock < $min_stock && $current_stock > 0): ?>
                                                <br><small class="text-warning">
                                                    <i class="bx bx-alarm"></i> Below min
                                                </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <span class="badge bg-light text-dark border">
                                                    Min: <?= $min_stock ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-<?= $stock_class ?> bg-opacity-10 text-<?= $stock_class ?> px-3 py-1">
                                                    <i class="bx bx-circle me-1"></i><?= $stock_text ?>
                                                </span>
                                                <?php if ($stock_percentage > 0): ?>
                                                <div class="progress mt-1" style="width: 60px; margin: 0 auto;">
                                                    <div class="progress-bar bg-<?= $stock_class ?>"
                                                         role="progressbar"
                                                         style="width: <?= min($stock_percentage, 100) ?>%"></div>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-success">₹<?= number_format($product['retail_price'], 2) ?></strong>
                                                <?php if ($profit_margin > 0): ?>
                                                <br><small class="text-success">
                                                    <i class="bx bx-trending-up"></i> <?= number_format($profit_margin, 1) ?>%
                                                </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-warning">₹<?= number_format($product['wholesale_price'], 2) ?></strong>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="product_view.php?id=<?= $product['id'] ?>"
                                                       class="btn btn-outline-info"
                                                       data-bs-toggle="tooltip"
                                                       title="View Details">
                                                        <i class="bx bx-show"></i>
                                                    </a>
                                                    <?php if (in_array($user_role, ['admin', 'warehouse_manager', 'stock_manager'])): ?>
                                                    <button type="button" 
                                                            class="btn btn-outline-primary adjust-stock-btn"
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#adjustStockModal"
                                                            data-product-id="<?= $product['id'] ?>"
                                                            data-product-name="<?= htmlspecialchars($product['product_name']) ?>"
                                                            data-current-stock="<?= $current_stock ?>"
                                                            data-bs-toggle="tooltip"
                                                            title="Adjust Stock">
                                                        <i class="bx bx-edit"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if (in_array($user_role, ['admin', 'stock_manager'])): ?>
                                                    <a href="stock_transfer_add.php?product_id=<?= $product['id'] ?>&source=<?= $warehouse_id ?>" 
                                                       class="btn btn-outline-success"
                                                       data-bs-toggle="tooltip"
                                                       title="Transfer Stock">
                                                        <i class="bx bx-transfer"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Value Summary -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Warehouse Value Summary</h6>
                                <div class="row">
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Cost Value</p>
                                        <h4 class="text-primary">₹<?= number_format($stats['warehouse_stock_cost_value'] ?? 0, 2) ?></h4>
                                    </div>
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Retail Value</p>
                                        <h4 class="text-success">₹<?= number_format($stats['warehouse_stock_retail_value'] ?? 0, 2) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Stock Distribution</h6>
                                <div class="row">
                                    <div class="col-4">
                                        <p class="text-muted mb-1">In Stock</p>
                                        <h5 class="text-success"><?= $stats['in_stock'] ?? 0 ?></h5>
                                    </div>
                                    <div class="col-4">
                                        <p class="text-muted mb-1">Low Stock</p>
                                        <h5 class="text-warning"><?= $stats['low_stock'] ?? 0 ?></h5>
                                    </div>
                                    <div class="col-4">
                                        <p class="text-muted mb-1">Out of Stock</p>
                                        <h5 class="text-danger"><?= $stats['out_of_stock'] ?? 0 ?></h5>
                                    </div>
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

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adjust Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="adjustStockForm" action="process_adjust_stock.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="product_id" id="modalProductId">
                    <input type="hidden" name="shop_id" value="<?= $warehouse_id ?>">
                    <input type="hidden" name="business_id" value="<?= $current_business_id ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control" id="modalProductName" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Current Stock</label>
                        <input type="text" class="form-control" id="modalCurrentStock" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select name="adjustment_type" class="form-select" required>
                            <option value="add">Add Stock</option>
                            <option value="remove">Remove Stock</option>
                            <option value="correction">Correction</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" class="form-control" min="1" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <select name="reason" class="form-select" required>
                            <option value="stock_take">Stock Take</option>
                            <option value="damaged">Damaged Goods</option>
                            <option value="expired">Expired</option>
                            <option value="found">Found Stock</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Adjust Modal -->
<div class="modal fade" id="bulkAdjustModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Stock Adjustment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="process_bulk_adjust.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="shop_id" value="<?= $warehouse_id ?>">
                    <input type="hidden" name="business_id" value="<?= $current_business_id ?>">
                    
                    <div class="alert alert-info">
                        <i class="bx bx-info-circle me-2"></i>
                        Upload a CSV file with columns: product_code, adjustment_type, quantity, reason, notes
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Upload CSV File</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Or Enter Manually (JSON format)</label>
                        <textarea name="bulk_data" class="form-control" rows="4" 
                                  placeholder='[{"product_code": "PROD001", "adjustment_type": "add", "quantity": 10, "reason": "stock_take"}]'></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Process Bulk Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    // Initialize DataTables with client-side processing
    $('#warehouseTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search products:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ products",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        },
        columnDefs: [
            {
                targets: [3, 4, 6, 7], // numeric columns
                className: 'dt-body-right'
            },
            {
                targets: [5, 8], // center columns
                className: 'dt-body-center'
            }
        ]
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Stock adjustment modal
    const adjustStockModal = new bootstrap.Modal('#adjustStockModal');
    $('.adjust-stock-btn').click(function() {
        $('#modalProductId').val($(this).data('product-id'));
        $('#modalProductName').val($(this).data('product-name'));
        $('#modalCurrentStock').val($(this).data('current-stock'));
        $('#adjustQuantity').val('');
        $('#adjustReason').val('');
    });

    // Form submission with validation
    $('#adjustStockForm').submit(function(e) {
        e.preventDefault();
        
        const quantity = $(this).find('[name="quantity"]').val();
        const adjustmentType = $(this).find('[name="adjustment_type"]').val();
        const currentStock = parseInt($('#modalCurrentStock').val());
        
        // Validate removal doesn't go negative
        if (adjustmentType === 'remove' && parseInt(quantity) > currentStock) {
            alert('Cannot remove more stock than available!');
            return;
        }
        
        // Show loading
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="bx bx-loader bx-spin me-2"></i> Processing...');
        submitBtn.prop('disabled', true);
        
        const formData = new FormData(this);
        
        fetch('process_adjust_stock.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('success', 'Stock adjusted successfully!');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', data.message || 'Error updating stock');
                submitBtn.html(originalText);
                submitBtn.prop('disabled', false);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', 'Network error occurred');
            submitBtn.html(originalText);
            submitBtn.prop('disabled', false);
        });
    });

    // Image hover
    $('.product-image').hover(
        function() { $(this).css({transform: 'scale(1.1)', transition: 'transform 0.3s ease', boxShadow: '0 4px 12px rgba(91, 115, 232, 0.2)'}); },
        function() { $(this).css({transform: 'scale(1)', boxShadow: 'none'}); }
    );

    // Fallback on image error
    $('.product-image').on('error', function() {
        $(this).addClass('d-none');
        $(this).closest('.position-relative').find('.avatar-title').removeClass('d-none');
    });

    // Row hover
    $('.product-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // Toast function
    function showToast(type, message) {
        $('.toast').remove();
        const toast = $(`<div class="toast align-items-center text-bg-${type} border-0" role="alert"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
        if ($('.toast-container').length === 0) $('body').append('<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999"></div>');
        $('.toast-container').append(toast);
        new bootstrap.Toast(toast[0]).show();
    }
});
</script>
<style>
.table-hover tbody tr:hover {
    background-color: rgba(91, 115, 232, 0.05) !important;
}
.border-start {
    border-left-width: 4px !important;
}
.card-hover {
    transition: all 0.3s ease;
}
.card-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}
.avatar-sm {
    width: 40px;
    height: 40px;
}
.avatar-title {
    display: flex;
    align-items: center;
    justify-content: center;
}
.badge.bg-light {
    border: 1px solid #dee2e6;
}
.btn-group .btn {
    border-radius: 0.25rem !important;
}
.btn-group .btn:first-child {
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
}
.btn-group .btn:last-child {
    border-top-left-radius: 0 !important;
    border-bottom-left-radius: 0 !important;
}
</style>
</body>
</html>