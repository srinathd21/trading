<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check authentication and permission
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$allowed_roles = ['admin', 'warehouse_manager', 'shop_manager'];
if (!in_array($_SESSION['role'] ?? '', $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$subcategory_name = trim($_POST['subcategory_name'] ?? '');
$category_id = (int)($_POST['category_id'] ?? 0);
$business_id = (int)($_POST['business_id'] ?? 0);

if (empty($subcategory_name)) {
    echo json_encode(['success' => false, 'message' => 'Subcategory name is required']);
    exit();
}

if ($category_id <= 0 || $business_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid category or business']);
    exit();
}

// Validate that the parent category belongs to this business
try {
    $check = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND business_id = ? AND parent_id IS NULL");
    $check->execute([$category_id, $business_id]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Invalid or unauthorized parent category']);
        exit();
    }

    // Check for duplicate subcategory under same parent
    $dup = $pdo->prepare("SELECT id FROM subcategories WHERE subcategory_name = ? AND category_id = ? AND business_id = ?");
    $dup->execute([$subcategory_name, $category_id, $business_id]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Subcategory "' . htmlspecialchars($subcategory_name) . '" already exists in this category']);
        exit();
    }

    // Insert new subcategory
    $stmt = $pdo->prepare("INSERT INTO subcategories 
        (business_id, category_id, subcategory_name, status, created_at) 
        VALUES (?, ?, ?, 'active', NOW())");
    
    $stmt->execute([$business_id, $category_id, $subcategory_name]);
    $subcategory_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Subcategory added successfully',
        'subcategory_id' => $subcategory_id,
        'subcategory_name' => htmlspecialchars($subcategory_name)
    ]);

} catch (Exception $e) {
    error_log("Quick add subcategory error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to add subcategory. Please try again.']);
}
?>