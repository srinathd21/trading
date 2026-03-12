<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// === DEBUG LOG FUNCTION ===
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/invoice_save_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message";
    if ($data !== null) {
        $log_entry .= " | " . json_encode($data, JSON_PRETTY_PRINT);
    }
    $log_entry .= "\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Clear any previous output
ob_start();
header('Content-Type: application/json');

// Start logging
debug_log("=== NEW INVOICE REQUEST STARTED ===");

if (!isset($_SESSION['user_id'])) {
    debug_log("Authentication failed - no user_id in session");
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    ob_end_flush();
    exit;
}

debug_log("User authenticated", ['user_id' => $_SESSION['user_id']]);

/* =========================
   INPUT VALIDATION
========================= */
$raw_input = file_get_contents('php://input');
debug_log("Raw input received", ['raw' => $raw_input]);

$input = json_decode($raw_input, true);
if (!$input || !isset($input['items']) || !is_array($input['items'])) {
    debug_log("Invalid JSON or missing items array");
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    ob_end_flush();
    exit;
}

debug_log("Input parsed successfully", [
    'item_count' => count($input['items']),
    'gst_type' => $input['gst_type'] ?? 'not set',
    'customer_name' => $input['customer_name'] ?? 'not set',
    'loyalty_points_used' => $input['loyalty_points_used'] ?? 0,
    'loyalty_points_discount' => $input['loyalty_points_discount'] ?? 0
]);

// DISABLE TRANSACTION FOR DEBUGGING
$use_transaction = false; // Set to false to debug

try {
    if ($use_transaction) {
        $pdo->beginTransaction();
        debug_log("Transaction started");
    }

    /* =========================
       BASIC CONTEXT
    ========================= */
    $business_id = $_SESSION['current_business_id'] ?? 1;
    $shop_id = $_SESSION['current_shop_id'] ?? 1;
    $seller_id = $_SESSION['user_id'];
    debug_log("Context loaded", [
        'business_id' => $business_id,
        'shop_id' => $shop_id,
        'seller_id' => $seller_id
    ]);

    /* =========================
       LOYALTY SETTINGS
    ========================= */
    $loyalty_settings_stmt = $pdo->prepare("
        SELECT points_per_amount, amount_per_point, 
               redeem_value_per_point, min_points_to_redeem, expiry_months, is_active
        FROM loyalty_settings 
        WHERE business_id = ?
    ");
    $loyalty_settings_stmt->execute([$business_id]);
    $loyalty_settings = $loyalty_settings_stmt->fetch();
    
    if (!$loyalty_settings) {
        $loyalty_settings = [
            'points_per_amount' => 0.01,
            'amount_per_point' => 100.00,
            'redeem_value_per_point' => 1.00,
            'min_points_to_redeem' => 50,
            'expiry_months' => null,
            'is_active' => 1
        ];
        debug_log("Using default loyalty settings", $loyalty_settings);
    } else {
        debug_log("Loyalty settings loaded", $loyalty_settings);
    }

    /* =========================
       GST TYPE FROM FRONTEND
    ========================= */
    $gst_type = $input['gst_type'] ?? 'gst';
    $force_non_gst = ($gst_type === 'non-gst');
    debug_log("GST handling", [
        'frontend_gst_type' => $gst_type,
        'force_non_gst' => $force_non_gst
    ]);

    /* =========================
       CUSTOMER ADDRESS
    ========================= */
    $customer_address = trim($input['customer_address'] ?? '');
    debug_log("Customer address", ['address' => $customer_address]);

    /* =========================
       GST SETTINGS
    ========================= */
    $gst_settings = ['is_gst_enabled' => 0, 'is_inclusive' => 1];

    if (!$force_non_gst) {
        $stmt = $pdo->prepare("
            SELECT is_gst_enabled, is_inclusive
            FROM gst_settings
            WHERE business_id = ? AND (shop_id = ? OR shop_id IS NULL)
            AND status = 'active'
            ORDER BY shop_id DESC
            LIMIT 1
        ");
        $stmt->execute([$business_id, $shop_id]);
        $gst = $stmt->fetch();
        if ($gst) {
            $gst_settings['is_gst_enabled'] = (int)$gst['is_gst_enabled'];
            $gst_settings['is_inclusive'] = (int)$gst['is_inclusive'];
        }
    }
    debug_log("GST settings loaded", $gst_settings);

    /* =========================
       DETERMINE GST STATUS
    ========================= */
    $has_hsn = false;
    foreach ($input['items'] as $i) {
        if (!empty($i['hsn_code'])) {
            $has_hsn = true;
            break;
        }
    }
    debug_log("HSN check", ['has_hsn' => $has_hsn]);

    $gst_status = $force_non_gst ? 0 : ($gst_settings['is_gst_enabled'] && $has_hsn ? 1 : 0);
    $invoice_type = $gst_status ? 'tax_invoice' : 'retail_bill';
    debug_log("Invoice type decided", [
        'gst_status' => $gst_status,
        'invoice_type' => $invoice_type
    ]);

    /* =========================
       CUSTOMER HANDLING
    ========================= */
    $customer_name = trim($input['customer_name'] ?? 'Walk-in Customer');
    $customer_phone = preg_replace('/[^0-9]/', '', $input['customer_phone'] ?? '');
    $customer_gstin = trim($input['customer_gstin'] ?? '');
    $customer_type = !empty($customer_gstin) ? 'wholesale' : 'retail';

    $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND business_id = ? LIMIT 1");
    $stmt->execute([$customer_phone, $business_id]);
    $cust = $stmt->fetch();
    
    if ($cust) {
        $customer_id = $cust['id'];
        if ($customer_address) {
            $pdo->prepare("UPDATE customers SET address = ? WHERE id = ?")
                ->execute([$customer_address, $customer_id]);
            debug_log("Updated existing customer address", ['customer_id' => $customer_id]);
        }
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO customers
            (business_id, customer_type, name, phone, gstin, address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$business_id, $customer_type, $customer_name, $customer_phone, $customer_gstin, $customer_address]);
        $customer_id = $pdo->lastInsertId();
        debug_log("Created new customer", ['customer_id' => $customer_id]);
    }

    /* =========================
       LOYALTY POINTS CHECK
    ========================= */
    $points_used = (int)($input['loyalty_points_used'] ?? 0);
    $points_discount = (float)($input['loyalty_points_discount'] ?? 0);
    
    // Check if customer exists in loyalty points
    $customer_points_stmt = $pdo->prepare("
        SELECT * FROM customer_points 
        WHERE customer_id = ? AND business_id = ?
    ");
    $customer_points_stmt->execute([$customer_id, $business_id]);
    $customer_points = $customer_points_stmt->fetch();
    
    if (!$customer_points) {
        // Create new customer points record
        $create_points_stmt = $pdo->prepare("
            INSERT INTO customer_points 
            (customer_id, business_id, total_points_earned, total_points_redeemed, available_points)
            VALUES (?, ?, 0.00, 0.00, 0.00)
        ");
        $create_points_stmt->execute([$customer_id, $business_id]);
        debug_log("Created new customer points record", ['customer_id' => $customer_id]);
        
        // Re-fetch to get the record
        $customer_points_stmt->execute([$customer_id, $business_id]);
        $customer_points = $customer_points_stmt->fetch();
    }
    
    // Verify customer has enough points if using points
    if ($points_used > 0) {
        if ($customer_points['available_points'] < $points_used) {
            throw new Exception("Insufficient loyalty points available. Available: " . $customer_points['available_points'] . ", Requested: " . $points_used);
        }
        
        // Check minimum points requirement
        if ($points_used < $loyalty_settings['min_points_to_redeem']) {
            throw new Exception("Minimum " . $loyalty_settings['min_points_to_redeem'] . " points required for redemption");
        }
        
        debug_log("Points usage verified", [
            'points_used' => $points_used,
            'points_discount' => $points_discount,
            'available_points' => $customer_points['available_points']
        ]);
    }

    /* =========================
       INVOICE NUMBER
    ========================= */
    $prefix = ($gst_settings['is_gst_enabled'] && !$force_non_gst) ? 'INV' : 'INVNG';
    $year_month = date('Ym');
    $full_prefix = $prefix . $year_month . '-';

    $stmt = $pdo->prepare("
        SELECT invoice_number
        FROM invoices
        WHERE business_id = ?
          AND invoice_number LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$business_id, $full_prefix . '%']);
    $last = $stmt->fetch();
    if ($last) {
        $last_num = (int)substr($last['invoice_number'], strlen($full_prefix));
        $seq = $last_num + 1;
    } else {
        $seq = 1;
    }
    $invoice_number = $full_prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    debug_log("Generated invoice number", ['invoice_number' => $invoice_number]);

    /* =========================
       PAYMENT & TOTALS
    ========================= */
    $subtotal = (float)($input['subtotal'] ?? 0);
    $overall_discount = (float)($input['overall_discount'] ?? 0);
    $total = (float)($input['total'] ?? 0);
    $cash_amount = (float)($input['cash_amount'] ?? 0);
    $upi_amount = (float)($input['upi_amount'] ?? 0);
    $bank_amount = (float)($input['bank_amount'] ?? 0);
    $cheque_amount = (float)($input['cheque_amount'] ?? 0);
    $total_paid = $cash_amount + $upi_amount + $bank_amount + $cheque_amount;
    $change_given = max(0, $total_paid - $total);
    $pending_amount = max(0, $total - $total_paid);

    $payment_status = 'pending';
    if ($pending_amount == 0) $payment_status = 'paid';
    elseif ($total_paid > 0) $payment_status = 'partial';

    $methods = [];
    if ($cash_amount > 0) $methods[] = 'cash';
    if ($upi_amount > 0) $methods[] = 'upi';
    if ($bank_amount > 0) $methods[] = 'bank';
    if ($cheque_amount > 0) $methods[] = 'cheque';
    $payment_method = count($methods) > 1 ? 'split' : (count($methods) === 1 ? $methods[0] : 'cash');

    debug_log("Payment summary", [
        'subtotal' => $subtotal,
        'overall_discount' => $overall_discount,
        'points_discount' => $points_discount,
        'total' => $total,
        'total_paid' => $total_paid,
        'change_given' => $change_given,
        'pending_amount' => $pending_amount,
        'payment_status' => $payment_status,
        'payment_method' => $payment_method
    ]);

    /* =========================
       REFERRAL
    ========================= */
    $referral_id = !empty($input['referral_id']) ? (int)$input['referral_id'] : null;
    $total_referral_commission = (float)($input['referral_commission_amount'] ?? 0);
    debug_log("Referral info", ['referral_id' => $referral_id, 'commission' => $total_referral_commission]);

    /* =========================
       INSERT INVOICE
    ========================= */
    $stmt = $pdo->prepare("
        INSERT INTO invoices (
            customer_id, invoice_number, invoice_type, customer_type, gst_status,
            subtotal, discount, discount_type, overall_discount, total,
            cash_received, change_given, pending_amount, paid_amount, payment_status,
            cash_amount, upi_amount, bank_amount, cheque_amount,
            cheque_number, upi_reference, bank_reference,
            payment_method, seller_id, shop_id, business_id,
            referral_id, referral_commission_amount,
            points_redeemed, points_discount_amount,
            created_at, gst_type
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            NOW(), ?
        )
    ");
    
    $stmt->execute([
        $customer_id, $invoice_number, $invoice_type, $customer_type, $gst_status,
        $subtotal, $input['discount'] ?? 0, $input['discount_type'] ?? 'percent', $overall_discount, $total,
        $total_paid, $change_given, $pending_amount, $total_paid, $payment_status,
        $cash_amount, $upi_amount, $bank_amount, $cheque_amount,
        $input['cheque_number'] ?? '',
        $input['upi_reference'] ?? '',
        $input['bank_reference'] ?? '',
        $payment_method, $seller_id, $shop_id, $business_id,
        $referral_id,
        $total_referral_commission,
        $points_used,
        $points_discount,
        $gst_type
    ]);
    
    $invoice_id = $pdo->lastInsertId();
    debug_log("Invoice created", [
        'invoice_id' => $invoice_id,
        'points_used' => $points_used,
        'points_discount' => $points_discount
    ]);

    /* =========================
       SAVE CUSTOMER ADDRESS (if provided)
    ========================= */
    if ($customer_address) {
        try {
            @$pdo->exec("
                CREATE TABLE IF NOT EXISTS customer_addresses (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    customer_id INT NOT NULL,
                    invoice_id INT NOT NULL,
                    address TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");
            $stmt_addr = $pdo->prepare("
                INSERT INTO customer_addresses (customer_id, invoice_id, address)
                VALUES (?, ?, ?)
            ");
            $stmt_addr->execute([$customer_id, $invoice_id, $customer_address]);
            debug_log("Customer address saved");
        } catch (Exception $e) {
            debug_log("Address save failed (non-critical)", ['error' => $e->getMessage()]);
        }
    }

    /* =========================
       PROCESS ITEMS + STOCK DEDUCTION
    ========================= */
    $total_taxable = $total_cgst = $total_sgst = $total_igst = 0;
    $item_index = 0;

    foreach ($input['items'] as $item) {
        $item_index++;
        debug_log("Processing item #$item_index", $item);

        $product_id = (int)$item['product_id'];
        $qty = (float)$item['quantity']; // Primary quantity
        $unit_price = (float)$item['unit_price'];
        $original_price = (float)($item['original_price'] ?? $unit_price);
        $discount_value = (float)($item['discount_value'] ?? 0);
        $discount_type = $item['discount_type'] ?? 'percent';
        $sale_type = ($item['price_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
        
        // Get unit information
        $unit = $item['unit'] ?? 'PCS';
        $secondary_quantity = $item['secondary_quantity'] ?? null;
        $secondary_unit = $item['secondary_unit'] ?? null;
        
        // Determine actual unit for storage
        $actual_unit = $unit;
        if ($secondary_quantity !== null && $secondary_unit !== null) {
            $actual_unit = $secondary_unit;
        }

        $line_total_before = $unit_price * $qty;
        $line_discount = $discount_type === 'percent'
            ? $line_total_before * ($discount_value / 100)
            : $discount_value;
        $line_total = max(0, $line_total_before - $line_discount);
        
        // Profit calculation
        if ($secondary_quantity !== null && $secondary_unit !== null) {
            $profit = $line_total - ($original_price * $qty);
        } else {
            $profit = $line_total - ($original_price * $qty);
        }

        // GST Calculation
        $hsn = $item['hsn_code'] ?? '';
        $cgst_rate = (float)($item['cgst_rate'] ?? 0);
        $sgst_rate = (float)($item['sgst_rate'] ?? 0);
        $igst_rate = (float)($item['igst_rate'] ?? 0);
        $taxable_value = $cgst_amount = $sgst_amount = $igst_amount = 0;

        if ($gst_status && $hsn && ($cgst_rate + $sgst_rate + $igst_rate) > 0) {
            if ($gst_settings['is_inclusive']) {
                $taxable_value = $line_total / (1 + (($cgst_rate + $sgst_rate + $igst_rate) / 100));
            } else {
                $taxable_value = $line_total;
            }
            $cgst_amount = round($taxable_value * ($cgst_rate / 100), 2);
            $sgst_amount = round($taxable_value * ($sgst_rate / 100), 2);
            $igst_amount = round($taxable_value * ($igst_rate / 100), 2);
        } else {
            $taxable_value = $line_total;
        }

        $total_taxable += $taxable_value;
        $total_cgst += $cgst_amount;
        $total_sgst += $sgst_amount;
        $total_igst += $igst_amount;

        // Insert item with unit information
        $stmt_item = $pdo->prepare("
            INSERT INTO invoice_items (
                invoice_id, product_id, sale_type, quantity,
                unit_price, original_price, total_price,
                discount_rate, discount_amount,
                hsn_code, cgst_rate, sgst_rate, igst_rate,
                cgst_amount, sgst_amount, igst_amount,
                total_with_gst, taxable_value, profit,
                gst_inclusive, referral_commission, unit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $quantity_for_db = $qty; // Primary quantity for stock
        
        $stmt_item->execute([
            $invoice_id, $product_id, $sale_type, $quantity_for_db,
            $unit_price, $original_price, $line_total,
            $discount_type === 'percent' ? $discount_value : 0,
            $discount_type === 'flat' ? $discount_value : 0,
            $hsn, $cgst_rate, $sgst_rate, $igst_rate,
            $cgst_amount, $sgst_amount, $igst_amount,
            $line_total, $taxable_value, $profit,
            $gst_status ? 1 : 0,
            $item['referral_commission'] ?? 0,
            $actual_unit
        ]);
        $item_id = $pdo->lastInsertId();
        debug_log("Item #$item_index saved", [
            'item_id' => $item_id, 
            'unit' => $actual_unit,
            'quantity_stored' => $quantity_for_db,
            'secondary_quantity' => $secondary_quantity,
            'profit' => $profit
        ]);

        // Deduct stock (only deduct primary quantity from shop stock)
        $stock_stmt = $pdo->prepare("
            UPDATE product_stocks
            SET quantity = GREATEST(0, quantity - ?), last_updated = NOW()
            WHERE product_id = ? AND shop_id = ? AND business_id = ?
        ");
        $stock_stmt->execute([$qty, $product_id, $shop_id, $business_id]);
        $rows_affected = $stock_stmt->rowCount();
        debug_log("Stock deduction for product $product_id", [
            'qty_deducted' => $qty,
            'rows_affected' => $rows_affected
        ]);
    }

    /* =========================
       UPDATE REFERRAL & INVOICE
    ========================= */
    if ($referral_id && $total_referral_commission > 0) {
        $pdo->prepare("
            UPDATE referral_person
            SET debit_amount = debit_amount + ?,
                balance_due = balance_due + ?,
                total_sales_amount = total_sales_amount + ?,
                updated_at = NOW()
            WHERE id = ? AND business_id = ?
        ")->execute([
            $total_referral_commission,
            $total_referral_commission,
            $total,
            $referral_id,
            $business_id
        ]);
        debug_log("Referral updated");

        $pdo->prepare("
            UPDATE invoices SET referral_commission_amount = ? WHERE id = ?
        ")->execute([$total_referral_commission, $invoice_id]);
    }

    /* =========================
       LOYALTY POINTS HANDLING
    ========================= */
    // 1. Deduct used points (if any)
    if ($points_used > 0) {
        $deduct_points_stmt = $pdo->prepare("
            UPDATE customer_points 
            SET total_points_redeemed = total_points_redeemed + ?,
                available_points = available_points - ?,
                last_updated = NOW()
            WHERE customer_id = ? AND business_id = ?
        ");
        $deduct_points_stmt->execute([
            $points_used,
            $points_used,
            $customer_id,
            $business_id
        ]);
        debug_log("Points deducted", ['points_used' => $points_used]);
        
        // Record points redemption
        try {
            @$pdo->exec("
                CREATE TABLE IF NOT EXISTS points_redemptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    customer_id INT NOT NULL,
                    business_id INT NOT NULL,
                    invoice_id INT NOT NULL,
                    points_used INT NOT NULL,
                    discount_amount DECIMAL(12,2) NOT NULL,
                    redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");
            
            $redemption_stmt = $pdo->prepare("
                INSERT INTO points_redemptions 
                (customer_id, business_id, invoice_id, points_used, discount_amount)
                VALUES (?, ?, ?, ?, ?)
            ");
            $redemption_stmt->execute([
                $customer_id,
                $business_id,
                $invoice_id,
                $points_used,
                $points_discount
            ]);
            debug_log("Points redemption recorded");
        } catch (Exception $e) {
            debug_log("Points redemption record failed", ['error' => $e->getMessage()]);
        }
    }
    
    // 2. Calculate and add earned points (from purchase amount)
    $points_earned = 0;
    $points_basis = $subtotal - $points_discount;
    
    if ($loyalty_settings['is_active'] && $loyalty_settings['points_per_amount'] > 0) {
        $points_earned = $points_basis * $loyalty_settings['points_per_amount'];
        $points_earned = round($points_earned, 2);
        
        if ($points_earned > 0) {
            $earn_points_stmt = $pdo->prepare("
                UPDATE customer_points 
                SET total_points_earned = total_points_earned + ?,
                    available_points = available_points + ?,
                    last_updated = NOW()
                WHERE customer_id = ? AND business_id = ?
            ");
            $earn_points_stmt->execute([
                $points_earned,
                $points_earned,
                $customer_id,
                $business_id
            ]);
            debug_log("Points earned", ['points_earned' => $points_earned]);
            
            // Record points earning
            try {
                @$pdo->exec("
                    CREATE TABLE IF NOT EXISTS points_earnings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        customer_id INT NOT NULL,
                        business_id INT NOT NULL,
                        invoice_id INT NOT NULL,
                        points_earned DECIMAL(12,2) NOT NULL,
                        purchase_amount DECIMAL(12,2) NOT NULL,
                        earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ");
                
                $earning_stmt = $pdo->prepare("
                    INSERT INTO points_earnings 
                    (customer_id, business_id, invoice_id, points_earned, purchase_amount)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $earning_stmt->execute([
                    $customer_id,
                    $business_id,
                    $invoice_id,
                    $points_earned,
                    $points_basis
                ]);
                debug_log("Points earning recorded");
            } catch (Exception $e) {
                debug_log("Points earning record failed", ['error' => $e->getMessage()]);
            }
        }
    }

    /* =========================
       GST SUMMARY
    ========================= */
    if ($gst_status) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO invoice_gst_summary
                (invoice_id, total_taxable_value, total_cgst, total_sgst, total_igst, total_gst)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_taxable_value = VALUES(total_taxable_value),
                    total_cgst = VALUES(total_cgst),
                    total_sgst = VALUES(total_sgst),
                    total_igst = VALUES(total_igst),
                    total_gst = VALUES(total_gst)
            ");
            $stmt->execute([
                $invoice_id,
                $total_taxable,
                $total_cgst,
                $total_sgst,
                $total_igst,
                $total_cgst + $total_sgst + $total_igst
            ]);
            debug_log("GST summary saved");
        } catch (Exception $e) {
            debug_log("GST summary save failed", ['error' => $e->getMessage()]);
        }
    }

    // Commit if transaction was started
    if ($use_transaction) {
        $pdo->commit();
        debug_log("Transaction committed successfully");
    }

    // SUCCESS - Send response and STOP EXECUTION
    debug_log("=== INVOICE SAVE COMPLETED SUCCESSFULLY ===");
    
    $response = [
        'success' => true,
        'invoice_id' => $invoice_id,
        'invoice_number' => $invoice_number,
        'gst_status' => $gst_status,
        'gst_type' => $gst_type,
        'invoice_type' => $invoice_type,
        'referral_commission' => $total_referral_commission,
        'payment_status' => $payment_status,
        'pending_amount' => $pending_amount,
        'total_paid' => $total_paid,
        'change_given' => $change_given,
        'force_non_gst' => $force_non_gst,
        'points_used' => $points_used,
        'points_discount' => $points_discount,
        'points_earned' => $points_earned,
        'points_basis' => $points_basis,
        'redirect_url' => 'print_invoice.php?id=' . $invoice_id
    ];
    
    // Clear output buffer and send response
    ob_end_clean();
    echo json_encode($response);
    
    // Force exit to prevent any further execution
    exit(0); // Use exit(0) for successful termination

} catch (Exception $e) {
    // Rollback if transaction was started
    if ($use_transaction && isset($pdo) && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
            debug_log("Transaction rolled back due to error");
        } catch (Exception $rollback_e) {
            debug_log("Rollback failed", ['error' => $rollback_e->getMessage()]);
        }
    }
    
    debug_log("CRITICAL ERROR", [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    // Clear output buffer and send error response
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save invoice: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
    exit(1); // Use exit(1) for error termination
}
?>