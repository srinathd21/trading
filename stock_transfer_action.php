<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

// Force collation
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

header('Content-Type: application/json');

try {
    if ($action === 'status_update' && $id > 0) {
        $valid_status = ['approved', 'in_transit', 'delivered'];
        
        if (!in_array($status, $valid_status)) {
            throw new Exception("Invalid status");
        }
        
        // Check permissions
        if ($status === 'approved' && !in_array($user_role, ['admin', 'warehouse_manager'])) {
            throw new Exception("You don't have permission to approve transfers");
        }
        
        if ($status === 'in_transit' && !in_array($user_role, ['admin', 'warehouse_manager'])) {
            throw new Exception("You don't have permission to dispatch transfers");
        }
        
        if ($status === 'delivered' && !in_array($user_role, ['admin', 'warehouse_manager', 'shop_manager'])) {
            throw new Exception("You don't have permission to mark as delivered");
        }

        $pdo->beginTransaction();

        // Get transfer details
        $stmt = $pdo->prepare("
            SELECT from_shop_id, to_shop_id, status 
            FROM stock_transfers 
            WHERE id = ? AND status NOT IN ('delivered', 'cancelled')
        ");
        $stmt->execute([$id]);
        $transfer = $stmt->fetch();

        if (!$transfer) {
            throw new Exception("Transfer not found or already processed");
        }

        $from_shop_id = $transfer['from_shop_id'];
        $to_shop_id = $transfer['to_shop_id'];

        // Update status
        $update_stmt = $pdo->prepare("
            UPDATE stock_transfers 
            SET status = ?, updated_at = NOW(), 
                approved_by = CASE WHEN ? = 'approved' THEN ? ELSE approved_by END,
                received_by = CASE WHEN ? = 'delivered' THEN ? ELSE received_by END
            WHERE id = ?
        ");
        $update_stmt->execute([$status, $status, $user_id, $status, $user_id, $id]);

        // Update transfer items status
        $pdo->prepare("
            UPDATE stock_transfer_items 
            SET status = ? 
            WHERE stock_transfer_id = ?
        ")->execute([$status, $id]);

        // If delivered, move stock
        if ($status === 'delivered') {
            // Get all items
            $items_stmt = $pdo->prepare("
                SELECT product_id, quantity 
                FROM stock_transfer_items 
                WHERE stock_transfer_id = ?
            ");
            $items_stmt->execute([$id]);
            $items = $items_stmt->fetchAll();

            foreach ($items as $item) {
                $product_id = $item['product_id'];
                $qty = (int)$item['quantity'];

                if ($qty <= 0) continue;

                // Reduce from source (warehouse)
                $pdo->prepare("
                    UPDATE product_stocks 
                    SET quantity = quantity - ?,
                        last_updated = NOW()
                    WHERE product_id = ? AND shop_id = ?
                ")->execute([$qty, $product_id, $from_shop_id]);

                // Add to destination (shop)
                $pdo->prepare("
                    INSERT INTO product_stocks (product_id, shop_id, quantity, last_updated) 
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        quantity = quantity + VALUES(quantity),
                        last_updated = NOW()
                ")->execute([$product_id, $to_shop_id, $qty]);

                // Log stock adjustment
                $adj_stmt = $pdo->prepare("
                    INSERT INTO stock_adjustments 
                    (adjustment_number, product_id, shop_id, adjustment_type, quantity, 
                     old_stock, new_stock, reason, reference_id, reference_type, 
                     adjusted_by, adjusted_at)
                    VALUES 
                    (?, ?, ?, 'transfer_out', ?, 
                     (SELECT quantity FROM product_stocks WHERE product_id = ? AND shop_id = ?),
                     (SELECT quantity FROM product_stocks WHERE product_id = ? AND shop_id = ?) - ?,
                     'Stock Transfer to Shop #{$to_shop_id}', ?, 'stock_transfer', ?, NOW())
                ");
                $adj_number = 'ADJ' . date('YmdHis') . rand(100, 999);
                $adj_stmt->execute([
                    $adj_number, $product_id, $from_shop_id, $qty,
                    $product_id, $from_shop_id,
                    $product_id, $from_shop_id, $qty,
                    $id, $user_id
                ]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Transfer status updated successfully!']);
        
    } elseif ($action === 'cancel' && $id > 0) {
        // Cancel transfer logic
        if (!in_array($user_role, ['admin', 'warehouse_manager'])) {
            throw new Exception("You don't have permission to cancel transfers");
        }
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE stock_transfers 
            SET status = 'cancelled', updated_at = NOW() 
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception("Transfer cannot be cancelled (already processed or not found)");
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Transfer cancelled successfully!']);
        
    } else {
        throw new Exception("Invalid action");
    }
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Stock Transfer Action Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>