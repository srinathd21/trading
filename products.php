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

/* -----------------------------
   Session messages
----------------------------- */
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

/* -----------------------------
   Single delete
----------------------------- */
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = (int) $_GET['delete'];

    try {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND business_id = ?");
        $stmt->execute([$delete_id, $current_business_id]);
        $_SESSION['success_message'] = 'Product deleted successfully!';
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Unable to delete product: ' . $e->getMessage();
    }

    $query = $_GET;
    unset($query['delete']);
    header('Location: products.php' . (!empty($query) ? '?' . http_build_query($query) : ''));
    exit;
}

/* -----------------------------
   Bulk actions
----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = trim($_POST['bulk_action'] ?? '');
    $selected_ids = [];

    if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $selected_ids = array_map('intval', $_POST['selected_ids']);
    } elseif (!empty($_POST['selected_ids_json'])) {
        $decoded = json_decode($_POST['selected_ids_json'], true);
        if (is_array($decoded)) {
            $selected_ids = array_map('intval', $decoded);
        }
    }

    $selected_ids = array_values(array_filter($selected_ids, function ($id) {
        return $id > 0;
    }));

    if (empty($selected_ids)) {
        $_SESSION['error_message'] = 'Please select at least one product.';
        header('Location: products.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

    try {
        switch ($bulk_action) {
            case 'activate':
                $sql = "UPDATE products SET is_active = 1 WHERE business_id = ? AND id IN ($placeholders)";
                $params_bulk = array_merge([$current_business_id], $selected_ids);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params_bulk);
                $_SESSION['success_message'] = count($selected_ids) . " product(s) activated successfully!";
                break;

            case 'deactivate':
                $sql = "UPDATE products SET is_active = 0 WHERE business_id = ? AND id IN ($placeholders)";
                $params_bulk = array_merge([$current_business_id], $selected_ids);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params_bulk);
                $_SESSION['success_message'] = count($selected_ids) . " product(s) deactivated successfully!";
                break;

            case 'delete':
                $sql = "DELETE FROM products WHERE business_id = ? AND id IN ($placeholders)";
                $params_bulk = array_merge([$current_business_id], $selected_ids);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params_bulk);
                $_SESSION['success_message'] = count($selected_ids) . " product(s) deleted successfully!";
                break;

            default:
                $_SESSION['error_message'] = 'Invalid bulk action selected.';
                break;
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Bulk action failed: ' . $e->getMessage();
    }

    header('Location: products.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

/* -----------------------------
   Pagination
----------------------------- */
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
$per_page = isset($_GET['per_page']) && is_numeric($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$per_page = min(max($per_page, 10), 100);
$offset = ($page - 1) * $per_page;

/* -----------------------------
   Filters
----------------------------- */
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$stock_filter = $_GET['stock'] ?? 'all';
$hsn_filter = $_GET['hsn'] ?? '';

$where = "WHERE p.is_active = 1 AND p.business_id = ?";
$params = [$current_business_id];

$shop_condition = $is_admin ? "" : "AND ps.shop_id = " . (int)$current_shop_id;

/* -----------------------------
   Filter data
----------------------------- */
$cat_stmt = $pdo->prepare("
    SELECT id, category_name
    FROM categories
    WHERE business_id = ? AND status = 'active' AND parent_id IS NULL
    ORDER BY category_name
");
$cat_stmt->execute([$current_business_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

$hsn_stmt = $pdo->prepare("
    SELECT DISTINCT g.hsn_code
    FROM gst_rates g
    INNER JOIN products p ON g.id = p.gst_id
    WHERE g.business_id = ? AND g.status = 'active'
    ORDER BY g.hsn_code
");
$hsn_stmt->execute([$current_business_id]);
$hsn_codes = $hsn_stmt->fetchAll(PDO::FETCH_COLUMN);

/* -----------------------------
   Stock summary
----------------------------- */
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

/* -----------------------------
   Apply filters
----------------------------- */
if ($search !== '') {
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

/* -----------------------------
   Count total products
----------------------------- */
$count_sql = "
    SELECT COUNT(DISTINCT p.id) as total
    FROM products p
    LEFT JOIN product_stocks ps ON ps.product_id = p.id $shop_condition
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    $where
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_products = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

/* -----------------------------
   Fetch products
----------------------------- */
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
        p.warranty_type,
        p.warranty_period,
        p.warranty_unit,
        p.warranty_description,
        p.created_at,
        c.category_name,
        s.subcategory_name,
        g.hsn_code,
        CONCAT(g.cgst_rate, '%') as cgst_rate,
        CONCAT(g.sgst_rate, '%') as sgst_rate,
        CONCAT(g.igst_rate, '%') as igst_rate,
        CONCAT(g.cgst_rate + g.sgst_rate + g.igst_rate, '%') AS total_tax_rate,
        COALESCE(SUM(ps.quantity), 0) AS total_stock,
        COALESCE(MAX(ps.total_secondary_units), 0) AS total_secondary_units
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id
    LEFT JOIN gst_rates g ON p.gst_id = g.id
    LEFT JOIN product_stocks ps ON ps.product_id = p.id $shop_condition
    $where
    GROUP BY p.id
    ORDER BY p.product_name
    LIMIT ? OFFSET ?
";

$params_for_products = $params;
$params_for_products[] = $per_page;
$params_for_products[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params_for_products);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   HSN filter in PHP
----------------------------- */
if ($hsn_filter !== '') {
    $products = array_values(array_filter($products, function ($product) use ($hsn_filter) {
        return ($product['hsn_code'] ?? '') == $hsn_filter;
    }));
    $total_products = count($products);
}

/* -----------------------------
   Stock filter in PHP
----------------------------- */
if ($stock_filter !== 'all') {
    $filtered_products = [];

    foreach ($products as $p) {
        $current_stock = (float)$p['total_stock'];
        $min_stock = !empty($p['min_stock_level']) ? (float)$p['min_stock_level'] : 10;

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
    $total_products = count($products);
}

$total_pages = max(1, (int)ceil($total_products / $per_page));

/* -----------------------------
   Stock value summary
----------------------------- */
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

/* -----------------------------
   Helpers
----------------------------- */
function getWarrantyEndDate($created_at, $warranty_period, $warranty_unit) {
    if (!$warranty_period || $warranty_period <= 0 || !$created_at) {
        return null;
    }

    $date = new DateTime($created_at);
    switch ($warranty_unit) {
        case 'days':
            $date->modify("+{$warranty_period} days");
            break;
        case 'months':
            $date->modify("+{$warranty_period} months");
            break;
        case 'years':
            $date->modify("+{$warranty_period} years");
            break;
        default:
            return null;
    }
    return $date;
}

function getWarrantyStatus($end_date) {
    if (!$end_date) return null;

    $now = new DateTime();
    if ($end_date < $now) {
        return ['text' => 'Expired', 'class' => 'danger'];
    }

    $days_left = $now->diff($end_date)->days;
    if ($days_left <= 30) {
        return ['text' => 'Expiring Soon', 'class' => 'warning'];
    }

    return ['text' => 'Active', 'class' => 'success'];
}

function buildPaginationUrl($page, $per_page, $search, $category, $hsn_filter, $stock_filter) {
    $params = [];
    if ($page > 1) $params['page'] = $page;
    if ($per_page != 25) $params['per_page'] = $per_page;
    if ($search) $params['search'] = $search;
    if ($category) $params['category'] = $category;
    if ($hsn_filter) $params['hsn'] = $hsn_filter;
    if ($stock_filter != 'all') $params['stock'] = $stock_filter;

    return 'products.php' . (empty($params) ? '' : '?' . http_build_query($params));
}
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
                                    <div><strong>Success!</strong> <?= htmlspecialchars($success_message) ?></div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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
                                    <div><strong>Error!</strong> <?= htmlspecialchars($error_message) ?></div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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
                                            <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>>
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
                                            <option value="<?= htmlspecialchars($hsn) ?>" <?= $hsn_filter == $hsn ? 'selected' : '' ?>>
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
                            <input type="hidden" name="per_page" value="<?= $per_page ?>">
                        </form>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="card shadow-sm">
                    <div class="card-body">

                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h5 class="card-title mb-0">
                                <i class="bx bx-list-ul me-1"></i> Product List
                            </h5>

                            <form method="POST" id="bulkActionForm" class="d-flex gap-2 flex-wrap">
                                <input type="hidden" name="selected_ids_json" id="selectedIdsJson">

                                <select name="bulk_action" class="form-select form-select-sm" style="width:auto;">
                                    <option value="">Bulk Actions</option>
                                    <option value="activate">Activate Selected</option>
                                    <option value="deactivate">Deactivate Selected</option>
                                    <option value="delete">Delete Selected</option>
                                </select>

                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bx bx-play-circle me-1"></i> Apply
                                </button>

                                <button type="button" class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn">
                                    <i class="bx bx-trash me-1"></i> Delete Selected
                                </button>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:50px;">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="selectAll">
                                            </div>
                                        </th>
                                        <th>Product Details</th>
                                        <th>Category</th>
                                        <th class="text-end">Prices</th>
                                        <th>Units & Conversion</th>
                                        <th>GST Details</th>
                                        <th>Warranty</th>
                                        <th class="text-end">Stock</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-5">
                                            <i class="bx bx-package fs-1 text-muted"></i>
                                            <p class="text-muted mt-2 mb-0">No products found</p>
                                            <?php if ($is_stock_manager): ?>
                                                <a href="product_add.php" class="btn btn-sm btn-primary mt-3">
                                                    <i class="bx bx-plus-circle me-1"></i> Add Product
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $p):
                                        $current_stock = (float)$p['total_stock'];
                                        $min_stock = !empty($p['min_stock_level']) ? (float)$p['min_stock_level'] : 10;
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

                                        $profit_margin = ($p['retail_price'] > 0 && $p['stock_price'] > 0)
                                            ? (($p['retail_price'] - $p['stock_price']) / $p['stock_price']) * 100
                                            : 0;

                                        $tax_type = 'CGST+SGST';
                                        if (!empty($p['igst_rate']) && $p['igst_rate'] != '0%') {
                                            $tax_type = 'IGST';
                                        }

                                        $has_image = !empty($p['image_thumbnail_path']);
                                        $image_src = $has_image ? htmlspecialchars($p['image_thumbnail_path']) : '';
                                        $full_image_src = $has_image ? htmlspecialchars($p['image_path'] ?? $p['image_thumbnail_path']) : '';
                                        $alt_text = htmlspecialchars($p['image_alt_text'] ?? $p['product_name']);

                                        $unit_of_measure = htmlspecialchars($p['unit_of_measure'] ?? 'pcs');
                                        $secondary_unit = htmlspecialchars($p['secondary_unit'] ?? '');
                                        $sec_unit_conversion = $p['sec_unit_conversion'] ?? 0;
                                        $sec_unit_price_type = $p['sec_unit_price_type'] ?? 'fixed';
                                        $sec_unit_extra_charge = $p['sec_unit_extra_charge'] ?? 0;
                                        $total_secondary_units = $p['total_secondary_units'] ?? 0;

                                        $warranty_type = $p['warranty_type'] ?? 'none';
                                        $warranty_period = $p['warranty_period'] ?? 0;
                                        $warranty_unit = $p['warranty_unit'] ?? 'months';
                                        $warranty_description = $p['warranty_description'] ?? '';
                                        $created_at = $p['created_at'] ?? null;

                                        $warranty_end_date = null;
                                        $warranty_status = null;
                                        if ($warranty_type != 'none' && $warranty_period > 0 && $created_at) {
                                            $warranty_end_date = getWarrantyEndDate($created_at, $warranty_period, $warranty_unit);
                                            $warranty_status = getWarrantyStatus($warranty_end_date);
                                        }

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
                                                <div class="form-check mb-0">
                                                    <input class="form-check-input select-checkbox" type="checkbox" value="<?= (int)$p['id'] ?>">
                                                </div>
                                            </td>

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

                                                        <?php if (!empty($code_info)): ?>
                                                            <div class="mb-1"><?= $code_info ?></div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($p['description'])): ?>
                                                            <small class="text-muted d-block">
                                                                <?= htmlspecialchars(substr($p['description'], 0, 80)) ?><?= strlen($p['description']) > 80 ? '...' : '' ?>
                                                            </small>
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
                                                <?php if ($p['mrp'] > 0): ?>
                                                    <div class="mb-1">
                                                        <s class="text-muted small">₹<?= number_format($p['mrp'], 2) ?></s>
                                                        <?php if ($p['discount_value'] > 0): ?>
                                                            <span class="badge bg-danger ms-1">
                                                                <?php if ($p['discount_type'] === 'percentage'): ?>
                                                                    <?= number_format($p['discount_value'], 1) ?>% off
                                                                <?php else: ?>
                                                                    ₹<?= number_format($p['discount_value'], 2) ?> off
                                                                <?php endif; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

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
                                                    <div class="mb-1">
                                                        <span class="badge bg-primary bg-opacity-10 text-primary">
                                                            <i class="bx bx-cube me-1"></i><?= $unit_of_measure ?>
                                                        </span>
                                                    </div>

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
                                                    <?php if (!empty($p['hsn_code'])): ?>
                                                        <div class="mb-1">
                                                            <span class="badge bg-info bg-opacity-10 text-info">
                                                                <i class="bx bx-hash me-1"></i><?= htmlspecialchars($p['hsn_code']) ?>
                                                            </span>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="mb-1">
                                                            <span class="badge bg-secondary bg-opacity-10 text-secondary">No HSN</span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="mb-1">
                                                        <span class="badge bg-<?= $p['gst_type'] == 'inclusive' ? 'success' : 'primary' ?> bg-opacity-10 text-<?= $p['gst_type'] == 'inclusive' ? 'success' : 'primary' ?>">
                                                            <i class="bx bx-receipt me-1"></i>
                                                            GST <?= ucfirst($p['gst_type']) ?>
                                                            <?php if ($p['gst_amount'] > 0): ?>
                                                                <span class="ms-1">(₹<?= number_format($p['gst_amount'], 2) ?>)</span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>

                                                    <?php if (!empty($p['total_tax_rate']) && $p['total_tax_rate'] != '0%'): ?>
                                                        <div>
                                                            <small class="text-muted">
                                                                <?php if ($tax_type == 'CGST+SGST'): ?>
                                                                    <div class="d-flex gap-1 mb-1">
                                                                        <span class="badge bg-primary bg-opacity-10 text-primary">C: <?= $p['cgst_rate'] ?></span>
                                                                        <span class="badge bg-primary bg-opacity-10 text-primary">S: <?= $p['sgst_rate'] ?></span>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <div class="mb-1">
                                                                        <span class="badge bg-warning bg-opacity-10 text-warning">I: <?= $p['igst_rate'] ?></span>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <small>Total: <?= $p['total_tax_rate'] ?></small>
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <td>
                                                <?php if ($warranty_type != 'none' && $warranty_period > 0): ?>
                                                    <div class="d-flex flex-column">
                                                        <div class="mb-1">
                                                            <span class="badge bg-primary bg-opacity-10 text-primary">
                                                                <i class="bx bx-shield me-1"></i>
                                                                <?= ucfirst($warranty_type) ?> Warranty
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <small class="text-muted"><?= $warranty_period ?> <?= $warranty_unit ?></small>
                                                        </div>
                                                        <?php if ($warranty_end_date): ?>
                                                            <div>
                                                                <small class="text-muted">Until: <?= $warranty_end_date->format('d M Y') ?></small>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($warranty_status): ?>
                                                            <div class="mt-1">
                                                                <span class="badge bg-<?= $warranty_status['class'] ?> bg-opacity-10 text-<?= $warranty_status['class'] ?>">
                                                                    <?= $warranty_status['text'] ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($warranty_description)): ?>
                                                            <div class="mt-1">
                                                                <small class="text-muted" title="<?= htmlspecialchars($warranty_description) ?>">
                                                                    <?= htmlspecialchars(substr($warranty_description, 0, 40)) ?><?= strlen($warranty_description) > 40 ? '...' : '' ?>
                                                                </small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center text-muted">
                                                        <i class="bx bx-shield-alt fs-4"></i>
                                                        <br><small>No Warranty</small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-end">
                                                <div class="d-flex flex-column align-items-end">
                                                    <div class="mb-1">
                                                        <span class="badge bg-<?= $stock_class ?> rounded-pill px-3 py-1 fs-6">
                                                            <?= number_format($current_stock, 2) ?> <?= $unit_of_measure ?>
                                                        </span>
                                                    </div>

                                                    <?php if (!empty($secondary_unit) && $sec_unit_conversion > 0 && $total_secondary_units > 0): ?>
                                                        <div class="mb-1">
                                                            <small class="text-info">≈ <?= number_format($total_secondary_units, 2) ?> <?= $secondary_unit ?></small>
                                                        </div>
                                                    <?php endif; ?>

                                                    <small class="text-muted">Min: <?= $min_stock ?> <?= $unit_of_measure ?></small>
                                                    <?php if ($stock_percentage > 0): ?>
                                                        <div class="progress mt-1" style="width: 80px; height: 6px;">
                                                            <div class="progress-bar bg-<?= $stock_class ?>" style="width: <?= min($stock_percentage, 100) ?>%"></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <td class="text-center">
                                                <span class="badge bg-<?= $stock_class ?> bg-opacity-10 text-<?= $stock_class ?> px-3 py-1">
                                                    <i class="bx bx-circle me-1"></i><?= $stock_text ?>
                                                </span>

                                                <?php if (!empty($p['referral_enabled'])): ?>
                                                    <br><small class="text-success mt-1 d-inline-block">Referral Enabled</small>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="product_view.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline-info" data-bs-toggle="tooltip" title="View Product">
                                                        <i class="bx bx-show"></i>
                                                    </a>

                                                    <?php if ($is_stock_manager): ?>
                                                        <a href="product_add.php?edit=<?= (int)$p['id'] ?>" class="btn btn-outline-warning" data-bs-toggle="tooltip" title="Edit Product">
                                                            <i class="bx bx-edit"></i>
                                                        </a>

                                                        <a href="stock_transfer.php?product_id=<?= (int)$p['id'] ?>" class="btn btn-outline-secondary" data-bs-toggle="tooltip" title="Transfer Stock">
                                                            <i class="bx bx-transfer"></i>
                                                        </a>

                                                        <button type="button"
                                                                class="btn btn-outline-danger delete-product-btn"
                                                                data-id="<?= (int)$p['id'] ?>"
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

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="row mt-4">
                                <div class="col-sm-12 col-md-6">
                                    <div class="dataTables_info" role="status" aria-live="polite">
                                        Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $offset + count($products)) ?> of <?= $total_products ?> entries
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    <div class="dataTables_paginate paging_simple_numbers float-md-end">
                                        <ul class="pagination pagination-rounded mb-0">
                                            <li class="paginate_button page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a href="<?= buildPaginationUrl(1, $per_page, $search, $category, $hsn_filter, $stock_filter) ?>" class="page-link">
                                                    <i class="bx bx-chevrons-left"></i>
                                                </a>
                                            </li>

                                            <li class="paginate_button page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a href="<?= buildPaginationUrl($page - 1, $per_page, $search, $category, $hsn_filter, $stock_filter) ?>" class="page-link">
                                                    <i class="bx bx-chevron-left"></i>
                                                </a>
                                            </li>

                                            <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            for ($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                                <li class="paginate_button page-item <?= $i == $page ? 'active' : '' ?>">
                                                    <a href="<?= buildPaginationUrl($i, $per_page, $search, $category, $hsn_filter, $stock_filter) ?>" class="page-link"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>

                                            <li class="paginate_button page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a href="<?= buildPaginationUrl($page + 1, $per_page, $search, $category, $hsn_filter, $stock_filter) ?>" class="page-link">
                                                    <i class="bx bx-chevron-right"></i>
                                                </a>
                                            </li>

                                            <li class="paginate_button page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a href="<?= buildPaginationUrl($total_pages, $per_page, $search, $category, $hsn_filter, $stock_filter) ?>" class="page-link">
                                                    <i class="bx bx-chevrons-right"></i>
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

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
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                    <i class="bx bx-trash me-1"></i> Delete
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Product Image Preview Modal -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="imagePreviewTitle">Product Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="" id="imagePreviewImg" class="img-fluid rounded" alt="Product Image">
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function () {
    $('[data-bs-toggle="tooltip"]').tooltip();

    function getSelectedIds() {
        let ids = [];
        $('.select-checkbox:checked').each(function () {
            ids.push($(this).val());
        });
        return ids;
    }

    function updateSelectAllState() {
        const total = $('.select-checkbox').length;
        const checked = $('.select-checkbox:checked').length;

        if (checked === 0) {
            $('#selectAll').prop('checked', false).prop('indeterminate', false);
        } else if (checked === total) {
            $('#selectAll').prop('checked', true).prop('indeterminate', false);
        } else {
            $('#selectAll').prop('checked', false).prop('indeterminate', true);
        }
    }

    $('#selectAll').on('change', function () {
        $('.select-checkbox').prop('checked', this.checked);
        updateSelectAllState();
    });

    $(document).on('change', '.select-checkbox', function () {
        updateSelectAllState();
    });

    $('#bulkActionForm').on('submit', function (e) {
        const action = $('select[name="bulk_action"]').val();
        const selectedIds = getSelectedIds();
        const count = selectedIds.length;

        if (!action) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Please select a bulk action'
            });
            return false;
        }

        if (count === 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Please select at least one product'
            });
            return false;
        }

        $('#selectedIdsJson').val(JSON.stringify(selectedIds));

        if (action === 'delete') {
            e.preventDefault();
            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to delete ${count} product(s)`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete them!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#selectedIdsJson').val(JSON.stringify(selectedIds));
                    document.getElementById('bulkActionForm').submit();
                }
            });
            return false;
        }

        if (action === 'activate' || action === 'deactivate') {
            e.preventDefault();
            Swal.fire({
                title: 'Confirm Action',
                text: `You are about to ${action} ${count} product(s)`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, continue',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#selectedIdsJson').val(JSON.stringify(selectedIds));
                    document.getElementById('bulkActionForm').submit();
                }
            });
            return false;
        }

        return true;
    });

    $('#deleteSelectedBtn').on('click', function () {
        $('select[name="bulk_action"]').val('delete');
        $('#bulkActionForm').trigger('submit');
    });

    $(document).on('click', '.delete-product-btn', function () {
        const productId = $(this).data('id');
        const productName = $(this).data('name');

        $('#deleteProductName').text(productName);
        $('#confirmDeleteBtn').attr('href', 'products.php?delete=' + productId);

        const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        deleteModal.show();
    });

    $(document).on('click', '.product-image', function () {
        const fullImage = $(this).data('full-image');
        const productName = $(this).data('product-name');

        $('#imagePreviewImg').attr('src', fullImage);
        $('#imagePreviewTitle').text(productName);

        const previewModal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
        previewModal.show();
    });

    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => $('#filterForm').submit(), 500);
    });
});
</script>

<style>
.card-hover {
    transition: all 0.2s ease;
}
.card-hover:hover {
    transform: translateY(-2px);
}
.table td, .table th {
    vertical-align: middle;
}
.product-image:hover {
    transform: scale(1.03);
    transition: 0.2s ease;
}
.progress {
    background-color: #e9ecef;
}
</style>
</body>
</html>