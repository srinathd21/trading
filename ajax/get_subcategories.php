<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['category_id']) || !isset($_GET['business_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$category_id = (int)$_GET['category_id'];
$business_id = (int)$_GET['business_id'];

try {
    $stmt = $pdo->prepare("
        SELECT id, subcategory_name 
        FROM subcategories 
        WHERE business_id = ? AND category_id = ? AND status = 'active'
        ORDER BY subcategory_name
    ");
    $stmt->execute([$business_id, $category_id]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'subcategories' => $subcategories   // ← Important: key name must match JS expectation
    ]);
} catch (PDOException $e) {
    error_log("Subcategory fetch error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
}
?>