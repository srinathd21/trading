<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if it's a login request or password reset request
    if (isset($_POST['reset_email'])) {
        // Handle password reset request
        $email = trim($_POST['reset_email'] ?? '');
        
        if ($email === '') {
            $error = "Please enter your email address.";
        } else {
            try {
                // Check if email exists in database
                $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Generate reset token
                    $reset_token = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token valid for 1 hour
                    
                    // Store token in database
                    $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
                    $stmt->execute([$reset_token, $expires_at, $user['id']]);
                    
                    // Create reset link
                    $reset_link = "https://ecommer.in/billing/trading/reset_password.php?token=" . $reset_token;
                    
                    // Prepare email
                    $to = $user['email'];
                    $subject = "Password Reset Request - Ecommer";
                    $message = "
                    <html>
                    <head>
                        <title>Password Reset</title>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: #4361ee; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                            .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
                            .button { display: inline-block; background: #4361ee; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; }
                            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h2>Password Reset Request</h2>
                            </div>
                            <div class='content'>
                                <h3>Hello " . htmlspecialchars($user['full_name']) . ",</h3>
                                <p>You requested to reset your password for your Ecommer account.</p>
                                <p>Click the button below to reset your password:</p>
                                <p style='text-align: center; margin: 30px 0;'>
                                    <a href='" . $reset_link . "' class='button'>Reset Password</a>
                                </p>
                                <p>Or copy and paste this link in your browser:</p>
                                <p style='background: #e9ecef; padding: 15px; border-radius: 5px; word-break: break-all;'>
                                    " . $reset_link . "
                                </p>
                                <p><strong>Note:</strong> This link will expire in 1 hour.</p>
                                <p>If you didn't request a password reset, please ignore this email.</p>
                                <div class='footer'>
                                    <p>© " . date('Y') . " Ecommer. All rights reserved.</p>
                                    <p>This is an automated message, please do not reply.</p>
                                </div>
                            </div>
                        </div>
                    </body>
                    </html>
                    ";
                    
                    $headers = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= "From: Ecommer <noreply@ecommer.in>" . "\r\n";
                    $headers .= "X-Mailer: PHP/" . phpversion();
                    
                    // Send email
                    if (mail($to, $subject, $message, $headers)) {
                        $success = "Password reset link has been sent to your email.";
                    } else {
                        $error = "Failed to send email. Please try again.";
                    }
                } else {
                    $error = "No account found with this email address.";
                }
            } catch (Exception $e) {
                $error = "Failed to process reset request. Please try again.";
            }
        }
    } else {
        // Handle login request (existing code)
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if ($username === '' || $password === '') {
            $error = "Please enter both username and password.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, full_name, password, role, is_active, shop_id, business_id
                    FROM users
                    WHERE username = ? OR email = ?
                    LIMIT 1
                ");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password'])) {
                    if ($user['is_active'] != 1) {
                        $error = "Account deactivated.";
                    } else {
                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['business_id'] = (int)$user['business_id'];
                        $_SESSION['current_business_id'] = (int)$user['business_id'];
                        $_SESSION['current_shop_id'] = (int)$user['shop_id'];
                        $_SESSION['login_time'] = time();
                        
                        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                            ->execute([$user['id']]);
                        
                        // Check user role and redirect accordingly
                        if ($user['role'] === 'admin') {
                            // Admin can access all businesses - go to shop selector
                            header("Location: select_shop.php");
                            exit();
                        } elseif ($user['shop_id']) {
                            // Normal user - auto select their shop
                            $shop = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND is_active = 1")
                                       ->execute([$user['shop_id']]) ?
                                       $pdo->query("SELECT shop_name FROM shops WHERE id = {$user['shop_id']}")->fetch() : null;
                            if ($shop) {
                                $_SESSION['current_shop_id'] = $user['shop_id'];
                                $_SESSION['current_shop_name'] = $shop['shop_name'];
                                header("Location: dashboard.php");
                                exit();
                            } else {
                                $error = "Your assigned shop is not active.";
                            }
                        } else {
                            $error = "No shop assigned to your account.";
                        }
                    }
                } else {
                    $error = "Invalid username or password.";
                }
            } catch (Exception $e) {
                $error = "Login failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ecommer - Billing</title>
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon//favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon//favicon-16x16.png">
    <link rel="manifest" href="assets/favicon//site.webmanifest">
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
        
        .login-container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            animation: fadeIn 0.8s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .login-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .login-header {
            text-align: center;
        }
        
        .login-header .logo-big>img {
            width: 50%;
            margin-top: 20px;
        }
        
        .login-body {
            padding: 40px 35px;
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
        }
        
        .form-control {
            border: none;
            padding: 14px 15px;
            font-size: 16px;
            height: auto;
            box-shadow: none;
        }
        
        .form-control:focus {
            box-shadow: none;
        }
        
        .password-toggle {
            background: none;
            border: none;
            color: var(--gray-text);
            padding: 0 18px;
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .password-toggle:hover {
            color: var(--primary-color);
        }
        
        .btn-login {
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
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }
        
        .btn-login:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }
        
        @keyframes ripple {
            0% { transform: scale(0, 0); opacity: 0.5; }
            100% { transform: scale(20, 20); opacity: 0; }
        }
        
        .forgot-password {
            text-align: center;
            margin: 15px 0;
        }
        
        .forgot-password a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .forgot-password a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .alert {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: none;
            font-weight: 500;
        }
        
        .alert-danger {
            background-color: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .footer-text {
            text-align: center;
            margin-top: 30px;
            color: var(--gray-text);
            font-size: 14px;
        }
        
        .footer-text a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }
        
        .footer-text a:hover {
            text-decoration: underline;
        }
        
        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 25px;
            color: var(--gray-text);
            font-size: 14px;
        }
        
        .security-badge i {
            color: #4caf50;
        }
        
        /* Modal Styles */
        .modal-content {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
        }
        
        .modal-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 20px 25px;
        }
        
        .modal-title {
            font-weight: 600;
            font-size: 20px;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid var(--border-color);
        }
        
        .btn-reset {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        /* Loading animation */
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
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-big">
                    <img src="assets/logo.png" alt="Bakery Logo">
                </div>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="loginForm">
                    <div class="form-group">
                        <label class="form-label">Username or Email</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-user"></i>
                            </span>
                            <input type="text" 
                                   name="username" 
                                   class="form-control" 
                                   placeholder="Enter username or email" 
                                   required 
                                   autofocus
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input type="password" 
                                   name="password" 
                                   id="password" 
                                   class="form-control" 
                                   placeholder="Enter your password" 
                                   required>
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-login" id="loginBtn">
                        <span id="btnText">Sign In</span>
                        <span id="loading" style="display:none;">
                            <span class="spinner"></span> Signing In...
                        </span>
                    </button>
                    
                    <div class="forgot-password">
                        <a href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                            <i class="fas fa-key me-1"></i> Forgot Password?
                        </a>
                    </div>
                    
                    <div class="security-badge">
                        <i class="fas fa-shield-alt"></i>
                        <span>Secured with SSL • Passwords encrypted</span>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="forgotPasswordModalLabel">
                        <i class="fas fa-key me-2"></i>Reset Password
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="resetPasswordForm">
                    <div class="modal-body">
                        <p class="mb-4">Enter your registered email address. We'll send you a link to reset your password.</p>
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <input type="email" 
                                       name="reset_email" 
                                       class="form-control" 
                                       placeholder="Enter your email address" 
                                       required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-reset" id="resetBtn">
                            <span id="resetBtnText">Send Reset Link</span>
                            <span id="resetLoading" style="display:none;">
                                <span class="spinner"></span> Sending...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (password.type === 'password') {
                password.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                password.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Form submission loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btnText');
            const loading = document.getElementById('loading');
            
            // Disable button and show loading
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline';
        });
        
        // Reset password form submission
        document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('resetBtn');
            const btnText = document.getElementById('resetBtnText');
            const loading = document.getElementById('resetLoading');
            
            // Disable button and show loading
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline';
        });
        
        // Clear success message when modal is closed
        document.getElementById('forgotPasswordModal').addEventListener('hidden.bs.modal', function () {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                successAlert.remove();
            }
        });
        
        // Clear error message when modal is opened
        document.getElementById('forgotPasswordModal').addEventListener('show.bs.modal', function () {
            const errorAlert = document.querySelector('.alert-danger');
            if (errorAlert) {
                errorAlert.remove();
            }
        });
    </script>
</body>
</html>