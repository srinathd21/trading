<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$quotation_id = $data['id'] ?? 0;

try {
    $stmt = $pdo->prepare("DELETE FROM quotations WHERE id = ? AND business_id = ?");
    $stmt->execute([$quotation_id, $_SESSION['current_business_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Quotation deleted successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}