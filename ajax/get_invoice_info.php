<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized']));
}

$business_id = $_SESSION['current_business_id'] ?? 1;
$invoice_id = (int)($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    die(json_encode(['error' => 'Invalid invoice ID']));
}

$stmt = $pdo->prepare("
    SELECT 
        i.invoice_number,
        i.total,
        i.created_at,
        c.name as customer_name,
        c.phone as customer_phone,
        (SELECT SUM(return_qty) FROM invoice_items WHERE invoice_id = i.id) as already_returned_qty
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE i.id = ? AND i.business_id = ?
");
$stmt->execute([$invoice_id, $business_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die(json_encode(['error' => 'Invoice not found']));
}

header('Content-Type: application/json');
echo json_encode($invoice);
?>