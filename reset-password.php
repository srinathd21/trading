<?php
session_start();
require_once 'config/database.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

// Verify token
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $token && $email) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM password_resets 
            WHERE token = ? AND email = ? AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token, urldecode($email)]);
        $reset = $stmt->fetch();
        
        if (!$reset) {
            $error = "Invalid or expired reset link. Please request a new password reset.";
        }
    } catch (Exception $e) {
        $error = "Error verifying reset link.";
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $token = $_POST['token'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        try {
            // Verify token again
            $stmt = $pdo->prepare("
                SELECT * FROM password_resets 
                WHERE token = ? AND email = ? AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$token, $email]);
            $reset = $stmt->fetch();
            
            if ($reset) {
                // Update password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET password = ? 
                    WHERE email = ? AND is_active = 1
                ");
                $stmt->execute([$hashed_password, $email]);
                
                // Delete used token
                $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
                
                $success = "Password reset successfully! You can now <a href='index.php'>login</a> with your new password.";
            } else {
                $error = "Invalid or expired reset link.";
            }
        } catch (Exception $e) {
            $error = "Error resetting password.";
        }
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
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .reset-card {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 40px;
        }
        .logo-reset {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo-reset img {
            max-width: 200px;
        }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="logo-reset">
            <img src="assets/logo.png" alt="Ecommer Logo">
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && $token && $email && !$error): ?>
            <form method="POST" id="resetForm">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="email" value="<?= htmlspecialchars(urldecode($email)) ?>">
                
                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" 
                               name="new_password" 
                               id="new_password" 
                               class="form-control" 
                               placeholder="Enter new password" 
                               required
                               minlength="6">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" 
                               name="confirm_password" 
                               id="confirm_password" 
                               class="form-control" 
                               placeholder="Confirm new password" 
                               required
                               minlength="6">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 py-2">Reset Password</button>
                
                <div class="text-center mt-3">
                    <a href="index.php" class="text-decoration-none">
                        <i class="fas fa-arrow-left me-1"></i> Back to Login
                    </a>
                </div>
            </form>
        <?php elseif (!$error && !$success): ?>
            <div class="text-center">
                <div class="alert alert-info">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    No reset request found. Please request a password reset from the login page.
                </div>
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Login
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Form validation
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long!');
                return false;
            }
        });
    </script>
</body>
</html>