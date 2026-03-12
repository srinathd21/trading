<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 0;

// Fetch user details with business and shop names
$stmt = $pdo->prepare("
    SELECT 
        u.*, 
        s.shop_name,
        b.business_name,
        b.business_code
    FROM users u
    LEFT JOIN shops s ON u.shop_id = s.id AND s.business_id = u.business_id
    LEFT JOIN businesses b ON u.business_id = b.id
    WHERE u.id = ? AND u.business_id = ?
");
$stmt->execute([$user_id, $business_id]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header('Location: dashboard.php');
    exit();
}

// Handle form submission
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($full_name) || empty($email)) {
        $error = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        // Check if email already exists for other users
        $checkEmail = $pdo->prepare("
            SELECT id FROM users 
            WHERE email = ? 
              AND id != ? 
              AND business_id = ?
        ");
        $checkEmail->execute([$email, $user_id, $business_id]);
        
        if ($checkEmail->fetch()) {
            $error = "Email already exists for another user.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, phone = ?
                    WHERE id = ? AND business_id = ?
                ");
                $stmt->execute([$full_name, $email, $phone, $user_id, $business_id]);
                
                // Update session with new name
                $_SESSION['user_fullname'] = $full_name;
                
                $success = "Profile updated successfully!";
                
                // Refresh user data
                $stmt = $pdo->prepare("
                    SELECT 
                        u.*, 
                        s.shop_name,
                        b.business_name,
                        b.business_code
                    FROM users u
                    LEFT JOIN shops s ON u.shop_id = s.id AND s.business_id = u.business_id
                    LEFT JOIN businesses b ON u.business_id = b.id
                    WHERE u.id = ?
                ");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Profile - <?php echo htmlspecialchars($user['full_name']); ?></title>
    <?php include 'includes/head.php'; ?>
    <style>
        .profile-edit-card {
            max-width: auto;
            margin-bottom: 20px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #eaeaea;
        }
        .profile-header {
            background: #ffffff;
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid #eaeaea;
        }
        .avatar-large {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background-color: #f8f9fa;
            color: #5b73e8;
            font-size: 36px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            border: 3px solid #eaeaea;
        }
        .form-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        .form-group-icon {
            position: relative;
        }
        .form-group-icon input, .form-group-icon select {
            padding-left: 45px;
        }
        .form-control:read-only {
            background-color: #f8f9fa;
            border-color: #eaeaea;
        }
        .info-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 15px;
            font-weight: 500;
            color: #495057;
        }
        .card-header {
            background: #f8f9fa;
            border-bottom: 1px solid #eaeaea;
            padding: 15px 20px;
        }
    </style>
</head>
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
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-edit me-2"></i> Edit Profile
                            </h4>
                            <div class="d-flex gap-2">
                                <a href="profile.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Profile
                                </a>
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
                    <i class="bx bx-error-circle me-2"></i><?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Profile Edit Form -->
                <div class="card profile-edit-card">
                    <div class="profile-header">
                        <div class="avatar-large">
                            <?php echo strtoupper(substr($user['full_name'], 0, 2)); ?>
                        </div>
                        <h4><?php echo htmlspecialchars($user['full_name']); ?></h4>
                        <p class="mb-0 text-muted">
                            <i class="bx bx-briefcase me-1"></i>
                            <?php echo htmlspecialchars($user['business_name'] ?? $user['business_code'] ?? 'N/A'); ?>
                            <?php if(!empty($user['shop_name'])): ?>
                            • <?php echo htmlspecialchars($user['shop_name']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div class="card-body">
                        <form method="POST" id="profileForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-user form-icon"></i>
                                        <input type="text" class="form-control" name="full_name" 
                                               value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Username</label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-at form-icon"></i>
                                        <input type="text" class="form-control" 
                                               value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                                    </div>
                                    <small class="text-muted">Username cannot be changed</small>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-envelope form-icon"></i>
                                        <input type="email" class="form-control" name="email" 
                                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Phone Number</label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-phone form-icon"></i>
                                        <input type="tel" class="form-control" name="phone" 
                                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Role</label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-user-circle form-icon"></i>
                                        <input type="text" class="form-control" 
                                               value="<?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>" readonly>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Account Status</label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-circle form-icon"></i>
                                        <input type="text" class="form-control" 
                                               value="<?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>" readonly>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Business</label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-building form-icon"></i>
                                        <input type="text" class="form-control" 
                                               value="<?php echo htmlspecialchars($user['business_name'] ?? $user['business_code'] ?? 'N/A'); ?>" readonly>
                                    </div>
                                </div>
                                
                                <?php if(!empty($user['shop_name'])): ?>
                                <div class="col-md-6">
                                    <label class="form-label">Assigned Shop</label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-store form-icon"></i>
                                        <input type="text" class="form-control" 
                                               value="<?php echo htmlspecialchars($user['shop_name']); ?>" readonly>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Member Since</label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-calendar form-icon"></i>
                                        <input type="text" class="form-control" 
                                               value="<?php echo date('d M Y', strtotime($user['created_at'])); ?>" readonly>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Last Login</label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-log-in form-icon"></i>
                                        <input type="text" class="form-control" 
                                               value="<?php echo $user['last_login'] ? 
                                                      date('d M Y, h:i A', strtotime($user['last_login'])) : 
                                                      'Never logged in'; ?>" readonly>
                                    </div>
                                </div>
                                
                                <?php if(!empty($user['rfid_card_uid'])): ?>
                                <div class="col-md-6">
                                    <label class="form-label">RFID Card ID</label>
                                    <div class="form-group-icon">
                                        <i class="bx bx-card form-icon"></i>
                                        <input type="text" class="form-control" 
                                               value="<?php echo htmlspecialchars($user['rfid_card_uid']); ?>" readonly>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <a href="change_password.php" class="btn btn-outline-primary w-100">
                                        <i class="bx bx-lock me-1"></i> Change Password
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bx bx-save me-1"></i> Save Changes
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
// Form validation
document.getElementById('profileForm').addEventListener('submit', function(e) {
    const email = this.querySelector('[name="email"]');
    const name = this.querySelector('[name="full_name"]');
    
    if (email && !email.value.trim()) {
        e.preventDefault();
        alert('Email is required');
        email.focus();
        return;
    }
    
    if (name && !name.value.trim()) {
        e.preventDefault();
        alert('Full name is required');
        name.focus();
        return;
    }
    
    // Basic email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email.value)) {
        e.preventDefault();
        alert('Please enter a valid email address');
        email.focus();
        return;
    }
});
</script>
</body>
</html>