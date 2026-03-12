<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$quotation_id = $_GET['id'] ?? 0;

if (!$quotation_id) {
    header('Location: pos.php');
    exit();
}

// Get quotation details
$stmt = $pdo->prepare("
    SELECT q.*, qi.* 
    FROM quotations q
    JOIN quotation_items qi ON q.id = qi.quotation_id
    WHERE q.id = ? AND q.business_id = ? AND q.status IN ('sent', 'accepted')
");

$stmt->execute([$quotation_id, $_SESSION['current_business_id']]);
$quotation_data = $stmt->fetchAll();

if (empty($quotation_data)) {
    echo "<script>
        alert('Cannot convert this quotation. It may be already converted, expired, or rejected.');
        window.location.href = 'view_quotation.php?id=$quotation_id';
    </script>";
    exit();
}

// Check stock availability
foreach ($quotation_data as $item) {
    if ($item['product_id']) {
        $stockStmt = $pdo->prepare("
            SELECT COALESCE(ps.quantity, 0) as shop_stock
            FROM product_stocks ps
            WHERE ps.product_id = ? AND ps.shop_id = ?
        ");
        $stockStmt->execute([$item['product_id'], $item['shop_id']]);
        $stock = $stockStmt->fetchColumn();
        
        if ($stock < $item['quantity']) {
            echo "<script>
                alert('Insufficient stock for product: {$item['product_name']}. Available: $stock, Required: {$item['quantity']}');
                window.location.href = 'view_quotation.php?id=$quotation_id';
            </script>";
            exit();
        }
    }
}

// Store quotation data in session to load in POS
$_SESSION['converting_quotation'] = [
    'id' => $quotation_id,
    'customer_name' => $quotation_data[0]['customer_name'],
    'customer_phone' => $quotation_data[0]['customer_phone'],
    'customer_gstin' => $quotation_data[0]['customer_gstin'],
    'items' => array_map(function($item) {
        return [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'price_type' => $item['price_type'],
            'discount_value' => $item['discount_amount'],
            'discount_type' => $item['discount_type']
        ];
    }, $quotation_data)
];

// Update quotation status
$updateStmt = $pdo->prepare("
    UPDATE quotations 
    SET status = 'accepted',
        updated_at = NOW()
    WHERE id = ? AND business_id = ?
");

$updateStmt->execute([$quotation_id, $_SESSION['current_business_id']]);

// Redirect to POS with quotation items
header('Location: pos.php?convert_quotation=' . $quotation_id);
exit();