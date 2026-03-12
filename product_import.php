<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Authentication & basic checks
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if (!isset($_SESSION['current_business_id'])) {
    header('Location: select_shop.php');
    exit();
}

$current_business_id = (int)$_SESSION['current_business_id'];
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$user_id = $_SESSION['user_id'];

$error = '';
$success = '';
$preview_data = null;
$total_records = 0;
$valid_records = 0;
$invalid_records = 0;

// Fetch available shops/warehouses
$shops_stmt = $pdo->prepare("
    SELECT id, shop_name, location_type
    FROM shops
    WHERE business_id = ? AND is_active = 1
    ORDER BY location_type, shop_name
");
$shops_stmt->execute([$current_business_id]);
$shops = $shops_stmt->fetchAll(PDO::FETCH_ASSOC);

// ──────────────────────────────────────────────────────────────────────────────
// HANDLE CSV UPLOAD & PREVIEW
// ──────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
    ini_set('auto_detect_line_endings', true);

    if ($_FILES['csv_file']['size'] > 10 * 1024 * 1024) {
        $error = "File too large (max 10MB).";
    } elseif (strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $error = "Only CSV files are allowed.";
    } else {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            $error = "Cannot read file.";
        } else {
            $headers = fgetcsv($handle);
            if (!$headers) {
                $error = "Invalid or empty CSV.";
                fclose($handle);
            } else {
                // Expected headers - NO direct price columns
                $expected_headers = [
                    'Product Name', 'Product Code', 'Category', 'Subcategory', 'Current Stock',
                    'Barcode', 'HSN Code', 'Unit of Measure',
                    'MRP', 'Discount',
                    'Retail Markup Type', 'Retail Markup',
                    'Wholesale Markup Type', 'Wholesale Markup',
                    'Referral Enabled', 'Referral Type', 'Referral Value'
                ];

                $headers = array_map('trim', $headers);
                $missing = array_diff($expected_headers, $headers);

                if (!empty($missing)) {
                    $error = "Missing required columns: " . implode(', ', $missing);
                    fclose($handle);
                } else {
                    $preview_data = [];
                    $row_num = 1; // Header row

                    while (($row = fgetcsv($handle)) !== false) {
                        $row_num++;
                        if (count($row) !== count($expected_headers)) continue;

                        $data = array_combine($expected_headers, array_map('trim', $row));
                        $row_errors = [];

                        // Required fields
                        if (empty($data['Product Name'])) {
                            $row_errors[] = "Product Name is required";
                        }
                        if (empty($data['Unit of Measure'])) {
                            $row_errors[] = "Unit of Measure is required";
                        }
                        if (empty($data['MRP']) || floatval($data['MRP']) <= 0) {
                            $row_errors[] = "Valid MRP (>0) is required";
                        }

                        // Price inputs
                        $mrp = floatval($data['MRP'] ?? 0);
                        $discount_str = trim($data['Discount'] ?? '');
                        
                        // Process Discount
                        $discount_type = 'percentage';
                        $discount_value = 0;
                        
                        if (!empty($discount_str)) {
                            if (stripos($discount_str, '%') !== false) {
                                $discount_type = 'percentage';
                                $discount_value = floatval(str_replace('%', '', $discount_str));
                                $discount_value = max(0, min(100, $discount_value));
                            } else {
                                $discount_type = 'fixed';
                                $discount_value = floatval($discount_str);
                            }
                        }
                        
                        // Process Retail Markup
                        $retail_markup_type_raw = strtolower(trim($data['Retail Markup Type'] ?? ''));
                        $retail_markup_value = floatval($data['Retail Markup'] ?? 0);
                        
                        // Normalize retail markup type
                        $retail_markup_type = 'percentage';
                        if (in_array($retail_markup_type_raw, ['fixed', 'amount', 'rupees', '₹'])) {
                            $retail_markup_type = 'fixed';
                        }
                        
                        // Process Wholesale Markup
                        $wholesale_markup_type_raw = strtolower(trim($data['Wholesale Markup Type'] ?? ''));
                        $wholesale_markup_value = floatval($data['Wholesale Markup'] ?? 0);
                        
                        // Normalize wholesale markup type
                        $wholesale_markup_type = 'percentage';
                        if (in_array($wholesale_markup_type_raw, ['fixed', 'amount', 'rupees', '₹'])) {
                            $wholesale_markup_type = 'fixed';
                        }

                        // Validate markup types
                        if ($retail_markup_value > 0 && !in_array($retail_markup_type_raw, ['percentage', 'percent', 'fixed', 'amount', 'rupees', '₹', ''])) {
                            $row_errors[] = "Invalid Retail Markup Type. Use 'percentage' or 'fixed'";
                        }
                        if ($wholesale_markup_value > 0 && !in_array($wholesale_markup_type_raw, ['percentage', 'percent', 'fixed', 'amount', 'rupees', '₹', ''])) {
                            $row_errors[] = "Invalid Wholesale Markup Type. Use 'percentage' or 'fixed'";
                        }

                        // 1. Calculate Stock Price (from MRP and Discount)
                        $stock_price = $mrp;
                        $stock_calc_method = 'mrp_only';

                        if (!empty($discount_str)) {
                            if ($discount_type === 'percentage') {
                                $stock_price = $mrp * (1 - $discount_value / 100);
                            } else {
                                $stock_price = max(0, $mrp - $discount_value);
                            }
                            $stock_calc_method = 'mrp_discount';
                        }

                        $stock_price = round($stock_price, 2);

                        if ($stock_price <= 0) {
                            $row_errors[] = "Stock Price calculated as ₹0 or negative";
                        }

                        // 2. Calculate Retail Price (from Stock Price + Retail Markup)
                        $retail_price = $stock_price;
                        $retail_calc_method = 'no_markup';

                        if ($retail_markup_value > 0) {
                            if ($retail_markup_type === 'percentage') {
                                $markup_amount = $stock_price * ($retail_markup_value / 100);
                            } else {
                                $markup_amount = $retail_markup_value;
                            }
                            $retail_price = $stock_price + $markup_amount;
                            $retail_calc_method = 'markup';
                        }
                        $retail_price = round($retail_price, 2);

                        // 3. Calculate Wholesale Price (from Stock Price + Wholesale Markup)
                        $wholesale_price = $stock_price;
                        $wholesale_calc_method = 'no_markup';

                        if ($wholesale_markup_value > 0) {
                            if ($wholesale_markup_type === 'percentage') {
                                $markup_amount = $stock_price * ($wholesale_markup_value / 100);
                            } else {
                                $markup_amount = $wholesale_markup_value;
                            }
                            $wholesale_price = $stock_price + $markup_amount;
                            $wholesale_calc_method = 'markup';
                        }
                        $wholesale_price = round($wholesale_price, 2);

                        // Price validations
                        if ($retail_price <= $stock_price) {
                            $row_errors[] = "Retail Price (₹$retail_price) not greater than Stock Price (₹$stock_price)";
                        }
                        if ($wholesale_price < $stock_price) {
                            $row_errors[] = "Warning: Wholesale Price lower than Stock Price";
                        }
                        if ($retail_price > $mrp) {
                            $row_errors[] = "Retail Price (₹$retail_price) exceeds MRP (₹$mrp)";
                        }
                        if ($wholesale_price > $mrp) {
                            $row_errors[] = "Wholesale Price (₹$wholesale_price) exceeds MRP (₹$mrp)";
                        }

                        // Category lookup
                        $category_id = null;
                        if (!empty($data['Category'])) {
                            $stmt = $pdo->prepare("SELECT id FROM categories WHERE business_id = ? AND category_name = ? AND status = 'active'");
                            $stmt->execute([$current_business_id, $data['Category']]);
                            if ($cat = $stmt->fetch()) {
                                $category_id = $cat['id'];
                            } else {
                                $row_errors[] = "Category not found: " . htmlspecialchars($data['Category']);
                            }
                        } else {
                            $row_errors[] = "Category is required";
                        }

                        // Subcategory lookup (optional)
                        $subcategory_id = null;
                        if (!empty($data['Subcategory']) && $category_id) {
                            $stmt = $pdo->prepare("SELECT id FROM subcategories WHERE business_id = ? AND category_id = ? AND subcategory_name = ? AND status = 'active'");
                            $stmt->execute([$current_business_id, $category_id, $data['Subcategory']]);
                            if ($sub = $stmt->fetch()) {
                                $subcategory_id = $sub['id'];
                            } else {
                                $row_errors[] = "Subcategory not found: " . htmlspecialchars($data['Subcategory']);
                            }
                        }

                        // HSN Code → GST lookup
                        $gst_id = null;
                        $hsn_code = trim($data['HSN Code'] ?? '');
                        if ($hsn_code) {
                            $stmt = $pdo->prepare("SELECT id FROM gst_rates WHERE business_id = ? AND hsn_code = ? AND status = 'active'");
                            $stmt->execute([$current_business_id, $hsn_code]);
                            if ($gst = $stmt->fetch()) {
                                $gst_id = $gst['id'];
                            } else {
                                $row_errors[] = "HSN Code not found: $hsn_code";
                            }
                        }

                        // Referral processing
                        $ref_enabled_str = strtolower(trim($data['Referral Enabled'] ?? ''));
                        $referral_enabled = in_array($ref_enabled_str, ['yes', 'true', '1', 'y']) ? 1 : 0;
                        $referral_type = strtolower($data['Referral Type'] ?? 'percentage');
                        $referral_type = in_array($referral_type, ['percentage', 'fixed']) ? $referral_type : 'percentage';
                        $referral_value = $referral_enabled ? floatval($data['Referral Value'] ?? 0) : 0;

                        // Store in preview with ALL fields
                        $data['_row_num'] = $row_num;
                        $data['_errors'] = $row_errors;
                        $data['_category_id'] = $category_id;
                        $data['_subcategory_id'] = $subcategory_id;
                        $data['_gst_id'] = $gst_id;
                        $data['_hsn_code'] = $hsn_code;
                        
                        // Calculated prices
                        $data['_stock_price'] = $stock_price;
                        $data['_retail_price'] = $retail_price;
                        $data['_wholesale_price'] = $wholesale_price;
                        
                        // Calculation methods (for info)
                        $data['_stock_calc_method'] = $stock_calc_method;
                        $data['_retail_calc_method'] = $retail_calc_method;
                        $data['_wholesale_calc_method'] = $wholesale_calc_method;
                        
                        // Discount fields
                        $data['_discount_type'] = $discount_type;
                        $data['_discount_value'] = $discount_value;
                        
                        // Retail markup fields
                        $data['_retail_markup_type'] = $retail_markup_type;
                        $data['_retail_markup_value'] = $retail_markup_value;
                        
                        // Wholesale markup fields
                        $data['_wholesale_markup_type'] = $wholesale_markup_type;
                        $data['_wholesale_markup_value'] = $wholesale_markup_value;
                        
                        // Referral fields
                        $data['_referral_enabled'] = $referral_enabled;
                        $data['_referral_type'] = $referral_type;
                        $data['_referral_value'] = $referral_value;

                        $preview_data[] = $data;

                        if (empty($row_errors)) {
                            $valid_records++;
                        } else {
                            $invalid_records++;
                        }
                    }

                    $total_records = count($preview_data);
                    fclose($handle);

                    if ($total_records === 0) {
                        $error = "No valid data rows found in CSV.";
                    }
                }
            }
        }
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// CONFIRMED IMPORT - COMPLETE VERSION WITH ALL FIELDS
// ──────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    $shop_id = (int)($_POST['shop_id'] ?? 0);
    $import_data = json_decode($_POST['import_data'] ?? '[]', true);

    if (empty($import_data)) {
        $error = "No valid data to import.";
    } elseif ($shop_id <= 0) {
        $error = "Please select a valid shop/warehouse.";
    } else {
        $pdo->beginTransaction();
        try {
            $inserted = $updated = $stock_updated = $failed = 0;
            $import_errors = [];

            foreach ($import_data as $row) {
                try {
                    $product_name = trim($row['Product Name']);
                    $unit = trim($row['Unit of Measure']);
                    $product_code = !empty($row['Product Code']) ? trim($row['Product Code']) : null;
                    $barcode = !empty($row['Barcode']) ? trim($row['Barcode']) : null;
                    $mrp = floatval($row['MRP'] ?? 0);

                    // Use the PRE-VALIDATED values from preview
                    $category_id = $row['_category_id'];
                    $subcategory_id = $row['_subcategory_id'];
                    $gst_id = $row['_gst_id'];
                    $hsn_code = $row['_hsn_code'] ?? null;
                    
                    // Calculated prices
                    $stock_price = $row['_stock_price'];
                    $retail_price = $row['_retail_price'];
                    $wholesale_price = $row['_wholesale_price'];
                    
                    // Discount fields
                    $discount_type = $row['_discount_type'] ?? 'percentage';
                    $discount_value = $row['_discount_value'] ?? 0;
                    
                    // Retail markup fields
                    $retail_price_type = $row['_retail_markup_type'] ?? 'percentage';
                    $retail_price_value = $row['_retail_markup_value'] ?? 0;
                    
                    // Wholesale markup fields
                    $wholesale_price_type = $row['_wholesale_markup_type'] ?? 'percentage';
                    $wholesale_price_value = $row['_wholesale_markup_value'] ?? 0;
                    
                    // Referral fields
                    $referral_enabled = $row['_referral_enabled'] ?? 0;
                    $referral_type = $row['_referral_type'] ?? 'percentage';
                    $referral_value = $row['_referral_value'] ?? 0;

                    // Find existing product
                    $existing = null;
                    if ($product_code) {
                        $stmt = $pdo->prepare("SELECT id FROM products WHERE business_id = ? AND product_code = ?");
                        $stmt->execute([$current_business_id, $product_code]);
                        $existing = $stmt->fetch();
                    }
                    if (!$existing && $barcode) {
                        $stmt = $pdo->prepare("SELECT id FROM products WHERE business_id = ? AND barcode = ?");
                        $stmt->execute([$current_business_id, $barcode]);
                        $existing = $stmt->fetch();
                    }
                    if (!$existing) {
                        $stmt = $pdo->prepare("SELECT id FROM products WHERE business_id = ? AND product_name = ? AND unit_of_measure = ?");
                        $stmt->execute([$current_business_id, $product_name, $unit]);
                        $existing = $stmt->fetch();
                    }

                    if ($existing) {
                        // UPDATE existing product with ALL fields
                        $stmt = $pdo->prepare("
                            UPDATE products SET
                                product_name = ?,
                                product_code = ?,
                                barcode = ?,
                                hsn_code = ?,
                                gst_id = ?,
                                category_id = ?,
                                subcategory_id = ?,
                                unit_of_measure = ?,
                                stock_price = ?,
                                retail_price = ?,
                                wholesale_price = ?,
                                mrp = ?,
                                discount_type = ?,
                                discount_value = ?,
                                retail_price_type = ?,
                                retail_price_value = ?,
                                wholesale_price_type = ?,
                                wholesale_price_value = ?,
                                referral_enabled = ?,
                                referral_type = ?,
                                referral_value = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $product_name,
                            $product_code,
                            $barcode,
                            $hsn_code,
                            $gst_id,
                            $category_id,
                            $subcategory_id,
                            $unit,
                            $stock_price,
                            $retail_price,
                            $wholesale_price,
                            $mrp,
                            $discount_type,
                            $discount_value,
                            $retail_price_type,
                            $retail_price_value,
                            $wholesale_price_type,
                            $wholesale_price_value,
                            $referral_enabled,
                            $referral_type,
                            $referral_value,
                            $existing['id']
                        ]);
                        $updated++;
                        $product_id = $existing['id'];
                    } else {
                        // INSERT new product with ALL fields
                        $stmt = $pdo->prepare("
                            INSERT INTO products (
                                business_id, product_name, product_code, barcode, hsn_code, gst_id,
                                category_id, subcategory_id, unit_of_measure,
                                stock_price, retail_price, wholesale_price, mrp,
                                discount_type, discount_value,
                                retail_price_type, retail_price_value,
                                wholesale_price_type, wholesale_price_value,
                                referral_enabled, referral_type, referral_value,
                                is_active, created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                ?, ?,
                                ?, ?,
                                ?, ?,
                                ?, ?, ?,
                                1, NOW(), NOW()
                            )
                        ");
                        $stmt->execute([
                            $current_business_id,
                            $product_name,
                            $product_code,
                            $barcode,
                            $hsn_code,
                            $gst_id,
                            $category_id,
                            $subcategory_id,
                            $unit,
                            $stock_price,
                            $retail_price,
                            $wholesale_price,
                            $mrp,
                            // Discount fields
                            $discount_type,
                            $discount_value,
                            // Retail markup fields
                            $retail_price_type,
                            $retail_price_value,
                            // Wholesale markup fields
                            $wholesale_price_type,
                            $wholesale_price_value,
                            // Referral fields
                            $referral_enabled,
                            $referral_type,
                            $referral_value
                        ]);
                        $inserted++;
                        $product_id = $pdo->lastInsertId();
                    }

                    // Update stock
                    $qty = intval($row['Current Stock'] ?? 0);
                    if ($qty !== 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO product_stocks (product_id, shop_id, business_id, quantity, last_updated)
                            VALUES (?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                quantity = quantity + VALUES(quantity),
                                last_updated = NOW()
                        ");
                        $stmt->execute([$product_id, $shop_id, $current_business_id, $qty]);
                        if ($stmt->rowCount() > 0) $stock_updated++;
                    }

                } catch (Exception $e) {
                    $failed++;
                    $import_errors[] = "Row {$row['_row_num']}: " . $e->getMessage();
                }
            }

            $pdo->commit();

            $success = "Import completed successfully!<br>
                • New products: <strong>$inserted</strong><br>
                • Updated products: <strong>$updated</strong><br>
                • Stock updated: <strong>$stock_updated</strong><br>
                • Failed rows: <strong>$failed</strong>";

            if ($import_errors) {
                $error = "Some rows failed:<br>" . implode("<br>", array_slice($import_errors, 0, 10));
                if (count($import_errors) > 10) $error .= "<br><strong>...and " . (count($import_errors)-10) . " more</strong>";
            }

            $preview_data = null; // Clear preview after import

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Import failed: " . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Products - Auto Price Calculation</title>
    <?php include('includes/head.php'); ?>
    <style>
        .step-indicator {
            display: flex;
            margin-bottom: 30px;
            position: relative;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step:not(:last-child):before {
            content: '';
            position: absolute;
            top: 20px;
            left: 60%;
            width: 80%;
            height: 2px;
            background: #e9ecef;
            z-index: 1;
        }
        .step.completed:not(:last-child):before {
            background: #5b73e8;
        }
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: 600;
            position: relative;
            z-index: 2;
            transition: all 0.3s;
        }
        .step.active .step-number {
            background: #5b73e8;
            color: white;
            box-shadow: 0 0 0 4px rgba(91, 115, 232, 0.2);
        }
        .step.completed .step-number {
            background: #28a745;
            color: white;
        }
        .step-title {
            font-size: 14px;
            font-weight: 500;
            color: #495057;
        }
        .step.active .step-title {
            color: #5b73e8;
            font-weight: 600;
        }
        .stats-card {
            padding: 20px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-bottom: 20px;
        }
        .stat-item {
            text-align: center;
            padding: 15px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            line-height: 1.2;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .progress-success {
            background: linear-gradient(45deg, #28a745, #20c997);
        }
        .progress-warning {
            background: linear-gradient(45deg, #ffc107, #fd7e14);
        }
        .preview-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .preview-table td {
            vertical-align: middle;
            font-size: 13px;
        }
        .price-badge {
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .price-badge.stock {
            background: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
        }
        .price-badge.retail {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        .price-badge.wholesale {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        .calculation-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #e9ecef;
            color: #495057;
        }
        .invalid-row {
            background-color: rgba(220, 53, 69, 0.05);
            border-left: 4px solid #dc3545;
        }
        .valid-row {
            background-color: rgba(40, 167, 69, 0.05);
            border-left: 4px solid #28a745;
        }
        .tooltip-icon {
            cursor: help;
            color: #6c757d;
            margin-left: 5px;
        }
        .tooltip-icon:hover {
            color: #5b73e8;
        }
        .template-download {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .file-upload-area {
            border: 2px dashed #ced4da;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
        }
        .file-upload-area:hover {
            border-color: #5b73e8;
            background: rgba(91, 115, 232, 0.05);
        }
        .file-upload-area i {
            font-size: 48px;
            color: #5b73e8;
            margin-bottom: 15px;
        }
        .shop-select {
            max-width: 400px;
            margin: 0 auto 20px;
        }
    </style>
</head>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-import me-2"></i> Import Products
                                    <small class="text-muted ms-2">Bulk upload with auto price calculation</small>
                                </h4>
                                <p class="text-muted mb-0">
                                    <i class="bx bx-store me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_shop_name'] ?? 'All Shops') ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="products.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Products
                                </a>
                                <a href="download_template.php" class="btn btn-info">
                                    <i class="bx bx-download me-1"></i> Download Template
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step Indicator -->
                <div class="step-indicator mb-4">
                    <div class="step <?= !$preview_data ? 'active' : 'completed' ?>">
                        <div class="step-number">1</div>
                        <div class="step-title">Upload CSV</div>
                    </div>
                    <div class="step <?= $preview_data ? 'active' : '' ?> <?= $success ? 'completed' : '' ?>">
                        <div class="step-number">2</div>
                        <div class="step-title">Preview & Validate</div>
                    </div>
                    <div class="step <?= $success ? 'active' : '' ?>">
                        <div class="step-number">3</div>
                        <div class="step-title">Import Complete</div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bx bx-error-circle fs-3 me-3"></i>
                        <div><?= $error ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bx bx-check-circle fs-3 me-3"></i>
                        <div><?= $success ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (!$preview_data && !$success): ?>
                <!-- Upload Form -->
                <div class="row">
                    <div class="col-lg-12 mx-auto">
                        <!-- Template Download Card -->
                        <div class="template-download shadow-sm mb-4">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <i class="bx bx-file-blank fs-1"></i>
                                </div>
                                <div class="col">
                                    <h5 class="text-white mb-1">Need a template?</h5>
                                    <p class="text-white-50 mb-0">Download our CSV template with all required columns and examples</p>
                                </div>
                                <div class="col-auto">
                                    <a href="download_template.php" class="btn btn-light">
                                        <i class="bx bx-download me-1"></i> Download Template
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Upload Card -->
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-cloud-upload me-2"></i> Upload CSV File
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                                    <div class="file-upload-area mb-4" onclick="document.getElementById('csvFile').click()">
                                        <input type="file" name="csv_file" id="csvFile" class="d-none" accept=".csv" required onchange="updateFileName(this)">
                                        <i class="bx bx-cloud-upload"></i>
                                        <h5>Click to upload or drag and drop</h5>
                                        <p class="text-muted mb-2">CSV files only (max 10MB)</p>
                                        <span class="badge bg-light text-dark" id="fileName">No file chosen</span>
                                    </div>

                                    <!-- Quick Stats -->
                                    <div class="row mb-4">
                                        <div class="col-md-4">
                                            <div class="text-center p-3 bg-light rounded">
                                                <div class="text-primary fs-4">17</div>
                                                <small class="text-muted">Total Columns</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="text-center p-3 bg-light rounded">
                                                <div class="text-success fs-4">Auto</div>
                                                <small class="text-muted">Price Calculation</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="text-center p-3 bg-light rounded">
                                                <div class="text-info fs-4">Bulk</div>
                                                <small class="text-muted">Stock Update</small>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary btn-lg w-100" id="uploadBtn" disabled>
                                        <i class="bx bx-search me-2"></i> Preview Import
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Instructions Card -->
                        <div class="card shadow-sm mt-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-info-circle me-2"></i> CSV Format Instructions
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3">Required Columns:</h6>
                                        <ul class="list-unstyled">
                                            <li class="mb-2"><span class="badge bg-danger me-2">*</span> Product Name</li>
                                            <li class="mb-2"><span class="badge bg-danger me-2">*</span> Unit of Measure</li>
                                            <li class="mb-2"><span class="badge bg-danger me-2">*</span> MRP</li>
                                            <li class="mb-2"><span class="badge bg-warning me-2">!</span> Category</li>
                                            <li class="mb-2"><span class="badge bg-warning me-2">!</span> HSN Code</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3">Price Calculation Rules:</h6>
                                        <div class="bg-light p-3 rounded">
                                            <p class="mb-2"><strong>Stock Price</strong> = MRP - Discount</p>
                                            <p class="mb-2"><strong>Retail Price</strong> = Stock Price + Retail Markup</p>
                                            <p class="mb-0"><strong>Wholesale Price</strong> = Stock Price + Wholesale Markup</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($preview_data): ?>
                <!-- Preview Data -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bx bx-list-check me-2"></i> Preview Import Data
                            </h5>
                            <div>
                                <span class="badge bg-primary me-2">Total: <?= $total_records ?></span>
                                <span class="badge bg-success me-2">Valid: <?= $valid_records ?></span>
                                <span class="badge bg-danger">Invalid: <?= $invalid_records ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Statistics Cards -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card bg-primary bg-opacity-10 border-primary">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="text-primary mb-1">Total Records</h6>
                                                <h3 class="mb-0"><?= $total_records ?></h3>
                                            </div>
                                            <div class="avatar-sm">
                                                <span class="avatar-title bg-primary bg-opacity-20 rounded-circle fs-3">
                                                    <i class="bx bx-file text-primary"></i>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-success bg-opacity-10 border-success">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="text-success mb-1">Valid Records</h6>
                                                <h3 class="mb-0"><?= $valid_records ?></h3>
                                            </div>
                                            <div class="avatar-sm">
                                                <span class="avatar-title bg-success bg-opacity-20 rounded-circle fs-3">
                                                    <i class="bx bx-check-circle text-success"></i>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-warning bg-opacity-10 border-warning">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="text-warning mb-1">With Issues</h6>
                                                <h3 class="mb-0"><?= $invalid_records ?></h3>
                                            </div>
                                            <div class="avatar-sm">
                                                <span class="avatar-title bg-warning bg-opacity-20 rounded-circle fs-3">
                                                    <i class="bx bx-error-circle text-warning"></i>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-info bg-opacity-10 border-info">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="text-info mb-1">Success Rate</h6>
                                                <h3 class="mb-0"><?= $total_records > 0 ? round(($valid_records / $total_records) * 100, 1) : 0 ?>%</h3>
                                            </div>
                                            <div class="avatar-sm">
                                                <span class="avatar-title bg-info bg-opacity-20 rounded-circle fs-3">
                                                    <i class="bx bx-pie-chart-alt text-info"></i>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="progress mb-4" style="height: 25px;">
                            <div class="progress-bar bg-success" style="width: <?= $total_records > 0 ? ($valid_records / $total_records) * 100 : 0 ?>%">
                                Valid (<?= $valid_records ?>)
                            </div>
                            <div class="progress-bar bg-warning" style="width: <?= $total_records > 0 ? ($invalid_records / $total_records) * 100 : 0 ?>%">
                                Invalid (<?= $invalid_records ?>)
                            </div>
                        </div>

                        <form method="POST" id="importForm">
                            <input type="hidden" name="confirm_import" value="1">
                            <input type="hidden" name="import_data" id="importData" value='<?= htmlspecialchars(json_encode($preview_data)) ?>'>
                            
                            <!-- Shop Selection -->
                            <div class="card bg-light mb-4">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <label class="form-label fw-bold mb-2">Select Target Shop/Warehouse</label>
                                            <select name="shop_id" class="form-select" required>
                                                <option value="">-- Choose location --</option>
                                                <?php foreach ($shops as $shop): ?>
                                                <option value="<?= $shop['id'] ?>" <?= ($current_shop_id == $shop['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($shop['shop_name']) ?> 
                                                    <span class="badge bg-info"><?= $shop['location_type'] ?></span>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <span class="badge bg-primary p-2">
                                                <i class="bx bx-info-circle me-1"></i>
                                                Stock will be added to this location
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Preview Table -->
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover preview-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Product</th>
                                            <th>Code</th>
                                            <th>Category</th>
                                            <th>Stock</th>
                                            <th>MRP</th>
                                            <th>Discount</th>
                                            <th>Stock Price</th>
                                            <th>Retail Price</th>
                                            <th>Wholesale Price</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($preview_data as $row): ?>
                                        <tr class="<?= empty($row['_errors']) ? 'valid-row' : 'invalid-row' ?>">
                                            <td>
                                                <span class="badge bg-secondary"><?= $row['_row_num'] ?></span>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($row['Product Name'] ?? '') ?></strong>
                                                <?php if (!empty($row['Unit of Measure'])): ?>
                                                    <small class="text-muted d-block">Unit: <?= htmlspecialchars($row['Unit of Measure']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['Product Code'])): ?>
                                                    <span class="badge bg-light text-dark"><?= htmlspecialchars($row['Product Code']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                                <?php if (!empty($row['Barcode'])): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($row['Barcode']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($row['Category'] ?? '') ?>
                                                <?php if (!empty($row['Subcategory'])): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($row['Subcategory']) ?></small>
                                                <?php endif; ?>
                                                <?php if (is_null($row['_category_id'])): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger ms-1" title="Category not found">
                                                        <i class="bx bx-error"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php $stock = intval($row['Current Stock'] ?? 0); ?>
                                                <?php if ($stock > 0): ?>
                                                    <span class="badge bg-success"><?= $stock ?></span>
                                                <?php elseif ($stock < 0): ?>
                                                    <span class="badge bg-danger"><?= $stock ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <strong>₹<?= number_format($row['MRP'] ?? 0, 2) ?></strong>
                                                <?php if (!empty($row['HSN Code'])): ?>
                                                    <br><small class="text-muted">HSN: <?= htmlspecialchars($row['HSN Code']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['Discount'])): ?>
                                                    <span class="badge bg-info"><?= htmlspecialchars($row['Discount']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <span class="price-badge stock">₹<?= number_format($row['_stock_price'], 2) ?></span>
                                                <span class="calculation-badge ms-1" title="Calculation method">
                                                    <?= $row['_stock_calc_method'] === 'mrp_discount' ? 'D' : 'M' ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <span class="price-badge retail">₹<?= number_format($row['_retail_price'], 2) ?></span>
                                                <?php if ($row['_retail_calc_method'] === 'markup'): ?>
                                                    <span class="calculation-badge ms-1" title="Includes markup">M</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <span class="price-badge wholesale">₹<?= number_format($row['_wholesale_price'], 2) ?></span>
                                                <?php if ($row['_wholesale_calc_method'] === 'markup'): ?>
                                                    <span class="calculation-badge ms-1" title="Includes markup">M</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if (empty($row['_errors'])): ?>
                                                    <span class="badge bg-success" data-bs-toggle="tooltip" title="No validation errors">
                                                        <i class="bx bx-check me-1"></i> Valid
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger" 
                                                          data-bs-toggle="tooltip" 
                                                          data-bs-html="true"
                                                          title="<?= htmlspecialchars(implode('<br>', $row['_errors'])) ?>">
                                                        <i class="bx bx-error me-1"></i> <?= count($row['_errors']) ?> error(s)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Import Actions -->
                            <div class="mt-4 d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted">
                                        <i class="bx bx-info-circle me-1"></i>
                                        Only <strong class="text-success"><?= $valid_records ?></strong> valid records will be imported
                                    </span>
                                </div>
                                <div>
                                    <a href="product_import.php" class="btn btn-outline-secondary me-2">
                                        <i class="bx bx-x me-1"></i> Cancel
                                    </a>
                                    <?php if ($valid_records > 0): ?>
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to import <?= $valid_records ?> valid records? This will add/update products in your database.')">
                                        <i class="bx bx-check-circle me-1"></i> Import <?= $valid_records ?> Valid Records
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <!-- Success Actions -->
                <div class="row mt-4">
                    <div class="col-12 text-center">
                        <a href="products.php" class="btn btn-primary btn-lg me-2">
                            <i class="bx bx-show me-1"></i> View Products
                        </a>
                        <a href="product_import.php" class="btn btn-outline-secondary btn-lg">
                            <i class="bx bx-import me-1"></i> Import More
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/scripts.php'); ?>
<script>
    $(document).ready(function() {
        // Initialize tooltips
        $('[data-bs-toggle="tooltip"]').tooltip({
            html: true
        });

        // File upload handling
        $('#csvFile').on('change', function() {
            updateFileName(this);
        });

        // Enable/disable upload button based on file selection
        $('#csvFile').on('change', function() {
            $('#uploadBtn').prop('disabled', !this.files.length);
        });

        // Drag and drop highlight
        $('.file-upload-area').on('dragover', function(e) {
            e.preventDefault();
            $(this).addClass('border-primary').css('background', 'rgba(91, 115, 232, 0.05)');
        });

        $('.file-upload-area').on('dragleave', function(e) {
            e.preventDefault();
            $(this).removeClass('border-primary').css('background', '#f8f9fa');
        });

        $('.file-upload-area').on('drop', function(e) {
            e.preventDefault();
            $(this).removeClass('border-primary').css('background', '#f8f9fa');
            
            const files = e.originalEvent.dataTransfer.files;
            if (files.length) {
                $('#csvFile')[0].files = files;
                updateFileName($('#csvFile')[0]);
                $('#uploadBtn').prop('disabled', false);
            }
        });

        // Row hover effect
        $('.preview-table tbody tr').hover(
            function() { $(this).css('background-color', 'rgba(0,0,0,0.02)'); },
            function() { 
                if ($(this).hasClass('valid-row')) {
                    $(this).css('background-color', 'rgba(40, 167, 69, 0.05)');
                } else if ($(this).hasClass('invalid-row')) {
                    $(this).css('background-color', 'rgba(220, 53, 69, 0.05)');
                }
            }
        );

        // Auto-hide alerts
        setTimeout(function() {
            $('.alert').fadeOut('slow');
        }, 5000);
    });

    function updateFileName(input) {
        if (input.files && input.files[0]) {
            const fileName = input.files[0].name;
            const fileSize = (input.files[0].size / 1024).toFixed(2);
            $('#fileName').text(fileName + ' (' + fileSize + ' KB)').removeClass('text-dark').addClass('text-primary');
        } else {
            $('#fileName').text('No file chosen').removeClass('text-primary').addClass('text-dark');
        }
    }
</script>

<style>
    .file-upload-area {
        transition: all 0.3s ease;
    }
    .file-upload-area.border-primary {
        border-color: #5b73e8 !important;
        background: rgba(91, 115, 232, 0.05) !important;
    }
    .preview-table {
        font-size: 0.9rem;
    }
    .preview-table th {
        position: sticky;
        top: 0;
        background: #f8f9fa;
        z-index: 10;
    }
    .bg-opacity-20 {
        --bs-bg-opacity: 0.2;
    }
    .valid-row {
        transition: background-color 0.3s ease;
    }
    .invalid-row {
        transition: background-color 0.3s ease;
    }
    .price-badge {
        display: inline-block;
        min-width: 80px;
        text-align: center;
    }
    .calculation-badge {
        display: inline-block;
        min-width: 20px;
    }
    .step-indicator {
        margin-top: 20px;
    }
    @media (max-width: 768px) {
        .step-title {
            font-size: 12px;
        }
        .step-number {
            width: 30px;
            height: 30px;
            font-size: 14px;
        }
    }
</style>
</body>
</html>
<?php include('includes/scripts.php'); ?>
</body>
</html>