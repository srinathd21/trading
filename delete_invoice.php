<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['current_business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// Check if user has permission to delete invoices (only admin or shop_manager)
if (!in_array($user_role, ['admin', 'shop_manager'])) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to delete invoices']);
    exit();
}

if (!isset($_POST['invoice_id']) || empty($_POST['invoice_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invoice ID is required']);
    exit();
}

$invoice_id = (int)$_POST['invoice_id'];

try {
    $pdo->beginTransaction();

    // Check if invoice exists and belongs to the current business
    $check_stmt = $pdo->prepare("
        SELECT i.*, s.id as shop_id, s.shop_name 
        FROM invoices i
        LEFT JOIN shops s ON i.shop_id = s.id
        WHERE i.id = ? AND i.business_id = ?
    ");
    $check_stmt->execute([$invoice_id, $business_id]);
    $invoice = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    // Check if user has access to this invoice's shop
    if ($user_role !== 'admin' && $invoice['shop_id'] != $current_shop_id) {
        throw new Exception('You do not have permission to delete this invoice');
    }

    // Check if invoice already has returns
    $return_check = $pdo->prepare("
        SELECT COUNT(*) as return_count 
        FROM returns 
        WHERE invoice_id = ?
    ");
    $return_check->execute([$invoice_id]);
    $return_data = $return_check->fetch(PDO::FETCH_ASSOC);

    if ($return_data['return_count'] > 0) {
        throw new Exception('Cannot delete invoice with existing returns');
    }

    // ===== RESTOCK ITEMS =====
    // Get all items from this invoice with their details
    $items_stmt = $pdo->prepare("
        SELECT ii.*, 
               p.id as product_id,
               p.secondary_unit,
               p.sec_unit_conversion,
               p.unit_of_measure as primary_unit,
               p.stock_price,
               p.retail_price,
               p.wholesale_price
        FROM invoice_items ii
        JOIN products p ON ii.product_id = p.id
        WHERE ii.invoice_id = ?
    ");
    $items_stmt->execute([$invoice_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $product_id = $item['product_id'];
        $quantity = (int)$item['quantity'];
        $shop_id = $invoice['shop_id'];
        
        // IMPORTANT: Check if the sale was in secondary unit (like meters)
        // The 'unit' field in invoice_items will tell us what unit was used for sale
        $sold_in_secondary = !empty($item['secondary_unit']) && $item['unit'] == $item['secondary_unit'];
        
        // Calculate quantities for restocking
        $primary_quantity = 0;
        $secondary_quantity = 0;
        
        if ($sold_in_secondary) {
            // Sold in secondary unit (e.g., meters)
            $secondary_quantity = $quantity;
            
            // Calculate how many primary units (coils) this represents
            if (!empty($item['sec_unit_conversion']) && $item['sec_unit_conversion'] > 0) {
                // Convert secondary units back to primary units (meters to coils)
                // For Green Wire: 1 coil = 90 meters, so 50 meters = 50/90 = 0.5555 coils
                $primary_quantity = $secondary_quantity / $item['sec_unit_conversion'];
            }
        } else {
            // Sold in primary unit (e.g., coils)
            $primary_quantity = $quantity;
            
            // Calculate secondary units if product has secondary unit
            if (!empty($item['secondary_unit']) && !empty($item['sec_unit_conversion']) && $item['sec_unit_conversion'] > 0) {
                $secondary_quantity = $quantity * $item['sec_unit_conversion'];
            }
        }

        // Check if product stock exists
        $stock_stmt = $pdo->prepare("
            SELECT id, quantity, total_secondary_units 
            FROM product_stocks 
            WHERE product_id = ? AND shop_id = ? AND business_id = ?
        ");
        $stock_stmt->execute([$product_id, $shop_id, $business_id]);
        $stock = $stock_stmt->fetch(PDO::FETCH_ASSOC);

        if ($stock) {
            // Update existing stock
            $new_quantity = $stock['quantity'] + $primary_quantity;
            $new_secondary_units = $stock['total_secondary_units'] + $secondary_quantity;
            
            $update_stmt = $pdo->prepare("
                UPDATE product_stocks 
                SET quantity = ?,
                    total_secondary_units = ?,
                    last_updated = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([$new_quantity, $new_secondary_units, $stock['id']]);
            
            $stock_id = $stock['id'];
        } else {
            // Create new stock record
            $insert_stmt = $pdo->prepare("
                INSERT INTO product_stocks 
                (product_id, shop_id, business_id, quantity, total_secondary_units, last_updated)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $insert_stmt->execute([$product_id, $shop_id, $business_id, $primary_quantity, $secondary_quantity]);
            $stock_id = $pdo->lastInsertId();
        }

        // Log stock movement in stock_movements table with detailed notes
        $log_stmt = $pdo->prepare("
            INSERT INTO stock_movements 
            (product_id, stock_id, shop_id, business_id, movement_type, quantity, 
             secondary_quantity, reference_type, reference_id, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, 'restock', ?, ?, 'invoice_deletion', ?, ?, ?, NOW())
        ");
        
        $restock_note = "Restocked from deleted invoice #{$invoice['invoice_number']}. ";
        if ($sold_in_secondary) {
            $restock_note .= "Sold in {$item['unit']} (secondary unit): {$secondary_quantity} {$item['secondary_unit']} restocked, equivalent to " . round($primary_quantity, 4) . " {$item['primary_unit']}";
        } else {
            $restock_note .= "Sold in {$item['primary_unit']} (primary unit): {$primary_quantity} {$item['primary_unit']} restocked";
            if ($secondary_quantity > 0) {
                $restock_note .= ", plus {$secondary_quantity} {$item['secondary_unit']} in secondary units";
            }
        }
        
        $log_stmt->execute([
            $product_id,
            $stock_id,
            $shop_id,
            $business_id,
            $primary_quantity,
            $secondary_quantity,
            $invoice_id,
            $restock_note,
            $user_id
        ]);

        // Update product's updated_at timestamp
        $update_product_stmt = $pdo->prepare("
            UPDATE products 
            SET updated_at = NOW() 
            WHERE id = ?
        ");
        $update_product_stmt->execute([$product_id]);
    }

    // ===== DELETE POINT TRANSACTIONS AND REVERSE POINTS =====
    // First, get point transactions to reverse points
    $point_stmt = $pdo->prepare("
        SELECT * FROM point_transactions 
        WHERE invoice_id = ? AND transaction_type = 'earned'
    ");
    $point_stmt->execute([$invoice_id]);
    $point_transactions = $point_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($point_transactions as $pt) {
        // Reverse points earned from this invoice in customer_points
        $reverse_stmt = $pdo->prepare("
            UPDATE customer_points 
            SET total_points_earned = total_points_earned - ?,
                available_points = available_points - ?,
                last_updated = NOW()
            WHERE customer_id = ? AND business_id = ?
        ");
        $reverse_stmt->execute([
            $pt['points'],
            $pt['points'],
            $invoice['customer_id'],
            $business_id
        ]);

        // Log point reversal
        $log_point_stmt = $pdo->prepare("
            INSERT INTO point_transactions 
            (customer_id, business_id, invoice_id, transaction_type, points, amount_basis, notes, created_by, created_at)
            VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, NOW())
        ");
        $log_point_stmt->execute([
            $invoice['customer_id'],
            $business_id,
            $invoice_id,
            -abs($pt['points']), // Negative points for reversal
            $pt['amount_basis'] ?? 0,
            "Points reversed due to invoice deletion (Invoice #{$invoice['invoice_number']})",
            $user_id
        ]);
    }

    // Delete original point transactions
    $delete_points = $pdo->prepare("DELETE FROM point_transactions WHERE invoice_id = ?");
    $delete_points->execute([$invoice_id]);

    // Delete points earnings records
    $delete_earnings = $pdo->prepare("DELETE FROM points_earnings WHERE invoice_id = ?");
    $delete_earnings->execute([$invoice_id]);

    // Delete points redemptions if any
    $delete_redemptions = $pdo->prepare("DELETE FROM points_redemptions WHERE invoice_id = ?");
    $delete_redemptions->execute([$invoice_id]);

    // ===== DELETE GST SUMMARY =====
    $delete_gst = $pdo->prepare("DELETE FROM invoice_gst_summary WHERE invoice_id = ?");
    $delete_gst->execute([$invoice_id]);

    // ===== DELETE INVOICE ITEMS =====
    $delete_items = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
    $delete_items->execute([$invoice_id]);

    // ===== DELETE PAYMENT RECORDS =====
    // Check if invoice_payments table exists
    $payments_table = $pdo->query("SHOW TABLES LIKE 'invoice_payments'");
    if ($payments_table->rowCount() > 0) {
        $delete_payments = $pdo->prepare("DELETE FROM invoice_payments WHERE invoice_id = ?");
        $delete_payments->execute([$invoice_id]);
    }

    // Check if payments table exists
    $payments_table2 = $pdo->query("SHOW TABLES LIKE 'payments'");
    if ($payments_table2->rowCount() > 0) {
        $delete_payments2 = $pdo->prepare("DELETE FROM payments WHERE reference_id = ? AND type = 'customer'");
        $delete_payments2->execute([$invoice_id]);
    }

    // Check if customer_payments table exists
    $customer_payments_table = $pdo->query("SHOW TABLES LIKE 'customer_payments'");
    if ($customer_payments_table->rowCount() > 0) {
        $delete_customer_payments = $pdo->prepare("DELETE FROM customer_payments WHERE invoice_id = ?");
        $delete_customer_payments->execute([$invoice_id]);
    }

    // ===== DELETE INVOICE CREDIT =====
    $credit_table = $pdo->query("SHOW TABLES LIKE 'invoice_credit'");
    if ($credit_table->rowCount() > 0) {
        $delete_credit = $pdo->prepare("DELETE FROM invoice_credit WHERE invoice_id = ?");
        $delete_credit->execute([$invoice_id]);
    }

    // ===== DELETE WHATSAPP LOGS =====
    $whatsapp_table = $pdo->query("SHOW TABLES LIKE 'whatsapp_logs'");
    if ($whatsapp_table->rowCount() > 0) {
        $delete_whatsapp = $pdo->prepare("DELETE FROM whatsapp_logs WHERE invoice_id = ?");
        $delete_whatsapp->execute([$invoice_id]);
    }

    // ===== DELETE FROM REFERRAL TRANSACTIONS =====
    $referral_table = $pdo->query("SHOW TABLES LIKE 'referral_transactions'");
    if ($referral_table->rowCount() > 0) {
        $delete_referral = $pdo->prepare("DELETE FROM referral_transactions WHERE invoice_id = ?");
        $delete_referral->execute([$invoice_id]);
    }

    // ===== DELETE FROM CUSTOMER ADDRESSES =====
    $address_table = $pdo->query("SHOW TABLES LIKE 'customer_addresses'");
    if ($address_table->rowCount() > 0) {
        $delete_address = $pdo->prepare("DELETE FROM customer_addresses WHERE invoice_id = ?");
        $delete_address->execute([$invoice_id]);
    }

    // ===== DELETE INVOICE =====
    $delete_invoice = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
    $delete_invoice->execute([$invoice_id]);

    // ===== LOG DELETION =====
    $log_deletion = $pdo->prepare("
        INSERT INTO deletion_logs 
        (table_name, record_id, record_details, deleted_by, business_id, created_at)
        VALUES ('invoices', ?, ?, ?, ?, NOW())
    ");
    $record_details = json_encode([
        'invoice_number' => $invoice['invoice_number'],
        'total' => $invoice['total'],
        'customer_id' => $invoice['customer_id'],
        'shop_id' => $invoice['shop_id']
    ]);
    $log_deletion->execute([$invoice_id, $record_details, $user_id, $business_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Invoice deleted successfully and items restocked',
        'invoice_number' => $invoice['invoice_number']
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>