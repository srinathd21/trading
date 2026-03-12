<?php
session_start();
require_once 'config/database.php';

// ==================== LOGIN & ROLE CHECK ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 0;

// Admin only access for this business
if ($user_role !== 'admin') {
    $_SESSION['error'] = "Access denied. Admin only.";
    header('Location: dashboard.php');
    exit();
}

// ==================== EMAIL CONFIGURATION ====================
$baseUrl = "https://ecommer.in/";
$ecommerLogoUrl = $baseUrl . "billing/ecom.png";

// ==================== EMAIL FUNCTIONS ====================
function sendWelcomeEmail($to, $username, $plainPassword, $fullName, $role, $shopName, $businessName) {
    global $baseUrl, $ecommerLogoUrl;
    
    $subject = "Welcome to Ecommer - Your Account is Ready!";
    
    $roleDisplay = ucfirst(str_replace('_', ' ', $role));
    
    $message = "
    <html>
    <head>
        <title>Welcome to Ecommer!</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
            .header { background: white; padding: 20px; text-align: center; }
            .content { padding: 30px; text-align: center; }
            .credentials { background: #f0f0f0; padding: 15px; margin: 20px 0; border-radius: 5px; font-family: monospace; }
            .footer { background: #f1f1f1; padding: 20px; text-align: center; font-size: 12px; color: #666; }
            img.logo { max-width: 200px; height: auto; }
        </style>
    </head>
    <body>
        <div class=\"container\">
            <div class=\"header\">
                <img src=\"{$ecommerLogoUrl}\" alt=\"Ecommer\" class=\"logo\">
            </div>
            <div class=\"content\">
                <h2>Welcome to Ecommer, " . htmlspecialchars($fullName) . "!</h2>
                <p>Your account has been successfully created.</p>
                
                <p><strong>Business:</strong> " . htmlspecialchars($businessName) . "<br>";
                
    if ($shopName) {
        $message .= "<strong>Shop:</strong> " . htmlspecialchars($shopName) . "<br>";
    }
    
    $message .= "<strong>Role:</strong> " . htmlspecialchars($roleDisplay) . "<br>
                <strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                
                <h3>Your Login Credentials</h3>
                <div class=\"credentials\">
                    <strong>Username:</strong> " . htmlspecialchars($username) . "<br>
                    <strong>Password:</strong> " . htmlspecialchars($plainPassword) . "
                </div>
                
                <p><strong>Important:</strong> For security, please change your password immediately after logging in.</p>
                
                <p>You can now log in and start managing your sales, inventory, and customers.</p>
                <p><a href=\"{$baseUrl}billing/trading/\" style=\"background:#007bff; color:white; padding:12px 24px; text-decoration:none; border-radius:5px;\">Login to Dashboard</a></p>
            </div>
            <div class=\"footer\">
                <p>Thank you for choosing <strong>Ecommer</strong> – Your all-in-one retail solution.</p>
                <p>&copy; " . date('Y') . " Ecommer. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: Ecommer <no-reply@ecommer.com>\r\n";
    $headers .= "Reply-To: ecommerofficial@gmail.com\r\n";

    return @mail($to, $subject, $message, $headers);
}

function sendAccountUpdateEmail($to, $username, $fullName, $role, $shopName, $businessName, $isActive, $passwordUpdated = false) {
    global $baseUrl, $ecommerLogoUrl;
    
    $subject = "Ecommer Account Updated";
    $statusText = $isActive ? "activated" : "deactivated";
    $roleDisplay = ucfirst(str_replace('_', ' ', $role));
    
    $message = "
    <html>
    <head>
        <title>Account Updated - Ecommer</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
            .header { background: white; padding: 20px; text-align: center; }
            .content { padding: 30px; text-align: center; }
            .info-box { background: #f0f8ff; padding: 15px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #007bff; }
            .status-badge { display: inline-block; padding: 5px 15px; border-radius: 20px; font-weight: bold; margin: 10px 0; }
            .active { background: #d4edda; color: #155724; }
            .inactive { background: #f8d7da; color: #721c24; }
            .footer { background: #f1f1f1; padding: 20px; text-align: center; font-size: 12px; color: #666; }
            img.logo { max-width: 200px; height: auto; }
        </style>
    </head>
    <body>
        <div class=\"container\">
            <div class=\"header\">
                <img src=\"{$ecommerLogoUrl}\" alt=\"Ecommer\" class=\"logo\">
            </div>
            <div class=\"content\">
                <h2>Account Update Notification</h2>
                <p>Dear " . htmlspecialchars($fullName) . ",</p>
                
                <div class=\"info-box\">
                    <p>Your Ecommer account has been updated:</p>
                    
                    <p><strong>Business:</strong> " . htmlspecialchars($businessName) . "<br>";
                    
    if ($shopName) {
        $message .= "<strong>Shop:</strong> " . htmlspecialchars($shopName) . "<br>";
    }
    
    $message .= "<strong>Role:</strong> " . htmlspecialchars($roleDisplay) . "<br>
                    <strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                    
                    <div class=\"status-badge " . ($isActive ? "active" : "inactive") . "\">
                        Status: " . ($isActive ? "ACTIVE" : "INACTIVE") . "
                    </div>";
    
    if ($passwordUpdated) {
        $message .= "<p><strong>⚠️ Your password has been reset.</strong> Please contact your administrator for the new password.</p>";
    }
    
    $message .= "</div>
                
                <p>If you did not request these changes or have any questions, please contact your system administrator immediately.</p>
                
                <p><a href=\"{$baseUrl}billing/trading/\" style=\"background:#007bff; color:white; padding:12px 24px; text-decoration:none; border-radius:5px;\">Login to Dashboard</a></p>
            </div>
            <div class=\"footer\">
                <p>Thank you for using <strong>Ecommer</strong> – Your all-in-one retail solution.</p>
                <p>&copy; " . date('Y') . " Ecommer. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: Ecommer <no-reply@ecommer.com>\r\n";
    $headers .= "Reply-To: ecommerofficial@gmail.com\r\n";

    return @mail($to, $subject, $message, $headers);
}

// ==================== FETCH BUSINESS NAME ====================
$businessName = '';
$businessStmt = $pdo->prepare("SELECT business_name FROM businesses WHERE id = ?");
$businessStmt->execute([$business_id]);
$businessData = $businessStmt->fetch();
if ($businessData) {
    $businessName = $businessData['business_name'];
}

// ==================== SEARCH & FILTER PARAMETERS ====================
$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$shop_filter = $_GET['shop_filter'] ?? '';

// ==================== HANDLE FORM SUBMISSIONS ====================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'add':
                    // Validate and add new user
                    $username = trim($_POST['username']);
                    $email = trim($_POST['email']);
                    $full_name = trim($_POST['full_name']);
                    $role = $_POST['role'];
                    $phone = trim($_POST['phone']);
                    $shop_id = !empty($_POST['shop_id']) ? (int)$_POST['shop_id'] : null;
                    $plainPassword = $_POST['password'];
                    
                    // Validate inputs
                    if (empty($username) || empty($email) || empty($full_name) || empty($plainPassword)) {
                        $error = "All required fields must be filled!";
                        break;
                    }
                    
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error = "Invalid email format!";
                        break;
                    }
                    
                    if (strlen($plainPassword) < 6) {
                        $error = "Password must be at least 6 characters long!";
                        break;
                    }
                    
                    // Check if username exists in same business
                    $checkUsername = $pdo->prepare("SELECT id FROM users WHERE username = ? AND business_id = ?");
                    $checkUsername->execute([$username, $business_id]);
                    if ($checkUsername->fetch()) {
                        $error = "Username already exists! Please choose a different username.";
                        break;
                    }
                    
                    // Check if email exists in same business
                    $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? AND business_id = ?");
                    $checkEmail->execute([$email, $business_id]);
                    if ($checkEmail->fetch()) {
                        $error = "Email already exists! Please use a different email.";
                        break;
                    }
                    
                    // Hash password
                    $password = password_hash($plainPassword, PASSWORD_DEFAULT);
                    
                    // Get shop name if shop_id is provided
                    $shopName = '';
                    if ($shop_id) {
                        $shopStmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
                        $shopStmt->execute([$shop_id, $business_id]);
                        $shopData = $shopStmt->fetch();
                        if ($shopData) {
                            $shopName = $shopData['shop_name'];
                        }
                    }
                    
                    // Insert user
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, email, password, role, shop_id, full_name, phone, is_active, business_id, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
                    ");
                    $stmt->execute([$username, $email, $password, $role, $shop_id, $full_name, $phone, $business_id]);
                    $new_user_id = $pdo->lastInsertId();
                    
                    // Handle shop access for non-admin users
                    if ($role !== 'admin' && $shop_id) {
                        $accessStmt = $pdo->prepare("
                            INSERT INTO user_shop_access (user_id, shop_id, can_sell, can_manage_stock, can_view_reports)
                            VALUES (?, ?, 1, 1, 1)
                        ");
                        $accessStmt->execute([$new_user_id, $shop_id]);
                    }
                    
                    // Send welcome email
                    $emailSent = sendWelcomeEmail($email, $username, $plainPassword, $full_name, $role, $shopName, $businessName);
                    
                    $success = "User added successfully!" . ($emailSent ? " Welcome email sent to user." : " (Note: Email could not be sent)");
                    break;
                    
                case 'edit':
                    // Update existing user
                    $id = (int)$_POST['user_id'];
                    $username = trim($_POST['username']);
                    $email = trim($_POST['email']);
                    $full_name = trim($_POST['full_name']);
                    $role = $_POST['role'];
                    $phone = trim($_POST['phone']);
                    $shop_id = !empty($_POST['shop_id']) ? (int)$_POST['shop_id'] : null;
                    $is_active = isset($_POST['is_active']) ? 1 : 0;
                    $passwordUpdated = !empty($_POST['password']);
                    
                    // Validate inputs
                    if (empty($username) || empty($email) || empty($full_name)) {
                        $error = "All required fields must be filled!";
                        break;
                    }
                    
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error = "Invalid email format!";
                        break;
                    }
                    
                    // Check if username exists for other users in same business
                    $checkUsername = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ? AND business_id = ?");
                    $checkUsername->execute([$username, $id, $business_id]);
                    if ($checkUsername->fetch()) {
                        $error = "Username already exists! Please choose a different username.";
                        break;
                    }
                    
                    // Check if email exists for other users in same business
                    $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? AND business_id = ?");
                    $checkEmail->execute([$email, $id, $business_id]);
                    if ($checkEmail->fetch()) {
                        $error = "Email already exists! Please use a different email.";
                        break;
                    }
                    
                    // Get current user data before update (for email)
                    $currentStmt = $pdo->prepare("SELECT username, email, full_name, role, shop_id, is_active FROM users WHERE id = ? AND business_id = ?");
                    $currentStmt->execute([$id, $business_id]);
                    $currentUser = $currentStmt->fetch();
                    
                    if (!$currentUser) {
                        $error = "User not found!";
                        break;
                    }
                    
                    // Check if password is being updated
                    if ($passwordUpdated && strlen($_POST['password']) < 6) {
                        $error = "Password must be at least 6 characters long!";
                        break;
                    }
                    
                    // Get shop name if shop_id is provided
                    $shopName = '';
                    if ($shop_id) {
                        $shopStmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
                        $shopStmt->execute([$shop_id, $business_id]);
                        $shopData = $shopStmt->fetch();
                        if ($shopData) {
                            $shopName = $shopData['shop_name'];
                        }
                    }
                    
                    // Update user
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET username = ?, email = ?, full_name = ?, role = ?, shop_id = ?, phone = ?, is_active = ?
                        WHERE id = ? AND business_id = ?
                    ");
                    $stmt->execute([$username, $email, $full_name, $role, $shop_id, $phone, $is_active, $id, $business_id]);
                    
                    // Update password if provided
                    if ($passwordUpdated) {
                        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND business_id = ?")->execute([$password, $id, $business_id]);
                    }
                    
                    // Update shop access
                    $pdo->prepare("DELETE FROM user_shop_access WHERE user_id = ?")->execute([$id]);
                    if ($role !== 'admin' && $shop_id) {
                        $accessStmt = $pdo->prepare("
                            INSERT INTO user_shop_access (user_id, shop_id, can_sell, can_manage_stock, can_view_reports)
                            VALUES (?, ?, 1, 1, 1)
                        ");
                        $accessStmt->execute([$id, $shop_id]);
                    }
                    
                    // Send update notification email if something changed
                    if ($currentUser['username'] != $username || 
                        $currentUser['email'] != $email || 
                        $currentUser['full_name'] != $full_name || 
                        $currentUser['role'] != $role || 
                        $currentUser['shop_id'] != $shop_id || 
                        $currentUser['is_active'] != $is_active ||
                        $passwordUpdated) {
                        
                        $emailSent = sendAccountUpdateEmail($email, $username, $full_name, $role, $shopName, $businessName, $is_active, $passwordUpdated);
                        $emailNote = $emailSent ? " Update notification sent to user." : " (Note: Email could not be sent)";
                    } else {
                        $emailNote = "";
                    }
                    
                    $success = "User updated successfully!" . $emailNote;
                    break;
                    
                case 'deactivate':
                    // Deactivate user (soft delete - set inactive)
                    $id = (int)$_POST['user_id'];
                    
                    // Prevent deactivating yourself
                    if ($id == $user_id) {
                        $error = "You cannot deactivate your own account!";
                        break;
                    }
                    
                    // Get user data before deactivation
                    $userStmt = $pdo->prepare("SELECT username, email, full_name, role, shop_id FROM users WHERE id = ? AND business_id = ?");
                    $userStmt->execute([$id, $business_id]);
                    $userData = $userStmt->fetch();
                    
                    // Get shop name
                    $shopName = '';
                    if ($userData && $userData['shop_id']) {
                        $shopStmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
                        $shopStmt->execute([$userData['shop_id'], $business_id]);
                        $shopData = $shopStmt->fetch();
                        if ($shopData) {
                            $shopName = $shopData['shop_name'];
                        }
                    }
                    
                    // Deactivate user
                    $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND business_id = ?");
                    $stmt->execute([$id, $business_id]);
                    
                    // Send deactivation email
                    if ($userData) {
                        $emailSent = sendAccountUpdateEmail($userData['email'], $userData['username'], $userData['full_name'], $userData['role'], $shopName, $businessName, false);
                        $emailNote = $emailSent ? " Deactivation notification sent to user." : " (Note: Email could not be sent)";
                    } else {
                        $emailNote = "";
                    }
                    
                    $success = "User deactivated successfully!" . $emailNote;
                    break;
                    
                case 'activate':
                    // Activate user
                    $id = (int)$_POST['user_id'];
                    
                    // Get user data before activation
                    $userStmt = $pdo->prepare("SELECT username, email, full_name, role, shop_id FROM users WHERE id = ? AND business_id = ?");
                    $userStmt->execute([$id, $business_id]);
                    $userData = $userStmt->fetch();
                    
                    // Get shop name
                    $shopName = '';
                    if ($userData && $userData['shop_id']) {
                        $shopStmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
                        $shopStmt->execute([$userData['shop_id'], $business_id]);
                        $shopData = $shopStmt->fetch();
                        if ($shopData) {
                            $shopName = $shopData['shop_name'];
                        }
                    }
                    
                    // Activate user
                    $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ? AND business_id = ?");
                    $stmt->execute([$id, $business_id]);
                    
                    // Send activation email
                    if ($userData) {
                        $emailSent = sendAccountUpdateEmail($userData['email'], $userData['username'], $userData['full_name'], $userData['role'], $shopName, $businessName, true);
                        $emailNote = $emailSent ? " Activation notification sent to user." : " (Note: Email could not be sent)";
                    } else {
                        $emailNote = "";
                    }
                    
                    $success = "User activated successfully!" . $emailNote;
                    break;
                    
                case 'delete':
                    // Permanently delete user
                    $id = (int)$_POST['user_id'];
                    
                    // Prevent deleting yourself
                    if ($id == $user_id) {
                        $error = "You cannot delete your own account!";
                        break;
                    }
                    
                    // Check if user has any sales/invoices before deleting
                    $checkSales = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE seller_id = ? AND business_id = ?");
                    $checkSales->execute([$id, $business_id]);
                    $salesCount = $checkSales->fetchColumn();
                    
                    if ($salesCount > 0) {
                        $error = "Cannot delete user! This user has created $salesCount invoices. Please deactivate instead.";
                        break;
                    }
                    
                    // Get user data before deletion (for logging)
                    $userStmt = $pdo->prepare("SELECT username, email, full_name FROM users WHERE id = ? AND business_id = ?");
                    $userStmt->execute([$id, $business_id]);
                    $userData = $userStmt->fetch();
                    
                    if (!$userData) {
                        $error = "User not found!";
                        break;
                    }
                    
                    // Start transaction
                    $pdo->beginTransaction();
                    try {
                        // Remove shop access
                        $pdo->prepare("DELETE FROM user_shop_access WHERE user_id = ?")->execute([$id]);
                        
                        // Delete user
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND business_id = ?");
                        $stmt->execute([$id, $business_id]);
                        
                        $pdo->commit();
                        $success = "User permanently deleted successfully!";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Error deleting user: " . $e->getMessage();
                    }
                    break;
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// ==================== BUILD SQL QUERY WITH FILTERS ====================
// Fetch shops for current business
$shops = $pdo->prepare("SELECT id, shop_name, shop_code FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name");
$shops->execute([$business_id]);
$shops = $shops->fetchAll();

// Role options
$roles = ['admin', 'shop_manager', 'seller', 'cashier', 'stock_manager', 'warehouse_manager', 'field_executive', 'staff'];

// Build WHERE conditions - always filter by business_id
$where_conditions = ["u.business_id = ?"];
$params = [$business_id];

if (!empty($search)) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if (!empty($role_filter)) {
    $where_conditions[] = "u.role = ?";
    $params[] = $role_filter;
}

if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $where_conditions[] = "u.is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $where_conditions[] = "u.is_active = 0";
    }
}

if (!empty($shop_filter)) {
    $where_conditions[] = "u.shop_id = ?";
    $params[] = $shop_filter;
}

// Build WHERE clause
$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// ==================== FETCH USERS WITH FILTERS ====================
$sql = "
    SELECT u.*, s.shop_name, s.shop_code,
           DATE_FORMAT(u.created_at, '%d/%m/%Y') as created_date,
           DATE_FORMAT(u.last_login, '%d/%m/%Y %h:%i %p') as last_login_formatted
    FROM users u
    LEFT JOIN shops s ON u.shop_id = s.id AND s.business_id = u.business_id
    $where_clause
    ORDER BY u.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================== FETCH STATISTICS WITH FILTERS ====================
// Build stats query with same filters
$stats_where = 'WHERE business_id = ?';
$stats_params = [$business_id];

if (!empty($search)) {
    $stats_where .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = "%$search%";
    $stats_params = array_merge($stats_params, [$like, $like, $like, $like]);
}

if (!empty($role_filter)) {
    $stats_where .= " AND role = ?";
    $stats_params[] = $role_filter;
}

if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $stats_where .= " AND is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $stats_where .= " AND is_active = 0";
    }
}

if (!empty($shop_filter)) {
    $stats_where .= " AND shop_id = ?";
    $stats_params[] = $shop_filter;
}

$stats_sql = "
    SELECT 
        COUNT(*) as total_users,
        COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_users,
        COUNT(CASE WHEN role = 'admin' THEN 1 END) as admin_users,
        COUNT(CASE WHEN role IN ('seller', 'cashier') THEN 1 END) as sales_users
    FROM users
    $stats_where
";

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch();
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Users Management"; ?>
<?php include 'includes/head.php'; ?>
<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

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
                                <i class="bx bx-user-circle me-2"></i> Users Management
                                <small class="text-muted ms-2">
                                    <i class="bx bx-cog me-1"></i> Admin Panel
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                    <i class="bx bx-plus-circle me-1"></i> Add User
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert" style="display: none;">
                    <i class="bx bx-check-circle me-2"></i><?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert" style="display: none;">
                    <i class="bx bx-error-circle me-2"></i><?= $error ?>
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
                                        <h6 class="text-muted mb-1">Total Users</h6>
                                        <h3 class="mb-0 text-primary"><?= $stats['total_users'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-user text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Active Users</h6>
                                        <h3 class="mb-0 text-success"><?= $stats['active_users'] ?? 0 ?></h3>
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
                                        <h6 class="text-muted mb-1">Admin Users</h6>
                                        <h3 class="mb-0 text-info"><?= $stats['admin_users'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-crown text-info"></i>
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
                                        <h6 class="text-muted mb-1">Sales Staff</h6>
                                        <h3 class="mb-0 text-warning"><?= $stats['sales_users'] ?? 0 ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-cart text-warning"></i>
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
                            <i class="bx bx-filter-alt me-1"></i> Filter Users
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Search Users</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Name, Username, Email, Phone..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Role</label>
                                    <select name="role" class="form-select">
                                        <option value="">All Roles</option>
                                        <?php foreach ($roles as $role): ?>
                                        <option value="<?= $role ?>" <?= $role_filter == $role ? 'selected' : '' ?>>
                                            <?= ucfirst(str_replace('_', ' ', $role)) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Shop</label>
                                    <select name="shop_filter" class="form-select">
                                        <option value="">All Shops</option>
                                        <?php foreach ($shops as $shop): ?>
                                        <option value="<?= $shop['id'] ?>" <?= $shop_filter == $shop['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($shop['shop_name']) ?> (<?= $shop['shop_code'] ?>)
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
                                <div class="col-lg-3 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($search || $role_filter || $shop_filter || $status_filter): ?>
                                        <a href="users.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="usersTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>User Details</th>
                                        <th>Role & Shop</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Last Login</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bx bx-user-circle fs-1 text-muted mb-3"></i>
                                                <h5>No Users Found</h5>
                                                <?php if ($search || $role_filter || $shop_filter || $status_filter): ?>
                                                <p class="text-muted">No users match your filter criteria</p>
                                                <a href="users.php" class="btn btn-outline-secondary">
                                                    <i class="bx bx-reset me-1"></i> Clear Filters
                                                </a>
                                                <?php else: ?>
                                                <p class="text-muted">Add a new user to get started</p>
                                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                                    <i class="bx bx-plus me-1"></i> Add New User
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach($users as $u): ?>
                                    <?php 
                                    $role_badge_class = match($u['role']) {
                                        'admin' => 'danger',
                                        'shop_manager' => 'primary',
                                        'seller' => 'success',
                                        'cashier' => 'info',
                                        'stock_manager' => 'warning',
                                        'warehouse_manager' => 'purple',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <tr class="user-row" data-id="<?= $u['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <span class="fw-bold fs-5">
                                                            <?= strtoupper(substr($u['full_name'], 0, 2)) ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($u['full_name']) ?></strong>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-at me-1"></i><?= htmlspecialchars($u['username']) ?>
                                                    </small>
                                                    <small class="text-muted">
                                                        <?php if($u['email']): ?>
                                                        <i class="bx bx-envelope me-1"></i><?= htmlspecialchars($u['email']) ?>
                                                        <?php endif; ?>
                                                        <?php if($u['phone']): ?>
                                                        <br><i class="bx bx-phone me-1"></i><?= htmlspecialchars($u['phone']) ?>
                                                        <?php endif; ?>
                                                    </small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-calendar me-1"></i>Created: <?= $u['created_date'] ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <span class="badge bg-<?= $role_badge_class ?> bg-opacity-10 text-<?= $role_badge_class ?> px-3 py-1 mb-1">
                                                    <i class="bx bx-user-<?= $u['role'] == 'admin' ? 'pin' : 'circle' ?> me-1"></i>
                                                    <?= ucfirst(str_replace('_', ' ', $u['role'])) ?>
                                                </span>
                                                <?php if($u['shop_name']): ?>
                                                <div class="mt-2">
                                                    <small class="text-muted d-flex align-items-center">
                                                        <i class="bx bx-store-alt me-1"></i> 
                                                        <?= htmlspecialchars($u['shop_name']) ?> (<?= $u['shop_code'] ?>)
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($u['is_active']): ?>
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
                                            <small class="text-muted">
                                                <?= $u['last_login_formatted'] ?: '<span class="text-muted">Never logged in</span>' ?>
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" 
                                                        class="btn btn-outline-warning edit-user-btn"
                                                        data-id="<?= $u['id'] ?>"
                                                        data-full_name="<?= htmlspecialchars($u['full_name']) ?>"
                                                        data-username="<?= htmlspecialchars($u['username']) ?>"
                                                        data-email="<?= htmlspecialchars($u['email']) ?>"
                                                        data-phone="<?= htmlspecialchars($u['phone'] ?? '') ?>"
                                                        data-role="<?= htmlspecialchars($u['role']) ?>"
                                                        data-shop_id="<?= $u['shop_id'] ?? '' ?>"
                                                        data-is_active="<?= $u['is_active'] ?>"
                                                        data-bs-toggle="tooltip"
                                                        title="Edit User">
                                                    <i class="bx bx-edit"></i>
                                                </button>
                                                <?php if ($u['is_active'] && $u['id'] != $user_id): ?>
                                                <form method="POST" class="d-inline deactivate-form">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="button" 
                                                            class="btn btn-outline-secondary deactivate-btn"
                                                            data-user-name="<?= htmlspecialchars($u['full_name']) ?>"
                                                            data-bs-toggle="tooltip"
                                                            title="Deactivate User">
                                                        <i class="bx bx-user-minus"></i>
                                                    </button>
                                                </form>
                                                <?php elseif (!$u['is_active'] && $u['id'] != $user_id): ?>
                                                <form method="POST" class="d-inline activate-form">
                                                    <input type="hidden" name="action" value="activate">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="button" 
                                                            class="btn btn-outline-success activate-btn"
                                                            data-user-name="<?= htmlspecialchars($u['full_name']) ?>"
                                                            data-bs-toggle="tooltip"
                                                            title="Activate User">
                                                        <i class="bx bx-user-check"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <?php if ($u['id'] != $user_id): ?>
                                                <form method="POST" class="d-inline delete-form">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="button" 
                                                            class="btn btn-outline-danger delete-btn"
                                                            data-user-name="<?= htmlspecialchars($u['full_name']) ?>"
                                                            data-bs-toggle="tooltip"
                                                            title="Permanently Delete User">
                                                        <i class="bx bx-trash"></i>
                                                    </button>
                                                </form>
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-user-plus me-2"></i> Add New User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addUserForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="password" id="add_password" required minlength="6">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('add_password')">
                                    <i class="bx bx-show"></i>
                                </button>
                            </div>
                            <small class="text-muted">Minimum 6 characters. A welcome email with credentials will be sent.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= $role ?>"><?= ucfirst(str_replace('_', ' ', $role)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assign to Shop</label>
                            <select class="form-select" name="shop_id">
                                <option value="">Select Shop (Optional)</option>
                                <?php foreach ($shops as $shop): ?>
                                    <option value="<?= $shop['id'] ?>"><?= htmlspecialchars($shop['shop_name']) ?> (<?= $shop['shop_code'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Required for non-admin roles</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-edit me-2"></i> Edit User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editUserForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" id="edit_full_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" id="edit_username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" id="edit_email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone" id="edit_phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="password" id="edit_password">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('edit_password')">
                                    <i class="bx bx-show"></i>
                                </button>
                            </div>
                            <small class="text-muted">Leave blank to keep current password. If changed, the user will be notified.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" id="edit_role" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= $role ?>"><?= ucfirst(str_replace('_', ' ', $role)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assign to Shop</label>
                            <select class="form-select" name="shop_id" id="edit_shop_id">
                                <option value="">Select Shop (Optional)</option>
                                <?php foreach ($shops as $shop): ?>
                                    <option value="<?= $shop['id'] ?>"><?= htmlspecialchars($shop['shop_name']) ?> (<?= $shop['shop_code'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-4 pt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label" for="edit_is_active">Active User</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>
<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTables (client-side processing for filtered data)
    $('#usersTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search in filtered results:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ users",
            infoFiltered: "(filtered from <?= $stats['total_users'] ?? 0 ?> total users)",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Edit button handler
    $('.edit-user-btn').click(function() {
        $('#edit_user_id').val($(this).data('id'));
        $('#edit_full_name').val($(this).data('full_name'));
        $('#edit_username').val($(this).data('username'));
        $('#edit_email').val($(this).data('email'));
        $('#edit_phone').val($(this).data('phone'));
        $('#edit_role').val($(this).data('role'));
        $('#edit_shop_id').val($(this).data('shop_id'));
        $('#edit_is_active').prop('checked', $(this).data('is_active') == 1);
        $('#edit_password').val('');
        
        const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        modal.show();
    });

    // Reset edit modal when closed
    $('#editUserModal').on('hidden.bs.modal', function () {
        $(this).find('form')[0].reset();
        $('#edit_user_id').val('');
        $('#edit_password').val('');
    });

    // Deactivate confirmation
    $('.deactivate-btn').on('click', function() {
        const form = $(this).closest('form.deactivate-form');
        const userName = $(this).data('user-name');
        
        Swal.fire({
            title: 'Deactivate User?',
            html: `Are you sure you want to deactivate <strong>${userName}</strong>?<br><br>This will prevent the user from logging in. You can activate them again later.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, deactivate',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
    
    // Activate confirmation
    $('.activate-btn').on('click', function() {
        const form = $(this).closest('form.activate-form');
        const userName = $(this).data('user-name');
        
        Swal.fire({
            title: 'Activate User?',
            html: `Are you sure you want to activate <strong>${userName}</strong>?<br><br>This will allow the user to log in again.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, activate',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
    
    // Delete confirmation with extra warning
    $('.delete-btn').on('click', function() {
        const form = $(this).closest('form.delete-form');
        const userName = $(this).data('user-name');
        
        Swal.fire({
            title: 'Permanently Delete User?',
            html: `<span class="text-danger"><strong>WARNING:</strong> This action cannot be undone!</span><br><br>
                   You are about to permanently delete <strong>${userName}</strong> and all associated data.`,
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete permanently',
            cancelButtonText: 'Cancel',
            showCloseButton: true,
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                // Double-check with another confirmation
                Swal.fire({
                    title: 'Are you absolutely sure?',
                    html: `This action is irreversible. All data for <strong>${userName}</strong> will be lost forever.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete everything',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true
                }).then((secondResult) => {
                    if (secondResult.isConfirmed) {
                        form.submit();
                    }
                });
            }
        });
    });
});

// Toggle password visibility
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = input.nextElementSibling.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bx-show');
        icon.classList.add('bx-hide');
    } else {
        input.type = 'password';
        icon.classList.remove('bx-hide');
        icon.classList.add('bx-show');
    }
}

// SweetAlert2 Form Validation
document.getElementById('addUserForm')?.addEventListener('submit', function(e) {
    const password = this.querySelector('[name="password"]');
    if (password && password.value.length < 6) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Password Too Short',
            text: 'Password must be at least 6 characters long!',
            confirmButtonColor: '#5b73e8',
            confirmButtonText: 'OK'
        });
        password.focus();
    }
});

document.getElementById('editUserForm')?.addEventListener('submit', function(e) {
    const password = this.querySelector('[name="password"]');
    if (password && password.value && password.value.length < 6) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Password Too Short',
            text: 'Password must be at least 6 characters long!',
            confirmButtonColor: '#5b73e8',
            confirmButtonText: 'OK'
        });
        password.focus();
    }
});

// SweetAlert2 for PHP messages
<?php if ($success): ?>
$(document).ready(function() {
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: '<?= addslashes($success) ?>',
        showConfirmButton: true,
        confirmButtonColor: '#28a745',
        timer: 5000,
        timerProgressBar: true
    });
});
<?php endif; ?>

<?php if ($error): ?>
$(document).ready(function() {
    Swal.fire({
        icon: 'error',
        title: 'Error!',
        text: '<?= addslashes($error) ?>',
        showConfirmButton: true,
        confirmButtonColor: '#dc3545'
    });
});
<?php endif; ?>
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
.user-row:hover .avatar-sm .bg-primary {
    transform: scale(1.1);
    transition: transform 0.3s ease;
}
.bg-purple {
    background-color: #6f42c1 !important;
}
.bg-purple.bg-opacity-10 {
    background-color: rgba(111, 66, 193, 0.1) !important;
}
.text-purple {
    color: #6f42c1 !important;
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

/* SweetAlert2 Custom Styles */
.swal2-popup {
    font-family: inherit;
}
.swal2-title {
    font-size: 1.5rem;
    font-weight: 600;
}
.swal2-html-container {
    font-size: 1rem;
}
.swal2-confirm {
    font-weight: 500;
    padding: 0.5rem 1.5rem;
}
.swal2-cancel {
    font-weight: 500;
    padding: 0.5rem 1.5rem;
}
</style>
</body>
</html>