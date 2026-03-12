<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

// Verify token and check expiration
if ($token) {
    try {
        $stmt = $pdo->prepare("SELECT id, reset_token_expires FROM users WHERE reset_token = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            $expires = strtotime($user['reset_token_expires']);
            $now = time();
            
            if ($expires > $now) {
                // Token is valid
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $password = $_POST['password'] ?? '';
                    $confirm_password = $_POST['confirm_password'] ?? '';
                    
                    if (strlen($password) < 8) {
                        $error = "Password must be at least 8 characters long.";
                    } elseif ($password !== $confirm_password) {
                        $error = "Passwords do not match.";
                    } else {
                        // Hash new password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Update password and clear reset token
                        $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?");
                        $stmt->execute([$hashed_password, $user['id']]);
                        
                        $success = "Password has been reset successfully! You can now login with your new password.";
                        $token = ''; // Clear token to show success message
                    }
                }
            } else {
                $error = "Password reset link has expired. Please request a new one.";
                $token = '';
            }
        } else {
            $error = "Invalid password reset link.";
            $token = '';
        }
    } catch (Exception $e) {
        $error = "An error occurred. Please try again.";
        $token = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Ecommer</title>
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
        
        .reset-container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            animation: fadeIn 0.8s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .reset-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .reset-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .reset-header {
            text-align: center;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 30px 20px;
        }
        
        .reset-header img {
            width: 40%;
            margin-bottom: 15px;
        }
        
        .reset-body {
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
        
        .btn-reset {
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
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
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
        
        .password-strength {
            margin-top: 8px;
            font-size: 14px;
            color: var(--gray-text);
        }
        
        .strength-meter {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            width: 0;
            border-radius: 2px;
            transition: all 0.3s;
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
    <div class="reset-container">
        <div class="reset-card">
            <div class="reset-header">
                <img src="assets/logo.png" alt="Ecommer Logo">
                <h3>Reset Your Password</h3>
            </div>
            
            <div class="reset-body">
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
                        <div class="mt-3">
                            <a href="index.php" class="btn btn-reset">
                                <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                            </a>
                        </div>
                    </div>
                <?php elseif ($token): ?>
                    <p class="mb-4">Enter your new password below.</p>
                    <form method="POST" id="resetForm">
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input type="password" 
                                       name="password" 
                                       id="password" 
                                       class="form-control" 
                                       placeholder="Enter new password" 
                                       required
                                       minlength="8">
                                <button type="button" class="password-toggle" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength">
                                <div>Password strength: <span id="strengthText">Weak</span></div>
                                <div class="strength-meter">
                                    <div class="strength-fill" id="strengthMeter"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Confirm Password</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input type="password" 
                                       name="confirm_password" 
                                       id="confirmPassword" 
                                       class="form-control" 
                                       placeholder="Confirm new password" 
                                       required
                                       minlength="8">
                                <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-reset" id="resetBtn">
                            <span id="btnText">Reset Password</span>
                            <span id="loading" style="display:none;">
                                <span class="spinner"></span> Resetting...
                            </span>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Invalid or expired reset link. Please request a new password reset.
                        <div class="mt-3">
                            <a href="index.php" class="btn btn-reset">
                                <i class="fas fa-arrow-left me-2"></i>Back to Login
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="footer-text">
                    <p>© <?= date('Y') ?> Ecommer. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        function setupPasswordToggle(inputId, toggleId) {
            const toggleBtn = document.getElementById(toggleId);
            const passwordInput = document.getElementById(inputId);
            
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            }
        }
        
        // Setup password toggles
        setupPasswordToggle('password', 'togglePassword');
        setupPasswordToggle('confirmPassword', 'toggleConfirmPassword');
        
        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthMeter = document.getElementById('strengthMeter');
        const strengthText = document.getElementById('strengthText');
        
        if (passwordInput && strengthMeter && strengthText) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                
                // Length check
                if (password.length >= 8) strength++;
                if (password.length >= 12) strength++;
                
                // Character variety checks
                if (/[a-z]/.test(password)) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;
                
                // Update meter
                const width = Math.min(100, (strength / 6) * 100);
                strengthMeter.style.width = width + '%';
                
                // Update color and text
                if (width < 40) {
                    strengthMeter.style.backgroundColor = '#dc3545';
                    strengthText.textContent = 'Weak';
                } else if (width < 70) {
                    strengthMeter.style.backgroundColor = '#ffc107';
                    strengthText.textContent = 'Medium';
                } else {
                    strengthMeter.style.backgroundColor = '#28a745';
                    strengthText.textContent = 'Strong';
                }
            });
        }
        
        // Form submission loading state
        const resetForm = document.getElementById('resetForm');
        if (resetForm) {
            resetForm.addEventListener('submit', function(e) {
                const btn = document.getElementById('resetBtn');
                const btnText = document.getElementById('btnText');
                const loading = document.getElementById('loading');
                
                // Validate passwords match
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    return;
                }
                
                // Disable button and show loading
                btn.disabled = true;
                btnText.style.display = 'none';
                loading.style.display = 'inline';
            });
        }
    </script>
</body>
</html>