<?php
// log_whatsapp_send.php - Log WhatsApp send actions
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;

$invoice_id = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$status = isset($_POST['status']) ? trim($_POST['status']) : 'sent';
$token = isset($_POST['token']) ? trim($_POST['token']) : '';

if ($invoice_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid invoice ID']);
    exit();
}

// Create whatsapp_logs table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            user_id INT NOT NULL,
            business_id INT NOT NULL,
            phone_number VARCHAR(20),
            status VARCHAR(20) DEFAULT 'sent',
            token_used VARCHAR(100),
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_invoice (invoice_id),
            INDEX idx_user (user_id),
            INDEX idx_business (business_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // Table might already exist
}

// Insert log
$log_stmt = $pdo->prepare("
    INSERT INTO whatsapp_logs (invoice_id, user_id, business_id, phone_number, status, token_used, sent_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");
$log_stmt->execute([$invoice_id, $user_id, $business_id, $phone, $status, $token]);

echo json_encode(['success' => true, 'message' => 'WhatsApp send logged']);
exit;