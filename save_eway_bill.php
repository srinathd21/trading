<?php
// save_eway_bill.php - Save E-Way Bill details for invoice
session_start();
require_once 'config/database.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set JSON header
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';

// Check if user has permission (admin or shop_manager can edit)
$can_edit = in_array($user_role, ['admin', 'shop_manager']);

if (!$can_edit) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

// Get POST data
$invoice_id = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
$eway_bill_number = isset($_POST['eway_bill_number']) ? trim($_POST['eway_bill_number']) : '';
$eway_doc_number = isset($_POST['eway_doc_number']) ? trim($_POST['eway_doc_number']) : '';
$eway_doc_date = isset($_POST['eway_doc_date']) ? trim($_POST['eway_doc_date']) : '';
$eway_transport_mode = isset($_POST['eway_transport_mode']) ? trim($_POST['eway_transport_mode']) : 'Road';

// Validate input
if ($invoice_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid invoice ID']);
    exit();
}

// Check if invoice exists and belongs to this business
$check_stmt = $pdo->prepare("
    SELECT id, invoice_number, business_id, shop_id 
    FROM invoices 
    WHERE id = ? AND business_id = ?
");
$check_stmt->execute([$invoice_id, $business_id]);
$invoice = $check_stmt->fetch();

if (!$invoice) {
    echo json_encode(['success' => false, 'message' => 'Invoice not found or access denied']);
    exit();
}

// First, check if the columns exist
try {
    $column_check = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'eway_bill_number'");
    $columns_exist = $column_check->fetch();
    
    if (!$columns_exist) {
        // Try to add the columns
        try {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN eway_bill_number VARCHAR(50) DEFAULT NULL");
            $pdo->exec("ALTER TABLE invoices ADD COLUMN eway_doc_number VARCHAR(50) DEFAULT NULL");
            $pdo->exec("ALTER TABLE invoices ADD COLUMN eway_doc_date DATE DEFAULT NULL");
            $pdo->exec("ALTER TABLE invoices ADD COLUMN eway_transport_mode VARCHAR(20) DEFAULT 'Road'");
            $pdo->exec("ALTER TABLE invoices ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        } catch (Exception $e) {
            // Columns might already exist or other error
            error_log("Error adding columns: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log("Column check error: " . $e->getMessage());
}

// Prepare update data
$update_fields = [];
$update_params = [];

if (!empty($eway_bill_number)) {
    $update_fields[] = "eway_bill_number = ?";
    $update_params[] = $eway_bill_number;
}

if (!empty($eway_doc_number)) {
    $update_fields[] = "eway_doc_number = ?";
    $update_params[] = $eway_doc_number;
}

if (!empty($eway_doc_date)) {
    $update_fields[] = "eway_doc_date = ?";
    $update_params[] = $eway_doc_date;
}

if (!empty($eway_transport_mode)) {
    $update_fields[] = "eway_transport_mode = ?";
    $update_params[] = $eway_transport_mode;
}

// Add updated_at timestamp
$update_fields[] = "updated_at = NOW()";

if (empty($update_fields)) {
    echo json_encode(['success' => false, 'message' => 'No data to update']);
    exit();
}

// Add invoice_id to params
$update_params[] = $invoice_id;

// Build and execute update query
$sql = "UPDATE invoices SET " . implode(", ", $update_fields) . " WHERE id = ?";
$stmt = $pdo->prepare($sql);
$result = $stmt->execute($update_params);

if ($result) {
    // Log the action (create table if not exists)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                business_id INT NOT NULL,
                action VARCHAR(100),
                details TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, business_id, action, details, created_at)
            VALUES (?, ?, 'eway_bill_updated', ?, NOW())
        ");
        
        $log_details = json_encode([
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice['invoice_number'],
            'eway_bill_number' => $eway_bill_number,
            'eway_doc_number' => $eway_doc_number,
            'eway_doc_date' => $eway_doc_date,
            'eway_transport_mode' => $eway_transport_mode,
            'updated_by' => $user_id
        ]);
        
        $log_stmt->execute([$user_id, $business_id, $log_details]);
    } catch (Exception $e) {
        // Log table might not exist, ignore
        error_log("Logging error: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'E-Way Bill details saved successfully',
        'data' => [
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice['invoice_number'],
            'eway_bill_number' => $eway_bill_number,
            'eway_doc_number' => $eway_doc_number,
            'eway_doc_date' => $eway_doc_date,
            'eway_transport_mode' => $eway_transport_mode
        ]
    ]);
} else {
    $error = $stmt->errorInfo();
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to save E-Way Bill details: ' . ($error[2] ?? 'Unknown error')
    ]);
}

exit;