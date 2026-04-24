<?php
/* purchase-edit.php - GST inclusive/exclusive edit page
   Logic matched with add purchase page:
   - Inclusive: entered value is already final value.
   - Exclusive: entered value is without GST; final after GST is saved.
   - products.mrp, products.stock_price, products.retail_price, products.wholesale_price save final after-GST values for exclusive.
   - UI-calculated retail/wholesale final values are posted and saved.
*/

date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'stock_manager'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$current_business_id = $_SESSION['current_business_id'] ?? null;

if (!$current_business_id) {
    $_SESSION['error'] = 'Please select a business first.';
    header('Location: select_shop.php');
    exit();
}
$current_business_id = (int)$current_business_id;

$purchase_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($purchase_id <= 0) {
    $_SESSION['error'] = 'Invalid purchase ID.';
    header('Location: purchases.php');
    exit();
}

function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function dbColumnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function finalFromEntered(float $value, float $gstRate, string $gstType): float {
    if ($gstType === 'exclusive') {
        return $value + ($value * $gstRate / 100);
    }
    return $value;
}

$hasPurchaseItemGstType = dbColumnExists($pdo, 'purchase_items', 'gst_type');
$hasBatchGstType = dbColumnExists($pdo, 'purchase_batches', 'gst_type');
$hasProductGstType = dbColumnExists($pdo, 'products', 'gst_type');

$shop_id = (int)($_SESSION['current_shop_id'] ?? 1);
$shop_name_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
$shop_name_stmt->execute([$shop_id, $current_business_id]);
$shop_name = $shop_name_stmt->fetchColumn() ?: 'Shop';

$warehouse_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE is_warehouse = 1 AND business_id = ? LIMIT 1");
$warehouse_stmt->execute([$current_business_id]);
$warehouse = $warehouse_stmt->fetch(PDO::FETCH_ASSOC);
$warehouse_id = (int)($warehouse['id'] ?? 0);

$purchase_stmt = $pdo->prepare("\n    SELECT p.*, m.name AS manufacturer_name, s.shop_name AS receiving_shop_name\n    FROM purchases p\n    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id AND m.business_id = p.business_id\n    LEFT JOIN shops s ON p.shop_id = s.id AND s.business_id = p.business_id\n    WHERE p.id = ? AND p.business_id = ?\n");
$purchase_stmt->execute([$purchase_id, $current_business_id]);
$purchase = $purchase_stmt->fetch(PDO::FETCH_ASSOC);

if (!$purchase) {
    $_SESSION['error'] = 'Purchase order not found.';
    header('Location: purchases.php');
    exit();
}

$selectGstType = $hasPurchaseItemGstType ? "pi.gst_type AS item_gst_type," : "NULL AS item_gst_type,";
$items_stmt = $pdo->prepare("\n    SELECT pi.*, $selectGstType\n           p.product_name, p.product_code, p.secondary_unit, p.sec_unit_conversion,\n           p.retail_price_type, p.retail_price_value,\n           p.wholesale_price_type, p.wholesale_price_value,\n           p.stock_price AS original_stock_price, p.mrp AS product_mrp,\n           " . ($hasProductGstType ? "p.gst_type AS product_gst_type," : "'inclusive' AS product_gst_type,") . "\n           pb.id AS batch_id, pb.batch_number, pb.manufacture_date, pb.expiry_date,\n           pb.quantity_remaining AS batch_quantity_remaining,\n           pb.old_mrp, pb.new_mrp AS batch_mrp,\n           pb.old_retail_price, pb.retail_price AS batch_retail_price,\n           pb.old_wholesale_price, pb.wholesale_price AS batch_wholesale_price\n    FROM purchase_items pi\n    JOIN products p ON pi.product_id = p.id AND p.business_id = pi.business_id\n    LEFT JOIN purchase_batches pb ON pi.purchase_id = pb.purchase_id\n        AND pi.product_id = pb.product_id AND pb.business_id = pi.business_id\n    WHERE pi.purchase_id = ? AND pi.business_id = ?\n    ORDER BY pi.id\n");
$items_stmt->execute([$purchase_id, $current_business_id]);
$purchase_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

$manufacturers = $pdo->prepare("SELECT id, name FROM manufacturers WHERE business_id = ? AND is_active = 1 ORDER BY name");
$manufacturers->execute([$current_business_id]);
$manufacturers = $manufacturers->fetchAll(PDO::FETCH_ASSOC);

$shops = $pdo->prepare("SELECT id, shop_name, location_type, is_warehouse FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY is_warehouse DESC, shop_name");
$shops->execute([$current_business_id]);
$shops = $shops->fetchAll(PDO::FETCH_ASSOC);

$upload_dir = 'uploads/purchase_bills/';
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
$max_file_size = 10 * 1024 * 1024;
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manufacturer_id = (int)($_POST['manufacturer_id'] ?? 0);
    $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
    $reference = trim($_POST['reference'] ?? '');
    $purchase_invoice_no = trim($_POST['purchase_invoice_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $shop_id = (int)($_POST['shop_id'] ?? 0);
    $items = $_POST['items'] ?? [];
    $payment_status = $_POST['payment_status'] ?? 'unpaid';
    $paid_amount = (float)($_POST['paid_amount'] ?? 0);

    if ($manufacturer_id <= 0 || $shop_id <= 0 || empty($items)) {
        $error = 'Please select supplier, receiving shop and add at least one product.';
    } else {
        try {
            $pdo->beginTransaction();

            $bill_image_path = $purchase['bill_image'] ?? null;
            if (isset($_FILES['bill_image']) && $_FILES['bill_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['bill_image'];
                $file_ext = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
                if (!in_array($file_ext, $allowed_extensions, true)) {
                    throw new Exception('Invalid file type. Only JPG, PNG, GIF, WEBP, PDF allowed.');
                }
                if ((int)$file['size'] > $max_file_size) {
                    throw new Exception('File too large (max 10MB).');
                }
                if ($bill_image_path && file_exists($bill_image_path)) {
                    @unlink($bill_image_path);
                }
                $unique_name = uniqid('bill_', true) . '_' . time() . '.' . $file_ext;
                $bill_image_path = $upload_dir . $unique_name;
                if (!move_uploaded_file($file['tmp_name'], $bill_image_path)) {
                    throw new Exception('Upload failed.');
                }
            }

            $update_purchase = $pdo->prepare("\n                UPDATE purchases\n                SET manufacturer_id = ?, purchase_date = ?, reference = ?,\n                    purchase_invoice_no = ?, bill_image = ?, notes = ?,\n                    shop_id = ?, payment_status = ?, paid_amount = ?,\n                    updated_at = NOW()\n                WHERE id = ? AND business_id = ?\n            ");
            $update_purchase->execute([
                $manufacturer_id, $purchase_date, $reference, $purchase_invoice_no ?: null,
                $bill_image_path, $notes, $shop_id, $payment_status, $paid_amount,
                $purchase_id, $current_business_id
            ]);

            /* Restore old stock from this purchase before re-applying edited items */
            $old_items_stmt = $pdo->prepare("\n                SELECT pi.product_id, pi.quantity\n                FROM purchase_items pi\n                WHERE pi.purchase_id = ? AND pi.business_id = ?\n            ");
            $old_items_stmt->execute([$purchase_id, $current_business_id]);
            $old_items = $old_items_stmt->fetchAll(PDO::FETCH_ASSOC);
            $old_shop_id = (int)($purchase['shop_id'] ?? $shop_id);

            foreach ($old_items as $oldItem) {
                $oldPid = (int)$oldItem['product_id'];
                $oldQty = (float)$oldItem['quantity'];
                $stock_stmt = $pdo->prepare("\n                    SELECT id, quantity\n                    FROM product_stocks\n                    WHERE product_id = ? AND shop_id = ? AND business_id = ?\n                    LIMIT 1\n                ");
                $stock_stmt->execute([$oldPid, $old_shop_id, $current_business_id]);
                $stock = $stock_stmt->fetch(PDO::FETCH_ASSOC);
                if ($stock) {
                    $newQty = max(0, (float)$stock['quantity'] - $oldQty);
                    $pdo->prepare("UPDATE product_stocks SET quantity = ?, last_updated = NOW() WHERE id = ?")
                        ->execute([$newQty, (int)$stock['id']]);
                }
            }

            $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ? AND business_id = ?")->execute([$purchase_id, $current_business_id]);
            $pdo->prepare("DELETE FROM purchase_batches WHERE purchase_id = ? AND business_id = ?")->execute([$purchase_id, $current_business_id]);
            $pdo->prepare("DELETE FROM gst_credits WHERE purchase_id = ? AND business_id = ?")->execute([$purchase_id, $current_business_id]);

            $grand_total = 0;
            $total_gst = 0;
            $total_credit_amount = 0;

            foreach ($items as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $qty = (float)($item['quantity'] ?? 0);
                $entered_mrp = (float)($item['mrp'] ?? 0);
                $entered_purchase_price = (float)($item['purchase_price'] ?? 0);
                $posted_retail_price = (float)($item['retail_price'] ?? 0);
                $posted_wholesale_price = (float)($item['wholesale_price'] ?? 0);
                $discount_input = trim($item['discount'] ?? '');
                $cgst = (float)($item['cgst_rate'] ?? 0);
                $sgst = (float)($item['sgst_rate'] ?? 0);
                $igst = (float)($item['igst_rate'] ?? 0);
                $gst_type = strtolower(trim($item['gst_type'] ?? 'inclusive'));
                if (!in_array($gst_type, ['inclusive', 'exclusive'], true)) {
                    $gst_type = 'inclusive';
                }
                $batch_number = !empty($item['batch_number']) ? trim($item['batch_number']) : null;
                $expiry_date = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
                $manufacture_date = !empty($item['manufacture_date']) ? $item['manufacture_date'] : null;

                if ($pid <= 0 || $qty <= 0 || $entered_mrp < 0 || $entered_purchase_price < 0) {
                    continue;
                }

                $product_stmt = $pdo->prepare("\n                    SELECT p.*,\n                           " . ($hasProductGstType ? "p.gst_type AS product_gst_type," : "'inclusive' AS product_gst_type,") . "\n                           COALESCE(g.cgst_rate, 0) AS cgst_rate,\n                           COALESCE(g.sgst_rate, 0) AS sgst_rate,\n                           COALESCE(g.igst_rate, 0) AS igst_rate\n                    FROM products p\n                    LEFT JOIN gst_rates g ON p.gst_id = g.id AND g.business_id = p.business_id\n                    WHERE p.id = ? AND p.business_id = ?\n                ");
                $product_stmt->execute([$pid, $current_business_id]);
                $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) {
                    throw new Exception('Product not found');
                }

                $total_gst_rate = $cgst + $sgst + $igst;
                $entered_value = $qty * $entered_purchase_price;

                if ($gst_type === 'exclusive') {
                    $taxable_amount = $entered_value;
                    $cgst_amt = $taxable_amount * $cgst / 100;
                    $sgst_amt = $taxable_amount * $sgst / 100;
                    $igst_amt = $taxable_amount * $igst / 100;
                    $total_with_tax = $entered_value + $cgst_amt + $sgst_amt + $igst_amt;

                    $purchase_price = finalFromEntered($entered_purchase_price, $total_gst_rate, 'exclusive');
                    $mrp = finalFromEntered($entered_mrp, $total_gst_rate, 'exclusive');
                } else {
                    $total_with_tax = $entered_value;
                    $taxable_amount = $total_gst_rate > 0 ? $entered_value / (1 + $total_gst_rate / 100) : $entered_value;
                    $cgst_amt = $taxable_amount * $cgst / 100;
                    $sgst_amt = $taxable_amount * $sgst / 100;
                    $igst_amt = $taxable_amount * $igst / 100;

                    $purchase_price = $entered_purchase_price;
                    $mrp = $entered_mrp;
                }

                /* Use UI-calculated final retail/wholesale if posted. Fallback to backend markup logic. */
                if ($posted_retail_price > 0) {
                    $retail_price = $posted_retail_price;
                } else {
                    $retail_base = $entered_purchase_price;
                    if ((float)$product['retail_price_value'] > 0) {
                        if ($product['retail_price_type'] === 'percentage') {
                            $retail_base = $entered_purchase_price + ($entered_purchase_price * (float)$product['retail_price_value'] / 100);
                        } else {
                            $retail_base = $entered_purchase_price + (float)$product['retail_price_value'];
                        }
                    }
                    $retail_price = finalFromEntered($retail_base, $total_gst_rate, $gst_type);
                }

                if ($posted_wholesale_price > 0) {
                    $wholesale_price = $posted_wholesale_price;
                } else {
                    $wholesale_base = $entered_purchase_price;
                    if ((float)$product['wholesale_price_value'] > 0) {
                        if ($product['wholesale_price_type'] === 'percentage') {
                            $wholesale_base = $entered_purchase_price + ($entered_purchase_price * (float)$product['wholesale_price_value'] / 100);
                        } else {
                            $wholesale_base = $entered_purchase_price + (float)$product['wholesale_price_value'];
                        }
                    }
                    $wholesale_price = finalFromEntered($wholesale_base, $total_gst_rate, $gst_type);
                }

                $hsn_stmt = $pdo->prepare("SELECT hsn_code FROM products WHERE id = ? AND business_id = ?");
                $hsn_stmt->execute([$pid, $current_business_id]);
                $hsn_code = $hsn_stmt->fetchColumn() ?: '';

                if ($hasPurchaseItemGstType) {
                    $insert_item_sql = "\n                        INSERT INTO purchase_items\n                        (purchase_id, product_id, quantity, mrp, discount, discount_type, discount_value,\n                         purchase_price, retail_price, wholesale_price, hsn_code, gst_type,\n                         cgst_rate, sgst_rate, igst_rate,\n                         cgst_amount, sgst_amount, igst_amount, total_price, business_id)\n                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n                    ";
                    $insert_item_params = [
                        $purchase_id, $pid, $qty, $mrp, $discount_input, 'percentage',
                        (float)str_replace('%', '', $discount_input) ?: 0,
                        $purchase_price, $retail_price, $wholesale_price, $hsn_code, $gst_type,
                        $cgst, $sgst, $igst, $cgst_amt, $sgst_amt, $igst_amt, $total_with_tax, $current_business_id
                    ];
                } else {
                    $insert_item_sql = "\n                        INSERT INTO purchase_items\n                        (purchase_id, product_id, quantity, mrp, discount, discount_type, discount_value,\n                         purchase_price, retail_price, wholesale_price, hsn_code,\n                         cgst_rate, sgst_rate, igst_rate,\n                         cgst_amount, sgst_amount, igst_amount, total_price, business_id)\n                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n                    ";
                    $insert_item_params = [
                        $purchase_id, $pid, $qty, $mrp, $discount_input, 'percentage',
                        (float)str_replace('%', '', $discount_input) ?: 0,
                        $purchase_price, $retail_price, $wholesale_price, $hsn_code,
                        $cgst, $sgst, $igst, $cgst_amt, $sgst_amt, $igst_amt, $total_with_tax, $current_business_id
                    ];
                }

                $stmt = $pdo->prepare($insert_item_sql);
                $stmt->execute($insert_item_params);
                $purchase_item_id = (int)$pdo->lastInsertId();

                $grand_total += $total_with_tax;
                $total_gst += $cgst_amt + $sgst_amt + $igst_amt;
                $total_credit_amount += $cgst_amt + $sgst_amt + $igst_amt;

                $stock_check = $pdo->prepare("\n                    SELECT id, quantity, old_qty, total_secondary_units\n                    FROM product_stocks\n                    WHERE product_id = ? AND shop_id = ? AND business_id = ?\n                    LIMIT 1\n                ");
                $stock_check->execute([$pid, $shop_id, $current_business_id]);
                $stock_record = $stock_check->fetch(PDO::FETCH_ASSOC);
                $old_qty = $stock_record ? (float)$stock_record['quantity'] : 0;
                $is_first_stock = (!$stock_record || $old_qty <= 0);

                $prev_batch_stmt = $pdo->prepare("\n                    SELECT purchase_price, selling_price, retail_price, wholesale_price, old_retail_price, old_wholesale_price\n                    FROM purchase_batches\n                    WHERE product_id = ? AND business_id = ?\n                    ORDER BY received_date DESC, id DESC\n                    LIMIT 1\n                ");
                $prev_batch_stmt->execute([$pid, $current_business_id]);
                $prev_batch = $prev_batch_stmt->fetch(PDO::FETCH_ASSOC);

                $old_purchase_price = $prev_batch ? (float)$prev_batch['purchase_price'] : (float)$product['stock_price'];
                $old_retail_price = $prev_batch ? (float)$prev_batch['retail_price'] : (float)$product['retail_price'];
                $old_wholesale_price = $prev_batch ? (float)$prev_batch['wholesale_price'] : (float)$product['wholesale_price'];
                $old_selling_price = $prev_batch ? (float)$prev_batch['selling_price'] : (float)$product['retail_price'];

                $is_increase = $purchase_price > (float)$product['stock_price'];
                $is_decrease = $purchase_price < (float)$product['stock_price'];
                $batch_number = $batch_number ?: 'BATCH-' . date('Ymd') . '-' . str_pad((string)$purchase_item_id, 4, '0', STR_PAD_LEFT);

                if ($hasBatchGstType) {
                    $batch_sql = "\n                        INSERT INTO purchase_batches\n                        (business_id, product_id, purchase_id, shop_id, batch_number,\n                         purchase_price, old_purchase_price,\n                         selling_price, old_selling_price,\n                         old_mrp, new_mrp,\n                         retail_price, old_retail_price,\n                         wholesale_price, old_wholesale_price,\n                         gst_type,\n                         quantity_received, quantity_remaining,\n                         received_date, manufacture_date, expiry_date, notes,\n                         is_increase, is_decrease, created_at)\n                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n                    ";
                    $batch_params = [
                        $current_business_id, $pid, $purchase_id, $shop_id, $batch_number,
                        $purchase_price, $old_purchase_price,
                        $retail_price, $old_selling_price,
                        (float)$product['mrp'], $mrp,
                        $retail_price, $old_retail_price,
                        $wholesale_price, $old_wholesale_price,
                        $gst_type,
                        $qty, $qty,
                        $purchase_date, $manufacture_date, $expiry_date,
                        'Batch from edited purchase ' . $purchase['purchase_number'],
                        $is_increase ? 1 : 0, $is_decrease ? 1 : 0
                    ];
                } else {
                    $batch_sql = "\n                        INSERT INTO purchase_batches\n                        (business_id, product_id, purchase_id, shop_id, batch_number,\n                         purchase_price, old_purchase_price,\n                         selling_price, old_selling_price,\n                         old_mrp, new_mrp,\n                         retail_price, old_retail_price,\n                         wholesale_price, old_wholesale_price,\n                         quantity_received, quantity_remaining,\n                         received_date, manufacture_date, expiry_date, notes,\n                         is_increase, is_decrease, created_at)\n                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n                    ";
                    $batch_params = [
                        $current_business_id, $pid, $purchase_id, $shop_id, $batch_number,
                        $purchase_price, $old_purchase_price,
                        $retail_price, $old_selling_price,
                        (float)$product['mrp'], $mrp,
                        $retail_price, $old_retail_price,
                        $wholesale_price, $old_wholesale_price,
                        $qty, $qty,
                        $purchase_date, $manufacture_date, $expiry_date,
                        'Batch from edited purchase ' . $purchase['purchase_number'],
                        $is_increase ? 1 : 0, $is_decrease ? 1 : 0
                    ];
                }

                $batch_stmt = $pdo->prepare($batch_sql);
                $batch_stmt->execute($batch_params);
                $batch_id = (int)$pdo->lastInsertId();

                $total_secondary_units = null;
                if (!empty($product['sec_unit_conversion']) && (float)$product['sec_unit_conversion'] > 0) {
                    $total_secondary_units = $qty * (float)$product['sec_unit_conversion'];
                }

                if ($stock_record) {
                    $new_quantity = $old_qty + $qty;
                    $new_secondary_units = $stock_record['total_secondary_units'];
                    if ($total_secondary_units !== null) {
                        $new_secondary_units = ((float)($new_secondary_units ?? 0)) + $total_secondary_units;
                    }
                    $pdo->prepare("\n                        UPDATE product_stocks\n                        SET quantity = ?, old_qty = ?, total_secondary_units = ?,\n                            use_batch_tracking = 1, batch_id = ?, last_updated = NOW()\n                        WHERE id = ?\n                    ")->execute([$new_quantity, $old_qty, $new_secondary_units, $batch_id, (int)$stock_record['id']]);
                    $stock_id = (int)$stock_record['id'];
                } else {
                    $pdo->prepare("\n                        INSERT INTO product_stocks\n                        (product_id, shop_id, business_id, quantity, old_qty, total_secondary_units, use_batch_tracking, batch_id, last_updated)\n                        VALUES (?, ?, ?, ?, 0, ?, 1, ?, NOW())\n                    ")->execute([$pid, $shop_id, $current_business_id, $qty, $total_secondary_units, $batch_id]);
                    $stock_id = (int)$pdo->lastInsertId();
                }

                try {
                    $movement_stmt = $pdo->prepare("\n                        INSERT INTO stock_movements\n                        (product_id, stock_id, shop_id, business_id, movement_type, quantity, secondary_quantity,\n                         reference_type, reference_id, notes, created_by, created_at)\n                        VALUES (?, ?, ?, ?, 'purchase_edit_add', ?, ?, 'purchase', ?, ?, ?, NOW())\n                    ");
                    $movement_stmt->execute([
                        $pid, $stock_id, $shop_id, $current_business_id, $qty, $total_secondary_units ?? 0,
                        $purchase_id, 'Stock added from edited purchase #' . $purchase['purchase_number'], $user_id
                    ]);
                } catch (Throwable $ignored) {}

                /* Update product prices. If product stock was 0, update all main price fields. */
                if ($is_first_stock) {
                    if ($hasProductGstType) {
                        $pdo->prepare("\n                            UPDATE products\n                            SET mrp = ?, stock_price = ?, retail_price = ?, wholesale_price = ?, gst_type = ?, updated_at = NOW()\n                            WHERE id = ? AND business_id = ?\n                        ")->execute([$mrp, $purchase_price, $retail_price, $wholesale_price, $gst_type, $pid, $current_business_id]);
                    } else {
                        $pdo->prepare("\n                            UPDATE products\n                            SET mrp = ?, stock_price = ?, retail_price = ?, wholesale_price = ?, updated_at = NOW()\n                            WHERE id = ? AND business_id = ?\n                        ")->execute([$mrp, $purchase_price, $retail_price, $wholesale_price, $pid, $current_business_id]);
                    }
                } else {
                    $update_fields = [];
                    $update_params = [];
                    if (abs($mrp - (float)$product['mrp']) > 0.01) {
                        $update_fields[] = 'mrp = ?';
                        $update_params[] = $mrp;
                    }
                    if (abs($retail_price - (float)$product['retail_price']) > 0.01) {
                        $update_fields[] = 'retail_price = ?';
                        $update_params[] = $retail_price;
                    }
                    if (abs($wholesale_price - (float)$product['wholesale_price']) > 0.01) {
                        $update_fields[] = 'wholesale_price = ?';
                        $update_params[] = $wholesale_price;
                    }
                    if (!empty($update_fields)) {
                        $update_fields[] = 'updated_at = NOW()';
                        $update_params[] = $pid;
                        $update_params[] = $current_business_id;
                        $pdo->prepare('UPDATE products SET ' . implode(', ', $update_fields) . ' WHERE id = ? AND business_id = ?')
                            ->execute($update_params);
                    }
                }
            }

            $pdo->prepare("UPDATE purchases SET total_amount = ?, total_gst = ? WHERE id = ? AND business_id = ?")
                ->execute([$grand_total, $total_gst, $purchase_id, $current_business_id]);

            if ($total_credit_amount > 0) {
                $gstmt = $pdo->prepare("\n                    INSERT INTO gst_credits\n                    (business_id, purchase_id, purchase_number, purchase_invoice_no, credit_amount, status, created_at)\n                    VALUES (?, ?, ?, ?, ?, 'not_claimed', NOW())\n                ");
                $gstmt->execute([$current_business_id, $purchase_id, $purchase['purchase_number'], $purchase_invoice_no ?: null, $total_credit_amount]);
            }

            $pdo->commit();
            header('Location: purchase_edit.php?id=' . $purchase_id . '&success=1');
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            if (isset($bill_image_path) && $bill_image_path !== ($purchase['bill_image'] ?? '') && file_exists($bill_image_path)) {
                @unlink($bill_image_path);
            }
            $error = 'Failed to update purchase: ' . $e->getMessage();
        }
    }
}

$prodSql = "SELECT p.id, p.product_name, p.product_code, p.barcode,
                   p.stock_price, p.mrp, p.retail_price, p.wholesale_price,
                   p.retail_price_type, p.retail_price_value,
                   p.wholesale_price_type, p.wholesale_price_value,
                   p.secondary_unit, p.sec_unit_conversion,
                   p.hsn_code, " . ($hasProductGstType ? "p.gst_type," : "'inclusive' AS gst_type,") . "
                   COALESCE(g.cgst_rate, 0) AS cgst_rate,
                   COALESCE(g.sgst_rate, 0) AS sgst_rate,
                   COALESCE(g.igst_rate, 0) AS igst_rate,
                   c.category_name,
                   COALESCE(ps_shop.quantity, 0) AS shop_stock,
                   COALESCE(ps_shop.old_qty, 0) AS shop_old_qty,
                   COALESCE(ps_shop.total_secondary_units, 0) AS shop_secondary_units,
                   COALESCE(ps_shop.use_batch_tracking, 0) AS use_batch_tracking,
                   COALESCE(ps_warehouse.quantity, 0) AS warehouse_stock,
                   COALESCE(ps_warehouse.total_secondary_units, 0) AS warehouse_secondary_units,
                   (SELECT purchase_price FROM purchase_batches pb WHERE pb.product_id = p.id AND pb.business_id = p.business_id AND pb.quantity_remaining > 0 ORDER BY pb.received_date DESC, pb.id DESC LIMIT 1) AS last_batch_price,
                   (SELECT retail_price FROM purchase_batches pb WHERE pb.product_id = p.id AND pb.business_id = p.business_id AND pb.quantity_remaining > 0 ORDER BY pb.received_date DESC, pb.id DESC LIMIT 1) AS last_batch_retail_price,
                   (SELECT wholesale_price FROM purchase_batches pb WHERE pb.product_id = p.id AND pb.business_id = p.business_id AND pb.quantity_remaining > 0 ORDER BY pb.received_date DESC, pb.id DESC LIMIT 1) AS last_batch_wholesale_price,
                   (SELECT old_mrp FROM purchase_batches pb WHERE pb.product_id = p.id AND pb.business_id = p.business_id AND pb.quantity_remaining > 0 ORDER BY pb.received_date DESC, pb.id DESC LIMIT 1) AS last_batch_old_mrp,
                   (SELECT new_mrp FROM purchase_batches pb WHERE pb.product_id = p.id AND pb.business_id = p.business_id AND pb.quantity_remaining > 0 ORDER BY pb.received_date DESC, pb.id DESC LIMIT 1) AS last_batch_new_mrp
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id AND c.business_id = p.business_id
            LEFT JOIN gst_rates g ON p.gst_id = g.id AND g.business_id = p.business_id
            LEFT JOIN product_stocks ps_shop ON p.id = ps_shop.product_id AND ps_shop.shop_id = ? AND ps_shop.business_id = p.business_id
            LEFT JOIN product_stocks ps_warehouse ON p.id = ps_warehouse.product_id AND ps_warehouse.shop_id = ? AND ps_warehouse.business_id = p.business_id
            WHERE p.is_active = 1 AND p.business_id = ?
            ORDER BY c.category_name, p.product_name";
$prodStmt = $pdo->prepare($prodSql);
$prodStmt->execute([$shop_id, $warehouse_id, $current_business_id]);
$prodRes = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

$jsProducts = [];
$barcodeMap = [];
foreach ($prodRes as $p) {
    $pid = (int)$p['id'];
    $stock_price = (float)$p['stock_price'];
    $retail_price = (float)$p['retail_price'];
    $wholesale_price = (float)$p['wholesale_price'];
    $retail_markup_percent = ($stock_price > 0 && $retail_price > $stock_price) ? (($retail_price - $stock_price) / $stock_price) * 100 : 0;
    $wholesale_markup_percent = ($stock_price > 0 && $wholesale_price > $stock_price) ? (($wholesale_price - $stock_price) / $stock_price) * 100 : 0;
    $code = $p['product_code'] ? h($p['product_code']) : sprintf('P%06d', $pid);
    $barcode = h($p['barcode'] ?? '');
    $cgst = (float)($p['cgst_rate'] ?? 0);
    $sgst = (float)($p['sgst_rate'] ?? 0);
    $igst = (float)($p['igst_rate'] ?? 0);
    $total_gst = $cgst + $sgst + $igst;

    $suggested_discount = '';
    if ((float)$p['mrp'] > 0 && $stock_price > 0 && (float)$p['mrp'] > $stock_price) {
        $suggested_discount = round((((float)$p['mrp'] - $stock_price) / (float)$p['mrp']) * 100, 1) . '%';
    }

    $jsProducts[$pid] = [
        'id' => $pid,
        'name' => h($p['product_name']),
        'mrp' => (float)$p['mrp'],
        'stock_price' => $stock_price,
        'retail_price' => $retail_price,
        'wholesale_price' => $wholesale_price,
        'retail_price_type' => $p['retail_price_type'],
        'retail_price_value' => (float)$p['retail_price_value'],
        'retail_markup_percent' => $retail_markup_percent,
        'wholesale_price_type' => $p['wholesale_price_type'],
        'wholesale_price_value' => (float)$p['wholesale_price_value'],
        'wholesale_markup_percent' => $wholesale_markup_percent,
        'suggested_discount' => $suggested_discount,
        'last_batch_price' => (float)$p['last_batch_price'],
        'last_batch_retail_price' => (float)$p['last_batch_retail_price'],
        'last_batch_wholesale_price' => (float)$p['last_batch_wholesale_price'],
        'last_batch_old_mrp' => (float)$p['last_batch_old_mrp'],
        'last_batch_new_mrp' => (float)$p['last_batch_new_mrp'],
        'use_batch_tracking' => (int)$p['use_batch_tracking'],
        'shop_old_qty' => (int)$p['shop_old_qty'],
        'code' => $code,
        'barcode' => $barcode,
        'shop_stock' => (int)$p['shop_stock'],
        'warehouse_stock' => (int)$p['warehouse_stock'],
        'total_stock' => (int)$p['shop_stock'] + (int)$p['warehouse_stock'],
        'shop_secondary' => (float)$p['shop_secondary_units'],
        'warehouse_secondary' => (float)$p['warehouse_secondary_units'],
        'secondary_unit' => h($p['secondary_unit'] ?? ''),
        'sec_unit_conversion' => (float)$p['sec_unit_conversion'],
        'hsn' => h($p['hsn_code'] ?? ''),
        'gst_type' => in_array(($p['gst_type'] ?? 'inclusive'), ['inclusive', 'exclusive'], true) ? $p['gst_type'] : 'inclusive',
        'cgst' => $cgst,
        'sgst' => $sgst,
        'igst' => $igst,
        'total_gst' => $total_gst,
        'category' => h($p['category_name'] ?? 'Uncategorized')
    ];

    if (!empty($p['barcode'])) $barcodeMap[$p['barcode']] = $pid;
    $barcodeMap[$code] = $pid;
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = 'Edit Purchase Order #' . h($purchase['purchase_number']); include 'includes/head.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
.purchase-items-container{overflow-y:visible;padding-right:0}.product-search-section{background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:.75rem;margin-bottom:.75rem}.stock-badge{font-size:.7rem;padding:2px 6px;border-radius:3px;margin-right:3px}.shop-stock-badge{background:#17a2b8;color:#fff}.warehouse-stock-badge{background:#6c757d;color:#fff}.gst-badge{background:#6f42c1;color:#fff}.batch-info-section{background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:.6rem;margin-top:.5rem;display:none}.batch-info-section.show{display:block}.product-details-card{background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:.6rem;margin-top:.5rem;display:none}.product-details-card.show{display:block}.selected-products-table{font-size:.78rem}.selected-products-table th{background:#f8f9fa;font-weight:600;white-space:nowrap}.selected-products-table td{vertical-align:middle;padding:.38rem .45rem}.price-calculation-section{background:#e7f4ff;border:1px solid #b6e0fe;border-radius:6px;padding:.6rem;margin-top:.5rem}.price-calculation-section input[readonly]{background-color:#f8f9fa}.select2-container--default .select2-selection--single{height:32px;border:1px solid #ced4da;border-radius:.375rem}.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:30px;font-size:.82rem}.select2-container--default .select2-selection--single .select2-selection__arrow{height:30px}.price-change-warning{background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:8px;margin-top:5px;font-size:.8rem}.bill-upload-section{background:#f0f9ff;border:2px dashed #0dcaf0;border-radius:8px;padding:.75rem;text-align:center;cursor:pointer}.bill-preview{max-width:140px;max-height:110px;margin:5px auto 0;display:none}.bill-preview img,.bill-preview embed{max-width:100%;max-height:110px;border:1px solid #dee2e6;border-radius:4px}.current-bill{margin-top:10px;padding:8px;background:#f8f9fa;border-radius:6px;border:1px solid #dee2e6}.markup-badge{font-size:.7rem;padding:1px 4px;border-radius:2px;background:#20c997;color:white;margin-left:3px}.manual-price-input{border-left:3px solid #007bff!important}.stock-change-warning{background:#fff3cd;border-left:4px solid #ffc107;padding:10px;margin-bottom:12px;border-radius:4px}.gst-value-hint{margin-top:3px;display:flex;flex-wrap:wrap;gap:3px;font-size:.68rem;line-height:1.2}.gst-value-hint span{display:inline-flex;align-items:center;padding:2px 5px;border-radius:999px;border:1px solid #d8e2ef;white-space:nowrap}.base-chip{background:#fff8e1;border-color:#ffe08a!important;color:#7a5700}.final-chip{background:#e7f4ff;border-color:#b6e0fe!important;color:#074f7a;font-weight:600}.gst-chip{background:#eef9f1;border-color:#b9e6c5!important;color:#176b2c}.gst-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:5px}.gst-summary-cell{background:#fff;border:1px solid #d8e2ef;border-radius:6px;padding:5px 7px}.gst-summary-cell small{display:block;color:#6c757d;font-size:.66rem}.gst-summary-cell strong{display:block;font-size:.78rem}.card{margin-bottom:.75rem}.card-header{padding:.55rem .85rem}.card-header h5{font-size:.96rem}.card-body{padding:.85rem}.form-label{font-size:.78rem;margin-bottom:.22rem}.form-control,.form-select{min-height:32px;height:32px;padding:.28rem .48rem;font-size:.82rem}textarea.form-control{height:auto;min-height:32px}.btn{padding:.32rem .65rem;font-size:.82rem}.alert{padding:.55rem .75rem;margin-bottom:.75rem}@media(max-width:767.98px){.gst-summary-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body data-sidebar="dark">
<div id="layout-wrapper">
<?php include 'includes/topbar.php'; ?>
<div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php')?></div></div>
<div class="main-content"><div class="page-content mb-4"><div class="container-fluid">
<div class="row mb-3"><div class="col-12"><div class="page-title-box d-flex align-items-center justify-content-between"><div><h4 class="mb-0"><i class="bx bx-edit me-2"></i> Edit Purchase Order <small class="text-muted ms-2">#<?= h($purchase['purchase_number']) ?></small></h4><p class="text-muted mb-0"><?= h($_SESSION['current_shop_name'] ?? 'All Shops') ?> | Business: <?= h($_SESSION['current_business_name'] ?? 'N/A') ?></p></div><div><a href="purchase_view.php?id=<?= $purchase_id ?>" class="btn btn-info me-2"><i class="bx bx-show me-1"></i> View</a><a href="purchases.php" class="btn btn-outline-secondary"><i class="bx bx-arrow-back me-1"></i> Back</a></div></div></div></div>
<?php if (isset($_GET['success'])): ?><div class="alert alert-success alert-dismissible fade show"><i class="bx bx-check-circle me-2"></i>Purchase order updated successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bx bx-error me-2"></i><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<div class="stock-change-warning"><i class="bx bx-info-circle me-2"></i><strong>Important:</strong> Editing this purchase will adjust stock quantities. Previous purchase stock is restored first, then edited stock is applied.</div>
<form method="POST" id="purchaseForm" enctype="multipart/form-data">
<div class="row g-3">
<div class="col-12"><div class="card border-start border-primary border-4 shadow-sm"><div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bx bx-detail me-2"></i> Purchase Details</h5></div><div class="card-body"><div class="row g-2">
<div class="col-md-3"><label class="form-label fw-bold">Purchase Number</label><input type="text" class="form-control bg-light" value="<?= h($purchase['purchase_number']) ?>" readonly></div>
<div class="col-md-3"><label class="form-label fw-bold">Purchase Date <span class="text-danger">*</span></label><input type="date" name="purchase_date" class="form-control" value="<?= h($purchase['purchase_date']) ?>" required></div>
<div class="col-md-3"><label class="form-label fw-bold">Supplier <span class="text-danger">*</span></label><select name="manufacturer_id" class="form-select select2-supplier" required><option value="">-- Select Supplier --</option><?php foreach ($manufacturers as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === (int)$purchase['manufacturer_id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label fw-bold">Receive Stock At <span class="text-danger">*</span></label><select name="shop_id" class="form-select select2-shop" required><option value="">-- Select Location --</option><?php foreach ($shops as $shop): ?><option value="<?= (int)$shop['id'] ?>" <?= (int)$shop['id'] === (int)$purchase['shop_id'] ? 'selected' : '' ?>><?= h($shop['shop_name']) ?><?= $shop['is_warehouse'] ? ' (Warehouse)' : '' ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label fw-bold">Purchase Invoice No.</label><input type="text" name="purchase_invoice_no" class="form-control" value="<?= h($purchase['purchase_invoice_no'] ?? '') ?>"></div>
<div class="col-md-3"><label class="form-label fw-bold">Bill/Reference No.</label><input type="text" name="reference" class="form-control" value="<?= h($purchase['reference'] ?? '') ?>"></div>
<div class="col-md-2"><label class="form-label">Payment Status</label><select name="payment_status" class="form-select"><option value="unpaid" <?= $purchase['payment_status'] === 'unpaid' ? 'selected' : '' ?>>Unpaid</option><option value="partial" <?= $purchase['payment_status'] === 'partial' ? 'selected' : '' ?>>Partial</option><option value="paid" <?= $purchase['payment_status'] === 'paid' ? 'selected' : '' ?>>Paid</option></select></div>
<div class="col-md-2"><label class="form-label">Paid Amount</label><input type="number" name="paid_amount" step="0.01" min="0" class="form-control" value="<?= h($purchase['paid_amount'] ?? 0) ?>"></div>
<div class="col-md-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="1"><?= h($purchase['notes'] ?? '') ?></textarea></div>
<div class="col-12"><label class="form-label fw-bold">Bill Image</label><?php if (!empty($purchase['bill_image']) && file_exists($purchase['bill_image'])): ?><div class="current-bill mb-2"><a href="<?= h($purchase['bill_image']) ?>" target="_blank"><i class="bx bx-file me-1"></i> View Current Bill</a></div><?php endif; ?><div class="bill-upload-section w-100" onclick="document.getElementById('billImage').click()"><i class="bx bx-cloud-upload fs-3 text-primary"></i><p class="mb-0">Click to upload new bill image</p><small class="text-muted">JPG, PNG, GIF, WEBP, PDF (Max 10MB)</small><input type="file" name="bill_image" id="billImage" class="d-none" accept="image/*,.pdf"><div id="billPreview" class="bill-preview"></div></div></div>
</div></div></div></div>
<div class="col-12"><div class="card shadow-sm"><div class="card-header bg-light"><div class="d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="bx bx-package me-2"></i> Purchase Items</h5><span class="badge bg-primary" id="itemCount">0 Items</span></div></div><div class="card-body purchase-items-container">
<div class="product-search-section"><div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0"><i class="bx bx-search me-2"></i> Add Products</h5></div><div class="row g-2">
<div class="col-md-12"><label class="form-label">Search Product</label><select id="productSelect" class="form-control select2-products"><option value=""></option></select></div>
<div class="col-md-3"><label class="form-label">Total Stock</label><input type="text" id="stockDisplay" class="form-control bg-white" readonly value="0"></div>
<div class="col-md-3"><label class="form-label">MRP <span class="text-danger">*</span></label><input type="number" step="0.01" min="0" id="mrp" class="form-control" value="0"><div id="mrpGstHint" class="gst-value-hint"><span class="base-chip">Without GST: ₹0.00</span><span class="final-chip">After GST: ₹0.00</span></div></div>
<div class="col-md-3"><label class="form-label">Discount</label><input type="text" id="discount" class="form-control" placeholder="e.g., 30% or 100"></div>
<div class="col-md-3"><label class="form-label">Purchase Price <span class="text-danger">*</span></label><input type="number" step="0.01" min="0" id="purchasePrice" class="form-control manual-price-input" value="0"><small class="text-muted">Manual entry</small><div id="purchasePriceGstHint" class="gst-value-hint"><span class="base-chip">Without GST: ₹0.00</span><span class="gst-chip">GST: ₹0.00</span><span class="final-chip">After GST: ₹0.00</span></div></div>
<div class="col-md-3"><label class="form-label">Quantity <span class="text-danger">*</span></label><input type="number" id="quantity" class="form-control" min="1" value="1"></div>
<div class="col-md-12"><div id="priceCalculation" class="price-calculation-section" style="display:none;"><div class="row g-2"><div class="col-md-4"><label class="form-label small">Calculated Purchase Price</label><input type="number" step="0.01" id="calculatedPurchasePrice" class="form-control bg-light" readonly><div id="calculatedPurchasePriceGstHint" class="gst-value-hint"></div></div><div class="col-md-4"><label class="form-label small">Retail Price <span id="retailMarkupBadge"></span></label><input type="number" step="0.01" id="retailPrice" class="form-control bg-light" readonly><div id="retailPriceGstHint" class="gst-value-hint"></div></div><div class="col-md-4"><label class="form-label small">Wholesale Price <span id="wholesaleMarkupBadge"></span></label><input type="number" step="0.01" id="wholesalePrice" class="form-control bg-light" readonly><div id="wholesalePriceGstHint" class="gst-value-hint"></div></div></div></div></div>
<div class="col-md-12"><div class="row g-2 align-items-end"><div class="col-md-2 col-6"><label class="form-label small">GST Type</label><select id="gstType" class="form-select"><option value="inclusive">Inclusive</option><option value="exclusive">Exclusive</option></select></div><div class="col-md-2 col-6"><label class="form-label small">CGST %</label><input type="number" step="0.01" id="cgstRate" class="form-control" value="0"></div><div class="col-md-2 col-6"><label class="form-label small">SGST %</label><input type="number" step="0.01" id="sgstRate" class="form-control" value="0"></div><div class="col-md-2 col-6"><label class="form-label small">IGST %</label><input type="number" step="0.01" id="igstRate" class="form-control" value="0"></div><div class="col-md-4 col-12"><label class="form-label small">GST Calculation</label><div id="gstBreakupBox" class="alert alert-light border mb-0 small"><div class="gst-summary-grid"><div class="gst-summary-cell"><small>Without GST</small><strong>₹0.00</strong></div><div class="gst-summary-cell"><small>GST</small><strong>₹0.00</strong></div><div class="gst-summary-cell"><small>Final Value</small><strong>₹0.00</strong></div></div></div></div></div></div>
<div class="col-md-12"><div id="batchInfoSection" class="batch-info-section"><div class="row g-2"><div class="col-md-4"><label class="form-label small">Batch Number</label><input type="text" id="batchNumber" class="form-control" placeholder="Auto-generated"></div><div class="col-md-4"><label class="form-label small">Manufacture Date</label><input type="date" id="manufactureDate" class="form-control"></div><div class="col-md-4"><label class="form-label small">Expiry Date</label><input type="date" id="expiryDate" class="form-control"></div></div></div></div>
<div class="col-md-12"><button type="button" id="addProductBtn" class="btn btn-primary w-100"><i class="bx bx-plus me-1"></i> Add Product to List</button></div>
</div><div id="productDetails" class="product-details-card"><div class="row"><div class="col-md-6"><strong id="productName"></strong><br><small class="text-muted">Code: <span id="productCode"></span></small><br><small class="text-muted" id="productHSN"></small><div id="productStockInfo" class="mt-1"></div></div><div class="col-md-6 text-end"><small class="text-danger fw-bold">MRP: ₹<span id="mrpDisplay"></span></small><br><small class="text-success fw-bold">Current Cost: ₹<span id="currentCost"></span></small><br><small class="text-muted" id="productGST"></small><div class="mt-1"><small>Retail: ₹<span id="currentRetailPrice"></span></small><br><small>Wholesale: ₹<span id="currentWholesalePrice"></span></small></div></div></div></div><div id="priceChangeWarning" class="price-change-warning" style="display:none;"><i class="bx bx-info-circle me-1"></i><span id="warningText"></span></div></div>
<div class="table-responsive mt-3"><table class="table table-hover selected-products-table" id="selectedProductsTable"><thead><tr><th>#</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Purchase Price</th><th class="text-end">Tax</th><th class="text-end">Total</th><th class="text-center">Batch</th><th class="text-center">Action</th></tr></thead><tbody id="selectedProductsBody"></tbody><tfoot><tr><td colspan="5" class="text-end fw-bold">Grand Total:</td><td class="text-end fw-bold" id="grandTotal">₹0.00</td><td colspan="2"></td></tr><tr><td colspan="5" class="text-end fw-bold">Total GST:</td><td class="text-end fw-bold" id="totalGST">₹0.00</td><td colspan="2"></td></tr><tr><td colspan="5" class="text-end fw-bold">GST Credit:</td><td class="text-end fw-bold text-success" id="gstCredit">₹0.00</td><td colspan="2"></td></tr></tfoot></table></div>
<div class="alert alert-info mt-3"><strong>Purchase Summary:</strong> <span id="stockSummary">No products selected</span></div></div><div class="card-footer"><div class="text-end"><button type="submit" class="btn btn-success btn-lg px-5" id="submitBtn"><i class="bx bx-save me-2"></i> Update Purchase Order</button></div></div></div></div>
</div></form></div></div><?php include 'includes/footer.php'; ?></div></div>
<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
const PRODUCTS = <?php echo json_encode($jsProducts, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const BARCODE_MAP = <?php echo json_encode($barcodeMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const PURCHASE_ITEMS = <?php echo json_encode($purchase_items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
let selectedProducts = new Map(); let itemCounter = 0; let currentProductId = null; let manualPriceUpdate = false;
const Toast = Swal.mixin({toast:true,position:'top-end',showConfirmButton:false,timer:2500,timerProgressBar:true});
function findProductById(id){return PRODUCTS[id];}
function num(v){return parseFloat(v)||0;}
function formatMoney(n){return '₹'+num(n).toFixed(2);}
function gstRate(c,s,i){return num(c)+num(s)+num(i);}
function finalFromEntered(value,cgst,sgst,igst,gstType){let rate=gstRate(cgst,sgst,igst); value=num(value); return gstType==='exclusive'?value+(value*rate/100):value;}
function enteredFromFinal(value,cgst,sgst,igst,gstType){let rate=gstRate(cgst,sgst,igst); value=num(value); return gstType==='exclusive'&&rate>0?value/(1+rate/100):value;}
function splitValue(value,cgst,sgst,igst,gstType){value=num(value); let rate=gstRate(cgst,sgst,igst); if(gstType==='exclusive'){let gst=value*rate/100;return{entered:value,withoutGst:value,gstAmount:gst,finalValue:value+gst};} let without=rate>0?value/(1+rate/100):value; return{entered:value,withoutGst:without,gstAmount:value-without,finalValue:value};}
function calculateItemTotal(price,quantity,cgst,sgst,igst,gstType='inclusive'){let q=num(quantity); let split=splitValue(price,cgst,sgst,igst,gstType); let taxable=split.withoutGst*q; let ca=taxable*num(cgst)/100, sa=taxable*num(sgst)/100, ia=taxable*num(igst)/100; let gst=ca+sa+ia; let total=gstType==='exclusive'?taxable+gst:split.finalValue*q; return{enteredValue:split.entered*q,withoutGst:taxable,taxable:taxable,cgst:ca,sgst:sa,igst:ia,gstAmount:gst,total:total,gstCredit:gst};}
function calculatePurchasePriceFromDiscount(mrp,discountInput){let price=num(mrp); if(discountInput&&discountInput.trim()){let d=discountInput.trim(); if(d.includes('%')) price-=price*(num(d.replace('%',''))/100); else price-=num(d);} return price<0?0:price;}
function calculateDiscountFromPrice(mrp,purchasePrice){mrp=num(mrp);purchasePrice=num(purchasePrice); if(mrp<=0||purchasePrice<=0||purchasePrice>=mrp)return''; return(((mrp-purchasePrice)/mrp)*100).toFixed(1)+'%';}
function calculateSellingPrices(basePurchasePrice,product,cgst,sgst,igst,gstType){let retailBase=num(basePurchasePrice), wholesaleBase=num(basePurchasePrice); if(num(product.retail_markup_percent)>0) retailBase+=retailBase*num(product.retail_markup_percent)/100; else if(num(product.retail_price_value)>0) retailBase+=product.retail_price_type==='percentage'?retailBase*num(product.retail_price_value)/100:num(product.retail_price_value); if(num(product.wholesale_markup_percent)>0) wholesaleBase+=wholesaleBase*num(product.wholesale_markup_percent)/100; else if(num(product.wholesale_price_value)>0) wholesaleBase+=product.wholesale_price_type==='percentage'?wholesaleBase*num(product.wholesale_price_value)/100:num(product.wholesale_price_value); return{retailPrice:finalFromEntered(retailBase,cgst,sgst,igst,gstType),wholesalePrice:finalFromEntered(wholesaleBase,cgst,sgst,igst,gstType),retailBase:retailBase,wholesaleBase:wholesaleBase,retailMarkupPercent:basePurchasePrice>0?((retailBase-basePurchasePrice)/basePurchasePrice)*100:0,wholesaleMarkupPercent:basePurchasePrice>0?((wholesaleBase-basePurchasePrice)/basePurchasePrice)*100:0};}
function chipHtml(s){return`<span class="base-chip">Without GST: ${formatMoney(s.withoutGst)}</span><span class="gst-chip">GST: ${formatMoney(s.gstAmount)}</span><span class="final-chip">After GST: ${formatMoney(s.finalValue)}</span>`;}
function setHint(id,value,cgst,sgst,igst,gstType){$('#'+id).html(chipHtml(splitValue(value,cgst,sgst,igst,gstType)));}
function updatePriceCalculations(){if(!currentProductId)return; let product=findProductById(currentProductId); if(!product)return; let mrp=num($('#mrp').val()), discount=$('#discount').val().trim(), manual=num($('#purchasePrice').val()), qty=num($('#quantity').val())||1, cgst=num($('#cgstRate').val()), sgst=num($('#sgstRate').val()), igst=num($('#igstRate').val()), gstType=$('#gstType').val()||'inclusive'; let purchasePrice=manual; if(manualPriceUpdate){$('#discount').val(calculateDiscountFromPrice(mrp,purchasePrice));}else{purchasePrice=discount?calculatePurchasePriceFromDiscount(mrp,discount):enteredFromFinal(product.stock_price,cgst,sgst,igst,gstType); if(purchasePrice<=0)purchasePrice=mrp; $('#purchasePrice').val(purchasePrice.toFixed(2));} let selling=calculateSellingPrices(purchasePrice,product,cgst,sgst,igst,gstType); let totals=calculateItemTotal(purchasePrice,qty,cgst,sgst,igst,gstType); $('#calculatedPurchasePrice').val(finalFromEntered(purchasePrice,cgst,sgst,igst,gstType).toFixed(2)); $('#retailPrice').val(selling.retailPrice.toFixed(2)); $('#wholesalePrice').val(selling.wholesalePrice.toFixed(2)); $('#retailMarkupBadge').html(`<span class="markup-badge">+${selling.retailMarkupPercent.toFixed(1)}%</span>`); $('#wholesaleMarkupBadge').html(`<span class="markup-badge">+${selling.wholesaleMarkupPercent.toFixed(1)}%</span>`); setHint('mrpGstHint',mrp,cgst,sgst,igst,gstType); setHint('purchasePriceGstHint',purchasePrice,cgst,sgst,igst,gstType); setHint('calculatedPurchasePriceGstHint',purchasePrice,cgst,sgst,igst,gstType); $('#retailPriceGstHint').html(chipHtml(splitValue(selling.retailBase,cgst,sgst,igst,gstType))); $('#wholesalePriceGstHint').html(chipHtml(splitValue(selling.wholesaleBase,cgst,sgst,igst,gstType))); $('#gstBreakupBox').html(`<div class="gst-summary-grid"><div class="gst-summary-cell"><small>Entered / Line</small><strong>${formatMoney(totals.enteredValue)}</strong></div><div class="gst-summary-cell"><small>Without GST</small><strong>${formatMoney(totals.withoutGst)}</strong></div><div class="gst-summary-cell"><small>GST Added</small><strong>${formatMoney(totals.gstAmount)}</strong></div><div class="gst-summary-cell"><small>Final Value</small><strong>${formatMoney(totals.total)}</strong></div><div class="gst-summary-cell"><small>MRP Final</small><strong>${formatMoney(finalFromEntered(mrp,cgst,sgst,igst,gstType))}</strong></div><div class="gst-summary-cell"><small>Purchase Final</small><strong>${formatMoney(finalFromEntered(purchasePrice,cgst,sgst,igst,gstType))}</strong></div></div>`); $('#priceCalculation,#priceDetails').show(); checkPriceChange(finalFromEntered(purchasePrice,cgst,sgst,igst,gstType),product);}
function updateProductDetails(productId){let product=findProductById(productId); if(!product)return; currentProductId=productId; manualPriceUpdate=false; let gstType=product.gst_type||'inclusive'; let cgst=num(product.cgst),sgst=num(product.sgst),igst=num(product.igst); $('#stockDisplay').val(product.total_stock||0); $('#gstType').val(gstType); $('#mrp').val(enteredFromFinal(product.mrp,cgst,sgst,igst,gstType).toFixed(2)); $('#purchasePrice').val(enteredFromFinal(product.stock_price,cgst,sgst,igst,gstType).toFixed(2)); $('#discount').val(calculateDiscountFromPrice($('#mrp').val(),$('#purchasePrice').val())); $('#quantity').val(1); $('#cgstRate').val(cgst); $('#sgstRate').val(sgst); $('#igstRate').val(igst); $('#batchNumber').val('BATCH-'+new Date().toISOString().slice(0,10).replace(/-/g,'')+'-'+Math.floor(Math.random()*1000)); $('#productName').text(product.name); $('#productCode').text(product.code); $('#productHSN').text(product.hsn?'HSN: '+product.hsn:''); $('#mrpDisplay').text(num(product.mrp).toFixed(2)); $('#currentCost').text(num(product.stock_price).toFixed(2)); $('#currentRetailPrice').text(num(product.retail_price).toFixed(2)); $('#currentWholesalePrice').text(num(product.wholesale_price).toFixed(2)); $('#productGST').text((product.total_gst||0)>0?`GST: ${product.total_gst}% (${gstType})`:'No GST'); $('#productStockInfo').html(`<span class="stock-badge shop-stock-badge">Shop: ${product.shop_stock||0}</span><span class="stock-badge warehouse-stock-badge">Warehouse: ${product.warehouse_stock||0}</span><span class="stock-badge gst-badge">GST ${product.total_gst||0}% ${gstType}</span>`); $('#productDetails,#batchInfoSection').addClass('show').show(); updatePriceCalculations();}
function checkPriceChange(finalPurchasePrice,product){let w=$('#priceChangeWarning'),t=$('#warningText'); if(product.stock_price>0&&Math.abs(finalPurchasePrice-product.stock_price)>0.01){let diff=finalPurchasePrice-product.stock_price; t.html(`Purchase final price ${diff>0?'increased':'decreased'} from ${formatMoney(product.stock_price)} to ${formatMoney(finalPurchasePrice)}.`); w.show();}else w.hide();}
function addProductToCart(){let productId=$('#productSelect').val(); if(!productId){Toast.fire({icon:'warning',title:'Select product'});return;} let product=findProductById(productId); let data={productId,productName:product.name,productCode:product.code,mrp:num($('#mrp').val()),discount:$('#discount').val().trim(),purchasePrice:num($('#purchasePrice').val()),quantity:num($('#quantity').val())||1,cgst:num($('#cgstRate').val()),sgst:num($('#sgstRate').val()),igst:num($('#igstRate').val()),gst_type:$('#gstType').val()||'inclusive',hsn:product.hsn||'',batch_number:$('#batchNumber').val()||'',manufacture_date:$('#manufactureDate').val()||'',expiry_date:$('#expiryDate').val()||''}; if(data.mrp<=0||data.purchasePrice<=0){Toast.fire({icon:'warning',title:'Enter valid MRP and purchase price'});return;} proceedWithAddProduct(product,data);}
function proceedWithAddProduct(product,data){let selling=calculateSellingPrices(data.purchasePrice,product,data.cgst,data.sgst,data.igst,data.gst_type); let totals=calculateItemTotal(data.purchasePrice,data.quantity,data.cgst,data.sgst,data.igst,data.gst_type); let itemId=++itemCounter; selectedProducts.set(itemId,{id:data.productId,itemId,name:data.productName,code:data.productCode,mrp:data.mrp,discount:data.discount,purchase_price:data.purchasePrice,final_purchase_price:finalFromEntered(data.purchasePrice,data.cgst,data.sgst,data.igst,data.gst_type),retail_price:selling.retailPrice,wholesale_price:selling.wholesalePrice,retail_base:selling.retailBase,wholesale_base:selling.wholesaleBase,quantity:data.quantity,cgst:data.cgst,sgst:data.sgst,igst:data.igst,total_gst:gstRate(data.cgst,data.sgst,data.igst),gst_type:data.gst_type,hsn:data.hsn,batch_number:data.batch_number,manufacture_date:data.manufacture_date,expiry_date:data.expiry_date,without_gst:totals.withoutGst,gst_amount:totals.gstAmount,cgst_amount:totals.cgst,sgst_amount:totals.sgst,igst_amount:totals.igst,total:totals.total,gst_credit:totals.gstCredit}); updateProductsTable(); updateSummary(); resetProductFields(); Toast.fire({icon:'success',title:'Product added'});}
function loadPurchaseItems(){selectedProducts.clear(); itemCounter=0; PURCHASE_ITEMS.forEach(item=>{let product=findProductById(item.product_id); if(!product)return; let cgst=num(item.cgst_rate),sgst=num(item.sgst_rate),igst=num(item.igst_rate); let gstType=item.item_gst_type||item.gst_type||product.gst_type||'inclusive'; let enteredPurchase=enteredFromFinal(item.purchase_price,cgst,sgst,igst,gstType); let enteredMrp=enteredFromFinal(item.mrp,cgst,sgst,igst,gstType); let totals=calculateItemTotal(enteredPurchase,item.quantity,cgst,sgst,igst,gstType); let itemId=++itemCounter; selectedProducts.set(itemId,{id:item.product_id,itemId,name:product.name,code:product.code,mrp:enteredMrp,discount:item.discount||'',purchase_price:enteredPurchase,final_purchase_price:num(item.purchase_price),retail_price:num(item.retail_price),wholesale_price:num(item.wholesale_price),quantity:num(item.quantity),cgst,sgst,igst,total_gst:gstRate(cgst,sgst,igst),gst_type:gstType,hsn:item.hsn_code||product.hsn,batch_number:item.batch_number||'',manufacture_date:item.manufacture_date||'',expiry_date:item.expiry_date||'',without_gst:totals.withoutGst,gst_amount:totals.gstAmount,cgst_amount:totals.cgst,sgst_amount:totals.sgst,igst_amount:totals.igst,total:totals.total,gst_credit:totals.gstCredit});}); updateProductsTable(); updateSummary();}
function updateProductsTable(){let tbody=$('#selectedProductsBody'); tbody.empty(); let totalAmount=0,totalGST=0,totalCredit=0,row=0; if(selectedProducts.size===0){tbody.html('<tr class="text-center"><td colspan="8" class="py-4">No products added yet</td></tr>'); $('#itemCount').text('0 Items'); $('#submitBtn').prop('disabled',true); $('#grandTotal,#totalGST,#gstCredit').text(formatMoney(0)); return;} selectedProducts.forEach((p,itemId)=>{row++; totalAmount+=p.total; totalGST+=p.gst_amount; totalCredit+=p.gst_credit; tbody.append(`<tr><td>${row}</td><td><strong>${p.name}</strong><br><small>${p.code}</small>${p.hsn?`<br><small>HSN: ${p.hsn}</small>`:''}<input type="hidden" name="items[${itemId}][product_id]" value="${p.id}"><input type="hidden" name="items[${itemId}][mrp]" value="${p.mrp}"><input type="hidden" name="items[${itemId}][discount]" value="${p.discount}"><input type="hidden" name="items[${itemId}][purchase_price]" value="${p.purchase_price}"><input type="hidden" name="items[${itemId}][retail_price]" value="${p.retail_price}"><input type="hidden" name="items[${itemId}][wholesale_price]" value="${p.wholesale_price}"><input type="hidden" name="items[${itemId}][quantity]" value="${p.quantity}"><input type="hidden" name="items[${itemId}][cgst_rate]" value="${p.cgst}"><input type="hidden" name="items[${itemId}][sgst_rate]" value="${p.sgst}"><input type="hidden" name="items[${itemId}][igst_rate]" value="${p.igst}"><input type="hidden" name="items[${itemId}][gst_type]" value="${p.gst_type}"><input type="hidden" name="items[${itemId}][batch_number]" value="${p.batch_number}"><input type="hidden" name="items[${itemId}][manufacture_date]" value="${p.manufacture_date}"><input type="hidden" name="items[${itemId}][expiry_date]" value="${p.expiry_date}"></td><td class="text-end">${p.quantity}</td><td class="text-end"><strong>${formatMoney(p.final_purchase_price)}</strong><br><small>${p.gst_type}</small><br><small>Base: ${formatMoney(p.purchase_price)}</small></td><td class="text-end">${p.total_gst}%<br><small>${formatMoney(p.gst_amount)}</small></td><td class="text-end fw-bold">${formatMoney(p.total)}<br><small>Without GST: ${formatMoney(p.without_gst)}</small></td><td class="text-center">${p.batch_number||'-'}</td><td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm delete-btn" data-item-id="${itemId}"><i class="bx bx-trash"></i></button></td></tr>`);}); $('#grandTotal').text(formatMoney(totalAmount)); $('#totalGST').text(formatMoney(totalGST)); $('#gstCredit').text(formatMoney(totalCredit)); $('#itemCount').text(`${selectedProducts.size} ${selectedProducts.size===1?'Item':'Items'}`); $('#submitBtn').prop('disabled',false); $('.delete-btn').on('click',function(){selectedProducts.delete($(this).data('item-id')); updateProductsTable(); updateSummary();});}
function updateSummary(){if(selectedProducts.size===0){$('#stockSummary').html('No products selected');return;} let q=0,v=0,w=0,g=0; selectedProducts.forEach(p=>{q+=p.quantity;v+=p.total;w+=p.without_gst;g+=p.gst_amount;}); $('#stockSummary').html(`${selectedProducts.size} items | Qty: <strong>${q}</strong> | Without GST: <strong>${formatMoney(w)}</strong> | GST: <strong>${formatMoney(g)}</strong> | Final: <strong>${formatMoney(v)}</strong>`);}
function resetProductFields(){$('#productSelect').val(null).trigger('change');currentProductId=null;manualPriceUpdate=false;$('#stockDisplay,#mrp,#purchasePrice').val('0');$('#discount').val('');$('#quantity').val(1);$('#calculatedPurchasePrice,#retailPrice,#wholesalePrice').val('');$('#cgstRate,#sgstRate,#igstRate').val('0');$('#gstType').val('inclusive');$('#batchNumber,#manufactureDate,#expiryDate').val('');$('#productDetails,#batchInfoSection').removeClass('show').hide();$('#priceCalculation,#priceDetails,#priceChangeWarning').hide();}
function setupBillImagePreview(){$('#billImage').on('change',function(){let file=this.files[0],preview=$('#billPreview'); if(!file){preview.hide().html('');return;} let reader=new FileReader(); reader.onload=e=>{preview.html(file.type==='application/pdf'?`<embed src="${e.target.result}" type="application/pdf" />`:`<img src="${e.target.result}" />`).show();}; reader.readAsDataURL(file);});}
function initializeSelect2(){$('.select2-supplier,.select2-shop').select2({width:'100%'}); let productOptions=[]; Object.keys(PRODUCTS).forEach(id=>{let p=PRODUCTS[id]; productOptions.push({id,text:`${p.name} (${p.code})`});}); $('#productSelect').select2({placeholder:'-- Type to search product --',allowClear:true,width:'100%',data:productOptions}); $('#productSelect').on('change',function(){let id=$(this).val(); if(id)updateProductDetails(id);});}
$(document).ready(function(){initializeSelect2();setupBillImagePreview(); if(typeof flatpickr!=='undefined'){flatpickr('#manufactureDate',{dateFormat:'Y-m-d'});flatpickr('#expiryDate',{dateFormat:'Y-m-d'});} loadPurchaseItems(); $('#addProductBtn').on('click',addProductToCart); $('#discount').on('input',function(){manualPriceUpdate=false;updatePriceCalculations();}); $('#purchasePrice').on('input',function(){manualPriceUpdate=true;updatePriceCalculations();}); $('#mrp,#quantity,#cgstRate,#sgstRate,#igstRate,#gstType').on('input change',updatePriceCalculations); $('#purchaseForm').on('submit',function(e){if(selectedProducts.size===0){e.preventDefault();Toast.fire({icon:'warning',title:'Please add at least one product'});}});});
</script>
</body>
</html>
