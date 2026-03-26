<?php
// update_invoice.php - Update existing invoice
session_start();
require_once 'config/database.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'seller';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// Check if user can edit invoices (admin or shop_manager only)
$can_edit = in_array($user_role, ['admin', 'shop_manager']);

if (!$can_edit) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    // Try regular POST
    $data = $_POST;
}

if (empty($data)) {
    echo json_encode(['success' => false, 'message' => 'No data received']);
    exit();
}

$invoice_id = isset($data['invoice_id']) ? (int)$data['invoice_id'] : 0;
$customer_name = isset($data['customer_name']) ? trim($data['customer_name']) : '';
$customer_phone = isset($data['customer_phone']) ? trim($data['customer_phone']) : '';
$customer_address = isset($data['customer_address']) ? trim($data['customer_address']) : '';
$customer_gstin = isset($data['customer_gstin']) ? trim($data['customer_gstin']) : '';
$invoice_type = isset($data['invoice_type']) ? $data['invoice_type'] : 'gst';
$price_type = isset($data['price_type']) ? $data['price_type'] : 'retail';
$date = isset($data['date']) ? $data['date'] : date('Y-m-d');
$referral_id = isset($data['referral_id']) && !empty($data['referral_id']) ? (int)$data['referral_id'] : null;
$overall_discount = isset($data['overall_discount']) ? (float)$data['overall_discount'] : 0;
$overall_discount_type = isset($data['overall_discount_type']) ? $data['overall_discount_type'] : 'rupees';
$items = isset($data['items']) ? $data['items'] : [];
$shipping_details = isset($data['shipping_details']) ? $data['shipping_details'] : [];
$payment_methods = isset($data['payment_methods']) ? $data['payment_methods'] : [];
$payment_details = isset($data['payment_details']) ? $data['payment_details'] : [];

// Validate required fields
if ($invoice_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid invoice ID']);
    exit();
}

if (empty($customer_name)) {
    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
    exit();
}

if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'At least one item is required']);
    exit();
}

// Check if invoice exists and belongs to this business
$check_stmt = $pdo->prepare("
    SELECT i.*, s.shop_name 
    FROM invoices i
    LEFT JOIN shops s ON i.shop_id = s.id
    WHERE i.id = ? AND i.business_id = ?
");
$check_stmt->execute([$invoice_id, $business_id]);
$invoice = $check_stmt->fetch();

if (!$invoice) {
    echo json_encode(['success' => false, 'message' => 'Invoice not found']);
    exit();
}

// Check shop permission
if ($user_role !== 'admin' && $invoice['shop_id'] != $current_shop_id) {
    echo json_encode(['success' => false, 'message' => 'You don\'t have permission to edit this invoice']);
    exit();
}

// Start transaction
$pdo->beginTransaction();

try {
    // Get or create customer
    $customer_id = null;
    
    if (!empty($customer_phone)) {
        // Check if customer exists
        $cust_stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND business_id = ?");
        $cust_stmt->execute([$customer_phone, $business_id]);
        $customer = $cust_stmt->fetch();
        
        if ($customer) {
            $customer_id = $customer['id'];
            // Update customer details
            $update_cust = $pdo->prepare("
                UPDATE customers 
                SET name = ?, address = ?, gstin = ?
                WHERE id = ? AND business_id = ?
            ");
            $update_cust->execute([$customer_name, $customer_address, $customer_gstin, $customer_id, $business_id]);
        } else {
            // Create new customer
            $insert_cust = $pdo->prepare("
                INSERT INTO customers (business_id, name, phone, address, gstin, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $insert_cust->execute([$business_id, $customer_name, $customer_phone, $customer_address, $customer_gstin]);
            $customer_id = $pdo->lastInsertId();
        }
    } else {
        // No phone - check by name
        $cust_stmt = $pdo->prepare("SELECT id FROM customers WHERE name = ? AND business_id = ? AND (phone IS NULL OR phone = '') LIMIT 1");
        $cust_stmt->execute([$customer_name, $business_id]);
        $customer = $cust_stmt->fetch();
        
        if ($customer) {
            $customer_id = $customer['id'];
        } else {
            // Create new customer with just name
            $insert_cust = $pdo->prepare("
                INSERT INTO customers (business_id, name, created_at)
                VALUES (?, ?, NOW())
            ");
            $insert_cust->execute([$business_id, $customer_name]);
            $customer_id = $pdo->lastInsertId();
        }
    }
    
    // Calculate totals
    $subtotal = 0;
    $total_discount = 0;
    $total_cgst = 0;
    $total_sgst = 0;
    $total_igst = 0;
    $total_taxable = 0;
    
    foreach ($items as $item) {
        $line_total = $item['unit_price'] * $item['quantity'];
        $discount = $item['discount_amount'] ?? 0;
        
        $subtotal += $line_total;
        $total_discount += $discount;
        
        // Calculate GST
        $cgst_rate = (float)($item['cgst_rate'] ?? 0);
        $sgst_rate = (float)($item['sgst_rate'] ?? 0);
        $igst_rate = (float)($item['igst_rate'] ?? 0);
        $total_gst_rate = $cgst_rate + $sgst_rate + $igst_rate;
        
        if ($invoice_type === 'gst' && $total_gst_rate > 0) {
            $taxable = $line_total / (1 + ($total_gst_rate / 100));
            $gst_amount = $line_total - $taxable;
            
            $cgst_amount = $gst_amount * ($cgst_rate / $total_gst_rate);
            $sgst_amount = $gst_amount * ($sgst_rate / $total_gst_rate);
            $igst_amount = $gst_amount * ($igst_rate / $total_gst_rate);
        } else {
            $taxable = $line_total;
            $cgst_amount = 0;
            $sgst_amount = 0;
            $igst_amount = 0;
        }
        
        $total_taxable += $taxable;
        $total_cgst += $cgst_amount;
        $total_sgst += $sgst_amount;
        $total_igst += $igst_amount;
    }
    
    $subtotal_after_discount = $subtotal - $total_discount;
    
    // Calculate overall discount
    $overall_discount_amount = 0;
    if ($overall_discount > 0) {
        if ($overall_discount_type === 'percentage') {
            $overall_discount_amount = $subtotal_after_discount * ($overall_discount / 100);
        } else {
            $overall_discount_amount = min($overall_discount, $subtotal_after_discount);
        }
    }
    
    $total_before_shipping = $subtotal_after_discount - $overall_discount_amount;
    $shipping_charges = (float)($shipping_details['charges'] ?? 0);
    $grand_total = $total_before_shipping + $shipping_charges;
    
    // Calculate payment amounts
    $cash_amount = (float)($payment_details['cash'] ?? 0);
    $upi_amount = (float)($payment_details['upi'] ?? 0);
    $bank_amount = (float)($payment_details['bank'] ?? 0);
    $cheque_amount = (float)($payment_details['cheque'] ?? 0);
    $credit_amount = (float)($payment_details['credit'] ?? 0);
    
    $total_paid = $cash_amount + $upi_amount + $bank_amount + $cheque_amount + $credit_amount;
    $change_given = $total_paid > $grand_total ? $total_paid - $grand_total : 0;
    $pending_amount = $total_paid < $grand_total ? $grand_total - $total_paid : 0;
    
    $payment_status = $pending_amount == 0 ? 'paid' : ($total_paid > 0 ? 'partial' : 'pending');
    $payment_method = !empty($payment_methods) ? implode('+', $payment_methods) : 'cash';
    
    // Determine invoice type for database
    $customer_type = !empty($customer_gstin) ? 'wholesale' : 'retail';
    $invoice_type_db = $invoice_type === 'gst' ? 'tax_invoice' : 'retail_bill';
    $gst_status = $invoice_type === 'gst' ? 1 : 0;
    
    // Build update query dynamically to handle optional columns
    $update_fields = [
        "customer_id = ?",
        "customer_type = ?",
        "invoice_type = ?",
        "gst_type = ?",
        "gst_status = ?",
        "subtotal = ?",
        "discount = ?",
        "discount_type = ?",
        "overall_discount = ?",
        "total = ?",
        "cash_received = ?",
        "change_given = ?",
        "pending_amount = ?",
        "paid_amount = ?",
        "payment_status = ?",
        "cash_amount = ?",
        "upi_amount = ?",
        "bank_amount = ?",
        "cheque_amount = ?",
        "credit_amount = ?",
        "cheque_number = ?",
        "upi_reference = ?",
        "bank_reference = ?",
        "credit_reference = ?",
        "payment_method = ?",
        "referral_id = ?",
        "shipping_name = ?",
        "shipping_contact = ?",
        "shipping_gstin = ?",
        "shipping_address = ?",
        "shipping_vehicle_number = ?",
        "shipping_charges = ?",
        "updated_at = NOW()"
    ];
    
    $update_params = [
        $customer_id,
        $customer_type,
        $invoice_type_db,
        $invoice_type,
        $gst_status,
        $subtotal,
        $total_discount,
        $overall_discount_type === 'percentage' ? 'percent' : 'flat',
        $overall_discount_amount,
        $grand_total,
        $total_paid,
        $change_given,
        $pending_amount,
        $total_paid,
        $payment_status,
        $cash_amount,
        $upi_amount,
        $bank_amount,
        $cheque_amount,
        $credit_amount,
        $payment_details['cheque_number'] ?? '',
        $payment_details['upi_reference'] ?? '',
        $payment_details['bank_reference'] ?? '',
        $payment_details['credit_reference'] ?? '',
        $payment_method,
        $referral_id,
        $shipping_details['name'] ?? null,
        $shipping_details['contact'] ?? null,
        $shipping_details['gstin'] ?? null,
        $shipping_details['address'] ?? null,
        $shipping_details['vehicle_number'] ?? null,
        $shipping_details['charges'] ?? 0,
        $invoice_id,
        $business_id
    ];
    
    $update_sql = "UPDATE invoices SET " . implode(", ", $update_fields) . " WHERE id = ? AND business_id = ?";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute($update_params);
    
    // Delete old invoice items
    $delete_items = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
    $delete_items->execute([$invoice_id]);
    
    // Insert updated items
    $insert_item = $pdo->prepare("
        INSERT INTO invoice_items (
            invoice_id, product_id, sale_type, quantity, unit_price, 
            discount_amount, discount_rate, hsn_code, cgst_rate, sgst_rate, 
            igst_rate, cgst_amount, sgst_amount, igst_amount, taxable_value,
            total_with_gst, profit, unit, total_price
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($items as $item) {
        $product_id = (int)$item['product_id'];
        $sale_type = $item['price_type'] ?? 'retail';
        $quantity = (float)$item['quantity'];
        $unit_price = (float)$item['unit_price'];
        $discount_amount = (float)($item['discount_amount'] ?? 0);
        $discount_rate = 0;
        
        if ($discount_amount > 0 && $unit_price > 0) {
            if (($item['discount_type'] ?? 'percentage') === 'percentage') {
                $discount_rate = $discount_amount;
                $discount_amount = $unit_price * ($discount_rate / 100);
            } else {
                $discount_rate = ($discount_amount / $unit_price) * 100;
            }
        }
        
        $line_total = $unit_price * $quantity;
        $line_total_after_discount = $line_total - $discount_amount;
        
        $cgst_rate = (float)($item['cgst_rate'] ?? 0);
        $sgst_rate = (float)($item['sgst_rate'] ?? 0);
        $igst_rate = (float)($item['igst_rate'] ?? 0);
        $total_gst_rate = $cgst_rate + $sgst_rate + $igst_rate;
        
        if ($invoice_type === 'gst' && $total_gst_rate > 0) {
            $taxable = $line_total_after_discount / (1 + ($total_gst_rate / 100));
            $gst_amount = $line_total_after_discount - $taxable;
            
            $cgst_amount = $gst_amount * ($cgst_rate / $total_gst_rate);
            $sgst_amount = $gst_amount * ($sgst_rate / $total_gst_rate);
            $igst_amount = $gst_amount * ($igst_rate / $total_gst_rate);
        } else {
            $taxable = $line_total_after_discount;
            $cgst_amount = 0;
            $sgst_amount = 0;
            $igst_amount = 0;
        }
        
        // Calculate profit (simplified - you may need to get stock price)
        $profit = 0;
        
        $insert_item->execute([
            $invoice_id,
            $product_id,
            $sale_type,
            $quantity,
            $unit_price,
            $discount_amount,
            $discount_rate,
            $item['hsn_code'] ?? '',
            $cgst_rate,
            $sgst_rate,
            $igst_rate,
            $cgst_amount,
            $sgst_amount,
            $igst_amount,
            $taxable,
            $line_total_after_discount,
            $profit,
            $item['unit'] ?? 'PCS',
            $line_total_after_discount
        ]);
    }
    
    // Update GST summary
    try {
        // Check if table exists
        $table_check = $pdo->query("SHOW TABLES LIKE 'invoice_gst_summary'")->fetch();
        if ($table_check) {
            $pdo->prepare("DELETE FROM invoice_gst_summary WHERE invoice_id = ?")->execute([$invoice_id]);
            
            $insert_gst = $pdo->prepare("
                INSERT INTO invoice_gst_summary (invoice_id, total_taxable_value, total_cgst, total_sgst, total_igst, total_gst)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insert_gst->execute([
                $invoice_id,
                $total_taxable,
                $total_cgst,
                $total_sgst,
                $total_igst,
                $total_cgst + $total_sgst + $total_igst
            ]);
        }
    } catch (Exception $e) {
        // GST summary table might not exist, ignore
        error_log("GST summary error: " . $e->getMessage());
    }
    
    // Log the update
    try {
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, business_id, action, details, created_at)
            VALUES (?, ?, 'invoice_updated', ?, NOW())
        ");
        $log_details = json_encode([
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice['invoice_number'],
            'updated_by' => $user_id
        ]);
        $log_stmt->execute([$user_id, $business_id, $log_details]);
    } catch (Exception $e) {
        // Activity log table might not exist, ignore
        error_log("Activity log error: " . $e->getMessage());
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Invoice updated successfully',
        'invoice_id' => $invoice_id,
        'invoice_number' => $invoice['invoice_number']
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Update Invoice Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update invoice: ' . $e->getMessage()
    ]);
}