<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$transfer_id = $_GET['id'] ?? 0;
$success = $error = '';
$can_approve = false;

// Check permissions
$current_user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// Who can approve? Only admin and warehouse_manager
$can_approve = ($current_user_role == 'admin' || $current_user_role == 'warehouse_manager');

// Fetch transfer details
$stmt = $pdo->prepare("
    SELECT t.*, 
           f.location_name as from_location,
           f.location_type as from_type,
           t2.location_name as to_location,
           t2.location_type as to_type,
           u.full_name as requested_by,
           u2.full_name as approved_by_name,
           u.phone as requester_phone
    FROM stock_transfers t
    JOIN stock_locations f ON t.from_location_id = f.id
    JOIN stock_locations t2 ON t.to_location_id = t2.id
    JOIN users u ON t.requested_by = u.id
    LEFT JOIN users u2 ON t.approved_by = u2.id
    WHERE t.id = ?
");
$stmt->execute([$transfer_id]);
$transfer = $stmt->fetch();

if (!$transfer) {
    header('Location: transfer_stock.php');
    exit();
}

// Fetch transfer items
$items = $pdo->prepare("
    SELECT sti.*, 
           p.product_name, p.product_code, p.stock_price,
           ps.quantity as available_stock
    FROM stock_transfer_items sti
    JOIN products p ON sti.product_id = p.id
    LEFT JOIN product_stocks ps ON ps.product_id = p.id AND ps.location_id = ?
    WHERE sti.stock_transfer_id = ?
");
$items->execute([$transfer['from_location_id'], $transfer_id]);
$items = $items->fetchAll();

// Calculate totals
$total_items = 0;
$total_qty = 0;
$estimated_value = 0;
foreach ($items as $item) {
    $total_items++;
    $total_qty += $item['quantity'];
    $estimated_value += ($item['stock_price'] * $item['quantity']);
}

// Process actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        switch ($action) {
            case 'approve':
                if (!$can_approve) {
                    throw new Exception("You don't have permission to approve transfers.");
                }
                if ($transfer['status'] != 'pending') {
                    throw new Exception("Transfer is not in pending status.");
                }
                
                // Update transfer status
                $stmt = $pdo->prepare("
                    UPDATE stock_transfers 
                    SET status = 'approved', 
                        approved_by = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $transfer_id]);
                
                // Process stock movements
                foreach ($items as $item) {
                    // Check current stock at source location (warehouse)
                    $stmt = $pdo->prepare("SELECT quantity FROM product_stocks WHERE product_id = ? AND location_id = ?");
                    $stmt->execute([$item['product_id'], $transfer['from_location_id']]);
                    $current_stock = $stmt->fetchColumn() ?? 0;
                    
                    if ($current_stock < $item['quantity']) {
                        throw new Exception("Insufficient stock for {$item['product_name']}. Available: $current_stock, Required: {$item['quantity']}");
                    }
                    
                    // 1. DEDUCT FROM WAREHOUSE (source location)
                    $stmt = $pdo->prepare("
                        UPDATE product_stocks 
                        SET quantity = quantity - ? 
                        WHERE product_id = ? AND location_id = ?
                    ");
                    $stmt->execute([$item['quantity'], $item['product_id'], $transfer['from_location_id']]);
                    
                    // Record stock adjustment for source
                    $stmt = $pdo->prepare("
                        INSERT INTO stock_adjustments 
                        (product_id, location_id, adjustment_type, quantity, old_stock, new_stock, reason, adjusted_by)
                        SELECT ?, ?, 'remove', ?, quantity, quantity - ?, 'Transfer Approval - Source Deduction', ?
                        FROM product_stocks 
                        WHERE product_id = ? AND location_id = ?
                    ");
                    $stmt->execute([
                        $item['product_id'], $transfer['from_location_id'], $item['quantity'], $item['quantity'],
                        $_SESSION['user_id'], $item['product_id'], $transfer['from_location_id']
                    ]);
                    
                    // 2. ADD TO SHOP (destination location)
                    // Check if stock record exists at destination
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_stocks WHERE product_id = ? AND location_id = ?");
                    $stmt->execute([$item['product_id'], $transfer['to_location_id']]);
                    
                    if ($stmt->fetchColumn() == 0) {
                        // Create new stock record at destination
                        $stmt = $pdo->prepare("INSERT INTO product_stocks (product_id, location_id, quantity) VALUES (?, ?, ?)");
                        $stmt->execute([$item['product_id'], $transfer['to_location_id'], $item['quantity']]);
                        
                        // Record stock adjustment for destination (new record)
                        $stmt = $pdo->prepare("
                            INSERT INTO stock_adjustments 
                            (product_id, location_id, adjustment_type, quantity, old_stock, new_stock, reason, adjusted_by)
                            VALUES (?, ?, 'add', ?, 0, ?, 'Transfer Approval - Destination Addition', ?)
                        ");
                        $stmt->execute([
                            $item['product_id'], $transfer['to_location_id'], $item['quantity'], $item['quantity'],
                            $_SESSION['user_id']
                        ]);
                    } else {
                        // Update existing stock at destination
                        $stmt = $pdo->prepare("
                            UPDATE product_stocks 
                            SET quantity = quantity + ? 
                            WHERE product_id = ? AND location_id = ?
                        ");
                        $stmt->execute([$item['quantity'], $item['product_id'], $transfer['to_location_id']]);
                        
                        // Record stock adjustment for destination (existing record)
                        $stmt = $pdo->prepare("
                            INSERT INTO stock_adjustments 
                            (product_id, location_id, adjustment_type, quantity, old_stock, new_stock, reason, adjusted_by)
                            SELECT ?, ?, 'add', ?, quantity, quantity + ?, 'Transfer Approval - Destination Addition', ?
                            FROM product_stocks 
                            WHERE product_id = ? AND location_id = ?
                        ");
                        $stmt->execute([
                            $item['product_id'], $transfer['to_location_id'], $item['quantity'], $item['quantity'],
                            $_SESSION['user_id'], $item['product_id'], $transfer['to_location_id']
                        ]);
                    }
                }
                
                $success = "Transfer approved successfully! Stock moved from warehouse to shop.";
                break;
                
            case 'mark_in_transit':
                if (!in_array($transfer['status'], ['approved', 'pending'])) {
                    throw new Exception("Cannot mark as in transit from current status.");
                }
                
                $stmt = $pdo->prepare("
                    UPDATE stock_transfers 
                    SET status = 'in_transit', 
                        shipping_date = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([date('Y-m-d'), $transfer_id]);
                $success = "Transfer marked as in transit.";
                break;
                
            case 'mark_completed':
                if ($transfer['status'] != 'in_transit') {
                    throw new Exception("Transfer must be in transit to mark as completed.");
                }
                
                // Update received quantities if provided
                if (isset($_POST['received_qty'])) {
                    foreach ($_POST['received_qty'] as $item_id => $received_qty) {
                        $received_qty = (int)$received_qty;
                        if ($received_qty >= 0) {
                            $stmt = $pdo->prepare("
                                UPDATE stock_transfer_items 
                                SET received_quantity = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$received_qty, $item_id]);
                        }
                    }
                }
                
                $stmt = $pdo->prepare("
                    UPDATE stock_transfers 
                    SET status = 'completed', 
                        delivery_date = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([date('Y-m-d'), $transfer_id]);
                $success = "Transfer completed! Stock was already moved to shop on approval.";
                break;
                
            case 'reject':
                if (!$can_approve) {
                    throw new Exception("You don't have permission to reject transfers.");
                }
                if ($transfer['status'] != 'pending') {
                    throw new Exception("Only pending transfers can be rejected.");
                }
                
                $reason = trim($_POST['reject_reason'] ?? '');
                if (empty($reason)) {
                    throw new Exception("Please provide a reason for rejection.");
                }
                
                $stmt = $pdo->prepare("
                    UPDATE stock_transfers 
                    SET status = 'rejected', 
                        approved_by = ?,
                        notes = CONCAT(COALESCE(notes, ''), '\n\nRejected: ', ?),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $reason, $transfer_id]);
                $success = "Transfer rejected successfully.";
                break;
                
            case 'update_received':
                if ($transfer['status'] != 'in_transit') {
                    throw new Exception("Can only update received quantities for in-transit transfers.");
                }
                
                foreach ($_POST['received_qty'] as $item_id => $received_qty) {
                    $received_qty = (int)$received_qty;
                    if ($received_qty >= 0) {
                        $stmt = $pdo->prepare("
                            UPDATE stock_transfer_items 
                            SET received_quantity = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$received_qty, $item_id]);
                    }
                }
                $success = "Received quantities updated.";
                break;
                
            default:
                throw new Exception("Invalid action.");
        }
        
        $pdo->commit();
        
        // Refresh transfer data
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   f.location_name as from_location,
                   f.location_type as from_type,
                   t2.location_name as to_location,
                   t2.location_type as to_type,
                   u.full_name as requested_by,
                   u2.full_name as approved_by_name,
                   u.phone as requester_phone
            FROM stock_transfers t
            JOIN stock_locations f ON t.from_location_id = f.id
            JOIN stock_locations t2 ON t.to_location_id = t2.id
            JOIN users u ON t.requested_by = u.id
            LEFT JOIN users u2 ON t.approved_by = u2.id
            WHERE t.id = ?
        ");
        $stmt->execute([$transfer_id]);
        $transfer = $stmt->fetch();
        
        // Refresh items with updated stock info
        $items = $pdo->prepare("
            SELECT sti.*, 
                   p.product_name, p.product_code, p.stock_price,
                   ps.quantity as available_stock
            FROM stock_transfer_items sti
            JOIN products p ON sti.product_id = p.id
            LEFT JOIN product_stocks ps ON ps.product_id = p.id AND ps.location_id = ?
            WHERE sti.stock_transfer_id = ?
        ");
        $items->execute([$transfer['from_location_id'], $transfer_id]);
        $items = $items->fetchAll();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Status colors for UI
$status_colors = [
    'pending' => 'warning',
    'approved' => 'info',
    'in_transit' => 'primary',
    'completed' => 'success',
    'rejected' => 'danger'
];

// Determine available actions based on current status AND user role
$available_actions = [];
switch ($transfer['status']) {
    case 'pending':
        if ($can_approve) {
            $available_actions[] = 'approve';
            $available_actions[] = 'reject';
        }
        break;
    case 'approved':
        // Anyone with appropriate role can mark as in transit
        if ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff') {
            $available_actions[] = 'mark_in_transit';
        }
        break;
    case 'in_transit':
        // Allow updating received quantities and marking as completed
        if ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff') {
            $available_actions[] = 'update_received';
            $available_actions[] = 'mark_completed';
        }
        break;
    case 'completed':
    case 'rejected':
        // No actions for completed/rejected transfers
        $available_actions = [];
        break;
}

// Get dates with null checks
$shipping_date = !empty($transfer['shipping_date']) ? date('d M Y', strtotime($transfer['shipping_date'])) : null;
$delivery_date = !empty($transfer['delivery_date']) ? date('d M Y', strtotime($transfer['delivery_date'])) : null;
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Transfer #" . $transfer['transfer_number']; ?>
<?php include('includes/head.php'); ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Back Button -->
                <div class="row mb-3">
                    <div class="col-12">
                        <a href="transfer_stock.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back to Transfers
                        </a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">Transfer #<?= htmlspecialchars($transfer['transfer_number']) ?></h4>
                            <div class="d-flex gap-2">
                                <span class="badge bg-<?= $status_colors[$transfer['status']] ?> fs-6">
                                    <?= ucfirst($transfer['status']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Transfer Details Card -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="fas fa-info-circle me-2"></i>Transfer Details
                                </h5>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Transfer Number</label>
                                        <p class="form-control-plaintext fw-bold text-primary"><?= htmlspecialchars($transfer['transfer_number']) ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Transfer Date</label>
                                        <p class="form-control-plaintext fw-bold"><?= date('d M Y', strtotime($transfer['transfer_date'])) ?></p>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">From Location</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-warehouse me-2"></i><?= htmlspecialchars($transfer['from_location']) ?>
                                            <small class="text-muted d-block">(<?= ucfirst($transfer['from_type']) ?>)</small>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">To Location</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-store me-2"></i><?= htmlspecialchars($transfer['to_location']) ?>
                                            <small class="text-muted d-block">(<?= ucfirst($transfer['to_type']) ?>)</small>
                                        </p>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Requested By</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-user me-2"></i><?= htmlspecialchars($transfer['requested_by']) ?>
                                            <?php if (!empty($transfer['requester_phone'])): ?>
                                            <small class="text-muted d-block"><?= htmlspecialchars($transfer['requester_phone']) ?></small>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Approved By</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-user-check me-2"></i>
                                            <?= !empty($transfer['approved_by_name']) ? htmlspecialchars($transfer['approved_by_name']) : 'Not yet approved' ?>
                                        </p>
                                    </div>
                                    
                                    <?php if ($shipping_date): ?>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Shipping Date</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-shipping-fast me-2"></i><?= $shipping_date ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($delivery_date): ?>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Delivery Date</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-truck-loading me-2"></i><?= $delivery_date ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($transfer['notes'])): ?>
                                    <div class="col-12">
                                        <label class="form-label text-muted">Notes</label>
                                        <div class="border rounded p-3 bg-light">
                                            <?= nl2br(htmlspecialchars($transfer['notes'])) ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Card -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="fas fa-chart-bar me-2"></i>Transfer Summary
                                </h5>
                                
                                <div class="d-flex flex-column gap-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Total Products</span>
                                        <span class="badge bg-primary fs-6"><?= $total_items ?></span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Total Quantity</span>
                                        <span class="badge bg-info fs-6"><?= $total_qty ?></span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Estimated Value</span>
                                        <span class="badge bg-success fs-6">₹<?= number_format($estimated_value, 2) ?></span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Created On</span>
                                        <span class="text-muted"><?= date('d M Y h:i A', strtotime($transfer['created_at'])) ?></span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Last Updated</span>
                                        <span class="text-muted"><?= date('d M Y h:i A', strtotime($transfer['updated_at'] ?? $transfer['created_at'])) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <?php 
                        // Determine what to show based on user role
                        $show_actions = false;
                        
                        // For pending status
                        if ($transfer['status'] == 'pending') {
                            $show_actions = $can_approve; // Only show for admin/warehouse_manager
                        }
                        // For approved status
                        elseif ($transfer['status'] == 'approved') {
                            $show_actions = ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff');
                        }
                        // For in_transit status
                        elseif ($transfer['status'] == 'in_transit') {
                            $show_actions = ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff');
                        }
                        
                        if ($show_actions): ?>
                        <div class="card mt-3">
                            <div class="card-body">
                                <h6 class="card-title mb-3">
                                    <i class="fas fa-cogs me-2"></i>Actions
                                </h6>
                                
                                <div class="d-grid gap-2">
                                    <?php if ($transfer['status'] == 'pending' && $can_approve): ?>
                                    <form method="POST" class="d-grid">
                                        <button type="submit" name="action" value="approve" class="btn btn-success">
                                            <i class="fas fa-check-circle me-2"></i>Approve Transfer
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($transfer['status'] == 'pending' && $can_approve): ?>
                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                        <i class="fas fa-times-circle me-2"></i>Reject Transfer
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($transfer['status'] == 'approved' && ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff')): ?>
                                    <form method="POST" class="d-grid">
                                        <button type="submit" name="action" value="mark_in_transit" class="btn btn-info">
                                            <i class="fas fa-truck me-2"></i>Mark as In Transit
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($transfer['status'] == 'in_transit' && ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff')): ?>
                                    <form method="POST" class="d-grid">
                                        <button type="submit" name="action" value="mark_completed" class="btn btn-success">
                                            <i class="fas fa-check-double me-2"></i>Mark as Completed
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Transfer Items Card -->
                <div class="card mt-3">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="fas fa-boxes me-2"></i>Transfer Items (<?= count($items) ?>)
                        </h5>
                        
                        <?php if (empty($items)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-box-open fa-3x mb-3"></i>
                            <p>No items found in this transfer.</p>
                        </div>
                        <?php else: ?>
                        
                        <?php if ($transfer['status'] == 'in_transit' && ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff')): ?>
                        <form method="POST">
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Product Code</th>
                                        <th>Stock Price</th>
                                        <th>Transfer Qty</th>
                                        <?php if ($transfer['status'] == 'in_transit' || $transfer['status'] == 'completed'): ?>
                                        <th>Received Qty</th>
                                        <th>Difference</th>
                                        <?php endif; ?>
                                        <th>Value</th>
                                        <th>Available at Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $index => $item): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($item['product_code']) ?></span>
                                        </td>
                                        <td>₹<?= number_format($item['stock_price'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?= $item['quantity'] ?></span>
                                        </td>
                                        
                                        <?php if ($transfer['status'] == 'in_transit' || $transfer['status'] == 'completed'): ?>
                                        <td>
                                            <?php if ($transfer['status'] == 'in_transit'): ?>
                                            <input type="number" name="received_qty[<?= $item['id'] ?>]" 
                                                   class="form-control form-control-sm" 
                                                   value="<?= $item['received_quantity'] ?? 0 ?>"
                                                   min="0" max="<?= $item['quantity'] ?>"
                                                   style="width: 80px;">
                                            <?php else: ?>
                                            <span class="badge bg-<?= ($item['received_quantity'] ?? 0) == $item['quantity'] ? 'success' : 'warning' ?>">
                                                <?= $item['received_quantity'] ?? 0 ?>
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($transfer['status'] == 'completed'):
                                                $received_qty = $item['received_quantity'] ?? 0;
                                                $diff = $received_qty - $item['quantity'];
                                            ?>
                                            <span class="badge bg-<?= $diff == 0 ? 'success' : ($diff > 0 ? 'warning' : 'danger') ?>">
                                                <?= $diff >= 0 ? "+$diff" : $diff ?>
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <td class="fw-bold">₹<?= number_format($item['stock_price'] * $item['quantity'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-<?= ($item['available_stock'] ?? 0) >= $item['quantity'] ? 'success' : 'danger' ?>">
                                                <?= $item['available_stock'] ?? 0 ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="<?= ($transfer['status'] == 'in_transit' || $transfer['status'] == 'completed') ? '6' : '4' ?>" class="text-end">Total:</th>
                                        <th><?= $total_qty ?></th>
                                        <?php if ($transfer['status'] == 'completed'): ?>
                                        <th>
                                            <?php 
                                            $total_received = 0;
                                            foreach ($items as $item) {
                                                $total_received += ($item['received_quantity'] ?? 0);
                                            }
                                            $total_diff = $total_received - $total_qty;
                                            ?>
                                            <span class="badge bg-<?= $total_diff == 0 ? 'success' : ($total_diff > 0 ? 'warning' : 'danger') ?>">
                                                <?= $total_diff >= 0 ? "+$total_diff" : $total_diff ?>
                                            </span>
                                        </th>
                                        <?php endif; ?>
                                        <th class="text-success">₹<?= number_format($estimated_value, 2) ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <?php if ($transfer['status'] == 'in_transit' && ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff')): ?>
                            <div class="mt-3 text-end">
                                <button type="submit" name="action" value="update_received" class="btn btn-primary me-2">
                                    <i class="fas fa-save me-2"></i>Update Received
                                </button>
                                <small class="text-muted">or mark as completed directly</small>
                            </div>
                        </form>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Transfer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Are you sure you want to reject this transfer? This action cannot be undone.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason for Rejection *</label>
                        <textarea name="reject_reason" class="form-control" rows="3" 
                                  placeholder="Please provide a reason for rejecting this transfer..."
                                  required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="reject" class="btn btn-danger">
                        <i class="fas fa-times-circle me-2"></i>Confirm Reject
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
// Confirmation for critical actions
document.addEventListener('DOMContentLoaded', function() {
    // Confirm before approving
    const approveBtn = document.querySelector('button[name="action"][value="approve"]');
    if (approveBtn) {
        approveBtn.onclick = function(e) {
            if (!confirm('Are you sure you want to approve this transfer? Stock will be moved from warehouse to shop.')) {
                e.preventDefault();
            }
        };
    }
    
    // Confirm before marking completed
    const completeBtn = document.querySelector('button[name="action"][value="mark_completed"]');
    if (completeBtn) {
        completeBtn.onclick = function(e) {
            if (!confirm('Mark this transfer as completed? Stock was already moved on approval.')) {
                e.preventDefault();
            }
        };
    }
});

// Auto-focus on received quantity inputs
<?php if ($transfer['status'] == 'in_transit'): ?>
document.addEventListener('DOMContentLoaded', function() {
    const firstInput = document.querySelector('input[name^="received_qty"]');
    if (firstInput) firstInput.focus();
});
<?php endif; ?>
</script>
</body>
</html>
<!doctype html>
<html lang="en">
<?php $page_title = "Transfer #" . $transfer['transfer_number']; ?>
<?php include('includes/head.php'); ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Back Button -->
                <div class="row mb-3">
                    <div class="col-12">
                        <a href="transfer_stock.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back to Transfers
                        </a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">Transfer #<?= htmlspecialchars($transfer['transfer_number']) ?></h4>
                            <div class="d-flex gap-2">
                                <span class="badge bg-<?= $status_colors[$transfer['status']] ?> fs-6">
                                    <?= ucfirst($transfer['status']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Debug info (remove after testing) -->
                <div class="alert alert-info">
                    <strong>Debug Info:</strong><br>
                    Your Role: <?= htmlspecialchars($current_user_role) ?><br>
                    Can Approve: <?= $can_approve ? 'Yes' : 'No' ?><br>
                    Transfer Status: <?= htmlspecialchars($transfer['status']) ?><br>
                    Available Actions: <?= implode(', ', $available_actions) ?>
                </div>

                <!-- Transfer Details Card -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="fas fa-info-circle me-2"></i>Transfer Details
                                </h5>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Transfer Number</label>
                                        <p class="form-control-plaintext fw-bold text-primary"><?= htmlspecialchars($transfer['transfer_number']) ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Transfer Date</label>
                                        <p class="form-control-plaintext fw-bold"><?= date('d M Y', strtotime($transfer['transfer_date'])) ?></p>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">From Location</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-warehouse me-2"></i><?= htmlspecialchars($transfer['from_location']) ?>
                                            <small class="text-muted d-block">(<?= ucfirst($transfer['from_type']) ?>)</small>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">To Location</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-store me-2"></i><?= htmlspecialchars($transfer['to_location']) ?>
                                            <small class="text-muted d-block">(<?= ucfirst($transfer['to_type']) ?>)</small>
                                        </p>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Requested By</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-user me-2"></i><?= htmlspecialchars($transfer['requested_by']) ?>
                                            <?php if (!empty($transfer['requester_phone'])): ?>
                                            <small class="text-muted d-block"><?= htmlspecialchars($transfer['requester_phone']) ?></small>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Approved By</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-user-check me-2"></i>
                                            <?= !empty($transfer['approved_by_name']) ? htmlspecialchars($transfer['approved_by_name']) : 'Not yet approved' ?>
                                        </p>
                                    </div>
                                    
                                    <?php if ($shipping_date): ?>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Shipping Date</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-shipping-fast me-2"></i><?= $shipping_date ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($delivery_date): ?>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted">Delivery Date</label>
                                        <p class="form-control-plaintext fw-bold">
                                            <i class="fas fa-truck-loading me-2"></i><?= $delivery_date ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($transfer['notes'])): ?>
                                    <div class="col-12">
                                        <label class="form-label text-muted">Notes</label>
                                        <div class="border rounded p-3 bg-light">
                                            <?= nl2br(htmlspecialchars($transfer['notes'])) ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Card -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="fas fa-chart-bar me-2"></i>Transfer Summary
                                </h5>
                                
                                <div class="d-flex flex-column gap-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Total Products</span>
                                        <span class="badge bg-primary fs-6"><?= $total_items ?></span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Total Quantity</span>
                                        <span class="badge bg-info fs-6"><?= $total_qty ?></span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Estimated Value</span>
                                        <span class="badge bg-success fs-6">₹<?= number_format($estimated_value, 2) ?></span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Created On</span>
                                        <span class="text-muted"><?= date('d M Y h:i A', strtotime($transfer['created_at'])) ?></span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Last Updated</span>
                                        <span class="text-muted"><?= date('d M Y h:i A', strtotime($transfer['updated_at'] ?? $transfer['created_at'])) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Action Buttons - Updated -->
                        <?php 
                        // Determine what to show based on user role
                        $show_actions = false;
                        
                        // For pending status
                        if ($transfer['status'] == 'pending') {
                            $show_actions = $can_approve; // Only show for admin/warehouse_manager
                        }
                        // For approved status
                        elseif ($transfer['status'] == 'approved') {
                            $show_actions = ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff');
                        }
                        // For in_transit status
                        elseif ($transfer['status'] == 'in_transit') {
                            $show_actions = ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff');
                        }
                        
                        if ($show_actions): ?>
                        <div class="card mt-3">
                            <div class="card-body">
                                <h6 class="card-title mb-3">
                                    <i class="fas fa-cogs me-2"></i>Actions
                                </h6>
                                
                                <div class="d-grid gap-2">
                                    <?php if ($transfer['status'] == 'pending' && $can_approve): ?>
                                    <form method="POST" class="d-grid">
                                        <button type="submit" name="action" value="approve" class="btn btn-success">
                                            <i class="fas fa-check-circle me-2"></i>Approve Transfer
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($transfer['status'] == 'pending' && $can_approve): ?>
                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                        <i class="fas fa-times-circle me-2"></i>Reject Transfer
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($transfer['status'] == 'approved' && ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff')): ?>
                                    <form method="POST" class="d-grid">
                                        <button type="submit" name="action" value="mark_in_transit" class="btn btn-info">
                                            <i class="fas fa-truck me-2"></i>Mark as In Transit
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($transfer['status'] == 'in_transit' && ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff')): ?>
                                    <form method="POST" class="d-grid">
                                        <button type="submit" name="action" value="mark_completed" class="btn btn-success">
                                            <i class="fas fa-check-double me-2"></i>Mark as Completed
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Transfer Items Card -->
                <div class="card mt-3">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="fas fa-boxes me-2"></i>Transfer Items (<?= count($items) ?>)
                        </h5>
                        
                        <?php if (empty($items)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-box-open fa-3x mb-3"></i>
                            <p>No items found in this transfer.</p>
                        </div>
                        <?php else: ?>
                        
                        <?php if ($transfer['status'] == 'in_transit' && ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff')): ?>
                        <form method="POST">
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Product Code</th>
                                        <th>Stock Price</th>
                                        <th>Transfer Qty</th>
                                        <?php if ($transfer['status'] == 'in_transit' || $transfer['status'] == 'completed'): ?>
                                        <th>Received Qty</th>
                                        <th>Difference</th>
                                        <?php endif; ?>
                                        <th>Value</th>
                                        <th>Available at Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $index => $item): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($item['product_code']) ?></span>
                                        </td>
                                        <td>₹<?= number_format($item['stock_price'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?= $item['quantity'] ?></span>
                                        </td>
                                        
                                        <?php if ($transfer['status'] == 'in_transit' || $transfer['status'] == 'completed'): ?>
                                        <td>
                                            <?php if ($transfer['status'] == 'in_transit'): ?>
                                            <input type="number" name="received_qty[<?= $item['id'] ?>]" 
                                                   class="form-control form-control-sm" 
                                                   value="<?= $item['received_quantity'] ?? 0 ?>"
                                                   min="0" max="<?= $item['quantity'] ?>"
                                                   style="width: 80px;">
                                            <?php else: ?>
                                            <span class="badge bg-<?= ($item['received_quantity'] ?? 0) == $item['quantity'] ? 'success' : 'warning' ?>">
                                                <?= $item['received_quantity'] ?? 0 ?>
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($transfer['status'] == 'completed'):
                                                $received_qty = $item['received_quantity'] ?? 0;
                                                $diff = $received_qty - $item['quantity'];
                                            ?>
                                            <span class="badge bg-<?= $diff == 0 ? 'success' : ($diff > 0 ? 'warning' : 'danger') ?>">
                                                <?= $diff >= 0 ? "+$diff" : $diff ?>
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <td class="fw-bold">₹<?= number_format($item['stock_price'] * $item['quantity'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-<?= ($item['available_stock'] ?? 0) >= $item['quantity'] ? 'success' : 'danger' ?>">
                                                <?= $item['available_stock'] ?? 0 ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="<?= ($transfer['status'] == 'in_transit' || $transfer['status'] == 'completed') ? '6' : '4' ?>" class="text-end">Total:</th>
                                        <th><?= $total_qty ?></th>
                                        <?php if ($transfer['status'] == 'completed'): ?>
                                        <th>
                                            <?php 
                                            $total_received = 0;
                                            foreach ($items as $item) {
                                                $total_received += ($item['received_quantity'] ?? 0);
                                            }
                                            $total_diff = $total_received - $total_qty;
                                            ?>
                                            <span class="badge bg-<?= $total_diff == 0 ? 'success' : ($total_diff > 0 ? 'warning' : 'danger') ?>">
                                                <?= $total_diff >= 0 ? "+$total_diff" : $total_diff ?>
                                            </span>
                                        </th>
                                        <?php endif; ?>
                                        <th class="text-success">₹<?= number_format($estimated_value, 2) ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <?php if ($transfer['status'] == 'in_transit' && ($can_approve || $current_user_role == 'seller' || $current_user_role == 'staff')): ?>
                            <div class="mt-3 text-end">
                                <button type="submit" name="action" value="update_received" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Received Quantities
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Transfer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Are you sure you want to reject this transfer? This action cannot be undone.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason for Rejection *</label>
                        <textarea name="reject_reason" class="form-control" rows="3" 
                                  placeholder="Please provide a reason for rejecting this transfer..."
                                  required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="reject" class="btn btn-danger">
                        <i class="fas fa-times-circle me-2"></i>Confirm Reject
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
// Confirmation for critical actions
document.addEventListener('DOMContentLoaded', function() {
    // Confirm before approving
    const approveBtn = document.querySelector('button[name="action"][value="approve"]');
    if (approveBtn) {
        approveBtn.onclick = function(e) {
            if (!confirm('Are you sure you want to approve this transfer? Stock will be deducted from source location.')) {
                e.preventDefault();
            }
        };
    }
    
    // Confirm before marking completed
    const completeBtn = document.querySelector('button[name="action"][value="mark_completed"]');
    if (completeBtn) {
        completeBtn.onclick = function(e) {
            if (!confirm('Are you sure you want to mark this transfer as completed? Stock will be added to destination location.')) {
                e.preventDefault();
            }
        };
    }
});

// Auto-focus on received quantity inputs
<?php if ($transfer['status'] == 'in_transit'): ?>
document.addEventListener('DOMContentLoaded', function() {
    const firstInput = document.querySelector('input[name^="received_qty"]');
    if (firstInput) firstInput.focus();
});
<?php endif; ?>
</script>
</body>
</html>