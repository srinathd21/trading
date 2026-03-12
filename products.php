<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if (!isset($_SESSION['current_business_id'])) {
    header('Location: select_shop.php');
    exit();
}
$current_business_id = (int) $_SESSION['current_business_id'];
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_stock_manager = in_array($user_role, ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager']);

// Check for session messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';

// Clear messages after retrieving
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// === FILTERS & SEARCH ===
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$stock_filter = $_GET['stock'] ?? 'all';
$hsn_filter = $_GET['hsn'] ?? '';

// Build WHERE conditions
$where = "WHERE p.is_active = 1 AND p.business_id = ?";
$params = [$current_business_id];

// Shop condition for stock
$shop_condition = $is_admin ? "" : "AND ps.shop_id = " . (int)$current_shop_id;

// Fetch categories for filter
$cat_stmt = $pdo->prepare("SELECT id, category_name FROM categories WHERE business_id = ? AND status = 'active' AND parent_id IS NULL ORDER BY category_name");
$cat_stmt->execute([$current_business_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch HSN codes for filter
$hsn_stmt = $pdo->prepare("SELECT DISTINCT g.hsn_code FROM gst_rates g INNER JOIN products p ON g.id = p.gst_id WHERE g.business_id = ? AND g.status = 'active' ORDER BY g.hsn_code");
$hsn_stmt->execute([$current_business_id]);
$hsn_codes = $hsn_stmt->fetchAll(PDO::FETCH_COLUMN);

// Stock summary (unaffected by stock filter)
$stock_summary_sql = "
    SELECT
        COUNT(DISTINCT p.id) as total,
        SUM(CASE WHEN COALESCE(ps.quantity, 0) = 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN COALESCE(ps.quantity, 0) > 0 AND COALESCE(ps.quantity, 0) < p.min_stock_level THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN COALESCE(ps.quantity, 0) >= p.min_stock_level THEN 1 ELSE 0 END) as in_stock
    FROM products p
    LEFT JOIN product_stocks ps ON ps.product_id = p.id $shop_condition
    $where
";
$summary_stmt = $pdo->prepare($stock_summary_sql);
$summary_stmt->execute($params);
$stock_summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Now build WHERE conditions with proper table references
if ($search !== '') {
    // Note: We need to reference tables that will be joined in the main query
    $where .= " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)";
    $like = "%$search%";
    $params[] = $like; 
    $params[] = $like; 
    $params[] = $like;
}

if ($category !== '') {
    $where .= " AND p.category_id = ?";
    $params[] = $category;
}

// Main products query with joins
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
        p.description,
        p.is_active,
        p.image_path,
        p.image_thumbnail_path,
        p.image_alt_text,
        p.referral_enabled,
        p.referral_type,
        p.referral_value,
        p.unit_of_measure,
        p.secondary_unit,
        p.sec_unit_conversion,
        p.sec_unit_price_type,
        p.sec_unit_extra_charge,
        p.mrp,
        p.discount_type,
        p.discount_value,
        p.retail_price_type,
        p.retail_price_value,
        p.wholesale_price_type,
        p.wholesale_price_value,
        p.gst_type,
        p.gst_amount,
        c.category_name,
        s.subcategory_name,
        g.hsn_code,
        CONCAT(g.cgst_rate, '%') as cgst_rate,
        CONCAT(g.sgst_rate, '%') as sgst_rate,
        CONCAT(g.igst_rate, '%') as igst_rate,
        CONCAT(g.cgst_rate + g.sgst_rate + g.igst_rate, '%') AS total_tax_rate,
        COALESCE(SUM(ps.quantity), 0) AS total_stock,
        COALESCE(ps.total_secondary_units, 0) AS total_secondary_units
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    LEFT JOIN product_stocks ps ON ps.product_id = p.id $shop_condition
    $where
    GROUP BY p.id
    ORDER BY p.product_name
";

// Apply HSN filter after building the main SQL (will be filtered in PHP)
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Apply HSN filter in PHP
if ($hsn_filter !== '') {
    $products = array_filter($products, function($product) use ($hsn_filter) {
        return ($product['hsn_code'] ?? '') == $hsn_filter;
    });
    // Re-index array
    $products = array_values($products);
}

// Apply stock filter in PHP
if ($stock_filter !== 'all') {
    $filtered_products = [];
    foreach ($products as $p) {
        $current_stock = $p['total_stock'];
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

// Calculate stock value summary
// Note: We need a separate query for this with the same filters
$summary_params = [$current_business_id];
$summary_where = "WHERE p.is_active = 1 AND p.business_id = ?";

if ($search !== '') {
    $summary_where .= " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)";
    $like = "%$search%";
    $summary_params[] = $like; 
    $summary_params[] = $like; 
    $summary_params[] = $like;
}

if ($category !== '') {
    $summary_where .= " AND p.category_id = ?";
    $summary_params[] = $category;
}

$summary_sql = "
    SELECT
        COALESCE(SUM(p.stock_price * COALESCE(ps.quantity, 0)), 0) as stock_value
    FROM products p
    LEFT JOIN product_stocks ps ON p.id = ps.product_id $shop_condition
    $summary_where
";
$summary_stmt = $pdo->prepare($summary_sql);
$summary_stmt->execute($summary_params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Product Inventory"; ?>
<?php include('includes/head.php'); ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
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
                                <i class="bx bx-package me-2"></i> Product Inventory
                                <small class="text-muted ms-2">
                                    <i class="bx bx-store me-1"></i>
                                    <?= htmlspecialchars($_SESSION['current_shop_name'] ?? 'All Shops') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <?php if ($is_stock_manager): ?>
                                <a href="product_add.php" class="btn btn-primary">
                                    <i class="bx bx-plus-circle me-1"></i> Add Product
                                </a>
                                <?php endif; ?>
                                <a href="product_export.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-download me-1"></i> Export
                                </a>
                               
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <div class="d-flex align-items-center">
                                <i class="bx bx-check-circle fs-4 me-2"></i>
                                <div>
                                    <strong>Success!</strong> <?= htmlspecialchars($success_message) ?>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <div class="d-flex align-items-center">
                                <i class="bx bx-error-circle fs-4 me-2"></i>
                                <div>
                                    <strong>Error!</strong> <?= htmlspecialchars($error_message) ?>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick Stats Cards -->
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
                                        <h6 class="text-muted mb-1">Stock Value</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($summary['stock_value'] ?? 0, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-success"></i>
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
                                            <i class="bx bx-alarm-exclamation text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Out of Stock</h6>
                                        <h3 class="mb-0 text-danger"><?= $stock_summary['out_of_stock'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
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
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Products
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Name / Code / Barcode"
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"
                                            <?= $category == $cat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['category_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">HSN Code</label>
                                    <select name="hsn" class="form-select">
                                        <option value="">All HSN Codes</option>
                                        <?php foreach ($hsn_codes as $hsn): ?>
                                        <option value="<?= htmlspecialchars($hsn) ?>"
                                            <?= $hsn_filter == $hsn ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($hsn) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Stock Status</label>
                                    <select name="stock" class="form-select">
                                        <option value="all" <?= $stock_filter === 'all' ? 'selected' : '' ?>>All Stock</option>
                                        <option value="in" <?= $stock_filter === 'in' ? 'selected' : '' ?>>In Stock</option>
                                        <option value="low" <?= $stock_filter === 'low' ? 'selected' : '' ?>>Low Stock</option>
                                        <option value="critical" <?= $stock_filter === 'critical' ? 'selected' : '' ?>>Critical</option>
                                        <option value="out" <?= $stock_filter === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($search || $category || $hsn_filter || $stock_filter !== 'all'): ?>
                                        <a href="products.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="productsTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product Details</th>
                                        <th>Category</th>
                                        <th class="text-end">Prices</th>
                                        <th>Units & Conversion</th>
                                        <th>GST Details</th>
                                        <th class="text-end">Stock</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($products)): ?>
                                   
                                    <?php else: ?>
                                    <?php foreach ($products as $p):
                                        $current_stock = $p['total_stock'];
                                        $min_stock = $p['min_stock_level'] ?: 10;
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
                                       
                                        $profit_margin = $p['retail_price'] > 0 && $p['stock_price'] > 0 ?
                                            (($p['retail_price'] - $p['stock_price']) / $p['stock_price']) * 100 : 0;
                                       
                                        $tax_type = 'CGST+SGST';
                                        if (!empty($p['igst_rate']) && $p['igst_rate'] != '0%') {
                                            $tax_type = 'IGST';
                                        }
                                       
                                        $has_image = !empty($p['image_thumbnail_path']);
                                        $image_src = $has_image ? htmlspecialchars($p['image_thumbnail_path']) : '';
                                        $full_image_src = $has_image ? htmlspecialchars($p['image_path'] ?? $p['image_thumbnail_path']) : '';
                                        $alt_text = htmlspecialchars($p['image_alt_text'] ?? $p['product_name']);
                                       
                                        // Unit information
                                        $unit_of_measure = htmlspecialchars($p['unit_of_measure'] ?? 'pcs');
                                        $secondary_unit = htmlspecialchars($p['secondary_unit'] ?? '');
                                        $sec_unit_conversion = $p['sec_unit_conversion'] ?? 0;
                                        $sec_unit_price_type = $p['sec_unit_price_type'] ?? 'fixed';
                                        $sec_unit_extra_charge = $p['sec_unit_extra_charge'] ?? 0;
                                        $total_secondary_units = $p['total_secondary_units'] ?? 0;
                                       
                                        // Pricing information
                                        $mrp = $p['mrp'] ?? 0;
                                        $discount_type = $p['discount_type'] ?? 'percentage';
                                        $discount_value = $p['discount_value'] ?? 0;
                                        $retail_price_type = $p['retail_price_type'] ?? 'percentage';
                                        $retail_price_value = $p['retail_price_value'] ?? 0;
                                        $wholesale_price_type = $p['wholesale_price_type'] ?? 'percentage';
                                        $wholesale_price_value = $p['wholesale_price_value'] ?? 0;
                                       
                                        // GST Information
                                        $gst_type = $p['gst_type'] ?? 'inclusive';
                                        $gst_amount = $p['gst_amount'] ?? 0;
                                       
                                        // Calculate discounted price
                                        if ($mrp > 0 && $discount_value > 0) {
                                            if ($discount_type === 'percentage') {
                                                $discounted_price = $mrp - ($mrp * $discount_value / 100);
                                            } else {
                                                $discounted_price = $mrp - $discount_value;
                                            }
                                        } else {
                                            $discounted_price = $p['retail_price'];
                                        }
                                       
                                        // Code and Barcode info
                                        $code_info = '';
                                        if (!empty($p['product_code'])) {
                                            $code_info .= '<span class="badge bg-light text-dark me-2">' . htmlspecialchars($p['product_code']) . '</span>';
                                        }
                                        if (!empty($p['barcode'])) {
                                            $code_info .= '<span class="badge bg-light text-muted"><i class="bx bx-barcode me-1"></i>' . htmlspecialchars($p['barcode']) . '</span>';
                                        }
                                    ?>
                                    <tr class="product-row" data-id="<?= $p['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-start">
                                                <?php if ($has_image): ?>
                                                    <div class="avatar-sm me-3 position-relative">
                                                        <img src="<?= $image_src ?>"
                                                             alt="<?= $alt_text ?>"
                                                             class="rounded img-thumbnail product-image"
                                                             data-full-image="<?= $full_image_src ?>"
                                                             data-product-name="<?= htmlspecialchars($p['product_name']) ?>"
                                                             style="width: 48px; height: 48px; object-fit: cover; cursor: pointer;">
                                                    </div>
                                                <?php else: ?>
                                                    <div class="avatar-sm me-3">
                                                        <div class="avatar-title bg-light text-primary rounded">
                                                            <i class="bx bx-package fs-4"></i>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="flex-grow-1">
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($p['product_name']) ?></strong>
                                                   
                                                    <!-- Code and Barcode section -->
                                                    <?php if (!empty($code_info)): ?>
                                                    <div class="mb-1">
                                                        <?= $code_info ?>
                                                    </div>
                                                    <?php endif; ?>
                                                   
                                                    <?php if (!empty($p['description'])): ?>
                                                    <small class="text-muted d-block"><?= htmlspecialchars(substr($p['description'], 0, 80)) ?><?= strlen($p['description']) > 80 ? '...' : '' ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($p['category_name'] ?? 'Uncategorized') ?>
                                            <?php if (!empty($p['subcategory_name'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($p['subcategory_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <!-- MRP and Discount -->
                                            <?php if ($mrp > 0): ?>
                                            <div class="mb-1">
                                                <s class="text-muted small">₹<?= number_format($mrp, 2) ?></s>
                                                <?php if ($discount_value > 0): ?>
                                                <span class="badge bg-danger ms-1">
                                                    <?php if ($discount_type === 'percentage'): ?>
                                                    <?= number_format($discount_value, 1) ?>% off
                                                    <?php else: ?>
                                                    ₹<?= number_format($discount_value, 2) ?> off
                                                    <?php endif; ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                           
                                            <!-- Selling Prices -->
                                            <div>
                                                <strong class="text-success">₹<?= number_format($p['retail_price'], 2) ?></strong>
                                                <small class="text-muted d-block">Retail</small>
                                            </div>
                                           
                                            <?php if (!empty($p['wholesale_price']) && $p['wholesale_price'] != $p['retail_price']): ?>
                                            <div class="mt-1">
                                                <small class="text-info">₹<?= number_format($p['wholesale_price'], 2) ?></small>
                                                <small class="text-muted d-block">Wholesale</small>
                                            </div>
                                            <?php endif; ?>
                                           
                                            <!-- Stock Price -->
                                            <?php if ($p['stock_price'] > 0): ?>
                                            <div class="mt-1">
                                                <small class="text-muted">Cost: ₹<?= number_format($p['stock_price'], 2) ?></small>
                                                <?php if ($profit_margin > 0): ?>
                                                <br><small class="text-success">
                                                    <i class="bx bx-trending-up"></i> <?= number_format($profit_margin, 1) ?>%
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <!-- Primary Unit -->
                                                <div class="mb-1">
                                                    <span class="badge bg-primary bg-opacity-10 text-primary">
                                                        <i class="bx bx-cube me-1"></i><?= $unit_of_measure ?>
                                                    </span>
                                                </div>
                                               
                                                <!-- Secondary Unit and Conversion -->
                                                <?php if (!empty($secondary_unit) && $sec_unit_conversion > 0): ?>
                                                <div>
                                                    <small class="text-muted">
                                                        <i class="bx bx-transfer me-1"></i>
                                                        1 <?= $unit_of_measure ?> = <?= number_format($sec_unit_conversion, 2) ?> <?= $secondary_unit ?>
                                                    </small>
                                                   
                                                    <?php if ($sec_unit_extra_charge > 0): ?>
                                                    <br><small class="text-warning">
                                                        <i class="bx bx-plus-circle me-1"></i>
                                                        Extra: 
                                                        <?php if ($sec_unit_price_type === 'percentage'): ?>
                                                        <?= number_format($sec_unit_extra_charge, 2) ?>%
                                                        <?php else: ?>
                                                        ₹<?= number_format($sec_unit_extra_charge, 2) ?>
                                                        <?php endif; ?>
                                                    </small>
                                                    <?php endif; ?>
                                                   
                                                    <?php if ($total_secondary_units > 0): ?>
                                                    <br><small class="text-info">
                                                        <i class="bx bx-calculator me-1"></i>
                                                        Total: <?= number_format($total_secondary_units, 2) ?> <?= $secondary_unit ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <!-- HSN Code -->
                                                <?php if (!empty($p['hsn_code'])): ?>
                                                <div class="mb-1">
                                                    <span class="badge bg-info bg-opacity-10 text-info">
                                                        <i class="bx bx-hash me-1"></i><?= htmlspecialchars($p['hsn_code']) ?>
                                                    </span>
                                                </div>
                                                <?php else: ?>
                                                <div class="mb-1">
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary">
                                                        No HSN
                                                    </span>
                                                </div>
                                                <?php endif; ?>
                                               
                                                <!-- GST Type Badge -->
                                                <div class="mb-1">
                                                    <span class="badge bg-<?= $gst_type == 'inclusive' ? 'success' : 'primary' ?> bg-opacity-10 text-<?= $gst_type == 'inclusive' ? 'success' : 'primary' ?>">
                                                        <i class="bx bx-receipt me-1"></i>
                                                        GST <?= ucfirst($gst_type) ?>
                                                        <?php if ($gst_amount > 0): ?>
                                                        <span class="ms-1">(₹<?= number_format($gst_amount, 2) ?>)</span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                               
                                                <!-- Tax Rates -->
                                                <?php if (!empty($p['total_tax_rate']) && $p['total_tax_rate'] != '0%'): ?>
                                                <div>
                                                    <small class="text-muted">
                                                        <?php if ($tax_type == 'CGST+SGST'): ?>
                                                        <div class="d-flex gap-1 mb-1">
                                                            <span class="badge bg-primary bg-opacity-10 text-primary">
                                                                C: <?= $p['cgst_rate'] ?>
                                                            </span>
                                                            <span class="badge bg-primary bg-opacity-10 text-primary">
                                                                S: <?= $p['sgst_rate'] ?>
                                                            </span>
                                                        </div>
                                                        <?php else: ?>
                                                        <div class="mb-1">
                                                            <span class="badge bg-warning bg-opacity-10 text-warning">
                                                                I: <?= $p['igst_rate'] ?>
                                                            </span>
                                                        </div>
                                                        <?php endif; ?>
                                                        <small>Total: <?= $p['total_tax_rate'] ?></small>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex flex-column align-items-end">
                                                <!-- Primary Stock -->
                                                <div class="mb-1">
                                                    <span class="badge bg-<?= $stock_class ?> rounded-pill px-3 py-1 fs-6">
                                                        <?= number_format($current_stock, 2) ?> <?= $unit_of_measure ?>
                                                    </span>
                                                </div>
                                               
                                                <!-- Secondary Stock -->
                                                <?php if (!empty($secondary_unit) && $sec_unit_conversion > 0 && $total_secondary_units > 0): ?>
                                                <div class="mb-1">
                                                    <small class="text-info">
                                                        ≈ <?= number_format($total_secondary_units, 2) ?> <?= $secondary_unit ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                               
                                                <!-- Min Stock and Progress -->
                                                <small class="text-muted">Min: <?= $min_stock ?> <?= $unit_of_measure ?></small>
                                                <?php if ($stock_percentage > 0): ?>
                                                <div class="progress mt-1" style="width: 80px; height: 6px;">
                                                    <div class="progress-bar bg-<?= $stock_class ?>"
                                                         role="progressbar"
                                                         style="width: <?= min($stock_percentage, 100) ?>%"></div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $stock_class ?> bg-opacity-10 text-<?= $stock_class ?> px-3 py-1">
                                                <i class="bx bx-circle me-1"></i><?= $stock_text ?>
                                            </span>
                                           
                                            <?php if ($p['referral_enabled'] == 1): ?>
                                            <br>
                                            <span class="badge bg-purple bg-opacity-10 text-purple mt-1">
                                                <i class="bx bx-share-alt me-1"></i>
                                                <?php if ($p['referral_type'] === 'percentage'): ?>
                                                <?= number_format($p['referral_value'], 1) ?>%
                                                <?php else: ?>
                                                ₹<?= number_format($p['referral_value'], 2) ?>
                                                <?php endif; ?>
                                                Commission
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="product_view.php?id=<?= $p['id'] ?>"
                                                   class="btn btn-outline-info"
                                                   data-bs-toggle="tooltip"
                                                   title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </a>
                                                <?php if ($is_stock_manager): ?>
                                                <a href="product_edit.php?id=<?= $p['id'] ?>"
                                                   class="btn btn-outline-warning"
                                                   data-bs-toggle="tooltip"
                                                   title="Edit Product">
                                                    <i class="bx bx-edit"></i>
                                                </a>
                                                <button type="button"
                                                        class="btn btn-outline-primary adjust-stock-btn"
                                                        data-id="<?= $p['id'] ?>"
                                                        data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                                        data-current="<?= $current_stock ?>"
                                                        data-unit="<?= $unit_of_measure ?>"
                                                        data-secondary="<?= $secondary_unit ?>"
                                                        data-conversion="<?= $sec_unit_conversion ?>"
                                                        data-bs-toggle="tooltip"
                                                        title="Adjust Stock">
                                                    <i class="bx bx-transfer"></i>
                                                </button>
                                                <button type="button"
                                                        class="btn btn-outline-danger delete-product-btn"
                                                        data-id="<?= $p['id'] ?>"
                                                        data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                                        data-bs-toggle="tooltip"
                                                        title="Delete Product">
                                                    <i class="bx bx-trash"></i>
                                                </button>
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
            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>
<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title text-danger">
                    <i class="bx bx-trash me-2"></i> Confirm Delete
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    Are you sure you want to <strong>permanently delete</strong> this product?
                </p>
                <div class="mt-3 p-3 bg-light rounded">
                    <strong id="deleteProductName"></strong>
                </div>
                <small class="text-muted d-block mt-3">
                    This action cannot be undone.
                </small>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bx bx-x me-1"></i> Cancel
                </button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                    <i class="bx bx-trash me-1"></i> Delete Product
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Quick Stock Adjustment Modal -->
<div class="modal fade" id="adjustStockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-transfer me-1"></i> Adjust Stock
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="adjustStockForm">
                    <input type="hidden" id="adjustProductId" name="product_id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product</label>
                            <input type="text" class="form-control bg-light" id="adjustProductName" readonly>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Primary Unit</label>
                            <input type="text" class="form-control bg-light" id="adjustPrimaryUnit" readonly>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Current Stock</label>
                            <input type="text" class="form-control bg-light" id="adjustCurrentStock" readonly>
                        </div>
                    </div>
                   
                    <!-- Secondary Unit Information -->
                    <div class="row mb-3" id="secondaryUnitSection" style="display: none;">
                        <div class="col-12">
                            <div class="alert alert-info py-2 mb-0">
                                <i class="bx bx-info-circle me-2"></i>
                                <span id="secondaryUnitInfo"></span>
                                <span id="secondaryStockInfo"></span>
                            </div>
                        </div>
                    </div>
                   
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Adjustment Type</label>
                            <select class="form-select" id="adjustType" name="type">
                                <option value="add">Add Stock</option>
                                <option value="remove">Remove Stock</option>
                                <option value="set">Set to Specific Value</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" id="quantityLabel">Quantity</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="adjustQuantity" name="quantity" min="0" step="0.0001" value="1" required>
                                <span class="input-group-text" id="adjustUnitText">units</span>
                            </div>
                            <small class="text-muted" id="quantityHelp"></small>
                        </div>
                    </div>
                   
                    <!-- Secondary Unit Adjustment Option -->
                    <div class="row mb-3" id="secondaryAdjustmentSection" style="display: none;">
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="adjustSecondaryUnit">
                                <label class="form-check-label" for="adjustSecondaryUnit">
                                    Adjust in secondary unit (<span id="secondaryUnitName"></span>)
                                </label>
                                <small class="text-muted d-block mt-1">
                                    When checked, the quantity will be converted to primary units using the conversion rate.
                                </small>
                            </div>
                        </div>
                    </div>
                   
                    <div class="mb-3">
                        <label class="form-label">Reason <small class="text-muted">(Optional)</small></label>
                        <textarea class="form-control" id="adjustReason" name="reason" rows="2" placeholder="e.g., Purchase order, Damaged goods, Stock correction..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bx bx-x me-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" id="saveStockAdjustment">
                    <i class="bx bx-save me-1"></i> Save Adjustment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="imageModalLabel">Product Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" class="img-fluid rounded" style="max-height: 70vh; object-fit: contain;">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bx bx-x me-1"></i> Close
                </button>
                <a href="#" class="btn btn-primary" id="downloadImageBtn" download>
                    <i class="bx bx-download me-1"></i> Download
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Auto-dismissible Success Toast -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999"></div>

<script>
    // Delete Confirmation Modal
    const deleteModal = new bootstrap.Modal('#deleteConfirmModal');
    let deleteUrl = '';

    $(document).on('click', '.delete-product-btn', function() {
        const productId = $(this).data('id');
        const productName = $(this).data('name');

        $('#deleteProductName').text(productName);
        deleteUrl = 'product_delete.php?id=' + productId;
        $('#confirmDeleteBtn').attr('href', deleteUrl);

        deleteModal.show();
    });

    // Optional: Prevent going to delete page if modal is closed without confirming
    $('#deleteConfirmModal').on('hidden.bs.modal', function () {
        $('#confirmDeleteBtn').attr('href', '#');
    });

$(document).ready(function() {
    // Initialize DataTables with client-side processing
    $('#productsTable').DataTable({
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
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Update quantity label based on type
    function updateQuantityLabel() {
        const type = $('#adjustType').val();
        const labels = {
            'add': 'Quantity to Add',
            'remove': 'Quantity to Remove',
            'set': 'New Stock Value'
        };
        $('#quantityLabel').text(labels[type]);
    }

    // Stock adjustment modal
    const adjustStockModal = new bootstrap.Modal('#adjustStockModal');
    $('.adjust-stock-btn').click(function() {
        const productId = $(this).data('id');
        const productName = $(this).data('name');
        const currentStock = parseFloat($(this).data('current'));
        const primaryUnit = $(this).data('unit');
        const secondaryUnit = $(this).data('secondary');
        const conversion = parseFloat($(this).data('conversion'));
        
        $('#adjustProductId').val(productId);
        $('#adjustProductName').val(productName);
        $('#adjustCurrentStock').val(currentStock.toFixed(4) + ' ' + primaryUnit);
        $('#adjustPrimaryUnit').val(primaryUnit);
        $('#adjustQuantity').val('');
        $('#adjustReason').val('');
        $('#adjustType').val('add');
        $('#adjustUnitText').text(primaryUnit);
        
        // Show/hide secondary unit sections
        if (secondaryUnit && conversion > 0) {
            $('#secondaryUnitSection').show();
            $('#secondaryAdjustmentSection').show();
            $('#secondaryUnitInfo').html(`1 ${primaryUnit} = ${conversion.toFixed(4)} ${secondaryUnit}`);
            $('#secondaryUnitName').text(secondaryUnit);
            
            // Calculate and show secondary stock if we have total_secondary_units in data attribute
            const secondaryStock = $(this).closest('tr').find('small.text-info').text().match(/≈ ([\d,.]+)/);
            if (secondaryStock) {
                $('#secondaryStockInfo').html(` | Current: ${secondaryStock[1]} ${secondaryUnit}`);
            }
        } else {
            $('#secondaryUnitSection').hide();
            $('#secondaryAdjustmentSection').hide();
        }
        
        updateQuantityLabel();
        adjustStockModal.show();
    });

    // Handle secondary unit checkbox
    $('#adjustSecondaryUnit').change(function() {
        const primaryUnit = $('#adjustPrimaryUnit').val();
        const secondaryUnit = $('#secondaryUnitName').text();
        const isChecked = $(this).is(':checked');
        
        if (isChecked) {
            $('#adjustUnitText').text(secondaryUnit);
            $('#quantityHelp').text(`1 ${primaryUnit} = ${$('#secondaryUnitInfo').text().match(/= ([\d.]+)/)[1]} ${secondaryUnit}`);
        } else {
            $('#adjustUnitText').text(primaryUnit);
            $('#quantityHelp').text('');
        }
    });

    // Initialize on modal show
    $('#adjustStockModal').on('show.bs.modal', function() {
        updateQuantityLabel();
        $('#adjustSecondaryUnit').prop('checked', false);
        $('#adjustSecondaryUnit').trigger('change');
    });

    // Dynamic quantity validation based on adjustment type
    $('#adjustType').change(function() {
        const currentStock = parseFloat($('#adjustCurrentStock').val()) || 0;
        const type = $(this).val();
        const primaryUnit = $('#adjustPrimaryUnit').val();
        
        updateQuantityLabel();
        
        if (type === 'remove') {
            $('#adjustQuantity').attr('max', currentStock);
            $('#adjustQuantity').attr('placeholder', `Max: ${currentStock}`);
            $('#quantityHelp').text(`Maximum you can remove: ${currentStock} ${primaryUnit}`);
        } else if (type === 'set') {
            $('#adjustQuantity').removeAttr('max');
            $('#adjustQuantity').attr('placeholder', 'Enter new stock value');
            $('#quantityHelp').text(`Set the exact stock quantity in ${primaryUnit}`);
        } else {
            $('#adjustQuantity').removeAttr('max');
            $('#adjustQuantity').attr('placeholder', 'Enter quantity to add');
            $('#quantityHelp').text(`Enter the quantity to add to current stock in ${primaryUnit}`);
        }
    });

    $('#saveStockAdjustment').click(function() {
        const productId = $('#adjustProductId').val();
        const type = $('#adjustType').val();
        let quantity = $('#adjustQuantity').val();
        const reason = $('#adjustReason').val();
        const currentStock = parseFloat($('#adjustCurrentStock').val()) || 0;
        const primaryUnit = $('#adjustPrimaryUnit').val();
        const adjustInSecondary = $('#adjustSecondaryUnit').is(':checked');
        const conversionRate = parseFloat($('#secondaryUnitInfo').text().match(/= ([\d.]+)/)?.[1]) || 1;
        
        // Convert to primary units if adjusting in secondary
        if (adjustInSecondary) {
            quantity = parseFloat(quantity) / conversionRate;
        }
        
        // Validation
        if (!quantity || quantity <= 0) {
            showToast('warning', 'Please enter a valid quantity');
            $('#adjustQuantity').focus();
            return;
        }
        
        if (type === 'remove' && parseFloat(quantity) > currentStock) {
            showToast('warning', `Cannot remove more than current stock (${currentStock} ${primaryUnit})`);
            $('#adjustQuantity').focus();
            return;
        }
        
        if (type === 'set' && parseFloat(quantity) < 0) {
            showToast('warning', 'Stock cannot be negative');
            $('#adjustQuantity').focus();
            return;
        }
        
        const formData = {
            product_id: productId,
            type: type,
            quantity: quantity,
            reason: reason || 'Manual adjustment',
            shop_id: '<?= $current_shop_id ?>',
            adjust_in_secondary: adjustInSecondary ? 1 : 0,
            conversion_rate: adjustInSecondary ? conversionRate : 1
        };
        
        $(this).prop('disabled', true).html('<i class="bx bx-loader bx-spin"></i> Saving...');
        
        $.ajax({
            url: 'ajax/adjust_stock.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            timeout: 10000,
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Stock adjusted successfully!');
                    adjustStockModal.hide();
                    
                    // Update the row immediately without full page reload
                    const row = $(`tr[data-id="${productId}"]`);
                    if (row.length) {
                        // Calculate new stock based on type
                        let newStock = currentStock;
                        if (type === 'add') newStock = currentStock + parseFloat(quantity);
                        else if (type === 'remove') newStock = currentStock - parseFloat(quantity);
                        else if (type === 'set') newStock = parseFloat(quantity);
                        
                        // Update primary stock display
                        const stockBadge = row.find('.badge.rounded-pill');
                        if (stockBadge.length) {
                            stockBadge.text(newStock.toFixed(2) + ' ' + primaryUnit);
                        }
                        
                        // Update secondary stock if exists
                        const secondaryUnit = $('#secondaryUnitName').text();
                        if (secondaryUnit && conversionRate > 0) {
                            const newSecondaryStock = newStock * conversionRate;
                            const secondaryStockElement = row.find('small.text-info');
                            if (secondaryStockElement.length) {
                                secondaryStockElement.text(`≈ ${newSecondaryStock.toFixed(2)} ${secondaryUnit}`);
                            }
                        }
                        
                        // Update stock status
                        const minStockText = row.find('small.text-muted:contains("Min:")').text();
                        const minStock = parseFloat(minStockText.replace('Min: ', '')) || 10;
                        let stockClass, stockText;
                        
                        if (newStock == 0) {
                            stockClass = 'danger';
                            stockText = 'Out of Stock';
                        } else if (newStock < minStock * 0.25) {
                            stockClass = 'danger';
                            stockText = 'Critical';
                        } else if (newStock < minStock) {
                            stockClass = 'warning';
                            stockText = 'Low';
                        } else {
                            stockClass = 'success';
                            stockText = 'In Stock';
                        }
                        
                        // Update badges
                        const statusBadge = row.find('.badge.bg-opacity-10');
                        stockBadge.removeClass('bg-danger bg-warning bg-success').addClass('bg-' + stockClass);
                        statusBadge.removeClass('bg-danger bg-warning bg-success text-danger text-warning text-success')
                                   .addClass('bg-' + stockClass + ' bg-opacity-10 text-' + stockClass)
                                   .html('<i class="bx bx-circle me-1"></i>' + stockText);
                        
                        // Update progress bar if exists
                        const progressBar = row.find('.progress-bar');
                        if (progressBar.length) {
                            const stockPercentage = minStock > 0 ? Math.min((newStock / minStock) * 100, 100) : 0;
                            progressBar.css('width', stockPercentage + '%')
                                       .removeClass('bg-danger bg-warning bg-success')
                                       .addClass('bg-' + stockClass);
                        }
                        
                        // Update the data-current attribute on the adjust button
                        row.find('.adjust-stock-btn').data('current', newStock);
                    }
                    
                    // Show success message for 2 seconds before reloading
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showToast('error', response.message || 'Failed to adjust stock');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                
                if (status === 'timeout') {
                    showToast('error', 'Request timeout. Please try again.');
                } else if (status === 'parsererror') {
                    showToast('error', 'Server response error. Please check console for details.');
                } else if (status === 'error') {
                    if (xhr.status === 404) {
                        showToast('error', 'Server endpoint not found. Check if ajax/adjust_stock.php exists.');
                    } else if (xhr.status === 500) {
                        showToast('error', 'Server error. Please try again later.');
                    } else {
                        showToast('error', 'Network error. Please check your connection.');
                    }
                }
            },
            complete: function() {
                $('#saveStockAdjustment').prop('disabled', false).html('<i class="bx bx-save me-1"></i> Save Adjustment');
            }
        });
    });

    // Image modal
    const imageModal = new bootstrap.Modal('#imageModal');
    $(document).on('click', '.product-image', function() {
        const fullImageSrc = $(this).data('full-image');
        const productName = $(this).data('product-name');
        if (fullImageSrc) {
            $('#modalImage').attr('src', fullImageSrc);
            $('#imageModalLabel').text(productName);
            $('#downloadImageBtn').attr('href', fullImageSrc)
                                   .attr('download', productName.replace(/[^a-z0-9]/gi, '_').toLowerCase() + '.jpg');
            imageModal.show();
        }
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

    // Real-time search debounce
    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => $('#filterForm').submit(), 500);
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

    // Auto-dismiss success messages after 5 seconds
    setTimeout(function() {
        $('.alert-success').fadeOut('slow');
    }, 5000);
});
</script>

<!-- Additional CSS for messages -->
<style>
.alert {
    border-left-width: 4px;
    border-radius: 4px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    animation: slideIn 0.3s ease-out;
}

.alert-success {
    border-left-color: #28a745;
    background-color: #d4edda;
    color: #155724;
}

.alert-danger {
    border-left-color: #dc3545;
    background-color: #f8d7da;
    color: #721c24;
}

@keyframes slideIn {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.toast {
    min-width: 300px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.text-bg-success {
    background-color: #28a745 !important;
}

.text-bg-warning {
    background-color: #ffc107 !important;
    color: #212529 !important;
}

.text-bg-error, .text-bg-danger {
    background-color: #dc3545 !important;
}
</style>
</body>
</html>