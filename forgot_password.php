<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$message = '';
$error = '';

// Include the mail function
require_once 'mail_function.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = trim($_POST['role'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($role) || empty($email)) {
        $error = "Please select your role and enter your registered email.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // Check if user exists with the given role and email
            $stmt = $pdo->prepare("
                SELECT id, full_name, username, role, email, is_active 
                FROM users 
                WHERE role = ? AND email = ? 
                LIMIT 1
            ");
            $stmt->execute([$role, $email]);
            $user = $stmt->fetch();
            
            if ($user) {
                if ($user['is_active'] != 1) {
                    $error = "Account is deactivated. Please contact administrator.";
                } else {
                    // Check if there's an existing unused token
                    $stmt = $pdo->prepare("
                        SELECT id FROM password_reset_tokens 
                        WHERE user_id = ? AND used = 0 AND expires_at > NOW()
                        LIMIT 1
                    ");
                    $stmt->execute([$user['id']]);
                    $existing_token = $stmt->fetch();
                    
                    if ($existing_token) {
                        // Use existing token if still valid
                        $message = "A password reset link has already been sent to your email. Please check your inbox (and spam folder). If you didn't receive it, please wait a few minutes and try again.";
                    } else {
                        // Generate a unique token for password reset
                        $token = bin2hex(random_bytes(32));
                        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                        
                        // Store token in database
                        $stmt = $pdo->prepare("
                            INSERT INTO password_reset_tokens (user_id, token, expires_at) 
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$user['id'], $token, $expires_at]);
                        
                        // Send password reset email using the mail function
                        if (sendPasswordResetEmail($user['email'], $user['full_name'], $token)) {
                            $message = "Password reset link has been sent to your email address. Please check your inbox (and spam folder). The link will expire in 1 hour.";
                        } else {
                            $error = "Failed to send email. Please try again later or contact support at support@ecommer.in";
                        }
                    }
                }
            } else {
                $error = "No account found with this role and email combination.";
            }
        } catch (Exception $e) {
            $error = "An error occurred. Please try again later.";
            // Log error for debugging
            error_log("Forgot password error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Ecommer Billing</title>
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="assets/favicon/site.webmanifest">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --primary-dark: #3651d4;
            --secondary-color: #7209b7;
            --accent-color: #f72585;
            --success-color: #4cc9f0;
            --light-bg: #f8f9fa;
            --dark-text: #2b2d42;
            --gray-text: #6c757d;
            --border-color: #e2e8f0;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 20px 50px rgba(67, 97, 238, 0.15);
            --radius: 16px;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--dark-text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin: 0;
        }
        
        .forgot-container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            animation: fadeIn 0.8s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .forgot-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .forgot-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .forgot-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px 30px;
            text-align: center;
        }
        
        .forgot-header h2 {
            margin: 0;
            font-weight: 600;
        }
        
        .forgot-header p {
            margin: 10px 0 0;
            opacity: 0.9;
            font-size: 15px;
        }
        
        .forgot-body {
            padding: 40px 35px;
        }
        
        .back-to-login {
            text-align: center;
            margin-top: 25px;
        }
        
        .back-to-login a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        
        .back-to-login a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
            transform: translateX(-2px);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark-text);
            font-size: 15px;
        }
        
        .input-group {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid var(--border-color);
            transition: all 0.3s;
        }
        
        .input-group:focus-within {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        .input-group-text {
            background-color: white;
            border: none;
            color: var(--gray-text);
            padding: 0 18px;
            min-width: 45px;
        }
        
        .form-control, .form-select {
            border: none;
            padding: 14px 15px;
            font-size: 16px;
            height: auto;
            box-shadow: none;
        }
        
        .form-control:focus, .form-select:focus {
            box-shadow: none;
        }
        
        .btn-submit {
            width: 100%;
            padding: 15px;
            font-weight: 600;
            font-size: 17px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 12px;
            color: white;
            margin-top: 10px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
        }
        
        .btn-submit:disabled {
            opacity: 0.7;
            transform: none !important;
            box-shadow: none !important;
        }
        
        .alert {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: none;
            font-weight: 500;
        }
        
        .alert-success {
            background-color: #e8f6ef;
            color: #10b981;
            border-left: 4px solid #10b981;
        }
        
        .alert-danger {
            background-color: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .alert i {
            margin-right: 8px;
        }
        
        .info-box {
            background: linear-gradient(135deg, #f0f4ff 0%, #e6f0ff 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary-color);
        }
        
        .info-box h5 {
            color: var(--primary-color);
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .info-box p {
            margin: 0;
            font-size: 14px;
            color: var(--gray-text);
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .instruction-text {
            font-size: 14px;
            color: var(--gray-text);
            margin-top: 5px;
        }
        
        .email-tips {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 12px;
            padding: 15px;
            margin-top: 20px;
            border-left: 4px solid #4caf50;
        }
        
        .email-tips h6 {
            color: #2e7d32;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .email-tips ul {
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            color: #388e3c;
        }
        
        .email-tips li {
            margin-bottom: 5px;
        }
        
        .support-contact {
            text-align: center;
            margin-top: 20px;
            padding: 15px;
            background: linear-gradient(135deg, #fff3e0 0%, #ffecb3 100%);
            border-radius: 12px;
            border-left: 4px solid #ff9800;
        }
        
        .support-contact p {
            margin: 0;
            font-size: 13px;
            color: #e65100;
        }
        
        .support-contact a {
            color: #d84315;
            font-weight: 500;
            text-decoration: none;
        }
        
        .support-contact a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <div class="forgot-card">
            <div class="forgot-header">
                <h2>Forgot Password</h2>
                <p>Enter your account details to reset your password</p>
            </div>
            
            <div class="forgot-body">
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <div class="info-box">
                    <h5><i class="fas fa-info-circle me-2"></i>How to reset your password</h5>
                    <p>Select your role and enter your registered email address. We'll send you a password reset link that will expire in 1 hour.</p>
                </div>
                
                <form method="POST" id="forgotForm">
                    <div class="form-group">
                        <label class="form-label">Your Role</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-user-tag"></i>
                            </span>
                            <select name="role" class="form-select" required>
                                <option value="">Select your role</option>
                                <option value="admin" <?= isset($_POST['role']) && $_POST['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="shop_manager" <?= isset($_POST['role']) && $_POST['role'] == 'shop_manager' ? 'selected' : '' ?>>Shop Manager</option>
                                <option value="seller" <?= isset($_POST['role']) && $_POST['role'] == 'seller' ? 'selected' : '' ?>>Seller</option>
                                <option value="cashier" <?= isset($_POST['role']) && $_POST['role'] == 'cashier' ? 'selected' : '' ?>>Cashier</option>
                                <option value="stock_manager" <?= isset($_POST['role']) && $_POST['role'] == 'stock_manager' ? 'selected' : '' ?>>Stock Manager</option>
                                <option value="warehouse_manager" <?= isset($_POST['role']) && $_POST['role'] == 'warehouse_manager' ? 'selected' : '' ?>>Warehouse Manager</option>
                                <option value="field_executive" <?= isset($_POST['role']) && $_POST['role'] == 'field_executive' ? 'selected' : '' ?>>Field Executive</option>
                                <option value="staff" <?= isset($_POST['role']) && $_POST['role'] == 'staff' ? 'selected' : '' ?>>Staff</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Registered Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-envelope"></i>
                            </span>
                            <input type="email" 
                                   name="email" 
                                   class="form-control" 
                                   placeholder="Enter your registered email" 
                                   required
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="instruction-text">
                            Enter the exact email address associated with your account.
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <span id="btnText">Send Reset Link</span>
                        <span id="loading" style="display:none;">
                            <span class="spinner"></span> Sending...
                        </span>
                    </button>
                    
                    <div class="email-tips">
                        <h6><i class="fas fa-lightbulb me-1"></i> Email Not Received?</h6>
                        <ul>
                            <li>Check your spam or junk folder</li>
                            <li>Verify you entered the correct email address</li>
                            <li>Wait a few minutes - email delivery can take time</li>
                            <li>Ensure your email inbox is not full</li>
                        </ul>
                    </div>
                    
                    <div class="support-contact">
                        <p><i class="fas fa-life-ring me-1"></i> Still having trouble? <a href="mailto:support@ecommer.in">Contact Support</a></p>
                    </div>
                    
                    <div class="back-to-login">
                        <a href="login.php">
                            <i class="fas fa-arrow-left"></i> Back to Login
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Form submission loading state
        document.getElementById('forgotForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const loading = document.getElementById('loading');
            
            // Disable button and show loading
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline';
            
            // Prevent multiple submissions
            setTimeout(() => {
                btn.disabled = false;
                btnText.style.display = 'inline';
                loading.style.display = 'none';
            }, 5000); // Re-enable after 5 seconds if still on page
        });
        
        // Auto-focus on first input
        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.querySelector('select[name="role"]');
            if (roleSelect) {
                roleSelect.focus();
            }
        });
    </script>
</body>
</html>