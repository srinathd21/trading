<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$invoice_id = (int)($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    echo json_encode(['error' => 'Invalid invoice ID']);
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;

try {
    // Check if token exists, if not generate one
    $stmt = $pdo->prepare("
        SELECT public_token 
        FROM invoices 
        WHERE id = ? AND business_id = ?
    ");
    $stmt->execute([$invoice_id, $business_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($invoice) {
        if (empty($invoice['public_token'])) {
            // Generate new token
            $token = bin2hex(random_bytes(32)); // 64 character hex token
            $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
            
            $update = $pdo->prepare("
                UPDATE invoices 
                SET public_token = ?, token_expiry = ? 
                WHERE id = ?
            ");
            $update->execute([$token, $expiry, $invoice_id]);
            
            echo json_encode(['token' => $token]);
        } else {
            echo json_encode(['token' => $invoice['public_token']]);
        }
    } else {
        echo json_encode(['error' => 'Invoice not found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}