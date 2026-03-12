<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$business_id = $_SESSION['current_business_id'] ?? 1;
$phone = $_GET['phone'] ?? '';

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone required']);
    exit;
}

try {
    // Get customer by phone
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, cp.available_points, cp.total_points_earned, cp.total_points_redeemed
        FROM customers c
        LEFT JOIN customer_points cp ON c.id = cp.customer_id AND cp.business_id = ?
        WHERE c.phone = ? AND c.business_id = ?
        LIMIT 1
    ");
    $stmt->execute([$business_id, $phone, $business_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customer) {
        echo json_encode([
            'success' => true,
            'customer_id' => $customer['id'],
            'customer_name' => $customer['name'],
            'available_points' => (float)$customer['available_points'] ?: 0,
            'total_points_earned' => (float)$customer['total_points_earned'] ?: 0,
            'total_points_redeemed' => (float)$customer['total_points_redeemed'] ?: 0
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found'
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}