<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// This file only handles AJAX requests
header('Content-Type: application/json');

// Check if it's an AJAX request
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
if (!$is_ajax) {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    require_once 'config/database.php';
    
    // Check login
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Please login first');
    }
    
    // Get POST data
    $purchase_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if (!$purchase_id) {
        throw new Exception('Invalid purchase ID');
    }
    
    $user_id = $_SESSION['user_id'];
    $business_id = $_SESSION['business_id'] ?? 1;
    
    // Log the delete attempt
    error_log("Attempting to delete purchase ID: $purchase_id for business: $business_id");
    
    // Verify purchase exists and belongs to this business
    $check_stmt = $pdo->prepare("SELECT id, purchase_number, shop_id FROM purchases WHERE id = ? AND business_id = ?");
    $check_stmt->execute([$purchase_id, $business_id]);
    $purchase = $check_stmt->fetch();
    
    if (!$purchase) {
        throw new Exception('Purchase order not found.');
    }
    
    // Get the shop_id from the purchase (may be NULL)
    $purchase_shop_id = $_SESSION['current_shop_id'];
    
    // Begin transaction
    $pdo->beginTransaction();
    $in_transaction = true;
    
    // Get all batches for this purchase
    $batches_stmt = $pdo->prepare("
        SELECT * FROM purchase_batches 
        WHERE purchase_id = ? AND business_id = ?
    ");
    $batches_stmt->execute([$purchase_id, $business_id]);
    $batches = $batches_stmt->fetchAll();
    
    error_log("Found " . count($batches) . " batches");
    
    // FIRST: Update product_stocks where batch_id matches the batch being deleted
    // Create debug log file path
$debug_log_file = __DIR__ . '/purchase_delete_debug.log';

// Helper function to write to debug log
function writeDebugLog($message, $data = null) {
    global $debug_log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message";
    if ($data !== null) {
        $log_message .= " - Data: " . print_r($data, true);
    }
    $log_message .= PHP_EOL;
    file_put_contents($debug_log_file, $log_message, FILE_APPEND);
}

// Clear debug log at start of batch processing
writeDebugLog("========== STARTING PURCHASE DELETION DEBUG ==========");
writeDebugLog("Purchase ID: " . ($purchase['id'] ?? 'unknown'));
writeDebugLog("Purchase Number: " . ($purchase['purchase_number'] ?? 'unknown'));
writeDebugLog("Shop ID: " . ($purchase_shop_id ?? 'NULL'));

// Process each batch
foreach ($batches as $batch_index => $batch) {
    $product_id = $batch['product_id'];
    $batch_id = $batch['id'];
    
    writeDebugLog("=== Processing Batch #" . ($batch_index + 1) . " ===");
    writeDebugLog("Batch Details", [
        'batch_id' => $batch_id,
        'product_id' => $product_id,
        'quantity_received' => $batch['quantity_received'],
        'quantity_remaining' => $batch['quantity_remaining'],
        'batch_number' => $batch['batch_number'] ?? 'N/A',
        'purchase_price' => $batch['purchase_price'],
        'old_purchase_price' => $batch['old_purchase_price'],
        'old_retail_price' => $batch['old_retail_price'],
        'old_wholesale_price' => $batch['old_wholesale_price'],
        'old_mrp' => $batch['old_mrp']
    ]);
    
    // Find product_stock records that have this batch_id
    writeDebugLog("Looking for stock records with batch_id = $batch_id in shop_id = $purchase_shop_id");
    
    $stock_stmt = $pdo->prepare("
        SELECT id, product_id, shop_id, quantity, old_qty, total_secondary_units, batch_id, use_batch_tracking 
        FROM product_stocks 
        WHERE product_id = ? AND shop_id = ? AND business_id = ? AND batch_id = ?
    ");
    $stock_stmt->execute([$product_id, $purchase_shop_id, $business_id, $batch_id]);
    $stock_records = $stock_stmt->fetchAll();
    
    writeDebugLog("Found " . count($stock_records) . " stock record(s) with matching batch_id");
    
    if (count($stock_records) > 0) {
        writeDebugLog("Stock records found:", $stock_records);
    } else {
        // Try to find any stock records for this product in this shop (even with different batch)
        $any_stock_stmt = $pdo->prepare("
            SELECT id, product_id, shop_id, quantity, old_qty, batch_id, use_batch_tracking
            FROM product_stocks 
            WHERE product_id = ? AND shop_id = ? AND business_id = ?
        ");
        $any_stock_stmt->execute([$product_id, $purchase_shop_id, $business_id]);
        $any_stock = $any_stock_stmt->fetchAll();
        
        writeDebugLog("No stock with exact batch_id found. Other stock records for this product in this shop:", $any_stock);
    }
    
    foreach ($stock_records as $stock_index => $stock) {
        writeDebugLog("--- Processing Stock Record #" . ($stock_index + 1) . " ---");
        writeDebugLog("Stock BEFORE update", [
            'stock_id' => $stock['id'],
            'current_quantity' => $stock['quantity'],
            'old_qty' => $stock['old_qty'],
            'batch_id' => $stock['batch_id'],
            'use_batch_tracking' => $stock['use_batch_tracking'],
            'total_secondary_units' => $stock['total_secondary_units'] ?? 0
        ]);
        
        // Store old values for logging
        $old_quantity = $stock['quantity'];
        $old_old_qty = $stock['old_qty'];
        $old_batch_id = $stock['batch_id'];
        $old_tracking = $stock['use_batch_tracking'];
        
        // Update stock: quantity = old_qty, old_qty = 0, batch_id = null, use_batch_tracking = 0
        $update_stock_stmt = $pdo->prepare("
            UPDATE product_stocks 
            SET quantity = old_qty,
                old_qty = 0,
                batch_id = NULL,
                use_batch_tracking = 0,
                last_updated = NOW()
            WHERE id = ?
        ");
        $update_result = $update_stock_stmt->execute([$stock['id']]);
        
        writeDebugLog("Stock UPDATE executed", [
            'success' => $update_result ? 'YES' : 'NO',
            'rows_affected' => $update_stock_stmt->rowCount()
        ]);
        
        if ($update_result && $update_stock_stmt->rowCount() > 0) {
            writeDebugLog("Stock AFTER update (verification)", [
                'stock_id' => $stock['id'],
                'quantity_set_to' => $old_old_qty,  // This is what old_qty was before
                'old_qty_set_to' => 0,
                'batch_id_set_to' => 'NULL',
                'use_batch_tracking_set_to' => 0
            ]);
            
            error_log("Updated stock ID {$stock['id']} for product $product_id: set quantity to old_qty ({$old_old_qty}), cleared batch tracking");
            
            // Log stock movement
            $movement_stmt = $pdo->prepare("
                INSERT INTO stock_movements 
                (product_id, stock_id, shop_id, business_id, movement_type, quantity, 
                 secondary_quantity, reference_type, reference_id, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, 'purchase_deletion', ?, ?, 'purchase', ?, ?, ?, NOW())
            ");
            
            $movement_result = $movement_stmt->execute([
                $product_id,
                $stock['id'],
                $purchase_shop_id,
                $business_id,
                $batch['quantity_remaining'], // Quantity being restored
                0, // secondary_quantity
                $purchase_id,
                "Stock restored to old_qty from deleted purchase #{$purchase['purchase_number']} (batch deleted)",
                $user_id
            ]);
            
            writeDebugLog("Stock movement logged", [
                'movement_id' => $pdo->lastInsertId(),
                'quantity' => $batch['quantity_remaining'],
                'success' => $movement_result ? 'YES' : 'NO'
            ]);
            
            // Verify the update was successful by fetching the record again
            $verify_stmt = $pdo->prepare("
                SELECT id, quantity, old_qty, batch_id, use_batch_tracking, last_updated
                FROM product_stocks 
                WHERE id = ?
            ");
            $verify_stmt->execute([$stock['id']]);
            $verified_stock = $verify_stmt->fetch();
            
            writeDebugLog("VERIFICATION - Stock record after all operations", $verified_stock);
            
        } else {
            writeDebugLog("ERROR: Failed to update stock record ID {$stock['id']}");
        }
    }
    
    // Also check if there are any other stock records for this product that might have been affected
    $check_other_stmt = $pdo->prepare("
        SELECT COUNT(*) as other_count 
        FROM product_stocks 
        WHERE product_id = ? AND shop_id = ? AND business_id = ? AND batch_id != ?
    ");
    $check_other_stmt->execute([$product_id, $purchase_shop_id, $business_id, $batch_id]);
    $other_count = $check_other_stmt->fetchColumn();
    
    writeDebugLog("Other stock records for this product (different batch): $other_count");
}

writeDebugLog("========== BATCH PROCESSING COMPLETED ==========");
writeDebugLog("");

// Continue with the rest of your deletion code...
    
    // SECOND: Update products table with old price values from the batch
    foreach ($batches as $batch) {
        $product_id = $batch['product_id'];
        $batch_id = $batch['id'];
        
        // Check if this product has any other active batches (excluding current one)
        $other_batch_stmt = $pdo->prepare("
            SELECT COUNT(*) as batch_count 
            FROM purchase_batches 
            WHERE product_id = ? AND business_id = ? AND id != ? AND quantity_remaining > 0
        ");
        $other_batch_stmt->execute([$product_id, $business_id, $batch_id]);
        $other_batch_result = $other_batch_stmt->fetch();
        $has_other_batches = ($other_batch_result['batch_count'] > 0);
        
        // Only update product prices if this was the last active batch for this product
        if (!$has_other_batches) {
            // Update product with old price values from the batch
            $update_product_stmt = $pdo->prepare("
                UPDATE products 
                SET stock_price = COALESCE(?, stock_price),
                    retail_price = COALESCE(?, retail_price),
                    wholesale_price = COALESCE(?, wholesale_price),
                    mrp = COALESCE(?, mrp),
                    updated_at = NOW()
                WHERE id = ? AND business_id = ?
            ");
            
            $update_product_stmt->execute([
                $batch['old_purchase_price'],
                $batch['old_retail_price'] ?? $batch['old_selling_price'],
                $batch['old_wholesale_price'] ?? $batch['old_selling_price'],
                $batch['old_mrp'],
                $product_id,
                $business_id
            ]);
            
            error_log("Restored product $product_id prices to old values from batch $batch_id");
        } else {
            error_log("Product $product_id has other active batches, skipping price restoration");
        }
    }
    
    // Get all payments for this purchase from payments table
    $payments_stmt = $pdo->prepare("
        SELECT * FROM payments 
        WHERE reference_id = ? AND type = 'supplier'
    ");
    $payments_stmt->execute([$purchase_id]);
    $payments = $payments_stmt->fetchAll();
    
    error_log("Found " . count($payments) . " payments");
    
    // Delete payments associated with this purchase
    if (!empty($payments)) {
        $delete_payments_stmt = $pdo->prepare("DELETE FROM payments WHERE reference_id = ? AND type = 'supplier'");
        $delete_payments_stmt->execute([$purchase_id]);
        error_log("Deleted " . count($payments) . " payments");
    }
    
    // Delete purchase items
    $delete_items_stmt = $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ? AND business_id = ?");
    $delete_items_stmt->execute([$purchase_id, $business_id]);
    error_log("Deleted purchase items");
    
    // Delete purchase batches
    $delete_batches_stmt = $pdo->prepare("DELETE FROM purchase_batches WHERE purchase_id = ? AND business_id = ?");
    $delete_batches_stmt->execute([$purchase_id, $business_id]);
    error_log("Deleted batches");
    
    // Delete GST credits associated with this purchase
    $delete_gst_stmt = $pdo->prepare("DELETE FROM gst_credits WHERE purchase_id = ? AND business_id = ?");
    $delete_gst_stmt->execute([$purchase_id, $business_id]);
    error_log("Deleted GST credits");
    
    // Finally, delete the purchase itself
    $delete_purchase_stmt = $pdo->prepare("DELETE FROM purchases WHERE id = ? AND business_id = ?");
    $delete_purchase_stmt->execute([$purchase_id, $business_id]);
    error_log("Deleted purchase");
    
    // Log the deletion
    $log_stmt = $pdo->prepare("
        INSERT INTO deletion_logs (table_name, record_id, record_details, deleted_by, business_id) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $record_details = json_encode([
        'purchase_number' => $purchase['purchase_number'],
        'id' => $purchase_id
    ]);
    $log_stmt->execute(['purchases', $purchase_id, $record_details, $user_id, $business_id]);
    
    $pdo->commit();
    $in_transaction = false;
    
    error_log("Purchase deletion successful");
    
    echo json_encode(['success' => true, 'message' => "Purchase order #{$purchase['purchase_number']} has been deleted successfully."]);
    
} catch (Exception $e) {
    // Only rollback if we have an active transaction
    if (isset($in_transaction) && $in_transaction && isset($pdo)) {
        $pdo->rollBack();
    }
    
    error_log("Purchase deletion error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode(['success' => false, 'message' => "Failed to delete purchase: " . $e->getMessage()]);
}
?>