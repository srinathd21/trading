<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$shop_id = $_GET['shop_id'] ?? $_SESSION['shop_id'];
$business_id = $_GET['business_id'] ?? $_SESSION['current_business_id'];

try {
    $stmt = $pdo->prepare("
        SELECT q.*, 
               COUNT(qi.id) as item_count
        FROM quotations q
        LEFT JOIN quotation_items qi ON q.id = qi.quotation_id
        WHERE q.business_id = ? AND q.shop_id = ?
        GROUP BY q.id
        ORDER BY q.created_at DESC
        LIMIT 50
    ");
    
    $stmt->execute([$business_id, $shop_id]);
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($quotations);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}