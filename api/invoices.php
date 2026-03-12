<?php
// api/invoices.php
require_once '../includes/auth.php';
require_once '../config/database.php';

checkAuth();
$business_id = getBusinessId();
$shop_id = getShopId();
$user_id = getUserId();

// === DEBUG LOG FUNCTION ===
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/../logs/invoice_api_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message";
    if ($data !== null) {
        $log_entry .= " | " . json_encode($data, JSON_PRETTY_PRINT);
    }
    $log_entry .= "\n";
    
    // Ensure directory exists
    if (!file_exists(dirname($log_file))) {
        mkdir(dirname($log_file), 0777, true);
    }
    
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

header('Content-Type: application/json');

try {
    // Get action from GET or POST
    $action = $_GET['action'] ?? '';
    
    // If no action in GET, check POST data
    if (empty($action) && !empty($_POST['action'])) {
        $action = $_POST['action'];
    }
    
    // If still empty and we have raw input, check JSON
    if (empty($action)) {
        $raw_input = file_get_contents('php://input');
        if (!empty($raw_input)) {
            $input_data = json_decode($raw_input, true);
            if ($input_data && isset($input_data['action'])) {
                $action = $input_data['action'];
            }
        }
    }
    
    switch ($action) {
        case 'get_next_invoice_number':
            getNextInvoiceNumber();
            break;
        case 'save':
            saveInvoice('save');
            break;
        case 'save_for_print':
            saveInvoice('print');
            break;
        case 'check_credit_limit':
            checkCustomerCreditLimit();
            break;
        case 'list':
            listInvoices();
            break;
        case 'get_details':
            getInvoiceDetails();
            break;
        case 'delete':
            deleteInvoice();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
    }
} catch (Exception $e) {
    error_log("Invoices API Error: " . $e->getMessage());
    debug_log("Invoices API Exception", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    echo json_encode(['success' => false, 'message' => 'Server error occurred: ' . $e->getMessage()]);
}

function getNextInvoiceNumber() {
    global $pdo, $business_id, $shop_id;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $gst_type = $data['invoice_type'] ?? 'gst';
    $prefix = ($gst_type === 'gst') ? 'INV' : 'INVNG';
    $year_month = date('Ym');
    $full_prefix = $prefix . $year_month . '-';
    
    debug_log("Generating next invoice number", [
        'business_id' => $business_id,
        'shop_id' => $shop_id,
        'gst_type' => $gst_type,
        'prefix' => $prefix,
        'year_month' => $year_month
    ]);
    
    // Check for existing invoice number with exact pattern
    $sql = "SELECT invoice_number
            FROM invoices
            WHERE business_id = ?
              AND shop_id = ?
              AND invoice_number LIKE ?
            ORDER BY CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED) DESC
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$business_id, $shop_id, $full_prefix . '%']);
    $last = $stmt->fetch();
    
    $seq = 1;
    if ($last && isset($last['invoice_number'])) {
        $last_num = (int)substr($last['invoice_number'], strlen($full_prefix));
        $seq = $last_num + 1;
    }
    
    // Keep trying until we find a unique number
    $max_attempts = 10;
    $attempt = 0;
    $invoice_number = '';
    
    while ($attempt < $max_attempts) {
        $invoice_number = $full_prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
        
        // Check if this invoice number already exists
        $check_sql = "SELECT id FROM invoices WHERE invoice_number = ? AND business_id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$invoice_number, $business_id]);
        $exists = $check_stmt->fetch();
        
        if (!$exists) {
            break; // Found unique number
        }
        
        // If exists, try next number
        $seq++;
        $attempt++;
    }
    
    if ($attempt >= $max_attempts) {
        // Use timestamp as fallback
        $timestamp = time();
        $invoice_number = $full_prefix . $timestamp;
    }
    
    debug_log("Generated invoice number", [
        'invoice_number' => $invoice_number,
        'attempts' => $attempt,
        'final_seq' => $seq
    ]);
    
    echo json_encode([
        'success' => true,
        'invoice_number' => $invoice_number,
        'prefix' => $prefix,
        'year_month' => $year_month,
        'next_number' => str_pad($seq, 4, '0', STR_PAD_LEFT)
    ]);
}

function saveInvoice($action = 'save') {
    global $pdo, $business_id, $shop_id, $user_id;
   
    // Start logging
    debug_log("=== NEW INVOICE SAVE REQUEST STARTED ===");
    debug_log("Action: " . $action);
   
    // Get raw input
    $raw_input = file_get_contents('php://input');
    if (empty($raw_input)) {
        echo json_encode(['success' => false, 'message' => 'Empty request body']);
        return;
    }
   
    debug_log("Raw input received", ['raw' => substr($raw_input, 0, 2000)]);
   
    $data = json_decode($raw_input, true);
    if (!$data) {
        debug_log("Invalid JSON received");
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }
    
    // Determine if it's for print
    $is_print = ($action === 'print');

    if (!isset($data['items']) || !is_array($data['items'])) {
        debug_log("Missing items array");
        echo json_encode(['success' => false, 'message' => 'Missing items array']);
        return;
    }
   
    // Validate required fields
    $required_fields = ['customer_name', 'invoice_number', 'grand_total'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field])) {
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            return;
        }
    }
   
    debug_log("Input parsed successfully", [
        'item_count' => count($data['items']),
        'gst_type' => $data['invoice_type'] ?? 'not set',
        'customer_name' => $data['customer_name'] ?? 'not set',
        'points_used' => $data['points_used'] ?? 0,
        'points_discount' => $data['points_discount'] ?? 0,
        'invoice_number' => $data['invoice_number']
    ]);
   
    // Start transaction
    $pdo->beginTransaction();
    debug_log("Transaction started");
   
    try {
        /* =========================
           BASIC CONTEXT
        ========================= */
        debug_log("Context loaded", [
            'business_id' => $business_id,
            'shop_id' => $shop_id,
            'seller_id' => $user_id
        ]);
       
        /* =========================
           LOYALTY SETTINGS
        ========================= */
        $loyalty_settings = [
            'points_per_amount' => 0.01,
            'amount_per_point' => 100.00,
            'redeem_value_per_point' => 1.00,
            'min_points_to_redeem' => 50,
            'expiry_months' => null,
            'is_active' => 1
        ];
       
        try {
            $loyalty_settings_stmt = $pdo->prepare("
                SELECT points_per_amount, amount_per_point,
                       redeem_value_per_point, min_points_to_redeem, expiry_months, is_active
                FROM loyalty_settings
                WHERE business_id = ?
            ");
            $loyalty_settings_stmt->execute([$business_id]);
            $db_loyalty_settings = $loyalty_settings_stmt->fetch();
            if ($db_loyalty_settings) {
                $loyalty_settings = $db_loyalty_settings;
            }
        } catch (Exception $e) {
            debug_log("Failed to load loyalty settings, using defaults", ['error' => $e->getMessage()]);
        }
       
        debug_log("Loyalty settings", $loyalty_settings);
       
        /* =========================
           GST TYPE FROM FRONTEND
        ========================= */
        $gst_type = $data['invoice_type'] ?? 'gst';
        $force_non_gst = ($gst_type === 'non-gst');
        debug_log("GST handling", [
            'frontend_gst_type' => $gst_type,
            'force_non_gst' => $force_non_gst
        ]);
       
        /* =========================
           CUSTOMER ADDRESS
        ========================= */
        $customer_address = trim($data['customer_address'] ?? '');
        debug_log("Customer address", ['address' => $customer_address]);
       
        /* =========================
           GST SETTINGS
        ========================= */
        $gst_settings = ['is_gst_enabled' => 0, 'is_inclusive' => 1];
       
        if (!$force_non_gst) {
            try {
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
            } catch (Exception $e) {
                debug_log("Failed to load GST settings", ['error' => $e->getMessage()]);
            }
        }
        debug_log("GST settings loaded", $gst_settings);
       
        /* =========================
           DETERMINE GST STATUS
        ========================= */
        $has_hsn = false;
        foreach ($data['items'] as $i) {
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
        $customer_name = trim($data['customer_name'] ?? 'Walk-in Customer');
        $customer_phone = preg_replace('/[^0-9]/', '', $data['customer_phone'] ?? '');
        $customer_gstin = trim($data['customer_gstin'] ?? '');
        $customer_type = !empty($customer_gstin) ? 'wholesale' : 'retail';
       
        // Check customer credit limit if customer exists
        $customer_id = $data['customer_id'] ?? null;
       
        if ($customer_id) {
            // Check existing customer
            $customerCheckSql = "SELECT id, name, credit_limit, outstanding_amount
                                 FROM customers
                                 WHERE id = ? AND business_id = ?";
            $customerStmt = $pdo->prepare($customerCheckSql);
            $customerStmt->execute([$customer_id, $business_id]);
            $customer = $customerStmt->fetch();
           
            if ($customer) {
                // Update customer information if provided
                if ($customer_address) {
                    $updateStmt = $pdo->prepare("UPDATE customers SET address = ? WHERE id = ?");
                    $updateStmt->execute([$customer_address, $customer_id]);
                    debug_log("Updated existing customer address", ['customer_id' => $customer_id]);
                }
               
                // Check credit limit
                $creditLimit = $customer['credit_limit'] ?? 0;
                $outstanding = $customer['outstanding_amount'] ?? 0;
                $pending_amount = $data['pending_amount'] ?? 0;
                $newOutstanding = $outstanding + $pending_amount;
               
                if ($creditLimit > 0 && $newOutstanding > $creditLimit) {
                    throw new Exception("Customer credit limit exceeded! Limit: ₹{$creditLimit}, New Outstanding: ₹{$newOutstanding}");
                }
            } else {
                $customer_id = null; // Reset if not found
            }
        }
       
        if (!$customer_id) {
            // Check by phone or create new
            if (!empty($customer_phone)) {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND business_id = ? LIMIT 1");
                $stmt->execute([$customer_phone, $business_id]);
                $cust = $stmt->fetch();
               
                if ($cust) {
                    $customer_id = $cust['id'];
                    if ($customer_address) {
                        $updateStmt = $pdo->prepare("UPDATE customers SET address = ? WHERE id = ?");
                        $updateStmt->execute([$customer_address, $customer_id]);
                        debug_log("Updated existing customer address", ['customer_id' => $customer_id]);
                    }
                }
            }
           
            if (!$customer_id) {
                // Create new customer
                $stmt = $pdo->prepare("
                    INSERT INTO customers
                    (business_id, customer_type, name, phone, gstin, address, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $business_id,
                    $customer_type,
                    $customer_name,
                    $customer_phone ?: NULL,
                    $customer_gstin ?: NULL,
                    $customer_address ?: NULL
                ]);
                $customer_id = $pdo->lastInsertId();
                debug_log("Created new customer", ['customer_id' => $customer_id]);
            }
        }
       
        /* =========================
           LOYALTY POINTS CHECK
        ========================= */
        $points_used = (int)($data['points_used'] ?? 0);
        $points_discount = (float)($data['points_discount'] ?? 0);
       
        // Check if customer exists in loyalty points
        $customer_points = null;
        try {
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
        } catch (Exception $e) {
            debug_log("Customer points check failed", ['error' => $e->getMessage()]);
            // Continue without points
        }
       
        // Verify customer has enough points if using points
        if ($points_used > 0 && $customer_points) {
            $available_points = (float)($customer_points['available_points'] ?? 0);
            if ($available_points < $points_used) {
                throw new Exception("Insufficient loyalty points available. Available: " . $available_points . ", Requested: " . $points_used);
            }
           
            // Check minimum points requirement
            if ($points_used < $loyalty_settings['min_points_to_redeem']) {
                throw new Exception("Minimum " . $loyalty_settings['min_points_to_redeem'] . " points required for redemption");
            }
           
            debug_log("Points usage verified", [
                'points_used' => $points_used,
                'points_discount' => $points_discount,
                'available_points' => $available_points
            ]);
        }
       
        /* =========================
           INVOICE NUMBER (USING EXISTING LOGIC)
        ========================= */
        $invoice_number = $data['invoice_number'] ?? '';
        if (empty($invoice_number)) {
            throw new Exception("Invoice number is required");
        }
        
        // Check for duplicate invoice number before proceeding
        $check_duplicate_sql = "SELECT id FROM invoices 
                               WHERE invoice_number = ? 
                               AND business_id = ?";
        $check_stmt = $pdo->prepare($check_duplicate_sql);
        $check_stmt->execute([$invoice_number, $business_id]);
        $existing_invoice = $check_stmt->fetch();
        
        if ($existing_invoice) {
            debug_log("Duplicate invoice number detected", [
                'invoice_number' => $invoice_number,
                'existing_id' => $existing_invoice['id']
            ]);
            
            // Generate a new unique invoice number
            $gst_type = $data['invoice_type'] ?? 'gst';
            $prefix = ($gst_type === 'gst') ? 'INV' : 'INVNG';
            $year_month = date('Ym');
            $full_prefix = $prefix . $year_month . '-';
            
            // Find the next available number
            $sql = "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) as max_num
                    FROM invoices
                    WHERE business_id = ?
                      AND shop_id = ?
                      AND invoice_number LIKE ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$business_id, $shop_id, $full_prefix . '%']);
            $result = $stmt->fetch();
            
            $seq = 1;
            if ($result && $result['max_num']) {
                $seq = $result['max_num'] + 1;
            }
            
            // Generate new number
            $invoice_number = $full_prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
            
            debug_log("Generated new invoice number", [
                'old_number' => $data['invoice_number'],
                'new_number' => $invoice_number
            ]);
        }
        
        debug_log("Using invoice number", ['invoice_number' => $invoice_number]);
       
        /* =========================
   PAYMENT & TOTALS
========================= */
$subtotal = (float)($data['subtotal'] ?? 0);
$overall_discount = (float)($data['overall_discount'] ?? 0);
$total = (float)($data['grand_total'] ?? 0);

// Get payment details
$payment_details = $data['payment_details'] ?? [];
$cash_amount = (float)($payment_details['cash'] ?? 0);
$upi_amount = (float)($payment_details['upi'] ?? 0);
$bank_amount = (float)($payment_details['bank'] ?? 0);
$cheque_amount = (float)($payment_details['cheque'] ?? 0);
$credit_amount = (float)($payment_details['credit'] ?? 0);
$total_paid = $cash_amount + $upi_amount + $bank_amount + $cheque_amount + $credit_amount;
$change_given = max(0, $total_paid - $total);

// Calculate pending amount - use credit amount if credit payment exists
if ($credit_amount > 0) {
    $pending_amount = $credit_amount; // Insert credit amount as pending amount
} else {
    $pending_amount = max(0, $total - $total_paid); // Regular calculation when no credit
}

$payment_status = 'pending';
if ($pending_amount == 0) $payment_status = 'paid';
elseif ($total_paid > 0) $payment_status = 'partial';

$methods = [];
if ($cash_amount > 0) $methods[] = 'cash';
if ($upi_amount > 0) $methods[] = 'upi';
if ($bank_amount > 0) $methods[] = 'bank';
if ($cheque_amount > 0) $methods[] = 'cheque';
if ($credit_amount > 0) $methods[] = 'credit';
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
$referral_id = !empty($data['referral_id']) ? (int)$data['referral_id'] : null;
$total_referral_commission = (float)($data['referral_commission'] ?? 0);
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

$discount = $data['discount'] ?? 0;
$discount_type = $data['discount_type'] ?? 'percent';

$stmt->execute([
    $customer_id,
    $invoice_number,
    $invoice_type,
    $customer_type,
    $gst_status,
    $subtotal,
    $discount,
    $discount_type,
    $overall_discount,
    $total,
    $total_paid,
    $change_given,
    $pending_amount, // Now contains credit amount when credit payment is used
    $total_paid,
    $payment_status,
    $cash_amount,
    $upi_amount,
    $bank_amount,
    $cheque_amount,
    $payment_details['cheque_number'] ?? '',
    $payment_details['upi_reference'] ?? '',
    $payment_details['bank_reference'] ?? '',
    $payment_method,
    $user_id,
    $shop_id,
    $business_id,
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
                // Check if table exists, create if not
                $table_check = $pdo->query("SHOW TABLES LIKE 'customer_addresses'")->fetch();
                if (!$table_check) {
                    $pdo->exec("
                        CREATE TABLE customer_addresses (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            customer_id INT NOT NULL,
                            invoice_id INT NOT NULL,
                            address TEXT NOT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            INDEX idx_customer (customer_id),
                            INDEX idx_invoice (invoice_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
               
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
       
        foreach ($data['items'] as $item) {
            $item_index++;
            debug_log("Processing item #$item_index", $item);
           
            $product_id = (int)($item['product_id'] ?? 0);
            if ($product_id <= 0) {
                throw new Exception("Invalid product ID in item #$item_index");
            }
           
            $qty = (float)($item['quantity'] ?? 0);
            if ($qty <= 0) {
                throw new Exception("Invalid quantity in item #$item_index");
            }
           
            $unit_price = (float)($item['price'] ?? 0);
            $discount_value = (float)($item['discount_value'] ?? 0);
            $discount_type = $item['discount_type'] ?? 'percentage';
            $price_type = $item['price_type'] ?? 'retail';
            $sale_type = ($price_type === 'wholesale') ? 'wholesale' : 'retail';
           
            // Get unit information
            $unit = $item['unit'] ?? 'PCS';
            $is_secondary = (bool)($item['is_secondary_unit'] ?? false);
            $sec_conversion = (float)($item['sec_unit_conversion'] ?? 1);
           
            // Determine actual unit for storage
            $actual_unit = $unit;
           
            // Calculate line total
            $line_total_before = $unit_price * $qty;
            $line_discount = $discount_type === 'percentage'
                ? $line_total_before * ($discount_value / 100)
                : $discount_value;
            $line_total = max(0, $line_total_before - $line_discount);
           
            // ==============================================
            // FIXED: GET PRODUCT INFORMATION WITH BATCH INFO
            // ==============================================
            $product_info_stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.product_name,
                    p.unit_of_measure,
                    p.secondary_unit,
                    p.sec_unit_conversion,
                    p.stock_price,
                    p.mrp,
                    p.discount_type,
                    p.discount_value,
                    ps.quantity,
                    ps.total_secondary_units,
                    ps.old_qty,
                    ps.use_batch_tracking,
                    ps.batch_id
                FROM products p
                LEFT JOIN product_stocks ps ON p.id = ps.product_id
                    AND ps.shop_id = ? 
                    AND ps.business_id = ?
                WHERE p.id = ?
            ");
           
            $product_info_stmt->execute([$shop_id, $business_id, $product_id]);
            $product_info = $product_info_stmt->fetch();
           
            if (!$product_info) {
                throw new Exception("Product not found in inventory: ID $product_id");
            }
           
            // Extract product information
            $product_name = $product_info['product_name'] ?? 'Unknown Product';
            $unit_of_measure = $product_info['unit_of_measure'] ?? 'pcs';
            $secondary_unit = $product_info['secondary_unit'] ?? null;
            $sec_unit_conversion = (float)($product_info['sec_unit_conversion'] ?? 1);
            $current_stock = (float)($product_info['quantity'] ?? 0);
            $current_total_sec_units = (float)($product_info['total_secondary_units'] ?? 0);
            $old_qty = (float)($product_info['old_qty'] ?? 0);
            $use_batch_tracking = (bool)($product_info['use_batch_tracking'] ?? false);
            $batch_id = $product_info['batch_id'] ?? null;
           
            debug_log("Product stock info", [
                'product_name' => $product_name,
                'unit_of_measure' => $unit_of_measure,
                'secondary_unit' => $secondary_unit,
                'sec_unit_conversion' => $sec_unit_conversion,
                'current_stock' => $current_stock,
                'current_total_sec_units' => $current_total_sec_units,
                'old_qty' => $old_qty,
                'use_batch_tracking' => $use_batch_tracking,
                'batch_id' => $batch_id
            ]);
           
            // ==============================================
            // FIXED: QUANTITY CALCULATION BASED ON UNIT TYPE
            // ==============================================
            if ($is_secondary && $sec_unit_conversion > 0) {
                // Convert secondary unit quantity to primary units
                // Always use database conversion factor
                $quantity_in_primary_units = $qty / $sec_unit_conversion;
                $quantity_in_sec_units = $qty;
               
                debug_log("Secondary unit calculation", [
                    'qty_requested' => $qty,
                    'unit_requested' => $unit,
                    'db_conversion' => $sec_unit_conversion,
                    'frontend_conversion' => $sec_conversion,
                    'quantity_in_primary' => $quantity_in_primary_units,
                    'quantity_in_secondary' => $quantity_in_sec_units
                ]);
            } else {
                // Primary unit calculation
                $quantity_in_primary_units = $qty;
                $quantity_in_sec_units = $qty * $sec_unit_conversion;
               
                debug_log("Primary unit calculation", [
                    'qty_requested' => $qty,
                    'unit_requested' => $unit,
                    'quantity_in_primary' => $quantity_in_primary_units,
                    'quantity_in_secondary' => $quantity_in_sec_units
                ]);
            }
           
            // ==============================================
            // FIXED: STOCK AVAILABILITY CHECK
            // ==============================================
            // Check against total available stock (old_qty + current stock)
            $total_available_primary_units = $current_stock;
            $total_available_secondary_units = $current_total_sec_units;
           
            debug_log("Stock availability check", [
                'quantity_to_check' => $quantity_in_primary_units,
                'total_available_primary' => $total_available_primary_units,
                'total_available_secondary' => $total_available_secondary_units,
                'old_qty' => $old_qty,
                'current_stock' => $current_stock
            ]);
           
            if ($quantity_in_primary_units > $total_available_primary_units) {
                $available_secondary = $total_available_primary_units * $sec_unit_conversion;
                $required_secondary = $quantity_in_primary_units * $sec_unit_conversion;
               
                throw new Exception("Insufficient stock for $product_name. " .
                    "Available: " . number_format($total_available_primary_units, 4) . " $unit_of_measure ($available_secondary $secondary_unit), " .
                    "Required: " . number_format($quantity_in_primary_units, 4) . " $unit_of_measure ($required_secondary $secondary_unit)");
            }
           
            // Calculate profit using appropriate price
            $original_price = (float)($item['base_price'] ?? $item['stock_price'] ?? $unit_price);
            $profit = $line_total - ($original_price * $quantity_in_primary_units);
           
            // GST Calculation
            $hsn = $item['hsn_code'] ?? '';
            $cgst_rate = (float)($item['cgst_rate'] ?? 0);
            $sgst_rate = (float)($item['sgst_rate'] ?? 0);
            $igst_rate = (float)($item['igst_rate'] ?? 0);
            $taxable_value = $cgst_amount = $sgst_amount = $igst_amount = 0;
           
            if ($gst_status && !empty($hsn) && ($cgst_rate + $sgst_rate + $igst_rate) > 0) {
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
           
            $stmt_item->execute([
                $invoice_id,
                $product_id,
                $sale_type,
                $qty, // Store the actual quantity sold
                $unit_price,
                $original_price,
                $line_total,
                $discount_type === 'percentage' ? $discount_value : 0,
                $discount_type === 'fixed' ? $discount_value : 0,
                $hsn,
                $cgst_rate,
                $sgst_rate,
                $igst_rate,
                $cgst_amount,
                $sgst_amount,
                $igst_amount,
                $line_total,
                $taxable_value,
                $profit,
                $gst_status ? 1 : 0,
                $item['referral_commission'] ?? 0,
                $actual_unit
            ]);
           
            $item_id = $pdo->lastInsertId();
            debug_log("Item #$item_index saved", [
                'item_id' => $item_id,
                'unit' => $actual_unit,
                'quantity_sold' => $qty,
                'quantity_in_primary' => $quantity_in_primary_units,
                'quantity_in_secondary' => $quantity_in_sec_units,
                'profit' => $profit
            ]);
           
            // ==============================================
            // FIXED: BATCH TRACKING LOGIC
            // ==============================================
// ==============================================
// FIXED: BATCH TRACKING LOGIC WITH ITERATIVE DEDUCTION
// ==============================================
if ($use_batch_tracking && $batch_id) {
    debug_log("Using batch tracking with iterative deduction", [
        'batch_id' => $batch_id,
        'old_qty' => $old_qty,
        'current_stock' => $current_stock,
        'quantity_to_deduct' => $quantity_in_primary_units
    ]);
    
    // ==============================================
    // STEP 1: GET BATCH INFORMATION
    // ==============================================
    $batch_info_stmt = $pdo->prepare("
        SELECT 
            purchase_price,
            old_mrp,
            new_mrp,
            retail_price,
            wholesale_price,
            old_retail_price,
            old_wholesale_price,
            old_purchase_price,
            old_selling_price,
            quantity_received,
            is_increase,
            is_decrease
        FROM purchase_batches
        WHERE id = ? AND product_id = ? AND business_id = ?
    ");
    $batch_info_stmt->execute([$batch_id, $product_id, $business_id]);
    $batch_info = $batch_info_stmt->fetch();

    if (!$batch_info) {
        throw new Exception("Batch not found for product: $product_name, batch: $batch_id");
    }

    $purchase_price = (float)$batch_info['purchase_price'];
    $old_purchase_price = (float)$batch_info['old_purchase_price'];
    $old_mrp = (float)$batch_info['old_mrp'];
    $new_mrp = (float)$batch_info['new_mrp'];
    $retail_price = (float)$batch_info['retail_price'];
    $wholesale_price = (float)$batch_info['wholesale_price'];
    $old_retail_price = (float)$batch_info['old_retail_price'];
    $old_wholesale_price = (float)$batch_info['old_wholesale_price'];
    $old_selling_price = (float)$batch_info['old_selling_price'];
    $quantity_received = (float)$batch_info['quantity_received'];
    $batch_is_increase = (int)$batch_info['is_increase'];
    $batch_is_decrease = (int)$batch_info['is_decrease'];

    debug_log("Batch info", [
        'quantity_received' => $quantity_received,
        'purchase_price' => $purchase_price,
        'old_purchase_price' => $old_purchase_price,
        'old_mrp' => $old_mrp,
        'new_mrp' => $new_mrp,
        'retail_price' => $retail_price,
        'wholesale_price' => $wholesale_price,
        'old_retail_price' => $old_retail_price,
        'old_wholesale_price' => $old_wholesale_price,
        'old_selling_price' => $old_selling_price,
        'current_stock' => $current_stock,
        'old_qty' => $old_qty
    ]);

    // ==============================================
    // STEP 2: ITERATIVE DEDUCTION - ONE BY ONE
    // ==============================================
    $deducted_count = 0;
    $price_updated = false;
    $old_qty_before_deduction = $old_qty;
    $quantity_before_deduction = $current_stock;
    
    // Get product selling price based on sale type
    $current_selling_price = ($sale_type === 'wholesale') ? $wholesale_price : $retail_price;
    
    // Store initial values for profit/loss calculation
    $initial_old_qty = $old_qty;
    $initial_quantity = $current_stock;
    
    // Deduct one unit at a time until we've deducted all required quantity
    for ($i = 0; $i < $quantity_in_primary_units; $i++) {
        $deducted_count++;
        
        debug_log("Iterative deduction #$deducted_count of $quantity_in_primary_units", [
            'current_old_qty' => $old_qty,
            'current_quantity' => $current_stock
        ]);
        
        // ==============================================
        // STEP 2a: GET CURRENT STOCK VALUES BEFORE DEDUCTION
        // ==============================================
        $current_stock_stmt = $pdo->prepare("
            SELECT old_qty, quantity, total_secondary_units
            FROM product_stocks
            WHERE product_id = ? AND shop_id = ? AND business_id = ?
        ");
        $current_stock_stmt->execute([$product_id, $shop_id, $business_id]);
        $current_values = $current_stock_stmt->fetch();
        
        if (!$current_values) {
            throw new Exception("Stock record not found for product: $product_name");
        }
        
        $current_old_qty = (float)$current_values['old_qty'];
        $current_quantity = (float)$current_values['quantity'];
        $current_sec_units = (float)$current_values['total_secondary_units'];
        
        // Calculate secondary unit deduction for this single unit
        $single_unit_sec_deduction = $sec_unit_conversion > 0 ? $sec_unit_conversion : 0;
        
        // ==============================================
        // STEP 2b: DEDUCT ONE UNIT FROM ALL THREE VALUES
        // ==============================================
        $deduct_single_stmt = $pdo->prepare("
            UPDATE product_stocks
            SET 
                old_qty = GREATEST(0, old_qty - 1),
                quantity = GREATEST(0, quantity - 1),
                total_secondary_units = GREATEST(0, total_secondary_units - ?),
                last_updated = NOW()
            WHERE product_id = ? 
              AND shop_id = ? 
              AND business_id = ?
        ");
        
        $deduct_single_stmt->execute([
            $single_unit_sec_deduction,
            $product_id,
            $shop_id,
            $business_id
        ]);
        
        debug_log("Deducted one unit", [
            'old_qty_before' => $current_old_qty,
            'old_qty_after' => max(0, $current_old_qty - 1),
            'quantity_before' => $current_quantity,
            'quantity_after' => $current_quantity - 1,
            'sec_units_deducted' => $single_unit_sec_deduction
        ]);
        
        // Update local variables for next iteration
        $old_qty = max(0, $current_old_qty - 1);
        $current_stock = $current_quantity - 1;
        
        // ==============================================
        // STEP 2c: CHECK IF OLD_QTY BECAME ZERO AFTER THIS DEDUCTION
        // ==============================================
        if ($old_qty <= 0.001 && !$price_updated) {
            // Get updated quantity after deduction
            $updated_stock_stmt = $pdo->prepare("
                SELECT quantity
                FROM product_stocks
                WHERE product_id = ? AND shop_id = ? AND business_id = ?
            ");
            $updated_stock_stmt->execute([$product_id, $shop_id, $business_id]);
            $updated_stock = $updated_stock_stmt->fetch();
            $updated_quantity = (float)$updated_stock['quantity'];
            
            debug_log("Old stock exhausted at deduction #$deducted_count", [
                'new_old_qty' => $old_qty,
                'updated_quantity' => $updated_quantity,
                'quantity_received' => $quantity_received
            ]);
            
            // Check if remaining quantity equals batch quantity received
            if (abs($updated_quantity - $quantity_received) <= 0.001) {
                debug_log("Batch completion condition met. Updating prices...", [
                    'purchase_price' => $purchase_price,
                    'retail_price' => $retail_price,
                    'wholesale_price' => $wholesale_price,
                    'new_mrp' => $new_mrp
                ]);
                
                // ==============================================
                // STEP 2d: APPLY NEW PRICING
                // ==============================================
                $update_product_stmt = $pdo->prepare("
                    UPDATE products
                    SET 
                        stock_price = ?,
                        retail_price = ?,
                        wholesale_price = ?,
                        mrp = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update_product_stmt->execute([
                    $purchase_price,
                    $retail_price,
                    $wholesale_price,
                    $new_mrp,
                    $product_id
                ]);
                
                debug_log("Product prices updated", [
                    'new_stock_price' => $purchase_price,
                    'new_retail_price' => $retail_price,
                    'new_wholesale_price' => $wholesale_price,
                    'new_mrp' => $new_mrp
                ]);
                
                // ==============================================
                // STEP 2e: RESET BATCH TRACKING FLAG
                // ==============================================
                $reset_batch_stmt = $pdo->prepare("
                    UPDATE product_stocks
                    SET 
                        use_batch_tracking = 0,
                        batch_id = NULL,
                        old_qty = 0,
                        last_updated = NOW()
                    WHERE product_id = ? 
                      AND shop_id = ? 
                      AND business_id = ?
                ");
                $reset_batch_stmt->execute([
                    $product_id,
                    $shop_id,
                    $business_id
                ]);
                
                debug_log("Batch tracking reset after price update");
                $price_updated = true;
            }
        }
    }
    
    debug_log("Iterative deduction completed", [
        'total_deducted' => $deducted_count,
        'price_updated' => $price_updated ? 'Yes' : 'No'
    ]);
    
    // ==============================================
    // STEP 3: CALCULATE PROFIT/LOSS BASED ON MARGIN CHANGE
    // ==============================================
    
    // Get the actual selling price from the invoice item
    $selling_price = $unit_price; // Price at which the item was sold
    $cost_price = $original_price; // Original cost price from product
    
    // Calculate margin before and after batch
    $margin_before = 0;
    $margin_after = 0;
    $new_batch_product_profit = 0;
    $new_batch_product_loss = 0;
    
    // Get the current selling price before any batch changes
    $current_selling_price_before = $current_selling_price;
    
    // Determine if we're selling from old stock or new stock
    if ($deducted_count <= $initial_old_qty) {
        // Selling from old stock
        $margin_before = ($current_selling_price_before - $old_purchase_price) / $current_selling_price_before * 100;
        $margin_after = ($current_selling_price_before - $purchase_price) / $current_selling_price_before * 100;
        
        debug_log("Selling from OLD stock", [
            'old_purchase_price' => $old_purchase_price,
            'new_purchase_price' => $purchase_price,
            'selling_price' => $current_selling_price_before,
            'margin_before' => $margin_before,
            'margin_after' => $margin_after
        ]);
    } else {
        // Selling from new stock (after old stock exhausted)
        $margin_before = ($current_selling_price_before - $old_purchase_price) / $current_selling_price_before * 100;
        $margin_after = ($current_selling_price - $purchase_price) / $current_selling_price * 100;
        
        debug_log("Selling from NEW stock", [
            'old_purchase_price' => $old_purchase_price,
            'new_purchase_price' => $purchase_price,
            'selling_price' => $current_selling_price,
            'margin_before' => $margin_before,
            'margin_after' => $margin_after
        ]);
    }
    
    // Calculate profit/loss based on margin change
    if ($margin_after > $margin_before) {
        // Margin increased - Profit
        $new_batch_product_profit = ($margin_after - $margin_before) * $selling_price / 100;
        debug_log("Margin INCREASE - Profit", [
            'margin_increase' => ($margin_after - $margin_before) . '%',
            'profit_amount' => $new_batch_product_profit
        ]);
    } elseif ($margin_after < $margin_before) {
        // Margin decreased - Loss
        $new_batch_product_loss = ($margin_before - $margin_after) * $selling_price / 100;
        debug_log("Margin DECREASE - Loss", [
            'margin_decrease' => ($margin_before - $margin_after) . '%',
            'loss_amount' => $new_batch_product_loss
        ]);
    }
    
    // ==============================================
    // STEP 4: UPDATE INVOICE_ITEM WITH PROFIT/LOSS VALUES
    // ==============================================
    $update_item_stmt = $pdo->prepare("
        UPDATE invoice_items
        SET 
            new_batch_product_profit = ?,
            new_batch_product_loss = ?
        WHERE id = ?
    ");
    $update_item_stmt->execute([
        $new_batch_product_profit,
        $new_batch_product_loss,
        $item_id
    ]);
    
    debug_log("Updated invoice item with profit/loss", [
        'item_id' => $item_id,
        'new_batch_product_profit' => $new_batch_product_profit,
        'new_batch_product_loss' => $new_batch_product_loss
    ]);
    
    // ==============================================
    // STEP 5: VERIFY SECONDARY UNIT SYNC
    // ==============================================
    if ($sec_unit_conversion > 0) {
        $verify_sync_stmt = $pdo->prepare("
            SELECT quantity, total_secondary_units
            FROM product_stocks
            WHERE product_id = ? 
              AND shop_id = ? 
              AND business_id = ?
        ");
        $verify_sync_stmt->execute([$product_id, $shop_id, $business_id]);
        $updated_stock = $verify_sync_stmt->fetch();
        
        if ($updated_stock) {
            $calculated_secondary = (float)$updated_stock['quantity'] * $sec_unit_conversion;
            $stored_secondary = (float)$updated_stock['total_secondary_units'];
            $difference = abs($calculated_secondary - $stored_secondary);
            
            if ($difference > 0.01) {
                debug_log("Secondary units out of sync after iterative deduction, correcting...", [
                    'calculated' => $calculated_secondary,
                    'stored' => $stored_secondary,
                    'difference' => $difference
                ]);
                
                $sync_sec_stmt = $pdo->prepare("
                    UPDATE product_stocks
                    SET total_secondary_units = quantity * ?,
                        last_updated = NOW()
                    WHERE product_id = ? 
                      AND shop_id = ? 
                      AND business_id = ?
                ");
                $sync_sec_stmt->execute([
                    $sec_unit_conversion,
                    $product_id,
                    $shop_id,
                    $business_id
                ]);
                
                debug_log("Secondary units synchronized after iterative deduction");
            }
        }
    }
    
} else {
    // ==============================================
    // NON-BATCH TRACKING: DIRECT STOCK DEDUCTION (ALL AT ONCE)
    // ==============================================
    debug_log("Using direct stock deduction (non-batch)", [
        'quantity_to_deduct' => $quantity_in_primary_units,
        'sec_units_to_deduct' => $quantity_in_sec_units
    ]);
    
    $deduct_stmt = $pdo->prepare("
        UPDATE product_stocks
        SET 
            quantity = GREATEST(0, quantity - ?),
            total_secondary_units = GREATEST(0, total_secondary_units - ?),
            last_updated = NOW()
        WHERE product_id = ? 
          AND shop_id = ? 
          AND business_id = ?
    ");
    
    $deduct_stmt->execute([
        $quantity_in_primary_units,
        $quantity_in_sec_units,
        $product_id,
        $shop_id,
        $business_id
    ]);
    
    $rows_affected = $deduct_stmt->rowCount();
    
    debug_log("Direct stock deduction completed", [
        'qty_deducted_primary' => $quantity_in_primary_units,
        'qty_deducted_secondary' => $quantity_in_sec_units,
        'rows_affected' => $rows_affected
    ]);
    
    // ==============================================
    // HANDLE MISSING STOCK RECORD
    // ==============================================
    if ($rows_affected === 0) {
        debug_log("No stock record found, creating/updating...");
        
        $insert_stmt = $pdo->prepare("
            INSERT INTO product_stocks
            (product_id, shop_id, business_id, quantity, total_secondary_units, old_qty, use_batch_tracking, last_updated)
            VALUES (?, ?, ?, 0, 0, 0, 0, NOW())
            ON DUPLICATE KEY UPDATE
                quantity = GREATEST(0, quantity - ?),
                total_secondary_units = GREATEST(0, total_secondary_units - ?),
                last_updated = NOW()
        ");
        
        $insert_stmt->execute([
            $product_id,
            $shop_id,
            $business_id,
            $quantity_in_primary_units,
            $quantity_in_sec_units
        ]);
        
        debug_log("Stock record inserted/updated");
    }
    
    // ==============================================
    // VERIFY SECONDARY UNIT SYNC FOR NON-BATCH
    // ==============================================
    if ($sec_unit_conversion > 0) {
        $sync_sec_stmt = $pdo->prepare("
            UPDATE product_stocks
            SET total_secondary_units = quantity * ?,
                last_updated = NOW()
            WHERE product_id = ? 
              AND shop_id = ? 
              AND business_id = ?
              AND ABS(total_secondary_units - (quantity * ?)) > 0.01
        ");
        
        $sync_sec_stmt->execute([
            $sec_unit_conversion,
            $product_id,
            $shop_id,
            $business_id,
            $sec_unit_conversion
        ]);
        
        if ($sync_sec_stmt->rowCount() > 0) {
            debug_log("Synchronized secondary units for non-batch product");
        }
    }
}
            // ==============================================
            // FINAL VERIFICATION: GET UPDATED STOCK
            // ==============================================
            $final_check_stmt = $pdo->prepare("
                SELECT quantity, total_secondary_units, old_qty, use_batch_tracking, batch_id
                FROM product_stocks
                WHERE product_id = ? 
                  AND shop_id = ? 
                  AND business_id = ?
            ");
            $final_check_stmt->execute([$product_id, $shop_id, $business_id]);
            $final_stock = $final_check_stmt->fetch();
           
            debug_log("Final stock status", [
                'final_quantity' => $final_stock['quantity'] ?? 0,
                'final_secondary_units' => $final_stock['total_secondary_units'] ?? 0,
                'final_old_qty' => $final_stock['old_qty'] ?? 0,
                'final_batch_tracking' => $final_stock['use_batch_tracking'] ?? 0,
                'final_batch_id' => $final_stock['batch_id'] ?? 'NULL'
            ]);
           
            // ==============================================
            // LOG SUCCESS MESSAGE
            // ==============================================
            debug_log("Stock deduction completed for item #$item_index", [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'sold_quantity' => $qty . ' ' . $unit,
                'converted_primary' => $quantity_in_primary_units . ' ' . $unit_of_measure,
                'converted_secondary' => $quantity_in_sec_units . ' ' . $secondary_unit,
                'batch_tracking_used' => $use_batch_tracking ? 'Yes' : 'No',
                'old_stock_deducted' => isset($deduct_from_old_qty) ? $deduct_from_old_qty : 'N/A'
            ]);
        }
       
        /* =========================
           UPDATE REFERRAL & INVOICE
        ========================= */
        if ($referral_id && $total_referral_commission > 0) {
            try {
                $updateReferralStmt = $pdo->prepare("
                    UPDATE referral_person
                    SET debit_amount = debit_amount + ?,
                        balance_due = balance_due + ?,
                        total_sales_amount = total_sales_amount + ?,
                        updated_at = NOW()
                    WHERE id = ? AND business_id = ?
                ");
                $updateReferralStmt->execute([
                    $total_referral_commission,
                    $total_referral_commission,
                    $total,
                    $referral_id,
                    $business_id
                ]);
                debug_log("Referral updated");
            } catch (Exception $e) {
                debug_log("Referral update failed", ['error' => $e->getMessage()]);
            }
        }
       
        /* =========================
           LOYALTY POINTS HANDLING
        ========================= */
        // 1. Deduct used points (if any)
        if ($points_used > 0 && $customer_points) {
            try {
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
                    $table_check = $pdo->query("SHOW TABLES LIKE 'points_redemptions'")->fetch();
                    if (!$table_check) {
                        $pdo->exec("
                            CREATE TABLE points_redemptions (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                customer_id INT NOT NULL,
                                business_id INT NOT NULL,
                                invoice_id INT NOT NULL,
                                points_used INT NOT NULL,
                                discount_amount DECIMAL(12,2) NOT NULL,
                                redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                INDEX idx_customer (customer_id),
                                INDEX idx_invoice (invoice_id)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                   
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
            } catch (Exception $e) {
                debug_log("Points deduction failed", ['error' => $e->getMessage()]);
            }
        }
       
        // 2. Calculate and add earned points (from purchase amount)
        $points_earned = 0;
        $points_basis = $subtotal - $points_discount;
       
        if ($loyalty_settings['is_active'] && $loyalty_settings['points_per_amount'] > 0 && $points_basis > 0) {
            $points_earned = $points_basis * $loyalty_settings['points_per_amount'];
            $points_earned = round($points_earned, 2);
           
            if ($points_earned > 0) {
                try {
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
                        $table_check = $pdo->query("SHOW TABLES LIKE 'points_earnings'")->fetch();
                        if (!$table_check) {
                            $pdo->exec("
                                CREATE TABLE points_earnings (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    customer_id INT NOT NULL,
                                    business_id INT NOT NULL,
                                    invoice_id INT NOT NULL,
                                    points_earned DECIMAL(12,2) NOT NULL,
                                    purchase_amount DECIMAL(12,2) NOT NULL,
                                    earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                    INDEX idx_customer (customer_id),
                                    INDEX idx_invoice (invoice_id)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                            ");
                        }
                       
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
                } catch (Exception $e) {
                    debug_log("Points earning failed", ['error' => $e->getMessage()]);
                }
            }
        }
       
        /* =========================
           GST SUMMARY
        ========================= */
        if ($gst_status) {
            try {
                $table_check = $pdo->query("SHOW TABLES LIKE 'invoice_gst_summary'")->fetch();
                if (!$table_check) {
                    $pdo->exec("
                        CREATE TABLE invoice_gst_summary (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            invoice_id INT NOT NULL,
                            total_taxable_value DECIMAL(10,2) DEFAULT 0.00,
                            total_cgst DECIMAL(10,2) DEFAULT 0.00,
                            total_sgst DECIMAL(10,2) DEFAULT 0.00,
                            total_igst DECIMAL(10,2) DEFAULT 0.00,
                            total_gst DECIMAL(10,2) DEFAULT 0.00,
                            UNIQUE KEY invoice_id (invoice_id),
                            INDEX idx_invoice (invoice_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
               
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
       
        // Update customer outstanding amount if credit sale
        if ($pending_amount > 0) {
            try {
                $updateCustomerSql = "UPDATE customers
                                     SET outstanding_amount = outstanding_amount + ?,
                                         outstanding_type = 'credit'
                                     WHERE id = ? AND business_id = ?";
                $updateStmt = $pdo->prepare($updateCustomerSql);
                $updateStmt->execute([$pending_amount, $customer_id, $business_id]);
                debug_log("Updated customer outstanding amount", ['pending_amount' => $pending_amount]);
            } catch (Exception $e) {
                debug_log("Failed to update customer outstanding", ['error' => $e->getMessage()]);
            }
        }
       
        // Commit transaction
        $pdo->commit();
        debug_log("Transaction committed successfully");
       
        // SUCCESS - Send response
        debug_log("=== INVOICE SAVE COMPLETED SUCCESSFULLY ===");
       
        $response = [
            'success' => true,
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'gst_status' => $gst_status,
            'gst_type' => $gst_type,
            'invoice_type' => $invoice_type,
            'payment_status' => $payment_status,
            'pending_amount' => $pending_amount,
            'total_paid' => $total_paid,
            'change_given' => $change_given,
            'points_used' => $points_used,
            'points_discount' => $points_discount,
            'points_earned' => $points_earned,
            'customer_id' => $customer_id,
            'message' => $is_print
                ? 'Invoice saved successfully and ready for printing'
                : 'Invoice saved successfully'
        ];

        if ($is_print) {
            $response['print_url'] = "invoice_print.php?invoice_id=" . $invoice_id;
        }

        echo json_encode($response);

    } catch (Exception $e) {
        // Rollback if transaction was started
        if ($pdo->inTransaction()) {
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
       
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save invoice: ' . $e->getMessage(),
            'error' => $e->getMessage()
        ]);
    }
}

function checkCustomerCreditLimit() {
    global $pdo, $business_id;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $customer_id = $data['customer_id'] ?? 0;
    $pending_amount = (float)($data['pending_amount'] ?? 0);
    
    if (!$customer_id) {
        echo json_encode(['success' => false, 'message' => 'Customer ID required']);
        return;
    }
    
    try {
        $sql = "SELECT credit_limit, outstanding_amount 
                FROM customers 
                WHERE id = ? AND business_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$customer_id, $business_id]);
        $customer = $stmt->fetch();
        
        if (!$customer) {
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            return;
        }
        
        $creditLimit = $customer['credit_limit'] ?? 0;
        $outstanding = $customer['outstanding_amount'] ?? 0;
        $newOutstanding = $outstanding + $pending_amount;
        $availableCredit = $creditLimit - $outstanding;
        
        echo json_encode([
            'success' => true,
            'has_credit_limit' => $creditLimit > 0,
            'credit_limit' => $creditLimit,
            'outstanding_amount' => $outstanding,
            'new_outstanding' => $newOutstanding,
            'available_credit' => $availableCredit,
            'can_proceed' => $creditLimit == 0 || $newOutstanding <= $creditLimit,
            'exceeded_by' => $newOutstanding > $creditLimit ? ($newOutstanding - $creditLimit) : 0
        ]);
    } catch (Exception $e) {
        debug_log("Credit check error", ['error' => $e->getMessage()]);
        echo json_encode(['success' => false, 'message' => 'Error checking credit limit: ' . $e->getMessage()]);
    }
}

// New function to list invoices
function listInvoices() {
    global $pdo, $business_id, $shop_id;
    
    try {
        $page = $_GET['page'] ?? 1;
        $limit = $_GET['limit'] ?? 20;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $date_from = $_GET['date_from'] ?? '';
        $date_to = $_GET['date_to'] ?? '';
        $customer_id = $_GET['customer_id'] ?? '';
        $payment_status = $_GET['payment_status'] ?? '';
        
        $sql = "SELECT 
                    i.id,
                    i.invoice_number,
                    i.customer_id,
                    c.name as customer_name,
                    i.total,
                    i.paid_amount,
                    i.pending_amount,
                    i.payment_status,
                    i.payment_method,
                    i.created_at,
                    i.invoice_type,
                    u.username as seller_name
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                LEFT JOIN users u ON i.seller_id = u.id
                WHERE i.business_id = ? AND i.shop_id = ?";
        
        $params = [$business_id, $shop_id];
        
        if (!empty($search)) {
            $sql .= " AND (i.invoice_number LIKE ? OR c.name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($customer_id)) {
            $sql .= " AND i.customer_id = ?";
            $params[] = $customer_id;
        }
        
        if (!empty($payment_status)) {
            $sql .= " AND i.payment_status = ?";
            $params[] = $payment_status;
        }
        
        if (!empty($date_from)) {
            $sql .= " AND DATE(i.created_at) >= ?";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $sql .= " AND DATE(i.created_at) <= ?";
            $params[] = $date_to;
        }
        
        $sql .= " ORDER BY i.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();
        
        // Count total for pagination
        $count_sql = "SELECT COUNT(*) as total FROM invoices i
                     LEFT JOIN customers c ON i.customer_id = c.id
                     WHERE i.business_id = ? AND i.shop_id = ?";
        
        $count_params = [$business_id, $shop_id];
        
        if (!empty($search)) {
            $count_sql .= " AND (i.invoice_number LIKE ? OR c.name LIKE ?)";
            $count_params[] = "%$search%";
            $count_params[] = "%$search%";
        }
        
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($count_params);
        $total = $count_stmt->fetch()['total'];
        
        echo json_encode([
            'success' => true,
            'invoices' => $invoices,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ]);
    } catch (Exception $e) {
        debug_log("List invoices error", ['error' => $e->getMessage()]);
        echo json_encode(['success' => false, 'message' => 'Error listing invoices: ' . $e->getMessage()]);
    }
}

// New function to get invoice details
function getInvoiceDetails() {
    global $pdo, $business_id;
    
    $invoice_id = $_GET['invoice_id'] ?? 0;
    
    if (!$invoice_id) {
        echo json_encode(['success' => false, 'message' => 'Invoice ID required']);
        return;
    }
    
    try {
        // Get invoice basic info
        $sql = "SELECT 
                    i.*,
                    c.name as customer_name,
                    c.phone as customer_phone,
                    c.gstin as customer_gstin,
                    c.address as customer_address,
                    u.username as seller_name,
                    rp.name as referral_name
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                LEFT JOIN users u ON i.seller_id = u.id
                LEFT JOIN referral_person rp ON i.referral_id = rp.id
                WHERE i.id = ? AND i.business_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$invoice_id, $business_id]);
        $invoice = $stmt->fetch();
        
        if (!$invoice) {
            echo json_encode(['success' => false, 'message' => 'Invoice not found']);
            return;
        }
        
        // Get invoice items
        $items_sql = "SELECT 
                        ii.*,
                        p.product_name,
                        p.product_code,
                        p.unit_of_measure
                    FROM invoice_items ii
                    LEFT JOIN products p ON ii.product_id = p.id
                    WHERE ii.invoice_id = ?
                    ORDER BY ii.id";
        
        $items_stmt = $pdo->prepare($items_sql);
        $items_stmt->execute([$invoice_id]);
        $items = $items_stmt->fetchAll();
        
        // Get GST summary if exists
        $gst_sql = "SELECT * FROM invoice_gst_summary WHERE invoice_id = ?";
        $gst_stmt = $pdo->prepare($gst_sql);
        $gst_stmt->execute([$invoice_id]);
        $gst_summary = $gst_stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'invoice' => $invoice,
            'items' => $items,
            'gst_summary' => $gst_summary
        ]);
    } catch (Exception $e) {
        debug_log("Get invoice details error", ['error' => $e->getMessage()]);
        echo json_encode(['success' => false, 'message' => 'Error getting invoice details: ' . $e->getMessage()]);
    }
}

// New function to delete invoice
function deleteInvoice() {
    global $pdo, $business_id, $user_id;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $invoice_id = $data['invoice_id'] ?? 0;
    
    if (!$invoice_id) {
        echo json_encode(['success' => false, 'message' => 'Invoice ID required']);
        return;
    }
    
    // Check if user has permission (add your own permission logic here)
    
    try {
        $pdo->beginTransaction();
        
        // Get invoice details first for stock restoration
        $invoice_sql = "SELECT * FROM invoices WHERE id = ? AND business_id = ?";
        $invoice_stmt = $pdo->prepare($invoice_sql);
        $invoice_stmt->execute([$invoice_id, $business_id]);
        $invoice = $invoice_stmt->fetch();
        
        if (!$invoice) {
            throw new Exception("Invoice not found");
        }
        
        // Get all items from this invoice
        $items_sql = "SELECT * FROM invoice_items WHERE invoice_id = ?";
        $items_stmt = $pdo->prepare($items_sql);
        $items_stmt->execute([$invoice_id]);
        $items = $items_stmt->fetchAll();
        
        // Restore stock for each item
        foreach ($items as $item) {
            // Get current stock info
            $stock_sql = "SELECT * FROM product_stocks WHERE product_id = ? AND business_id = ?";
            $stock_stmt = $pdo->prepare($stock_sql);
            $stock_stmt->execute([$item['product_id'], $business_id]);
            $stock = $stock_stmt->fetch();
            
            if ($stock) {
                // Restore quantity (simplified - you might need to adjust based on your logic)
                $restore_qty = $item['quantity'];
                
                // Get conversion factor
                $product_sql = "SELECT sec_unit_conversion FROM products WHERE id = ?";
                $product_stmt = $pdo->prepare($product_sql);
                $product_stmt->execute([$item['product_id']]);
                $product = $product_stmt->fetch();
                $sec_conversion = $product['sec_unit_conversion'] ?? 1;
                
                $restore_sec_qty = $restore_qty * $sec_conversion;
                
                $update_sql = "UPDATE product_stocks 
                               SET quantity = quantity + ?, 
                                   total_secondary_units = total_secondary_units + ?,
                                   last_updated = NOW()
                               WHERE product_id = ? AND business_id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([$restore_qty, $restore_sec_qty, $item['product_id'], $business_id]);
            }
        }
        
        // Update customer outstanding if credit sale
        if ($invoice['pending_amount'] > 0) {
            $customer_sql = "UPDATE customers 
                            SET outstanding_amount = outstanding_amount - ? 
                            WHERE id = ? AND business_id = ?";
            $customer_stmt = $pdo->prepare($customer_sql);
            $customer_stmt->execute([$invoice['pending_amount'], $invoice['customer_id'], $business_id]);
        }
        
        // Delete invoice items
        $delete_items_sql = "DELETE FROM invoice_items WHERE invoice_id = ?";
        $delete_items_stmt = $pdo->prepare($delete_items_sql);
        $delete_items_stmt->execute([$invoice_id]);
        
        // Delete invoice
        $delete_sql = "DELETE FROM invoices WHERE id = ? AND business_id = ?";
        $delete_stmt = $pdo->prepare($delete_sql);
        $delete_stmt->execute([$invoice_id, $business_id]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Invoice deleted successfully'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        debug_log("Delete invoice error", ['error' => $e->getMessage()]);
        echo json_encode(['success' => false, 'message' => 'Error deleting invoice: ' . $e->getMessage()]);
    }
}

?>
