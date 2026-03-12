<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';

// Check permissions - only admin/shop_manager can access
if (!in_array($user_role, ['admin', 'shop_manager'])) {
    $_SESSION['error'] = "You don't have permission to access loyalty settings";
    header('Location: index.php');
    exit();
}

// Initialize variables
$points_per_amount = 0.01; // 1 point per ₹100
$amount_per_point = 100.00;
$redeem_value_per_point = 1.00;
$min_points_to_redeem = 50;
$expiry_months = null; // null = never expires
$is_active = 1;
$message = '';

// Check if loyalty settings exist for this business
try {
    $check_stmt = $pdo->prepare("
        SELECT * FROM loyalty_settings 
        WHERE business_id = ? 
        LIMIT 1
    ");
    $check_stmt->execute([$business_id]);
    $settings = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($settings) {
        $points_per_amount = (float)$settings['points_per_amount'];
        $amount_per_point = (float)$settings['amount_per_point'];
        $redeem_value_per_point = (float)$settings['redeem_value_per_point'];
        $min_points_to_redeem = (int)$settings['min_points_to_redeem'];
        $expiry_months = $settings['expiry_months'];
        $is_active = (int)$settings['is_active'];
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Error loading loyalty settings: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Get form data - checkboxes need special handling
        $points_per_amount = (float)$_POST['points_per_amount'];
        $amount_per_point = (float)$_POST['amount_per_point'];
        $redeem_value_per_point = (float)$_POST['redeem_value_per_point'];
        $min_points_to_redeem = (int)$_POST['min_points_to_redeem'];
        $expiry_months = !empty($_POST['expiry_months']) ? (int)$_POST['expiry_months'] : null;
        
        // Checkbox handling - if not set, default to 0
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validate data
        if ($points_per_amount < 0 || $points_per_amount > 1) {
            throw new Exception("Points per amount must be between 0 and 1");
        }
        if ($amount_per_point <= 0) {
            throw new Exception("Amount per point must be greater than 0");
        }
        if ($redeem_value_per_point <= 0) {
            throw new Exception("Redeem value per point must be greater than 0");
        }
        if ($min_points_to_redeem < 0) {
            throw new Exception("Minimum points to redeem cannot be negative");
        }
        if ($expiry_months !== null && $expiry_months < 1) {
            throw new Exception("Expiry months must be at least 1 month or empty for never");
        }
        
        // Save to database
        if ($settings) {
            // Update existing settings
            $stmt = $pdo->prepare("
                UPDATE loyalty_settings 
                SET points_per_amount = ?,
                    amount_per_point = ?,
                    redeem_value_per_point = ?,
                    min_points_to_redeem = ?,
                    expiry_months = ?,
                    is_active = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE business_id = ?
            ");
            $stmt->execute([
                $points_per_amount,
                $amount_per_point,
                $redeem_value_per_point,
                $min_points_to_redeem,
                $expiry_months,
                $is_active,
                $business_id
            ]);
            $message = "Loyalty settings updated successfully";
        } else {
            // Insert new settings
            $stmt = $pdo->prepare("
                INSERT INTO loyalty_settings (
                    business_id,
                    points_per_amount,
                    amount_per_point,
                    redeem_value_per_point,
                    min_points_to_redeem,
                    expiry_months,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $business_id,
                $points_per_amount,
                $amount_per_point,
                $redeem_value_per_point,
                $min_points_to_redeem,
                $expiry_months,
                $is_active
            ]);
            $message = "Loyalty settings created successfully";
        }
        
        // Update local variable
        $settings = $settings ?: [];
        $settings['points_per_amount'] = $points_per_amount;
        $settings['amount_per_point'] = $amount_per_point;
        $settings['redeem_value_per_point'] = $redeem_value_per_point;
        $settings['min_points_to_redeem'] = $min_points_to_redeem;
        $settings['expiry_months'] = $expiry_months;
        $settings['is_active'] = $is_active;
        
        $_SESSION['success'] = $message;
        
        // Redirect to avoid form resubmission
        header("Location: loyalty_settings.php");
        exit();
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $_SESSION['error'] = $message;
    }
}

// Get statistics for display
$stats = [
    'total_customers' => 0,
    'customers_with_points' => 0,
    'total_points_issued' => 0,
    'total_points_redeemed' => 0,
    'active_points' => 0
];

try {
    // Get total customers
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM customers 
        WHERE business_id = ?
    ");
    $stmt->execute([$business_id]);
    $stats['total_customers'] = $stmt->fetchColumn();
    
    // Get points statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as customers_with_points,
            SUM(total_points_earned) as total_points_issued,
            SUM(total_points_redeemed) as total_points_redeemed,
            SUM(available_points) as active_points
        FROM customer_points 
        WHERE business_id = ?
    ");
    $stmt->execute([$business_id]);
    $points_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($points_stats) {
        $stats['customers_with_points'] = (int)$points_stats['customers_with_points'];
        $stats['total_points_issued'] = (float)$points_stats['total_points_issued'] ?: 0;
        $stats['total_points_redeemed'] = (float)$points_stats['total_points_redeemed'] ?: 0;
        $stats['active_points'] = (float)$points_stats['active_points'] ?: 0;
    }
} catch (Exception $e) {
    // Silently ignore stats errors
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Loyalty Settings - <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?></title>
    
    <?php include 'includes/head.php'; ?>
    
    <style>
        .settings-card {
            border-left: 4px solid var(--bs-primary);
            transition: all 0.3s ease;
        }
        .settings-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .form-check-label {
            font-weight: 500;
            cursor: pointer;
        }
        .stat-card {
            border-radius: 12px;
            overflow: hidden;
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 24px;
        }
        .info-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .form-range::-webkit-slider-thumb {
            background: var(--bs-primary);
        }
        .form-range::-moz-range-thumb {
            background: var(--bs-primary);
        }
        .example-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .form-switch-lg .form-check-input {
            width: 3.5rem;
            height: 1.75rem;
        }
        .form-switch-lg .form-check-input:checked {
            background-color: #28a745;
            border-color: #28a745;
        }
    </style>
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
                <!-- Page Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-award me-2"></i> Loyalty Program Settings
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mt-1 mb-0">
                                    Configure how customers earn and redeem loyalty points
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="customer_points.php" class="btn btn-outline-primary">
                                    <i class="bx bx-user-check me-1"></i> View Customer Points
                                </a>
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

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                            <i class="bx bx-user"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="mb-1"><?= number_format($stats['total_customers']) ?></h5>
                                        <p class="text-muted mb-0">Total Customers</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                                            <i class="bx bx-gift"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="mb-1"><?= number_format($stats['customers_with_points']) ?></h5>
                                        <p class="text-muted mb-0">With Points</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <div class="stat-icon bg-info bg-opacity-10 text-info">
                                            <i class="bx bx-coin-stack"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="mb-1"><?= number_format($stats['total_points_issued'], 0) ?></h5>
                                        <p class="text-muted mb-0">Total Points Issued</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                            <i class="bx bx-wallet"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="mb-1">₹<?= number_format($stats['active_points'], 2) ?></h5>
                                        <p class="text-muted mb-0">Active Points Value</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Example Calculation -->
                <div class="example-card">
                    <h5 class="text-white mb-3"><i class="bx bx-calculator me-2"></i> Example Calculation</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-2">
                                <small class="text-white-50">Purchase Amount:</small>
                                <h4 class="text-white mb-0">₹1,000</h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-2">
                                <small class="text-white-50">Points Earned:</small>
                                <h4 class="text-white mb-0" id="examplePoints">10</h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-2">
                                <small class="text-white-50">Discount Value:</small>
                                <h4 class="text-white mb-0" id="exampleDiscount">₹10.00</h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Settings Form -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card settings-card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-cog me-2"></i> Loyalty Program Configuration
                                </h5>
                                
                                <form method="POST" id="loyaltySettingsForm">
                                    <!-- Activation Toggle -->
                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <div class="form-check form-switch form-switch-lg">
                                                <input type="checkbox" class="form-check-input" 
                                                       id="is_active" name="is_active" 
                                                       value="1"
                                                       <?= $is_active ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="is_active">
                                                    <h5 class="mb-1">Enable Loyalty Program</h5>
                                                    <p class="text-muted mb-0">
                                                        Turn on/off the entire loyalty program system. 
                                                        When disabled, no points will be awarded or redeemed.
                                                    </p>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <hr class="my-4">
                                    
                                    <!-- Points Earning Configuration -->
                                    <h6 class="mb-3 text-primary">
                                        <i class="bx bx-coin me-1"></i> Points Earning
                                    </h6>
                                    
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">
                                                    <strong>Points per ₹ Spent</strong>
                                                    <span class="text-muted">(e.g., 0.01 = 1 point per ₹100)</span>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" 
                                                           name="points_per_amount" 
                                                           id="points_per_amount"
                                                           value="<?= htmlspecialchars($points_per_amount) ?>"
                                                           step="0.0001" min="0" max="1" required>
                                                    <span class="input-group-text">points/₹</span>
                                                </div>
                                                <small class="text-muted">
                                                    Each ₹ spent earns this many points. Higher values mean faster point accumulation.
                                                </small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">
                                                    <strong>₹ per Point</strong>
                                                    <span class="text-muted">(Alternative view)</span>
                                                </label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" class="form-control" 
                                                           name="amount_per_point" 
                                                           id="amount_per_point"
                                                           value="<?= htmlspecialchars($amount_per_point) ?>"
                                                           step="0.01" min="1" required>
                                                    <span class="input-group-text">per point</span>
                                                </div>
                                                <small class="text-muted">
                                                    Amount customer needs to spend to earn 1 point.
                                                    Auto-calculated from points per ₹.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Visual representation -->
                                    <div class="info-box mb-4">
                                        <h6 class="mb-2">
                                            <i class="bx bx-info-circle me-1"></i> How it works:
                                        </h6>
                                        <p class="mb-0">
                                            With current settings: 
                                            <strong>1 point = ₹<?= number_format($amount_per_point, 2) ?></strong>
                                            <br>
                                            Customer spending ₹1,000 earns: 
                                            <strong><?= number_format(1000 * $points_per_amount, 0) ?> points</strong>
                                        </p>
                                    </div>
                                    
                                    <hr class="my-4">
                                    
                                    <!-- Points Redemption Configuration -->
                                    <h6 class="mb-3 text-primary">
                                        <i class="bx bx-gift me-1"></i> Points Redemption
                                    </h6>
                                    
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">
                                                    <strong>₹ Discount per Point</strong>
                                                </label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" class="form-control" 
                                                           name="redeem_value_per_point" 
                                                           id="redeem_value_per_point"
                                                           value="<?= htmlspecialchars($redeem_value_per_point) ?>"
                                                           step="0.01" min="0.01" required>
                                                    <span class="input-group-text">per point</span>
                                                </div>
                                                <small class="text-muted">
                                                    Value of 1 point when redeemed as discount.
                                                </small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">
                                                    <strong>Minimum Points to Redeem</strong>
                                                </label>
                                                <input type="number" class="form-control" 
                                                       name="min_points_to_redeem" 
                                                       id="min_points_to_redeem"
                                                       value="<?= htmlspecialchars($min_points_to_redeem) ?>"
                                                       min="0" required>
                                                <small class="text-muted">
                                                    Minimum points required to redeem for discount.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-box mb-4">
                                        <h6 class="mb-2">
                                            <i class="bx bx-info-circle me-1"></i> Redemption Value:
                                        </h6>
                                        <p class="mb-0">
                                            With current settings: 
                                            <strong>1 point = ₹<?= number_format($redeem_value_per_point, 2) ?></strong>
                                            <br>
                                            Minimum <?= number_format($min_points_to_redeem) ?> points = 
                                            <strong>₹<?= number_format($min_points_to_redeem * $redeem_value_per_point, 2) ?> discount</strong>
                                        </p>
                                    </div>
                                    
                                    <hr class="my-4">
                                    
                                    <!-- Expiry Settings -->
                                    <h6 class="mb-3 text-primary">
                                        <i class="bx bx-calendar me-1"></i> Points Expiry
                                    </h6>
                                    
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">
                                                    <strong>Points Expire After</strong>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" 
                                                           name="expiry_months" 
                                                           id="expiry_months"
                                                           value="<?= htmlspecialchars($expiry_months) ?>"
                                                           min="1" placeholder="Never expire">
                                                    <span class="input-group-text">months</span>
                                                </div>
                                                <small class="text-muted">
                                                    Leave empty if points never expire. 
                                                    Points expire X months after being earned.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Note:</strong> 
                                        <?php if ($expiry_months): ?>
                                        Points will expire <?= $expiry_months ?> months after being earned.
                                        <?php else: ?>
                                        Points never expire under current settings.
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Form Actions -->
                                    <div class="row mt-5">
                                        <div class="col-12">
                                            <div class="d-flex justify-content-between">
                                                <a href="index.php" class="btn btn-outline-secondary">
                                                    <i class="bx bx-arrow-back me-1"></i> Back to Dashboard
                                                </a>
                                                <button type="submit" class="btn btn-primary px-4">
                                                    <i class="bx bx-save me-1"></i> Save Settings
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Summary Sidebar -->
                    <div class="col-lg-4">
                        <div class="card shadow-sm sticky-top" style="top: 20px;">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-info-circle me-2"></i> Program Summary
                                </h5>
                                
                                <div class="list-group list-group-flush">
                                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0 py-2">
                                        <span class="text-muted">Program Status</span>
                                        <span id="statusBadge" class="badge bg-<?= $is_active ? 'success' : 'danger' ?>">
                                            <?= $is_active ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0 py-2">
                                        <span class="text-muted">Points per ₹100</span>
                                        <span class="fw-bold"><?= number_format($points_per_amount * 100, 1) ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0 py-2">
                                        <span class="text-muted">₹ per Point</span>
                                        <span class="fw-bold">₹<?= number_format($amount_per_point, 2) ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0 py-2">
                                        <span class="text-muted">Value per Point</span>
                                        <span class="fw-bold">₹<?= number_format($redeem_value_per_point, 2) ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0 py-2">
                                        <span class="text-muted">Min to Redeem</span>
                                        <span class="fw-bold"><?= number_format($min_points_to_redeem) ?> points</span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0 py-2">
                                        <span class="text-muted">Points Expiry</span>
                                        <span class="fw-bold">
                                            <?= $expiry_months ? $expiry_months . ' months' : 'Never' ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <hr class="my-4">
                                
                                <!-- Quick Tips -->
                                <h6 class="mb-3">
                                    <i class="bx bx-bulb me-1"></i> Tips
                                </h6>
                                <ul class="list-unstyled text-muted small">
                                    <li class="mb-2">
                                        <i class="bx bx-check-circle text-success me-2"></i>
                                        Typical values: 1 point per ₹100 spent
                                    </li>
                                    <li class="mb-2">
                                        <i class="bx bx-check-circle text-success me-2"></i>
                                        Redemption value is usually ₹0.5-₹2 per point
                                    </li>
                                    <li class="mb-2">
                                        <i class="bx bx-check-circle text-success me-2"></i>
                                        Set minimum points to encourage larger purchases
                                    </li>
                                    <li>
                                        <i class="bx bx-check-circle text-success me-2"></i>
                                        Expiry helps manage liability and encourages usage
                                    </li>
                                </ul>
                                
                                <!-- Program Value -->
                                <div class="alert alert-warning mt-4">
                                    <h6 class="alert-heading mb-2">
                                        <i class="bx bx-calculator me-2"></i> Program Cost
                                    </h6>
                                    <p class="mb-0 small">
                                        Based on current settings and ₹<?= number_format($stats['total_points_issued'] * $redeem_value_per_point, 2) ?> 
                                        in outstanding points, your loyalty liability is approximately:
                                        <br>
                                        <strong>₹<?= number_format($stats['active_points'], 2) ?></strong>
                                    </p>
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

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>

<script>
$(document).ready(function() {
    // Update example calculation in real-time
    function updateExample() {
        const pointsPerAmount = parseFloat($('#points_per_amount').val()) || 0;
        const redeemValue = parseFloat($('#redeem_value_per_point').val()) || 0;
        const purchaseAmount = 1000; // Example ₹1000 purchase
        
        const pointsEarned = Math.floor(purchaseAmount * pointsPerAmount);
        const discountValue = pointsEarned * redeemValue;
        
        $('#examplePoints').text(pointsEarned.toLocaleString());
        $('#exampleDiscount').text('₹' + discountValue.toFixed(2));
    }
    
    // Auto-calculate amount per point when points per amount changes
    $('#points_per_amount').on('input', function() {
        const pointsPerAmount = parseFloat($(this).val());
        if (pointsPerAmount > 0) {
            const amountPerPoint = (1 / pointsPerAmount).toFixed(2);
            $('#amount_per_point').val(amountPerPoint);
        } else {
            $('#amount_per_point').val('100.00');
        }
        updateExample();
    });
    
    // Auto-calculate points per amount when amount per point changes
    $('#amount_per_point').on('input', function() {
        const amountPerPoint = parseFloat($(this).val());
        if (amountPerPoint > 0) {
            const pointsPerAmount = (1 / amountPerPoint).toFixed(4);
            $('#points_per_amount').val(pointsPerAmount);
        }
        updateExample();
    });
    
    // Update example when redeem value changes
    $('#redeem_value_per_point').on('input', updateExample);
    
    // Update status badge when toggle changes
    $('#is_active').on('change', function() {
        const isActive = $(this).is(':checked');
        const $badge = $('#statusBadge');
        
        if (isActive) {
            $badge.removeClass('bg-danger').addClass('bg-success').text('Active');
        } else {
            $badge.removeClass('bg-success').addClass('bg-danger').text('Inactive');
        }
    });
    
    // Initialize example
    updateExample();
    
    // Form validation
    $('#loyaltySettingsForm').on('submit', function(e) {
        const pointsPerAmount = parseFloat($('#points_per_amount').val());
        const amountPerPoint = parseFloat($('#amount_per_point').val());
        const redeemValue = parseFloat($('#redeem_value_per_point').val());
        const minPoints = parseInt($('#min_points_to_redeem').val());
        
        let isValid = true;
        let errorMessage = '';
        
        if (pointsPerAmount < 0 || pointsPerAmount > 1) {
            isValid = false;
            errorMessage = 'Points per amount must be between 0 and 1';
        } else if (amountPerPoint <= 0) {
            isValid = false;
            errorMessage = 'Amount per point must be greater than 0';
        } else if (redeemValue <= 0) {
            isValid = false;
            errorMessage = 'Redeem value per point must be greater than 0';
        } else if (minPoints < 0) {
            isValid = false;
            errorMessage = 'Minimum points to redeem cannot be negative';
        }
        
        if (!isValid) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: errorMessage
            });
        } else {
            // Show confirmation
            const isActive = $('#is_active').is(':checked');
            const statusText = isActive ? 'Active' : 'Inactive';
            
            Swal.fire({
                title: 'Save Settings?',
                html: `The loyalty program will be set to <strong>${statusText}</strong>.<br><br>
                       Are you sure you want to save these settings?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, save it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Submit the form
                    $(this).unbind('submit').submit();
                } else {
                    e.preventDefault();
                }
            });
            
            // Prevent default submit (we'll handle it in the promise)
            e.preventDefault();
        }
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        $('.alert').fadeOut();
    }, 5000);
    
    // Add tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Debug: Log form data on submit
    $('#loyaltySettingsForm').on('submit', function() {
        console.log('Form data:', {
            is_active: $('#is_active').is(':checked'),
            points_per_amount: $('#points_per_amount').val(),
            amount_per_point: $('#amount_per_point').val(),
            redeem_value_per_point: $('#redeem_value_per_point').val(),
            min_points_to_redeem: $('#min_points_to_redeem').val(),
            expiry_months: $('#expiry_months').val()
        });
    });
});
</script>

</body>
</html>