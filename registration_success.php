<?php
require_once 'config/database.php';
session_start();

if (!isset($_SESSION['success_message'], $_SESSION['business_id'], $_SESSION['shop_id'])) {
    header("Location: register_business.php");
    exit();
}

$successMessage = $_SESSION['success_message'];
$businessId = $_SESSION['business_id'];
$shopId = $_SESSION['shop_id'];
$userId = $_SESSION['user_id'] ?? 'N/A';

// Clear session data after use
unset($_SESSION['success_message'], $_SESSION['business_id'], $_SESSION['shop_id'], $_SESSION['user_id']);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch Business Details
    $businessStmt = $pdo->prepare("
        SELECT business_name, business_code, owner_name, phone, email, address, gstin, logo_path 
        FROM businesses 
        WHERE id = ?
    ");
    $businessStmt->execute([$businessId]);
    $business = $businessStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch Shop Details
    $shopStmt = $pdo->prepare("
        SELECT shop_name, shop_code, location_type, address AS shop_address, phone AS shop_phone, gstin AS shop_gstin
        FROM shops 
        WHERE id = ?
    ");
    $shopStmt->execute([$shopId]);
    $shop = $shopStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch Admin User Details (optional - for full name and username)
    $userStmt = $pdo->prepare("
        SELECT username, full_name, email 
        FROM users 
        WHERE id = ?
    ");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // If DB fetch fails, still show success but without detailed info
    $business = $shop = $user = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful - Ecommer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .success-card {
            max-width: 700px;
            text-align: center;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            background: white;
        }
        .success-icon {
            font-size: 80px;
            color: #28a745;
            margin-bottom: 20px;
        }
        .logo-img {
            max-height: 80px;
            margin-bottom: 20px;
        }
        .details-box {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
            text-align: left;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px dashed #dee2e6;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #495057;
            min-width: 180px;
        }
        .detail-value {
            color: #212529;
            text-align: right;
            word-break: break-word;
        }
        @media (max-width: 576px) {
            .detail-row {
                flex-direction: column;
                text-align: left;
            }
            .detail-value {
                text-align: left;
                margin-top: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="success-card">
        <!-- Ecommer Logo (optional - adjust path) -->
        <img src="https://ecommer.in/billing/ecom.png" alt="Ecommer Logo" class="logo-img">

        <div class="success-icon">✓</div>
        <h1 class="mb-4 text-success">Registration Successful!</h1>
        <p class="lead mb-5"><?php echo htmlspecialchars($successMessage); ?></p>

        <?php if ($business && $shop): ?>
        <div class="details-box">
            <h4 class="text-center mb-4">Your Account Details</h4>

            <div class="detail-row">
                <span class="detail-label">Business Name:</span>
                <span class="detail-value"><?php echo htmlspecialchars($business['business_name']); ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Owner Name:</span>
                <span class="detail-value"><?php echo htmlspecialchars($business['owner_name']); ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Business Phone:</span>
                <span class="detail-value"><?php echo htmlspecialchars($business['phone']); ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Business Email:</span>
                <span class="detail-value"><?php echo htmlspecialchars($business['email'] ?? 'Not provided'); ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Shop / Location:</span>
                <span class="detail-value"><?php echo htmlspecialchars($shop['shop_name']) . " (" . ucfirst($shop['location_type']) . ")"; ?></span>
            </div>

            <?php if ($user): ?>
            <div class="detail-row">
                <span class="detail-label">Admin Username:</span>
                <span class="detail-value"><?php echo htmlspecialchars($user['username']); ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Admin Full Name:</span>
                <span class="detail-value"><?php echo htmlspecialchars($user['full_name']); ?></span>
            </div>
            <?php endif; ?>

            <div class="detail-row">
                <span class="detail-label">Registered On:</span>
                <span class="detail-value"><?php echo date('d M Y, h:i A'); ?></span>
            </div>
        </div>

        <div class="alert alert-warning mt-4">
            <strong>Important:</strong> Login credentials have been sent to your registered email.<br>
            Please check your inbox (and spam folder) and <strong>change your password</strong> after first login.
        </div>

        <?php else: ?>
        <div class="alert alert-info">
            Details could not be loaded. Please contact support if needed.
        </div>
        <?php endif; ?>

        <div class="mt-4">
            <a href="register_business.php" class="btn btn-outline-secondary me-3 px-4">Register Another Business</a>
            <a href="https://ecommer.in/billing/trading/" class="btn btn-primary btn-lg px-5">Login to Dashboard</a>
        </div>

        <div class="mt-5">
            <small class="text-muted">
                Need help? Contact support at <strong>ecommerofficial@gmail.com</strong>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>