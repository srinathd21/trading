<?php
require_once 'config/database.php';

$shop_id = $_GET['shop_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT hi.*, 
           JSON_LENGTH(hi.cart_items) as item_count,
           u.name as seller_name
    FROM held_invoices hi
    LEFT JOIN users u ON hi.seller_id = u.id
    WHERE hi.shop_id = ? AND hi.status = 'held'
    ORDER BY hi.created_at DESC
    LIMIT 50
");
$stmt->execute([$shop_id]);
$invoices = $stmt->fetchAll();

echo json_encode($invoices);
?>