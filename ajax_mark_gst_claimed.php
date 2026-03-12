<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? '';

if (!in_array($user_role, ['admin', 'warehouse_manager'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $credit_id = (int)($_POST['credit_id'] ?? 0);
    
    if ($credit_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid credit ID']);
        exit();
    }
    
    try {
        // Update GST credit status
        $stmt = $pdo->prepare("
            UPDATE gst_credits 
            SET status = 'claimed', 
                updated_at = NOW()
            WHERE id = ? 
              AND business_id = ?
              AND status = 'not_claimed'
        ");
        $stmt->execute([$credit_id, $business_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'GST credit marked as claimed successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Credit not found or already claimed']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>