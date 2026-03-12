<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'seller') != 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$invoice_id = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;

if ($invoice_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid invoice ID']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // First, get invoice details for stock restoration
    $invoiceQuery = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
    $invoiceQuery->execute([$invoice_id]);
    $invoice = $invoiceQuery->fetch();
    
    if (!$invoice) {
        throw new Exception("Invoice not found");
    }
    
    // Get all items from this invoice
    $itemsQuery = $pdo->prepare("
        SELECT product_id, location_id, quantity 
        FROM invoice_items ii
        LEFT JOIN invoices i ON ii.invoice_id = i.id
        WHERE ii.invoice_id = ?
    ");
    $itemsQuery->execute([$invoice_id]);
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Restore stock for each item
    foreach ($items as $item) {
        $restoreQuery = $pdo->prepare("
            UPDATE product_stocks 
            SET quantity = quantity + ?, last_updated = NOW()
            WHERE product_id = ? AND location_id = ?
        ");
        $restoreQuery->execute([$item['quantity'], $item['product_id'], $item['location_id']]);
        
        // Record stock adjustment
        $adjQuery = $pdo->prepare("
            INSERT INTO stock_adjustments 
            (product_id, location_id, adjustment_type, quantity, reason, adjusted_by, adjusted_at)
            VALUES (?, ?, 'add', ?, 'Invoice Deletion - {$invoice['invoice_number']}', ?, NOW())
        ");
        $adjQuery->execute([$item['product_id'], $item['location_id'], $item['quantity'], $_SESSION['user_id']]);
    }
    
    // Delete invoice items
    $deleteItems = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
    $deleteItems->execute([$invoice_id]);
    
    // Delete GST summary
    $deleteGST = $pdo->prepare("DELETE FROM invoice_gst_summary WHERE invoice_id = ?");
    $deleteGST->execute([$invoice_id]);
    
    // Delete the invoice
    $deleteInvoice = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
    $deleteInvoice->execute([$invoice_id]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Invoice deleted successfully'
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}