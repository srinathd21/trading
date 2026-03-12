<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'field_executive','seller','shop_manager'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$success = $error = '';

// === FILTERS ===
$city_filter = $_GET['city'] ?? '';
$executive_filter = $_GET['executive'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search_term = $_GET['search'] ?? '';

// === ADD / EDIT STORE ===
$edit_mode = false;
$store = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM stores WHERE id = ? AND business_id = ?");
    $stmt->execute([$edit_id, $business_id]);
    $store = $stmt->fetch();
    if ($store) $edit_mode = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = $_POST['id'] ?? 0;
        $store_code = trim($_POST['store_code']);
        $store_name = trim($_POST['store_name']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $owner_name = trim($_POST['owner_name'] ?? '');
        $whatsapp_number = trim($_POST['whatsapp_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $city = trim($_POST['city'] ?? 'Dharmapuri');
        $gstin = trim($_POST['gstin'] ?? '');
        $field_executive_id = !empty($_POST['field_executive_id']) ? (int)$_POST['field_executive_id'] : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($store_code) || empty($store_name) || empty($phone) || empty($address)) {
            throw new Exception("Required fields are missing");
        }
        
        if ($id > 0) {
            // Update store
            $stmt = $pdo->prepare("
                UPDATE stores SET 
                    store_name = ?, phone = ?, address = ?, owner_name = ?, 
                    whatsapp_number = ?, email = ?, city = ?, gstin = ?,
                    field_executive_id = ?, is_active = ?, updated_at = NOW()
                WHERE id = ? AND business_id = ?
            ");
            $stmt->execute([
                $store_name, $phone, $address, $owner_name, 
                $whatsapp_number, $email, $city, $gstin,
                $field_executive_id, $is_active, $id, $business_id
            ]);
            $success = "Store updated successfully!";
        } else {
            // Check if store code already exists
            $check = $pdo->prepare("SELECT id FROM stores WHERE store_code = ? AND business_id = ?");
            $check->execute([$store_code, $business_id]);
            if ($check->fetch()) {
                throw new Exception("Store code already exists!");
            }
            
            // Insert new store
            $stmt = $pdo->prepare("
                INSERT INTO stores (store_code, store_name, phone, address, owner_name, 
                                  whatsapp_number, email, city, gstin, field_executive_id, is_active, business_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $store_code, $store_name, $phone, $address, $owner_name, 
                $whatsapp_number, $email, $city, $gstin, $field_executive_id, $is_active, $business_id
            ]);
            $success = "Store added successfully!";
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Build WHERE conditions for filters
$where_conditions = ["s.business_id = ?"];
$params = [$business_id];

// City filter
if (!empty($city_filter) && $city_filter !== 'all') {
    $where_conditions[] = "s.city = ?";
    $params[] = $city_filter;
}

// Executive filter
if (!empty($executive_filter) && $executive_filter !== 'all') {
    $where_conditions[] = "s.field_executive_id = ?";
    $params[] = $executive_filter;
}

// Status filter
if ($status_filter === 'active') {
    $where_conditions[] = "s.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "s.is_active = 0";
}

// Search filter
if (!empty($search_term)) {
    $where_conditions[] = "(s.store_code LIKE ? OR s.store_name LIKE ? OR s.phone LIKE ? OR s.city LIKE ? OR u.full_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param; // for store_code
    $params[] = $search_param; // for store_name
    $params[] = $search_param; // for phone
    $params[] = $search_param; // for city
    $params[] = $search_param; // for executive name
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch stores with stats
$stores = $pdo->prepare("
    SELECT s.*, u.full_name AS executive_name,
           (SELECT COUNT(*) FROM store_visits WHERE store_id = s.id) as visit_count,
           (SELECT COUNT(*) FROM store_requirements sr
            JOIN store_visits sv ON sv.id = sr.store_visit_id
            WHERE sv.store_id = s.id) as requirement_count
    FROM stores s
    LEFT JOIN users u ON s.field_executive_id = u.id
    WHERE $where_clause
    ORDER BY s.store_name
");
$stores->execute($params);
$stores = $stores->fetchAll();

// Get distinct cities for filter dropdown
$cities = $pdo->query("SELECT DISTINCT city FROM stores WHERE business_id = $business_id AND city IS NOT NULL AND city != '' ORDER BY city")->fetchAll();

$executives = $pdo->query("SELECT id, full_name FROM users WHERE business_id = $business_id AND role = 'field_executive' AND is_active = 1 ORDER BY full_name")->fetchAll();

// Stats
$total_stores = count($stores);
$active_stores = count(array_filter($stores, fn($s) => $s['is_active']));
$total_visits = array_sum(array_column($stores, 'visit_count'));
$total_requirements = array_sum(array_column($stores, 'requirement_count'));
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Stores Management"; include 'includes/head.php'; ?>
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
                                <i class="bx bx-store me-2"></i> Stores Management
                                <small class="text-muted ms-2">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" onclick="exportStores()">
                                    <i class="bx bx-download me-1"></i> Export Excel
                                </button>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStoreModal">
                                    <i class="bx bx-plus-circle me-1"></i> Add Store
                                </button>
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
                            <i class="bx bx-filter-alt me-1"></i> Filter Stores
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Search Stores</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Store name, code, phone, city..."
                                               value="<?= htmlspecialchars($search_term) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">City</label>
                                    <select name="city" class="form-select">
                                        <option value="all">All Cities</option>
                                        <option value="">Unassigned</option>
                                        <?php foreach ($cities as $city): ?>
                                        <option value="<?= htmlspecialchars($city['city']) ?>" 
                                            <?= $city_filter == $city['city'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($city['city']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Executive</label>
                                    <select name="executive" class="form-select">
                                        <option value="all">All Executives</option>
                                        <option value="">Unassigned</option>
                                        <?php foreach ($executives as $exec): ?>
                                        <option value="<?= $exec['id'] ?>" 
                                            <?= $executive_filter == $exec['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($exec['full_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
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
                                <div class="col-lg-2 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($search_term || $city_filter || $executive_filter || $status_filter): ?>
                                        <a href="stores.php" class="btn btn-outline-secondary">
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
                                        <h6 class="text-muted mb-1">Total Stores</h6>
                                        <h3 class="mb-0 text-primary"><?= $total_stores ?></h3>
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
                                        <h6 class="text-muted mb-1">Active Stores</h6>
                                        <h3 class="mb-0 text-success"><?= $active_stores ?></h3>
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
                                        <h6 class="text-muted mb-1">Total Visits</h6>
                                        <h3 class="mb-0 text-warning"><?= $total_visits ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-map text-warning"></i>
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
                                        <h6 class="text-muted mb-1">Requirements</h6>
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
                </div>

                <!-- Stores Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="storesTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Store Details</th>
                                        <th class="text-center">Contact Info</th>
                                        <th class="text-center">Location</th>
                                        <th class="text-center">Field Executive</th>
                                        <th class="text-center">Activity Stats</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($stores)): ?>
                                    
                                    <?php else: ?>
                                    <?php foreach($stores as $i => $s): ?>
                                    <tr class="store-row" data-id="<?= $s['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx bx-store fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($s['store_name']) ?></strong>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-hash me-1"></i><?= htmlspecialchars($s['store_code']) ?>
                                                    </small>
                                                    <?php if ($s['owner_name']): ?>
                                                    <small class="text-muted">
                                                        <i class="bx bx-user me-1"></i><?= htmlspecialchars($s['owner_name']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                    <?php if ($s['gstin']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-barcode me-1"></i>GSTIN: <?= htmlspecialchars($s['gstin']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <a href="tel:<?= htmlspecialchars($s['phone']) ?>" 
                                                   class="text-decoration-none d-flex align-items-center justify-content-center">
                                                    <i class="bx bx-phone text-primary me-2"></i>
                                                    <?= htmlspecialchars($s['phone']) ?>
                                                </a>
                                            </div>
                                            <?php if ($s['whatsapp_number']): ?>
                                            <div>
                                                <a href="https://wa.me/91<?= preg_replace('/\D/', '', $s['whatsapp_number']) ?>" 
                                                   target="_blank" 
                                                   class="text-decoration-none d-flex align-items-center justify-content-center">
                                                    <i class="bx bxl-whatsapp text-success me-2"></i>
                                                    <?= htmlspecialchars($s['whatsapp_number']) ?>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($s['email']): ?>
                                            <div>
                                                <a href="mailto:<?= htmlspecialchars($s['email']) ?>" 
                                                   class="text-decoration-none d-flex align-items-center justify-content-center">
                                                    <i class="bx bx-envelope text-info me-2"></i>
                                                    <?= htmlspecialchars($s['email']) ?>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1">
                                                    <i class="bx bx-map-pin me-1"></i><?= htmlspecialchars($s['city']) ?>
                                                </span>
                                            </div>
                                            <?php if ($s['address']): ?>
                                            <small class="text-muted">
                                                <i class="bx bx-map me-1"></i><?= htmlspecialchars(substr($s['address'], 0, 40)) ?>
                                                <?= strlen($s['address']) > 40 ? '...' : '' ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($s['executive_name']): ?>
                                            <div class="mb-2">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                    <i class="bx bx-user-check me-1"></i><?= htmlspecialchars($s['executive_name']) ?>
                                                </span>
                                            </div>
                                            <?php else: ?>
                                            <span class="badge bg-light text-dark px-3 py-1">
                                                <i class="bx bx-user-x me-1"></i>Unassigned
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex flex-column align-items-center">
                                                <div class="mb-2">
                                                    <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 fs-6">
                                                        <i class="bx bx-map me-1"></i> <?= $s['visit_count'] ?>
                                                    </span>
                                                    <small class="text-muted d-block">Visits</small>
                                                </div>
                                                <div>
                                                    <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3 py-2 fs-6">
                                                        <i class="bx bx-clipboard me-1"></i> <?= $s['requirement_count'] ?>
                                                    </span>
                                                    <small class="text-muted d-block">Requirements</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-warning"
                                                        onclick="editStore(<?= $s['id'] ?>)"
                                                        data-bs-toggle="tooltip"
                                                        title="Edit Store">
                                                    <i class="bx bx-edit"></i>
                                                </button>
                                                <a href="store_visits.php?store_id=<?= $s['id'] ?>" 
                                                   class="btn btn-outline-info"
                                                   data-bs-toggle="tooltip"
                                                   title="View Visits">
                                                    <i class="bx bx-history"></i>
                                                </a>
                                                <?php if ($s['is_active']): ?>
                                                <span class="btn btn-outline-success" disabled
                                                      data-bs-toggle="tooltip" title="Active">
                                                    <i class="bx bx-check-circle"></i>
                                                </span>
                                                <?php else: ?>
                                                <span class="btn btn-outline-secondary" disabled
                                                      data-bs-toggle="tooltip" title="Inactive">
                                                    <i class="bx bx-x-circle"></i>
                                                </span>
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
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addStoreModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="storeForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-plus-circle me-2"></i>
                        <span id="modalTitle">Add New Store</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="storeId" value="<?= $store['id'] ?? '' ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Store Code <span class="text-danger">*</span></label>
                            <input type="text" name="store_code" id="storeCode" class="form-control" required 
                                   value="<?= $store['store_code'] ?? '' ?>" <?= $edit_mode ? 'readonly' : '' ?>>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Store Name <span class="text-danger">*</span></label>
                            <input type="text" name="store_name" id="storeName" class="form-control" required 
                                   value="<?= $store['store_name'] ?? '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Owner Name</label>
                            <input type="text" name="owner_name" id="ownerName" class="form-control" 
                                   value="<?= $store['owner_name'] ?? '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" id="phone" class="form-control" required 
                                   value="<?= $store['phone'] ?? '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">WhatsApp Number</label>
                            <input type="text" name="whatsapp_number" id="whatsappNumber" class="form-control" 
                                   value="<?= $store['whatsapp_number'] ?? '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control" 
                                   value="<?= $store['email'] ?? '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">City</label>
                            <input type="text" name="city" id="city" class="form-control" 
                                   value="<?= $store['city'] ?? 'Dharmapuri' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">GSTIN</label>
                            <input type="text" name="gstin" id="gstin" class="form-control" 
                                   value="<?= $store['gstin'] ?? '' ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address <span class="text-danger">*</span></label>
                            <textarea name="address" id="address" class="form-control" rows="3" required><?= $store['address'] ?? '' ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assigned Field Executive</label>
                            <select name="field_executive_id" id="fieldExecutiveId" class="form-select">
                                <option value="">None</option>
                                <?php foreach ($executives as $e): ?>
                                <option value="<?= $e['id'] ?>" 
                                    <?= ($store['field_executive_id'] ?? '') == $e['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($e['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">&nbsp;</label>
                            <div class="form-check mt-3">
                                <input type="checkbox" name="is_active" id="is_active" 
                                       class="form-check-input" <?= ($store['is_active'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">Active Store</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="bx bx-save me-2"></i>
                        <span id="submitText">Save Store</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#storesTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search in table:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ stores",
            infoFiltered: "(filtered from <?= $total_stores ?> total stores)",
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

    // Auto-submit on filter change (optional)
    $('select[name="city"], select[name="executive"], select[name="status"]').on('change', function() {
        $('#filterForm').submit();
    });

    // Row hover effect
    $('.store-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // Export function
    window.exportStores = function() {
        const btn = event.target.closest('button');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
        btn.disabled = true;
        
        // Build export URL with current search parameters
        const params = new URLSearchParams(window.location.search);
        const exportUrl = 'stores_export.php' + (params.toString() ? '?' + params.toString() : '');
        
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

// Edit store function
function editStore(storeId) {
    // Fetch store data via AJAX or redirect
    window.location.href = '?edit=' + storeId;
}

// Auto-open modal on edit
<?php if ($edit_mode): ?>
$(document).ready(function() {
    $('#modalTitle').text('Edit Store');
    $('#submitText').text('Update Store');
    const modal = new bootstrap.Modal(document.getElementById('addStoreModal'));
    modal.show();
});
<?php endif; ?>

// Reset modal on close
$('#addStoreModal').on('hidden.bs.modal', function () {
    $('#modalTitle').text('Add New Store');
    $('#submitText').text('Save Store');
    $('#storeId').val('');
    $('#storeForm')[0].reset();
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
}
.btn-group .btn {
    padding: 0.375rem 0.75rem;
    font-size: 14px;
}
.btn-group .btn:hover {
    transform: translateY(-1px);
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
.store-row:hover .avatar-sm .bg-primary {
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
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
}
</style>
</body>
</html>