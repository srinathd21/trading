<?php
// api/loyalty.php
require_once '../includes/auth.php';
require_once '../config/database.php';

checkAuth();
$business_id = getBusinessId();
$customer_id = $_GET['customer_id'] ?? null;

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? 'settings';
    
    switch ($action) {
        case 'settings':
            getLoyaltySettings();
            break;
        case 'customer_points':
            if ($customer_id) {
                getCustomerPoints();
            } else {
                echo json_encode(['success' => false, 'message' => 'Customer ID required']);
            }
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Loyalty API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

function getLoyaltySettings() {
    global $pdo, $business_id;
    
    $sql = "SELECT points_per_amount, amount_per_point, 
                   redeem_value_per_point, min_points_to_redeem,
                   expiry_months, is_active
            FROM loyalty_settings 
            WHERE business_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$business_id]);
    $settings = $stmt->fetch();
    
    if (!$settings) {
        // Default settings if none exist
        $settings = [
            'points_per_amount' => 0.01, // 1 point per ₹100
            'amount_per_point' => 100.00,
            'redeem_value_per_point' => 1.00, // ₹1 discount per point
            'min_points_to_redeem' => 50,
            'expiry_months' => null,
            'is_active' => 1
        ];
    }
    
    echo json_encode(['success' => true, 'settings' => $settings]);
}

function getCustomerPoints() {
    global $pdo, $business_id, $customer_id;
    
    $sql = "SELECT available_points, total_points_earned, total_points_redeemed
            FROM customer_points 
            WHERE customer_id = ? AND business_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customer_id, $business_id]);
    $points = $stmt->fetch();
    
    if (!$points) {
        $points = [
            'available_points' => 0,
            'total_points_earned' => 0,
            'total_points_redeemed' => 0
        ];
    }
    
    echo json_encode(['success' => true, 'points' => $points]);
}
?>