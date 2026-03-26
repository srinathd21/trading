<?php
// get_invoice_token.php - Generate or retrieve token for public invoice access
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$business_id = $_SESSION['business_id'] ?? 1;

if ($invoice_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid invoice ID']);
    exit();
}

// Check if invoices table has public_token column
try {
    $column_check = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'public_token'");
    $has_token_column = $column_check->fetch();
    
    if (!$has_token_column) {
        // Add token columns
        $pdo->exec("ALTER TABLE invoices ADD COLUMN public_token VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE invoices ADD COLUMN token_expiry DATETIME DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log("Token column check error: " . $e->getMessage());
}

// Check if invoice exists and belongs to this business
$stmt = $pdo->prepare("
    SELECT id, invoice_number, public_token, token_expiry 
    FROM invoices 
    WHERE id = ? AND business_id = ?
");
$stmt->execute([$invoice_id, $business_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    echo json_encode(['success' => false, 'message' => 'Invoice not found']);
    exit();
}

// Check if token exists and is not expired
$token = $invoice['public_token'];
$expiry = $invoice['token_expiry'];

if (empty($token) || strtotime($expiry) < time()) {
    // Generate new token
    $token = bin2hex(random_bytes(32));
    $expiry_date = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    $update_stmt = $pdo->prepare("
        UPDATE invoices 
        SET public_token = ?, token_expiry = ? 
        WHERE id = ?
    ");
    $update_stmt->execute([$token, $expiry_date, $invoice_id]);
}

echo json_encode([
    'success' => true,
    'token' => $token,
    'invoice_id' => $invoice_id,
    'invoice_number' => $invoice['invoice_number']
]);
exit;