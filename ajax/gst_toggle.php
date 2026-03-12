<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'shop_manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$business_id = $_SESSION['current_business_id'] ?? null;
if (!$business_id) {
    echo json_encode(['success' => false, 'message' => 'No business selected']);
    exit();
}

// Validate input
$setting_id = (int)($_POST['setting_id'] ?? 0);
$field = $_POST['field'] ?? '';
$value = (int)($_POST['value'] ?? 0);

if ($setting_id <= 0 || !in_array($field, ['is_gst_enabled', 'is_inclusive'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

try {
    // Verify the setting belongs to the user's business
    $check_stmt = $pdo->prepare("SELECT id FROM gst_settings WHERE id = ? AND business_id = ?");
    $check_stmt->execute([$setting_id, $business_id]);
    
    if (!$check_stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Setting not found']);
        exit();
    }
    
    // Update the setting
    $update_stmt = $pdo->prepare("
        UPDATE gst_settings 
        SET $field = ?, updated_at = NOW() 
        WHERE id = ? AND business_id = ?
    ");
    $update_stmt->execute([$value, $setting_id, $business_id]);
    
    // Get field name for message
    $field_name = str_replace('_', ' ', $field);
    $field_name = ucwords($field_name);
    
    echo json_encode([
        'success' => true, 
        'message' => "$field_name updated successfully!",
        'value' => $value
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>