<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$business_id = $_POST['business_id'] ?? $_SESSION['current_business_id'] ?? null;
$hsn_code = strtoupper(trim($_POST['hsn_code'] ?? ''));
$description = trim($_POST['description'] ?? '');
$cgst_rate = (float)($_POST['cgst_rate'] ?? 0);
$sgst_rate = (float)($_POST['sgst_rate'] ?? 0);
$igst_rate = (float)($_POST['igst_rate'] ?? 0);

if (empty($hsn_code)) {
    echo json_encode(['success' => false, 'message' => 'HSN code is required']);
    exit();
}

if ($cgst_rate == 0 && $sgst_rate == 0 && $igst_rate == 0) {
    echo json_encode(['success' => false, 'message' => 'At least one GST rate must be specified']);
    exit();
}

try {
    // Check if GST rate already exists
    $check = $pdo->prepare("SELECT id FROM gst_rates WHERE business_id = ? AND hsn_code = ?");
    $check->execute([$business_id, $hsn_code]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'GST rate already exists for this HSN code']);
        exit();
    }
    
    // Insert GST rate
    $stmt = $pdo->prepare("
        INSERT INTO gst_rates (business_id, hsn_code, description, cgst_rate, sgst_rate, igst_rate, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
    ");
    $stmt->execute([$business_id, $hsn_code, $description ?: null, $cgst_rate, $sgst_rate, $igst_rate]);
    
    $gst_id = $pdo->lastInsertId();
    $total_gst_rate = $cgst_rate + $sgst_rate + $igst_rate;
    
    echo json_encode([
        'success' => true,
        'gst_id' => $gst_id,
        'hsn_code' => $hsn_code,
        'cgst_rate' => $cgst_rate,
        'sgst_rate' => $sgst_rate,
        'igst_rate' => $igst_rate,
        'total_gst_rate' => $total_gst_rate,
        'message' => 'GST rate added successfully'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}