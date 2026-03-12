<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $phone = $_POST['phone'] ?? '';
    $status = $_POST['status'] ?? 'sent';
    $business_id = $_SESSION['business_id'] ?? 1;
    
    try {
        // Create table if not exists
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
        
        $stmt = $pdo->prepare("INSERT INTO whatsapp_logs (business_id, invoice_id, customer_phone, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$business_id, $invoice_id, $phone, $status]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}