<?php
header('Content-Type: application/json');
require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);
$business_id = $_SESSION['current_business_id'] ?? 1;
$shop_id = $_SESSION['current_shop_id'] ?? 1;

try {
    foreach ($data['items'] as $item) {
        $stmt = $pdo->prepare("
            UPDATE product_stocks 
            SET quantity = quantity - ?,
                old_qty = quantity,
                last_updated = NOW()
            WHERE product_id = ? 
                AND shop_id = ? 
                AND business_id = ?
        ");
        $stmt->execute([
            $item['quantity'],
            $item['product_id'],
            $shop_id,
            $business_id
        ]);
        
        // Log stock adjustment
        $adjustStmt = $pdo->prepare("
            INSERT INTO stock_adjustments (
                adjustment_number, product_id, shop_id, adjustment_type,
                quantity, old_stock, new_stock, reason, reference_id,
                reference_type, notes, adjusted_by, adjusted_at
            ) VALUES (?, ?, ?, 'sale', ?, ?, ?, 'Sale', ?, 'invoice', ?, ?, NOW())
        ");
        
        $adjustStmt->execute([
            'ADJ-' . time() . rand(100, 999),
            $item['product_id'],
            $shop_id,
            $item['quantity'],
            $old_stock, // Need to get this from before update
            $new_stock, // Need to calculate this
            $invoice_id ?? null,
            'Stock reduced due to sale',
            $_SESSION['user_id']
        ]);
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update stock: ' . $e->getMessage()
    ]);
}
?>