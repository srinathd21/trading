<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
require_once 'includes/number_format_helper.php';

// Authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'warehouse_manager','stock_manager'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$current_business_id = $_SESSION['current_business_id'] ?? null;

if (!$current_business_id) {
    $_SESSION['error'] = "Please select a business first.";
    header('Location: select_shop.php');
    exit();
}

// Get current shop info for this business
$shop_id = $_SESSION['current_shop_id'] ?? 1;
$shop_name = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
$shop_name->execute([$shop_id, $current_business_id]);
$shop_name = $shop_name->fetchColumn() ?? 'Shop';

// Get warehouse info for THIS BUSINESS ONLY
$warehouse = $pdo->prepare("SELECT id, shop_name FROM shops WHERE is_warehouse = 1 AND business_id = ? LIMIT 1");
$warehouse->execute([$current_business_id]);
$warehouse = $warehouse->fetch();
$warehouse_id = $warehouse['id'] ?? 0;
$warehouse_name = $warehouse['shop_name'] ?? 'Warehouse';

$success = $error = '';

/**
 * Safe column checker for purchases table.
 */
function purchaseColumnExists(PDO $pdo, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM purchases LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Create due-days columns automatically if they are missing.
 * This avoids HTTP 500 when the page is deployed before DB update.
 */
function ensurePurchaseDueColumns(PDO $pdo): void {
    try {
        if (!purchaseColumnExists($pdo, 'due_days')) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN due_days INT NULL DEFAULT NULL AFTER purchase_date");
        }

        if (!purchaseColumnExists($pdo, 'due_date')) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN due_date DATE NULL DEFAULT NULL AFTER due_days");
        }
    } catch (Throwable $e) {
        error_log('Unable to ensure purchase due columns: ' . $e->getMessage());
    }
}

/**
 * Calculate due date from purchase date and selected due days.
 */
function calculatePurchaseDueDate(string $purchase_date, int $due_days): string {
    $purchase_date = trim($purchase_date) !== '' ? $purchase_date : date('Y-m-d');

    try {
        $dt = new DateTime($purchase_date);
    } catch (Throwable $e) {
        $dt = new DateTime();
    }

    if ($due_days > 0) {
        $dt->modify('+' . $due_days . ' days');
    }

    return $dt->format('Y-m-d');
}

ensurePurchaseDueColumns($pdo);


/**
 * Generate purchase number using number_format_settings table.
 * Supports split format: prefix + middle format + separator + last digits.
 * Examples: PO202604-0001, PO2026-2027-0001
 */
function generatePurchaseNumber($pdo, $business_id, $shop_id = null, $purchase_date = null) {
    $purchase_date = $purchase_date ?: date('Y-m-d');

    $result = nf_generate_document_number($pdo, [
        'business_id'   => $business_id,
        'shop_id'       => $shop_id,
        'document_type' => 'purchase',
        'table_name'    => 'purchases',
        'number_column' => 'purchase_number',
        'date_column'   => 'purchase_date',
        'date_value'    => $purchase_date
    ]);

    if (!empty($result['success'])) {
        return $result['number'];
    }

    throw new Exception($result['message'] ?? 'Unable to generate purchase number');
}

// Generate Unique Purchase Number for current business
$purchase_number = generatePurchaseNumber($pdo, $current_business_id, $shop_id, date('Y-m-d'));

// Fetch Data - Only from current business
$manufacturers = $pdo->prepare("
    SELECT id, name 
    FROM manufacturers 
    WHERE business_id = ? 
      AND is_active = 1 
    ORDER BY name
");
$manufacturers->execute([$current_business_id]);
$manufacturers = $manufacturers->fetchAll();

// Get shops only from current business
$shops = $pdo->prepare("
    SELECT id, shop_name, location_type, is_warehouse 
    FROM shops 
    WHERE business_id = ? 
      AND is_active = 1 
    ORDER BY is_warehouse DESC, shop_name
");
$shops->execute([$current_business_id]);
$shops = $shops->fetchAll();

// Bill image upload configuration
$upload_dir = 'uploads/purchase_bills/';
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
$max_file_size = 10 * 1024 * 1024; // 10MB

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Process Form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manufacturer_id = (int)($_POST['manufacturer_id'] ?? 0);
    $purchase_date   = $_POST['purchase_date'] ?? date('Y-m-d');
    $posted_purchase_number = trim($_POST['purchase_number'] ?? '');
    $reference       = trim($_POST['reference'] ?? '');
    $purchase_invoice_no = trim($_POST['purchase_invoice_no'] ?? '');
    $notes           = trim($_POST['notes'] ?? '');
    $shop_id         = (int)($_POST['shop_id'] ?? 0);
    $items           = $_POST['items'] ?? [];

    // Due days and due date
    $due_days_option = $_POST['due_days_option'] ?? '5';
    $custom_due_days = (int)($_POST['custom_due_days'] ?? 0);

    if ($due_days_option === 'custom') {
        $due_days = $custom_due_days > 0 ? $custom_due_days : 0;
    } else {
        $due_days = (int)$due_days_option;
    }

    if (!in_array($due_days, [0, 5, 15, 20, 30], true) && $due_days_option !== 'custom') {
        $due_days = 5;
    }

    $due_date = calculatePurchaseDueDate($purchase_date, $due_days);

    // Round-off values from UI
    $round_off_enabled = (int)($_POST['round_off_enabled'] ?? 0);
    $round_off_amount  = (float)($_POST['round_off_amount'] ?? 0);
    $rounded_total     = (float)($_POST['rounded_total'] ?? 0);

    if ($manufacturer_id <= 0 || $shop_id <= 0 || empty($items)) {
        $error = "Please select supplier, receiving shop and add at least one product.";
    } else {
        try {
            $pdo->beginTransaction();

            // Use posted purchase number first; if empty/duplicate, generate from number_format_settings.
            $purchase_number = $posted_purchase_number;

            if ($purchase_number === '') {
                $purchase_number = generatePurchaseNumber($pdo, $current_business_id, $shop_id, $purchase_date);
            }

            $check_po = $pdo->prepare("
                SELECT id
                FROM purchases
                WHERE business_id = ?
                  AND purchase_number = ?
                LIMIT 1
            ");
            $check_po->execute([$current_business_id, $purchase_number]);

            if ($check_po->fetch()) {
                $purchase_number = generatePurchaseNumber($pdo, $current_business_id, $shop_id, $purchase_date);
            }

            // Handle bill image upload
            $bill_image_path = null;
            if (isset($_FILES['bill_image']) && $_FILES['bill_image']['error'] == UPLOAD_ERR_OK) {
                $file = $_FILES['bill_image'];
                $file_name = basename($file['name']);
                $file_tmp = $file['tmp_name'];
                $file_size = $file['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                $errors = [];
                if (!in_array($file_ext, $allowed_extensions) && $file_ext !== 'pdf') {
                    $errors[] = "Invalid file type. Only JPG, PNG, GIF, WEBP, PDF allowed.";
                }
                if ($file_size > $max_file_size) {
                    $errors[] = "File too large (max 10MB).";
                }

                if (empty($errors)) {
                    $unique_name = uniqid('bill_', true) . '_' . time() . '.' . $file_ext;
                    $bill_image_path = $upload_dir . $unique_name;

                    if (!move_uploaded_file($file_tmp, $bill_image_path)) {
                        $errors[] = "Upload failed.";
                    }
                }
                if (!empty($errors)) {
                    $error = implode("<br>", $errors);
                    throw new Exception($error);
                }
            }

            // Insert Purchase Record with business_id
            $stmt = $pdo->prepare("
                INSERT INTO purchases 
                (purchase_number, manufacturer_id, purchase_date, due_days, due_date, reference, shop_id,
                 purchase_invoice_no, bill_image, notes, 
                 total_amount, total_gst, payment_status, created_by, created_at, business_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'unpaid', ?, NOW(), ?)
            ");
            $stmt->execute([
                $purchase_number, 
                $manufacturer_id, 
                $purchase_date,
                $due_days,
                $due_date,
                $reference,
                $shop_id,
                $purchase_invoice_no ?: null,
                $bill_image_path,
                $notes, 
                $user_id, 
                $current_business_id
            ]);
            $purchase_id = $pdo->lastInsertId();

            $grand_total = 0;
            $total_gst   = 0;
            $total_credit_amount = 0;

            foreach ($items as $item) {
                $pid   = (int)($item['product_id'] ?? 0);
                $qty   = (int)($item['quantity'] ?? 0);

                // IMPORTANT:
                // Inclusive  : entered values are already FINAL values.
                // Exclusive  : entered values are WITHOUT GST, so final values must be calculated and stored.
                $entered_mrp            = (float)($item['mrp'] ?? 0);
                $entered_purchase_price = (float)($item['purchase_price'] ?? 0);

                // These values come from the selected-products cart UI.
                // They are already FINAL values after GST for exclusive items.
                // Inclusive items are already final as entered.
                $posted_retail_price    = (float)($item['retail_price'] ?? 0);
                $posted_wholesale_price = (float)($item['wholesale_price'] ?? 0);

                $mrp                    = $entered_mrp;
                $purchase_price         = $entered_purchase_price;

                $discount_input = trim($item['discount'] ?? '');
                $cgst  = (float)($item['cgst_rate'] ?? 0);
                $sgst  = (float)($item['sgst_rate'] ?? 0);
                $igst  = (float)($item['igst_rate'] ?? 0);
                $gst_type = strtolower(trim($item['gst_type'] ?? 'inclusive'));
                if (!in_array($gst_type, ['inclusive', 'exclusive'], true)) {
                    $gst_type = 'inclusive';
                }

                $batch_number = !empty($item['batch_number']) ? trim($item['batch_number']) : null;
                $expiry_date = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
                $manufacture_date = !empty($item['manufacture_date']) ? $item['manufacture_date'] : null;

                if ($pid > 0 && $qty > 0 && $entered_mrp >= 0 && $entered_purchase_price >= 0) {
                    // Get product details including markups
                    $product_stmt = $pdo->prepare("
                        SELECT p.*, 
                               COALESCE(g.cgst_rate, 0) as cgst_rate, 
                               COALESCE(g.sgst_rate, 0) as sgst_rate, 
                               COALESCE(g.igst_rate, 0) as igst_rate
                        FROM products p 
                        LEFT JOIN gst_rates g ON p.gst_id = g.id
                        WHERE p.id = ? AND p.business_id = ?
                    ");
                    $product_stmt->execute([$pid, $current_business_id]);
                    $product = $product_stmt->fetch();
                    
                    if (!$product) {
                        throw new Exception("Product not found");
                    }

                    // If item did not post gst_type correctly, fall back to product GST type.
                    if (empty($item['gst_type']) && !empty($product['gst_type']) && in_array($product['gst_type'], ['inclusive', 'exclusive'], true)) {
                        $gst_type = $product['gst_type'];
                    }

                    // GST calculation and FINAL value conversion.
                    // Rule:
                    // Inclusive  : entered values are already FINAL after-GST values.
                    // Exclusive  : entered values are WITHOUT GST, so calculate and store AFTER-GST values.
                    $total_gst_rate = $cgst + $sgst + $igst;
                    $entered_value  = $qty * $entered_purchase_price;

                    if ($gst_type === 'exclusive') {
                        // Purchase entered value is WITHOUT GST.
                        $taxable_amount = $entered_value;
                        $cgst_amt = $taxable_amount * $cgst / 100;
                        $sgst_amt = $taxable_amount * $sgst / 100;
                        $igst_amt = $taxable_amount * $igst / 100;

                        $total_with_tax = $entered_value + $cgst_amt + $sgst_amt + $igst_amt;

                        // Store AFTER-GST values in DB.
                        $purchase_price = $entered_purchase_price + ($entered_purchase_price * $total_gst_rate / 100);
                        $mrp            = $entered_mrp + ($entered_mrp * $total_gst_rate / 100);
                    } else {
                        // Inclusive entered values are already FINAL after-GST values.
                        $total_with_tax = $entered_value;
                        $taxable_amount = $total_gst_rate > 0
                            ? $entered_value / (1 + $total_gst_rate / 100)
                            : $entered_value;

                        $cgst_amt = $taxable_amount * $cgst / 100;
                        $sgst_amt = $taxable_amount * $sgst / 100;
                        $igst_amt = $taxable_amount * $igst / 100;

                        $purchase_price = $entered_purchase_price;
                        $mrp            = $entered_mrp;
                    }

                    // Calculate retail and wholesale from entered purchase value first.
                    // Then, for exclusive GST, store their AFTER-GST values.
                    $retail_base_price = $entered_purchase_price;
                    $wholesale_base_price = $entered_purchase_price;

                    if ((float)$product['retail_price_value'] > 0) {
                        if ($product['retail_price_type'] === 'percentage') {
                            $retail_base_price = $entered_purchase_price + ($entered_purchase_price * (float)$product['retail_price_value'] / 100);
                        } else {
                            $retail_base_price = $entered_purchase_price + (float)$product['retail_price_value'];
                        }
                    }

                    if ((float)$product['wholesale_price_value'] > 0) {
                        if ($product['wholesale_price_type'] === 'percentage') {
                            $wholesale_base_price = $entered_purchase_price + ($entered_purchase_price * (float)$product['wholesale_price_value'] / 100);
                        } else {
                            $wholesale_base_price = $entered_purchase_price + (float)$product['wholesale_price_value'];
                        }
                    }

                    if ($gst_type === 'exclusive') {
                        $retail_price = $retail_base_price + ($retail_base_price * $total_gst_rate / 100);
                        $wholesale_price = $wholesale_base_price + ($wholesale_base_price * $total_gst_rate / 100);
                    } else {
                        $retail_price = $retail_base_price;
                        $wholesale_price = $wholesale_base_price;
                    }

                    // Final safety: use UI calculated values when available.
                    // This fixes cases where the UI shows Retail/Wholesale after GST (e.g. 1180),
                    // but product markup fields in DB are 0, which would otherwise save purchase price value.
                    if ($posted_retail_price > 0) {
                        $retail_price = $posted_retail_price;
                    }
                    if ($posted_wholesale_price > 0) {
                        $wholesale_price = $posted_wholesale_price;
                    }

                    // Get HSN code for product
                    $hsn_stmt = $pdo->prepare("SELECT hsn_code FROM products WHERE id = ? AND business_id = ?");
                    $hsn_stmt->execute([$pid, $current_business_id]);
                    $hsn_code = $hsn_stmt->fetchColumn() ?? '';

                    // Insert Purchase Item with business_id
                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_items 
                        (purchase_id, product_id, quantity, mrp, discount, discount_type, discount_value,
                         purchase_price, retail_price, wholesale_price, hsn_code, gst_type,
                         cgst_rate, sgst_rate, igst_rate, 
                         cgst_amount, sgst_amount, igst_amount, total_price, business_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $purchase_id, 
                        $pid, 
                        $qty, 
                        $mrp,
                        $discount_input,
                        'percentage',
                        (float)str_replace('%', '', $discount_input) ?: 0,
                        $purchase_price,
                        $retail_price,
                        $wholesale_price,
                        $hsn_code,
                        $gst_type,
                        $cgst, 
                        $sgst, 
                        $igst,
                        $cgst_amt, 
                        $sgst_amt, 
                        $igst_amt,
                        $total_with_tax, 
                        $current_business_id
                    ]);
                    $purchase_item_id = $pdo->lastInsertId();

                    $grand_total += $total_with_tax;
                    $total_gst   += $cgst_amt + $sgst_amt + $igst_amt;
                    $total_credit_amount += $cgst_amt + $sgst_amt + $igst_amt;

                    // Check if price has changed from current product stock price
                    $price_changed = false;
                    $is_increase = false;
                    $is_decrease = false;
                    
                    if (abs($purchase_price - $product['stock_price']) > 0.01) {
                        $price_changed = true;
                        if ($purchase_price > $product['stock_price']) {
                            $is_increase = true;
                        } else {
                            $is_decrease = true;
                        }
                    }
                    
                    // Check if MRP has changed
                    $mrp_changed = false;
                    if (abs($mrp - $product['mrp']) > 0.01) {
                        $mrp_changed = true;
                    }
                    
                    // Check if retail/wholesale prices have changed
                    $retail_changed = abs($retail_price - $product['retail_price']) > 0.01;
                    $wholesale_changed = abs($wholesale_price - $product['wholesale_price']) > 0.01;
                    
                    // Get old quantity from product_stocks table before update
                    $stock_check = $pdo->prepare("
                        SELECT id, quantity, old_qty, total_secondary_units 
                        FROM product_stocks 
                        WHERE product_id = ? AND shop_id = ? AND business_id = ?
                    ");
                    $stock_check->execute([$pid, $shop_id, $current_business_id]);
                    $stock_record = $stock_check->fetch();
                    
                    $old_qty = 0;
                    $current_quantity = 0;
                    if ($stock_record) {
                        $old_qty = $stock_record['quantity'];
                        $current_quantity = $stock_record['quantity'];
                    }

                    // Get previous batch prices for comparison
                    $prev_batch_stmt = $pdo->prepare("
                        SELECT purchase_price, selling_price, retail_price, wholesale_price,
                               old_retail_price, old_wholesale_price
                        FROM purchase_batches 
                        WHERE product_id = ? AND business_id = ?
                        ORDER BY received_date DESC, id DESC
                        LIMIT 1
                    ");
                    $prev_batch_stmt->execute([$pid, $current_business_id]);
                    $prev_batch = $prev_batch_stmt->fetch();

                    // Get the current product prices as fallback for old prices
                    $old_purchase_price = $prev_batch ? $prev_batch['purchase_price'] : $product['stock_price'];
                    $old_retail_price = $prev_batch ? $prev_batch['retail_price'] : $product['retail_price'];
                    $old_wholesale_price = $prev_batch ? $prev_batch['wholesale_price'] : $product['wholesale_price'];
                    $old_selling_price = $prev_batch ? $prev_batch['selling_price'] : $product['retail_price'];

                    // Create purchase batch record with all price fields
                    $batch_number = $batch_number ?: 'BATCH-' . date('Ymd') . '-' . str_pad($purchase_item_id, 4, '0', STR_PAD_LEFT);
                    
                    $batch_stmt = $pdo->prepare("
                        INSERT INTO purchase_batches 
                        (business_id, product_id, purchase_id, shop_id, batch_number, 
                         purchase_price, old_purchase_price,
                         selling_price, old_selling_price,
                         old_mrp, new_mrp,
                         retail_price, old_retail_price,
                         wholesale_price, old_wholesale_price,
                         gst_type,
                         quantity_received, quantity_remaining,
                         received_date, manufacture_date, expiry_date, notes, 
                         is_increase, is_decrease, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $batch_stmt->execute([
                        $current_business_id,
                        $pid,
                        $purchase_id,
                        $shop_id,
                        $batch_number,
                        $purchase_price,
                        $old_purchase_price,
                        $retail_price,
                        $old_selling_price,
                        $product['mrp'],
                        $mrp,
                        $retail_price,
                        $old_retail_price,
                        $wholesale_price,
                        $old_wholesale_price,
                        $gst_type,
                        $qty,
                        $qty,
                        $purchase_date,
                        $manufacture_date,
                        $expiry_date,
                        'Batch from purchase ' . $purchase_number,
                        $is_increase ? 1 : 0,
                        $is_decrease ? 1 : 0
                    ]);
                    
                    $batch_id = $pdo->lastInsertId();

                    // Update Stock in Correct Shop
                    $total_secondary_units = null;
                    if ($product['sec_unit_conversion'] && $product['sec_unit_conversion'] > 0) {
                        $total_secondary_units = $qty * $product['sec_unit_conversion'];
                    }

                    // Determine if this is the first stock for this product in this shop
                    $is_first_stock = (!$stock_record || $current_quantity == 0);

                    if ($stock_record) {
                        // Update existing stock
                        $new_quantity = $stock_record['quantity'] + $qty;
                        $new_secondary_units = $stock_record['total_secondary_units'];
                        
                        if ($total_secondary_units !== null) {
                            $new_secondary_units = ($new_secondary_units ?? 0) + $total_secondary_units;
                        }
                        
                        $use_batch_tracking = 1;
                        
                        $update_query = "
                            UPDATE product_stocks 
                            SET quantity = ?, 
                                old_qty = ?,
                                total_secondary_units = ?,
                                use_batch_tracking = ?,
                                batch_id = ?,
                                last_updated = NOW()
                            WHERE product_id = ? AND shop_id = ? AND business_id = ?
                        ";
                        
                        $pdo->prepare($update_query)->execute([
                            $new_quantity,
                            $old_qty,
                            $new_secondary_units,
                            $use_batch_tracking,
                            $batch_id,
                            $pid,
                            $shop_id,
                            $current_business_id
                        ]);
                        
                        if ($is_first_stock) {
                            // Update product prices directly from batch when stock was 0
                            $update_product_first_stock = $pdo->prepare("
                                UPDATE products 
                                SET mrp = ?,
                                    stock_price = ?,
                                    retail_price = ?,
                                    wholesale_price = ?,
                                    gst_type = ?,
                                    updated_at = NOW()
                                WHERE id = ? AND business_id = ?
                            ");
                            
                            $update_product_first_stock->execute([
                                $mrp,
                                $purchase_price,
                                $retail_price,
                                $wholesale_price,
                                $gst_type,
                                $pid,
                                $current_business_id
                            ]);
                        }
                    } else {
                        // Insert new stock record
                        $insert_query = "
                            INSERT INTO product_stocks 
                            (product_id, shop_id, business_id, quantity, 
                             old_qty, total_secondary_units, use_batch_tracking,
                             batch_id, last_updated) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ";
                        
                        $pdo->prepare($insert_query)->execute([
                            $pid,
                            $shop_id,
                            $current_business_id,
                            $qty,
                            0,
                            $total_secondary_units,
                            1,
                            $batch_id
                        ]);
                        
                        // Update product prices directly from batch
                        $update_product_new_stock = $pdo->prepare("
                            UPDATE products 
                            SET mrp = ?,
                                stock_price = ?,
                                retail_price = ?,
                                wholesale_price = ?,
                                gst_type = ?,
                                updated_at = NOW()
                            WHERE id = ? AND business_id = ?
                        ");
                        
                        $update_product_new_stock->execute([
                            $mrp,
                            $purchase_price,
                            $retail_price,
                            $wholesale_price,
                            $gst_type,
                            $pid,
                            $current_business_id
                        ]);
                    }

                    // Update product retail and wholesale prices if price changed (increase OR decrease).
                    // For zero-stock products we already updated mrp, stock_price, retail, wholesale and gst_type above.
                    if (($price_changed || $mrp_changed) && !$is_first_stock) {
                        $update_fields = [];
                        $update_params = [];
                        
                        // Always update MRP if changed (whether increase or decrease)
                        if ($mrp_changed) {
                            $update_fields[] = "mrp = ?";
                            $update_params[] = $mrp;
                        }
                        
                        // Update retail price if changed (whether increase or decrease)
                        if ($retail_changed) {
                            $update_fields[] = "retail_price = ?";
                            $update_params[] = $retail_price;
                        }
                        
                        // Update wholesale price if changed (whether increase or decrease)
                        if ($wholesale_changed) {
                            $update_fields[] = "wholesale_price = ?";
                            $update_params[] = $wholesale_price;
                        }
                        
                        if (!empty($update_fields)) {
                            $update_fields[] = "updated_at = NOW()";
                            $update_query = "UPDATE products SET " . implode(", ", $update_fields) . " WHERE id = ? AND business_id = ?";
                            $update_params[] = $pid;
                            $update_params[] = $current_business_id;
                            
                            $pdo->prepare($update_query)->execute($update_params);
                            
                            // Log the price change (optional - for debugging)
                            error_log("Product ID $pid prices updated - MRP: $mrp, Retail: $retail_price, Wholesale: $wholesale_price");
                        }
                    }
                }
            }

            // Update Final Totals in purchases table
            // If round off is enabled, save the rounded total as purchase total_amount.
            $final_total_to_save = $grand_total;
            if ($round_off_enabled === 1 && $rounded_total > 0) {
                $final_total_to_save = $rounded_total;
            }

            $pdo->prepare("
                UPDATE purchases 
                SET total_amount = ?, total_gst = ?, paid_amount = 0 
                WHERE id = ? AND business_id = ?
            ")->execute([$final_total_to_save, $total_gst, $purchase_id, $current_business_id]);

            // Create GST credit record
            if ($total_credit_amount > 0) {
                $gstmt = $pdo->prepare("
                    INSERT INTO gst_credits 
                    (business_id, purchase_id, purchase_number, purchase_invoice_no, 
                     credit_amount, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'not_claimed', NOW())
                ");
                $gstmt->execute([
                    $current_business_id,
                    $purchase_id,
                    $purchase_number,
                    $purchase_invoice_no ?: null,
                    $total_credit_amount
                ]);
            }

            $pdo->commit();
            
            header("Location: purchases.php?success=1&po=" . urlencode($purchase_number));
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            if (isset($bill_image_path) && file_exists($bill_image_path)) {
                @unlink($bill_image_path);
            }
            $error = "Failed to save purchase: " . $e->getMessage();
        }
    }
}

// Get products with shop and warehouse stock - ONLY FOR THIS BUSINESS
$prodSql = "SELECT p.id, p.product_name, p.product_code, p.barcode,
                   p.stock_price, p.mrp, p.retail_price, p.wholesale_price,
                   p.retail_price_type, p.retail_price_value,
                   p.wholesale_price_type, p.wholesale_price_value,
                   p.secondary_unit, p.sec_unit_conversion,
                   p.hsn_code, p.gst_type, 
                   COALESCE(g.cgst_rate, 0) as cgst_rate, 
                   COALESCE(g.sgst_rate, 0) as sgst_rate, 
                   COALESCE(g.igst_rate, 0) as igst_rate,
                   c.category_name,
                   COALESCE(ps_shop.quantity, 0) as shop_stock,
                   COALESCE(ps_shop.old_qty, 0) as shop_old_qty,
                   COALESCE(ps_shop.total_secondary_units, 0) as shop_secondary_units,
                   COALESCE(ps_shop.use_batch_tracking, 0) as use_batch_tracking,
                   COALESCE(ps_warehouse.quantity, 0) as warehouse_stock,
                   COALESCE(ps_warehouse.total_secondary_units, 0) as warehouse_secondary_units,
                   (
                       SELECT purchase_price 
                       FROM purchase_batches pb 
                       WHERE pb.product_id = p.id 
                         AND pb.business_id = p.business_id
                         AND pb.quantity_remaining > 0
                       ORDER BY pb.received_date DESC 
                       LIMIT 1
                   ) as last_batch_price,
                   (
                       SELECT retail_price 
                       FROM purchase_batches pb 
                       WHERE pb.product_id = p.id 
                         AND pb.business_id = p.business_id
                         AND pb.quantity_remaining > 0
                       ORDER BY pb.received_date DESC 
                       LIMIT 1
                   ) as last_batch_retail_price,
                   (
                       SELECT wholesale_price 
                       FROM purchase_batches pb 
                       WHERE pb.product_id = p.id 
                         AND pb.business_id = p.business_id
                         AND pb.quantity_remaining > 0
                       ORDER BY pb.received_date DESC 
                       LIMIT 1
                   ) as last_batch_wholesale_price,
                   (
                       SELECT old_mrp 
                       FROM purchase_batches pb 
                       WHERE pb.product_id = p.id 
                         AND pb.business_id = p.business_id
                         AND pb.quantity_remaining > 0
                       ORDER BY pb.received_date DESC 
                       LIMIT 1
                   ) as last_batch_old_mrp,
                   (
                       SELECT new_mrp 
                       FROM purchase_batches pb 
                       WHERE pb.product_id = p.id 
                         AND pb.business_id = p.business_id
                         AND pb.quantity_remaining > 0
                       ORDER BY pb.received_date DESC 
                       LIMIT 1
                   ) as last_batch_new_mrp
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id AND c.business_id = p.business_id
            LEFT JOIN gst_rates g ON p.gst_id = g.id AND g.business_id = p.business_id
            LEFT JOIN product_stocks ps_shop ON p.id = ps_shop.product_id 
                AND ps_shop.shop_id = ? AND ps_shop.business_id = p.business_id
            LEFT JOIN product_stocks ps_warehouse ON p.id = ps_warehouse.product_id 
                AND ps_warehouse.shop_id = ? AND ps_warehouse.business_id = p.business_id
            WHERE p.is_active = 1 AND p.business_id = ?
            ORDER BY c.category_name, p.product_name";

$prodStmt = $pdo->prepare($prodSql);
$prodStmt->execute([$shop_id, $warehouse_id, $current_business_id]);
$prodRes = $prodStmt->fetchAll();

$jsProducts = [];
$barcodeMap = [];

foreach ($prodRes as $p) {
    $pid = (int)$p['id'];
    $name = htmlspecialchars($p['product_name']);
    $mrp = (float)$p['mrp'];
    $stock_price = (float)$p['stock_price'];
    $retail_price = (float)$p['retail_price'];
    $wholesale_price = (float)$p['wholesale_price'];
    $retail_price_type = $p['retail_price_type'];
    $retail_price_value = (float)$p['retail_price_value'];
    $wholesale_price_type = $p['wholesale_price_type'];
    $wholesale_price_value = (float)$p['wholesale_price_value'];
    $code = $p['product_code'] ? htmlspecialchars($p['product_code']) : sprintf('P%06d', $pid);
    $barcode = htmlspecialchars($p['barcode'] ?? '');
    $shop_stock = (int)$p['shop_stock'];
    $shop_old_qty = (int)$p['shop_old_qty'];
    $warehouse_stock = (int)$p['warehouse_stock'];
    $total_stock = $shop_stock + $warehouse_stock;
    $shop_secondary = (float)$p['shop_secondary_units'];
    $warehouse_secondary = (float)$p['warehouse_secondary_units'];
    $secondary_unit = htmlspecialchars($p['secondary_unit'] ?? '');
    $sec_unit_conversion = (float)$p['sec_unit_conversion'];
    $last_batch_price = (float)$p['last_batch_price'];
    $last_batch_retail_price = (float)$p['last_batch_retail_price'];
    $last_batch_wholesale_price = (float)$p['last_batch_wholesale_price'];
    $last_batch_old_mrp = (float)$p['last_batch_old_mrp'];
    $last_batch_new_mrp = (float)$p['last_batch_new_mrp'];
    $use_batch_tracking = (int)$p['use_batch_tracking'];
    $hsn = htmlspecialchars($p['hsn_code'] ?? '');
    $cgst = (float)($p['cgst_rate'] ?? 0);
    $sgst = (float)($p['sgst_rate'] ?? 0);
    $igst = (float)($p['igst_rate'] ?? 0);
    $total_gst = $cgst + $sgst + $igst;
    $category = htmlspecialchars($p['category_name'] ?? 'Uncategorized');
    
    // Calculate suggested discount based on MRP and stock price
    $suggested_discount = '';
    if ($mrp > 0 && $stock_price > 0 && $mrp > $stock_price) {
        $discount_percent = (($mrp - $stock_price) / $mrp) * 100;
        if ($discount_percent > 0) {
            $suggested_discount = round($discount_percent, 1) . '%';
        }
    }
    
    // Calculate retail markup percentage
    $retail_markup_percent = 0;
    if ($stock_price > 0 && $retail_price > $stock_price) {
        $retail_markup_percent = (($retail_price - $stock_price) / $stock_price) * 100;
    }
    
    // Calculate wholesale markup percentage
    $wholesale_markup_percent = 0;
    if ($stock_price > 0 && $wholesale_price > $stock_price) {
        $wholesale_markup_percent = (($wholesale_price - $stock_price) / $stock_price) * 100;
    }
    
    $jsProducts[$pid] = [
        'id' => $pid,
        'name' => $name,
        'mrp' => $mrp,
        'stock_price' => $stock_price,
        'retail_price' => $retail_price,
        'wholesale_price' => $wholesale_price,
        'retail_price_type' => $retail_price_type,
        'retail_price_value' => $retail_price_value,
        'retail_markup_percent' => $retail_markup_percent,
        'wholesale_price_type' => $wholesale_price_type,
        'wholesale_price_value' => $wholesale_price_value,
        'wholesale_markup_percent' => $wholesale_markup_percent,
        'suggested_discount' => $suggested_discount,
        'last_batch_price' => $last_batch_price,
        'last_batch_retail_price' => $last_batch_retail_price,
        'last_batch_wholesale_price' => $last_batch_wholesale_price,
        'last_batch_old_mrp' => $last_batch_old_mrp,
        'last_batch_new_mrp' => $last_batch_new_mrp,
        'use_batch_tracking' => $use_batch_tracking,
        'shop_old_qty' => $shop_old_qty,
        'code' => $code,
        'barcode' => $barcode,
        'shop_stock' => $shop_stock,
        'warehouse_stock' => $warehouse_stock,
        'total_stock' => $total_stock,
        'shop_secondary' => $shop_secondary,
        'warehouse_secondary' => $warehouse_secondary,
        'secondary_unit' => $secondary_unit,
        'sec_unit_conversion' => $sec_unit_conversion,
        'hsn' => $hsn,
        'gst_type' => in_array(($p['gst_type'] ?? 'inclusive'), ['inclusive', 'exclusive'], true) ? $p['gst_type'] : 'inclusive',
        'cgst' => $cgst,
        'sgst' => $sgst,
        'igst' => $igst,
        'total_gst' => $total_gst,
        'category' => $category
    ];
    
    if ($p['barcode']) $barcodeMap[$p['barcode']] = $pid;
    $barcodeMap[$code] = $pid;
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = "New Purchase Order"; include 'includes/head.php'; ?>
<!-- Add SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Add Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<!-- Add flatpickr for date picker -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
/* Scrollable purchase items section */
.purchase-items-container {
    overflow-y: visible;
    padding-right: 0;
}

/* Product Search Section */
.product-search-section {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}
.product-search-section h5 {
    color: #495057;
    font-size: 1rem;
    margin-bottom: 15px;
    font-weight: 600;
}

/* Stock badges */
.stock-badge {
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 3px;
    margin-right: 3px;
}
.shop-stock-badge {
    background: #17a2b8;
    color: white;
}
.warehouse-stock-badge {
    background: #6c757d;
    color: white;
}
.low-stock-badge {
    background: #dc3545;
    color: white;
}
.out-of-stock-badge {
    background: #343a40;
    color: white;
}
.gst-badge {
    background: #6f42c1;
    color: white;
}
.secondary-unit-badge {
    background: #20c997;
    color: white;
}
.price-increase-badge {
    background: #28a745;
    color: white;
}
.price-decrease-badge {
    background: #dc3545;
    color: white;
}

/* Batch info section */
.batch-info-section {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 6px;
    padding: 10px;
    margin-top: 10px;
    display: none;
}
.batch-info-section.show {
    display: block;
}

/* Product details card */
.product-details-card {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 12px;
    margin-top: 10px;
    display: none;
}
.product-details-card.show {
    display: block;
}

/* Selected products table */
.selected-products-table {
    font-size: 0.875rem;
}
.selected-products-table th {
    background: #f8f9fa;
    font-weight: 600;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 10;
}
.selected-products-table td {
    vertical-align: middle;
}

/* Price calculation section */
.price-calculation-section {
    background: #e7f4ff;
    border: 1px solid #b6e0fe;
    border-radius: 6px;
    padding: 10px;
    margin-top: 10px;
}
.price-calculation-section input[readonly] {
    background-color: #f8f9fa;
    cursor: not-allowed;
}

/* Select2 custom styling */
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
    border-radius: 0.375rem;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
    font-size: 0.875rem;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}
.select2-results__option {
    padding: 8px 12px;
    font-size: 0.875rem;
}
.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #0d6efd;
    color: white;
}
.select2-container--open .select2-dropdown--below {
    border-radius: 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

/* Product option in dropdown */
.product-option {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
}
.product-name {
    font-weight: 500;
    color: #333;
    font-size: 0.9rem;
}
.product-code {
    font-size: 0.8rem;
    color: #666;
}
.product-price {
    font-weight: 600;
    color: #dc3545;
    font-size: 0.85rem;
}
.product-stock-display {
    display: flex;
    gap: 5px;
    margin-top: 3px;
}

/* General styles */
.item-row {
    transition: all 0.3s ease;
}
.item-row:hover {
    background-color: #f8f9fa;
}

/* Price change warning */
.price-change-warning {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    padding: 8px;
    margin-top: 5px;
    font-size: 0.8rem;
}
.price-increase-warning {
    background: #d4edda;
    border-color: #28a745;
    color: #155724;
}
.price-decrease-warning {
    background: #f8d7da;
    border-color: #dc3545;
    color: #721c24;
}

/* Bill upload section */
.bill-upload-section {
    background: #f0f9ff;
    border: 2px dashed #0dcaf0;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
}
.bill-upload-section:hover {
    background: #e7f4ff;
    border-color: #0d6efd;
}
.bill-preview {
    max-width: 200px;
    max-height: 200px;
    margin: 10px auto;
    display: none;
}
.bill-preview img, .bill-preview embed {
    max-width: 100%;
    max-height: 200px;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

/* Scrollbar styling */
.purchase-items-container::-webkit-scrollbar {
    width: 8px;
}
.purchase-items-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}
.purchase-items-container::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}
.purchase-items-container::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Price details styling */
.price-details {
    font-size: 0.8rem;
    margin-top: 5px;
}
.price-details-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 2px;
}
.price-details-label {
    color: #666;
}
.price-details-value {
    font-weight: 500;
}
.markup-badge {
    font-size: 0.7rem;
    padding: 1px 4px;
    border-radius: 2px;
    background: #20c997;
    color: white;
    margin-left: 3px;
}

/* Manual purchase price input */
.manual-price-input {
    border-left: 3px solid #007bff !important;
}
.manual-price-input:focus {
    border-left-width: 4px !important;
    border-left-color: #0056b3 !important;
}

/* Quick Add Modal */
.quick-add-modal .modal-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}
.quick-add-modal .modal-title i {
    color: #0d6efd;
}
.quick-add-modal .form-label {
    font-weight: 500;
    color: #495057;
}
.quick-add-modal .form-text {
    font-size: 0.8rem;
    color: #6c757d;
}

/* SweetAlert2 small toast styling */
.swal-toast-small {
    font-size: 0.875rem !important;
}
.swal-toast-small .swal2-popup {
    font-size: 0.875rem !important;
    padding: 0.5rem !important;
    width: auto !important;
    min-width: 250px !important;
}
.swal-toast-small .swal2-title {
    font-size: 1rem !important;
    margin: 0 !important;
    padding: 0 0 0.25rem 0 !important;
}
.swal-toast-small .swal2-html-container {
    font-size: 0.875rem !important;
    margin: 0 !important;
    padding: 0 !important;
}
.swal-toast-small .swal2-actions {
    margin: 0.25rem 0 0 0 !important;
}
.swal-toast-small .swal2-confirm,
.swal-toast-small .swal2-cancel {
    font-size: 0.75rem !important;
    padding: 0.25rem 0.5rem !important;
}

/* GST base/final value chips */
.gst-value-hint {
    margin-top: 6px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    font-size: 0.76rem;
    line-height: 1.2;
}
.gst-value-hint .base-chip,
.gst-value-hint .final-chip,
.gst-value-hint .gst-chip {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 3px 7px;
    border-radius: 999px;
    border: 1px solid #d8e2ef;
    background: #fff;
    color: #495057;
    white-space: nowrap;
}
.gst-value-hint .base-chip {
    background: #fff8e1;
    border-color: #ffe08a;
    color: #7a5700;
}
.gst-value-hint .final-chip {
    background: #e7f4ff;
    border-color: #b6e0fe;
    color: #074f7a;
    font-weight: 600;
}
.gst-value-hint .gst-chip {
    background: #eef9f1;
    border-color: #b9e6c5;
    color: #176b2c;
}
.gst-summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
}
.gst-summary-cell {
    background: #fff;
    border: 1px solid #d8e2ef;
    border-radius: 8px;
    padding: 8px 10px;
}
.gst-summary-cell small {
    display: block;
    color: #6c757d;
    font-size: 0.72rem;
}
.gst-summary-cell strong {
    display: block;
    margin-top: 2px;
    font-size: 0.9rem;
}


/* Compact purchase page layout */
#purchaseForm .row.g-4 { --bs-gutter-y: .75rem; }
.card { margin-bottom: .75rem; }
.card-header { padding: .55rem .85rem; }
.card-header h5 { font-size: .96rem; }
.card-body { padding: .85rem; }
.product-search-section { padding: .75rem; margin-bottom: .75rem; }
.product-search-section .d-flex.mb-3 { margin-bottom: .55rem !important; }
.product-search-section h5 { font-size: .9rem; margin-bottom: 0; }
.form-label { font-size: .78rem; margin-bottom: .22rem; }
.form-control, .form-select { min-height: 32px; height: 32px; padding: .28rem .48rem; font-size: .82rem; }
textarea.form-control { height: auto; min-height: 32px; }
.select2-container--default .select2-selection--single { height: 32px; min-height: 32px; }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 30px; font-size: .82rem; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height: 30px; }
.price-calculation-section, .batch-info-section, .product-details-card { padding: .6rem; margin-top: .5rem; }
.price-calculation-section .row, .batch-info-section .row { --bs-gutter-y: .45rem; --bs-gutter-x: .6rem; }
.gst-value-hint { margin-top: 3px; gap: 3px; font-size: .68rem; }
.gst-value-hint .base-chip, .gst-value-hint .final-chip, .gst-value-hint .gst-chip { padding: 2px 5px; }
.gst-summary-grid { gap: 5px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
.gst-summary-cell { padding: 5px 7px; border-radius: 6px; }
.gst-summary-cell small { font-size: .66rem; }
.gst-summary-cell strong { font-size: .78rem; margin-top: 0; }
#gstBreakupBox { padding: .35rem .45rem !important; }
.bill-upload-section { padding: .75rem; }
.bill-upload-section .fs-1 { font-size: 1.45rem !important; margin-bottom: .25rem !important; }
.bill-upload-section p { font-size: .8rem; }
.bill-preview { max-width: 140px; max-height: 110px; margin: 5px auto 0; }
.bill-preview img, .bill-preview embed { max-height: 110px; }
.selected-products-table { font-size: .78rem; }
.selected-products-table th, .selected-products-table td { padding: .38rem .45rem; }
.alert { padding: .55rem .75rem; margin-bottom: .75rem; }
.btn { padding: .32rem .65rem; font-size: .82rem; }
.btn-lg { padding: .45rem 1.25rem; font-size: .9rem; }
.page-title-box { margin-bottom: .5rem; }
.page-content { padding-bottom: .5rem; }
@media (max-width: 767.98px) {
    .gst-summary-grid { grid-template-columns: 1fr 1fr; }
}

</style>
</head>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
        </div>
    </div>

    <div class="main-content mb-5">
        <div class="page-content mb-4">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-shopping-bag me-2"></i> New Purchase Order
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-store me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_shop_name'] ?? 'All Shops') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">Business: <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'N/A') ?></p>
                            </div>
                            <a href="purchases.php" class="btn btn-outline-secondary">
                                <i class="bx bx-arrow-back me-1"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>

                <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bx bx-check-circle me-2"></i>
                    Purchase <strong><?= htmlspecialchars($_GET['po'] ?? 'Order') ?></strong> created successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bx bx-error me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" id="purchaseForm" enctype="multipart/form-data">
                    <input type="hidden" name="round_off_enabled" id="round_off_enabled" value="0">
                    <input type="hidden" name="round_off_amount" id="round_off_amount" value="0">
                    <input type="hidden" name="rounded_total" id="rounded_total" value="0">
                    <div class="row g-4">
                        <!-- Purchase Details Card -->
                        <div class="col-12">
                            <div class="card card-hover border-start border-primary border-4 shadow-sm h-100">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">
                                        <i class="bx bx-detail me-2"></i> Purchase Details
                                    </h5>
                                </div>
                                <div class="card-body">
    <div class="row g-2">
        <div class="col-md-3">
            <label class="form-label fw-bold">Purchase Number</label>
            <input type="text"
                   name="purchase_number"
                   id="purchase_number"
                   class="form-control bg-light"
                   value="<?= htmlspecialchars($purchase_number) ?>"
                   readonly>
        </div>

        <div class="col-md-3">
            <label class="form-label fw-bold">Purchase Date <span class="text-danger">*</span></label>
            <input type="date" name="purchase_date" id="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="col-md-3">
            <label class="form-label fw-bold">Due Days</label>
            <select name="due_days_option" id="due_days_option" class="form-select">
                <option value="5" selected>5 Days</option>
                <option value="15">15 Days</option>
                <option value="20">20 Days</option>
                <option value="30">30 Days</option>
                <option value="custom">Custom</option>
            </select>
        </div>

        <div class="col-md-3" id="custom_due_days_box" style="display:none;">
            <label class="form-label fw-bold">Custom Due Days</label>
            <input type="number" name="custom_due_days" id="custom_due_days" class="form-control" min="1" step="1" placeholder="Enter days">
        </div>

        <div class="col-md-3">
            <label class="form-label fw-bold">Due Date</label>
            <input type="date" name="due_date" id="due_date" class="form-control bg-light" value="<?= date('Y-m-d', strtotime('+5 days')) ?>" readonly>
        </div>

        <div class="col-md-3">
            <label class="form-label fw-bold">Supplier <span class="text-danger">*</span></label>
            <select name="manufacturer_id" class="form-select select2-supplier" required>
                <option value="">-- Select Supplier --</option>
                <?php foreach ($manufacturers as $m): ?>
                    <option value="<?= $m['id'] ?>">
                        <?= htmlspecialchars($m['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label fw-bold">Receive Stock At <span class="text-danger">*</span></label>
            <select name="shop_id" class="form-select select2-shop" required>
                <option value="">-- Select Location --</option>
                <?php foreach ($shops as $shop): ?>
                    <option value="<?= $shop['id'] ?>">
                        <?= htmlspecialchars($shop['shop_name']) ?>
                        <?= $shop['is_warehouse'] ? ' (Warehouse)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label fw-bold">Purchase Invoice No.</label>
            <input type="text" name="purchase_invoice_no" class="form-control" placeholder="Supplier's invoice number">
        </div>

        <div class="col-md-4">
            <label class="form-label fw-bold">Bill/Reference No.</label>
            <input type="text" name="reference" class="form-control" placeholder="Optional">
        </div>

        <div class="col-4">
            <label class="form-label">Notes (Optional)</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Any special instructions..."></textarea>
        </div>

        <div class="col-12">
            <label class="form-label fw-bold">Bill Image (Optional)</label>
            <div class="bill-upload-section w-100" onclick="document.getElementById('billImage').click()">
                <i class="bx bx-cloud-upload fs-1 text-primary mb-3"></i>
                <p class="mb-1">Click to upload bill image</p>
                <p class="text-muted small mb-0">Supports: JPG, PNG, GIF, WEBP, PDF (Max 10MB)</p>
                <input type="file" name="bill_image" id="billImage" class="d-none" accept="image/*,.pdf">
                <div id="billPreview" class="bill-preview"></div>
            </div>
        </div>
    </div>
</div>
                            </div>
                        </div>

                        <!-- Products Section -->
                        <div class="col-12">
                            <div class="card shadow-sm h-100">
                                <div class="card-header bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <i class="bx bx-package me-2"></i> Purchase Items
                                        </h5>
                                        <span class="badge bg-primary" id="itemCount">0 Items</span>
                                    </div>
                                </div>
                                <div class="card-body purchase-items-container">
                                    <!-- Product Search Section -->
                                    <div class="product-search-section">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h5><i class="bx bx-search me-2"></i> Add Products</h5>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openQuickAddProductModal()">
                                                <i class="bx bx-plus me-1"></i> Quick Add Product
                                            </button>
                                        </div>
                                        
                                        <div class="row g-2">
                                            <div class="col-md-12">
                                                <label class="form-label">Search Product</label>
                                                <select id="productSelect" class="form-control select2-products">
                                                   <option value=""></option>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label class="form-label">Total Stock</label>
                                                <input type="text" id="stockDisplay" class="form-control bg-white" readonly value="0">
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label class="form-label">MRP <span class="text-danger">*</span></label>
                                                <input type="number" step="0.01" min="0" id="mrp" class="form-control" value="0" required>
                                                <div id="mrpGstHint" class="gst-value-hint">
                                                    <span class="base-chip">Without GST: ₹0.00</span>
                                                    <span class="final-chip">Final: ₹0.00</span>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label class="form-label">Discount</label>
                                                <input type="text" id="discount" class="form-control" placeholder="e.g., 30% or 100">
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label class="form-label">Purchase Price <span class="text-danger">*</span></label>
                                                <input type="number" step="0.01" min="0" id="purchasePrice" class="form-control manual-price-input" value="0" required>
                                                <small class="text-muted">Manual entry - discount will auto-calculate</small>
                                                <div id="purchasePriceGstHint" class="gst-value-hint">
                                                    <span class="base-chip">Without GST: ₹0.00</span>
                                                    <span class="gst-chip">GST: ₹0.00</span>
                                                    <span class="final-chip">Final: ₹0.00</span>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                                <input type="number" id="quantity" class="form-control" min="1" value="1" required>
                                            </div>
                                            
                                            <!-- Price Calculation Section -->
                                            <div class="col-md-12">
                                                <div id="priceCalculation" class="price-calculation-section" style="display:none;">
                                                    <div class="row g-2">
                                                        <div class="col-md-4">
                                                            <label class="form-label small">Calculated Purchase Price</label>
                                                            <input type="number" step="0.01" id="calculatedPurchasePrice" class="form-control bg-light" readonly>
                                                            <div id="calculatedPurchasePriceGstHint" class="gst-value-hint">
                                                                <span class="base-chip">Without GST: ₹0.00</span>
                                                                <span class="final-chip">Final: ₹0.00</span>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label small">Retail Price <span id="retailMarkupBadge"></span></label>
                                                            <input type="number" step="0.01" id="retailPrice" class="form-control bg-light" readonly>
                                                            <div id="retailPriceGstHint" class="gst-value-hint">
                                                                <span class="base-chip">Without GST: ₹0.00</span>
                                                                <span class="final-chip">Final: ₹0.00</span>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label small">Wholesale Price <span id="wholesaleMarkupBadge"></span></label>
                                                            <input type="number" step="0.01" id="wholesalePrice" class="form-control bg-light" readonly>
                                                            <div id="wholesalePriceGstHint" class="gst-value-hint">
                                                                <span class="base-chip">Without GST: ₹0.00</span>
                                                                <span class="final-chip">Final: ₹0.00</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="price-details mt-2" id="priceDetails" style="display:none;">
                                                        <div class="price-details-item">
                                                            <span class="price-details-label">Retail Markup:</span>
                                                            <span class="price-details-value" id="retailMarkupDisplay">0%</span>
                                                        </div>
                                                        <div class="price-details-item">
                                                            <span class="price-details-label">Wholesale Markup:</span>
                                                            <span class="price-details-value" id="wholesaleMarkupDisplay">0%</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Tax Rates -->
                                            <div class="col-md-12">
                                                <div class="row g-2 align-items-end compact-tax-row">
                                                    <div class="col-md-2 col-6">
                                                        <label class="form-label small">GST Type</label>
                                                        <select id="gstType" class="form-select">
                                                            <option value="inclusive">Inclusive</option>
                                                            <option value="exclusive">Exclusive</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2 col-6">
                                                        <label class="form-label small">CGST %</label>
                                                        <input type="number" step="0.01" id="cgstRate" class="form-control" value="0">
                                                    </div>
                                                    <div class="col-md-2 col-6">
                                                        <label class="form-label small">SGST %</label>
                                                        <input type="number" step="0.01" id="sgstRate" class="form-control" value="0">
                                                    </div>
                                                    <div class="col-md-2 col-6">
                                                        <label class="form-label small">IGST %</label>
                                                        <input type="number" step="0.01" id="igstRate" class="form-control" value="0">
                                                    </div>
                                                    <div class="col-md-4 col-12">
                                                        <label class="form-label small">GST Calculation</label>
                                                        <div id="gstBreakupBox" class="alert alert-light border py-2 px-3 mb-0 small">
                                                            <div class="gst-summary-grid">
                                                                <div class="gst-summary-cell"><small>Without GST</small><strong>₹0.00</strong></div>
                                                                <div class="gst-summary-cell"><small>GST</small><strong>₹0.00</strong></div>
                                                                <div class="gst-summary-cell"><small>Final Value</small><strong>₹0.00</strong></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Batch Information Section -->
                                            <div class="col-md-12">
                                                <div id="batchInfoSection" class="batch-info-section">
                                                    <div class="row g-2">
                                                        <div class="col-md-4">
                                                            <label class="form-label small">Batch Number</label>
                                                            <input type="text" id="batchNumber" class="form-control" placeholder="Auto-generated">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label small">Manufacture Date</label>
                                                            <input type="date" id="manufactureDate" class="form-control">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label small">Expiry Date</label>
                                                            <input type="date" id="expiryDate" class="form-control">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-12">
                                                <button type="button" id="addProductBtn" class="btn btn-primary w-100">
                                                    <i class="bx bx-plus me-1"></i> Add Product to List
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- Product Details Card -->
                                        <div id="productDetails" class="product-details-card">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <strong id="productName" class="product-name"></strong><br>
                                                    <small class="text-muted">Code: <span id="productCode"></span></small><br>
                                                    <small class="text-muted" id="productHSN"></small>
                                                    <div class="product-stock-display mt-1" id="productStockInfo"></div>
                                                </div>
                                                <div class="col-md-6 text-end">
                                                    <small class="text-danger fw-bold">MRP: ₹<span id="mrpDisplay"></span></small><br>
                                                    <small class="text-success fw-bold">Current Cost: ₹<span id="currentCost"></span></small><br>
                                                    <small class="text-muted" id="productGST"></small>
                                                    <div class="price-details mt-1" id="currentPriceDetails">
                                                        <div class="price-details-item">
                                                            <span class="price-details-label">Retail:</span>
                                                            <span class="price-details-value">₹<span id="currentRetailPrice"></span></span>
                                                        </div>
                                                        <div class="price-details-item">
                                                            <span class="price-details-label">Wholesale:</span>
                                                            <span class="price-details-value">₹<span id="currentWholesalePrice"></span></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-12">
                                                    <div class="border-top pt-2">
                                                        <small class="text-muted">Last Batch:</small>
                                                        <div class="row">
                                                            <div class="col-4">
                                                                <small>Price: ₹<span id="lastBatchPrice">0.00</span></small>
                                                            </div>
                                                            <div class="col-4">
                                                                <small>Retail: ₹<span id="lastBatchRetail">0.00</span></small>
                                                            </div>
                                                            <div class="col-4">
                                                                <small>Wholesale: ₹<span id="lastBatchWholesale">0.00</span></small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Price Change Warning -->
                                        <div id="priceChangeWarning" class="price-change-warning" style="display:none;">
                                            <i class="bx bx-info-circle me-1"></i>
                                            <span id="warningText"></span>
                                        </div>
                                    </div>

                                    <!-- Selected Products Table -->
                                    <div class="table-responsive mt-4">
                                        <table class="table table-hover selected-products-table" id="selectedProductsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="5%">#</th>
                                                    <th width="25%">Product</th>
                                                    <th width="8%" class="text-end">Qty</th>
                                                    <th width="12%" class="text-end">Purchase Price</th>
                                                    <th width="8%" class="text-end">Tax Rate</th>
                                                    <th width="12%" class="text-end">Total</th>
                                                    <th width="8%" class="text-center">Batch</th>
                                                    <th width="10%" class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="selectedProductsBody">
                                                <tr id="emptyRow" class="text-center">
                                                    <td colspan="8" class="py-4">
                                                        <i class="bx bx-package fs-1 text-muted mb-3 d-block"></i>
                                                        <p class="text-muted">No products added yet</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr class="table-light">
                                                    <td colspan="5" class="text-end fw-bold">Total GST:</td>
                                                    <td class="text-end fw-bold" id="totalGST">₹0.00</td>
                                                    <td colspan="2"></td>
                                                </tr>
                                                <tr class="table-light">
                                                    <td colspan="5" class="text-end fw-bold">GST Credit:</td>
                                                    <td class="text-end fw-bold text-success" id="gstCredit">₹0.00</td>
                                                    <td colspan="2"></td>
                                                </tr>
                                                <tr class="table-light">
                                                    <td colspan="5" class="text-end fw-bold">Grand Total:</td>
                                                    <td class="text-end fw-bold" id="grandTotal">₹0.00</td>
                                                    <td colspan="2"></td>
                                                </tr>
                                                <tr class="table-warning" id="roundOffRow" style="display:none;">
                                                    <td colspan="5" class="text-end fw-bold">Round Off:</td>
                                                    <td class="text-end fw-bold" id="roundOffDisplay">₹0.00</td>
                                                    <td colspan="2"></td>
                                                </tr>
                                                <tr class="table-success" id="roundedTotalRow" style="display:none;">
                                                    <td colspan="5" class="text-end fw-bold">Payable Rounded Total:</td>
                                                    <td class="text-end fw-bold" id="roundedGrandTotal">₹0.00</td>
                                                    <td colspan="2"></td>
                                                </tr>
                                                <tr class="table-light">
                                                    <td colspan="5" class="text-end">
                                                        <button type="button" class="btn btn-sm btn-warning" id="roundOffBtn">
                                                            <i class="bx bx-calculator me-1"></i> Round Off Paisa
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearRoundOffBtn" style="display:none;">
                                                            Clear Round Off
                                                        </button>
                                                    </td>
                                                    <td colspan="3"></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>

                                    <div class="alert alert-info mt-3">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Purchase Summary:</strong>
                                        <span id="stockSummary">
                                            No products selected
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="card-footer">
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-success btn-lg px-5" id="submitBtn" disabled>
                                            <i class="bx bx-check me-2"></i> Create Purchase Order
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Quick Add Product Modal -->
<div class="modal fade quick-add-modal" id="quickAddProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-plus-circle me-2 text-primary"></i> Quick Add Product
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="quickAddProductForm">
                    <div class="row g-2">
                        <!-- Category Selection -->
                        <div class="col-md-6">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select id="quickCategory" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php
                                $categories = $pdo->prepare("SELECT id, category_name FROM categories WHERE business_id = ? AND status = 'active' AND parent_id IS NULL ORDER BY category_name");
                                $categories->execute([$current_business_id]);
                                $categories = $categories->fetchAll();
                                foreach ($categories as $cat):
                                ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Subcategory (optional) -->
                        <div class="col-md-6">
                            <label class="form-label">Subcategory (Optional)</label>
                            <select id="quickSubcategory" class="form-select">
                                <option value="">None</option>
                            </select>
                        </div>
                        
                        <!-- Product Name -->
                        <div class="col-md-12">
                            <label class="form-label">Product Name <span class="text-danger">*</span></label>
                            <input type="text" id="quickProductName" class="form-control" required>
                        </div>
                        
                        <!-- Product Code & Barcode -->
                        <div class="col-md-6">
                            <label class="form-label">Product Code</label>
                            <input type="text" id="quickProductCode" class="form-control" placeholder="Auto-generated">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Barcode</label>
                            <div class="input-group">
                                <input type="text" id="quickBarcode" class="form-control" placeholder="Optional">
                                <button type="button" class="btn btn-outline-secondary" onclick="generateQuickBarcode()">
                                    <i class="bx bx-refresh"></i> Generate
                                </button>
                            </div>
                        </div>
                        
                        <!-- Unit of Measure -->
                        <div class="col-md-4">
                            <label class="form-label">Unit of Measure <span class="text-danger">*</span></label>
                            <select id="quickUnit" class="form-select">
                                <option value="pcs">Pieces (pcs)</option>
                                <option value="coil">Coil</option>
                                <option value="mtr">Meter (mtr)</option>
                                <option value="kg">Kilogram (kg)</option>
                                <option value="ltr">Liter (ltr)</option>
                                <option value="nos">Number (nos)</option>
                                <option value="box">Box</option>
                                <option value="feet">Feet</option>
                            </select>
                        </div>
                        
                        <!-- Secondary Unit -->
                        <div class="col-md-4">
                            <label class="form-label">Secondary Unit</label>
                            <input type="text" id="quickSecondaryUnit" class="form-control" placeholder="e.g., mtr, kg">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Conversion Rate</label>
                            <input type="number" step="0.0001" min="0" id="quickConversion" class="form-control" placeholder="e.g., 90">
                        </div>
                        
                        <!-- Pricing -->
                        <div class="col-md-4">
                            <label class="form-label">MRP <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" min="0" id="quickMRP" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Purchase Price <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" min="0" id="quickPurchasePrice" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">GST Rate</label>
                            <select id="quickGST" class="form-select">
                                <option value="">No GST</option>
                                <?php
                                $gst_rates_quick = $pdo->prepare("SELECT id, hsn_code, cgst_rate, sgst_rate, igst_rate FROM gst_rates WHERE business_id = ? AND status = 'active' ORDER BY hsn_code");
                                $gst_rates_quick->execute([$current_business_id]);
                                $gst_rates_quick = $gst_rates_quick->fetchAll();
                                foreach ($gst_rates_quick as $g):
                                $total_gst = $g['cgst_rate'] + $g['sgst_rate'] + $g['igst_rate'];
                                ?>
                                <option value="<?= $g['id'] ?>" 
                                    data-cgst="<?= $g['cgst_rate'] ?>" 
                                    data-sgst="<?= $g['sgst_rate'] ?>" 
                                    data-igst="<?= $g['igst_rate'] ?>"
                                    data-hsn="<?= $g['hsn_code'] ?>">
                                    <?= $g['hsn_code'] ?> - <?= $total_gst ?>%
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Retail Price Markup -->
                        <div class="col-md-6">
                            <label class="form-label">Retail Price Markup</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <select id="quickRetailType" class="form-select">
                                        <option value="percentage">Percentage (%)</option>
                                        <option value="fixed">Fixed (₹)</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <input type="number" step="0.01" min="0" id="quickRetailValue" class="form-control" placeholder="Value">
                                </div>
                            </div>
                            <div class="form-text">Markup on purchase price</div>
                        </div>
                        
                        <!-- Wholesale Price Markup -->
                        <div class="col-md-6">
                            <label class="form-label">Wholesale Price Markup</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <select id="quickWholesaleType" class="form-select">
                                        <option value="percentage">Percentage (%)</option>
                                        <option value="fixed">Fixed (₹)</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <input type="number" step="0.01" min="0" id="quickWholesaleValue" class="form-control" placeholder="Value">
                                </div>
                            </div>
                            <div class="form-text">Markup on purchase price</div>
                        </div>
                        
                        <!-- HSN Code -->
                        <div class="col-md-6">
                            <label class="form-label">HSN Code</label>
                            <input type="text" id="quickHSN" class="form-control" placeholder="HSN Code">
                        </div>
                        
                        <!-- Min Stock Level -->
                        <div class="col-md-3">
                            <label class="form-label">Min Stock Level</label>
                            <input type="number" id="quickMinStock" class="form-control" value="10">
                        </div>
                        
                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea id="quickDescription" class="form-control" rows="3" placeholder="Product description"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveQuickProduct()">
                    <i class="bx bx-save me-2"></i> Save Product
                </button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<!-- Add Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- Add flatpickr for date picker -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// Global state
const PRODUCTS = <?php echo json_encode($jsProducts, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const BARCODE_MAP = <?php echo json_encode($barcodeMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
let selectedProducts = new Map();
let itemCounter = 0;
let currentProductId = null;
let manualPriceUpdate = false; // Flag to track manual price entry mode
let roundOffEnabled = false;
let currentRawGrandTotal = 0;
let currentRoundedTotal = 0;
let currentRoundOffAmount = 0;

// SweetAlert2 Toast configuration
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer)
        toast.addEventListener('mouseleave', Swal.resumeTimer)
    }
});

// Confirmation Toast with buttons
const ConfirmToast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: true,
    showCancelButton: true,
    confirmButtonColor: '#3085d6',
    cancelButtonColor: '#d33',
    confirmButtonText: 'Yes',
    cancelButtonText: 'No',
    timer: null,
    customClass: {
        container: 'swal-toast-small'
    }
});

// Helper functions
function findProductById(id) {
    return PRODUCTS[id];
}

function formatMoney(n) { 
    return '₹' + parseFloat(n || 0).toFixed(2);
}

function getGstRate(cgst, sgst, igst) {
    return (parseFloat(cgst) || 0) + (parseFloat(sgst) || 0) + (parseFloat(igst) || 0);
}

function calculateUnitGstBreakup(value, cgst, sgst, igst, gstType = 'inclusive') {
    const inputValue = parseFloat(value) || 0;
    const rate = getGstRate(cgst, sgst, igst);
    let withoutGst = inputValue;
    let finalValue = inputValue;

    if (rate > 0) {
        if (gstType === 'exclusive') {
            withoutGst = inputValue;
            finalValue = inputValue + (inputValue * rate / 100);
        } else {
            finalValue = inputValue;
            withoutGst = inputValue / (1 + rate / 100);
        }
    }

    return {
        inputValue,
        withoutGst,
        gstAmount: finalValue - withoutGst,
        finalValue,
        rate
    };
}


function storedFinalToEntryValue(finalValue, cgst, sgst, igst, gstType = 'inclusive') {
    const value = parseFloat(finalValue) || 0;
    const rate = getGstRate(cgst, sgst, igst);

    // Product table prices are already stored as final prices with GST.
    // Inclusive: user enters final value.
    // Exclusive: user enters without-GST value, so convert stored final -> base for input.
    if (gstType === 'exclusive' && rate > 0) {
        return value / (1 + rate / 100);
    }
    return value;
}

function entryValueToFinalValue(entryValue, cgst, sgst, igst, gstType = 'inclusive') {
    return calculateUnitGstBreakup(entryValue, cgst, sgst, igst, gstType).finalValue;
}

function renderGstHint(selector, value, cgst, sgst, igst, gstType, showGst = false) {
    const b = calculateUnitGstBreakup(value, cgst, sgst, igst, gstType);
    const modeLabel = gstType === 'exclusive' ? 'After GST' : 'Final';
    let html = `<span class="base-chip">Without GST: ${formatMoney(b.withoutGst)}</span>`;
    if (showGst) {
        html += `<span class="gst-chip">GST: ${formatMoney(b.gstAmount)}</span>`;
    }
    html += `<span class="final-chip">${modeLabel}: ${formatMoney(b.finalValue)}</span>`;
    $(selector).html(html);
}

function resetGstHints() {
    const empty2 = '<span class="base-chip">Without GST: ₹0.00</span><span class="final-chip">Final: ₹0.00</span>';
    const empty3 = '<span class="base-chip">Without GST: ₹0.00</span><span class="gst-chip">GST: ₹0.00</span><span class="final-chip">Final: ₹0.00</span>';
    $('#mrpGstHint').html(empty2);
    $('#purchasePriceGstHint').html(empty3);
    $('#calculatedPurchasePriceGstHint').html(empty2);
    $('#retailPriceGstHint').html(empty2);
    $('#wholesalePriceGstHint').html(empty2);
}


// Calculate purchase price from MRP and discount
function calculatePurchasePriceFromDiscount(mrp, discountInput) {
    let purchasePrice = mrp;
    
    if (discountInput && discountInput.trim()) {
        const discount = discountInput.trim();
        
        if (discount.includes('%')) {
            const discountPercent = parseFloat(discount.replace('%', '')) || 0;
            if (discountPercent > 100) {
                Toast.fire({
                    icon: 'warning',
                    title: 'Discount percentage cannot exceed 100%'
                });
                return mrp;
            }
            purchasePrice = mrp - (mrp * discountPercent / 100);
        } else {
            const discountAmount = parseFloat(discount) || 0;
            if (discountAmount > mrp) {
                Toast.fire({
                    icon: 'warning',
                    title: 'Discount amount cannot exceed MRP'
                });
                return mrp;
            }
            purchasePrice = mrp - discountAmount;
        }
    }
    
    return purchasePrice < 0 ? 0 : purchasePrice;
}

// Calculate discount from MRP and purchase price
function calculateDiscountFromPrice(mrp, purchasePrice) {
    if (mrp <= 0 || purchasePrice <= 0) return '';
    if (purchasePrice >= mrp) return '';
    
    const discountPercent = ((mrp - purchasePrice) / mrp) * 100;
    return discountPercent.toFixed(1) + '%';
}

// Calculate retail and wholesale prices based on constant markup percentages
function calculateSellingPrices(purchasePrice, product) {
    let retailPrice = purchasePrice;
    let wholesalePrice = purchasePrice;
    
    // Apply retail markup (constant percentage from product)
    if (product.retail_markup_percent > 0) {
        retailPrice = purchasePrice + (purchasePrice * product.retail_markup_percent / 100);
    } else if (product.retail_price_value > 0) {
        if (product.retail_price_type === 'percentage') {
            retailPrice = purchasePrice + (purchasePrice * product.retail_price_value / 100);
        } else {
            retailPrice = purchasePrice + product.retail_price_value;
        }
    }
    
    // Apply wholesale markup (constant percentage from product)
    if (product.wholesale_markup_percent > 0) {
        wholesalePrice = purchasePrice + (purchasePrice * product.wholesale_markup_percent / 100);
    } else if (product.wholesale_price_value > 0) {
        if (product.wholesale_price_type === 'percentage') {
            wholesalePrice = purchasePrice + (purchasePrice * product.wholesale_price_value / 100);
        } else {
            wholesalePrice = purchasePrice + product.wholesale_price_value;
        }
    }
    
    return {
        retailPrice: retailPrice,
        wholesalePrice: wholesalePrice,
        retailMarkupPercent: purchasePrice > 0 ? ((retailPrice - purchasePrice) / purchasePrice) * 100 : 0,
        wholesaleMarkupPercent: purchasePrice > 0 ? ((wholesalePrice - purchasePrice) / purchasePrice) * 100 : 0
    };
}

// Calculate total for an item with GST
function calculateItemTotal(price, quantity, cgst, sgst, igst, gstType = 'inclusive') {
    const unitPrice = parseFloat(price) || 0;
    const qty = parseFloat(quantity) || 0;
    const unit = calculateUnitGstBreakup(unitPrice, cgst, sgst, igst, gstType);

    return {
        enteredValue: unitPrice * qty,
        taxable: unit.withoutGst * qty,
        cgst: (unit.withoutGst * qty) * (parseFloat(cgst) || 0) / 100,
        sgst: (unit.withoutGst * qty) * (parseFloat(sgst) || 0) / 100,
        igst: (unit.withoutGst * qty) * (parseFloat(igst) || 0) / 100,
        gstAmount: unit.gstAmount * qty,
        total: unit.finalValue * qty,
        gstCredit: unit.gstAmount * qty,
        unitWithoutGst: unit.withoutGst,
        unitFinal: unit.finalValue
    };
}

// Update price calculations
function updatePriceCalculations() {
    if (!currentProductId) return;
    
    const product = findProductById(currentProductId);
    if (!product) return;
    
    const mrp = parseFloat($('#mrp').val()) || 0;
    const discount = $('#discount').val().trim();
    const manualPurchasePrice = parseFloat($('#purchasePrice').val()) || 0;
    const quantity = parseInt($('#quantity').val()) || 1;
    const cgst = parseFloat($('#cgstRate').val()) || 0;
    const sgst = parseFloat($('#sgstRate').val()) || 0;
    const igst = parseFloat($('#igstRate').val()) || 0;
    const gstType = $('#gstType').val() || 'inclusive';
    
    let purchasePrice;
    
    if (manualPriceUpdate) {
        // Inclusive: user-entered purchase price is already FINAL with GST.
        // Exclusive: user-entered purchase price is WITHOUT GST.
        purchasePrice = manualPurchasePrice;
        
        if (mrp > 0 && purchasePrice < mrp) {
            const discountPercent = ((mrp - purchasePrice) / mrp) * 100;
            $('#discount').val(discountPercent.toFixed(1) + '%');
        } else if (purchasePrice >= mrp) {
            $('#discount').val('');
        }
    } else {
        if (discount) {
            purchasePrice = calculatePurchasePriceFromDiscount(mrp, discount);
        } else {
            purchasePrice = mrp;
        }
        $('#purchasePrice').val(purchasePrice.toFixed(2));
    }
    
    const sellingPrices = calculateSellingPrices(purchasePrice, product);
    const totals = calculateItemTotal(purchasePrice, quantity, cgst, sgst, igst, gstType);
    const mrpBreakup = calculateUnitGstBreakup(mrp, cgst, sgst, igst, gstType);
    const purchaseBreakup = calculateUnitGstBreakup(purchasePrice, cgst, sgst, igst, gstType);
    const retailBreakup = calculateUnitGstBreakup(sellingPrices.retailPrice, cgst, sgst, igst, gstType);
    const wholesaleBreakup = calculateUnitGstBreakup(sellingPrices.wholesalePrice, cgst, sgst, igst, gstType);
    
    // Show after-GST/final values in calculated fields.
    $('#calculatedPurchasePrice').val(purchaseBreakup.finalValue.toFixed(2));
    $('#retailPrice').val(retailBreakup.finalValue.toFixed(2));
    $('#wholesalePrice').val(wholesaleBreakup.finalValue.toFixed(2));

    renderGstHint('#mrpGstHint', mrp, cgst, sgst, igst, gstType, false);
    renderGstHint('#purchasePriceGstHint', purchasePrice, cgst, sgst, igst, gstType, true);
    renderGstHint('#calculatedPurchasePriceGstHint', purchasePrice, cgst, sgst, igst, gstType, true);
    renderGstHint('#retailPriceGstHint', sellingPrices.retailPrice, cgst, sgst, igst, gstType, true);
    renderGstHint('#wholesalePriceGstHint', sellingPrices.wholesalePrice, cgst, sgst, igst, gstType, true);
    
    $('#retailMarkupBadge').html(`<span class="markup-badge">+${product.retail_markup_percent.toFixed(1)}%</span>`);
    $('#wholesaleMarkupBadge').html(`<span class="markup-badge">+${product.wholesale_markup_percent.toFixed(1)}%</span>`);
    $('#retailMarkupDisplay').text(product.retail_markup_percent.toFixed(1) + '%');
    $('#wholesaleMarkupDisplay').text(product.wholesale_markup_percent.toFixed(1) + '%');
    
    $('#gstBreakupBox').html(`
        <div class="gst-summary-grid">
            <div class="gst-summary-cell"><small>Entered / Line Value</small><strong>${formatMoney(totals.enteredValue)}</strong></div>
            <div class="gst-summary-cell"><small>Without GST / Taxable</small><strong>${formatMoney(totals.taxable)}</strong></div>
            <div class="gst-summary-cell"><small>${gstType === 'exclusive' ? 'GST Added' : 'GST Included'}</small><strong>${formatMoney(totals.gstAmount)}</strong></div>
            <div class="gst-summary-cell"><small>Final Value</small><strong>${formatMoney(totals.total)}</strong></div>
            <div class="gst-summary-cell"><small>MRP Final</small><strong>${formatMoney(mrpBreakup.finalValue)}</strong></div>
            <div class="gst-summary-cell"><small>Purchase Final</small><strong>${formatMoney(purchaseBreakup.finalValue)}</strong></div>
        </div>
    `);

    $('#priceCalculation').show();
    $('#priceDetails').show();
    checkPriceChange(purchasePrice, product);
}

// Update product details when selected
function updateProductDetails(productId) {
    const product = findProductById(productId);
    if (product) {
        currentProductId = productId;
        manualPriceUpdate = false; // Start in auto mode

        const cgst = parseFloat(product.cgst || 0);
        const sgst = parseFloat(product.sgst || 0);
        const igst = parseFloat(product.igst || 0);
        const gstType = product.gst_type || 'inclusive';
        const entryMrp = storedFinalToEntryValue(product.mrp, cgst, sgst, igst, gstType);
        const entryStockPrice = storedFinalToEntryValue(product.stock_price, cgst, sgst, igst, gstType);

        $('#stockDisplay').val(product.total_stock);
        $('#mrp').val(entryMrp.toFixed(2));

        // Product DB prices are stored as final prices. For exclusive products we show the base value first.
        if (entryMrp > 0 && entryStockPrice > 0 && entryMrp > entryStockPrice) {
            const discountPercent = ((entryMrp - entryStockPrice) / entryMrp) * 100;
            $('#discount').val(discountPercent.toFixed(1) + '%');
            $('#purchasePrice').val(entryStockPrice.toFixed(2));
        } else {
            $('#discount').val('');
            $('#purchasePrice').val(entryStockPrice.toFixed(2));
        }

        $('#quantity').val(1);
        $('#cgstRate').val(cgst);
        $('#sgstRate').val(sgst);
        $('#igstRate').val(igst);
        $('#gstType').val(gstType);

        // Auto-generate batch number
        const date = new Date().toISOString().split('T')[0].replace(/-/g, '');
        $('#batchNumber').val('BATCH-' + date + '-' + Math.floor(Math.random() * 1000));

        // Show product details. These are DB-stored final values.
        $('#productDetails').addClass('show').show();
        $('#productName').text(product.name);
        $('#productCode').text(product.code);
        $('#productHSN').text(product.hsn ? 'HSN: ' + product.hsn : '');
        $('#mrpDisplay').text((parseFloat(product.mrp || 0)).toFixed(2));
        $('#currentCost').text((parseFloat(product.stock_price || 0)).toFixed(2));
        $('#currentRetailPrice').text((parseFloat(product.retail_price || 0)).toFixed(2));
        $('#currentWholesalePrice').text((parseFloat(product.wholesale_price || 0)).toFixed(2));

        // Show last batch info
        $('#lastBatchPrice').text(product.last_batch_price ? parseFloat(product.last_batch_price).toFixed(2) : '0.00');
        $('#lastBatchRetail').text(product.last_batch_retail_price ? parseFloat(product.last_batch_retail_price).toFixed(2) : '0.00');
        $('#lastBatchWholesale').text(product.last_batch_wholesale_price ? parseFloat(product.last_batch_wholesale_price).toFixed(2) : '0.00');

        // Show stock info
        let stockHtml = '';
        if (product.shop_stock > 0) {
            stockHtml += `<span class="stock-badge ${product.shop_stock < 10 ? 'low-stock-badge' : 'shop-stock-badge'}">S:${product.shop_stock}</span>`;
        } else {
            stockHtml += `<span class="stock-badge out-of-stock-badge">S:0</span>`;
        }
        stockHtml += ' ';
        if (product.warehouse_stock > 0) {
            stockHtml += `<span class="stock-badge warehouse-stock-badge">W:${product.warehouse_stock}</span>`;
        } else {
            stockHtml += `<span class="stock-badge out-of-stock-badge">W:0</span>`;
        }
        if (product.secondary_unit && product.sec_unit_conversion > 0) {
            stockHtml += `<span class="stock-badge secondary-unit-badge">${product.sec_unit_conversion} ${product.secondary_unit}</span>`;
        }
        $('#productStockInfo').html(stockHtml);

        // Show GST info
        let gstText = '';
        if (product.total_gst > 0) {
            gstText = `GST: ${product.total_gst}% (${gstType === 'exclusive' ? 'Exclusive' : 'Inclusive'})`;
            if (product.cgst > 0) gstText += ` C:${product.cgst}%`;
            if (product.sgst > 0) gstText += ` S:${product.sgst}%`;
            if (product.igst > 0) gstText += ` I:${product.igst}%`;
        } else {
            gstText = 'No GST';
        }
        $('#productGST').text(gstText);

        $('#retailMarkupDisplay').text((parseFloat(product.retail_markup_percent || 0)).toFixed(1) + '%');
        $('#wholesaleMarkupDisplay').text((parseFloat(product.wholesale_markup_percent || 0)).toFixed(1) + '%');

        updatePriceCalculations();
        $('#batchInfoSection').addClass('show').show();
        $('#mrp').focus().select();
    }
}

// Check if price has changed from current stock price
function checkPriceChange(newPurchasePrice, product) {
    const warningDiv = $('#priceChangeWarning');
    const warningText = $('#warningText');
    const gstType = $('#gstType').val() || product.gst_type || 'inclusive';
    const cgst = parseFloat($('#cgstRate').val()) || 0;
    const sgst = parseFloat($('#sgstRate').val()) || 0;
    const igst = parseFloat($('#igstRate').val()) || 0;
    const newFinalPrice = entryValueToFinalValue(newPurchasePrice, cgst, sgst, igst, gstType);
    const oldFinalPrice = parseFloat(product.stock_price || 0);

    warningDiv.removeClass('price-increase-warning price-decrease-warning');

    if (oldFinalPrice > 0 && Math.abs(newFinalPrice - oldFinalPrice) > 0.01) {
        const priceDiff = newFinalPrice - oldFinalPrice;
        const percentDiff = (Math.abs(priceDiff) / oldFinalPrice) * 100;
        const direction = priceDiff > 0 ? 'increased' : 'decreased';
        const warningClass = priceDiff > 0 ? 'price-increase-warning' : 'price-decrease-warning';

        warningDiv.addClass(warningClass);
        warningText.html(`Final purchase price ${direction} by ${percentDiff.toFixed(1)}% (from ${formatMoney(oldFinalPrice)} to ${formatMoney(newFinalPrice)}).<br>This will create a new batch with separate pricing.`);

        if (product.last_batch_price > 0) {
            const lastBatch = parseFloat(product.last_batch_price || 0);
            const lastBatchDiff = newFinalPrice - lastBatch;
            const lastBatchPercentDiff = (Math.abs(lastBatchDiff) / lastBatch) * 100;
            const lastBatchDirection = lastBatchDiff > 0 ? 'higher' : 'lower';
            warningText.html(warningText.html() + `<br>Compared to last batch (${formatMoney(lastBatch)}): ${lastBatchPercentDiff.toFixed(1)}% ${lastBatchDirection}.`);
        }
        warningDiv.show();
    } else {
        warningDiv.hide();
    }
}

// Add product to cart
function addProductToCart() {
    const select = $('#productSelect');
    const productId = select.val();
    
    if (!productId) {
        Toast.fire({
            icon: 'warning',
            title: 'Please select a product first'
        });
        select.focus();
        return;
    }

    const product = findProductById(productId);
    if (!product) {
        Toast.fire({
            icon: 'error',
            title: 'Product not found'
        });
        return;
    }

    const productName = product.name;
    const productCode = product.code;
    const shop_stock = product.shop_stock || 0;
    const warehouse_stock = product.warehouse_stock || 0;
    const total_stock = product.total_stock || 0;
    const secondary_unit = product.secondary_unit || '';
    const sec_unit_conversion = product.sec_unit_conversion || 0;
    const mrp = parseFloat($('#mrp').val()) || 0;
    const discount = $('#discount').val().trim();
    const purchasePrice = parseFloat($('#purchasePrice').val()) || 0;
    const quantity = parseInt($('#quantity').val()) || 1;
    const cgst = parseFloat($('#cgstRate').val()) || 0;
    const sgst = parseFloat($('#sgstRate').val()) || 0;
    const igst = parseFloat($('#igstRate').val()) || 0;
    const gst_type = $('#gstType').val() || 'inclusive';
    const total_gst = cgst + sgst + igst;
    const hsn = product.hsn || '';
    const batch_number = $('#batchNumber').val() || '';
    const manufacture_date = $('#manufactureDate').val() || '';
    const expiry_date = $('#expiryDate').val() || '';

    if (mrp <= 0) {
        Toast.fire({
            icon: 'warning',
            title: 'MRP must be greater than 0'
        });
        $('#mrp').focus();
        return;
    }

    if (purchasePrice <= 0) {
        Toast.fire({
            icon: 'warning',
            title: 'Purchase price must be greater than 0'
        });
        $('#purchasePrice').focus();
        return;
    }

    if (quantity <= 0) {
        Toast.fire({
            icon: 'warning',
            title: 'Quantity must be greater than 0'
        });
        $('#quantity').focus();
        return;
    }

    if (purchasePrice > mrp) {
        ConfirmToast.fire({
            icon: 'question',
            title: 'Purchase Price > MRP',
            html: `Purchase price (₹${purchasePrice.toFixed(2)}) is greater than MRP (₹${mrp.toFixed(2)}). Continue anyway?`,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, continue',
            cancelButtonText: 'No, cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                proceedWithAddProduct(product, {
                    productId, productName, productCode, shop_stock, warehouse_stock, total_stock,
                    secondary_unit, sec_unit_conversion, mrp, discount, purchasePrice, quantity,
                    cgst, sgst, igst, gst_type, total_gst, hsn, batch_number, manufacture_date, expiry_date
                });
            }
        });
        return;
    }

    proceedWithAddProduct(product, {
        productId, productName, productCode, shop_stock, warehouse_stock, total_stock,
        secondary_unit, sec_unit_conversion, mrp, discount, purchasePrice, quantity,
        cgst, sgst, igst, gst_type, total_gst, hsn, batch_number, manufacture_date, expiry_date
    });
}

function proceedWithAddProduct(product, data) {
    // Calculate selling prices and markups using constant percentages
    const sellingPrices = calculateSellingPrices(data.purchasePrice, product);
    
    // Calculate totals with inclusive/exclusive GST handling
    const totals = calculateItemTotal(data.purchasePrice, data.quantity, data.cgst, data.sgst, data.igst, data.gst_type || 'inclusive');
    const mrpBreakup = calculateUnitGstBreakup(data.mrp, data.cgst, data.sgst, data.igst, data.gst_type || 'inclusive');
    const purchaseBreakup = calculateUnitGstBreakup(data.purchasePrice, data.cgst, data.sgst, data.igst, data.gst_type || 'inclusive');
    const retailBreakup = calculateUnitGstBreakup(sellingPrices.retailPrice, data.cgst, data.sgst, data.igst, data.gst_type || 'inclusive');
    const wholesaleBreakup = calculateUnitGstBreakup(sellingPrices.wholesalePrice, data.cgst, data.sgst, data.igst, data.gst_type || 'inclusive');
    
    // Determine if price increased or decreased
    const isIncrease = data.purchasePrice > product.stock_price;
    const isDecrease = data.purchasePrice < product.stock_price;
    
    const itemId = ++itemCounter;

    // Add to selected products map with all batch fields
    selectedProducts.set(itemId, {
        id: data.productId,
        itemId: itemId,
        name: data.productName,
        code: data.productCode,
        shop_stock: data.shop_stock,
        warehouse_stock: data.warehouse_stock,
        total_stock: data.total_stock,
        secondary_unit: data.secondary_unit,
        sec_unit_conversion: data.sec_unit_conversion,
        mrp: data.mrp,
        old_mrp: product.mrp,
        discount: data.discount,
        purchase_price: data.purchasePrice,
        old_purchase_price: product.stock_price,
        retail_price: sellingPrices.retailPrice,
        old_retail_price: product.retail_price,
        wholesale_price: sellingPrices.wholesalePrice,
        old_wholesale_price: product.wholesale_price,
        retail_markup_percent: product.retail_markup_percent,
        wholesale_markup_percent: product.wholesale_markup_percent,
        quantity: data.quantity,
        cgst: data.cgst,
        sgst: data.sgst,
        igst: data.igst,
        total_gst: data.total_gst,
        gst_type: data.gst_type || 'inclusive',
        hsn: data.hsn,
        batch_number: data.batch_number,
        manufacture_date: data.manufacture_date,
        expiry_date: data.expiry_date,
        is_increase: isIncrease ? 1 : 0,
        is_decrease: isDecrease ? 1 : 0,
        entered_value: totals.enteredValue,
        taxable: totals.taxable,
        gst_amount: totals.gstAmount,
        cgst_amount: totals.cgst,
        sgst_amount: totals.sgst,
        igst_amount: totals.igst,
        total: totals.total,
        gst_credit: totals.gstCredit,
        mrp_without_gst: mrpBreakup.withoutGst,
        mrp_final: mrpBreakup.finalValue,
        purchase_without_gst: purchaseBreakup.withoutGst,
        purchase_final: purchaseBreakup.finalValue,
        retail_without_gst: retailBreakup.withoutGst,
        retail_final: retailBreakup.finalValue,
        wholesale_without_gst: wholesaleBreakup.withoutGst,
        wholesale_final: wholesaleBreakup.finalValue
    });

    // Update table
    updateProductsTable();
    updateSummary();

    // Reset fields
    resetProductFields();

    Toast.fire({
        icon: 'success',
        title: 'Product added to purchase list'
    });
}

function resetProductFields() {
    const select = $('#productSelect');
    select.val(null).trigger('change');
    currentProductId = null;
    manualPriceUpdate = false; // Reset manual mode flag
    $('#stockDisplay').val('0');
    $('#mrp').val('0');
    $('#discount').val('');
    $('#purchasePrice').val('0');
    $('#quantity').val(1);
    $('#cgstRate').val('0');
    $('#sgstRate').val('0');
    $('#igstRate').val('0');
    $('#gstType').val('inclusive');
    $('#gstBreakupBox').html('<div class="gst-summary-grid"><div class="gst-summary-cell"><small>Without GST</small><strong>₹0.00</strong></div><div class="gst-summary-cell"><small>GST</small><strong>₹0.00</strong></div><div class="gst-summary-cell"><small>Final Value</small><strong>₹0.00</strong></div></div>');
    resetGstHints();
    $('#batchNumber').val('');
    $('#manufactureDate').val('');
    $('#expiryDate').val('');
    $('#productDetails').removeClass('show');
    $('#batchInfoSection').removeClass('show');
    $('#priceCalculation').hide();
    $('#priceDetails').hide();
    $('#priceChangeWarning').hide();
    
    // Focus back on product selection
    select.focus();
}


function calculateRoundOffAmount(amount) {
    amount = parseFloat(amount) || 0;
    const rounded = Math.round(amount);
    const roundOff = rounded - amount;

    return {
        raw: amount,
        rounded: rounded,
        roundOff: roundOff
    };
}

function applyRoundOffDisplay() {
    const result = calculateRoundOffAmount(currentRawGrandTotal);

    currentRoundedTotal = result.rounded;
    currentRoundOffAmount = result.roundOff;

    $('#round_off_enabled').val(roundOffEnabled ? '1' : '0');
    $('#round_off_amount').val(currentRoundOffAmount.toFixed(2));
    $('#rounded_total').val(currentRoundedTotal.toFixed(2));

    if (roundOffEnabled) {
        $('#roundOffRow').show();
        $('#roundedTotalRow').show();
        $('#clearRoundOffBtn').show();

        $('#roundOffDisplay').text(
            (currentRoundOffAmount >= 0 ? '+ ' : '- ') + formatMoney(Math.abs(currentRoundOffAmount))
        );

        $('#roundedGrandTotal').text(formatMoney(currentRoundedTotal));
        $('#grandTotal').text(formatMoney(currentRawGrandTotal));
    } else {
        $('#roundOffRow').hide();
        $('#roundedTotalRow').hide();
        $('#clearRoundOffBtn').hide();

        $('#roundOffDisplay').text(formatMoney(0));
        $('#roundedGrandTotal').text(formatMoney(0));

        $('#round_off_amount').val('0');
        $('#rounded_total').val(currentRawGrandTotal.toFixed(2));
        $('#grandTotal').text(formatMoney(currentRawGrandTotal));
    }
}

// Update products table
function updateProductsTable() {
    const tbody = $('#selectedProductsBody');
    tbody.empty();
    let totalAmount = 0;
    let totalGST = 0;
    let totalGSTCredit = 0;
    let rowIndex = 0;

    if (selectedProducts.size === 0) {
        tbody.append('<tr id="emptyRow" class="text-center"><td colspan="8" class="py-4"><i class="bx bx-package fs-1 text-muted mb-3 d-block"></i><p class="text-muted">No products added yet</p></td></tr>');
        $('#itemCount').text('0 Items');
        $('#submitBtn').prop('disabled', true);
        currentRawGrandTotal = 0;
        roundOffEnabled = false;
        $('#grandTotal').text(formatMoney(0));
        $('#totalGST').text(formatMoney(0));
        $('#gstCredit').text(formatMoney(0));
        applyRoundOffDisplay();
        return;
    }

    selectedProducts.forEach((product, itemId) => {
        totalAmount += product.total;
        totalGST += product.cgst_amount + product.sgst_amount + product.igst_amount;
        totalGSTCredit += product.gst_credit;
        rowIndex++;
        
        // Add batch info
        let batchInfo = '';
        if (product.batch_number) {
            batchInfo = '<br><small class="text-info">';
            batchInfo += `Batch: ${product.batch_number}`;
            if (product.expiry_date) batchInfo += ` | Exp: ${product.expiry_date}`;
            batchInfo += '</small>';
        }
        
        // Add secondary unit info if available
        let secondaryInfo = '';
        if (product.secondary_unit && product.sec_unit_conversion > 0) {
            const secondary_qty = product.quantity * product.sec_unit_conversion;
            secondaryInfo = `<br><small class="text-success">${secondary_qty.toFixed(2)} ${product.secondary_unit}</small>`;
        }
        
        // Add price change indicator
        let priceChangeBadge = '';
        if (product.is_increase) {
            priceChangeBadge = ' <span class="badge bg-success">↑</span>';
        } else if (product.is_decrease) {
            priceChangeBadge = ' <span class="badge bg-danger">↓</span>';
        }
        
        // Add markup info - show constant percentages
        let markupInfo = '';
        if (product.retail_markup_percent > 0 || product.wholesale_markup_percent > 0) {
            markupInfo = '<br><small class="text-muted">';
            if (product.retail_markup_percent > 0) {
                markupInfo += `R: +${product.retail_markup_percent.toFixed(1)}%`;
            }
            if (product.wholesale_markup_percent > 0) {
                if (product.retail_markup_percent > 0) markupInfo += ' | ';
                markupInfo += `W: +${product.wholesale_markup_percent.toFixed(1)}%`;
            }
            markupInfo += ' (fixed)';
            markupInfo += '</small>';
        }
        
        // Add GST info to product display
        let gstInfo = '';
        if (product.total_gst > 0) {
            gstInfo = `<br><small class="${product.gst_type === 'exclusive' ? 'text-primary' : 'text-muted'}">GST: ${product.total_gst}% (${product.gst_type === 'exclusive' ? 'Exclusive' : 'Inclusive'})</small>`;
        }
        
        const row = $(`
            <tr class="item-row" data-item-id="${itemId}">
                <td>${rowIndex}</td>
                <td>
                    <strong>${product.name}${priceChangeBadge}</strong><br>
                    <small class="text-muted">${product.code}</small>
                    ${gstInfo}
                    ${batchInfo}
                    ${secondaryInfo}
                    <br><small class="text-muted">MRP without GST: ${formatMoney(product.mrp_without_gst || product.mrp)}</small>
                    <br><small class="text-primary">MRP final: ${formatMoney(product.mrp_final || product.mrp)} - ${product.discount || '0%'}</small>
                    ${markupInfo}
                </td>
                <td class="text-end">${product.quantity}</td>
                <td class="text-end">
                    <strong>${formatMoney(product.purchase_without_gst || product.taxable / product.quantity || 0)}</strong>
                    <br><small class="text-muted">Without GST / Unit</small>
                    <br><small class="text-primary">Final Unit: ${formatMoney(product.purchase_final || product.purchase_price)}</small>
                </td>
                <td class="text-end">
                    ${product.total_gst}%
                    <br><small class="${product.gst_type === 'exclusive' ? 'text-success' : 'text-muted'}">
                        ${product.gst_type === 'exclusive' ? 'Added' : 'Included'}: ${formatMoney(product.gst_amount || 0)}
                    </small>
                    <br><small class="text-muted">Type: ${product.gst_type === 'exclusive' ? 'Exclusive' : 'Inclusive'}</small>
                </td>
                <td class="text-end fw-bold">
                    ${formatMoney(product.total)}
                    <br><small class="text-muted">Without GST: ${formatMoney(product.taxable || 0)}</small>
                    <br><small class="text-primary">Entered: ${formatMoney(product.entered_value || (product.purchase_price * product.quantity))}</small>
                </td>
                <td class="text-center">
                    ${product.batch_number ? '<i class="bx bx-package text-info" title="Batch: ' + product.batch_number + '"></i>' : '-'}
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-outline-danger btn-sm delete-btn" 
                            data-item-id="${itemId}" title="Remove">
                        <i class="bx bx-trash"></i>
                    </button>
                </td>
            </tr>
        `);
        tbody.append(row);
    });

    // Update totals
    currentRawGrandTotal = totalAmount;
    $('#totalGST').text(formatMoney(totalGST));
    $('#gstCredit').text(formatMoney(totalGSTCredit));
    applyRoundOffDisplay();
    $('#itemCount').text(`${selectedProducts.size} ${selectedProducts.size === 1 ? 'Item' : 'Items'}`);
    $('#submitBtn').prop('disabled', false);

    // Attach delete events
    $('.delete-btn').on('click', function(e) {
        e.preventDefault();
        const itemId = $(this).data('item-id');
        deleteProduct(itemId);
    });
}

// Delete product from cart
function deleteProduct(itemId) {
    ConfirmToast.fire({
        icon: 'warning',
        title: 'Remove Product?',
        html: 'Are you sure you want to remove this item from the purchase?',
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, remove it',
        cancelButtonText: 'No, keep it'
    }).then((result) => {
        if (result.isConfirmed) {
            selectedProducts.delete(itemId);
            updateProductsTable();
            updateSummary();
            Toast.fire({
                icon: 'success',
                title: 'Product removed from purchase'
            });
        }
    });
}

// Update purchase summary
function updateSummary() {
    if (selectedProducts.size === 0) {
        $('#stockSummary').html('No products selected');
        return;
    }

    let totalQty = 0;
    let totalValue = 0;
    let itemCount = selectedProducts.size;
    let batchCount = 0;
    let increaseCount = 0;
    let decreaseCount = 0;

    selectedProducts.forEach(product => {
        totalQty += product.quantity;
        totalValue += product.total;
        if (product.batch_number) batchCount++;
        if (product.is_increase) increaseCount++;
        if (product.is_decrease) decreaseCount++;
    });

    let summary = `<strong>${itemCount} products</strong> | 
                  <strong>${totalQty} units</strong> | 
                  <strong>${formatMoney(totalValue)} total value</strong>`;
    
    if (batchCount > 0) {
        summary += ` | <strong>${batchCount} batch${batchCount > 1 ? 'es' : ''}</strong>`;
    }
    
    if (increaseCount > 0) {
        summary += ` | <span class="text-success">${increaseCount} price increase${increaseCount > 1 ? 's' : ''}</span>`;
    }
    
    if (decreaseCount > 0) {
        summary += ` | <span class="text-danger">${decreaseCount} price decrease${decreaseCount > 1 ? 's' : ''}</span>`;
    }
    
    $('#stockSummary').html(summary);
}

// Submit form
function prepareFormForSubmit() {
    // Sync round-off values before submit
    applyRoundOffDisplay();

    // Clear existing input fields
    $('input[name^="items["]').remove();
    
    // Add each product to form
    let index = 0;
    selectedProducts.forEach(product => {
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][product_id]`,
            value: product.id
        }).appendTo('#purchaseForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][quantity]`,
            value: product.quantity
        }).appendTo('#purchaseForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][mrp]`,
            value: product.mrp
        }).appendTo('#purchaseForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][discount]`,
            value: product.discount
        }).appendTo('#purchaseForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][purchase_price]`,
            value: product.purchase_price
        }).appendTo('#purchaseForm');

        // Send UI calculated FINAL after-GST retail and wholesale values to PHP.
        // PHP will save these directly instead of falling back to DB markup values.
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][retail_price]`,
            value: product.retail_final || product.retail_price || 0
        }).appendTo('#purchaseForm');

        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][wholesale_price]`,
            value: product.wholesale_final || product.wholesale_price || 0
        }).appendTo('#purchaseForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][cgst_rate]`,
            value: product.cgst
        }).appendTo('#purchaseForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][sgst_rate]`,
            value: product.sgst
        }).appendTo('#purchaseForm');
        
        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][igst_rate]`,
            value: product.igst
        }).appendTo('#purchaseForm');

        $('<input>').attr({
            type: 'hidden',
            name: `items[${index}][gst_type]`,
            value: product.gst_type || 'inclusive'
        }).appendTo('#purchaseForm');
        
        // Add batch info
        if (product.batch_number) {
            $('<input>').attr({
                type: 'hidden',
                name: `items[${index}][batch_number]`,
                value: product.batch_number
            }).appendTo('#purchaseForm');
            
            if (product.manufacture_date) {
                $('<input>').attr({
                    type: 'hidden',
                    name: `items[${index}][manufacture_date]`,
                    value: product.manufacture_date
                }).appendTo('#purchaseForm');
            }
            
            if (product.expiry_date) {
                $('<input>').attr({
                    type: 'hidden',
                    name: `items[${index}][expiry_date]`,
                    value: product.expiry_date
                }).appendTo('#purchaseForm');
            }
        }
        
        index++;
    });
}

// Barcode scanning
function handleBarcodeScan(barcode) {
    if (BARCODE_MAP[barcode]) {
        const productId = BARCODE_MAP[barcode];
        $('#productSelect').val(productId).trigger('change');
        updateProductDetails(productId);
        setTimeout(() => $('#mrp').focus(), 100);
    } else {
        Toast.fire({
            icon: 'info',
            title: 'Product not found for barcode: ' + barcode
        });
    }
}

// Bill image preview
function setupBillImagePreview() {
    $('#billImage').on('change', function() {
        const file = this.files[0];
        const preview = $('#billPreview');
        
        if (file) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                if (file.type === 'application/pdf') {
                    preview.html(`<embed src="${e.target.result}" type="application/pdf" />`).show();
                } else {
                    preview.html(`<img src="${e.target.result}" alt="Bill Preview" />`).show();
                }
            };
            
            reader.readAsDataURL(file);
        } else {
            preview.hide().html('');
        }
    });
}

// Initialize Select2 with custom template
function initializeSelect2() {
    // Supplier select
    $('.select2-supplier').select2({
        placeholder: '-- Select Supplier --',
        allowClear: false,
        width: '100%'
    });

    // Shop select
    $('.select2-shop').select2({
        placeholder: '-- Select Location --',
        allowClear: false,
        width: '100%'
    });

    // Prepare product data for Select2
    const productOptions = [];
    Object.keys(PRODUCTS).forEach(productId => {
        const product = PRODUCTS[productId];
        productOptions.push({
            id: productId,
            text: `${product.name} (${product.code})`,
            name: product.name,
            code: product.code,
            mrp: product.mrp,
            shop_stock: product.shop_stock,
            warehouse_stock: product.warehouse_stock,
            hsn: product.hsn,
            total_gst: product.total_gst,
            secondary_unit: product.secondary_unit,
            sec_unit_conversion: product.sec_unit_conversion
        });
    });

    // Product select with custom template
    $('#productSelect').select2({
        placeholder: '-- Type to search product --',
        allowClear: true,
        width: '100%',
        data: productOptions,
        templateResult: function(product) {
            if (!product.id) return product.text;
            
            const prodData = PRODUCTS[product.id];
            if (!prodData) return product.text;

            const shopBadgeClass = prodData.shop_stock > 0 ? 
                (prodData.shop_stock < 10 ? 'low-stock-badge' : 'shop-stock-badge') : 
                'out-of-stock-badge';
                
            const warehouseBadgeClass = prodData.warehouse_stock > 0 ? 
                'warehouse-stock-badge' : 
                'out-of-stock-badge';
            
            return $(`
                <div class="product-option">
                    <div style="flex: 1; min-width: 0;">
                        <div class="product-name">${prodData.name}</div>
                        <div class="product-code">
                            ${prodData.code} | 
                            <small class="text-muted">MRP: ₹${prodData.mrp.toFixed(2)}</small> |
                            <small class="text-muted">HSN: ${prodData.hsn || 'N/A'}</small>
                        </div>
                        <div class="product-stock-display">
                            <span class="stock-badge ${shopBadgeClass}">
                                S:${prodData.shop_stock}
                            </span>
                            <span class="stock-badge ${warehouseBadgeClass}">
                                W:${prodData.warehouse_stock}
                            </span>
                            <span class="stock-badge gst-badge">
                                GST:${prodData.total_gst}% (Inc)
                            </span>
                            ${prodData.secondary_unit && prodData.sec_unit_conversion > 0 ? 
                                `<span class="stock-badge secondary-unit-badge">
                                    ${prodData.sec_unit_conversion} ${prodData.secondary_unit}
                                </span>` : ''}
                        </div>
                    </div>
                    <div class="product-price" style="white-space: nowrap;">
                        Cost: ₹${prodData.stock_price.toFixed(2)} | 
                        R: +${prodData.retail_markup_percent.toFixed(1)}% | 
                        W: +${prodData.wholesale_markup_percent.toFixed(1)}%
                    </div>
                </div>
            `);
        },
        templateSelection: function(product) {
            if (!product.id) return product.text;
            const prodData = PRODUCTS[product.id];
            return prodData ? `${prodData.name} (${prodData.code})` : product.text;
        },
        escapeMarkup: function(markup) {
            return markup;
        }
    });

    // Handle product selection
    $('#productSelect').on('change', function() {
        const productId = $(this).val();
        if (productId) {
            updateProductDetails(productId);
        } else {
            currentProductId = null;
            manualPriceUpdate = false;
            $('#productDetails').removeClass('show');
            $('#batchInfoSection').removeClass('show');
            $('#priceCalculation').hide();
            $('#priceDetails').hide();
            $('#priceChangeWarning').hide();
            $('#stockDisplay').val('0');
            $('#mrp').val('0');
            $('#discount').val('');
            $('#purchasePrice').val('0');
            $('#quantity').val(1);
            $('#cgstRate').val('0');
            $('#sgstRate').val('0');
            $('#igstRate').val('0');
            $('#gstType').val('inclusive');
    $('#gstBreakupBox').html('<div class="gst-summary-grid"><div class="gst-summary-cell"><small>Without GST</small><strong>₹0.00</strong></div><div class="gst-summary-cell"><small>GST</small><strong>₹0.00</strong></div><div class="gst-summary-cell"><small>Final Value</small><strong>₹0.00</strong></div></div>');
    resetGstHints();
    $('#batchNumber').val('');
            $('#manufactureDate').val('');
            $('#expiryDate').val('');
        }
    });

    // Handle select2:select event
    $('#productSelect').on('select2:select', function(e) {
        const productId = e.params.data.id;
        updateProductDetails(productId);
    });

    // Clear product details when selection is cleared
    $('#productSelect').on('select2:clear', function() {
        currentProductId = null;
        manualPriceUpdate = false;
        $('#productDetails').removeClass('show');
        $('#batchInfoSection').removeClass('show');
        $('#priceCalculation').hide();
        $('#priceDetails').hide();
        $('#priceChangeWarning').hide();
        $('#stockDisplay').val('0');
        $('#mrp').val('0');
        $('#discount').val('');
        $('#purchasePrice').val('0');
        $('#quantity').val(1);
        $('#cgstRate').val('0');
        $('#sgstRate').val('0');
        $('#igstRate').val('0');
        $('#gstType').val('inclusive');
    $('#gstBreakupBox').html('<div class="gst-summary-grid"><div class="gst-summary-cell"><small>Without GST</small><strong>₹0.00</strong></div><div class="gst-summary-cell"><small>GST</small><strong>₹0.00</strong></div><div class="gst-summary-cell"><small>Final Value</small><strong>₹0.00</strong></div></div>');
    resetGstHints();
    $('#batchNumber').val('');
        $('#manufactureDate').val('');
        $('#expiryDate').val('');
    });
}

// Form validation
function setupFormValidation() {
    $('#purchaseForm').on('submit', function(e) {
        if (selectedProducts.size === 0) {
            e.preventDefault();
            Toast.fire({
                icon: 'warning',
                title: 'Please add at least one product to the purchase.'
            });
            return false;
        }
        
        if (!$('select[name="manufacturer_id"]').val()) {
            e.preventDefault();
            Toast.fire({
                icon: 'warning',
                title: 'Please select a supplier.'
            });
            $('select[name="manufacturer_id"]').focus();
            return false;
        }
        
        if (!$('select[name="shop_id"]').val()) {
            e.preventDefault();
            Toast.fire({
                icon: 'warning',
                title: 'Please select a location to receive stock.'
            });
            $('select[name="shop_id"]').focus();
            return false;
        }
        
        prepareFormForSubmit();
        return true;
    });
}

// ==============================================
// QUICK ADD PRODUCT FUNCTIONS
// ==============================================

// Open Quick Add Product Modal
function openQuickAddProductModal() {
    // Reset form
    $('#quickAddProductForm')[0].reset();
    $('#quickSubcategory').html('<option value="">None</option>');
    $('#quickProductCode').val('');
    $('#quickBarcode').val('');
    $('#quickMRP').val('');
    $('#quickPurchasePrice').val('');
    $('#quickRetailValue').val('');
    $('#quickWholesaleValue').val('');
    $('#quickHSN').val('');
    
    // Show modal
    $('#quickAddProductModal').modal('show');
}

// Load subcategories when category changes
$('#quickCategory').on('change', function() {
    const categoryId = $(this).val();
    const subcategorySelect = $('#quickSubcategory');
    
    if (!categoryId) {
        subcategorySelect.html('<option value="">None</option>');
        return;
    }
    
    // Fetch subcategories
    $.ajax({
        url: 'ajax/get_subcategories.php',
        method: 'GET',
        data: { category_id: categoryId, business_id: '<?= $current_business_id ?>' },
        dataType: 'json',
        success: function(response) {
            let options = '<option value="">None</option>';
            if (response.success && response.subcategories && response.subcategories.length > 0) {
                response.subcategories.forEach(function(subcat) {
                    options += `<option value="${subcat.id}">${subcat.subcategory_name}</option>`;
                });
            }
            subcategorySelect.html(options);
        },
        error: function() {
            subcategorySelect.html('<option value="">None</option>');
        }
    });
});

// Update HSN when GST is selected
$('#quickGST').on('change', function() {
    const selected = $(this).find(':selected');
    const hsn = selected.data('hsn');
    if (hsn) {
        $('#quickHSN').val(hsn);
    }
});

// Generate barcode for quick add
function generateQuickBarcode() {
    const prefix = '89';
    const random = Math.floor(Math.random() * 10000000000).toString().padStart(10, '0');
    const barcode = prefix + random;
    $('#quickBarcode').val(barcode);
    Toast.fire({
        icon: 'success',
        title: 'Barcode generated'
    });
}

// Auto-generate product code if empty
function generateQuickProductCode(name) {
    if (!name) return '';
    const prefix = name.substring(0, 3).toUpperCase().replace(/[^A-Z]/g, '');
    const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
    return prefix + random;
}

// Save quick product via AJAX
function saveQuickProduct() {
    // Validate required fields
    const categoryId = $('#quickCategory').val();
    const productName = $('#quickProductName').val().trim();
    const mrp = parseFloat($('#quickMRP').val()) || 0;
    const purchasePrice = parseFloat($('#quickPurchasePrice').val()) || 0;
    
    if (!categoryId) {
        Toast.fire({
            icon: 'warning',
            title: 'Please select a category'
        });
        return;
    }
    
    if (!productName) {
        Toast.fire({
            icon: 'warning',
            title: 'Please enter product name'
        });
        $('#quickProductName').focus();
        return;
    }
    
    if (mrp <= 0) {
        Toast.fire({
            icon: 'warning',
            title: 'MRP must be greater than 0'
        });
        $('#quickMRP').focus();
        return;
    }
    
    if (purchasePrice <= 0) {
        Toast.fire({
            icon: 'warning',
            title: 'Purchase price must be greater than 0'
        });
        $('#quickPurchasePrice').focus();
        return;
    }
    
    // Auto-generate product code if empty
    let productCode = $('#quickProductCode').val().trim();
    if (!productCode) {
        productCode = generateQuickProductCode(productName);
        $('#quickProductCode').val(productCode);
    }
    
    // Prepare data
    const gstId = $('#quickGST').val();
    const gstData = gstId ? $('#quickGST').find(':selected') : null;
    
    const formData = {
        category_id: categoryId,
        subcategory_id: $('#quickSubcategory').val() || null,
        product_name: productName,
        product_code: productCode,
        barcode: $('#quickBarcode').val().trim() || null,
        unit: $('#quickUnit').val(),
        secondary_unit: $('#quickSecondaryUnit').val().trim() || null,
        sec_unit_conversion: parseFloat($('#quickConversion').val()) || null,
        mrp: mrp,
        stock_price: purchasePrice,
        gst_id: gstId || null,
        hsn_code: $('#quickHSN').val().trim() || null,
        retail_price_type: $('#quickRetailType').val(),
        retail_price_value: parseFloat($('#quickRetailValue').val()) || 0,
        wholesale_price_type: $('#quickWholesaleType').val(),
        wholesale_price_value: parseFloat($('#quickWholesaleValue').val()) || 0,
        min_stock_level: parseInt($('#quickMinStock').val()) || 10,
        description: $('#quickDescription').val().trim() || null,
        business_id: '<?= $current_business_id ?>',
        user_id: '<?= $user_id ?>'
    };
    
    // Add GST rates if selected
    if (gstData && gstData.length) {
        formData.cgst_rate = parseFloat(gstData.data('cgst')) || 0;
        formData.sgst_rate = parseFloat(gstData.data('sgst')) || 0;
        formData.igst_rate = parseFloat(gstData.data('igst')) || 0;
    } else {
        formData.cgst_rate = 0;
        formData.sgst_rate = 0;
        formData.igst_rate = 0;
    }
    
    // Show loading
    const saveBtn = $(event.target);
    const originalText = saveBtn.html();
    saveBtn.html('<i class="bx bx-loader bx-spin me-2"></i> Saving...').prop('disabled', true);
    
    // Send AJAX request
    $.ajax({
        url: 'ajax/quick_add_product.php',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Close modal
                $('#quickAddProductModal').modal('hide');
                
                // Add new product to PRODUCTS object
                const newProduct = response.product;
                PRODUCTS[newProduct.id] = newProduct;
                
                // Add to barcode map if barcode exists
                if (newProduct.barcode) {
                    BARCODE_MAP[newProduct.barcode] = newProduct.id;
                }
                BARCODE_MAP[newProduct.code] = newProduct.id;
                
                // Update Select2 with new product
                updateSelect2WithNewProduct(newProduct);
                
                // Select the new product in dropdown
                $('#productSelect').val(newProduct.id).trigger('change');
                
                // Show success message
                Toast.fire({
                    icon: 'success',
                    title: 'Product added successfully!'
                });
            } else {
                Toast.fire({
                    icon: 'error',
                    title: 'Error: ' + (response.message || 'Failed to add product')
                });
            }
        },
        error: function(xhr, status, error) {
            Toast.fire({
                icon: 'error',
                title: 'Error: ' + error
            });
            console.error('Quick add error:', xhr.responseText);
        },
        complete: function() {
            saveBtn.html(originalText).prop('disabled', false);
        }
    });
}

// Update Select2 dropdown with new product
function updateSelect2WithNewProduct(product) {
    const select = $('#productSelect');
    
    // Create new option for Select2
    const newOption = new Option(`${product.name} (${product.code})`, product.id, true, true);
    
    // Append to select
    select.append(newOption);
    
    // Refresh Select2
    select.trigger('change');
    
    // Re-initialize Select2 with updated data
    select.select2('destroy');
    initializeSelect2();
    
    // Select the new product
    select.val(product.id).trigger('change');
}

// Show success/error messages
<?php if (isset($_GET['success'])): ?>
Toast.fire({
    icon: 'success',
    title: 'Purchase <strong><?= htmlspecialchars($_GET['po'] ?? 'Order') ?></strong> created successfully!'
});
<?php endif; ?>

<?php if ($error): ?>
Toast.fire({
    icon: 'error',
    title: '<?= addslashes($error) ?>'
});
<?php endif; ?>

// Initialize everything when document is ready
$(document).ready(function() {
    initializeSelect2();
    setupBillImagePreview();
    setupFormValidation();
    
    // Initialize date pickers
    flatpickr("#manufactureDate", {
        dateFormat: "Y-m-d"
    });
    
    flatpickr("#expiryDate", {
        dateFormat: "Y-m-d",
        minDate: "today"
    });
    
    // Add product button click
    $('#addProductBtn').on('click', addProductToCart);

    // Round off paisa button click
    $('#roundOffBtn').on('click', function() {
        if (selectedProducts.size === 0) {
            Toast.fire({
                icon: 'warning',
                title: 'Add products before round off'
            });
            return;
        }

        roundOffEnabled = true;
        applyRoundOffDisplay();

        Toast.fire({
            icon: 'success',
            title: 'Paisa rounded off'
        });
    });

    $('#clearRoundOffBtn').on('click', function() {
        roundOffEnabled = false;
        applyRoundOffDisplay();

        Toast.fire({
            icon: 'info',
            title: 'Round off removed'
        });
    });
    
    // Set default shop
    $('select[name="shop_id"]').val('<?= $shop_id ?>').trigger('change');
    
    // Auto-focus on product search
    setTimeout(() => {
        $('.select2-products').select2('open');
    }, 500);
    
    // Discount input handling
    $('#discount').on('input', function() {
        if (currentProductId) {
            // When discount is changed manually, switch to auto mode
            manualPriceUpdate = false;
            updatePriceCalculations();
        }
    });
    
    // MRP and other fields input handling
    $('#mrp, #quantity, #cgstRate, #sgstRate, #igstRate, #gstType').on('input change', function() {
        if (currentProductId) {
            updatePriceCalculations();
        }
    });
    
    // Manual purchase price input
    $('#purchasePrice').on('input', function() {
        if (currentProductId) {
            // When purchase price is changed manually, switch to manual mode
            manualPriceUpdate = true;
            updatePriceCalculations();
        }
    });
    
    // Auto-calculate discount when MRP changes (if not manual)
    $('#mrp').on('blur', function() {
        if (currentProductId && !manualPriceUpdate) {
            const product = findProductById(currentProductId);
            if (product) {
                const mrp = parseFloat($(this).val()) || 0;
                const currentPurchasePrice = parseFloat($('#purchasePrice').val()) || product.stock_price;
                
                if (mrp > 0 && currentPurchasePrice > 0 && mrp > currentPurchasePrice) {
                    const discountPercent = ((mrp - currentPurchasePrice) / mrp) * 100;
                    $('#discount').val(discountPercent.toFixed(1) + '%');
                } else if (mrp <= currentPurchasePrice) {
                    $('#discount').val('');
                }
                
                // Recalculate prices
                updatePriceCalculations();
            }
        }
    });
    
    // Enter key adds product
    $(document).on('keydown', function(e) {
        if (e.key === 'Enter' && ($('#quantity').is(':focus') || $('#purchasePrice').is(':focus'))) {
            e.preventDefault();
            addProductToCart();
        }
        
        // Alt+P to focus on product search
        if (e.altKey && e.key === 'p') {
            e.preventDefault();
            $('.select2-products').select2('open');
        }
        
        // Alt+A to add product
        if (e.altKey && e.key === 'a') {
            e.preventDefault();
            addProductToCart();
        }
        
        // Alt+Q to open quick add modal
        if (e.altKey && e.key === 'q') {
            e.preventDefault();
            openQuickAddProductModal();
        }
    });
    
    // Barcode scanner simulation
    let barcodeBuffer = '';
    let lastKeyTime = 0;
    
    $(document).on('keypress', function(e) {
        const currentTime = new Date().getTime();
        
        if (currentTime - lastKeyTime > 100) {
            barcodeBuffer = '';
        }
        
        barcodeBuffer += e.key;
        lastKeyTime = currentTime;
        
        if (e.key === 'Enter' && barcodeBuffer.length > 3) {
            const barcode = barcodeBuffer.slice(0, -1);
            handleBarcodeScan(barcode);
            barcodeBuffer = '';
            e.preventDefault();
        }
    });
    
    // Handle paste events for barcode scanning
    $(document).on('paste', function(e) {
        const pastedData = e.originalEvent.clipboardData.getData('text');
        if (pastedData && pastedData.trim().length > 3) {
            setTimeout(() => {
                handleBarcodeScan(pastedData.trim());
            }, 100);
        }
    });
});
</script>

<script>
async function refreshPurchaseNumberByDate() {
    const purchaseNumberInput = document.getElementById('purchase_number');
    const purchaseDateInput = document.getElementById('purchase_date');

    if (!purchaseNumberInput || !purchaseDateInput) {
        return;
    }

    try {
        const response = await fetch('api/number-generator.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                document_type: 'purchase',
                purchase_date: purchaseDateInput.value
            })
        });

        const data = await response.json();

        if (data.success) {
            purchaseNumberInput.value = data.document_number || data.number || purchaseNumberInput.value;
        } else {
            console.warn('Purchase number API message:', data.message || data);
        }
    } catch (error) {
        console.error('Purchase number refresh failed:', error);
    }
}

function calculateDueDateFromDays() {
    const purchaseDateInput = document.getElementById('purchase_date');
    const dueDaysSelect = document.getElementById('due_days_option');
    const customBox = document.getElementById('custom_due_days_box');
    const customInput = document.getElementById('custom_due_days');
    const dueDateInput = document.getElementById('due_date');

    if (!purchaseDateInput || !dueDaysSelect || !dueDateInput) {
        return;
    }

    const purchaseDate = purchaseDateInput.value;
    if (!purchaseDate) {
        return;
    }

    let days = 0;

    if (dueDaysSelect.value === 'custom') {
        if (customBox) {
            customBox.style.display = '';
        }
        days = parseInt(customInput ? customInput.value : '0', 10) || 0;
    } else {
        if (customBox) {
            customBox.style.display = 'none';
        }
        if (customInput) {
            customInput.value = '';
        }
        days = parseInt(dueDaysSelect.value, 10) || 0;
    }

    const dt = new Date(purchaseDate + 'T00:00:00');
    dt.setDate(dt.getDate() + days);

    const yyyy = dt.getFullYear();
    const mm = String(dt.getMonth() + 1).padStart(2, '0');
    const dd = String(dt.getDate()).padStart(2, '0');

    dueDateInput.value = `${yyyy}-${mm}-${dd}`;
}

document.addEventListener('DOMContentLoaded', function () {
    const purchaseDateInput = document.getElementById('purchase_date');
    const dueDaysSelect = document.getElementById('due_days_option');
    const customDueDaysInput = document.getElementById('custom_due_days');

    calculateDueDateFromDays();

    if (purchaseDateInput) {
        purchaseDateInput.addEventListener('change', function () {
            refreshPurchaseNumberByDate();
            calculateDueDateFromDays();
        });
    }

    if (dueDaysSelect) {
        dueDaysSelect.addEventListener('change', calculateDueDateFromDays);
    }

    if (customDueDaysInput) {
        customDueDaysInput.addEventListener('input', calculateDueDateFromDays);
    }
});
</script>
</body>
</html>