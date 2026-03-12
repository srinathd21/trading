<?php
header('Content-Type: application/json');
require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

try {
    $pdo->beginTransaction();
    
    // Save invoice
    $stmt = $pdo->prepare("
        INSERT INTO invoices (
            invoice_number, customer_id, invoice_type, customer_type,
            subtotal, discount, discount_type, overall_discount, total,
            cash_received, change_given, pending_amount, paid_amount,
            payment_status, cash_amount, upi_amount, bank_amount, cheque_amount,
            cheque_number, upi_reference, bank_reference, payment_method,
            notes, seller_id, shop_id, business_id, referral_id,
            referral_commission_percent, referral_commission_amount,
            points_redeemed, points_discount_amount, gst_status, gst_type
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // ... (calculate values from $data)
    $stmt->execute([/* values */]);
    $invoice_id = $pdo->lastInsertId();
    
    // Save invoice items
    foreach ($data['items'] as $item) {
        $itemStmt = $pdo->prepare("
            INSERT INTO invoice_items (
                invoice_id, product_id, batch_id, sale_type, quantity,
                unit_price, original_price, total_price, discount_rate,
                discount_amount, hsn_code, cgst_rate, sgst_rate, igst_rate,
                cgst_amount, sgst_amount, igst_amount, total_with_gst,
                taxable_value, profit, new_batch_product_profit, new_batch_product_loss,
                gst_inclusive, referral_commission, unit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        // ... (calculate item values)
        $itemStmt->execute([/* values */]);
    }
    
    // Update customer points if used
    if ($data['points_used'] > 0) {
        $pointsStmt = $pdo->prepare("
            UPDATE customer_points 
            SET total_points_redeemed = total_points_redeemed + ?,
                available_points = available_points - ?,
                last_updated = NOW()
            WHERE customer_id = ? AND business_id = ?
        ");
        $pointsStmt->execute([
            $data['points_used'],
            $data['points_used'],
            $data['customer_id'],
            $_SESSION['current_business_id']
        ]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'invoice_id' => $invoice_id,
        'message' => 'Invoice saved successfully'
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save invoice: ' . $e->getMessage()
    ]);
}
?>