<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Check authentication and permissions
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['staff', 'warehouse_manager', 'seller', 'admin'];
if (!in_array($user_role, $allowed_roles)) {
    header('Location: dashboard.php');
    exit();
}

// Get parameters
$visit_id = (int)($_GET['visit'] ?? 0);
$new_status = $_GET['status'] ?? '';
$valid_statuses = ['packed', 'shipped', 'delivered', 'approved'];

if ($visit_id <= 0 || !in_array($new_status, $valid_statuses)) {
    header('Location: store_requirements.php?error=Invalid parameters');
    exit();
}

// Check if user has permission for this status update
if ($user_role === 'seller' && $new_status !== 'approved') {
    header('Location: store_requirements.php?error=You can only approve requirements');
    exit();
}

// Check if visit exists and get current status
$visit_stmt = $pdo->prepare("
    SELECT sv.*, s.store_name, s.store_code,
           u.full_name as executive_name
    FROM store_visits sv
    JOIN stores s ON sv.store_id = s.id
    JOIN users u ON sv.field_executive_id = u.id
    WHERE sv.id = ?
");
$visit_stmt->execute([$visit_id]);
$visit = $visit_stmt->fetch();

if (!$visit) {
    header('Location: store_requirements.php?error=Visit not found');
    exit();
}

// Get all requirements for this visit
$requirements_stmt = $pdo->prepare("
    SELECT sr.*, p.product_name, p.product_code
    FROM store_requirements sr
    JOIN products p ON sr.product_id = p.id
    WHERE sr.store_visit_id = ?
    ORDER BY sr.id
");
$requirements_stmt->execute([$visit_id]);
$requirements = $requirements_stmt->fetchAll();

if (empty($requirements)) {
    header('Location: store_requirements.php?error=No requirements found for this visit');
    exit();
}

// Process status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes = trim($_POST['notes'] ?? '');
    $tracking_number = ($new_status === 'shipped') ? trim($_POST['tracking_number'] ?? '') : null;
    $action_confirmed = isset($_POST['confirm_action']);
    
    if (!$action_confirmed) {
        header('Location: store_requirements.php?error=Please confirm the action');
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update all requirements for this visit
        if ($new_status === 'packed') {
            $update_stmt = $pdo->prepare("
                UPDATE store_requirements 
                SET requirement_status = 'packed',
                    packed_by = ?,
                    packed_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), ?)
                WHERE store_visit_id = ? 
                AND requirement_status IN ('pending', 'approved')
            ");
            $update_stmt->execute([
                $user_id, 
                $notes ? "\n\nPacking Notes: " . $notes : '', 
                $visit_id
            ]);
            
        } elseif ($new_status === 'shipped') {
            if (empty($tracking_number)) {
                header('Location: store_requirements.php?error=Tracking number is required for shipping');
                exit();
            }
            
            $update_stmt = $pdo->prepare("
                UPDATE store_requirements 
                SET requirement_status = 'shipped',
                    shipped_by = ?,
                    shipped_at = NOW(),
                    tracking_number = ?,
                    notes = CONCAT(COALESCE(notes, ''), ?)
                WHERE store_visit_id = ? 
                AND requirement_status = 'packed'
            ");
            $update_stmt->execute([
                $user_id, 
                $tracking_number,
                $notes ? "\n\nShipping Notes: " . $notes : '',
                $visit_id
            ]);
            
        } elseif ($new_status === 'delivered') {
            $update_stmt = $pdo->prepare("
                UPDATE store_requirements 
                SET requirement_status = 'delivered',
                    delivered_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), ?)
                WHERE store_visit_id = ? 
                AND requirement_status = 'shipped'
            ");
            $update_stmt->execute([
                $user_id, 
                $notes ? "\n\nDelivery Notes: " . $notes : '',
                $visit_id
            ]);
            
        } elseif ($new_status === 'approved') {
            // Seller approval
            $update_stmt = $pdo->prepare("
                UPDATE store_requirements 
                SET requirement_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), ?)
                WHERE store_visit_id = ? 
                AND requirement_status = 'pending'
            ");
            $update_stmt->execute([
                $user_id, 
                $notes ? "\n\nApproval Notes: " . $notes : '',
                $visit_id
            ]);
        }
        
        // Log the status update
        $log_stmt = $pdo->prepare("
            INSERT INTO status_logs 
            (visit_id, updated_by, old_status, new_status, notes, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        // Get current status for logging
        $current_status_stmt = $pdo->prepare("
            SELECT requirement_status FROM store_requirements 
            WHERE store_visit_id = ? LIMIT 1
        ");
        $current_status_stmt->execute([$visit_id]);
        $current_status = $current_status_stmt->fetchColumn() ?: 'pending';
        
        $log_stmt->execute([
            $visit_id, 
            $user_id, 
            $current_status, 
            $new_status, 
            $notes
        ]);
        
        $pdo->commit();
        
        // Redirect with success message
        $_SESSION['success'] = "Status updated to " . ucfirst($new_status) . " successfully!";
        header("Location: store_requirements.php");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error updating status: " . $e->getMessage();
    }
}

// If not POST, show confirmation form
$status_descriptions = [
    'approved' => 'Approve the requirements for processing',
    'packed' => 'Mark items as packed and ready for shipping',
    'shipped' => 'Mark items as shipped with tracking information',
    'delivered' => 'Confirm delivery to the store'
];

$status_titles = [
    'approved' => 'Approve Requirements',
    'packed' => 'Mark as Packed',
    'shipped' => 'Mark as Shipped',
    'delivered' => 'Mark as Delivered'
];

$status_colors = [
    'approved' => 'success',
    'packed' => 'primary',
    'shipped' => 'dark',
    'delivered' => 'info'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Status</title>
    <?php include 'includes/head.php'; ?>
</head>
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
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1"><?= ucfirst($status_titles[$new_status]) ?></h4>
                                <p class="text-muted mb-0">Store: <?= htmlspecialchars($visit['store_name']) ?></p>
                            </div>
                            <a href="store_requirements.php" class="btn btn-secondary">Back</a>
                        </div>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-3">
                    <i class="bx bx-error me-2"></i>
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-4">
                                        <div class="alert alert-<?= $status_colors[$new_status] ?>">
                                            <h5 class="alert-heading">
                                                <i class="bx bx-info-circle me-2"></i>
                                                <?= $status_titles[$new_status] ?>
                                            </h5>
                                            <p class="mb-0"><?= $status_descriptions[$new_status] ?></p>
                                        </div>
                                    </div>

                                    <!-- Visit Details -->
                                    <div class="mb-4">
                                        <h5>Visit Details</h5>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>Store:</strong> <?= htmlspecialchars($visit['store_name']) ?> [<?= $visit['store_code'] ?>]</p>
                                                <p><strong>Field Executive:</strong> <?= htmlspecialchars($visit['executive_name']) ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Visit Date:</strong> <?= date('d M Y', strtotime($visit['visit_date'])) ?></p>
                                                <p><strong>Requirements:</strong> <?= count($requirements) ?> items</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Requirements List -->
                                    <div class="mb-4">
                                        <h5>Requirements to Update</h5>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Product</th>
                                                        <th>Qty</th>
                                                        <th>Current Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($requirements as $req): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($req['product_name']) ?> (<?= $req['product_code'] ?>)</td>
                                                        <td><span class="badge bg-primary"><?= $req['required_quantity'] ?></span></td>
                                                        <td>
                                                            <span class="badge bg-<?= 
                                                                $req['requirement_status'] === 'pending' ? 'warning' : 
                                                                ($req['requirement_status'] === 'approved' ? 'info' : 
                                                                ($req['requirement_status'] === 'packed' ? 'primary' : 
                                                                ($req['requirement_status'] === 'shipped' ? 'dark' : 'success')))
                                                            ?>">
                                                                <?= ucfirst($req['requirement_status']) ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Tracking Number (for shipping) -->
                                    <?php if ($new_status === 'shipped'): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Tracking Number <span class="text-danger">*</span></label>
                                        <input type="text" name="tracking_number" class="form-control" required
                                               placeholder="Enter tracking/courier number">
                                    </div>
                                    <?php endif; ?>

                                    <!-- Notes -->
                                    <div class="mb-3">
                                        <label class="form-label">Notes (Optional)</label>
                                        <textarea name="notes" class="form-control" rows="3" 
                                                  placeholder="Add any notes about this update..."></textarea>
                                    </div>

                                    <!-- Confirmation -->
                                    <div class="mb-4">
                                        <div class="form-check">
                                            <input type="checkbox" name="confirm_action" id="confirm_action" 
                                                   class="form-check-input" required>
                                            <label class="form-check-label" for="confirm_action">
                                                I confirm that I want to update the status of all <?= count($requirements) ?> 
                                                items to <strong><?= ucfirst($new_status) ?></strong>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-flex justify-content-between">
                                        <a href="store_requirements.php" class="btn btn-secondary">Cancel</a>
                                        <button type="submit" class="btn btn-<?= $status_colors[$new_status] ?>">
                                            <i class="bx bx-check me-1"></i>
                                            Confirm <?= ucfirst($new_status) ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Status Flow Sidebar -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="mb-3">Status Flow</h5>
                                <div class="timeline">
                                    <?php 
                                    $status_flow = ['pending', 'approved', 'packed', 'shipped', 'delivered'];
                                    $current_status_index = array_search(strtolower($requirements[0]['requirement_status']), $status_flow);
                                    $new_status_index = array_search($new_status, $status_flow);
                                    
                                    foreach ($status_flow as $index => $status):
                                        $is_past = $index <= $current_status_index;
                                        $is_current = $index == $current_status_index;
                                        $is_future = $index > $current_status_index;
                                        $is_target = $index == $new_status_index;
                                    ?>
                                    <div class="timeline-item <?= $is_target ? 'timeline-item-active' : '' ?>">
                                        <div class="timeline-point <?= $is_past ? 'bg-success' : ($is_target ? 'bg-primary' : 'bg-light') ?>">
                                            <?php if ($is_past): ?>
                                            <i class="bx bx-check"></i>
                                            <?php elseif ($is_target): ?>
                                            <i class="bx bx-arrow-to-right"></i>
                                            <?php else: ?>
                                            <i class="bx bx-circle"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="timeline-content">
                                            <h6 class="<?= $is_target ? 'text-primary fw-bold' : ($is_past ? 'text-success' : 'text-muted') ?>">
                                                <?= ucfirst($status) ?>
                                            </h6>
                                            <?php if ($is_current): ?>
                                            <small class="text-muted">Current Status</small>
                                            <?php elseif ($is_target): ?>
                                            <small class="text-primary">Updating to this</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}
.timeline::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}
.timeline-item {
    position: relative;
    margin-bottom: 20px;
}
.timeline-point {
    position: absolute;
    left: -25px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
}
.timeline-content {
    padding-left: 10px;
}
.timeline-item-active .timeline-point {
    border: 2px solid #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}
</style>

<?php include 'includes/scripts.php'; ?>
</body>
</html>