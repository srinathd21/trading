<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Only allow roles that can manage products/categories
$allowed_roles = ['admin', 'warehouse_manager', 'shop_manager'];
if (!in_array($_SESSION['role'] ?? '', $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$category_name = trim($_POST['category_name'] ?? '');
$business_id = (int)($_POST['business_id'] ?? 0);

if (empty($category_name)) {
    echo json_encode(['success' => false, 'message' => 'Category name is required']);
    exit();
}

if ($business_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid business']);
    exit();
}

try {
    // Check if category already exists for this business
    $check = $pdo->prepare("SELECT id FROM categories WHERE category_name = ? AND business_id = ? AND parent_id IS NULL");
    $check->execute([$category_name, $business_id]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Category "' . htmlspecialchars($category_name) . '" already exists']);
        exit();
    }

    // Insert new main category
    $stmt = $pdo->prepare("INSERT INTO categories 
        (business_id, category_name, parent_id, status, created_at) 
        VALUES (?, ?, NULL, 'active', NOW())");
    
    $stmt->execute([$business_id, $category_name]);
    $category_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Category added successfully',
        'category_id' => $category_id,
        'category_name' => htmlspecialchars($category_name)
    ]);

} catch (Exception $e) {
    error_log("Quick add category error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to add category. Please try again.']);
}
?>