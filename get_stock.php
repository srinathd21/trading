<?php
// get_stock.php
require_once 'config/database.php';
session_start();
if (!isset($_SESSION['user_id'])) exit(json_encode(['quantity' => 0]));

$product_id = (int)($_GET['product_id'] ?? 0);
$location_id = (int)($_GET['location_id'] ?? 0);

if ($product_id && $location_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(quantity, 0) as quantity FROM product_stocks WHERE product_id = ? AND location_id = ?");
    $stmt->execute([$product_id, $location_id]);
    $qty = $stmt->fetchColumn();
    echo json_encode(['quantity' => (int)$qty]);
} else {
    echo json_encode(['quantity' => 0]);
}
?>