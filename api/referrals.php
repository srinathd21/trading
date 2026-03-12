<?php
// api/referrals.php
require_once '../includes/auth.php';
require_once '../config/database.php';

checkAuth();
$business_id = getBusinessId();

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'list') {
        getReferrals();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Referrals API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

function getReferrals() {
    global $pdo, $business_id;
    
    $sql = "SELECT id, referral_code, full_name, phone, email, 
                   commission_percent, is_active
            FROM referral_person 
            WHERE business_id = ? AND is_active = 1
            ORDER BY full_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$business_id]);
    $referrals = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'referrals' => $referrals]);
}
?>