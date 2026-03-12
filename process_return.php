<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? '';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// Check if user can process returns
$can_process_returns = in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier']);
if (!$can_process_returns) {
    $_SESSION['error'] = "You don't have permission to process returns.";
    header('Location: invoices.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $return_reason = trim($_POST['return_reason'] ?? '');
    $return_date = $_POST['return_date'] ?? date('Y-m-d');
    $return_notes = trim($_POST['return_notes'] ?? '');
    $refund_to_cash = isset($_POST['refund_to_cash']) ? 1 : 0;
    
    // Validate input
    if (!$invoice_id || !$customer_id || !$return_reason) {
        $_SESSION['error'] = "Missing required fields.";
        header('Location: invoices.php');
        exit();
    }
    
    // Verify invoice belongs to this business and shop
    $invoice_check = $pdo->prepare("
        SELECT i.*, s.business_id 
        FROM invoices i
        JOIN shops s ON i.shop_id = s.id
        WHERE i.id = ? AND s.business_id = ?
    ");
    $invoice_check->execute([$invoice_id, $business_id]);
    $invoice = $invoice_check->fetch();
    
    if (!$invoice) {
        $_SESSION['error'] = "Invoice not found.";
        header('Location: invoices.php');
        exit();
    }
    
    // Get return items from POST
    $return_items = [];
    $total_return_amount = 0;
    $has_items = false;
    
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'return_qty_') === 0) {
            $item_id = (int)str_replace('return_qty_', '', $key);
            $qty = (int)$value;
            
            if ($qty > 0) {
                $has_items = true;
                
                // Get item details
                $item_stmt = $pdo->prepare("
                    SELECT ii.*, p.stock_price 
                    FROM invoice_items ii
                    JOIN products p ON ii.product_id = p.id
                    WHERE ii.id = ? AND ii.invoice_id = ?
                ");
                $item_stmt->execute([$item_id, $invoice_id]);
                $item = $item_stmt->fetch();
                
                if ($item) {
                    // Check if return quantity is valid
                    $max_return = $item['quantity'] - ($item['return_qty'] ?? 0);
                    if ($qty > $max_return) {
                        $_SESSION['error'] = "Return quantity exceeds available quantity for one or more items.";
                        header('Location: invoices.php');
                        exit();
                    }
                    
                    $return_amount = $qty * $item['unit_price'];
                    $total_return_amount += $return_amount;
                    
                    $return_items[] = [
                        'item_id' => $item_id,
                        'product_id' => $item['product_id'],
                        'quantity' => $qty,
                        'unit_price' => $item['unit_price'],
                        'return_amount' => $return_amount,
                        'stock_price' => $item['stock_price']
                    ];
                }
            }
        }
    }
    
    if (!$has_items) {
        $_SESSION['error'] = "No items selected for return.";
        header('Location: invoices.php');
        exit();
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Create return record
        $return_stmt = $pdo->prepare("
            INSERT INTO returns (
                invoice_id, customer_id, return_date, total_return_amount, 
                return_reason, notes, refund_to_cash, processed_by, business_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $return_stmt->execute([
            $invoice_id,
            $customer_id,
            $return_date,
            $total_return_amount,
            $return_reason,
            $return_notes,
            $refund_to_cash,
            $user_id,
            $business_id
        ]);
        
        $return_id = $pdo->lastInsertId();
        
        // Update invoice items and create return items
        foreach ($return_items as $item) {
            // Update return_qty in invoice_items
            $update_item = $pdo->prepare("
                UPDATE invoice_items 
                SET return_qty = return_qty + ?, 
                    return_status = CASE 
                        WHEN return_qty + ? >= quantity THEN 1 
                        ELSE return_status 
                    END
                WHERE id = ?
            ");
            $update_item->execute([$item['quantity'], $item['quantity'], $item['item_id']]);
            
            // Insert return item
            $return_item_stmt = $pdo->prepare("
                INSERT INTO return_items (
                    return_id, invoice_item_id, product_id, 
                    quantity, unit_price, return_value
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $return_item_stmt->execute([
                $return_id,
                $item['item_id'],
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['return_amount']
            ]);
            
            // Restock the product
            $restock_stmt = $pdo->prepare("
                UPDATE product_stocks 
                SET quantity = quantity + ?,
                    last_updated = NOW()
                WHERE product_id = ? AND shop_id = ?
            ");
            $restock_stmt->execute([$item['quantity'], $item['product_id'], $current_shop_id]);
            
            // Record stock movement
            $movement_stmt = $pdo->prepare("
                INSERT INTO stock_movements (
                    product_id, shop_id, business_id, movement_type, 
                    quantity, reference_type, reference_id, notes, created_by, created_at
                ) VALUES (?, ?, ?, 'return', ?, 'return', ?, ?, ?, NOW())
            ");
            $movement_stmt->execute([
                $item['product_id'],
                $current_shop_id,
                $business_id,
                $item['quantity'],
                $return_id,
                "Return from invoice #" . $invoice['invoice_number'],
                $user_id
            ]);
        }
        
        // Update invoice payment status if needed
        $paid_amount = $invoice['paid_amount'] ?? 0;
        $total = $invoice['total'] ?? 0;
        
        // If refund is to cash and payment was made, adjust payment status
        if ($refund_to_cash && $paid_amount > 0) {
            // You might want to record the refund payment here
            $refund_payment_stmt = $pdo->prepare("
                INSERT INTO invoice_payments (
                    invoice_id, customer_id, business_id, payment_amount, 
                    payment_method, payment_date, notes, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'refund', ?, ?, ?, NOW())
            ");
            $refund_payment_stmt->execute([
                $invoice_id,
                $customer_id,
                $business_id,
                -$total_return_amount,
                $return_date,
                "Refund for return #$return_id",
                $user_id
            ]);
        }
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION['success'] = "Return processed successfully. Return amount: ₹" . number_format($total_return_amount, 2);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to process return: " . $e->getMessage();
    }
    
    header('Location: invoices.php');
    exit();
} else {
    header('Location: invoices.php');
    exit();
}
?>