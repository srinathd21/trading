<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$business_id = $_POST['business_id'] ?? $_SESSION['current_business_id'] ?? null;
$category_id = (int)($_POST['category_id'] ?? 0);
$subcategory_name = trim($_POST['subcategory_name'] ?? '');
$subcategory_code = trim($_POST['subcategory_code'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($category_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid category']);
    exit();
}

if (empty($subcategory_name)) {
    echo json_encode(['success' => false, 'message' => 'Subcategory name is required']);
    exit();
}

try {
    // Verify category belongs to business
    $checkCat = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND business_id = ?");
    $checkCat->execute([$category_id, $business_id]);
    if (!$checkCat->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Invalid category']);
        exit();
    }
    
    // Check if subcategory already exists
    $check = $pdo->prepare("SELECT id FROM subcategories WHERE business_id = ? AND category_id = ? AND subcategory_name = ?");
    $check->execute([$business_id, $category_id, $subcategory_name]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Subcategory already exists in this category']);
        exit();
    }
    
    // Insert subcategory
    $stmt = $pdo->prepare("
        INSERT INTO subcategories (business_id, category_id, subcategory_name, subcategory_code, description, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())
    ");
    $stmt->execute([$business_id, $category_id, $subcategory_name, $subcategory_code ?: null, $description ?: null, $_SESSION['user_id']]);
    
    $subcategory_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'subcategory_id' => $subcategory_id,
        'subcategory_name' => $subcategory_name,
        'message' => 'Subcategory added successfully'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}