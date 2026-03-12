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

if (!in_array($user_role, ['admin', 'warehouse_manager', 'stock_manager'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$credit_id = (int)($_GET['credit_id'] ?? 0);

if ($credit_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid credit ID']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            gc.*,
            p.purchase_date,
            p.total_amount,
            p.payment_status,
            m.name as manufacturer_name
        FROM gst_credits gc
        LEFT JOIN purchases p ON gc.purchase_id = p.id AND gc.business_id = p.business_id
        LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
        WHERE gc.id = ? AND gc.business_id = ?
    ");
    $stmt->execute([$credit_id, $business_id]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($credit) {
        echo json_encode(['success' => true, 'data' => $credit]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Credit not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>