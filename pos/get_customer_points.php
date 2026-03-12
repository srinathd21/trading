<?php
require_once 'config/database.php';

$customer_id = $_GET['customer_id'] ?? 0;
$business_id = $_GET['business_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(points_earned), 0) as total_points_earned,
        COALESCE(SUM(points_redeemed), 0) as total_points_redeemed,
        COALESCE(SUM(points_earned), 0) - COALESCE(SUM(points_redeemed), 0) as available_points
    FROM loyalty_points 
    WHERE customer_id = ? AND business_id = ?
");
$stmt->execute([$customer_id, $business_id]);
$points = $stmt->fetch();

echo json_encode([
    'success' => true,
    'points' => $points ?: [
        'total_points_earned' => 0,
        'total_points_redeemed' => 0,
        'available_points' => 0
    ]
]);
?>