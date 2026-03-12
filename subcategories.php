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
$user_role = $_SESSION['role'] ?? '';
$is_admin = in_array($user_role, ['admin', 'shop_manager', 'stock_manager','stock_manager']);
$success = $error = '';
$categories = [];
$subcategories = [];
// Fetch all categories for dropdown
$cat_stmt = $pdo->prepare("
    SELECT id, category_name, category_code
    FROM categories
    WHERE business_id = ? AND status = 'active' AND parent_id IS NULL
    ORDER BY category_name
");
$cat_stmt->execute([$current_business_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_subcategory'])) {
        $category_id = (int)$_POST['category_id'];
        $subcategory_name = trim($_POST['subcategory_name']);
        $subcategory_code = trim($_POST['subcategory_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';
        // Validate
        if (empty($subcategory_name)) {
            $error = "Subcategory name is required.";
        } elseif (empty($category_id)) {
            $error = "Please select a category.";
        } else {
            try {
                // Check if subcategory already exists in this category
                $check_stmt = $pdo->prepare("
                    SELECT id FROM subcategories
                    WHERE business_id = ? AND category_id = ? AND subcategory_name = ?
                ");
                $check_stmt->execute([$current_business_id, $category_id, $subcategory_name]);
               
                if ($check_stmt->fetch()) {
                    $error = "Subcategory '$subcategory_name' already exists in this category.";
                } else {
                    // Generate code if not provided
                    if (empty($subcategory_code)) {
                        $subcategory_code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $subcategory_name), 0, 8));
                        $counter = 1;
                        while (true) {
                            $code_check = $pdo->prepare("SELECT id FROM subcategories WHERE business_id = ? AND subcategory_code = ?");
                            $code_check->execute([$current_business_id, $subcategory_code]);
                            if (!$code_check->fetch()) break;
                            $subcategory_code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $subcategory_name), 0, 6)) . $counter;
                            $counter++;
                        }
                    }
                    $stmt = $pdo->prepare("
                        INSERT INTO subcategories
                        (business_id, category_id, subcategory_name, subcategory_code, description, status, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $current_business_id,
                        $category_id,
                        $subcategory_name,
                        $subcategory_code,
                        $description,
                        $status,
                        $_SESSION['user_id']
                    ]);
                   
                    $success = "Subcategory '$subcategory_name' added successfully!";
                }
            } catch (PDOException $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
   
    // Handle update
    if (isset($_POST['update_subcategory'])) {
        $subcategory_id = (int)$_POST['subcategory_id'];
        $subcategory_name = trim($_POST['subcategory_name']);
        $subcategory_code = trim($_POST['subcategory_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';
        if (empty($subcategory_name)) {
            $error = "Subcategory name is required.";
        } else {
            try {
                // Check for duplicate name (excluding current)
                $check_stmt = $pdo->prepare("
                    SELECT id FROM subcategories
                    WHERE business_id = ? AND id != ? AND category_id = ? AND subcategory_name = ?
                ");
                $check_stmt->execute([
                    $current_business_id,
                    $subcategory_id,
                    $_POST['category_id'],
                    $subcategory_name
                ]);
               
                if ($check_stmt->fetch()) {
                    $error = "Subcategory '$subcategory_name' already exists in this category.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE subcategories
                        SET subcategory_name = ?, subcategory_code = ?, description = ?, status = ?, updated_at = NOW()
                        WHERE id = ? AND business_id = ?
                    ");
                    $stmt->execute([
                        $subcategory_name,
                        $subcategory_code,
                        $description,
                        $status,
                        $subcategory_id,
                        $current_business_id
                    ]);
                   
                    $success = "Subcategory updated successfully!";
                }
            } catch (PDOException $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}
// Handle delete
if (isset($_GET['delete'])) {
    $subcategory_id = (int)$_GET['delete'];
   
    try {
        // Check if subcategory has products
        $check_stmt = $pdo->prepare("
            SELECT COUNT(*) as product_count FROM products
            WHERE subcategory_id = ? AND business_id = ?
        ");
        $check_stmt->execute([$subcategory_id, $current_business_id]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
       
        if ($result['product_count'] > 0) {
            $error = "Cannot delete subcategory: It has " . $result['product_count'] . " product(s). Update products first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM subcategories WHERE id = ? AND business_id = ?");
            $stmt->execute([$subcategory_id, $current_business_id]);
            $success = "Subcategory deleted successfully!";
        }
    } catch (PDOException $e) {
        $error = "Error deleting subcategory: " . $e->getMessage();
    }
}
// Handle bulk actions
if (isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
   
    if (empty($selected_ids)) {
        $error = "Please select at least one subcategory.";
    } else {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
       
        switch ($action) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE subcategories SET status = 'active' WHERE id IN ($placeholders) AND business_id = ?");
                $params = array_merge($selected_ids, [$current_business_id]);
                $stmt->execute($params);
                $success = count($selected_ids) . " subcategory(s) activated.";
                break;
               
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE subcategories SET status = 'inactive' WHERE id IN ($placeholders) AND business_id = ?");
                $params = array_merge($selected_ids, [$current_business_id]);
                $stmt->execute($params);
                $success = count($selected_ids) . " subcategory(s) deactivated.";
                break;
               
            case 'delete':
                // Check if any subcategory has products
                $check_stmt = $pdo->prepare("
                    SELECT COUNT(*) as product_count FROM products
                    WHERE subcategory_id IN ($placeholders) AND business_id = ?
                ");
                $check_params = array_merge($selected_ids, [$current_business_id]);
                $check_stmt->execute($check_params);
                $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
               
                if ($result['product_count'] > 0) {
                    $error = "Cannot delete: Some subcategories have products assigned.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM subcategories WHERE id IN ($placeholders) AND business_id = ?");
                    $params = array_merge($selected_ids, [$current_business_id]);
                    $stmt->execute($params);
                    $success = count($selected_ids) . " subcategory(s) deleted.";
                }
                break;
        }
    }
}
// Fetch all subcategories with category info and product counts
$filter_category = $_GET['category'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$where_conditions = ["s.business_id = ?"];
$params = [$current_business_id];
if ($filter_category) {
    $where_conditions[] = "s.category_id = ?";
    $params[] = $filter_category;
}
if ($filter_status && in_array($filter_status, ['active', 'inactive'])) {
    $where_conditions[] = "s.status = ?";
    $params[] = $filter_status;
}
if ($search) {
    $where_conditions[] = "(s.subcategory_name LIKE ? OR s.subcategory_code LIKE ? OR c.category_name LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$subcat_sql = "
    SELECT
        s.*,
        c.category_name,
        c.category_code,
        (SELECT COUNT(*) FROM products p WHERE p.subcategory_id = s.id AND p.business_id = s.business_id) as product_count,
        u.full_name as created_by_name
    FROM subcategories s
    LEFT JOIN categories c ON s.category_id = c.id AND s.business_id = c.business_id
    LEFT JOIN users u ON s.created_by = u.id
    $where_sql
    ORDER BY c.category_name, s.subcategory_name
";
$stmt = $pdo->prepare($subcat_sql);
$stmt->execute($params);
$subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Get statistics
$stats_sql = "
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
        SUM((SELECT COUNT(*) FROM products p WHERE p.subcategory_id = s.id)) as total_products
    FROM subcategories s
    WHERE s.business_id = ?
";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([$current_business_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Manage Subcategories"; ?>
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
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-layer me-2"></i> Manage Subcategories
                                </h4>
                                <p class="text-muted mb-0">
                                    Organize your products into subcategories for better management
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubcategoryModal">
                                    <i class="bx bx-plus me-1"></i> Add Subcategory
                                </button>
                                <a href="categories.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-category me-1"></i> Manage Categories
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Subcategories</h6>
                                        <h3 class="mb-0 text-primary"><?= $stats['total'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-layer text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Active</h6>
                                        <h3 class="mb-0 text-success"><?= $stats['active'] ?? 0 ?></h3>
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
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Inactive</h6>
                                        <h3 class="mb-0 text-warning"><?= $stats['inactive'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-x-circle text-warning"></i>
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
                                        <h6 class="text-muted mb-1">Total Products</h6>
                                        <h3 class="mb-0 text-info"><?= $stats['total_products'] ?? 0 ?></h3>
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
                </div>
                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Subcategories
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
                                               placeholder="Subcategory name or code"
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"
                                            <?= $filter_category == $cat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['category_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="active" <?= $filter_status == 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= $filter_status == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6 d-flex align-items-end">
                                    <div class="d-flex gap-2 w-100">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($search || $filter_category || $filter_status): ?>
                                        <a href="subcategories.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- Subcategories Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show mb-4">
                            <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">
                                <i class="bx bx-list-ul me-1"></i> Subcategory List
                            </h5>
                            <form method="POST" class="d-flex gap-2" id="bulkActionForm">
                                <select name="bulk_action" class="form-select form-select-sm" style="width: auto;">
                                    <option value="">Bulk Actions</option>
                                    <option value="activate">Activate Selected</option>
                                    <option value="deactivate">Deactivate Selected</option>
                                    <option value="delete">Delete Selected</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bx bx-play-circle me-1"></i> Apply
                                </button>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table id="subcategoriesTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="selectAll">
                                            </div>
                                        </th>
                                        <th>Subcategory</th>
                                        <th>Category</th>
                                        <th>Code</th>
                                        <th class="text-center">Products</th>
                                        <th>Description</th>
                                        <th>Created By</th>
                                        <th>Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($subcategories)): ?>
                                   
                                    <?php else: ?>
                                    <?php foreach ($subcategories as $subcat):
                                        $status_class = $subcat['status'] == 'active' ? 'success' : 'danger';
                                        $status_icon = $subcat['status'] == 'active' ? 'bx-check-circle' : 'bx-x-circle';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input select-checkbox" type="checkbox"
                                                       name="selected_ids[]" value="<?= $subcat['id'] ?>">
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="avatar-title bg-primary bg-opacity-10 text-primary rounded">
                                                        <i class="bx bx-subdirectory-right fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($subcat['subcategory_name']) ?></strong>
                                                    <small class="text-muted">
                                                        Created: <?= date('M d, Y', strtotime($subcat['created_at'])) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary bg-opacity-10 text-primary">
                                                <?= htmlspecialchars($subcat['category_name']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($subcat['subcategory_code'])): ?>
                                            <span class="badge bg-light text-dark"><?= htmlspecialchars($subcat['subcategory_code']) ?></span>
                                            <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $subcat['product_count'] > 0 ? 'info' : 'secondary' ?> rounded-pill px-3 py-2">
                                                <?= $subcat['product_count'] ?>
                                            </span>
                                        </td>
                                        <td class="text-muted small">
                                            <?= $subcat['description'] ? htmlspecialchars(substr($subcat['description'], 0, 50)) . (strlen($subcat['description']) > 50 ? '...' : '') : '—' ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($subcat['created_by_name'] ?? 'System') ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $status_class ?> bg-opacity-10 text-<?= $status_class ?> px-3 py-1">
                                                <i class="bx <?= $status_icon ?> me-1"></i>
                                                <?= ucfirst($subcat['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button"
                                                        class="btn btn-outline-warning edit-btn"
                                                        data-id="<?= $subcat['id'] ?>"
                                                        data-name="<?= htmlspecialchars($subcat['subcategory_name']) ?>"
                                                        data-code="<?= htmlspecialchars($subcat['subcategory_code']) ?>"
                                                        data-description="<?= htmlspecialchars($subcat['description']) ?>"
                                                        data-status="<?= $subcat['status'] ?>"
                                                        data-category-id="<?= $subcat['category_id'] ?>"
                                                        data-bs-toggle="tooltip"
                                                        title="Edit">
                                                    <i class="bx bx-edit"></i>
                                                </button>
                                                <?php if ($subcat['product_count'] == 0): ?>
                                                <a href="?delete=<?= $subcat['id'] ?>"
                                                   class="btn btn-outline-danger delete-subcategory"
                                                   data-bs-toggle="tooltip"
                                                   title="Delete">
                                                    <i class="bx bx-trash"></i>
                                                </a>
                                                <?php else: ?>
                                                <button type="button"
                                                        class="btn btn-outline-danger disabled"
                                                        data-bs-toggle="tooltip"
                                                        title="Cannot delete: Has <?= $subcat['product_count'] ?> product(s)">
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
<!-- Add/Edit Modal -->
<div class="modal fade" id="addSubcategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">
                    <i class="bx bx-plus-circle"></i> Add Subcategory
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="subcategoryForm">
                <input type="hidden" name="subcategory_id" id="editSubcategoryId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><strong>Category <span class="text-danger">*</span></strong></label>
                        <select name="category_id" id="categoryId" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><strong>Subcategory Name <span class="text-danger">*</span></strong></label>
                        <input type="text" name="subcategory_name" id="subcategoryName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subcategory Code</label>
                        <input type="text" name="subcategory_code" id="subcategoryCode" class="form-control"
                               placeholder="Auto-generated if left empty">
                        <small class="text-muted">Unique identifier for the subcategory</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="status" id="statusActive" value="active" checked>
                                <label class="form-check-label" for="statusActive">Active</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="status" id="statusInactive" value="inactive">
                                <label class="form-check-label" for="statusInactive">Inactive</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_subcategory" class="btn btn-primary" id="submitBtn">
                        <i class="bx bx-save me-2"></i> Add Subcategory
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
$(document).ready(function() {
    // Initialize DataTables
    var table = $('#subcategoriesTable').DataTable({
        responsive: true,
        pageLength: 25,
        searching: false, // Disable built-in search to avoid conflict with custom filter form
        order: [[1, 'asc']], // Default sort by Subcategory name
        columnDefs: [
            { orderable: false, targets: [0, 8] } // Disable sorting on checkbox and actions columns
        ],
        language: {
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ subcategories",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Select all checkboxes (current page only)
    $('#selectAll').change(function() {
        $('.select-checkbox').prop('checked', this.checked);
    });

    // Update selectAll state on page draw or individual checkbox change
    table.on('draw', function() {
        var checkedCount = $('.select-checkbox:checked').length;
        var visibleCount = $('.select-checkbox').length;
        if (checkedCount === 0) {
            $('#selectAll').prop('checked', false).prop('indeterminate', false);
        } else if (checkedCount === visibleCount) {
            $('#selectAll').prop('checked', true).prop('indeterminate', false);
        } else {
            $('#selectAll').prop('checked', false).prop('indeterminate', true);
        }
    });

    $(document).on('change', '.select-checkbox', function() {
        var checkedCount = $('.select-checkbox:checked').length;
        var visibleCount = $('.select-checkbox').length;
        if (checkedCount === 0) {
            $('#selectAll').prop('checked', false).prop('indeterminate', false);
        } else if (checkedCount === visibleCount) {
            $('#selectAll').prop('checked', true).prop('indeterminate', false);
        } else {
            $('#selectAll').prop('checked', false).prop('indeterminate', true);
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // SweetAlert2 Toast configuration
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });

    // Bulk action form submission
    $('#bulkActionForm').submit(function(e) {
        const selectedCount = $('.select-checkbox:checked').length;
        const action = $('select[name="bulk_action"]').val();
       
        if (!action) {
            e.preventDefault();
            Toast.fire({
                icon: 'warning',
                title: 'Please select a bulk action'
            });
            return;
        }
       
        if (selectedCount === 0) {
            e.preventDefault();
            Toast.fire({
                icon: 'warning',
                title: 'Please select at least one subcategory'
            });
            return;
        }
       
        if (action === 'delete') {
            e.preventDefault();
            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to delete ${selectedCount} subcategory(s)`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete them!',
                cancelButtonText: 'Cancel',
                toast: true,
                position: 'top-end',
                showConfirmButton: true,
                timer: 3000,
                timerProgressBar: true,
                customClass: {
                    container: 'swal-toast-small'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Submit the form
                    $('#bulkActionForm')[0].submit();
                }
            });
        }
    });

    // Delete button handler
    $('.delete-subcategory').on('click', function(e) {
        e.preventDefault();
        let deleteUrl = $(this).attr('href');
        
        Swal.fire({
            title: 'Are you sure?',
            text: "You are about to delete this subcategory",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            toast: true,
            position: 'top-end',
            showConfirmButton: true,
            timer: 3000,
            timerProgressBar: true,
            customClass: {
                container: 'swal-toast-small'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = deleteUrl;
            }
        });
    });

    // Show success/error messages as SweetAlert toasts
    <?php if ($success): ?>
    Toast.fire({
        icon: 'success',
        title: '<?= addslashes($success) ?>'
    });
    <?php endif; ?>

    <?php if ($error): ?>
    Toast.fire({
        icon: 'error',
        title: '<?= addslashes($error) ?>'
    });
    <?php endif; ?>

    // Edit button handler
    $('.edit-btn').click(function() {
        const subcategoryId = $(this).data('id');
        const subcategoryName = $(this).data('name');
        const subcategoryCode = $(this).data('code');
        const description = $(this).data('description');
        const status = $(this).data('status');
        const categoryId = $(this).data('category-id');
        // Update modal for editing
        $('#modalTitle').html('<i class="bx bx-edit"></i> Edit Subcategory');
        $('#editSubcategoryId').val(subcategoryId);
        $('#subcategoryName').val(subcategoryName);
        $('#subcategoryCode').val(subcategoryCode);
        $('#description').val(description);
        $('#categoryId').val(categoryId);
       
        // Set status radio
        if (status === 'active') {
            $('#statusActive').prop('checked', true);
        } else {
            $('#statusInactive').prop('checked', true);
        }
       
        // Update submit button
        $('#submitBtn').html('<i class="bx bx-save me-2"></i> Update Subcategory');
        $('#submitBtn').attr('name', 'update_subcategory');
       
        // Show modal
        const modal = new bootstrap.Modal('#addSubcategoryModal');
        modal.show();
    });

    // Reset modal when closed
    $('#addSubcategoryModal').on('hidden.bs.modal', function () {
        $('#modalTitle').html('<i class="bx bx-plus-circle"></i> Add Subcategory');
        $('#editSubcategoryId').val('');
        $('#subcategoryForm')[0].reset();
        $('#submitBtn').html('<i class="bx bx-save me-2"></i> Add Subcategory');
        $('#submitBtn').attr('name', 'add_subcategory');
        $('#categoryId').val('');
    });

    // Auto-generate code from name
    $('#subcategoryName').on('blur', function() {
        if (!$('#subcategoryCode').val()) {
            const name = $(this).val();
            const code = name.replace(/[^a-zA-Z0-9]/g, '').toUpperCase().substring(0, 8);
            $('#subcategoryCode').val(code);
        }
    });

    // Form validation
    $('#subcategoryForm').submit(function(e) {
        const categoryId = $('#categoryId').val();
        const subcategoryName = $('#subcategoryName').val().trim();
       
        if (!categoryId) {
            e.preventDefault();
            Toast.fire({
                icon: 'error',
                title: 'Please select a category'
            });
            return false;
        }
       
        if (!subcategoryName) {
            e.preventDefault();
            Toast.fire({
                icon: 'error',
                title: 'Subcategory name is required'
            });
            return false;
        }
       
        return true;
    });

    // Auto-hide alerts
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Real-time search
    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => $('#filterForm').submit(), 500);
    });

    // Enter key in search triggers submit
    $('input[name="search"]').on('keypress', function(e) {
        if (e.which === 13) {
            $('#filterForm').submit();
        }
    });
});
</script>
<style>
.card-hover { transition: transform 0.2s ease, box-shadow 0.2s ease; }
.card-hover:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important; }
.table th { font-weight: 600; }
.btn-group .btn { border-radius: 4px !important; }
.empty-state { padding: 3rem; }
.avatar-title { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; }
.table-hover tbody tr:hover { background-color: #f8f9fa; }
.form-check-input:checked { background-color: #5b73e8; border-color: #5b73e8; }

/* SweetAlert2 small toast customization */
.swal-toast-small {
    font-size: 0.875rem !important;
}
.swal-toast-small .swal2-popup {
    font-size: 0.875rem !important;
    padding: 0.5rem !important;
    width: auto !important;
    min-width: 250px !important;
}
.swal-toast-small .swal2-title {
    font-size: 1rem !important;
    margin: 0 !important;
    padding: 0 0 0.25rem 0 !important;
}
.swal-toast-small .swal2-html-container {
    font-size: 0.875rem !important;
    margin: 0 !important;
    padding: 0 !important;
}
.swal-toast-small .swal2-actions {
    margin: 0.25rem 0 0 0 !important;
}
.swal-toast-small .swal2-confirm,
.swal-toast-small .swal2-cancel {
    font-size: 0.75rem !important;
    padding: 0.25rem 0.5rem !important;
}
</style>
</body>
</html>