<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$is_editing = $data['is_editing'] ?? false;
$quotation_id = $data['quotation_id'] ?? 0;

try {
    $pdo->beginTransaction();
    
    if ($is_editing && $quotation_id) {
        // UPDATE existing quotation
        // Check if quotation exists and belongs to this business
        $checkStmt = $pdo->prepare("
            SELECT id FROM quotations 
            WHERE id = ? AND business_id = ? AND shop_id = ? AND status = 'draft'
        ");
        $checkStmt->execute([$quotation_id, $data['business_id'], $data['shop_id']]);
        
        if (!$checkStmt->fetch()) {
            throw new Exception('Cannot edit this quotation. It may be already sent or does not exist.');
        }
        
        // Update quotation
        $stmt = $pdo->prepare("
            UPDATE quotations SET
                quotation_date = ?,
                valid_until = ?,
                customer_name = ?,
                customer_phone = ?,
                customer_gstin = ?,
                subtotal = ?,
                total_discount = ?,
                grand_total = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['quotation_date'],
            $data['valid_until'],
            $data['customer_name'],
            $data['customer_phone'] ?? null,
            $data['customer_gstin'] ?? null,
            $data['subtotal'],
            $data['total_discount'],
            $data['grand_total'],
            $data['notes'] ?? null,
            $quotation_id
        ]);
        
        // Delete old items
        $deleteStmt = $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id = ?");
        $deleteStmt->execute([$quotation_id]);
        
    } else {
        // CREATE new quotation
        $stmt = $pdo->prepare("
            INSERT INTO quotations (
                business_id, shop_id, quotation_number, quotation_date, valid_until,
                customer_name, customer_phone, customer_gstin, subtotal,
                total_discount, grand_total, notes, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
        ");
        
        $stmt->execute([
            $data['business_id'],
            $data['shop_id'],
            $data['quotation_number'],
            $data['quotation_date'],
            $data['valid_until'],
            $data['customer_name'],
            $data['customer_phone'] ?? null,
            $data['customer_gstin'] ?? null,
            $data['subtotal'],
            $data['total_discount'],
            $data['grand_total'],
            $data['notes'] ?? null,
            $data['created_by']
        ]);
        
        $quotation_id = $pdo->lastInsertId();
    }
    
    // Save quotation items
    $itemStmt = $pdo->prepare("
        INSERT INTO quotation_items (
            quotation_id, product_id, product_name, quantity, unit_price,
            discount_amount, discount_type, total_price, price_type
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($data['items'] as $item) {
        $itemStmt->execute([
            $quotation_id,
            $item['product_id'],
            $item['product_name'],
            $item['quantity'],
            $item['unit_price'],
            $item['discount_amount'],
            $item['discount_type'],
            $item['total_price'],
            $item['price_type']
        ]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => $is_editing ? 'Quotation updated successfully' : 'Quotation saved successfully',
        'quotation_id' => $quotation_id,
        'quotation_number' => $data['quotation_number']
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}