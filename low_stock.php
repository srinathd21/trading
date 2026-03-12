<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$is_admin = ($user_role === 'admin');
$is_stock_manager = in_array($user_role, ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager']);

// Force collation
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// === ADMIN SHOP SWITCHING ===
if ($is_admin && isset($_GET['switch_shop'])) {
    $new_shop_id = (int)$_GET['switch_shop'];
    
    // Verify shop exists
    $stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE id = ?");
    $stmt->execute([$new_shop_id]);
    $shop = $stmt->fetch();
    
    if ($shop) {
        $_SESSION['current_shop_id'] = $new_shop_id;
        $_SESSION['current_shop_name'] = $shop['shop_name'];
        $_SESSION['success'] = "Switched to shop: " . $shop['shop_name'];
        
        header('Location: low_stock.php');
        exit();
    }
}

// Get all shops for admin dropdown
$shops = [];
if ($is_admin) {
    $shops = $pdo->query("SELECT id, shop_name, location, is_warehouse FROM shops WHERE is_active = 1 ORDER BY is_warehouse DESC, shop_name")->fetchAll();
} else {
    // Non-admin users see only their current shop
    $stmt = $pdo->prepare("SELECT id, shop_name, is_warehouse FROM shops WHERE id = ? AND is_active = 1");
    $stmt->execute([$current_shop_id]);
    $shop = $stmt->fetch();
    if ($shop) {
        $shops = [$shop];
    }
}

// === FILTERS & SEARCH ===
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$stock_type = $_GET['stock_type'] ?? 'both'; // shop, warehouse, both
$selected_shop_id = $_GET['shop_id'] ?? ($is_admin ? 'all' : $current_shop_id);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build base conditions
$where_conditions = [];
$params = [];
$joins = [];

// Shop/warehouse filter
if ($selected_shop_id !== 'all') {
    // Single shop view
    $shop_condition = "ps.shop_id = " . (int)$selected_shop_id;
    $where_conditions[] = $shop_condition;
} else {
    // All shops view
    if ($stock_type === 'shop') {
        // Only shops (non-warehouse)
        $joins[] = "LEFT JOIN shops s ON ps.shop_id = s.id";
        $where_conditions[] = "s.is_warehouse = 0";
    } elseif ($stock_type === 'warehouse') {
        // Only warehouses
        $joins[] = "LEFT JOIN shops s ON ps.shop_id = s.id";
        $where_conditions[] = "s.is_warehouse = 1";
    }
    // 'both' shows all
}

// Search condition
if ($search !== '') {
    $where_conditions[] = "(p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ? OR p.hsn_code LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

// Category filter
if ($category !== '') {
    $where_conditions[] = "p.category_id = ?";
    $params[] = $category;
}

// Low stock condition - stock is less than minimum stock level
$where_conditions[] = "ps.quantity < p.min_stock_level";
$where_conditions[] = "ps.quantity > 0"; // Exclude out of stock

// Combine WHERE conditions
$where = "WHERE " . implode(" AND ", $where_conditions);

// Additional joins
$joins[] = "LEFT JOIN categories c ON p.category_id = c.id";
$joins[] = "LEFT JOIN gst_rates gr ON p.gst_id = gr.id";
$joins[] = "LEFT JOIN shops shop ON ps.shop_id = shop.id";

// Fetch categories for filter
$categories = $pdo->query("SELECT id, category_name FROM categories ORDER BY category_name")->fetchAll();

// === GET LOW STOCK STATISTICS ===
$stats_sql = "
    SELECT 
        COUNT(DISTINCT p.id) as total_low_products,
        COUNT(DISTINCT ps.shop_id) as total_shops_affected,
        SUM(CASE WHEN ps.quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN ps.quantity > 0 AND ps.quantity < p.min_stock_level * 0.25 THEN 1 ELSE 0 END) as critical_stock,
        SUM(CASE WHEN ps.quantity >= p.min_stock_level * 0.25 AND ps.quantity < p.min_stock_level THEN 1 ELSE 0 END) as low_stock,
        COALESCE(SUM(ps.quantity * p.stock_price), 0) as total_cost_value,
        COALESCE(SUM((p.min_stock_level - ps.quantity) * p.stock_price), 0) as required_investment,
        SUM(p.min_stock_level - ps.quantity) as total_units_needed
    FROM products p
    INNER JOIN product_stocks ps ON p.id = ps.product_id
    " . ($selected_shop_id !== 'all' ? "AND ps.shop_id = " . (int)$selected_shop_id : "") . "
    LEFT JOIN shops s ON ps.shop_id = s.id
    WHERE ps.quantity < p.min_stock_level 
    AND ps.quantity > 0
    AND p.is_active = 1
    " . ($stock_type === 'shop' ? "AND s.is_warehouse = 0" : "") . "
    " . ($stock_type === 'warehouse' ? "AND s.is_warehouse = 1" : "") . "
";

$stats_stmt = $pdo->query($stats_sql);
$stats = $stats_stmt->fetch();

// === GET LOW STOCK PRODUCTS ===
$sql = "
    SELECT 
        p.id,
        p.product_name,
        p.product_code,
        p.barcode,
        p.hsn_code,
        p.retail_price,
        p.wholesale_price,
        p.stock_price,
        p.min_stock_level,
        c.category_name,
        ps.quantity as current_stock,
        shop.shop_name,
        shop.is_warehouse,
        
        CONCAT(gr.cgst_rate + gr.sgst_rate + gr.igst_rate, '%') as tax_rate,
        (p.min_stock_level - ps.quantity) as units_needed,
        ROUND((ps.quantity * 100.0 / p.min_stock_level), 1) as stock_percentage,
        (p.min_stock_level - ps.quantity) * p.stock_price as restock_cost
    FROM products p
    INNER JOIN product_stocks ps ON p.id = ps.product_id
    " . implode(" ", $joins) . "
    $where
    AND p.is_active = 1
    ORDER BY 
        CASE 
            WHEN ps.quantity < p.min_stock_level * 0.25 THEN 1
            WHEN ps.quantity < p.min_stock_level * 0.5 THEN 2
            ELSE 3
        END,
        stock_percentage ASC,
        restock_cost DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$low_stock_products = $stmt->fetchAll();

// === GET TOTAL COUNT FOR PAGINATION ===
$count_sql = "
    SELECT COUNT(DISTINCT CONCAT(p.id, '-', ps.shop_id))
    FROM products p
    INNER JOIN product_stocks ps ON p.id = ps.product_id
    " . ($selected_shop_id !== 'all' ? "AND ps.shop_id = " . (int)$selected_shop_id : "") . "
    LEFT JOIN shops s ON ps.shop_id = s.id
    WHERE ps.quantity < p.min_stock_level 
    AND ps.quantity > 0
    AND p.is_active = 1
    " . ($stock_type === 'shop' ? "AND s.is_warehouse = 0" : "") . "
    " . ($stock_type === 'warehouse' ? "AND s.is_warehouse = 1" : "") . "
";

$count_stmt = $pdo->query($count_sql);
$total_items = $count_stmt->fetchColumn();
$total_pages = ceil($total_items / $limit);

// === GET SHOP-WISE SUMMARY ===
$shop_summary_sql = "
    SELECT 
        s.id,
        s.shop_name,
        s.is_warehouse,
        COUNT(DISTINCT p.id) as low_products,
        SUM(CASE WHEN ps.quantity < p.min_stock_level * 0.25 THEN 1 ELSE 0 END) as critical_count,
        SUM(p.min_stock_level - ps.quantity) as total_units_needed,
        SUM((p.min_stock_level - ps.quantity) * p.stock_price) as restock_value
    FROM shops s
    INNER JOIN product_stocks ps ON s.id = ps.shop_id
    INNER JOIN products p ON ps.product_id = p.id
    WHERE ps.quantity < p.min_stock_level 
    AND ps.quantity > 0
    AND p.is_active = 1
    AND s.is_active = 1
    GROUP BY s.id, s.shop_name, s.is_warehouse
    ORDER BY s.is_warehouse DESC, low_products DESC
";

$shop_summary = $pdo->query($shop_summary_sql)->fetchAll();
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Low Stock Alert";
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
                <!-- Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <!-- Header with buttons -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h4 class="card-title mb-0">
                                        <i class="bx bx-alarm-exclamation me-2"></i> Low Stock Alert
                                        <?php if ($is_admin && $selected_shop_id !== 'all'): ?>
                                        <span class="badge bg-<?= $shops[array_search($selected_shop_id, array_column($shops, 'id'))]['is_warehouse'] ? 'info' : 'primary' ?> ms-2">
                                            <?= htmlspecialchars($shops[array_search($selected_shop_id, array_column($shops, 'id'))]['shop_name'] ?? 'Unknown') ?>
                                            <?php if ($shops[array_search($selected_shop_id, array_column($shops, 'id'))]['is_warehouse']): ?>
                                            <small class="ms-1"><i class="fas fa-warehouse"></i></small>
                                            <?php endif; ?>
                                        </span>
                                        <?php elseif ($is_admin && $selected_shop_id === 'all'): ?>
                                        <span class="badge bg-info ms-2">
                                            <?= $stock_type === 'both' ? 'All Locations' : ($stock_type === 'warehouse' ? 'Warehouses Only' : 'Shops Only') ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-<?= $_SESSION['current_shop_is_warehouse'] ? 'info' : 'primary' ?> ms-2">
                                            <?= htmlspecialchars($_SESSION['current_shop_name'] ?? 'Current Shop') ?>
                                            <?php if ($_SESSION['current_shop_is_warehouse'] ?? false): ?>
                                            <small class="ms-1"><i class="fas fa-warehouse"></i></small>
                                            <?php endif; ?>
                                        </span>
                                        <?php endif; ?>
                                    </h4>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-outline-secondary" onclick="exportLowStock()">
                                            <i class="bx bx-export me-1"></i> Export
                                        </button>
                                        <?php if ($is_stock_manager && $selected_shop_id !== 'all'): ?>
                                        <a href="purchase_add.php?shop_id=<?= $selected_shop_id ?>&low_stock=1" class="btn btn-success">
                                            <i class="bx bx-cart-add me-1"></i> Quick Purchase
                                        </a>
                                        <a href="stock_transfer_add.php?to_shop=<?= $selected_shop_id ?>&low_stock=1" class="btn btn-info">
                                            <i class="bx bx-transfer me-1"></i> Transfer Stock
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($is_admin): ?>
                                        <button class="btn btn-warning" onclick="sendLowStockAlert()">
                                            <i class="bx bx-bell me-1"></i> Send Alert
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Admin Shop Switch Panel -->
                                <?php if ($is_admin && !empty($shops)): ?>
                                <div class="card bg-light mb-4">
                                    <div class="card-body py-2">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <i class="bx bx-store text-primary"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <small class="text-muted d-block">Switch Location / View All</small>
                                                <div class="d-flex flex-wrap gap-2 mt-1">
                                                    <a href="?shop_id=all&stock_type=both<?= $search?'&search='.urlencode($search):'' ?><?= $category?'&category='.$category:'' ?>" 
                                                       class="btn btn-sm btn-outline-info <?= $selected_shop_id === 'all' && $stock_type === 'both' ? 'active' : '' ?>">
                                                        <i class="bx bx-grid-alt me-1"></i> All Locations
                                                    </a>
                                                    <a href="?shop_id=all&stock_type=shop<?= $search?'&search='.urlencode($search):'' ?><?= $category?'&category='.$category:'' ?>" 
                                                       class="btn btn-sm btn-outline-primary <?= $selected_shop_id === 'all' && $stock_type === 'shop' ? 'active' : '' ?>">
                                                        <i class="bx bx-store me-1"></i> Shops Only
                                                    </a>
                                                    <a href="?shop_id=all&stock_type=warehouse<?= $search?'&search='.urlencode($search):'' ?><?= $category?'&category='.$category:'' ?>" 
                                                       class="btn btn-sm btn-outline-info <?= $selected_shop_id === 'all' && $stock_type === 'warehouse' ? 'active' : '' ?>">
                                                        <i class="fas fa-warehouse me-1"></i> Warehouses Only
                                                    </a>
                                                    <?php foreach ($shops as $shop): ?>
                                                    <a href="?shop_id=<?= $shop['id'] ?><?= $search?'&search='.urlencode($search):'' ?><?= $category?'&category='.$category:'' ?>" 
                                                       class="btn btn-sm btn-outline-<?= $shop['is_warehouse'] ? 'info' : 'primary' ?> <?= $selected_shop_id == $shop['id'] ? 'active' : '' ?>">
                                                        <?php if ($shop['is_warehouse']): ?>
                                                        <i class="fas fa-warehouse me-1"></i>
                                                        <?php else: ?>
                                                        <i class="bx bx-store me-1"></i>
                                                        <?php endif; ?>
                                                        <?= htmlspecialchars($shop['shop_name']) ?>
                                                        <?php if ($current_shop_id == $shop['id']): ?>
                                                        <span class="badge bg-success ms-1">Current</span>
                                                        <?php endif; ?>
                                                    </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Search & Filter -->
                                <form method="GET" class="mb-4">
                                    <?php if ($is_admin && $selected_shop_id !== 'all'): ?>
                                    <input type="hidden" name="shop_id" value="<?= $selected_shop_id ?>">
                                    <?php endif; ?>
                                    <div class="row g-3 align-items-end">
                                        <div class="col-md-3">
                                            <label class="form-label">Search Products</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                                <input type="text" name="search" class="form-control" 
                                                       placeholder="Name, Code, Barcode..."
                                                       value="<?= htmlspecialchars($search) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Category</label>
                                            <select name="category" class="form-select">
                                                <option value="">All Categories</option>
                                                <?php foreach ($categories as $cat): ?>
                                                <option value="<?= $cat['id'] ?>" <?= $category==$cat['id']?'selected':'' ?>>
                                                    <?= htmlspecialchars($cat['category_name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php if ($is_admin && $selected_shop_id === 'all'): ?>
                                        <div class="col-md-2">
                                            <label class="form-label">Stock Type</label>
                                            <select name="stock_type" class="form-select">
                                                <option value="both" <?= $stock_type==='both'?'selected':'' ?>>All Locations</option>
                                                <option value="shop" <?= $stock_type==='shop'?'selected':'' ?>>Shops Only</option>
                                                <option value="warehouse" <?= $stock_type==='warehouse'?'selected':'' ?>>Warehouses Only</option>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                        <div class="col-md-2">
                                            <label class="form-label">&nbsp;</label>
                                            <button type="submit" class="btn btn-primary w-100 h-100 d-flex align-items-center justify-content-center">
                                                <i class="fas fa-search me-1"></i> Search
                                            </button>
                                        </div>
                                        <?php if ($search || $category || ($is_admin && $selected_shop_id === 'all' && $stock_type != 'both')): ?>
                                        <div class="col-md-3">
                                            <label class="form-label">&nbsp;</label>
                                            <a href="low_stock.php<?= $is_admin && $selected_shop_id !== 'all' ? '?shop_id='.$selected_shop_id : '' ?>" 
                                               class="btn btn-outline-secondary w-100 h-100 d-flex align-items-center justify-content-center">
                                                <i class="fas fa-times me-1"></i> Clear Filters
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </form>

                                <!-- Summary Cards -->
                                <div class="row mb-4">
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card mini-stat bg-warning text-white">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h5 class="font-size-14 text-white">Low Stock Products</h5>
                                                        <h4 class="mt-2"><?= $stats['total_low_products'] ?? 0 ?></h4>
                                                        <small class="d-block">in <?= $stats['total_shops_affected'] ?? 0 ?> locations</small>
                                                    </div>
                                                    <div class="align-self-center">
                                                        <i class="fas fa-exclamation-triangle font-size-30"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card mini-stat bg-danger text-white">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h5 class="font-size-14 text-white">Critical Stock</h5>
                                                        <h4 class="mt-2"><?= $stats['critical_stock'] ?? 0 ?></h4>
                                                        <small class="d-block">Below 25%</small>
                                                    </div>
                                                    <div class="align-self-center">
                                                        <i class="fas fa-skull-crossbones font-size-30"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card mini-stat bg-info text-white">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h5 class="font-size-14 text-white">Units Needed</h5>
                                                        <h4 class="mt-2"><?= number_format($stats['total_units_needed'] ?? 0) ?></h4>
                                                        <small class="d-block">To reach min stock</small>
                                                    </div>
                                                    <div class="align-self-center">
                                                        <i class="fas fa-boxes font-size-30"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card mini-stat bg-success text-white">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h5 class="font-size-14 text-white">Restock Cost</h5>
                                                        <h4 class="mt-2">₹<?= number_format($stats['required_investment'] ?? 0, 0) ?></h4>
                                                        <small class="d-block">Estimated investment</small>
                                                    </div>
                                                    <div class="align-self-center">
                                                        <i class="fas fa-rupee-sign font-size-30"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Shop-wise Summary -->
                                <?php if ($is_admin && $selected_shop_id === 'all' && !empty($shop_summary)): ?>
                                <div class="row mb-4">
                                    <div class="col-12">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="card-title mb-3">
                                                    <i class="fas fa-chart-pie me-2"></i> Location-wise Low Stock Summary
                                                </h5>
                                                <div class="row">
                                                    <?php foreach ($shop_summary as $summary): ?>
                                                    <div class="col-md-3 col-6 mb-3">
                                                        <div class="card border <?= $summary['is_warehouse'] ? 'border-info' : 'border-primary' ?> h-100">
                                                            <div class="card-body p-3">
                                                                <div class="d-flex align-items-center">
                                                                    <div class="flex-shrink-0">
                                                                        <div class="avatar-sm <?= $summary['is_warehouse'] ? 'bg-info' : 'bg-primary' ?> bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                                            <?php if ($summary['is_warehouse']): ?>
                                                                            <i class="fas fa-warehouse text-info"></i>
                                                                            <?php else: ?>
                                                                            <i class="bx bx-store text-primary"></i>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="flex-grow-1 ms-3">
                                                                        <h6 class="mb-1">
                                                                            <?= htmlspecialchars($summary['shop_name']) ?>
                                                                            <?php if ($summary['is_warehouse']): ?>
                                                                            <small class="text-info ms-1"><i class="fas fa-warehouse"></i></small>
                                                                            <?php endif; ?>
                                                                        </h6>
                                                                        <div class="d-flex justify-content-between">
                                                                            <div>
                                                                                <span class="badge bg-<?= $summary['critical_count'] > 0 ? 'danger' : 'warning' ?> rounded-pill">
                                                                                    <?= $summary['low_products'] ?> products
                                                                                </span>
                                                                            </div>
                                                                            <div>
                                                                                <a href="?shop_id=<?= $summary['id'] ?>" class="text-decoration-none">
                                                                                    <i class="fas fa-arrow-right"></i>
                                                                                </a>
                                                                            </div>
                                                                        </div>
                                                                        <small class="text-muted d-block mt-1">
                                                                            <?= $summary['total_units_needed'] ?> units needed
                                                                        </small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Low Stock Products Table -->
                                <div class="table-responsive">
                                    <table class="table table-hover table-bordered align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Product Details</th>
                                                <th class="text-center">Location</th>
                                                <th class="text-center">Stock Level</th>
                                                <th class="text-center">Required</th>
                                                <th class="text-end">Restock Cost</th>
                                                <th class="text-center">Status</th>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($low_stock_products)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <i class="fas fa-check-circle display-1 text-success"></i>
                                                    <h5 class="text-success mt-3">No Low Stock Items!</h5>
                                                    <p class="text-muted">All products are adequately stocked.</p>
                                                    <a href="shop_stocks.php<?= $selected_shop_id !== 'all' ? '?shop_id='.$selected_shop_id : '' ?>" class="btn btn-primary">
                                                        <i class="fas fa-store me-1"></i> View All Stock
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php else: 
                                            $counter = $offset + 1;
                                            foreach ($low_stock_products as $p): 
                                                $stock_percentage = $p['stock_percentage'];
                                                $units_needed = $p['units_needed'];
                                                
                                                // Determine stock severity
                                                if ($stock_percentage < 25) {
                                                    $severity = 'critical';
                                                    $severity_class = 'danger';
                                                    $severity_text = 'Critical';
                                                    $severity_icon = 'fas fa-skull-crossbones';
                                                } elseif ($stock_percentage < 50) {
                                                    $severity = 'very-low';
                                                    $severity_class = 'warning';
                                                    $severity_text = 'Very Low';
                                                    $severity_icon = 'fas fa-exclamation-triangle';
                                                } else {
                                                    $severity = 'low';
                                                    $severity_class = 'warning';
                                                    $severity_text = 'Low';
                                                    $severity_icon = 'fas fa-exclamation-circle';
                                                }
                                            ?>
                                            <tr class="table-<?= $severity_class ?>-subtle">
                                                <td class="fw-bold"><?= $counter++ ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm me-3">
                                                            <div class="bg-<?= $severity_class ?> rounded-circle d-flex align-items-center justify-content-center"
                                                                 style="width: 40px; height: 40px;">
                                                                <span class="text-white fw-bold">
                                                                    <?= strtoupper(substr($p['product_name'], 0, 2)) ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <strong><?= htmlspecialchars($p['product_name']) ?></strong>
                                                            <br><small class="text-muted">
                                                                <code><?= htmlspecialchars($p['product_code']) ?></code>
                                                                <?php if ($p['barcode']): ?>
                                                                • <i class="fas fa-barcode"></i> <?= htmlspecialchars($p['barcode']) ?>
                                                                <?php endif; ?>
                                                            </small>
                                                            <br><small class="text-muted">
                                                                <i class="fas fa-tag me-1"></i><?= htmlspecialchars($p['category_name']) ?>
                                                                • Min Stock: <?= $p['min_stock_level'] ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex flex-column align-items-center">
                                                        <span class="badge bg-<?= $p['is_warehouse'] ? 'info' : 'primary' ?>">
                                                            <?php if ($p['is_warehouse']): ?>
                                                            <i class="fas fa-warehouse me-1"></i>
                                                            <?php else: ?>
                                                            <i class="bx bx-store me-1"></i>
                                                            <?php endif; ?>
                                                            <?= htmlspecialchars($p['shop_name']) ?>
                                                        </span>
                                                        <?php if ($p['location']): ?>
                                                        <small class="text-muted mt-1"><?= htmlspecialchars($p['location']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex flex-column align-items-center">
                                                        <span class="badge bg-<?= $severity_class ?> rounded-pill px-3 py-2 fs-6">
                                                            <?= $p['current_stock'] ?>
                                                        </span>
                                                        <div class="progress w-100 mt-1" style="height: 6px;">
                                                            <div class="progress-bar bg-<?= $severity_class ?>" 
                                                                 style="width: <?= min($stock_percentage, 100) ?>%"
                                                                 role="progressbar">
                                                            </div>
                                                        </div>
                                                        <small class="text-muted mt-1">
                                                            <?= number_format($stock_percentage, 1) ?>% of min
                                                        </small>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex flex-column align-items-center">
                                                        <span class="badge bg-danger rounded-pill px-3 py-2 fs-6">
                                                            <?= $units_needed ?>
                                                        </span>
                                                        <small class="text-muted mt-1">units needed</small>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <strong class="text-danger">₹<?= number_format($p['restock_cost'], 2) ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        Cost: ₹<?= number_format($p['stock_price'], 2) ?>/unit
                                                    </small>
                                                    <?php if ($p['tax_rate'] && $p['tax_rate'] != '0%'): ?>
                                                    <br><small class="text-muted">GST: <?= $p['tax_rate'] ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?= $severity_class ?> px-3 py-2">
                                                        <i class="<?= $severity_icon ?> me-1"></i>
                                                        <?= $severity_text ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="product_view.php?id=<?= $p['id'] ?>"
                                                           class="btn btn-outline-info btn-sm" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if ($is_stock_manager): ?>
                                                        <button type="button" class="btn btn-outline-warning btn-sm" 
                                                                onclick="quickRestock(<?= $p['id'] ?>, '<?= addslashes($p['product_name']) ?>', <?= $units_needed ?>, <?= $p['stock_price'] ?>, <?= $p['is_warehouse'] ? 1 : 0 ?>, '<?= addslashes($p['shop_name']) ?>')"
                                                                title="Quick Restock">
                                                            <i class="fas fa-plus-circle"></i>
                                                        </button>
                                                        <?php if (!$p['is_warehouse']): ?>
                                                        <button type="button" class="btn btn-outline-success btn-sm" 
                                                                onclick="transferFromWarehouse(<?= $p['id'] ?>, '<?= addslashes($p['product_name']) ?>', <?= $units_needed ?>, <?= $p['shop_id'] ?? 0 ?>)"
                                                                title="Transfer from Warehouse">
                                                            <i class="fas fa-truck"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div class="text-muted">
                                        Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_items) ?> of <?= $total_items ?> entries
                                    </div>
                                    <nav>
                                        <ul class="pagination justify-content-center mb-0">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">
                                                    <i class="fas fa-angle-double-left"></i>
                                                </a>
                                            </li>
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">
                                                    <i class="fas fa-chevron-left"></i>
                                                </a>
                                            </li>
                                            
                                            <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            
                                            for ($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                            <?php endfor; ?>
                                            
                                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">
                                                    <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </li>
                                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                                                    <i class="fas fa-angle-double-right"></i>
                                                </a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include('includes/footer.php') ?>
    </div>
</div>

<!-- Add Font Awesome CDN -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Quick Restock Modal -->
<div class="modal fade" id="quickRestockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="quickRestockForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>
                    <span id="restockProductName">Quick Restock</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="product_id" id="restockProductId">
                <input type="hidden" name="location_type" id="locationType">
                <input type="hidden" name="location_name" id="locationName">
                
                <div class="mb-3">
                    <label class="form-label">Location</label>
                    <input type="text" id="restockLocation" class="form-control" readonly>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Units Needed</label>
                    <input type="number" id="unitsNeeded" class="form-control" readonly>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Restock Quantity</label>
                    <input type="number" name="quantity" id="restockQuantity" class="form-control" min="1" required>
                    <small class="text-muted">Enter the quantity you want to purchase/restock</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Unit Cost</label>
                    <input type="number" name="unit_cost" id="unitCost" class="form-control" step="0.01" min="0" required>
                    <small class="text-muted">Cost per unit for this restock</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Restock Method</label>
                    <select name="restock_method" id="restockMethod" class="form-select" required>
                        <option value="">Select Method</option>
                        <option value="purchase">New Purchase</option>
                        <option value="transfer">Transfer from Another Location</option>
                        <?php if ($is_admin): ?>
                        <option value="adjustment">Direct Stock Adjustment</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="mb-3" id="supplierSection" style="display: none;">
                    <label class="form-label">Select Supplier</label>
                    <select name="supplier_id" class="form-select">
                        <option value="">Select Supplier</option>
                        <?php 
                        $suppliers = $pdo->query("SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name")->fetchAll();
                        foreach ($suppliers as $supp): ?>
                        <option value="<?= $supp['id'] ?>"><?= htmlspecialchars($supp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitRestock()">
                    <i class="fas fa-check me-2"></i> Process Restock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer from Warehouse Modal -->
<div class="modal fade" id="transferFromWarehouseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="warehouseTransferForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-truck me-2"></i>
                    <span id="transferProductName">Transfer from Warehouse</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="product_id" id="transferProductId">
                <input type="hidden" name="to_shop_id" id="toShopId">
                
                <div class="mb-3">
                    <label class="form-label">Product</label>
                    <input type="text" id="transferProduct" class="form-control" readonly>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Units Needed</label>
                    <input type="number" id="transferUnitsNeeded" class="form-control" readonly>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Select Warehouse</label>
                    <select name="from_warehouse_id" id="fromWarehouse" class="form-select" required>
                        <option value="">Select Warehouse</option>
                        <?php 
                        $warehouses = $pdo->query("SELECT id, shop_name FROM shops WHERE is_warehouse = 1 AND is_active = 1 ORDER BY shop_name")->fetchAll();
                        foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['shop_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Transfer Quantity</label>
                    <input type="number" name="transfer_quantity" id="transferQuantity" class="form-control" min="1" required>
                    <small class="text-muted">Quantity to transfer from warehouse</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Transfer Reason</label>
                    <input type="text" name="transfer_reason" class="form-control" value="Low stock replenishment" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitWarehouseTransfer()">
                    <i class="fas fa-truck-loading me-2"></i> Create Transfer
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
function exportLowStock() {
    // Get current search parameters
    const params = new URLSearchParams(window.location.search);
    
    // Show loading indicator
    const exportBtn = document.querySelector('.btn-outline-secondary');
    const originalText = exportBtn.innerHTML;
    exportBtn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
    exportBtn.disabled = true;
    
    // Create a form to submit the export request
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'export_low_stock.php';
    
    // Add all search parameters as hidden inputs
    params.forEach((value, key) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    });
    
    // Add export type
    const exportType = document.createElement('input');
    exportType.type = 'hidden';
    exportType.name = 'export_type';
    exportType.value = 'csv';
    form.appendChild(exportType);
    
    // Submit the form
    document.body.appendChild(form);
    form.submit();
    
    // Reset button after a delay
    setTimeout(() => {
        exportBtn.innerHTML = originalText;
        exportBtn.disabled = false;
    }, 3000);
}

function sendLowStockAlert() {
    if (!confirm('Send low stock alert to all shop managers?')) return;
    
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Sending...';
    btn.disabled = true;
    
    fetch('send_low_stock_alert.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Low stock alerts sent successfully!');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    })
    .finally(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

function quickRestock(productId, productName, unitsNeeded, unitCost, isWarehouse, locationName) {
    document.getElementById('restockProductId').value = productId;
    document.getElementById('restockProductName').textContent = 'Restock: ' + productName;
    document.getElementById('locationType').value = isWarehouse ? 'warehouse' : 'shop';
    document.getElementById('locationName').value = locationName;
    document.getElementById('restockLocation').value = locationName + (isWarehouse ? ' (Warehouse)' : ' (Shop)');
    document.getElementById('unitsNeeded').value = unitsNeeded;
    document.getElementById('restockQuantity').value = unitsNeeded;
    document.getElementById('unitCost').value = unitCost;
    document.getElementById('restockMethod').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('quickRestockModal'));
    modal.show();
}

function transferFromWarehouse(productId, productName, unitsNeeded, toShopId) {
    document.getElementById('transferProductId').value = productId;
    document.getElementById('transferProductName').textContent = 'Transfer: ' + productName;
    document.getElementById('toShopId').value = toShopId;
    document.getElementById('transferProduct').value = productName;
    document.getElementById('transferUnitsNeeded').value = unitsNeeded;
    document.getElementById('transferQuantity').value = Math.min(unitsNeeded, 10); // Default to min(units needed, 10)
    
    const modal = new bootstrap.Modal(document.getElementById('transferFromWarehouseModal'));
    modal.show();
}

// Show/hide supplier section based on restock method
document.getElementById('restockMethod').addEventListener('change', function() {
    const method = this.value;
    const supplierSection = document.getElementById('supplierSection');
    
    if (method === 'purchase') {
        supplierSection.style.display = 'block';
    } else {
        supplierSection.style.display = 'none';
    }
});

function submitRestock() {
    const form = document.getElementById('quickRestockForm');
    const formData = new FormData(form);
    const method = document.getElementById('restockMethod').value;
    
    if (!method) {
        alert('Please select a restock method');
        return;
    }
    
    let url = '';
    if (method === 'purchase') {
        url = 'purchase_quick_add.php';
    } else if (method === 'transfer') {
        url = 'stock_transfer_quick.php';
    } else if (method === 'adjustment') {
        url = 'stock_adjust_quick.php';
    }
    
    fetch(url, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    });
}

function submitWarehouseTransfer() {
    const form = document.getElementById('warehouseTransferForm');
    const formData = new FormData(form);
    
    fetch('stock_transfer_quick.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Transfer created successfully! Redirecting to transfer page...');
            window.location.href = 'stock_transfer_view.php?id=' + data.transfer_id;
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    });
}

// Auto-close alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<style>
.mini-stat .card-body {
    padding: 1.25rem;
}
.avatar-sm {
    width: 40px;
    height: 40px;
}
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
}
.badge.rounded-pill {
    padding: 0.5rem 0.75rem;
}
.btn-group .btn {
    padding: 0.375rem 0.75rem;
    border: 1px solid #dee2e6;
    font-size: 14px;
}
.btn-group .btn:not(:last-child) {
    border-right: none;
}
.btn-group .btn:hover {
    background-color: #f8f9fa;
    transform: translateY(-1px);
}
.font-size-30 {
    font-size: 30px;
    opacity: 0.8;
}
/* Severity colors */
.table-danger-subtle {
    background-color: rgba(220, 53, 69, 0.05) !important;
}
.table-warning-subtle {
    background-color: rgba(255, 193, 7, 0.05) !important;
}
/* Progress bar */
.progress {
    border-radius: 10px;
}
.progress-bar {
    border-radius: 10px;
}
/* Location badges */
.bg-opacity-10 {
    opacity: 0.1;
}
/* Hover effects */
.card:hover {
    transform: translateY(-2px);
    transition: transform 0.2s;
}
/* Responsive */
@media (max-width: 768px) {
    .btn-group {
        flex-wrap: wrap;
        gap: 2px;
    }
    .btn-group .btn {
        margin-bottom: 2px;
        border: 1px solid #dee2e6;
    }
}
</style>
</body>
</html>