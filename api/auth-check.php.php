<?php
// api/auth-check.php
require_once '../includes/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

try {
    // Check if user is authenticated
    session_start();
    
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
        echo json_encode([
            'authenticated' => true,
            'user_id' => $_SESSION['user_id'],
            'business_id' => $_SESSION['business_id'] ?? null,
            'shop_id' => $_SESSION['shop_id'] ?? null
        ]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
} catch (Exception $e) {
    echo json_encode(['authenticated' => false, 'error' => $e->getMessage()]);
}
?>