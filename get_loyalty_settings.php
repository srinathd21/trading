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

try {
    $stmt = $pdo->prepare("SELECT * FROM loyalty_settings WHERE business_id = ?");
    $stmt->execute([$business_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($settings) {
        echo json_encode([
            'success' => true,
            'settings' => $settings
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Loyalty settings not found'
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}