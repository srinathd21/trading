<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$business_id = $_SESSION['business_id'] ?? 1;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$is_admin = ($user_role === 'admin');
$is_stock_manager = in_array($user_role, ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager']);

// Force collation
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// === ADMIN SHOP SWITCHING ===
if ($is_admin && isset($_GET['switch_shop'])) {
    $new_shop_id = (int)$_GET['switch_shop'];
    
    // Verify shop exists
    $stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE id = ? AND business_id = ?");
    $stmt->execute([$new_shop_id, $business_id]);
    $shop = $stmt->fetch();
    
    if ($shop) {
        $_SESSION['current_shop_id'] = $new_shop_id;
        $_SESSION['current_shop_name'] = $shop['shop_name'];
        $_SESSION['success'] = "Switched to shop: " . $shop['shop_name'];
        
        // Remove shop filter to show all
        if (isset($_GET['shop_id'])) {
            unset($_GET['shop_id']);
        }
        
        header('Location: shop_stocks.php');
        exit();
    }
}

// Get all shops for admin dropdown
$shops = [];
if ($is_admin) {
    $shops = $pdo->query("SELECT id, shop_name FROM shops WHERE business_id = $business_id AND is_active = 1 ORDER BY shop_name")->fetchAll();
} else {
    // Non-admin users see only their current shop
    $stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE id = ? AND business_id = ? AND is_active = 1");
    $stmt->execute([$current_shop_id, $business_id]);
    $shop = $stmt->fetch();
    if ($shop) {
        $shops = [$shop];
    }
}

// === FILTERS & SEARCH ===
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$stock_filter = $_GET['stock'] ?? 'all';
$selected_shop_id = $_GET['shop_id'] ?? ($is_admin ? 'all' : $current_shop_id);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE conditions
$where = "WHERE p.business_id = $business_id AND p.is_active = 1";
$params = [];

// Shop condition
if ($selected_shop_id !== 'all') {
    $shop_condition = "AND ps.shop_id = " . (int)$selected_shop_id;
} else {
    $shop_condition = "";
}

if ($search !== '') {
    $where .= " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ? OR p.hsn_code LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($category !== '') {
    $where .= " AND p.category_id = ?";
    $params[] = $category;
}

// Fetch categories for filter
$categories = $pdo->query("SELECT id, category_name FROM categories WHERE business_id = $business_id ORDER BY category_name")->fetchAll();

// For single shop view - REPLACE THIS SECTION (around line 60-70)
if ($selected_shop_id !== 'all') {
    $stock_summary_sql = "
        SELECT 
            COUNT(DISTINCT p.id) as total,
            SUM(CASE WHEN COALESCE(ps.quantity, 0) = 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(CASE WHEN COALESCE(ps.quantity, 0) > 0 AND COALESCE(ps.quantity, 0) < p.min_stock_level THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN COALESCE(ps.quantity, 0) >= p.min_stock_level THEN 1 ELSE 0 END) as in_stock,
            COALESCE(SUM(ps.quantity * p.stock_price), 0) as stock_value  /* Changed from retail_price to stock_price */
        FROM products p
        LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ?
        $where
    ";
} else {
    // For all shops view - REPLACE THIS SECTION
    $stock_summary_sql = "
        SELECT 
            COUNT(DISTINCT p.id) as total,
            SUM(CASE WHEN ps_total.total_qty = 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(CASE WHEN ps_total.total_qty > 0 AND ps_total.total_qty < p.min_stock_level THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN ps_total.total_qty >= p.min_stock_level THEN 1 ELSE 0 END) as in_stock,
            COALESCE(SUM(ps_total.total_qty * p.stock_price), 0) as stock_value  /* Changed to use stock_price */
        FROM products p
        LEFT JOIN (
            SELECT product_id, SUM(quantity) as total_qty
            FROM product_stocks 
            GROUP BY product_id
        ) ps_total ON p.id = ps_total.product_id
        $where
    ";
}

$summary_stmt = $pdo->prepare($stock_summary_sql);
$summary_stmt->execute($summary_params);
$stock_summary = $summary_stmt->fetch();

// Main products query with shop-specific stock
if ($selected_shop_id !== 'all') {
    $sql = "
        SELECT 
            p.id, 
            p.product_name, 
            p.product_code, 
            p.barcode, 
            p.retail_price, 
            p.wholesale_price,
            p.stock_price,
            p.min_stock_level, 
            p.hsn_code,
            p.description,
            p.is_active,
            c.category_name,
            COALESCE(CONCAT(gr.cgst_rate + gr.sgst_rate + gr.igst_rate, '%'), '0%') AS tax_rate,
            COALESCE(ps.quantity, 0) AS current_stock,
            s.shop_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN gst_rates gr ON p.gst_id = gr.id
        LEFT JOIN product_stocks ps ON ps.product_id = p.id AND ps.shop_id = ?
        LEFT JOIN shops s ON ps.shop_id = s.id
        $where
        ORDER BY p.product_name
        LIMIT $limit OFFSET $offset
    ";
    $main_params = array_merge([(int)$selected_shop_id], $params);
} else {
    $sql = "
        SELECT 
            p.id, 
            p.product_name, 
            p.product_code, 
            p.barcode, 
            p.retail_price, 
            p.wholesale_price,
            p.stock_price,
            p.min_stock_level, 
            p.hsn_code,
            p.description,
            p.is_active,
            c.category_name,
            COALESCE(CONCAT(gr.cgst_rate + gr.sgst_rate + gr.igst_rate, '%'), '0%') AS tax_rate,
            COALESCE(ps_total.total_qty, 0) AS current_stock,
            'All Shops' as shop_name,
            GROUP_CONCAT(DISTINCT s.shop_name) as shop_names,
            GROUP_CONCAT(DISTINCT CONCAT(s.shop_name, ':', ps.quantity)) as shop_stocks
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN gst_rates gr ON p.gst_id = gr.id
        LEFT JOIN product_stocks ps ON ps.product_id = p.id
        LEFT JOIN shops s ON ps.shop_id = s.id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) as total_qty
            FROM product_stocks 
            GROUP BY product_id
        ) ps_total ON p.id = ps_total.product_id
        $where
        GROUP BY p.id
        ORDER BY p.product_name
        LIMIT $limit OFFSET $offset
    ";
    $main_params = $params;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($main_params);
$products = $stmt->fetchAll();

// Get total count for pagination
if ($selected_shop_id !== 'all') {
    $count_sql = "
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ?
        $where
    ";
    $count_params = array_merge([(int)$selected_shop_id], $params);
} else {
    $count_sql = "
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        $where
    ";
    $count_params = $params;
}

$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($count_params);
$total_products = $count_stmt->fetchColumn();
$total_pages = ceil($total_products / $limit);

// Apply stock filter after fetching
if ($stock_filter !== 'all') {
    $filtered_products = [];
    foreach ($products as $p) {
        $current_stock = $p['current_stock'];
        $min_stock = $p['min_stock_level'] ?: 10;
        
        if ($stock_filter === 'in' && $current_stock >= $min_stock) {
            $filtered_products[] = $p;
        } elseif ($stock_filter === 'low' && $current_stock > 0 && $current_stock < $min_stock) {
            $filtered_products[] = $p;
        } elseif ($stock_filter === 'out' && $current_stock == 0) {
            $filtered_products[] = $p;
        } elseif ($stock_filter === 'critical' && $current_stock < ceil($min_stock * 0.25)) {
            $filtered_products[] = $p;
        }
    }
    $products = $filtered_products;
}
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Shop Stocks Management";
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
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-package me-2"></i> Shop Stocks Management
                                <small class="text-muted ms-2">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <?php if ($selected_shop_id !== 'all'): ?>
                                <button class="btn btn-outline-secondary" onclick="exportStock()">
                                    <i class="bx bx-download me-1"></i> Export Excel
                                </button>
                                <?php endif; ?>
                                <?php if ($is_stock_manager && $selected_shop_id !== 'all'): ?>
                                <a href="stock_adjustment.php?shop_id=<?= $selected_shop_id ?>" class="btn btn-warning">
                                    <i class="bx bx-adjust me-1"></i> Adjust Stock
                                </a>
                                <a href="stock_transfer_add.php?from_shop=<?= $selected_shop_id ?>" class="btn btn-info">
                                    <i class="bx bx-transfer me-1"></i> Transfer Stock
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <!-- Admin Shop Switch Panel -->
                <?php if ($is_admin && !empty($shops)): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="bx bx-store me-2"></i> Shop Selection
                        </h5>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="?shop_id=all<?= $search?'&search='.urlencode($search):'' ?><?= $category?'&category='.$category:'' ?>" 
                               class="btn btn-sm btn-outline-info <?= $selected_shop_id === 'all' ? 'active' : '' ?>">
                                <i class="bx bx-grid-alt me-1"></i> All Shops
                            </a>
                            <?php foreach ($shops as $shop): ?>
                            <div class="btn-group" role="group">
                                <a href="?shop_id=<?= $shop['id'] ?><?= $search?'&search='.urlencode($search):'' ?><?= $category?'&category='.$category:'' ?>" 
                                   class="btn btn-sm btn-outline-primary <?= $selected_shop_id == $shop['id'] ? 'active' : '' ?>">
                                    <i class="bx bx-store me-1"></i> <?= htmlspecialchars($shop['shop_name']) ?>
                                    <?php if ($current_shop_id == $shop['id']): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success ms-1">Current</span>
                                    <?php endif; ?>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-secondary" 
                                        onclick="switchShop(<?= $shop['id'] ?>)" 
                                        title="Switch to this shop">
                                    <i class="bx bx-log-in"></i>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Products
                        </h5>
                        <form method="GET" id="filterForm">
                            <?php if ($is_admin && $selected_shop_id !== 'all'): ?>
                            <input type="hidden" name="shop_id" value="<?= $selected_shop_id ?>">
                            <?php endif; ?>
                            <div class="row g-3">
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Search Products</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Name, Code, Barcode, HSN..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
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
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Stock Status</label>
                                    <select name="stock" class="form-select">
                                        <option value="all" <?= $stock_filter==='all'?'selected':'' ?>>All Products</option>
                                        <option value="in" <?= $stock_filter==='in'?'selected':'' ?>>In Stock</option>
                                        <option value="low" <?= $stock_filter==='low'?'selected':'' ?>>Low Stock</option>
                                        <option value="critical" <?= $stock_filter==='critical'?'selected':'' ?>>Critical</option>
                                        <option value="out" <?= $stock_filter==='out'?'selected':'' ?>>Out of Stock</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($search || $category || $stock_filter != 'all'): ?>
                                        <a href="shop_stocks.php<?= $is_admin && $selected_shop_id !== 'all' ? '?shop_id='.$selected_shop_id : '' ?>" 
                                           class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Products</h6>
                                        <h3 class="mb-0 text-primary"><?= $stock_summary['total'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-package text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">In Stock</h6>
                                        <h3 class="mb-0 text-success"><?= $stock_summary['in_stock'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-check-circle text-success"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Stock Value</h6>
                                        <h3 class="mb-0 text-info">₹<?= number_format($stock_summary['stock_value'] ?? 0, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Low Stock</h6>
                                        <h3 class="mb-0 text-warning"><?= $stock_summary['low_stock'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-error text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="stocksTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product Details</th>
                                        <th class="text-center">Category</th>
                                        <th class="text-end">Pricing</th>
                                        <th class="text-center">Stock Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($products)): ?>
                                    
                                    <?php else: 
                                    $counter = $offset + 1;
                                    foreach ($products as $p): 
                                        $current_stock = $p['current_stock'];
                                        $min_stock = $p['min_stock_level'] ?: 10;
                                        $stock_percentage = $min_stock > 0 ? ($current_stock / $min_stock) * 100 : 0;
                                        
                                        // Determine stock status
                                        if ($current_stock == 0) {
                                            $stock_class = 'danger';
                                            $stock_text = 'Out of Stock';
                                            $icon = 'bx-x-circle';
                                        } elseif ($stock_percentage < 25) {
                                            $stock_class = 'danger';
                                            $stock_text = 'Critical';
                                            $icon = 'bx-error';
                                        } elseif ($stock_percentage < 50) {
                                            $stock_class = 'warning';
                                            $stock_text = 'Low';
                                            $icon = 'bx-error-circle';
                                        } elseif ($stock_percentage < 100) {
                                            $stock_class = 'warning';
                                            $stock_text = 'Below Min';
                                            $icon = 'bx-minus-circle';
                                        } else {
                                            $stock_class = 'success';
                                            $stock_text = 'In Stock';
                                            $icon = 'bx-check-circle';
                                        }
                                    ?>
                                    <tr class="product-row" data-id="<?= $p['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx bx-package fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($p['product_name']) ?></strong>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-hash me-1"></i><?= htmlspecialchars($p['product_code']) ?>
                                                    </small>
                                                    <?php if ($p['barcode']): ?>
                                                    <small class="text-muted">
                                                        <i class="bx bx-barcode me-1"></i><?= htmlspecialchars($p['barcode']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                    <?php if ($selected_shop_id === 'all' && isset($p['shop_names'])): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-store me-1"></i>
                                                        <?php 
                                                        $shop_list = explode(',', $p['shop_names']);
                                                        echo count($shop_list) > 2 
                                                            ? (count($shop_list) . ' shops') 
                                                            : implode(', ', array_slice($shop_list, 0, 2));
                                                        ?>
                                                        <?php if (count($shop_list) > 2): ?>
                                                        <span class="badge bg-info bg-opacity-10 text-info ms-1">+<?= count($shop_list) - 2 ?> more</span>
                                                        <?php endif; ?>
                                                    </small>
                                                    <?php else: ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-store me-1"></i>
                                                        <?= htmlspecialchars($p['shop_name']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                <i class="bx bx-category me-1"></i>
                                                <?= htmlspecialchars($p['category_name'] ?? 'Uncategorized') ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="mb-1">
                                                <strong class="text-primary">₹<?= number_format($p['retail_price'], 2) ?></strong>
                                                <small class="text-muted d-block">Retail</small>
                                            </div>
                                            <?php if ($p['wholesale_price'] > 0): ?>
                                            <div class="mb-1">
                                                <span class="text-success">₹<?= number_format($p['wholesale_price'], 2) ?></span>
                                                <small class="text-muted d-block">Wholesale</small>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($p['stock_price'] > 0): ?>
                                            <div>
                                                <span class="text-muted">₹<?= number_format($p['stock_price'], 2) ?></span>
                                                <small class="text-muted d-block">Cost</small>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex flex-column align-items-center">
                                                <span class="badge bg-<?= $stock_class ?> bg-opacity-10 text-<?= $stock_class ?> px-3 py-2 mb-2">
                                                    <i class="bx <?= $icon ?> me-1 fs-6"></i>
                                                    <?= $stock_text ?>
                                                </span>
                                                <div class="d-flex align-items-center gap-2">
                                                    <strong class="text-<?= $stock_class ?> fs-5"><?= $current_stock ?></strong>
                                                    <small class="text-muted">/</small>
                                                    <small class="text-muted">Min: <?= $min_stock ?></small>
                                                </div>
                                                <div class="progress w-100 mt-2" style="height: 5px; max-width: 120px;">
                                                    <div class="progress-bar bg-<?= $stock_class ?>" 
                                                         style="width: <?= min($stock_percentage, 100) ?>%">
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="product_view.php?id=<?= $p['id'] ?>"
                                                   class="btn btn-outline-info"
                                                   data-bs-toggle="tooltip"
                                                   title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </a>
                                                <?php if ($is_stock_manager && $selected_shop_id !== 'all'): ?>
                                                <button type="button" class="btn btn-outline-warning" 
                                                        onclick="adjustStock(<?= $p['id'] ?>, '<?= addslashes($p['product_name']) ?>', <?= $current_stock ?>, <?= $selected_shop_id ?>)"
                                                        data-bs-toggle="tooltip"
                                                        title="Adjust Stock">
                                                    <i class="bx bx-adjust"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if ($selected_shop_id !== 'all'): ?>
                                                <a href="barcode_print.php?id=<?= $p['id'] ?>&shop_id=<?= $selected_shop_id ?>" target="_blank"
                                                   class="btn btn-outline-dark"
                                                   data-bs-toggle="tooltip"
                                                   title="Print Barcode">
                                                    <i class="bx bx-printer"></i>
                                                </a>
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
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <div class="text-muted">
                                Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_products) ?> of <?= $total_products ?> products
                            </div>
                            <nav>
                                <ul class="pagination justify-content-center mb-0">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">
                                            <i class="bx bx-chevrons-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">
                                            <i class="bx bx-chevron-left"></i>
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
                                            <i class="bx bx-chevron-right"></i>
                                        </a>
                                    </li>
                                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                                            <i class="bx bx-chevrons-right"></i>
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
        <?php include('includes/footer.php') ?>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div class="modal fade" id="adjustStockModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="adjustStockForm" method="POST" action="stock_adjust_action.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-adjust me-2"></i>
                    <span id="adjustProductName">Adjust Stock</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="product_id" id="adjustProductId">
                <input type="hidden" name="shop_id" id="adjustShopId">
                <input type="hidden" name="action" value="adjust_stock">
                
                <div class="mb-3">
                    <label class="form-label">Current Stock</label>
                    <input type="number" id="currentStock" class="form-control" readonly>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Adjustment Type</label>
                    <select name="adjustment_type" id="adjustmentType" class="form-select" required>
                        <option value="">Select Type</option>
                        <option value="add">Add Stock</option>
                        <option value="remove">Remove Stock</option>
                        <option value="set">Set Exact Quantity</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity" id="adjustQuantity" class="form-control" min="1" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="3" placeholder="Enter reason for adjustment..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bx bx-save me-2"></i> Save Adjustment
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#stocksTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search in table:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ products",
            infoFiltered: "(filtered from <?= $total_products ?> total products)",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Real-time search debounce
    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => $('#filterForm').submit(), 500);
    });

    // Auto-submit on filter change
    $('select[name="category"], select[name="stock"]').on('change', function() {
        $('#filterForm').submit();
    });

    // Row hover effect
    $('.product-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // Export function
    window.exportStock = function() {
        const btn = event.target.closest('button');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
        btn.disabled = true;
        
        // Build export URL with current search parameters
        const params = new URLSearchParams(window.location.search);
        const exportUrl = 'export_shop_stocks.php' + (params.toString() ? '?' + params.toString() : '');
        
        window.location = exportUrl;
        
        // Reset button after 3 seconds
        setTimeout(() => {
            btn.innerHTML = original;
            btn.disabled = false;
        }, 3000);
    };

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

// Switch shop function
function switchShop(shopId) {
    if (confirm('Switch to this shop? This will change your current working shop.')) {
        window.location.href = '?switch_shop=' + shopId;
    }
}

// Adjust stock function
function adjustStock(productId, productName, currentStock, shopId) {
    document.getElementById('adjustProductId').value = productId;
    document.getElementById('adjustProductName').textContent = 'Adjust Stock: ' + productName;
    document.getElementById('adjustShopId').value = shopId;
    document.getElementById('currentStock').value = currentStock;
    document.getElementById('adjustmentType').value = '';
    document.getElementById('adjustQuantity').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('adjustStockModal'));
    modal.show();
}

// Handle adjustment type change
document.getElementById('adjustmentType').addEventListener('change', function() {
    const type = this.value;
    const quantityInput = document.getElementById('adjustQuantity');
    
    if (type === 'set') {
        quantityInput.placeholder = 'Enter new quantity...';
    } else if (type === 'add') {
        quantityInput.placeholder = 'Enter quantity to add...';
    } else if (type === 'remove') {
        quantityInput.placeholder = 'Enter quantity to remove...';
    }
});

// Handle adjustment form submission
document.getElementById('adjustStockForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const type = document.getElementById('adjustmentType').value;
    const quantity = document.getElementById('adjustQuantity').value;
    const currentStock = parseInt(document.getElementById('currentStock').value);
    
    // Validation
    if (type === 'remove' && parseInt(quantity) > currentStock) {
        alert('Cannot remove more stock than available!');
        return;
    }
    
    if (type === 'set' && parseInt(quantity) < 0) {
        alert('Stock quantity cannot be negative!');
        return;
    }
    
    // Submit form
    fetch('stock_adjust_action.php', {
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
});
</script>

<style>
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}
.empty-state i {
    font-size: 4rem;
    opacity: 0.5;
}
.avatar-sm {
    width: 48px;
    height: 48px;
}
.badge.bg-opacity-10 {
    opacity: 0.9;
}
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
    border-top: none;
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
.btn-outline-primary.active, .btn-outline-info.active {
    background-color: var(--bs-primary);
    color: white;
}
.btn-outline-info.active {
    background-color: var(--bs-info);
    color: white;
}
.progress {
    border-radius: 10px;
}
.progress-bar {
    border-radius: 10px;
}
.card-hover {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.card-hover:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important;
}
.border-start {
    border-left-width: 4px !important;
}
@media (max-width: 768px) {
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .btn-group .btn {
        width: 100%;
        border: 1px solid #dee2e6;
    }
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
}
</style>
</body>
</html>