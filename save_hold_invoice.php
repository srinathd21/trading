<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

// ==================== AUTHENTICATION & BUSINESS CONTEXT ====================
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Critical: Get current business context (used in multi-tenant setup)
$current_business_id = $_SESSION['current_business_id'] ?? null;
if (!$current_business_id) {
    echo json_encode(['success' => false, 'message' => 'Business context missing. Please select a business.']);
    exit();
}

// Optional: You can also validate shop/seller belongs to this business if needed later

// ==================== INPUT VALIDATION ====================
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

// Required fields
$required_fields = ['reference', 'customer_name', 'shop_id', 'seller_id', 'subtotal', 'total', 'cart_items', 'cart_json', 'expiry_hours'];
foreach ($required_fields as $field) {
    if (!isset($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

// Basic type/sanity checks
if (!is_numeric($input['shop_id']) || $input['shop_id'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid shop ID']);
    exit();
}
if (!is_numeric($input['seller_id']) || $input['seller_id'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid seller ID']);
    exit();
}
if (!is_numeric($input['subtotal']) || !is_numeric($input['total'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount values']);
    exit();
}
if (!is_numeric($input['expiry_hours']) || $input['expiry_hours'] < 1 || $input['expiry_hours'] > 168) { // max 7 days
    echo json_encode(['success' => false, 'message' => 'Expiry hours must be between 1 and 168']);
    exit();
}

try {
    // ==================== GENERATE UNIQUE HOLD NUMBER ====================
    $prefix = 'HOLD';
    $year_month = date('Ym'); // e.g., 202512 for Dec 2025

    $stmt = $pdo->prepare("
        SELECT hold_number 
        FROM held_invoices 
        WHERE hold_number LIKE ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute(["{$prefix}{$year_month}-%"]);
    $last = $stmt->fetch();

    if ($last) {
        $last_num = (int)substr($last['hold_number'], -4);
        $seq = $last_num + 1;
    } else {
        $seq = 1;
    }

    $hold_number = $prefix . $year_month . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    // Example: HOLD202512-0001

    // ==================== CALCULATE EXPIRY ====================
    $expiry_at = date('Y-m-d H:i:s', strtotime('+' . (int)$input['expiry_hours'] . ' hours'));

    // ==================== INSERT INTO held_invoices ====================
    $stmt = $pdo->prepare("
        INSERT INTO held_invoices (
            hold_number, reference, customer_name, customer_phone, customer_gstin,
            shop_id, seller_id, subtotal, total, cart_items, cart_json,
            business_id, expiry_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $hold_number,
        $input['reference'],
        $input['customer_name'],
        $input['customer_phone'] ?? '',
        $input['customer_gstin'] ?? '',
        $input['shop_id'],
        $input['seller_id'],
        $input['subtotal'],
        $input['total'],
        json_encode($input['cart_items']),     // Ensure cart_items is array
        $input['cart_json'],                   // Should already be JSON string from frontend
        $current_business_id,                  // ← THIS WAS MISSING (fixes FK error)
        $expiry_at
    ]);

    $hold_id = $pdo->lastInsertId();

    // ==================== SUCCESS RESPONSE ====================
    echo json_encode([
        'success' => true,
        'hold_id' => $hold_id,
        'hold_number' => $hold_number,
        'expiry_at' => $expiry_at,
        'message' => 'Invoice successfully put on hold'
    ]);

} catch (Exception $e) {
    // Log error if needed (error_log($e->getMessage());)
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>