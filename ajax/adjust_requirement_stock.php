<?php
// ajax/adjust_requirement_stock.php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'shop_manager', 'sales'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requirement_id = (int)($_POST['requirement_id'] ?? 0);
    $action = $_POST['action'] ?? 'approve'; // approve, reject, fulfill
    
    if ($requirement_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid requirement ID']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get requirement details
        $req_stmt = $pdo->prepare("
            SELECT sr.*, p.id as product_id, p.product_name, 
                   p.wholesale_price, p.stock_price,
                   COALESCE(ps.quantity, 0) as warehouse_stock,
                   ps.id as stock_id, ps.shop_id as warehouse_id
            FROM store_requirements sr
            JOIN products p ON sr.product_id = p.id
            LEFT JOIN product_stocks ps ON ps.product_id = p.id 
                AND ps.shop_id = (SELECT id FROM shops WHERE is_warehouse = 1 AND business_id = ? LIMIT 1)
            WHERE sr.id = ? AND sr.business_id = ?
        ");
        $req_stmt->execute([$business_id, $requirement_id, $business_id]);
        $requirement = $req_stmt->fetch();
        
        if (!$requirement) {
            throw new Exception('Requirement not found');
        }
        
        if ($action === 'approve') {
            // Check if stock is available
            $warehouse_stock = $requirement['warehouse_stock'];
            $required_qty = $requirement['required_quantity'];
            
            if ($warehouse_stock >= $required_qty) {
                // Update product stock in warehouse
                if ($requirement['stock_id']) {
                    // Update existing stock
                    $update_stock = $pdo->prepare("
                        UPDATE product_stocks 
                        SET quantity = quantity - ?,
                            last_updated = NOW()
                        WHERE id = ? AND business_id = ?
                    ");
                    $update_stock->execute([$required_qty, $requirement['stock_id'], $business_id]);
                } else {
                    // Insert new stock record (should not happen)
                    $insert_stock = $pdo->prepare("
                        INSERT INTO product_stocks (product_id, shop_id, business_id, quantity, last_updated)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    // Set to negative quantity since we're deducting
                    $new_quantity = -$required_qty;
                    $insert_stock->execute([
                        $requirement['product_id'], 
                        $requirement['warehouse_id'], 
                        $business_id, 
                        $new_quantity
                    ]);
                }
                
                // Record stock adjustment
                $adjustment_number = 'ADJ-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                $old_stock = $warehouse_stock;
                $new_stock = $warehouse_stock - $required_qty;
                
                $adj_stmt = $pdo->prepare("
                    INSERT INTO stock_adjustments 
                    (adjustment_number, product_id, shop_id, business_id, adjustment_type, 
                     quantity, old_stock, new_stock, reason, reference_id, reference_type, 
                     notes, adjusted_by, adjusted_at)
                    VALUES (?, ?, ?, ?, 'transfer_out', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $adj_stmt->execute([
                    $adjustment_number,
                    $requirement['product_id'],
                    $requirement['warehouse_id'],
                    $business_id,
                    $required_qty,
                    $old_stock,
                    $new_stock,
                    'Store requirement fulfillment',
                    $requirement_id,
                    'store_requirement',
                    "Fulfilling requirement #{$requirement_id} for store visit #{$requirement['store_visit_id']}",
                    $user_id
                ]);
                
                // Update requirement status
                $update_req = $pdo->prepare("
                    UPDATE store_requirements 
                    SET requirement_status = 'approved',
                        stock_status = 'deducted',
                        approved_by = ?,
                        approved_at = NOW()
                    WHERE id = ? AND business_id = ?
                ");
                $update_req->execute([$user_id, $requirement_id, $business_id]);
                
                $message = "Stock adjusted successfully. {$required_qty} units deducted from warehouse.";
            } else {
                // Partial fulfillment or out of stock
                $stock_status = $warehouse_stock > 0 ? 'partial' : 'out_of_stock';
                $update_req = $pdo->prepare("
                    UPDATE store_requirements 
                    SET requirement_status = 'approved',
                        stock_status = ?,
                        notes = CONCAT(IFNULL(notes, ''), ' | Stock insufficient: Need {$required_qty}, Have {$warehouse_stock}'),
                        approved_by = ?,
                        approved_at = NOW()
                    WHERE id = ? AND business_id = ?
                ");
                $update_req->execute([$stock_status, $user_id, $requirement_id, $business_id]);
                
                $message = "Requirement approved but stock insufficient. Need: {$required_qty}, Available: {$warehouse_stock}";
            }
        } elseif ($action === 'reject') {
            // Just update status
            $update_req = $pdo->prepare("
                UPDATE store_requirements 
                SET requirement_status = 'rejected',
                    rejected_by = ?,
                    rejected_at = NOW(),
                    notes = CONCAT(IFNULL(notes, ''), ' | Rejected by admin')
                WHERE id = ? AND business_id = ?
            ");
            $update_req->execute([$user_id, $requirement_id, $business_id]);
            $message = "Requirement rejected successfully";
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => $message]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}