<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

$user_name = $_SESSION['full_name'] ?? 'Admin';
$business_id = $_SESSION['business_id'] ?? 1;

// ==================== SEARCH & FILTER PARAMETERS ====================
$search = trim($_GET['search'] ?? '');
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';

// ==================== HANDLE FORM SUBMISSIONS ====================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $shop_name = trim($_POST['shop_name']);
    $shop_code = strtoupper(trim($_POST['shop_code']));
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gstin = trim($_POST['gstin'] ?? '');
    $is_warehouse = isset($_POST['is_warehouse']) ? 1 : 0;
    $location_type = $is_warehouse ? 'warehouse' : 'shop';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($shop_name && $shop_code) {
        try {
            if ($id) {
                // Update
                $stmt = $pdo->prepare("UPDATE shops SET shop_name=?, shop_code=?, address=?, phone=?, gstin=?, is_warehouse=?, location_type=?, is_active=? WHERE id=? AND business_id=?");
                $stmt->execute([$shop_name, $shop_code, $address, $phone, $gstin, $is_warehouse, $location_type, $is_active, $id, $business_id]);
                $success = "Shop updated successfully!";
            } else {
                // Check if shop code already exists in this business
                $checkCode = $pdo->prepare("SELECT id FROM shops WHERE shop_code = ? AND business_id = ?");
                $checkCode->execute([$shop_code, $business_id]);
                if ($checkCode->fetch()) {
                    $error = "Shop code already exists in this business!";
                } else {
                    // Insert
                    $stmt = $pdo->prepare("INSERT INTO shops (shop_name, shop_code, address, phone, gstin, is_warehouse, location_type, is_active, business_id) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$shop_name, $shop_code, $address, $phone, $gstin, $is_warehouse, $location_type, $is_active, $business_id]);
                    $success = "Shop added successfully!";
                }
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Shop name and code are required!";
    }
    
    // Store messages in session for redirect
    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    
    // Redirect to clear POST data
    header("Location: shops.php");
    exit();
}

// SIMPLE DELETE - Always delete when confirmed
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    try {
        // Get shop name for message
        $stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
        $stmt->execute([$id, $business_id]);
        $shop = $stmt->fetch();
        
        if ($shop) {
            $shop_name = $shop['shop_name'];
            
            // SIMPLE DELETE - Just delete the shop
            $stmt = $pdo->prepare("DELETE FROM shops WHERE id = ? AND business_id = ?");
            if ($stmt->execute([$id, $business_id])) {
                $_SESSION['success'] = "Shop '$shop_name' deleted successfully!";
            } else {
                $_SESSION['error'] = "Failed to delete shop!";
            }
        } else {
            $_SESSION['error'] = "Shop not found!";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error deleting shop: " . $e->getMessage();
    }
    
    header("Location: shops.php");
    exit();
}

// Get messages from session
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ==================== BUILD SQL QUERY WITH FILTERS ====================
// Build WHERE conditions - always filter by business_id
$where_conditions = ["s.business_id = ?"];
$params = [$business_id];

if (!empty($search)) {
    $where_conditions[] = "(s.shop_name LIKE ? OR s.shop_code LIKE ? OR s.address LIKE ? OR s.phone LIKE ? OR s.gstin LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if (!empty($type_filter)) {
    if ($type_filter === 'warehouse') {
        $where_conditions[] = "s.is_warehouse = 1";
    } elseif ($type_filter === 'shop') {
        $where_conditions[] = "s.is_warehouse = 0";
    }
}

if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $where_conditions[] = "s.is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $where_conditions[] = "s.is_active = 0";
    }
}

// Build WHERE clause
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// ==================== FETCH SHOPS WITH FILTERS ====================
$sql = "
    SELECT s.*, 
           CASE WHEN s.is_warehouse = 1 THEN 'warehouse' ELSE 'shop' END AS type_label,
           (SELECT COUNT(*) FROM users u WHERE u.shop_id = s.id AND u.business_id = s.business_id AND u.is_active = 1) as staff_count,
           (SELECT COUNT(*) FROM product_stocks ps WHERE ps.shop_id = s.id AND ps.business_id = s.business_id) as products_count,
           (SELECT COUNT(*) FROM invoices i WHERE i.shop_id = s.id AND i.business_id = s.business_id) as invoice_count
    FROM shops s
    $where_clause
    ORDER BY s.is_warehouse DESC, s.shop_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shops = $stmt->fetchAll();

// ==================== FETCH STATISTICS WITH FILTERS ====================
$stats_where = 'WHERE business_id = ?';
$stats_params = [$business_id];

if (!empty($search)) {
    $stats_where .= " AND (shop_name LIKE ? OR shop_code LIKE ? OR address LIKE ? OR phone LIKE ? OR gstin LIKE ?)";
    $like = "%$search%";
    $stats_params = array_merge($stats_params, [$like, $like, $like, $like, $like]);
}

if (!empty($type_filter)) {
    if ($type_filter === 'warehouse') {
        $stats_where .= " AND is_warehouse = 1";
    } elseif ($type_filter === 'shop') {
        $stats_where .= " AND is_warehouse = 0";
    }
}

if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $stats_where .= " AND is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $stats_where .= " AND is_active = 0";
    }
}

$stats_sql = "
    SELECT 
        COUNT(*) as total_shops,
        COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_shops,
        COUNT(CASE WHEN is_warehouse = 1 THEN 1 END) as warehouse_count,
        COUNT(CASE WHEN is_warehouse = 0 THEN 1 END) as branch_count
    FROM shops
    $stats_where
";

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch();

// Calculate statistics
$total_shops = $stats['total_shops'] ?? 0;
$active_shops = $stats['active_shops'] ?? 0;
$warehouse_count = $stats['warehouse_count'] ?? 0;
$branch_count = $stats['branch_count'] ?? 0;
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Shop Management"; ?>
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
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-store-alt me-2"></i> Shop & Warehouse Management
                                <small class="text-muted ms-2">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addShopModal">
                                    <i class="bx bx-plus-circle me-1"></i> Add Location
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Locations</h6>
                                        <h3 class="mb-0 text-primary"><?= $total_shops ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-store text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Active Locations</h6>
                                        <h3 class="mb-0 text-success"><?= $active_shops ?></h3>
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
                                        <h6 class="text-muted mb-1">Warehouses</h6>
                                        <h3 class="mb-0 text-info"><?= $warehouse_count ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-package text-info"></i>
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
                                        <h6 class="text-muted mb-1">Branch Shops</h6>
                                        <h3 class="mb-0 text-warning"><?= $branch_count ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-shop text-warning"></i>
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
                            <i class="bx bx-filter-alt me-1"></i> Filter Shops
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Search Shops</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Shop Name, Code, Address, Phone..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Location Type</label>
                                    <select name="type" class="form-select">
                                        <option value="">All Types</option>
                                        <option value="warehouse" <?= $type_filter == 'warehouse' ? 'selected' : '' ?>>Warehouse Only</option>
                                        <option value="shop" <?= $type_filter == 'shop' ? 'selected' : '' ?>>Shop Only</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active Only</option>
                                        <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($search || $type_filter || $status_filter): ?>
                                        <a href="shops.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Shops Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="shopsTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Shop Details</th>
                                        <th class="text-center">Type</th>
                                        <th>Contact</th>
                                        <th class="text-center">Staff</th>
                                        <th class="text-center">Products</th>
                                        <th class="text-center">Sales</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($shops)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <div class="empty-state">
                                                <i class="bx bx-store fs-1 text-muted mb-3 d-block"></i>
                                                <p class="text-muted mb-1">No shops or warehouses found</p>
                                                <small>Add your first location using the "Add Location" button</small>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($shops as $shop): ?>
                                    <tr class="shop-row" data-id="<?= $shop['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <?php if ($shop['is_warehouse']): ?>
                                                    <div class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx bx-package fs-4"></i>
                                                    </div>
                                                    <?php else: ?>
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx bx-store fs-4"></i>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($shop['shop_name']) ?></strong>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-hash me-1"></i><?= htmlspecialchars($shop['shop_code']) ?>
                                                    </small>
                                                    <?php if ($shop['address']): ?>
                                                    <small class="text-muted">
                                                        <i class="bx bx-map me-1"></i><?= htmlspecialchars(substr($shop['address'], 0, 60)) ?>
                                                        <?= strlen($shop['address']) > 60 ? '...' : '' ?>
                                                    </small>
                                                    <?php endif; ?>
                                                    <?php if ($shop['gstin']): ?>
                                                    <br><small class="text-muted">
                                                        <i class="bx bx-barcode me-1"></i><?= htmlspecialchars($shop['gstin']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($shop['is_warehouse']): ?>
                                            <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                <i class="bx bx-package me-1"></i>Warehouse
                                            </span>
                                            <?php else: ?>
                                            <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1">
                                                <i class="bx bx-store me-1"></i>Branch Shop
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($shop['phone']): ?>
                                            <div class="mb-1">
                                                <a href="tel:<?= htmlspecialchars($shop['phone']) ?>" class="text-decoration-none d-flex align-items-center">
                                                    <i class="bx bx-phone text-primary me-2"></i> <?= htmlspecialchars($shop['phone']) ?>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3 py-1 fs-6">
                                                <?= $shop['staff_count'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3 py-1 fs-6">
                                                <?= $shop['products_count'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3 py-1 fs-6">
                                                <?= $shop['invoice_count'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($shop['is_active']): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success px-3 py-1">
                                                <i class="bx bx-circle me-1"></i>Active
                                            </span>
                                            <?php else: ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-1">
                                                <i class="bx bx-circle me-1"></i>Inactive
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" 
                                                        class="btn btn-outline-warning edit-shop-btn"
                                                        data-id="<?= $shop['id'] ?>"
                                                        data-shop_name="<?= htmlspecialchars($shop['shop_name']) ?>"
                                                        data-shop_code="<?= htmlspecialchars($shop['shop_code']) ?>"
                                                        data-address="<?= htmlspecialchars($shop['address'] ?? '') ?>"
                                                        data-phone="<?= htmlspecialchars($shop['phone'] ?? '') ?>"
                                                        data-gstin="<?= htmlspecialchars($shop['gstin'] ?? '') ?>"
                                                        data-is_warehouse="<?= $shop['is_warehouse'] ?>"
                                                        data-is_active="<?= $shop['is_active'] ?>"
                                                        data-bs-toggle="tooltip"
                                                        title="Edit Shop">
                                                    <i class="bx bx-edit"></i>
                                                </button>
                                                <a href="shops.php?delete=<?= $shop['id'] ?>" 
                                                   class="btn btn-outline-danger delete-btn"
                                                   onclick="return confirmDelete('<?= htmlspecialchars(addslashes($shop['shop_name'])) ?>', <?= $shop['staff_count'] ?>, <?= $shop['products_count'] ?>, <?= $shop['invoice_count'] ?>)"
                                                   data-bs-toggle="tooltip"
                                                   title="Delete Shop">
                                                    <i class="bx bx-trash"></i>
                                                </a>
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
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addShopModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-plus-circle me-2"></i> 
                    <span id="modalTitle">Add New Location</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="shopForm">
                <input type="hidden" name="id" id="shop_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Location Name <span class="text-danger">*</span></label>
                            <input type="text" name="shop_name" id="shop_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Location Code <span class="text-danger">*</span></label>
                            <input type="text" name="shop_code" id="shop_code" class="form-control text-uppercase" required maxlength="10">
                            <small class="text-muted">Unique code for this location (e.g., SHOP001, WH001)</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="phone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">GSTIN</label>
                            <input type="text" name="gstin" id="gstin" class="form-control text-uppercase">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_warehouse" id="is_warehouse" value="1">
                                <label class="form-check-label" for="is_warehouse">
                                    <strong class="text-info">This is a Warehouse</strong>
                                    <small class="text-muted d-block">Warehouses are used for stock storage and transfers</small>
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">
                                    <strong>Active Location</strong>
                                    <small class="text-muted d-block">Inactive locations won't appear in selection lists</small>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#shopsTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search in filtered results:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ locations",
            infoFiltered: "(filtered from <?= $total_shops ?> total locations)",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Edit button handler
    $('.edit-shop-btn').click(function() {
        $('#shop_id').val($(this).data('id'));
        $('#shop_name').val($(this).data('shop_name'));
        $('#shop_code').val($(this).data('shop_code'));
        $('#address').val($(this).data('address'));
        $('#phone').val($(this).data('phone'));
        $('#gstin').val($(this).data('gstin'));
        $('#is_warehouse').prop('checked', $(this).data('is_warehouse') == 1);
        $('#is_active').prop('checked', $(this).data('is_active') == 1);
        $('#modalTitle').text('Edit Location');
        
        const modal = new bootstrap.Modal(document.getElementById('addShopModal'));
        modal.show();
    });

    // Reset modal when closed
    $('#addShopModal').on('hidden.bs.modal', function () {
        $('#modalTitle').text('Add New Location');
        $(this).find('form')[0].reset();
        $('#shop_id').val('');
        $('#is_warehouse').prop('checked', false);
        $('#is_active').prop('checked', true);
    });

    // Real-time search debounce
    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => $('#filterForm').submit(), 500);
    });

    // Auto-submit on filter change (optional)
    $('select[name="type"], select[name="status"]').on('change', function() {
        $('#filterForm').submit();
    });

    // Row hover
    $('.shop-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // Export function
    window.exportShops = function() {
        const btn = event.target.closest('button');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
        btn.disabled = true;
        
        // Build export URL with current search parameters
        const params = new URLSearchParams(window.location.search);
        const exportUrl = 'shops_export.php' + (params.toString() ? '?' + params.toString() : '');
        
        window.location = exportUrl;
        
        // Reset button after 3 seconds
        setTimeout(() => {
            btn.innerHTML = original;
            btn.disabled = false;
        }, 3000);
    };

    // Print function
    window.printShops = function() {
        window.print();
    };

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

// Form validation
document.getElementById('shopForm').addEventListener('submit', function(e) {
    const shopName = this.querySelector('[name="shop_name"]');
    const shopCode = this.querySelector('[name="shop_code"]');
    
    if (!shopName.value.trim()) {
        e.preventDefault();
        alert('Please enter a location name!');
        shopName.focus();
        return;
    }
    
    if (!shopCode.value.trim()) {
        e.preventDefault();
        alert('Please enter a location code!');
        shopCode.focus();
        return;
    }
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Saving...';
    submitBtn.disabled = true;
    
    // Re-enable after 5 seconds if form doesn't submit
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 5000);
});

// Delete confirmation with warning
function confirmDelete(shopName, staffCount, productsCount, invoiceCount) {
    let message = `Are you sure you want to delete "${shopName}"?\n\n`;
    let warnings = [];
    
    if (staffCount > 0) {
        warnings.push(`• ${staffCount} user(s) are assigned to this shop`);
    }
    if (productsCount > 0) {
        warnings.push(`• ${productsCount} product stock record(s) exist`);
    }
    if (invoiceCount > 0) {
        warnings.push(`• ${invoiceCount} sales invoice(s) are linked`);
    }
    
    if (warnings.length > 0) {
        message += "WARNING: This shop has related data:\n" + warnings.join('\n') + "\n\n";
    }
    
    message += "This action cannot be undone!";
    
    return confirm(message);
}
</script>

<style>
.empty-state {
    text-align: center;
    padding: 2rem;
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
}
.btn-group .btn {
    padding: 0.375rem 0.75rem;
    font-size: 14px;
}
.btn-group .btn:hover {
    transform: translateY(-1px);
}
.form-switch .form-check-input:checked {
    background-color: #5b73e8;
    border-color: #5b73e8;
}
.modal-header {
    border-bottom: 1px solid #dee2e6;
}
.modal-footer {
    border-top: 1px solid #dee2e6;
}
.shop-row:hover .avatar-sm .bg-primary,
.shop-row:hover .avatar-sm .bg-info {
    transform: scale(1.1);
    transition: transform 0.3s ease;
}
@media (max-width: 768px) {
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .btn-group .btn {
        width: 100%;
    }
}
</style>
</body>
</html>