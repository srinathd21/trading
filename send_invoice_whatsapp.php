<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $customer_phone = $_POST['customer_phone'] ?? '';
    $business_id = $_SESSION['business_id'] ?? 1;
    
    if ($invoice_id && $customer_phone) {
        // Log the WhatsApp send attempt
        try {
            // Check if logs table exists, create if not
            $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                business_id INT NOT NULL,
                invoice_id INT NOT NULL,
                customer_phone VARCHAR(20),
                status VARCHAR(20),
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_invoice (invoice_id),
                INDEX idx_business (business_id)
            )");
            
            $stmt = $pdo->prepare("INSERT INTO whatsapp_logs (business_id, invoice_id, customer_phone, status) VALUES (?, ?, ?, 'sent')");
            $stmt->execute([$business_id, $invoice_id, $customer_phone]);
            
            $_SESSION['whatsapp_success'] = 'Invoice link sent via WhatsApp successfully!';
        } catch (Exception $e) {
            // Log error but don't stop the flow
            error_log("WhatsApp log error: " . $e->getMessage());
            $_SESSION['whatsapp_success'] = 'Invoice link opened in WhatsApp!';
        }
    } else {
        $_SESSION['whatsapp_error'] = 'Invalid invoice ID or phone number';
    }
}

header('Location: invoices.php');
exit();