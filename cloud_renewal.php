<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Check if temp session exists (from login redirect)
if (!isset($_SESSION['temp_business_id'])) {
    // If no temp session, check if user is logged in normally
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
    
    // Get business info for logged in user
    $stmt = $pdo->prepare("
        SELECT b.* 
        FROM businesses b
        WHERE b.id = ?
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['business_id']]);
    $business = $stmt->fetch();
    
    if (!$business) {
        header("Location: logout.php");
        exit();
    }
    
    // Check if subscription is actually expired
    $cloud_expired = false;
    $cloud_status = $business['cloud_subscription_status'];
    $cloud_expiry_date = $business['cloud_expiry_date'];
    
    if ($cloud_status === 'expired' || 
        ($cloud_expiry_date && strtotime($cloud_expiry_date) < time())) {
        $cloud_expired = true;
    }
    
    if (!$cloud_expired) {
        // Subscription is active, redirect to dashboard
        header("Location: dashboard.php");
        exit();
    }
    
    // Store in temp session for consistency
    $_SESSION['temp_user_id'] = $_SESSION['user_id'];
    $_SESSION['temp_business_id'] = $business['id'];
    $_SESSION['temp_business_name'] = $business['business_name'];
    $_SESSION['temp_full_name'] = $_SESSION['full_name'];
} else {
    // Get business info from temp session
    $stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['temp_business_id']]);
    $business = $stmt->fetch();
    
    if (!$business) {
        session_destroy();
        header("Location: login.php");
        exit();
    }
}

$business_id = $_SESSION['temp_business_id'];
$business_name = $_SESSION['temp_business_name'];
$user_name = $_SESSION['temp_full_name'];

// Handle payment submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['renew_subscription'])) {
        $plan = 'yearly'; // Only yearly plan available
        $payment_method = $_POST['payment_method'] ?? '';
        $transaction_id = $_POST['transaction_id'] ?? '';
        
        // Validate inputs
        if ($payment_method === '') {
            $message = 'Please select a payment method.';
            $message_type = 'error';
        } elseif ($payment_method === 'upi' && empty($transaction_id)) {
            $message = 'Please enter UPI Transaction ID.';
            $message_type = 'error';
        } elseif ($payment_method === 'bank_transfer' && empty($transaction_id)) {
            $message = 'Please enter Bank Transaction Reference ID.';
            $message_type = 'error';
        } else {
            try {
                // Calculate new expiry date
                $new_expiry_date = date('Y-m-d', strtotime("+1 year"));
                
                // Update business subscription
                $stmt = $pdo->prepare("
                    UPDATE businesses 
                    SET cloud_subscription_status = 'active',
                        cloud_expiry_date = ?,
                        cloud_plan = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([$new_expiry_date, $plan, $business_id]);
                
                // Create payment record
                $amount = 9999; // ₹9,999/year
                
                // Create payment record in a new payments table
                $payment_stmt = $pdo->prepare("
                    INSERT INTO cloud_payments 
                    (business_id, amount, plan, payment_method, transaction_id, status, payment_date)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                ");
                
                if (empty($transaction_id)) {
                    $transaction_id = 'CLOUD' . time() . rand(1000, 9999);
                }
                
                $payment_stmt->execute([$business_id, $amount, $plan, $payment_method, $transaction_id]);
                
                $message = 'Subscription renewal request submitted successfully! Our team will verify your payment and activate your subscription within 24 hours.';
                $message_type = 'success';
                
                // If user came from login, show success message but don't redirect yet
                if (isset($_SESSION['temp_user_id'])) {
                    // Clear temp session after showing success message
                    unset($_SESSION['temp_user_id']);
                    unset($_SESSION['temp_business_id']);
                    unset($_SESSION['temp_business_name']);
                    unset($_SESSION['temp_full_name']);
                }
                
            } catch (Exception $e) {
                $message = 'Error processing renewal. Please try again.';
                $message_type = 'error';
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
    <title>Cloud Subscription Renewal - Ecommer</title>
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
            --warning-color: #ff9e00;
            --danger-color: #ff3860;
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
            padding: 20px;
            margin: 0;
        }
        
        .renewal-container {
            max-width: 900px;
            margin: 40px auto;
            animation: fadeIn 0.8s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .renewal-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .logo-big {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-big img {
            max-width: 200px;
            height: auto;
        }
        
        .renewal-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .renewal-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .renewal-body {
            padding: 40px;
        }
        
        .business-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        
        .business-info h3 {
            margin: 0;
            font-weight: 600;
        }
        
        .business-info p {
            margin: 5px 0 0 0;
            opacity: 0.9;
        }
        
        .subscription-status {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(245, 87, 108, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(245, 87, 108, 0); }
            100% { box-shadow: 0 0 0 0 rgba(245, 87, 108, 0); }
        }
        
        .plan-card-container {
            display: flex;
            justify-content: center;
            margin-bottom: 40px;
        }
        
        .plan-card {
            background: white;
            border: 3px solid var(--warning-color);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            max-width: 500px;
            width: 100%;
            position: relative;
        }
        
        .plan-badge {
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(to right, var(--warning-color), #ff6b00);
            color: white;
            padding: 8px 25px;
            border-radius: 25px;
            font-size: 16px;
            font-weight: 600;
            box-shadow: 0 5px 15px rgba(255, 158, 0, 0.3);
        }
        
        .plan-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--dark-text);
        }
        
        .plan-price {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 10px;
            color: var(--primary-color);
        }
        
        .plan-period {
            font-size: 18px;
            color: var(--gray-text);
            margin-bottom: 30px;
        }
        
        .plan-features {
            list-style: none;
            padding: 0;
            margin: 0 0 30px 0;
            text-align: left;
        }
        
        .plan-features li {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 16px;
        }
        
        .plan-features li:last-child {
            border-bottom: none;
        }
        
        .plan-features i {
            color: var(--primary-color);
            margin-right: 12px;
            font-size: 18px;
        }
        
        .payment-methods {
            background: var(--light-bg);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .payment-method {
            display: flex;
            align-items: center;
            padding: 20px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            margin-bottom: 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .payment-method:hover {
            border-color: var(--primary-color);
            background: white;
        }
        
        .payment-method.selected {
            border-color: var(--primary-color);
            background: linear-gradient(to right, #f8f9ff, white);
        }
        
        .payment-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            color: white;
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .payment-details h5 {
            margin: 0 0 8px 0;
            font-weight: 600;
            font-size: 18px;
        }
        
        .payment-details p {
            margin: 0 0 5px 0;
            color: var(--gray-text);
            font-size: 15px;
        }
        
        .upi-details {
            background: #f8f9ff;
            border: 2px dashed #4361ee;
            border-radius: 10px;
            padding: 20px;
            margin-top: 15px;
            display: none;
        }
        
        .upi-details.show {
            display: block;
        }
        
        .upi-info {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .qr-code-container {
            flex-shrink: 0;
        }
        
        .qr-code {
            width: 200px;
            height: 200px;
            border: 2px solid #ddd;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            overflow: hidden;
        }
        
        .qr-code img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .upi-text {
            flex: 1;
        }
        
        .upi-id {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 10px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            text-align: center;
            border: 2px solid #e2e8f0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark-text);
            display: block;
        }
        
        .form-control {
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        .btn-renew {
            width: 100%;
            padding: 20px;
            font-weight: 600;
            font-size: 20px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 12px;
            color: white;
            transition: all 0.3s;
            margin-top: 20px;
        }
        
        .btn-renew:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(67, 97, 238, 0.4);
        }
        
        .btn-renew:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .alert {
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            border: none;
            font-weight: 500;
            font-size: 16px;
        }
        
        .alert-success {
            background-color: #e8f7ef;
            color: #0a7c40;
            border-left: 4px solid #0a7c40;
        }
        
        .alert-error {
            background-color: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }
        
        .contact-support {
            text-align: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px solid var(--border-color);
        }
        
        .contact-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .contact-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border: 2px solid var(--border-color);
            text-align: center;
            transition: all 0.3s;
        }
        
        .contact-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }
        
        .contact-item i {
            font-size: 32px;
            color: var(--primary-color);
            margin-bottom: 15px;
        }
        
        .contact-item h5 {
            margin: 0 0 10px 0;
            font-weight: 600;
        }
        
        .contact-item p {
            margin: 0;
            color: var(--gray-text);
        }
        
        .whatsapp-link {
            color: #25D366 !important;
            font-weight: 600;
        }
        
        .whatsapp-link:hover {
            color: #1da851 !important;
        }
        
        .upi-copy-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-left: 10px;
            transition: background 0.3s;
        }
        
        .upi-copy-btn:hover {
            background: var(--primary-dark);
        }
        
        .transaction-input {
            display: none;
            margin-top: 15px;
        }
        
        .transaction-input.show {
            display: block;
        }
    </style>
</head>
<body>
    <div class="renewal-container">
        <div class="renewal-header">
            <div class="logo-big">
                <img src="assets/logo.png" alt="Ecommer Logo">
            </div>
            <h1>Cloud Subscription Renewal</h1>
            <p class="text-muted">Renew your cloud subscription to continue using all features</p>
        </div>
        
        <div class="renewal-card">
            <div class="renewal-body">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'error' ?>">
                        <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
                        <?= htmlspecialchars($message) ?>
                        <?php if ($message_type === 'success'): ?>
                            <br><small class="mt-2 d-block">You will receive a confirmation email once your payment is verified.</small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="business-info">
                    <h3><i class="fas fa-building me-2"></i><?= htmlspecialchars($business_name) ?></h3>
                    <p><i class="fas fa-user me-2"></i><?= htmlspecialchars($user_name) ?></p>
                </div>
                
                <div class="subscription-status">
                    <h2><i class="fas fa-exclamation-triangle me-2"></i>Subscription Expired</h2>
                    <p>Your cloud subscription has expired on <?= date('d M Y', strtotime($business['cloud_expiry_date'])) ?></p>
                    <p>Please renew to access all features</p>
                </div>
                
                <form method="POST" id="renewalForm">
                    <input type="hidden" name="plan" id="selectedPlan" value="yearly">
                    
                    <h3 class="text-center mb-4">Renew Your Subscription</h3>
                    
                    <div class="plan-card-container">
                        <div class="plan-card">
                            <div class="plan-badge">YEARLY PLAN</div>
                            <div class="plan-title">Yearly Subscription</div>
                            <div class="plan-price">₹1,500</div>
                            <div class="plan-period">per year (Save 17%)</div>
                            <ul class="plan-features">
                                <li><i class="fas fa-check"></i> Cloud Storage & Backups</li>
                                <li><i class="fas fa-check"></i> Multi-device Access</li>
                                <li><i class="fas fa-check"></i> 24/7 Priority Support</li>
                                <li><i class="fas fa-check"></i> Advanced Reports & Analytics</li>
                                <li><i class="fas fa-check"></i> 5 GB Extra Storage</li>
                                <li><i class="fas fa-check"></i> Custom Branding Options</li>
                                <li><i class="fas fa-check"></i> Free Updates & Security Patches</li>
                            </ul>
                        </div>
                    </div>
                    
                    <h3 class="mb-4">Select Payment Method</h3>
                    <div class="payment-methods">
                        <!-- UPI Payment -->
                        <div class="payment-method" onclick="selectPayment('upi')" id="payment-upi">
                            <div class="payment-icon">
                                <i class="fas fa-qrcode"></i>
                            </div>
                            <div class="payment-details">
                                <h5>UPI Payment</h5>
                                <p>Pay using any UPI app (PhonePe, Google Pay, Paytm)</p>
                            </div>
                            <input type="radio" name="payment_method" value="upi" style="display: none;">
                        </div>
                        
                        <!-- Bank Transfer -->
                        <div class="payment-method" onclick="selectPayment('bank_transfer')" id="payment-bank">
                            <div class="payment-icon">
                                <i class="fas fa-university"></i>
                            </div>
                            <div class="payment-details">
                                <h5>Bank Transfer</h5>
                                <p>Direct bank transfer or NEFT/RTGS</p>
                            </div>
                            <input type="radio" name="payment_method" value="bank_transfer" style="display: none;">
                        </div>
                    </div>
                    
                    <input type="hidden" name="payment_method" id="selectedPayment" value="">
                    
                    <!-- UPI Details Section -->
                    <div class="upi-details" id="upiDetails">
                        <div class="upi-info">
                            <div class="qr-code-container">
                                <div class="qr-code">
                                    <?php
                                    // Check if QR code exists in assets folder
                                    $qr_code_path = 'qrcode.png';
                                    if (file_exists($qr_code_path)) {
                                        echo '<img src="' . $qr_code_path . '" alt="UPI QR Code">';
                                    } else {
                                        echo '<div class="text-center p-4">';
                                        echo '<i class="fas fa-qrcode fa-4x text-muted mb-3"></i>';
                                        echo '<p class="text-muted">QR Code will be displayed here</p>';
                                        echo '<p class="text-muted small">Upload QR code image as "qr_code.png" in assets folder</p>';
                                        echo '</div>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="upi-text">
                                <h5>Scan QR Code or Use UPI ID:</h5>
                                <div class="upi-id" id="upiId">7200314099@naviaxis</div>
                                <button type="button" class="upi-copy-btn" onclick="copyUpiId()">
                                    <i class="fas fa-copy me-1"></i> Copy UPI ID
                                </button>
                                <div class="form-group transaction-input show" id="upiTransactionInput">
                                    <label class="form-label">UPI Transaction ID *</label>
                                    <input type="text" name="transaction_id" class="form-control" 
                                           placeholder="Enter UPI Transaction ID" required>
                                    <small class="text-muted">After payment, enter the transaction ID from your UPI app</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bank Transfer Details -->
                    <div class="upi-details" id="bankDetails">
                        <div class="form-group">
                            <label class="form-label">Bank Account Details</label>
                            <div class="bg-light p-3 rounded">
                                <p><strong>Account Name:</strong> ECOMMER OFFICIAL</p>
                                <p><strong>Account Number:</strong> 1234567890123</p>
                                <p><strong>Bank Name:</strong> State Bank of India</p>
                                <p><strong>IFSC Code:</strong> SBIN0001234</p>
                                <p><strong>Branch:</strong> Chennai Main Branch</p>
                            </div>
                        </div>
                        <div class="form-group transaction-input" id="bankTransactionInput">
                            <label class="form-label">Bank Transaction Reference ID *</label>
                            <input type="text" name="transaction_id" class="form-control" 
                                   placeholder="Enter Bank Transaction Reference ID" required>
                            <small class="text-muted">After transfer, enter the transaction reference ID from your bank</small>
                        </div>
                    </div>
                    
                    <button type="submit" name="renew_subscription" class="btn-renew" id="renewBtn">
                        <i class="fas fa-sync-alt me-2"></i> Submit Renewal Request
                    </button>
                    
                    <div class="contact-support">
                        <h4>Need Help? Contact Our Support</h4>
                        <div class="contact-info">
                            <div class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <h5>Email Support</h5>
                                <p><a href="mailto:ecommerofficial@gmail.com">ecommerofficial@gmail.com</a></p>
                            </div>
                            <div class="contact-item">
                                <i class="fas fa-phone"></i>
                                <h5>Phone Support</h5>
                                <p><a href="tel:+919003552650">+91 9003552650</a></p>
                                <p><a href="tel:+917200314099">+91 7200314099</a></p>
                            </div>
                            <div class="contact-item">
                                <i class="fab fa-whatsapp"></i>
                                <h5>WhatsApp</h5>
                                <p><a href="https://wa.me/917200314099" class="whatsapp-link" target="_blank">+91 7200314099</a></p>
                            </div>
                            <div class="contact-item">
                                <i class="fas fa-clock"></i>
                                <h5>Support Hours</h5>
                                <p>Monday - Sunday</p>
                                <p>9:00 AM - 9:00 PM IST</p>
                            </div>
                        </div>
                        <p class="mt-4 text-muted">
                            <i class="fas fa-info-circle me-2"></i>
                            Payments are manually verified. Your subscription will be activated within 24 hours of payment confirmation.
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize with UPI selected
        document.addEventListener('DOMContentLoaded', function() {
            selectPayment('upi');
        });
        
        function selectPayment(method) {
            // Remove selected class from all payment methods
            document.querySelectorAll('.payment-method').forEach(payment => {
                payment.classList.remove('selected');
            });
            
            // Add selected class to clicked payment method
            const paymentMethod = document.getElementById('payment-' + method);
            if (paymentMethod) {
                paymentMethod.classList.add('selected');
                document.getElementById('selectedPayment').value = method;
            }
            
            // Show/hide payment details sections
            const upiDetails = document.getElementById('upiDetails');
            const bankDetails = document.getElementById('bankDetails');
            const upiTransactionInput = document.getElementById('upiTransactionInput');
            const bankTransactionInput = document.getElementById('bankTransactionInput');
            
            if (method === 'upi') {
                upiDetails.classList.add('show');
                bankDetails.classList.remove('show');
                upiTransactionInput.classList.add('show');
                bankTransactionInput.classList.remove('show');
            } else if (method === 'bank_transfer') {
                upiDetails.classList.remove('show');
                bankDetails.classList.add('show');
                upiTransactionInput.classList.remove('show');
                bankTransactionInput.classList.add('show');
            }
        }
        
        function copyUpiId() {
            const upiId = document.getElementById('upiId').textContent;
            navigator.clipboard.writeText(upiId).then(function() {
                const button = document.querySelector('.upi-copy-btn');
                const originalHtml = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check me-1"></i> Copied!';
                button.style.background = '#28a745';
                
                setTimeout(function() {
                    button.innerHTML = originalHtml;
                    button.style.background = '';
                }, 2000);
            });
        }
        
        // Form validation and submission
        document.getElementById('renewalForm').addEventListener('submit', function(e) {
            const paymentMethod = document.getElementById('selectedPayment').value;
            const renewBtn = document.getElementById('renewBtn');
            
            if (!paymentMethod) {
                e.preventDefault();
                alert('Please select a payment method.');
                return;
            }
            
            // Validate transaction ID
            let transactionId = '';
            if (paymentMethod === 'upi') {
                transactionId = document.querySelector('#upiTransactionInput input[name="transaction_id"]').value;
            } else if (paymentMethod === 'bank_transfer') {
                transactionId = document.querySelector('#bankTransactionInput input[name="transaction_id"]').value;
            }
            
            if (!transactionId.trim()) {
                e.preventDefault();
                alert('Please enter transaction ID.');
                return;
            }
            
            // Show loading state
            renewBtn.disabled = true;
            renewBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';
        });
    </script>
</body>
</html>