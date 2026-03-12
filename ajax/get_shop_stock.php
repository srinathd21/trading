<?php
require_once '../../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$product_id = (int)($_GET['product_id'] ?? 0);
$shop_id = (int)($_GET['shop_id'] ?? 0);
$business_id = $_SESSION['current_business_id'] ?? null;

if (!$product_id || !$shop_id || !$business_id) {
    echo json_encode(['stock' => 0]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(quantity, 0) as stock 
        FROM product_stocks 
        WHERE product_id = ? 
          AND shop_id = ? 
          AND business_id = ?
    ");
    $stmt->execute([$product_id, $shop_id, $business_id]);
    $result = $stmt->fetch();
    
    echo json_encode(['stock' => $result ? $result['stock'] : 0]);
} catch (Exception $e) {
    echo json_encode(['stock' => 0, 'error' => $e->getMessage()]);
}
?>