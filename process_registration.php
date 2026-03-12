<?php
require_once 'config/database.php';
session_start();

try {
    // Create database connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
   
    // Start transaction
    $pdo->beginTransaction();
   
    // Handle logo upload
    $logoPath = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/logos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileName = 'logo_' . time() . '_' . basename($_FILES['logo']['name']);
        $fileName = preg_replace("/[^a-zA-Z0-9._-]/", "", $fileName);
        $targetPath = $uploadDir . $fileName;
        
        $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif'];
        $fileType = $_FILES['logo']['type'];
        
        if (in_array($fileType, $allowedTypes) && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
                $logoPath = $targetPath;
            }
        }
    }
   
    // 1. Insert into businesses table
    $businessStmt = $pdo->prepare("
        INSERT INTO businesses (
            business_name, business_code, owner_name, phone, email,
            address, gstin, logo_path, is_active, created_at, updated_at,
            cloud_expiry_date, cloud_subscription_status, cloud_plan
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?)
    ");
   
    $businessData = [
        $_POST['business_name'],
        $_POST['business_code'],
        $_POST['owner_name'],
        $_POST['phone'],
        $_POST['email'] ?? null,
        $_POST['address'] ?? null,
        $_POST['gstin'] ?? null,
        $logoPath,
        1,
        $_POST['cloud_expiry_date'] ? $_POST['cloud_expiry_date'] : null,
        $_POST['cloud_subscription_status'] ?? 'trial',
        $_POST['cloud_plan'] ?? 'free'
    ];
   
    $businessStmt->execute($businessData);
    $businessId = $pdo->lastInsertId();
   
    // 2. Insert into shops table
    $shopStmt = $pdo->prepare("
        INSERT INTO shops (
            business_id, shop_name, shop_code, is_warehouse, location_type,
            address, phone, gstin, is_active, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
   
    $locationType = ($_POST['is_warehouse'] ?? 0) == 1 ? 'warehouse' : 'shop';
   
    $shopData = [
        $businessId,
        $_POST['shop_name'],
        $_POST['shop_code'],
        $_POST['is_warehouse'] ?? 0,
        $locationType,
        $_POST['shop_address'] ?? null,
        $_POST['shop_phone'] ?? null,
        $_POST['shop_gstin'] ?? null,
        1
    ];
   
    $shopStmt->execute($shopData);
    $shopId = $pdo->lastInsertId();
   
    // 3. Insert into users table
    $userStmt = $pdo->prepare("
        INSERT INTO users (
            username, email, password, role, shop_id, business_id,
            full_name, phone, rfid_card_uid, is_active, last_login, created_at
        ) VALUES (?, ?, ?, 'admin', ?, ?, ?, ?, NULL, 1, NULL, NOW())
    ");
   
    $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
   
    $userData = [
        $_POST['username'],
        $_POST['user_email'],
        $hashedPassword,
        $shopId,
        $businessId,
        $_POST['full_name'],
        $_POST['user_phone'] ?? null
    ];
   
    $userStmt->execute($userData);
    $userId = $pdo->lastInsertId();
   
    // Commit transaction
    $pdo->commit();
   
    $_SESSION['success_message'] = "Business registered successfully!";
    $_SESSION['business_id'] = $businessId;
    $_SESSION['shop_id'] = $shopId;
    $_SESSION['user_id'] = $userId;
   
    // --- Welcome Email with Credentials ---
    $to = $_POST['user_email'];
    $subject = "Welcome to Ecommer - Your Account is Ready!";
    
    $baseUrl = "https://ecommer.in/";
    $ecommerLogoUrl = $baseUrl . "billing/ecom.png"; // Update if the exact path differs
    $businessLogoUrl = $logoPath ? $baseUrl . $logoPath : $ecommerLogoUrl;
    
    // Plain text password (sent only once - user should change it immediately)
    $plainPassword = $_POST['password'];
    
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
                <h2>Congratulations, " . htmlspecialchars($_POST['full_name']) . "!</h2>
                <p>Your business has been successfully registered on <strong>Ecommer</strong>.</p>
                
                " . ($logoPath ? "<p><img src=\"{$businessLogoUrl}\" alt=\"Your Business Logo\" class=\"logo\" style=\"max-width:150px; margin:20px 0;\"></p>" : "") . "
                
                <p><strong>Business:</strong> " . htmlspecialchars($_POST['business_name']) . "<br>
                <strong>Location:</strong> " . htmlspecialchars($_POST['address']) . "<br>
                <strong>Username:</strong> " . htmlspecialchars($_POST['username']) . "</p>
                
                <h3>Your Login Credentials</h3>
                <div class=\"credentials\">
                    <strong>Username:</strong> " . htmlspecialchars($_POST['username']) . "<br>
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

    @mail($to, $subject, $message, $headers);
    
    header("Location: registration_success.php");
    exit();
   
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = "Registration failed: " . $e->getMessage();
    header("Location: register_business.php?error=1");
    exit();
}
?>