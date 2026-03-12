<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';


$success = $error = '';

// Get all active shops for selection
$shops_query = $pdo->query("SELECT id, shop_name, shop_code FROM shops WHERE is_active = 1 ORDER BY shop_name");
$shops = $shops_query->fetchAll(PDO::FETCH_ASSOC);

// Process registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $role      = $_POST['role'] ?? '';
    $shop_id   = isset($_POST['shop_id']) ? (int)$_POST['shop_id'] : 0;
    $access_shops = isset($_POST['access_shops']) ? $_POST['access_shops'] : [];

    // Validation
    $required_fields = ['full_name', 'username', 'email', 'password', 'role'];
    foreach ($required_fields as $field) {
        if (empty($$field)) {
            $error = "All required fields are missing.";
            break;
        }
    }

    if (!$error) {
        if ($password !== $confirm) {
            $error = "Passwords do not match.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            // Secure role filtering
            $allowed_roles = ['admin', 'shop_manager', 'seller', 'cashier', 'stock_manager', 'warehouse_manager', 'field_executive', 'staff'];
            if (!in_array($role, $allowed_roles)) {
                $role = 'staff'; // Force default
            }

            // Validate shop access
            if ($role !== 'admin') {
                if ($shop_id <= 0 && empty($access_shops)) {
                    $error = "Please assign at least one shop for non-admin users.";
                }
            }

            if (!$error) {
                try {
                    $pdo->beginTransaction();

                    // Check duplicate username/email
                    $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                    $check->execute([$username, $email]);
                    if ($check->rowCount() > 0) {
                        throw new Exception("Username or Email already exists.");
                    }

                    $hashed = password_hash($password, PASSWORD_DEFAULT);

                    // For admin, shop_id should be NULL
                    $final_shop_id = ($role === 'admin') ? null : $shop_id;

                    // Insert user
                    $stmt = $pdo->prepare("INSERT INTO users 
                        (full_name, username, email, phone, password, role, shop_id, is_active, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                    $stmt->execute([$full_name, $username, $email, $phone, $hashed, $role, $final_shop_id]);
                    
                    $user_id = $pdo->lastInsertId();

                    // Handle shop access for non-admin users
                    if ($role !== 'admin') {
                        // Determine shop permissions based on role
                        $can_sell = in_array($role, ['seller', 'cashier', 'shop_manager']);
                        $can_manage_stock = in_array($role, ['stock_manager', 'warehouse_manager', 'shop_manager']);
                        $can_view_reports = true; // All non-admin roles can view reports

                        // If specific shops selected, use those
                        if (!empty($access_shops)) {
                            foreach ($access_shops as $access_shop_id) {
                                $access_shop_id = (int)$access_shop_id;
                                if ($access_shop_id > 0) {
                                    $access_stmt = $pdo->prepare("
                                        INSERT INTO user_shop_access 
                                        (user_id, shop_id, can_sell, can_manage_stock, can_view_reports, created_at) 
                                        VALUES (?, ?, ?, ?, ?, NOW())
                                    ");
                                    $access_stmt->execute([
                                        $user_id, 
                                        $access_shop_id, 
                                        $can_sell, 
                                        $can_manage_stock, 
                                        $can_view_reports
                                    ]);
                                }
                            }
                        } 
                        // If single shop selected (for backward compatibility)
                        elseif ($shop_id > 0) {
                            $access_stmt = $pdo->prepare("
                                INSERT INTO user_shop_access 
                                (user_id, shop_id, can_sell, can_manage_stock, can_view_reports, created_at) 
                                VALUES (?, ?, ?, ?, ?, NOW())
                            ");
                            $access_stmt->execute([
                                $user_id, 
                                $shop_id, 
                                $can_sell, 
                                $can_manage_stock, 
                                $can_view_reports
                            ]);
                        }
                    }

                    $pdo->commit();

                    $success = "
                        <div class='text-center'>
                            <i class='bx bx-check-circle fs-1 text-success mb-3'></i>
                            <h4>Account Created Successfully!</h4>
                            <p class='mb-3'>User: <strong>{$username}</strong> | Role: <strong>{$role}</strong></p>
                            <div class='mt-3'>
                                <a href='login.php' class='btn btn-primary'>
                                    <i class='bx bx-log-in'></i> Go to Login
                                </a>
                            </div>
                        </div>
                    ";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Registration failed: " . $e->getMessage();
                }
            }
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Register | Vidhya Traders</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Vidhya Traders - Trading & Distribution ERP" name="description" />
    <meta name="author" content="Vidhya Traders" />

    <!-- Favicon -->
    <link rel="shortcut icon" href="assets/images/favicon.ico">

    <!-- Template CSS -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />

    <!-- Icons & Fonts -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Nunito', sans-serif;
            padding: 20px;
        }
        .auth-card {
            max-width: 600px;
            margin: 40px auto;
            background: white;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0,0,0,0.2);
        }
        .auth-header {
            background: linear-gradient(135deg, #5b73e8, #4a5fd1);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .auth-header h3 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .auth-body {
            padding: 45px 40px;
        }
        .form-control, .form-select {
            border-radius: 12px;
            padding: 14px 16px;
            border: 1px solid #e0e0e0;
            font-size: 15px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #5b73e8;
            box-shadow: 0 0 0 0.2rem rgba(91, 115, 232, 0.15);
        }
        .btn-register {
            background: linear-gradient(135deg, #5b73e8, #4a5fd1);
            border: none;
            padding: 14px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(91, 115, 232, 0.4);
        }
        .input-group-text {
            border-radius: 12px 0 0 12px;
            background: #f8f9fa;
        }
        .shop-select-container {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 15px;
            margin-top: 10px;
        }
        .shop-checkbox {
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .shop-checkbox:last-child {
            border-bottom: none;
        }
        .role-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
            font-size: 14px;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin: 0 10px;
            position: relative;
        }
        .step.active {
            background: #5b73e8;
            color: white;
        }
        .step::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            width: 40px;
            height: 2px;
            background: #e9ecef;
        }
        .step:last-child::after {
            display: none;
        }
        .form-section {
            display: none;
        }
        .form-section.active {
            display: block;
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <div class="auth-header">
        <h3><i class="bx bx-store-alt"></i> Vidhya Traders</h3>
        <p class="mb-0 mt-3 fs-5">New User Registration</p>
    </div>

    <div class="auth-body">
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step active" id="step1">1</div>
            <div class="step" id="step2">2</div>
            <div class="step" id="step3">3</div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success text-center border-0 py-4">
                <?= $success ?>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bx bx-error-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="registrationForm">
                <!-- Step 1: Basic Information -->
                <div class="form-section active" id="section1">
                    <h5 class="mb-4"><i class="bx bx-user-circle"></i> Personal Information</h5>
                    
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label"><i class="bx bx-user"></i> Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required 
                                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bx bx-id-card"></i> Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required 
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                            <small class="text-muted">Used for login</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bx bx-envelope"></i> Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required 
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label"><i class="bx bx-phone"></i> Phone (Optional)</label>
                            <div class="input-group">
                                <span class="input-group-text">+91</span>
                                <input type="text" name="phone" class="form-control" maxlength="10" 
                                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="col-12 mt-4">
                            <button type="button" class="btn btn-primary w-100" onclick="nextStep(2)">
                                Next <i class="bx bx-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Security & Role -->
                <div class="form-section" id="section2">
                    <h5 class="mb-4"><i class="bx bx-shield-alt"></i> Security & Role</h5>
                    
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label"><i class="bx bx-lock-alt"></i> Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="password" class="form-control" required minlength="6">
                            <div class="form-text">
                                <small>Must be at least 6 characters long</small>
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label"><i class="bx bx-lock"></i> Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                            <div class="form-text">
                                <small id="passwordMatch"></small>
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label"><i class="bx bx-user-badge"></i> User Role <span class="text-danger">*</span></label>
                            <select name="role" id="roleSelect" class="form-select" required>
                                <option value="">-- Select Role --</option>
                                <option value="admin" <?= ($_POST['role']??'')==='admin'?'selected':'' ?>>Administrator</option>
                                <option value="shop_manager" <?= ($_POST['role']??'')==='shop_manager'?'selected':'' ?>>Shop Manager</option>
                                <option value="seller" <?= ($_POST['role']??'')==='seller'?'selected':'' ?>>Seller (POS)</option>
                                <option value="cashier" <?= ($_POST['role']??'')==='cashier'?'selected':'' ?>>Cashier</option>
                                <option value="stock_manager" <?= ($_POST['role']??'')==='stock_manager'?'selected':'' ?>>Stock Manager</option>
                                <option value="warehouse_manager" <?= ($_POST['role']??'')==='warehouse_manager'?'selected':'' ?>>Warehouse Manager</option>
                                <option value="field_executive" <?= ($_POST['role']??'')==='field_executive'?'selected':'' ?>>Field Executive</option>
                                <option value="staff" <?= ($_POST['role']??'')==='staff'?'selected':'' ?>>Staff</option>
                            </select>
                            
                            <div class="role-info mt-2" id="roleDescription">
                                <strong>Role Description:</strong>
                                <div id="roleDescText">Select a role to see description</div>
                            </div>
                        </div>

                        <div class="col-12 mt-4">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary w-50" onclick="prevStep(1)">
                                    <i class="bx bx-chevron-left"></i> Back
                                </button>
                                <button type="button" class="btn btn-primary w-50" onclick="nextStep(3)">
                                    Next <i class="bx bx-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Shop Assignment -->
                <div class="form-section" id="section3">
                    <h5 class="mb-4"><i class="bx bx-store"></i> Shop Assignment</h5>
                    
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div id="shopAssignment">
                                <!-- Dynamic content based on role -->
                            </div>
                        </div>

                        <div class="col-12 mt-4">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary w-50" onclick="prevStep(2)">
                                    <i class="bx bx-chevron-left"></i> Back
                                </button>
                                <button type="submit" class="btn btn-primary w-50 btn-register">
                                    <i class="bx bx-user-plus"></i> Create Account
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>

        <div class="text-center mt-4">
            <p class="text-muted mb-0">
                Already have an account?
                <a href="login.php" class="fw-bold text-primary">Sign In</a>
            </p>
        </div>

        <div class="text-center mt-4 text-muted small">
            © <?= date('Y') ?> Vidhya Traders. All rights reserved.
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="assets/libs/jquery/jquery.min.js"></script>
<script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

<script>
// Role descriptions
const roleDescriptions = {
    'admin': 'Full system access. Can manage all shops, users, and settings.',
    'shop_manager': 'Manage specific shops. Can oversee sales, stock, and staff in assigned shops.',
    'seller': 'Process sales at POS. Can create invoices and handle customer transactions.',
    'cashier': 'Handle payments and cash management. Can process sales and returns.',
    'stock_manager': 'Manage inventory across shops. Can update stock levels and transfers.',
    'warehouse_manager': 'Manage warehouse operations and bulk stock movements.',
    'field_executive': 'Field operations and customer visits. Limited system access.',
    'staff': 'General staff with view-only access to assigned areas.'
};

// Step navigation
function nextStep(step) {
    // Validate current step
    if (step === 2 && !validateStep1()) return;
    if (step === 3 && !validateStep2()) return;
    
    // Hide all sections
    document.querySelectorAll('.form-section').forEach(section => {
        section.classList.remove('active');
    });
    
    // Show target section
    document.getElementById('section' + step).classList.add('active');
    
    // Update step indicator
    document.querySelectorAll('.step').forEach(stepEl => {
        stepEl.classList.remove('active');
    });
    document.getElementById('step' + step).classList.add('active');
}

function prevStep(step) {
    nextStep(step);
}

// Validation functions
function validateStep1() {
    const fullName = document.querySelector('input[name="full_name"]').value.trim();
    const username = document.querySelector('input[name="username"]').value.trim();
    const email = document.querySelector('input[name="email"]').value.trim();
    
    if (!fullName || !username || !email) {
        alert('Please fill in all required fields in Step 1.');
        return false;
    }
    
    // Simple email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        alert('Please enter a valid email address.');
        return false;
    }
    
    return true;
}

function validateStep2() {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const role = document.getElementById('roleSelect').value;
    
    if (!password || !confirmPassword || !role) {
        alert('Please fill in all required fields in Step 2.');
        return false;
    }
    
    if (password.length < 6) {
        alert('Password must be at least 6 characters long.');
        return false;
    }
    
    if (password !== confirmPassword) {
        alert('Passwords do not match.');
        return false;
    }
    
    return true;
}

// Password match validation
document.getElementById('confirm_password').addEventListener('input', function() {
    const password = document.getElementById('password').value;
    const confirm = this.value;
    const matchText = document.getElementById('passwordMatch');
    
    if (confirm === '') {
        matchText.textContent = '';
        matchText.className = '';
    } else if (password === confirm) {
        matchText.textContent = '✓ Passwords match';
        matchText.className = 'text-success';
    } else {
        matchText.textContent = '✗ Passwords do not match';
        matchText.className = 'text-danger';
    }
});

// Role selection handler
document.getElementById('roleSelect').addEventListener('change', function() {
    const role = this.value;
    const descText = document.getElementById('roleDescText');
    
    if (role && roleDescriptions[role]) {
        descText.textContent = roleDescriptions[role];
    } else {
        descText.textContent = 'Select a role to see description';
    }
    
    // Update shop assignment based on role
    updateShopAssignment(role);
});

// Update shop assignment based on role
function updateShopAssignment(role) {
    const shopAssignmentDiv = document.getElementById('shopAssignment');
    
    <?php if (count($shops) > 0): ?>
        const shops = <?php echo json_encode($shops); ?>;
        
        if (role === 'admin') {
            // Admin can access all shops
            shopAssignmentDiv.innerHTML = `
                <div class="alert alert-info">
                    <i class="bx bx-info-circle"></i>
                    <strong>Administrator Access:</strong> Full access to all shops in the system.
                </div>
                <input type="hidden" name="shop_id" value="0">
            `;
        } else if (role === 'shop_manager' || role === 'stock_manager' || role === 'warehouse_manager') {
            // These roles can manage multiple shops
            shopAssignmentDiv.innerHTML = `
                <label class="form-label mb-3">Select Shops for Access (Multiple Selection):</label>
                <div class="shop-select-container">
                    ${shops.map(shop => `
                        <div class="form-check shop-checkbox">
                            <input class="form-check-input" type="checkbox" name="access_shops[]" 
                                   value="${shop.id}" id="shop_${shop.id}">
                            <label class="form-check-label" for="shop_${shop.id}">
                                <strong>${shop.shop_name}</strong> (${shop.shop_code})
                            </label>
                        </div>
                    `).join('')}
                </div>
                <small class="text-muted mt-2 d-block">
                    <i class="bx bx-info-circle"></i> User will have access to selected shops only.
                </small>
                <input type="hidden" name="shop_id" value="0">
            `;
        } else if (['seller', 'cashier', 'field_executive', 'staff'].includes(role)) {
            // Single shop assignment for these roles
            shopAssignmentDiv.innerHTML = `
                <label class="form-label">Assign to Shop:</label>
                <select name="shop_id" class="form-select" required>
                    <option value="">-- Select Shop --</option>
                    ${shops.map(shop => `
                        <option value="${shop.id}">${shop.shop_name} (${shop.shop_code})</option>
                    `).join('')}
                </select>
                <small class="text-muted mt-2 d-block">
                    <i class="bx bx-info-circle"></i> User will be assigned to this shop.
                </small>
            `;
        } else {
            shopAssignmentDiv.innerHTML = `
                <div class="alert alert-warning">
                    Please select a valid role first.
                </div>
            `;
        }
    <?php else: ?>
        shopAssignmentDiv.innerHTML = `
            <div class="alert alert-warning">
                <i class="bx bx-error"></i>
                No shops available in the system. Please contact administrator.
            </div>
        `;
    <?php endif; ?>
}

// Form submission validation
document.getElementById('registrationForm').addEventListener('submit', function(e) {
    const role = document.getElementById('roleSelect').value;
    const shopId = document.querySelector('input[name="shop_id"], select[name="shop_id"]');
    const accessShops = document.querySelectorAll('input[name="access_shops[]"]:checked');
    
    // For non-admin users, validate shop assignment
    if (role !== 'admin') {
        if (role === 'shop_manager' || role === 'stock_manager' || role === 'warehouse_manager') {
            if (accessShops.length === 0) {
                e.preventDefault();
                alert('Please select at least one shop for this role.');
                return false;
            }
        } else {
            if (!shopId || shopId.value === '' || shopId.value === '0') {
                e.preventDefault();
                alert('Please select a shop for this user.');
                return false;
            }
        }
    }
    
    return true;
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const initialRole = document.getElementById('roleSelect').value;
    if (initialRole) {
        updateShopAssignment(initialRole);
    }
});
</script>
</body>
</html>