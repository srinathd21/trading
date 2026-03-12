<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'field_executive','seller'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$success = $error = '';

// === GET STORE ID ===
$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if (!$store_id) {
    header('Location: stores.php');
    exit();
}

// Fetch store details
$stmt = $pdo->prepare("
    SELECT s.*, u.full_name AS executive_name 
    FROM stores s 
    LEFT JOIN users u ON s.field_executive_id = u.id 
    WHERE s.id = ? AND s.business_id = ?
");
$stmt->execute([$store_id, $business_id]);
$store = $stmt->fetch();

if (!$store) {
    header('Location: stores.php');
    exit();
}

// === FILTERS ===
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$visit_type_filter = $_GET['visit_type'] ?? '';
$executive_filter = $_GET['executive'] ?? '';

// === ADD / EDIT VISIT ===
$edit_mode = false;
$visit = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM store_visits WHERE id = ? AND store_id = ? AND business_id = ?");
    $stmt->execute([$edit_id, $store_id, $business_id]);
    $visit = $stmt->fetch();
    if ($visit) $edit_mode = true;
}

// === ADD REQUIREMENT ===
$add_req_mode = false;
if (isset($_GET['add_requirement'])) {
    $add_req_mode = true;
    $req_visit_id = (int)$_GET['add_requirement'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'add_visit') {
            // Add/Edit Visit
            $id = $_POST['id'] ?? 0;
            $visit_date = trim($_POST['visit_date']);
            $contact_person = trim($_POST['contact_person'] ?? '');
            $contact_phone = trim($_POST['contact_phone'] ?? '');
            $visit_type = $_POST['visit_type'] ?? 'regular';
            $visit_notes = trim($_POST['visit_notes'] ?? '');
            $next_followup_date = trim($_POST['next_followup_date'] ?? '');
            $field_executive_id = !empty($_POST['field_executive_id']) ? (int)$_POST['field_executive_id'] : $user_id;
            
            if (empty($visit_date)) {
                throw new Exception("Visit date is required");
            }
            
            if ($id > 0) {
                // Update visit
                $stmt = $pdo->prepare("
                    UPDATE store_visits SET 
                        visit_date = ?, contact_person = ?, phone = ?, visit_type = ?, 
                        visit_notes = ?, next_followup_date = ?, field_executive_id = ?
                    WHERE id = ? AND store_id = ? AND business_id = ?
                ");
                $stmt->execute([
                    $visit_date, $contact_person, $contact_phone, $visit_type,
                    $visit_notes, $next_followup_date ? $next_followup_date : null, $field_executive_id,
                    $id, $store_id, $business_id
                ]);
                $success = "Visit updated successfully!";
            } else {
                // Insert new visit
                $stmt = $pdo->prepare("
                    INSERT INTO store_visits (store_id, field_executive_id, visit_date, 
                        contact_person, phone, visit_type, visit_notes, next_followup_date, business_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $store_id, $field_executive_id, $visit_date,
                    $contact_person, $contact_phone, $visit_type,
                    $visit_notes, $next_followup_date ? $next_followup_date : null, $business_id
                ]);
                $success = "Visit added successfully!";
            }
            
        } elseif (isset($_POST['action']) && $_POST['action'] === 'add_requirement') {
            // Add requirement from visit
            $visit_id = (int)$_POST['visit_id'];
            $product_id = (int)$_POST['product_id'];
            $required_quantity = (int)$_POST['required_quantity'];
            $urgency = $_POST['urgency'] ?? 'medium';
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($product_id) || $required_quantity <= 0) {
                throw new Exception("Valid product and quantity are required");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO store_requirements (store_visit_id, product_id, field_executive_id, 
                    required_quantity, urgency, notes, business_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $visit_id, $product_id, $user_id,
                $required_quantity, $urgency, $notes, $business_id
            ]);
            
            // Update visit to mark that requirements were collected
            $update_stmt = $pdo->prepare("
                UPDATE store_visits SET collected_requirements = 1 
                WHERE id = ? AND business_id = ?
            ");
            $update_stmt->execute([$visit_id, $business_id]);
            
            $success = "Requirement added successfully!";
            
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_requirement_status') {
            // Update requirement status
            $req_id = (int)$_POST['requirement_id'];
            $new_status = $_POST['status'];
            $notes = trim($_POST['notes'] ?? '');
            
            $stmt = $pdo->prepare("
                UPDATE store_requirements SET 
                    status = ?,
                    notes = CONCAT(IFNULL(notes, ''), '\n[Status Update: ' || ? || ' at ' || NOW() || '] ' || ?)
                WHERE id = ? AND business_id = ?
            ");
            $stmt->execute([$new_status, $new_status, $notes, $req_id, $business_id]);
            $success = "Requirement status updated!";
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?store_id=" . $store_id);
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Build WHERE conditions for filters
$where_conditions = ["sv.store_id = ?", "sv.business_id = ?"];
$params = [$store_id, $business_id];

// Date filters
if (!empty($date_from)) {
    $where_conditions[] = "sv.visit_date >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where_conditions[] = "sv.visit_date <= ?";
    $params[] = $date_to;
}

// Visit type filter
if (!empty($visit_type_filter) && $visit_type_filter !== 'all') {
    $where_conditions[] = "sv.visit_type = ?";
    $params[] = $visit_type_filter;
}

// Executive filter
if (!empty($executive_filter) && $executive_filter !== 'all') {
    $where_conditions[] = "sv.field_executive_id = ?";
    $params[] = $executive_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch visits with requirements count
$visits = $pdo->prepare("
    SELECT sv.*, u.full_name AS executive_name,
           (SELECT COUNT(*) FROM store_requirements sr WHERE sr.store_visit_id = sv.id) as requirement_count,
           (SELECT COUNT(*) FROM store_requirements sr WHERE sr.store_visit_id = sv.id AND sr.status = 'pending') as pending_requirements,
           (SELECT COUNT(*) FROM store_requirements sr WHERE sr.store_visit_id = sv.id AND sr.status = 'fulfilled') as fulfilled_requirements
    FROM store_visits sv
    LEFT JOIN users u ON sv.field_executive_id = u.id
    WHERE $where_clause
    ORDER BY sv.visit_date DESC, sv.id DESC
");
$visits->execute($params);
$visits = $visits->fetchAll();

// Get distinct executives for filter dropdown
$executives = $pdo->query("
    SELECT DISTINCT u.id, u.full_name 
    FROM store_visits sv
    JOIN users u ON sv.field_executive_id = u.id
    WHERE sv.business_id = $business_id AND sv.store_id = $store_id
    ORDER BY u.full_name
")->fetchAll();

// Get products for requirements
$products = $pdo->query("
    SELECT p.id, p.product_name, p.product_code, p.retail_price, p.wholesale_price,
           c.category_name, s.subcategory_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id
    WHERE p.business_id = $business_id AND p.is_active = 1
    ORDER BY p.product_name
")->fetchAll();

// Fetch requirements for current store
$requirements = $pdo->prepare("
    SELECT sr.*, p.product_name, p.product_code, p.retail_price,
           sv.visit_date, u.full_name as executive_name,
           (SELECT full_name FROM users WHERE id = sr.approved_by) as approved_by_name,
           (SELECT full_name FROM users WHERE id = sr.packed_by) as packed_by_name,
           (SELECT full_name FROM users WHERE id = sr.shipped_by) as shipped_by_name
    FROM store_requirements sr
    JOIN products p ON sr.product_id = p.id
    JOIN store_visits sv ON sr.store_visit_id = sv.id
    LEFT JOIN users u ON sr.field_executive_id = u.id
    WHERE sv.store_id = ? AND sr.business_id = ?
    ORDER BY sr.created_at DESC
");
$requirements->execute([$store_id, $business_id]);
$requirements = $requirements->fetchAll();

// Stats
$total_visits = count($visits);
$total_requirements = array_sum(array_column($visits, 'requirement_count'));
$pending_requirements = array_sum(array_column($visits, 'pending_requirements'));
$fulfilled_requirements = array_sum(array_column($visits, 'fulfilled_requirements'));
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Store Visits - " . htmlspecialchars($store['store_name']); include 'includes/head.php'; ?>
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
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-map me-2"></i> Store Visits
                                </h4>
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="stores.php">Stores</a></li>
                                        <li class="breadcrumb-item active">
                                            <?= htmlspecialchars($store['store_name']) ?> 
                                            <small class="text-muted">(<?= htmlspecialchars($store['store_code']) ?>)</small>
                                        </li>
                                    </ol>
                                </nav>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="stores.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Stores
                                </a>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVisitModal">
                                    <i class="bx bx-plus-circle me-1"></i> Add Visit
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Store Info Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="card-title mb-3">
                                    <i class="bx bx-store me-2"></i><?= htmlspecialchars($store['store_name']) ?>
                                </h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-1">
                                            <i class="bx bx-user me-2 text-muted"></i>
                                            <strong>Owner:</strong> <?= htmlspecialchars($store['owner_name'] ?? 'N/A') ?>
                                        </p>
                                        <p class="mb-1">
                                            <i class="bx bx-phone me-2 text-muted"></i>
                                            <strong>Phone:</strong> 
                                            <a href="tel:<?= htmlspecialchars($store['phone']) ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($store['phone']) ?>
                                            </a>
                                        </p>
                                        <p class="mb-1">
                                            <i class="bx bx-map-pin me-2 text-muted"></i>
                                            <strong>Location:</strong> <?= htmlspecialchars($store['city']) ?>, <?= htmlspecialchars($store['state']) ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1">
                                            <i class="bx bx-user-check me-2 text-muted"></i>
                                            <strong>Field Executive:</strong> 
                                            <?= $store['executive_name'] ? htmlspecialchars($store['executive_name']) : '<span class="text-muted">Unassigned</span>' ?>
                                        </p>
                                        <p class="mb-1">
                                            <i class="bx bx-buildings me-2 text-muted"></i>
                                            <strong>Business:</strong> <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                        </p>
                                        <p class="mb-1">
                                            <i class="bx bx-calendar me-2 text-muted"></i>
                                            <strong>Created:</strong> <?= date('d M Y', strtotime($store['created_at'])) ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="d-flex flex-column h-100 justify-content-center">
                                    <?php if ($store['is_active']): ?>
                                    <span class="badge bg-success px-3 py-2 mb-2">
                                        <i class="bx bx-check-circle me-1"></i> Active Store
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary px-3 py-2 mb-2">
                                        <i class="bx bx-x-circle me-1"></i> Inactive
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($store['is_franchise']): ?>
                                    <span class="badge bg-info px-3 py-2">
                                        <i class="bx bx-star me-1"></i> Franchise
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Visits
                        </h5>
                        <form method="GET" id="filterForm">
                            <input type="hidden" name="store_id" value="<?= $store_id ?>">
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Date From</label>
                                    <input type="date" name="date_from" class="form-control" 
                                           value="<?= htmlspecialchars($date_from) ?>">
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Date To</label>
                                    <input type="date" name="date_to" class="form-control" 
                                           value="<?= htmlspecialchars($date_to) ?>">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Visit Type</label>
                                    <select name="visit_type" class="form-select">
                                        <option value="all">All Types</option>
                                        <option value="regular" <?= $visit_type_filter == 'regular' ? 'selected' : '' ?>>Regular</option>
                                        <option value="requirement_collection" <?= $visit_type_filter == 'requirement_collection' ? 'selected' : '' ?>>Requirement Collection</option>
                                        <option value="delivery" <?= $visit_type_filter == 'delivery' ? 'selected' : '' ?>>Delivery</option>
                                        <option value="followup" <?= $visit_type_filter == 'followup' ? 'selected' : '' ?>>Follow-up</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Executive</label>
                                    <select name="executive" class="form-select">
                                        <option value="all">All Executives</option>
                                        <?php foreach ($executives as $exec): ?>
                                        <option value="<?= $exec['id'] ?>" 
                                            <?= $executive_filter == $exec['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($exec['full_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($date_from || $date_to || $visit_type_filter || $executive_filter): ?>
                                        <a href="store_visits.php?store_id=<?= $store_id ?>" class="btn btn-outline-secondary">
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
                                        <h6 class="text-muted mb-1">Total Visits</h6>
                                        <h3 class="mb-0 text-primary"><?= $total_visits ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-map text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Total Requirements</h6>
                                        <h3 class="mb-0 text-info"><?= $total_requirements ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-clipboard text-info"></i>
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
                                        <h6 class="text-muted mb-1">Pending Requirements</h6>
                                        <h3 class="mb-0 text-warning"><?= $pending_requirements ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-time text-warning"></i>
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
                                        <h6 class="text-muted mb-1">Fulfilled Requirements</h6>
                                        <h3 class="mb-0 text-success"><?= $fulfilled_requirements ?></h3>
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
                </div>

                <!-- Tabs -->
                <ul class="nav nav-tabs nav-tabs-custom mb-4" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#visits" role="tab">
                            <i class="bx bx-map me-1"></i> Visits (<?= $total_visits ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#requirements" role="tab">
                            <i class="bx bx-clipboard me-1"></i> Requirements (<?= $total_requirements ?>)
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Visits Tab -->
                    <div class="tab-pane active" id="visits" role="tabpanel">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <?php if (empty($visits)): ?>
                                <div class="text-center py-5">
                                    <i class="bx bx-map bx-lg text-muted mb-3 d-block"></i>
                                    <h5 class="text-muted">No visits recorded yet</h5>
                                    <p class="text-muted mb-4">Start by adding the first visit to this store</p>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVisitModal">
                                        <i class="bx bx-plus-circle me-1"></i> Add First Visit
                                    </button>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table id="visitsTable" class="table table-hover align-middle w-100">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Visit Details</th>
                                                <th class="text-center">Contact Info</th>
                                                <th class="text-center">Executive</th>
                                                <th class="text-center">Requirements</th>
                                                <th class="text-center">Follow-up</th>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($visits as $v): ?>
                                            <tr>
                                                <td>
                                                    <div class="mb-1">
                                                        <strong class="d-block"><?= date('d M Y', strtotime($v['visit_date'])) ?></strong>
                                                        <span class="badge bg-<?= 
                                                            $v['visit_type'] == 'regular' ? 'primary' : 
                                                            ($v['visit_type'] == 'requirement_collection' ? 'info' : 
                                                            ($v['visit_type'] == 'delivery' ? 'success' : 'warning'))
                                                        ?> bg-opacity-10 text-<?= 
                                                            $v['visit_type'] == 'regular' ? 'primary' : 
                                                            ($v['visit_type'] == 'requirement_collection' ? 'info' : 
                                                            ($v['visit_type'] == 'delivery' ? 'success' : 'warning'))
                                                        ?> px-2 py-1">
                                                            <?= ucfirst(str_replace('_', ' ', $v['visit_type'])) ?>
                                                        </span>
                                                    </div>
                                                    <?php if ($v['visit_notes']): ?>
                                                    <small class="text-muted">
                                                        <i class="bx bx-note me-1"></i>
                                                        <?= htmlspecialchars(substr($v['visit_notes'], 0, 80)) ?>
                                                        <?= strlen($v['visit_notes']) > 80 ? '...' : '' ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($v['contact_person']): ?>
                                                    <div class="mb-2">
                                                        <i class="bx bx-user me-1 text-muted"></i>
                                                        <?= htmlspecialchars($v['contact_person']) ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($v['phone']): ?>
                                                    <div>
                                                        <a href="tel:<?= htmlspecialchars($v['phone']) ?>" 
                                                           class="text-decoration-none">
                                                            <i class="bx bx-phone me-1 text-muted"></i>
                                                            <?= htmlspecialchars($v['phone']) ?>
                                                        </a>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                        <i class="bx bx-user-check me-1"></i>
                                                        <?= htmlspecialchars($v['executive_name']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex flex-column align-items-center">
                                                        <div class="mb-2">
                                                            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 fs-6">
                                                                <i class="bx bx-clipboard me-1"></i> <?= $v['requirement_count'] ?>
                                                            </span>
                                                            <small class="text-muted d-block">Total</small>
                                                        </div>
                                                        <div class="d-flex gap-2">
                                                            <span class="badge bg-success bg-opacity-10 text-success px-2 py-1">
                                                                ✓ <?= $v['fulfilled_requirements'] ?>
                                                            </span>
                                                            <span class="badge bg-warning bg-opacity-10 text-warning px-2 py-1">
                                                                ⏱ <?= $v['pending_requirements'] ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($v['next_followup_date']): ?>
                                                    <div class="mb-2">
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1">
                                                            <i class="bx bx-calendar me-1"></i>
                                                            <?= date('d M', strtotime($v['next_followup_date'])) ?>
                                                        </span>
                                                    </div>
                                                    <?php if (strtotime($v['next_followup_date']) < strtotime('today')): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger small">
                                                        Overdue
                                                    </span>
                                                    <?php endif; ?>
                                                    <?php else: ?>
                                                    <span class="text-muted">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-info"
                                                                onclick="viewRequirements(<?= $v['id'] ?>)"
                                                                data-bs-toggle="tooltip"
                                                                title="View Requirements">
                                                            <i class="bx bx-clipboard"></i>
                                                        </button>
                                                        <button class="btn btn-outline-primary"
                                                                onclick="editVisit(<?= $v['id'] ?>)"
                                                                data-bs-toggle="tooltip"
                                                                title="Edit Visit">
                                                            <i class="bx bx-edit"></i>
                                                        </button>
                                                        <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'field_executive'): ?>
                                                        <button class="btn btn-outline-success"
                                                                onclick="addRequirement(<?= $v['id'] ?>)"
                                                                data-bs-toggle="tooltip"
                                                                title="Add Requirement">
                                                            <i class="bx bx-plus"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Requirements Tab -->
                    <div class="tab-pane" id="requirements" role="tabpanel">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <?php if (empty($requirements)): ?>
                                <div class="text-center py-5">
                                    <i class="bx bx-clipboard bx-lg text-muted mb-3 d-block"></i>
                                    <h5 class="text-muted">No requirements recorded</h5>
                                    <p class="text-muted mb-4">Requirements will appear here when added during visits</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table id="requirementsTable" class="table table-hover align-middle w-100">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Product Details</th>
                                                <th class="text-center">Visit Info</th>
                                                <th class="text-center">Quantity & Urgency</th>
                                                <th class="text-center">Status</th>
                                                <th class="text-center">Created</th>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($requirements as $r): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm me-3">
                                                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                                 style="width: 40px; height: 40px;">
                                                                <i class="bx bx-package fs-4"></i>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <strong class="d-block mb-1"><?= htmlspecialchars($r['product_name']) ?></strong>
                                                            <?php if ($r['product_code']): ?>
                                                            <small class="text-muted d-block">
                                                                <i class="bx bx-hash me-1"></i><?= htmlspecialchars($r['product_code']) ?>
                                                            </small>
                                                            <?php endif; ?>
                                                            <small class="text-muted">
                                                                <i class="bx bx-rupee me-1"></i>₹<?= number_format($r['retail_price'], 2) ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="mb-1">
                                                        <small class="text-muted d-block">Visit Date:</small>
                                                        <strong><?= date('d M Y', strtotime($r['visit_date'])) ?></strong>
                                                    </div>
                                                    <?php if ($r['executive_name']): ?>
                                                    <div>
                                                        <small class="text-muted d-block">By:</small>
                                                        <span class="badge bg-info bg-opacity-10 text-info">
                                                            <?= htmlspecialchars($r['executive_name']) ?>
                                                        </span>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="mb-2">
                                                        <span class="badge bg-primary rounded-pill px-3 py-2 fs-6">
                                                            <?= $r['required_quantity'] ?> units
                                                        </span>
                                                    </div>
                                                    <span class="badge bg-<?= 
                                                        $r['urgency'] == 'high' ? 'danger' : 
                                                        ($r['urgency'] == 'medium' ? 'warning' : 'secondary')
                                                    ?> bg-opacity-10 text-<?= 
                                                        $r['urgency'] == 'high' ? 'danger' : 
                                                        ($r['urgency'] == 'medium' ? 'warning' : 'secondary')
                                                    ?> px-3 py-1">
                                                        <?= ucfirst($r['urgency']) ?> priority
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php 
                                                    $status_color = [
                                                        'pending' => 'warning',
                                                        'approved' => 'info',
                                                        'packed' => 'primary',
                                                        'shipped' => 'secondary',
                                                        'delivered' => 'success',
                                                        'cancelled' => 'danger',
                                                        'fulfilled' => 'success'
                                                    ][$r['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> px-3 py-1">
                                                        <?= ucfirst($r['status']) ?>
                                                    </span>
                                                    <?php if ($r['approved_by_name']): ?>
                                                    <div class="mt-1">
                                                        <small class="text-muted">Approved by: <?= htmlspecialchars($r['approved_by_name']) ?></small>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?= date('d M Y', strtotime($r['created_at'])) ?>
                                                    <br>
                                                    <small class="text-muted"><?= date('h:i A', strtotime($r['created_at'])) ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($_SESSION['role'] === 'admin'): ?>
                                                        <button class="btn btn-outline-warning"
                                                                onclick="updateRequirementStatus(<?= $r['id'] ?>)"
                                                                data-bs-toggle="tooltip"
                                                                title="Update Status">
                                                            <i class="bx bx-edit"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php if ($r['notes']): ?>
                                                        <button class="btn btn-outline-info"
                                                                onclick="viewNotes('<?= htmlspecialchars(addslashes($r['notes'])) ?>')"
                                                                data-bs-toggle="tooltip"
                                                                title="View Notes">
                                                            <i class="bx bx-note"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add/Edit Visit Modal -->
<div class="modal fade" id="addVisitModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="visitForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-plus-circle me-2"></i>
                        <span id="modalTitle">Add Store Visit</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="visitId" value="<?= $visit['id'] ?? '' ?>">
                    <input type="hidden" name="action" value="add_visit">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Visit Date <span class="text-danger">*</span></label>
                            <input type="date" name="visit_date" id="visitDate" class="form-control" required 
                                   value="<?= $visit['visit_date'] ?? date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Visit Type</label>
                            <select name="visit_type" id="visitType" class="form-select">
                                <option value="regular" <?= ($visit['visit_type'] ?? 'regular') == 'regular' ? 'selected' : '' ?>>Regular Visit</option>
                                <option value="requirement_collection" <?= ($visit['visit_type'] ?? '') == 'requirement_collection' ? 'selected' : '' ?>>Requirement Collection</option>
                                <option value="delivery" <?= ($visit['visit_type'] ?? '') == 'delivery' ? 'selected' : '' ?>>Delivery</option>
                                <option value="followup" <?= ($visit['visit_type'] ?? '') == 'followup' ? 'selected' : '' ?>>Follow-up</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" id="contactPerson" class="form-control" 
                                   value="<?= $visit['contact_person'] ?? '' ?>" 
                                   placeholder="Name of person met">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Phone</label>
                            <input type="text" name="contact_phone" id="contactPhone" class="form-control" 
                                   value="<?= $visit['phone'] ?? '' ?>" 
                                   placeholder="Phone number of contact">
                        </div>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                        <div class="col-md-6">
                            <label class="form-label">Field Executive</label>
                            <select name="field_executive_id" id="fieldExecutiveId" class="form-select">
                                <option value="">Select Executive</option>
                                <?php foreach ($executives as $e): ?>
                                <option value="<?= $e['id'] ?>" 
                                    <?= ($visit['field_executive_id'] ?? $user_id) == $e['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($e['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="field_executive_id" value="<?= $user_id ?>">
                        <?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label">Next Follow-up Date</label>
                            <input type="date" name="next_followup_date" id="nextFollowupDate" class="form-control" 
                                   value="<?= $visit['next_followup_date'] ?? '' ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Visit Notes</label>
                            <textarea name="visit_notes" id="visitNotes" class="form-control" rows="4" 
                                      placeholder="Details of the visit, discussions, observations..."><?= $visit['visit_notes'] ?? '' ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="bx bx-save me-2"></i>
                        <span id="submitText">Save Visit</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Requirement Modal -->
<div class="modal fade" id="addRequirementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="requirementForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-plus-circle me-2"></i>
                        Add Requirement from Visit
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_requirement">
                    <input type="hidden" name="visit_id" id="reqVisitId" value="<?= $req_visit_id ?? '' ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Product <span class="text-danger">*</span></label>
                            <select name="product_id" id="productId" class="form-select select2" required>
                                <option value="">Select Product</option>
                                <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['product_name']) ?> 
                                    (<?= htmlspecialchars($p['product_code']) ?>) 
                                    - ₹<?= number_format($p['retail_price'], 2) ?>
                                    <?= $p['category_name'] ? ' - ' . htmlspecialchars($p['category_name']) : '' ?>
                                    <?= $p['subcategory_name'] ? ' > ' . htmlspecialchars($p['subcategory_name']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="required_quantity" id="requiredQuantity" 
                                   class="form-control" min="1" value="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Urgency</label>
                            <select name="urgency" id="urgency" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="reqNotes" class="form-control" rows="3" 
                                      placeholder="Additional details about this requirement..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bx bx-save me-2"></i> Add Requirement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Requirement Status Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="statusForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-edit me-2"></i>
                        Update Requirement Status
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_requirement_status">
                    <input type="hidden" name="requirement_id" id="statusReqId" value="">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">New Status <span class="text-danger">*</span></label>
                            <select name="status" id="newStatus" class="form-select" required>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="packed">Packed</option>
                                <option value="shipped">Shipped</option>
                                <option value="delivered">Delivered</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="fulfilled">Fulfilled</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Status Notes</label>
                            <textarea name="notes" id="statusNotes" class="form-control" rows="3" 
                                      placeholder="Reason for status change or additional notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bx bx-save me-2"></i> Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Notes View Modal -->
<div class="modal fade" id="notesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-note me-2"></i>
                    Requirement Notes
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="notesContent" class="p-3 bg-light rounded"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#visitsTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search visits:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ visits",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    $('#requirementsTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[4, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search requirements:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ requirements",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Initialize Select2 for product search
    $('.select2').select2({
        placeholder: "Search product...",
        width: '100%'
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Auto-submit on filter change
    $('select[name="visit_type"], select[name="executive"]').on('change', function() {
        $('#filterForm').submit();
    });

    // Row hover effect
    $('#visitsTable tbody tr, #requirementsTable tbody tr').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Set min date for follow-up date to today
    $('#nextFollowupDate').attr('min', new Date().toISOString().split('T')[0]);
});

// Edit visit function
function editVisit(visitId) {
    window.location.href = '?store_id=<?= $store_id ?>&edit=' + visitId;
}

// Add requirement function
function addRequirement(visitId) {
    $('#reqVisitId').val(visitId);
    const modal = new bootstrap.Modal(document.getElementById('addRequirementModal'));
    modal.show();
}

// View requirements for a visit
function viewRequirements(visitId) {
    // Switch to requirements tab and filter by visit
    $('.nav-tabs a[href="#requirements"]').tab('show');
    $('#requirementsTable').DataTable().search(visitId).draw();
}

// Update requirement status
function updateRequirementStatus(reqId) {
    $('#statusReqId').val(reqId);
    const modal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
    modal.show();
}

// View notes
function viewNotes(notes) {
    $('#notesContent').text(notes);
    const modal = new bootstrap.Modal(document.getElementById('notesModal'));
    modal.show();
}

// Auto-open modals based on URL parameters
<?php if ($edit_mode): ?>
$(document).ready(function() {
    $('#modalTitle').text('Edit Visit');
    $('#submitText').text('Update Visit');
    const modal = new bootstrap.Modal(document.getElementById('addVisitModal'));
    modal.show();
});
<?php endif; ?>

<?php if ($add_req_mode): ?>
$(document).ready(function() {
    const modal = new bootstrap.Modal(document.getElementById('addRequirementModal'));
    modal.show();
});
<?php endif; ?>

// Reset modals on close
$('#addVisitModal').on('hidden.bs.modal', function () {
    $('#modalTitle').text('Add Store Visit');
    $('#submitText').text('Save Visit');
    $('#visitId').val('');
    $('#visitForm')[0].reset();
    $('#visitDate').val('<?= date('Y-m-d') ?>');
});

$('#addRequirementModal').on('hidden.bs.modal', function () {
    $('#requirementForm')[0].reset();
    $('#requiredQuantity').val(1);
    $('#urgency').val('medium');
});

$('#updateStatusModal').on('hidden.bs.modal', function () {
    $('#statusForm')[0].reset();
    $('#statusReqId').val('');
});
</script>

<style>
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
.nav-tabs-custom .nav-link {
    font-weight: 500;
    padding: 0.75rem 1.5rem;
}
.nav-tabs-custom .nav-link.active {
    border-bottom: 3px solid #0d6efd;
}
.avatar-sm {
    width: 40px;
    height: 40px;
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
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
    border-radius: 0.375rem;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
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
    .nav-tabs-custom .nav-link {
        padding: 0.5rem 1rem;
        font-size: 14px;
    }
}
</style>
</body>
</html>