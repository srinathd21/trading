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
    SELECT q.* 
    FROM quotations q
    WHERE q.id = ? AND q.business_id = ? AND q.status = 'draft'
");

$stmt->execute([$quotation_id, $_SESSION['current_business_id']]);
$quotation = $stmt->fetch();

if (!$quotation) {
    echo "<script>
        alert('Cannot edit this quotation. It may be already sent or accepted.');
        window.location.href = 'view_quotation.php?id=$quotation_id';
    </script>";
    exit();
}

// Store in session to load in POS for editing
$_SESSION['editing_quotation'] = [
    'id' => $quotation_id,
    'customer_name' => $quotation['customer_name'],
    'customer_phone' => $quotation['customer_phone'],
    'customer_gstin' => $quotation['customer_gstin'],
    'valid_until' => $quotation['valid_until'],
    'notes' => $quotation['notes']
];

// Get quotation items
$itemsStmt = $pdo->prepare("
    SELECT qi.* 
    FROM quotation_items qi
    WHERE qi.quotation_id = ?
");

$itemsStmt->execute([$quotation_id]);
$items = $itemsStmt->fetchAll();

// Store items in session
$_SESSION['editing_quotation_items'] = array_map(function($item) {
    return [
        'product_id' => $item['product_id'],
        'quantity' => $item['quantity'],
        'price_type' => $item['price_type'],
        'discount_value' => $item['discount_amount'],
        'discount_type' => $item['discount_type']
    ];
}, $items);

// Redirect to POS with quotation items for editing
header('Location: pos.php?edit_quotation=' . $quotation_id);
exit();