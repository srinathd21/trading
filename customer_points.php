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

// Check permissions
if (!in_array($user_role, ['admin', 'shop_manager', 'seller'])) {
    $_SESSION['error'] = "You don't have permission to view customer points";
    header('Location: index.php');
    exit();
}

// Get loyalty settings
$stmt = $pdo->prepare("SELECT * FROM loyalty_settings WHERE business_id = ?");
$stmt->execute([$business_id]);
$loyalty_settings = $stmt->fetch();

// Get customers with points
$query = "
    SELECT 
        cp.*,
        c.name,
        c.phone,
        c.email,
        (cp.available_points * ?) as points_value
    FROM customer_points cp
    INNER JOIN customers c ON cp.customer_id = c.id
    WHERE cp.business_id = ?
    ORDER BY cp.available_points DESC
";

$params = [
    $loyalty_settings ? $loyalty_settings['redeem_value_per_point'] : 1.00,
    $business_id
];

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Points - <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?></title>
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
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-user-check me-2"></i> Customer Points
                            </h4>
                            <a href="loyalty_settings.php" class="btn btn-outline-primary">
                                <i class="bx bx-cog me-1"></i> Settings
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="text-muted mb-2">Total Customers</h5>
                                <h3 class="mb-0"><?= count($customers) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="text-muted mb-2">Total Points</h5>
                                <h3 class="mb-0">
                                    <?= number_format(array_sum(array_column($customers, 'available_points')), 0) ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="text-muted mb-2">Points Value</h5>
                                <h3 class="mb-0">
                                    ₹<?= number_format(array_sum(array_column($customers, 'points_value')), 2) ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="text-muted mb-2">Avg Points</h5>
                                <h3 class="mb-0">
                                    <?= count($customers) > 0 ? 
                                        number_format(array_sum(array_column($customers, 'available_points')) / count($customers), 0) : 
                                        0 ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customers Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th class="text-center">Points Earned</th>
                                        <th class="text-center">Points Redeemed</th>
                                        <th class="text-center">Available Points</th>
                                        <th class="text-center">Points Value</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($customers)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="bx bx-package display-4 text-muted mb-3"></i>
                                            <p class="text-muted">No customers have points yet</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($customer['name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($customer['phone']) ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-success bg-opacity-10 text-success">
                                                <?= number_format($customer['total_points_earned'], 0) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-warning bg-opacity-10 text-warning">
                                                <?= number_format($customer['total_points_redeemed'], 0) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary bg-opacity-10 text-primary">
                                                <?= number_format($customer['available_points'], 0) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <strong>₹<?= number_format($customer['points_value'], 2) ?></strong>
                                        </td>
                                        <td>
                                            <?= date('d M Y', strtotime($customer['last_updated'])) ?>
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
<?php include('includes/scripts.php') ?>
</body>
</html>