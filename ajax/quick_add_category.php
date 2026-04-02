<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$business_id = $_POST['business_id'] ?? $_SESSION['current_business_id'] ?? null;
$category_name = trim($_POST['category_name'] ?? '');
$category_code = trim($_POST['category_code'] ?? '');
$description = trim($_POST['description'] ?? '');

if (empty($category_name)) {
    echo json_encode(['success' => false, 'message' => 'Category name is required']);
    exit();
}

try {
    // Check if category already exists
    $check = $pdo->prepare("SELECT id FROM categories WHERE business_id = ? AND category_name = ? AND parent_id IS NULL");
    $check->execute([$business_id, $category_name]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Category already exists']);
        exit();
    }
    
    // Insert category
    $stmt = $pdo->prepare("
        INSERT INTO categories (business_id, category_name, category_code, description, status, created_by, created_at)
        VALUES (?, ?, ?, ?, 'active', ?, NOW())
    ");
    $stmt->execute([$business_id, $category_name, $category_code ?: null, $description ?: null, $_SESSION['user_id']]);
    
    $category_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'category_id' => $category_id,
        'category_name' => $category_name,
        'message' => 'Category added successfully'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}