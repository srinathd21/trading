<?php
/**
 * Mail Function for Ecommer Billing System
 * Handles sending emails with proper configuration
 */

// Check if running independently
if (!isset($run_in_context)) {
    header("Location: login.php");
    exit();
}

/**
 * Send password reset email
 * 
 * @param string $to Recipient email
 * @param string $name Recipient name
 * @param string $token Password reset token
 * @return bool True if email sent successfully, false otherwise
 */
function sendPasswordResetEmail($to, $name, $token) {
    // Create reset link
    $reset_link = "https://ecommer.in/reset_password.php?token=" . urlencode($token);
    
    // Email subject
    $subject = "Password Reset Request - Ecommer Billing System";
    
    // Email content
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Password Reset</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                line-height: 1.6;
                color: #333;
                background-color: #f5f7fa;
            }
            
            .email-container {
                max-width: 600px;
                margin: 0 auto;
                background: #ffffff;
                border-radius: 16px;
                overflow: hidden;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            }
            
            .email-header {
                background: linear-gradient(135deg, #4361ee 0%, #7209b7 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }
            
            .email-header h1 {
                font-size: 24px;
                font-weight: 600;
                margin: 0;
            }
            
            .email-header p {
                opacity: 0.9;
                margin-top: 8px;
                font-size: 14px;
            }
            
            .email-body {
                padding: 40px;
            }
            
            .greeting {
                font-size: 18px;
                margin-bottom: 20px;
                color: #2b2d42;
            }
            
            .instructions {
                color: #4a5568;
                margin-bottom: 30px;
                font-size: 15px;
            }
            
            .reset-button {
                display: inline-block;
                background: linear-gradient(135deg, #4361ee 0%, #7209b7 100%);
                color: white !important;
                text-decoration: none;
                padding: 14px 32px;
                border-radius: 10px;
                font-weight: 600;
                font-size: 16px;
                text-align: center;
                margin: 25px 0;
                transition: all 0.3s ease;
            }
            
            .reset-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 25px rgba(67, 97, 238, 0.3);
            }
            
            .reset-link {
                display: block;
                background: #f7fafc;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 15px;
                margin: 20px 0;
                word-break: break-all;
                font-family: 'Courier New', monospace;
                font-size: 13px;
                color: #4a5568;
            }
            
            .warning-box {
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                padding: 15px;
                margin: 25px 0;
                border-radius: 4px;
            }
            
            .warning-box strong {
                color: #856404;
            }
            
            .warning-box p {
                margin: 5px 0;
                color: #856404;
                font-size: 14px;
            }
            
            .email-footer {
                border-top: 1px solid #e2e8f0;
                padding-top: 20px;
                margin-top: 30px;
                text-align: center;
                color: #718096;
                font-size: 12px;
            }
            
            .support-info {
                margin-top: 15px;
                padding: 12px;
                background: #f8f9fa;
                border-radius: 8px;
                font-size: 13px;
            }
            
            .support-info a {
                color: #4361ee;
                text-decoration: none;
            }
            
            @media (max-width: 600px) {
                .email-body {
                    padding: 25px;
                }
                
                .email-header {
                    padding: 20px;
                }
                
                .reset-button {
                    display: block;
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <div class='email-container'>
            <div class='email-header'>
                <h1>Ecommer Billing System</h1>
                <p>Password Reset Request</p>
            </div>
            
            <div class='email-body'>
                <h2 class='greeting'>Hello, {$name}!</h2>
                
                <p class='instructions'>
                    We received a request to reset your password for your Ecommer Billing System account. 
                    If you made this request, please click the button below to set a new password:
                </p>
                
                <div style='text-align: center;'>
                    <a href='{$reset_link}' class='reset-button' target='_blank'>
                        Reset Your Password
                    </a>
                </div>
                
                <p class='instructions'>
                    If the button doesn't work, copy and paste this link into your browser:
                </p>
                
                <div class='reset-link'>{$reset_link}</div>
                
                <div class='warning-box'>
                    <strong>⚠️ Important Security Notice:</strong>
                    <p>• This password reset link will expire in 1 hour</p>
                    <p>• If you didn't request this password reset, please ignore this email</p>
                    <p>• For security reasons, do not share this link with anyone</p>
                </div>
                
                <div class='email-footer'>
                    <p>This is an automated email, please do not reply to this message.</p>
                    <p>&copy; " . date('Y') . " Ecommer Billing System. All rights reserved.</p>
                    
                    <div class='support-info'>
                        Need help? Contact our support team at 
                        <a href='mailto:support@ecommer.in'>support@ecommer.in</a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Email headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    
    // Set proper From address with your domain
    $headers .= "From: Ecommer Billing System <noreply@ecommer.in>" . "\r\n";
    $headers .= "Reply-To: support@ecommer.in" . "\r\n";
    $headers .= "Return-Path: noreply@ecommer.in" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "X-Priority: 1 (Highest)" . "\r\n";
    $headers .= "X-MSMail-Priority: High" . "\r\n";
    $headers .= "Importance: High" . "\r\n";
    
    // Additional headers for better deliverability
    $headers .= "List-Unsubscribe: <mailto:unsubscribe@ecommer.in>" . "\r\n";
    $headers .= "Organization: Ecommer Billing System" . "\r\n";
    
    try {
        // Send email using PHP's mail function
        $result = mail($to, $subject, $message, $headers);
        
        // Log email sending attempt
        error_log("Password reset email sent to: {$to}, Result: " . ($result ? "Success" : "Failed"));
        
        return $result;
    } catch (Exception $e) {
        error_log("Error sending password reset email to {$to}: " . $e->getMessage());
        return false;
    }
}

/**
 * Test email configuration
 * 
 * @param string $to Test email address
 * @return array Test results
 */
function testEmailConfiguration($to = "test@example.com") {
    $test_subject = "Email Configuration Test - Ecommer Billing";
    $test_message = "This is a test email to verify that email sending is working correctly from your server.";
    $test_headers = "From: Ecommer Billing System <noreply@ecommer.in>\r\n";
    
    $result = mail($to, $test_subject, $test_message, $test_headers);
    
    return [
        'success' => $result,
        'message' => $result ? 
            'Email sent successfully. Check your inbox.' : 
            'Failed to send email. Check server configuration.',
        'phpinfo' => phpversion()
    ];
}

/**
 * Configure PHP for better email handling
 */
function configureEmailSettings() {
    // Set default timezone
    date_default_timezone_set('Asia/Kolkata');
    
    // Set additional PHP configuration for mail
    ini_set('sendmail_from', 'noreply@ecommer.in');
    ini_set('SMTP', 'localhost'); // Change this if using external SMTP
    ini_set('smtp_port', '25'); // Default SMTP port
    
    // Enable error logging for debugging
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/email_errors.log');
}

/**
 * Send general notification email
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @param string $name Recipient name
 * @return bool True if sent successfully
 */
function sendNotificationEmail($to, $subject, $body, $name = "") {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Ecommer Billing System <noreply@ecommer.in>" . "\r\n";
    $headers .= "Reply-To: support@ecommer.in" . "\r\n";
    
    return mail($to, $subject, $body, $headers);
}

// Configure email settings when this file is included
configureEmailSettings();

/**
 * Advanced SMTP Configuration (if needed)
 * Uncomment and configure if PHP mail() doesn't work
 */
/*
function sendEmailViaSMTP($to, $subject, $message, $name = "") {
    require_once 'PHPMailer/PHPMailer.php';
    require_once 'PHPMailer/SMTP.php';
    require_once 'PHPMailer/Exception.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer();
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.your-domain.com'; // Your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@ecommer.in'; // SMTP username
        $mail->Password = 'your-smtp-password'; // SMTP password
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('noreply@ecommer.in', 'Ecommer Billing System');
        $mail->addAddress($to, $name);
        $mail->addReplyTo('support@ecommer.in', 'Support');
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = strip_tags($message);
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("SMTP Error: {$mail->ErrorInfo}");
        return false;
    }
}
*/

/**
 * Check if email function is available
 * 
 * @return bool True if mail function exists
 */
function isEmailFunctionAvailable() {
    return function_exists('mail');
}

/**
 * Get email configuration status
 * 
 * @return array Configuration status
 */
function getEmailConfigurationStatus() {
    return [
        'mail_function' => function_exists('mail'),
        'sendmail_path' => ini_get('sendmail_path'),
        'smtp' => ini_get('SMTP'),
        'smtp_port' => ini_get('smtp_port'),
        'from_address' => ini_get('sendmail_from'),
        'php_version' => phpversion(),
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'Unknown'
    ];
}

// If accessed directly, show configuration info
if (basename($_SERVER['PHP_SELF']) == 'mail_function.php') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>Email Configuration</title><style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .info { background: #f0f0f0; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; }
        pre { background: #2d2d2d; color: #fff; padding: 15px; border-radius: 5px; overflow: auto; }
    </style></head><body>";
    echo "<h1>Email Configuration Status</h1>";
    
    $config = getEmailConfigurationStatus();
    echo "<div class='info'><pre>" . print_r($config, true) . "</pre></div>";
    
    if (isset($_GET['test']) && $_GET['test'] == '1') {
        $test_email = $_GET['email'] ?? 'test@example.com';
        $test_result = testEmailConfiguration($test_email);
        
        if ($test_result['success']) {
            echo "<div class='success'>Test email sent to {$test_email}. Please check your inbox.</div>";
        } else {
            echo "<div class='error'>Failed to send test email. Check server logs for details.</div>";
        }
    }
    
    echo "<p><a href='?test=1&email=your-email@example.com'>Click here to test email sending</a></p>";
    echo "<p>Note: Replace 'your-email@example.com' with your actual email address in the URL.</p>";
    echo "</body></html>";
    exit();
}
?>