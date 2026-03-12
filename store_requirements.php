<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$business_id = $_SESSION['business_id'] ?? 1;
$allowed_roles = ['admin', 'seller', 'staff', 'warehouse_manager', 'field_executive','shop_manager'];

if (!in_array($user_role, $allowed_roles)) {
    header('Location: dashboard.php');
    exit();
}

// Handle AJAX Delete Action
if (isset($_POST['ajax_delete']) && $_POST['ajax_delete'] == '1' && isset($_POST['visit_id'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }
    
    if (!in_array($user_role, ['field_executive', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to delete visits']);
        exit();
    }
    
    $visit_id = (int)$_POST['visit_id'];
    
    // Check if visit belongs to current business and can be deleted
    $stmt = $pdo->prepare("
        SELECT sv.id 
        FROM store_visits sv 
        LEFT JOIN store_requirements sr ON sr.store_visit_id = sv.id
        JOIN stores s ON sv.store_id = s.id
        WHERE sv.id = ? 
        AND s.business_id = ?
        AND (sv.field_executive_id = ? OR ? = 'admin')
        AND sr.invoice_id IS NULL 
        AND sr.requirement_status = 'pending'
    ");
    $stmt->execute([$visit_id, $business_id, $user_id, $user_role]);
    
    if ($stmt->fetch()) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM store_requirements WHERE store_visit_id = ?")->execute([$visit_id]);
            $pdo->prepare("DELETE FROM store_visits WHERE id = ?")->execute([$visit_id]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Visit deleted successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to delete visit: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Cannot delete visit: Invalid or non-pending visit']);
    }
    exit();
}

// Handle AJAX Approve Action
if (isset($_POST['ajax_approve']) && $_POST['ajax_approve'] == '1' && isset($_POST['visit_id'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }
    
    if (!in_array($user_role, ['admin', 'seller'])) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to approve items']);
        exit();
    }
    
    $visit_id = (int)$_POST['visit_id'];

    try {
        $pdo->beginTransaction();

        // Verify visit exists and belongs to current business
        $check_stmt = $pdo->prepare("
            SELECT 1
            FROM store_visits sv
            JOIN store_requirements sr ON sr.store_visit_id = sv.id
            JOIN stores s ON sv.store_id = s.id
            WHERE sv.id = ? 
            AND s.business_id = ?
            AND sr.requirement_status = 'pending'
        ");
        $check_stmt->execute([$visit_id, $business_id]);
        
        if ($check_stmt->fetch()) {
            $stmt = $pdo->prepare("
                UPDATE store_requirements
                SET requirement_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE store_visit_id = ? AND requirement_status = 'pending'
            ");
            $stmt->execute([$user_id, $visit_id]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Items approved successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No pending items found for this visit']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to approve items: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX Status Update
if (isset($_POST['ajax_update']) && $_POST['ajax_update'] == '1' && isset($_POST['visit_id'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }
    
    if (!in_array($user_role, ['admin', 'staff', 'warehouse_manager'])) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to update status']);
        exit();
    }
    
    $visit_id = (int)$_POST['visit_id'];
    $new_status = $_POST['new_status'] ?? '';
    $tracking_number = $_POST['tracking_number'] ?? null;
    
    $allowed_statuses = ['packed', 'shipped', 'delivered'];
    if (!in_array($new_status, $allowed_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status selected']);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Verify visit exists and belongs to current business
        $check_stmt = $pdo->prepare("
            SELECT 1
            FROM store_visits sv
            JOIN store_requirements sr ON sr.store_visit_id = sv.id
            JOIN stores s ON sv.store_id = s.id
            WHERE sv.id = ? 
            AND s.business_id = ?
            AND sr.requirement_status IN ('approved', 'packed', 'shipped')
        ");
        $check_stmt->execute([$visit_id, $business_id]);

        if ($check_stmt->fetch()) {
            $update_data = [
                'requirement_status' => $new_status,
                $new_status . '_by' => $user_id,
                $new_status . '_at' => date('Y-m-d H:i:s'),
                'store_visit_id' => $visit_id
            ];
            
            $query = "
                UPDATE store_requirements
                SET requirement_status = :requirement_status,
                    {$new_status}_by = :{$new_status}_by,
                    {$new_status}_at = :{$new_status}_at
            ";
            
            if ($new_status === 'shipped' && $tracking_number) {
                $query .= ", tracking_number = :tracking_number";
                $update_data['tracking_number'] = $tracking_number;
            }
            
            $query .= " WHERE store_visit_id = :store_visit_id AND requirement_status IN ('approved', 'packed', 'shipped')";

            $stmt = $pdo->prepare($query);
            $stmt->execute($update_data);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Status updated to $new_status successfully"]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No eligible items to update']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $e->getMessage()]);
    }
    exit();
}

// === FILTERS ===
$store_filter = (int)($_GET['store'] ?? 0);
$executive_filter = (int)($_GET['executive'] ?? 0);
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$where = "WHERE s.business_id = ?";
$params = [$business_id];

if ($user_role === 'field_executive') {
    $where .= " AND sv.field_executive_id = ?";
    $params[] = $user_id;
    $executive_filter = $user_id;
}
if ($store_filter > 0) { 
    $where .= " AND sv.store_id = ?"; 
    $params[] = $store_filter; 
}
if ($user_role !== 'field_executive' && $executive_filter > 0) { 
    $where .= " AND sv.field_executive_id = ?"; 
    $params[] = $executive_filter; 
}
if ($date_from) { 
    $where .= " AND DATE(sv.visit_date) >= ?"; 
    $params[] = $date_from; 
}
if ($date_to) { 
    $where .= " AND DATE(sv.visit_date) <= ?"; 
    $params[] = $date_to; 
}

// Status filter
if ($status_filter) {
    $status_conditions = [
        'pending' => "AND EXISTS (SELECT 1 FROM store_requirements sr2 WHERE sr2.store_visit_id = sv.id AND sr2.requirement_status = 'pending')",
        'approved' => "AND EXISTS (SELECT 1 FROM store_requirements sr2 WHERE sr2.store_visit_id = sv.id AND sr2.requirement_status = 'approved')",
        'packed' => "AND EXISTS (SELECT 1 FROM store_requirements sr2 WHERE sr2.store_visit_id = sv.id AND sr2.requirement_status = 'packed')",
        'shipped' => "AND EXISTS (SELECT 1 FROM store_requirements sr2 WHERE sr2.store_visit_id = sv.id AND sr2.requirement_status = 'shipped')",
        'delivered' => "AND EXISTS (SELECT 1 FROM store_requirements sr2 WHERE sr2.store_visit_id = sv.id AND sr2.requirement_status = 'delivered')"
    ];
    $where .= $status_conditions[$status_filter] ?? '';
}

// Fetch visits
$visits = $pdo->prepare("
    SELECT
        sv.id, sv.visit_date, sv.created_at, sv.visit_type, sv.next_followup_date,
        s.store_code, s.store_name, s.city,
        u.full_name AS executive_name,
        COUNT(sr.id) AS total_items,
        SUM(CASE WHEN sr.requirement_status = 'pending' THEN 1 ELSE 0 END) AS pending_items,
        SUM(CASE WHEN sr.requirement_status = 'approved' THEN 1 ELSE 0 END) AS approved_items,
        SUM(CASE WHEN sr.requirement_status = 'packed' THEN 1 ELSE 0 END) AS packed_items,
        SUM(CASE WHEN sr.requirement_status = 'shipped' THEN 1 ELSE 0 END) AS shipped_items,
        SUM(CASE WHEN sr.requirement_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_items,
        MAX(CASE WHEN sr.invoice_id IS NOT NULL THEN 1 ELSE 0 END) AS has_invoice,
        MAX(packer.full_name) AS packed_by_name,
        MAX(sr.packed_at) AS packed_date,
        MAX(shipper.full_name) AS shipped_by_name,
        MAX(sr.shipped_at) AS shipped_date,
        MAX(sr.tracking_number) AS tracking_number,
        MAX(approver.full_name) AS approved_by_name,
        MAX(sr.approved_at) AS approved_date
    FROM store_visits sv
    JOIN stores s ON sv.store_id = s.id
    JOIN users u ON sv.field_executive_id = u.id
    LEFT JOIN store_requirements sr ON sr.store_visit_id = sv.id
    LEFT JOIN users packer ON sr.packed_by = packer.id
    LEFT JOIN users shipper ON sr.shipped_by = shipper.id
    LEFT JOIN users approver ON sr.approved_by = approver.id
    $where
    GROUP BY sv.id
    ORDER BY sv.visit_date DESC, sv.created_at DESC
");
$visits->execute($params);
$visits = $visits->fetchAll();

// Filters data - Business based
$stores = $pdo->prepare("SELECT id, store_code, store_name FROM stores WHERE business_id = ? AND is_active = 1 ORDER BY store_name");
$stores->execute([$business_id]);
$stores = $stores->fetchAll();

if ($user_role !== 'field_executive') {
    $executives = $pdo->prepare("SELECT id, full_name FROM users WHERE business_id = ? AND role = 'field_executive' AND is_active = 1 ORDER BY full_name");
    $executives->execute([$business_id]);
    $executives = $executives->fetchAll();
} else {
    $executives = [];
}

// Stats
$total_visits = count($visits);
$total_items = array_sum(array_column($visits, 'total_items'));
$pending_items = array_sum(array_column($visits, 'pending_items'));
$delivered_items = array_sum(array_column($visits, 'delivered_items'));

// Messages
$success = $_SESSION['success'] ?? ''; unset($_SESSION['success']);
$error = $_SESSION['error'] ?? ''; unset($_SESSION['error']);
?>
<!doctype html>
<html lang="en">
<?php 
$page_title = $user_role === 'field_executive' ? "My Store Visits" : "Store Visits & Requirements"; 
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
                                <i class="bx bx-map me-2"></i> <?= $page_title ?>
                                <small class="text-muted ms-2">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <?php if ($user_role === 'field_executive' || $user_role === 'admin'): ?>
                                <a href="store_visit_form.php" class="btn btn-primary">
                                    <i class="bx bx-plus-circle me-1"></i> New Visit
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Visits
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                                </div>
                                <?php if ($user_role !== 'field_executive'): ?>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Store</label>
                                    <select name="store" class="form-select">
                                        <option value="">All Stores</option>
                                        <?php foreach($stores as $s): ?>
                                        <option value="<?= $s['id'] ?>" <?= $store_filter == $s['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['store_code']) ?> - <?= htmlspecialchars($s['store_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Executive</label>
                                    <select name="executive" class="form-select">
                                        <option value="">All Executives</option>
                                        <?php foreach($executives as $e): ?>
                                        <option value="<?= $e['id'] ?>" <?= $executive_filter == $e['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($e['full_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="pending" <?= $status_filter=='pending'?'selected':'' ?>>Pending</option>
                                        <option value="approved" <?= $status_filter=='approved'?'selected':'' ?>>Approved</option>
                                        <option value="packed" <?= $status_filter=='packed'?'selected':'' ?>>Packed</option>
                                        <option value="shipped" <?= $status_filter=='shipped'?'selected':'' ?>>Shipped</option>
                                        <option value="delivered" <?= $status_filter=='delivered'?'selected':'' ?>>Delivered</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="col-lg-3 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($date_from != date('Y-m-01') || $date_to != date('Y-m-d') || $store_filter || $executive_filter || $status_filter): ?>
                                        <a href="store_requirements.php" class="btn btn-outline-secondary">
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
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Items</h6>
                                        <h3 class="mb-0 text-success"><?= $total_items ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-package text-success"></i>
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
                                        <h6 class="text-muted mb-1">Pending Items</h6>
                                        <h3 class="mb-0 text-warning"><?= $pending_items ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-time-five text-warning"></i>
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
                                        <h6 class="text-muted mb-1">Delivered Items</h6>
                                        <h3 class="mb-0 text-info"><?= $delivered_items ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-truck text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visits Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="visitsTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Visit Details</th>
                                        <?php if ($user_role !== 'field_executive'): ?><th class="text-center">Executive</th><?php endif; ?>
                                        <th class="text-center">Items Status</th>
                                        <th class="text-center">Process Flow</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($visits)): ?>
                                    
                                    <?php else: ?>
                                    <?php foreach($visits as $i => $v): 
                                        // Determine overall status
                                        $status = 'pending';
                                        if ($v['total_items'] > 0) {
                                            if ($v['delivered_items'] == $v['total_items']) $status = 'delivered';
                                            elseif ($v['shipped_items'] > 0) $status = 'shipped';
                                            elseif ($v['packed_items'] > 0) $status = 'packed';
                                            elseif ($v['approved_items'] == $v['total_items']) $status = 'approved';
                                        }
                                        
                                        $status_color = [
                                            'pending' => 'warning',
                                            'approved' => 'info', 
                                            'packed' => 'primary',
                                            'shipped' => 'dark',
                                            'delivered' => 'success'
                                        ];
                                        $status_icon = [
                                            'pending' => 'bx-time-five',
                                            'approved' => 'bx-check-circle',
                                            'packed' => 'bx-package',
                                            'shipped' => 'bx-send',
                                            'delivered' => 'bx-check-double'
                                        ];
                                    ?>
                                    <tr class="visit-row" data-id="<?= $v['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-<?= $status_color[$status] ?> bg-opacity-10 text-<?= $status_color[$status] ?> rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx <?= $status_icon[$status] ?> fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($v['store_name']) ?></strong>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-hash me-1"></i><?= $v['store_code'] ?> | <?= $v['city'] ?>
                                                    </small>
                                                    <small class="text-muted">
                                                        <i class="bx bx-calendar me-1"></i><?= date('d M Y', strtotime($v['visit_date'])) ?>
                                                    </small>
                                                    <?php if ($v['visit_type']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-tag me-1"></i><?= ucfirst($v['visit_type']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <?php if ($user_role !== 'field_executive'): ?>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                    <i class="bx bx-user-check me-1"></i><?= htmlspecialchars($v['executive_name']) ?>
                                                </span>
                                            </div>
                                            <small class="text-muted">
                                                <i class="bx bx-time me-1"></i><?= date('h:i A', strtotime($v['created_at'])) ?>
                                            </small>
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 fs-6">
                                                    <i class="bx bx-package me-1"></i> <?= $v['total_items'] ?>
                                                </span>
                                                <small class="text-muted d-block">Total Items</small>
                                            </div>
                                            <div class="d-flex justify-content-center gap-2 flex-wrap">
                                                <?php if ($v['pending_items'] > 0): ?>
                                                <span class="badge bg-warning bg-opacity-10 text-warning px-2 py-1">
                                                    <?= $v['pending_items'] ?> Pending
                                                </span>
                                                <?php endif; ?>
                                                <?php if ($v['approved_items'] > 0): ?>
                                                <span class="badge bg-info bg-opacity-10 text-info px-2 py-1">
                                                    <?= $v['approved_items'] ?> Approved
                                                </span>
                                                <?php endif; ?>
                                                <?php if ($v['packed_items'] > 0): ?>
                                                <span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1">
                                                    <?= $v['packed_items'] ?> Packed
                                                </span>
                                                <?php endif; ?>
                                                <?php if ($v['shipped_items'] > 0): ?>
                                                <span class="badge bg-dark bg-opacity-10 text-dark px-2 py-1">
                                                    <?= $v['shipped_items'] ?> Shipped
                                                </span>
                                                <?php endif; ?>
                                                <?php if ($v['delivered_items'] > 0): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success px-2 py-1">
                                                    <?= $v['delivered_items'] ?> Delivered
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="process-flow">
                                                <div class="d-flex justify-content-center align-items-center gap-3 mb-2">
                                                    <?php if ($v['approved_by_name']): ?>
                                                    <div class="text-center">
                                                        <i class="bx bx-check-circle text-success fs-4"></i>
                                                        <small class="d-block text-muted"><?= date('d M', strtotime($v['approved_date'])) ?></small>
                                                        <small class="d-block"><?= htmlspecialchars($v['approved_by_name']) ?></small>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($v['packed_by_name']): ?>
                                                    <div class="text-center">
                                                        <i class="bx bx-package text-primary fs-4"></i>
                                                        <small class="d-block text-muted"><?= date('d M', strtotime($v['packed_date'])) ?></small>
                                                        <small class="d-block"><?= htmlspecialchars($v['packed_by_name']) ?></small>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($v['shipped_by_name']): ?>
                                                    <div class="text-center">
                                                        <i class="bx bx-send text-dark fs-4"></i>
                                                        <small class="d-block text-muted"><?= date('d M', strtotime($v['shipped_date'])) ?></small>
                                                        <small class="d-block">
                                                            <?= htmlspecialchars($v['shipped_by_name']) ?>
                                                            <?php if ($v['tracking_number']): ?>
                                                            <br><small class="text-muted">#<?= htmlspecialchars($v['tracking_number']) ?></small>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($v['next_followup_date']): ?>
                                                <div class="mt-2">
                                                    <small class="text-muted">
                                                        <i class="bx bx-calendar-event me-1"></i>
                                                        Next Follow-up: <?= date('d M Y', strtotime($v['next_followup_date'])) ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-info"
                                                        onclick="viewVisit(<?= $v['id'] ?>)"
                                                        data-bs-toggle="tooltip"
                                                        title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </button>
                                                
                                                <?php if ($user_role === 'field_executive' && $status === 'pending'): ?>
                                                <a href="store_visit_form.php?edit=<?= $v['id'] ?>" 
                                                   class="btn btn-outline-warning"
                                                   data-bs-toggle="tooltip"
                                                   title="Edit Visit">
                                                    <i class="bx bx-edit"></i>
                                                </a>
                                                <?php endif; ?>
                                                
                                                <?php if (($user_role === 'field_executive' && $status === 'pending') || $user_role === 'admin'): ?>
                                                <?php if (!$v['has_invoice']): ?>
                                                <button class="btn btn-outline-danger delete-visit" 
                                                        data-id="<?= $v['id'] ?>"
                                                        data-csrf="<?= $_SESSION['csrf_token'] ?>"
                                                        data-bs-toggle="tooltip" 
                                                        title="Delete Visit">
                                                    <i class="bx bx-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if (in_array($user_role, ['admin', 'seller']) && $v['pending_items'] > 0): ?>
                                                <button class="btn btn-success btn-sm approve-visit"
                                                        data-id="<?= $v['id'] ?>"
                                                        data-csrf="<?= $_SESSION['csrf_token'] ?>"
                                                        data-bs-toggle="tooltip" 
                                                        title="Approve Items">
                                                    <i class="bx bx-check"></i> Approve
                                                </button>
                                                <?php endif; ?>
                                                
                                                <?php if (in_array($user_role, ['admin', 'staff', 'warehouse_manager']) && in_array($status, ['approved', 'packed', 'shipped'])): ?>
                                                <div class="btn-group btn-group-sm ms-1">
                                                    <button type="button" 
                                                            class="btn btn-primary dropdown-toggle" 
                                                            data-bs-toggle="dropdown"
                                                            aria-expanded="false">
                                                        <i class="bx bx-sync"></i> Update
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <?php if ($status == 'approved'): ?>
                                                        <li>
                                                            <button class="dropdown-item update-status" 
                                                                    data-id="<?= $v['id'] ?>"
                                                                    data-status="packed"
                                                                    data-csrf="<?= $_SESSION['csrf_token'] ?>">
                                                                <i class="bx bx-package me-2 text-primary"></i> Mark as Packed
                                                            </button>
                                                        </li>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($status == 'packed'): ?>
                                                        <li>
                                                            <div class="px-3 py-2">
                                                                <input type="text" id="tracking_<?= $v['id'] ?>" 
                                                                       class="form-control form-control-sm mb-2" 
                                                                       placeholder="Tracking Number">
                                                                <button class="btn btn-primary btn-sm w-100 update-status-with-tracking"
                                                                        data-id="<?= $v['id'] ?>"
                                                                        data-status="shipped"
                                                                        data-csrf="<?= $_SESSION['csrf_token'] ?>">
                                                                    <i class="bx bx-send me-2"></i> Mark as Shipped
                                                                </button>
                                                            </div>
                                                        </li>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($status == 'shipped'): ?>
                                                        <li>
                                                            <button class="dropdown-item update-status"
                                                                    data-id="<?= $v['id'] ?>"
                                                                    data-status="delivered"
                                                                    data-csrf="<?= $_SESSION['csrf_token'] ?>">
                                                                <i class="bx bx-check-double me-2 text-success"></i> Mark as Delivered
                                                            </button>
                                                        </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
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

<!-- View Modal -->
<div class="modal fade" id="visitModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-map me-2"></i> Visit Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="visitDetails">Loading...</div>
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
            infoFiltered: "(filtered from <?= $total_visits ?> total visits)",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Delete visit with AJAX
    $(document).on('click', '.delete-visit', function() {
        const visitId = $(this).data('id');
        const csrfToken = $(this).data('csrf');
        const button = $(this);
        
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                button.html('<i class="bx bx-loader bx-spin"></i>').prop('disabled', true);
                
                // Send AJAX request
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        ajax_delete: 1,
                        visit_id: visitId,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message
                            });
                            button.html('<i class="bx bx-trash"></i>').prop('disabled', false);
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Failed to connect to server'
                        });
                        button.html('<i class="bx bx-trash"></i>').prop('disabled', false);
                    }
                });
            }
        });
    });

    // Approve visit with AJAX
    $(document).on('click', '.approve-visit', function() {
        const visitId = $(this).data('id');
        const csrfToken = $(this).data('csrf');
        const button = $(this);
        
        Swal.fire({
            title: 'Approve Items?',
            text: "Are you sure you want to approve all pending items in this visit?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, approve them!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                button.html('<i class="bx bx-loader bx-spin"></i>').prop('disabled', true);
                
                // Send AJAX request
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        ajax_approve: 1,
                        visit_id: visitId,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Approved!',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message
                            });
                            button.html('<i class="bx bx-check"></i> Approve').prop('disabled', false);
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Failed to connect to server'
                        });
                        button.html('<i class="bx bx-check"></i> Approve').prop('disabled', false);
                    }
                });
            }
        });
    });

    // Update status without tracking (packed, delivered)
    $(document).on('click', '.update-status', function() {
        const visitId = $(this).data('id');
        const newStatus = $(this).data('status');
        const csrfToken = $(this).data('csrf');
        const button = $(this);
        
        let statusText = '';
        let statusIcon = '';
        
        switch(newStatus) {
            case 'packed':
                statusText = 'packed';
                statusIcon = '📦';
                break;
            case 'delivered':
                statusText = 'delivered';
                statusIcon = '✅';
                break;
            default:
                statusText = newStatus;
        }
        
        Swal.fire({
            title: `Mark as ${statusText}?`,
            text: `Are you sure you want to mark this visit as ${statusText}?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#007bff',
            cancelButtonColor: '#6c757d',
            confirmButtonText: `Yes, mark as ${statusText}!`,
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                button.html('<i class="bx bx-loader bx-spin"></i>').prop('disabled', true);
                
                // Send AJAX request
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        ajax_update: 1,
                        visit_id: visitId,
                        new_status: newStatus,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Updated!',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message
                            });
                            location.reload();
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Failed to connect to server'
                        });
                        location.reload();
                    }
                });
            }
        });
    });

    // Update status with tracking (shipped)
    $(document).on('click', '.update-status-with-tracking', function() {
        const visitId = $(this).data('id');
        const newStatus = $(this).data('status');
        const csrfToken = $(this).data('csrf');
        const trackingNumber = $('#tracking_' + visitId).val();
        const button = $(this);
        
        if (!trackingNumber) {
            Swal.fire({
                icon: 'error',
                title: 'Tracking Number Required',
                text: 'Please enter a tracking number before marking as shipped.',
                confirmButtonColor: '#dc3545'
            });
            return;
        }
        
        Swal.fire({
            title: 'Mark as Shipped?',
            html: `Are you sure you want to mark this visit as shipped with tracking number: <strong>${trackingNumber}</strong>?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#007bff',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, mark as shipped!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                button.html('<i class="bx bx-loader bx-spin"></i>').prop('disabled', true);
                
                // Send AJAX request
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        ajax_update: 1,
                        visit_id: visitId,
                        new_status: newStatus,
                        tracking_number: trackingNumber,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Shipped!',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message
                            });
                            location.reload();
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Failed to connect to server'
                        });
                        location.reload();
                    }
                });
            }
        });
    });

    // Auto-submit on filter change (optional)
    $('select[name="store"], select[name="executive"], select[name="status"]').on('change', function() {
        $('#filterForm').submit();
    });
});

// View visit details
function viewVisit(id) {
    $('#visitDetails').html('<div class="text-center p-5"><i class="bx bx-loader bx-spin fs-1 text-primary"></i><p class="mt-3">Loading visit details...</p></div>');
    
    fetch('ajax_store_visit_details.php?id=' + id)
        .then(response => response.text())
        .then(html => {
            $('#visitDetails').html(html);
            new bootstrap.Modal('#visitModal').show();
        })
        .catch(error => {
            $('#visitDetails').html('<div class="alert alert-danger">Failed to load visit details. Please try again.</div>');
        });
}
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
.process-flow {
    min-width: 200px;
}
.visit-row:hover .avatar-sm .rounded-circle {
    transform: scale(1.1);
    transition: transform 0.3s ease;
}
.dropdown-menu {
    min-width: 250px;
    padding: 10px;
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
    .btn-group form {
        width: 100%;
    }
    .btn-group select, 
    .btn-group input[type="text"] {
        width: 100% !important;
        margin-bottom: 5px;
    }
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
    .process-flow {
        min-width: 150px;
    }
    .dropdown-menu {
        position: fixed;
        left: 10px !important;
        right: 10px !important;
        width: auto !important;
    }
}
</style>
</body>
</html>