<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
include('includes/functions.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get current business and shop from session
$current_business_id = $_SESSION['current_business_id'] ?? null;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

if (!$current_business_id || !$current_shop_id) {
    set_flash_message('error', 'Please select a business and shop first');
    header('Location: select_shop.php');
    exit();
}

// Only allow business_id = 28
if ($current_business_id != 28) {
    set_flash_message('error', 'This page is only for specific business type');
    header('Location: products.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager','stock_manager', 'shop_manager'])) {
    set_flash_message('error', 'You do not have permission to add products');
    header('Location: dashboard.php');
    exit();
}

$success = $error = '';
$categories = $gst_rates = [];

// Image upload configuration
$upload_dir = 'uploads/products/';
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$max_file_size = 2 * 1024 * 1024; // 2MB

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Fetch categories & GST rates
try {
    $categories = $pdo->prepare("
        SELECT id, category_name 
        FROM categories 
        WHERE business_id = ? AND status = 'active' AND parent_id IS NULL 
        ORDER BY category_name
    ");
    $categories->execute([$current_business_id]);
    $categories = $categories->fetchAll();

    $gst_rates = $pdo->prepare("
        SELECT id, hsn_code, cgst_rate, sgst_rate, igst_rate,
               CONCAT(hsn_code, ' (', cgst_rate + sgst_rate + igst_rate, '%)') as display_label,
               (cgst_rate + sgst_rate + igst_rate) as total_gst_rate
        FROM gst_rates 
        WHERE business_id = ? AND status = 'active' 
        ORDER BY hsn_code
    ");
    $gst_rates->execute([$current_business_id]);
    $gst_rates = $gst_rates->fetchAll();
} catch (Exception $e) {
    $error = "Failed to load categories/GST rates: " . $e->getMessage();
}

// Thumbnail function
function createThumbnail($source_path, $dest_path, $max_width = 200, $max_height = 200) {
    try {
        $image_info = getimagesize($source_path);
        if (!$image_info) return false;
        list($orig_width, $orig_height, $image_type) = $image_info;

        $ratio = min($max_width / $orig_width, $max_height / $orig_height);
        $new_width = (int)($orig_width * $ratio);
        $new_height = (int)($orig_height * $ratio);

        switch ($image_type) {
            case IMAGETYPE_JPEG: $source_image = imagecreatefromjpeg($source_path); break;
            case IMAGETYPE_PNG: $source_image = imagecreatefrompng($source_path); break;
            case IMAGETYPE_GIF: $source_image = imagecreatefromgif($source_path); break;
            case IMAGETYPE_WEBP: $source_image = imagecreatefromwebp($source_path); break;
            default: return false;
        }

        $thumbnail = imagecreatetruecolor($new_width, $new_height);

        if ($image_type == IMAGETYPE_PNG || $image_type == IMAGETYPE_GIF) {
            imagecolortransparent($thumbnail, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
        }

        imagecopyresampled($thumbnail, $source_image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);

        switch ($image_type) {
            case IMAGETYPE_JPEG: imagejpeg($thumbnail, $dest_path, 85); break;
            case IMAGETYPE_PNG: imagepng($thumbnail, $dest_path, 9); break;
            case IMAGETYPE_GIF: imagegif($thumbnail, $dest_path); break;
            case IMAGETYPE_WEBP: imagewebp($thumbnail, $dest_path, 85); break;
        }

        imagedestroy($source_image);
        imagedestroy($thumbnail);
        return true;
    } catch (Exception $e) {
        error_log("Thumbnail error: " . $e->getMessage());
        return false;
    }
}

$hide_wholesale = true;
$mrp_required = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error = "Invalid request.";
    } else {
        $product_name = trim($_POST['product_name'] ?? '');
        $product_code = trim($_POST['product_code'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $subcategory_id = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $unit = $_POST['unit'] ?? 'pcs';
        
        // GST fields
        $gst_type = $_POST['gst_type'] ?? 'exclusive';
        $gst_id = !empty($_POST['gst_id']) ? (int)$_POST['gst_id'] : null;

        // Secondary unit fields
        $secondary_unit = !empty($_POST['secondary_unit']) ? trim($_POST['secondary_unit']) : null;
        $sec_unit_conversion = !empty($_POST['sec_unit_conversion']) ? (float)$_POST['sec_unit_conversion'] : null;
        $sec_unit_price_type = $_POST['sec_unit_price_type'] ?? 'fixed';
        $sec_unit_extra_charge = !empty($_POST['sec_unit_extra_charge']) ? (float)$_POST['sec_unit_extra_charge'] : 0.00;

        // Warranty fields
        $warranty_type = $_POST['warranty_type'] ?? 'none';
        $warranty_period = !empty($_POST['warranty_period']) ? (int)$_POST['warranty_period'] : 0;
        $warranty_unit = $_POST['warranty_unit'] ?? 'months';
        $warranty_description = trim($_POST['warranty_description'] ?? '');
        $is_warranty_applicable = isset($_POST['is_warranty_applicable']) ? 1 : 0;

        // Purchase Price (Base price without GST) - User enters this
        $purchase_price_base = (float)($_POST['purchase_price_base'] ?? 0);
        
        // Retail Price (Base price without GST) - User enters this
        $retail_price_base = (float)($_POST['retail_price_base'] ?? 0);
        
        // Fetch GST rates
        $gst_rate_percentage = 0;
        $hsn_code = '';
        
        // Final purchase price with GST
        $purchase_gst_amount = 0;
        $final_purchase_price = $purchase_price_base;
        
        // Final retail price with GST
        $retail_gst_amount = 0;
        $final_retail_price = $retail_price_base;
        
        if ($gst_id) {
            $gst_stmt = $pdo->prepare("SELECT hsn_code, cgst_rate, sgst_rate, igst_rate FROM gst_rates WHERE id = ? AND business_id = ?");
            $gst_stmt->execute([$gst_id, $current_business_id]);
            $gst_row = $gst_stmt->fetch();
            if ($gst_row) {
                $hsn_code = $gst_row['hsn_code'] ?? '';
                $gst_rate_percentage = $gst_row['cgst_rate'] + $gst_row['sgst_rate'] + $gst_row['igst_rate'];
                
                // Calculate purchase price with GST
                if ($gst_type === 'exclusive') {
                    $purchase_gst_amount = $purchase_price_base * ($gst_rate_percentage / 100);
                    $final_purchase_price = $purchase_price_base + $purchase_gst_amount;
                    
                    $retail_gst_amount = $retail_price_base * ($gst_rate_percentage / 100);
                    $final_retail_price = $retail_price_base + $retail_gst_amount;
                } else {
                    $purchase_gst_amount = ($purchase_price_base * $gst_rate_percentage) / (100 + $gst_rate_percentage);
                    $final_purchase_price = $purchase_price_base;
                    
                    $retail_gst_amount = ($retail_price_base * $gst_rate_percentage) / (100 + $gst_rate_percentage);
                    $final_retail_price = $retail_price_base;
                }
            }
        }
        
        // Wholesale price (hidden but stored same as retail)
        $wholesale_price = $final_retail_price;
        
        $min_stock_level = (int)($_POST['min_stock_level'] ?? 10);
        $image_alt_text = trim($_POST['image_alt_text'] ?? '');
        $referral_enabled = isset($_POST['referral_enabled']) ? 1 : 0;
        $referral_type = $_POST['referral_type'] ?? 'percentage';
        $referral_value = (float)($_POST['referral_value'] ?? 0);
        $initial_stock = !empty($_POST['initial_stock']) ? (int)$_POST['initial_stock'] : 0;
        
        // Store the FINAL purchase price (including GST) as stock price
        $stock_price = $final_purchase_price;
        
        $image_path = $image_thumbnail_path = null;

        // Image upload handling
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == UPLOAD_ERR_OK) {
            $file = $_FILES['product_image'];
            $file_name = basename($file['name']);
            $file_tmp = $file['tmp_name'];
            $file_size = $file['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $errors = [];
            if (!in_array($file_ext, $allowed_extensions)) {
                $errors[] = "Invalid file type.";
            }
            if ($file_size > $max_file_size) {
                $errors[] = "File too large (max 2MB).";
            }
            if (!getimagesize($file_tmp)) {
                $errors[] = "Not a valid image.";
            }

            if (empty($errors)) {
                $unique_name = uniqid('prod_', true) . '_' . time() . '.' . $file_ext;
                $image_path = $upload_dir . $unique_name;

                if (move_uploaded_file($file_tmp, $image_path)) {
                    $thumbnail_name = 'thumb_' . $unique_name;
                    $thumbnail_path = $upload_dir . $thumbnail_name;
                    if (createThumbnail($image_path, $thumbnail_path)) {
                        $image_thumbnail_path = $thumbnail_path;
                    }
                    $image_path = str_replace('../', '', $image_path);
                    $image_thumbnail_path = $image_thumbnail_path ? str_replace('../', '', $image_thumbnail_path) : null;
                } else {
                    $errors[] = "Upload failed.";
                }
            }
            if (!empty($errors)) {
                $error = implode("<br>", $errors);
            }
        }

        if (empty($error)) {
            $errors = [];
            if (empty($product_name)) $errors[] = "Product name required.";
            if ($purchase_price_base <= 0) $errors[] = "Purchase price must be greater than 0.";
            if ($retail_price_base <= 0) $errors[] = "Retail price must be greater than 0.";
            
            // GST validation
            if ($gst_type === 'exclusive' && !$gst_id) {
                $errors[] = "Please select GST rate when GST is Exclusive.";
            }
            
            // Price hierarchy validation
            if ($final_purchase_price > 0 && $final_retail_price > 0 && $final_retail_price <= $final_purchase_price) {
                $errors[] = "Final Retail Price (with GST) must be greater than Final Purchase Price (with GST).";
            }

            if ($referral_enabled && $referral_value <= 0) $errors[] = "Referral value must be > 0.";
            if ($referral_enabled && $referral_type === 'percentage' && $referral_value > 100) $errors[] = "Referral % cannot exceed 100.";

            // Secondary unit validation
            if ($secondary_unit && $sec_unit_conversion <= 0) {
                $errors[] = "If secondary unit is specified, conversion rate must be greater than 0.";
            }
            if (!$secondary_unit && $sec_unit_conversion > 0) {
                $errors[] = "Please specify secondary unit name if entering conversion rate.";
            }

            // Warranty validation
            if ($is_warranty_applicable) {
                if ($warranty_type === 'none') {
                    $errors[] = "Please select warranty type if warranty is applicable.";
                }
                if ($warranty_period <= 0) {
                    $errors[] = "Warranty period must be greater than 0.";
                }
                if ($warranty_period > 120) {
                    $errors[] = "Warranty period cannot exceed 120 months/10 years.";
                }
            }

            // Duplicate checks
            if (!empty($barcode)) {
                $check = $pdo->prepare("SELECT id FROM products WHERE barcode = ? AND business_id = ?");
                $check->execute([$barcode, $current_business_id]);
                if ($check->fetch()) $errors[] = "Barcode already exists.";
            }
            if (!empty($product_code)) {
                $check = $pdo->prepare("SELECT id FROM products WHERE product_code = ? AND business_id = ?");
                $check->execute([$product_code, $current_business_id]);
                if ($check->fetch()) $errors[] = "Product code already exists.";
            }

            if (!empty($errors)) {
                $error = implode("<br>", $errors);
                if ($image_path) {
                    @unlink('../' . $image_path);
                    if ($image_thumbnail_path) @unlink('../' . $image_thumbnail_path);
                }
            } else {
                try {
                    $pdo->beginTransaction();

                    // Insert product - stock_price now contains price WITH GST
                    $stmt = $pdo->prepare("
                        INSERT INTO products (
                            business_id, product_name, product_code, barcode,
                            image_path, image_thumbnail_path, image_alt_text,
                            category_id, subcategory_id, description, unit_of_measure,
                            secondary_unit, sec_unit_conversion, sec_unit_price_type, sec_unit_extra_charge,
                            stock_price, retail_price, wholesale_price,
                            min_stock_level, gst_id, hsn_code, gst_type, gst_amount,
                            referral_enabled, referral_type, referral_value,
                            mrp, discount_type, discount_value,
                            retail_price_type, retail_price_value,
                            wholesale_price_type, wholesale_price_value,
                            warranty_type, warranty_period, warranty_unit, warranty_description,
                            created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?, ?, ?, ?
                        )
                    ");

                    $stmt->execute([
                        $current_business_id,
                        $product_name,
                        $product_code ?: null,
                        $barcode ?: null,
                        $image_path,
                        $image_thumbnail_path,
                        $image_alt_text ?: null,
                        $category_id,
                        $subcategory_id,
                        $description ?: null,
                        $unit,
                        $secondary_unit,
                        $sec_unit_conversion,
                        $sec_unit_price_type,
                        $sec_unit_extra_charge,
                        $stock_price, // Price WITH GST (e.g., ₹118)
                        $final_retail_price, // Final retail price WITH GST
                        $wholesale_price,
                        $min_stock_level,
                        $gst_id,
                        $hsn_code,
                        $gst_type,
                        $retail_gst_amount, // Store retail GST amount
                        $referral_enabled,
                        $referral_type,
                        $referral_value,
                        0, // mrp (not used)
                        'percentage', // discount_type
                        0, // discount_value
                        'manual', // retail_price_type
                        0, // retail_price_value
                        'percentage', // wholesale_price_type
                        0, // wholesale_price_value
                        $is_warranty_applicable ? $warranty_type : 'none',
                        $is_warranty_applicable ? $warranty_period : 0,
                        $is_warranty_applicable ? $warranty_unit : 'months',
                        $is_warranty_applicable ? $warranty_description : null,
                        date('Y-m-d H:i:s')
                    ]);

                    $product_id = $pdo->lastInsertId();

                    // Calculate total secondary units for initial stock
                    $total_secondary_units = null;
                    if ($sec_unit_conversion && $sec_unit_conversion > 0 && $initial_stock > 0) {
                        $total_secondary_units = $initial_stock * $sec_unit_conversion;
                    }

                    // Add initial stock to product_stocks table
                    if ($initial_stock > 0) {
                        $check_stock = $pdo->prepare("SELECT id, quantity FROM product_stocks WHERE product_id = ? AND shop_id = ?");
                        $check_stock->execute([$product_id, $current_shop_id]);
                        $existing_stock = $check_stock->fetch();

                        if ($existing_stock) {
                            $old_quantity = $existing_stock['quantity'];
                            
                            $update_stmt = $pdo->prepare("
                                UPDATE product_stocks 
                                SET quantity = quantity + ?, 
                                    total_secondary_units = COALESCE(total_secondary_units, 0) + ?,
                                    last_updated = NOW()
                                WHERE product_id = ? AND shop_id = ?
                            ");
                            $update_stmt->execute([
                                $initial_stock,
                                $total_secondary_units,
                                $product_id,
                                $current_shop_id
                            ]);
                            
                            $new_quantity = $old_quantity + $initial_stock;
                        } else {
                            $old_quantity = 0;
                            
                            $insert_stmt = $pdo->prepare("
                                INSERT INTO product_stocks 
                                (product_id, shop_id, business_id, quantity, total_secondary_units, last_updated) 
                                VALUES (?, ?, ?, ?, ?, NOW())
                            ");
                            $insert_stmt->execute([
                                $product_id,
                                $current_shop_id,
                                $current_business_id,
                                $initial_stock,
                                $total_secondary_units
                            ]);
                            
                            $new_quantity = $initial_stock;
                        }
                        
                        // Generate unique adjustment number
                        $date = new DateTime();
                        $adjustment_number = 'ADJ' . $date->format('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        
                        $check_adj = $pdo->prepare("SELECT id FROM stock_adjustments WHERE adjustment_number = ?");
                        $check_adj->execute([$adjustment_number]);
                        while ($check_adj->fetch()) {
                            $adjustment_number = 'ADJ' . $date->format('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                            $check_adj->execute([$adjustment_number]);
                        }
                        
                        $adj_stmt = $pdo->prepare("
                            INSERT INTO stock_adjustments (
                                adjustment_number,
                                product_id,
                                shop_id,
                                adjustment_type,
                                quantity,
                                old_stock,
                                new_stock,
                                reason,
                                reference_type,
                                notes,
                                adjusted_by,
                                adjusted_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        
                        $notes = "Initial stock added";
                        if ($total_secondary_units && $secondary_unit) {
                            $notes .= " ($total_secondary_units $secondary_unit)";
                        }
                        
                        $adj_stmt->execute([
                            $adjustment_number,
                            $product_id,
                            $current_shop_id,
                            'add',
                            $initial_stock,
                            $old_quantity,
                            $new_quantity,
                            'Initial stock on product creation',
                            'initial_stock',
                            $notes,
                            $_SESSION['user_id']
                        ]);
                        
                    } else {
                        $check_stock = $pdo->prepare("SELECT id FROM product_stocks WHERE product_id = ? AND shop_id = ?");
                        $check_stock->execute([$product_id, $current_shop_id]);
                        
                        if (!$check_stock->fetch()) {
                            $insert_stmt = $pdo->prepare("
                                INSERT INTO product_stocks 
                                (product_id, shop_id, business_id, quantity, total_secondary_units, last_updated) 
                                VALUES (?, ?, ?, 0, NULL, NOW())
                            ");
                            $insert_stmt->execute([
                                $product_id,
                                $current_shop_id,
                                $current_business_id
                            ]);
                            
                            $adjustment_number = 'ADJ' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                            
                            $adj_stmt = $pdo->prepare("
                                INSERT INTO stock_adjustments (
                                    adjustment_number,
                                    product_id,
                                    shop_id,
                                    adjustment_type,
                                    quantity,
                                    old_stock,
                                    new_stock,
                                    reason,
                                    reference_type,
                                    notes,
                                    adjusted_by,
                                    adjusted_at
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            
                            $adj_stmt->execute([
                                $adjustment_number,
                                $product_id,
                                $current_shop_id,
                                'add',
                                0,
                                0,
                                0,
                                'Product created with zero stock',
                                'initial_stock',
                                'Product added with no initial stock',
                                $_SESSION['user_id']
                            ]);
                        }
                    }

                    $pdo->commit();
                    
                    $success_message = "Product '$product_name' added successfully!";
                    if ($initial_stock > 0) {
                        $success_message .= " Initial stock added: $initial_stock $unit";
                        if ($total_secondary_units && $secondary_unit) {
                            $success_message .= " ($total_secondary_units $secondary_unit)";
                        }
                    }
                    
                    set_flash_message('success', $success_message);

                    if (isset($_POST['submit']) && $_POST['submit'] === 'add_another') {
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit();
                    } else {
                        header('Location: products.php');
                        exit();
                    }
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    if ($image_path) {
                        @unlink('../' . $image_path);
                        if ($image_thumbnail_path) @unlink('../' . $image_thumbnail_path);
                    }
                    $error = "Database error: " . $e->getMessage();
                    error_log("Add product error: " . $e->getMessage());
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Add New Product - Business 28"; ?>
<?php include('includes/head.php'); ?>

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

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-plus-circle me-2"></i> Add New Product
                                <span class="badge bg-info ms-2">Business Type 28</span>
                                <span class="badge bg-warning ms-2">No MRP Required</span>
                                <span class="badge bg-success ms-2">GST on Purchase & Retail Price</span>
                            </h4>
                            <a href="products.php" class="btn btn-outline-secondary">
                                <i class="bx bx-arrow-back me-1"></i> Back to Products
                            </a>
                        </div>
                    </div>
                </div>

                <?php display_flash_message(); ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bx bx-error-circle fs-4 me-2"></i> <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" id="addProductForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token(); ?>">
                    
                    <div class="row">
                        <div class="col-lg-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-package me-1"></i> Product Information
                                        <small class="text-muted ms-2">
                                            Business: <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'N/A') ?>
                                            | Shop: <?= htmlspecialchars($_SESSION['current_shop_name'] ?? 'N/A') ?>
                                        </small>
                                    </h5>

                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Category</strong> <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="openCategoryModal()"><i class="bx bx-plus"></i> Quick Add</button></label>
                                            <select name="category_id" id="categorySelect" class="form-select" required>
                                                <option value="">-- Select Category --</option>
                                                <?php foreach($categories as $c): ?>
                                                <option value="<?= $c['id'] ?>" 
                                                    <?= (isset($_POST['category_id']) && $_POST['category_id'] == $c['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['category_name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (empty($categories)): ?>
                                            <div class="form-text text-warning">
                                                <i class="bx bx-info-circle"></i> No categories found. 
                                                <button type="button" class="btn btn-link p-0" onclick="openCategoryModal()">Create one now</button>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Subcategory</strong> <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="openSubcategoryModal()"><i class="bx bx-plus"></i> Quick Add</button></label>
                                            <select name="subcategory_id" id="subcategorySelect" class="form-select">
                                                <option value="">-- Select Subcategory --</option>
                                            </select>
                                            <div class="form-text">Optional - select subcategory for better organization</div>
                                            <div id="subcategoryLoading" class="form-text text-muted" style="display: none;">
                                                <i class="bx bx-loader bx-spin"></i> Loading subcategories...
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Product Name <span class="text-danger">*</span></strong></label>
                                            <input type="text" name="product_name" class="form-control form-control-lg" 
                                                   value="<?= htmlspecialchars($_POST['product_name'] ?? '') ?>" 
                                                   required autofocus>
                                            <div class="form-text">Enter the product display name</div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Product Code</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">#</span>
                                                <input type="text" name="product_code" class="form-control" 
                                                       value="<?= htmlspecialchars($_POST['product_code'] ?? '') ?>" 
                                                       placeholder="e.g., PROD001">
                                            </div>
                                            <div class="form-text">Unique product identifier (optional)</div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Barcode</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bx bx-barcode"></i></span>
                                                <input type="text" name="barcode" class="form-control" 
                                                       value="<?= htmlspecialchars($_POST['barcode'] ?? '') ?>" 
                                                       placeholder="Scan or type barcode">
                                                <button type="button" class="btn btn-outline-secondary" onclick="generateBarcode()">
                                                    <i class="bx bx-refresh"></i> Generate
                                                </button>
                                            </div>
                                            <div class="form-text">Scan barcode or enter manually</div>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Unit of Measure</strong></label>
                                            <select name="unit" class="form-select">
                                                <option value="pcs" <?= ($_POST['unit'] ?? 'pcs') == 'pcs' ? 'selected' : '' ?>>Pieces (pcs)</option>
                                                <option value="coil" <?= ($_POST['unit'] ?? '') == 'coil' ? 'selected' : '' ?>>Coil</option>
                                                <option value="mtr" <?= ($_POST['unit'] ?? '') == 'mtr' ? 'selected' : '' ?>>Meter (mtr)</option>
                                                <option value="kg" <?= ($_POST['unit'] ?? '') == 'kg' ? 'selected' : '' ?>>Kilogram (kg)</option>
                                                <option value="ltr" <?= ($_POST['unit'] ?? '') == 'ltr' ? 'selected' : '' ?>>Liter (ltr)</option>
                                                <option value="nos" <?= ($_POST['unit'] ?? '') == 'nos' ? 'selected' : '' ?>>Number (nos)</option>
                                                <option value="box" <?= ($_POST['unit'] ?? '') == 'box' ? 'selected' : '' ?>>Box</option>
                                                <option value="feet" <?= ($_POST['unit'] ?? '') == 'feet' ? 'selected' : '' ?>>Feet</option>
                                                <option value="length" <?= ($_POST['unit'] ?? '') == 'length' ? 'selected' : '' ?>>Length</option>
                                                <option value="roll/mtr" <?= ($_POST['unit'] ?? '') == 'roll/mtr' ? 'selected' : '' ?>>Roll/Meter</option>
                                            </select>
                                        </div>

                                        <!-- Secondary Unit Section -->
                                        <div class="col-md-12 mt-4">
                                            <h6 class="border-bottom pb-2 mb-3">
                                                <i class="bx bx-transfer me-1"></i> Secondary Unit Conversion
                                                <small class="text-muted">– Sell in different units (e.g., coil → meters)</small>
                                            </h6>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label">Secondary Unit</label>
                                            <input type="text" name="secondary_unit" class="form-control"
                                                   value="<?= htmlspecialchars($_POST['secondary_unit'] ?? '') ?>"
                                                   placeholder="e.g., mtr, kg, ft"
                                                   onchange="calculateSecondaryPrices()">
                                            <div class="form-text">Unit for selling (leave blank if not needed)</div>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label">Conversion Rate</label>
                                            <div class="input-group">
                                                <span class="input-group-text">1 primary =</span>
                                                <input type="number" step="0.0001" min="0" name="sec_unit_conversion"
                                                       class="form-control text-end" id="secUnitConversion"
                                                       value="<?= htmlspecialchars($_POST['sec_unit_conversion'] ?? '') ?>"
                                                       onchange="calculateSecondaryPrices()"
                                                       placeholder="e.g., 90">
                                                <span class="input-group-text" id="secondaryUnitLabel">
                                                    <?= htmlspecialchars($_POST['secondary_unit'] ?? 'units') ?>
                                                </span>
                                            </div>
                                            <div class="form-text">How many secondary units in 1 primary unit</div>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label">Extra Charge Type</label>
                                            <select name="sec_unit_price_type" id="secUnitPriceType" class="form-select"
                                                    onchange="updateSecUnitExtraUnit(); calculateSecondaryPrices()">
                                                <option value="fixed" <?= ($_POST['sec_unit_price_type'] ?? 'fixed') == 'fixed' ? 'selected' : '' ?>>Fixed (₹)</option>
                                                <option value="percentage" <?= ($_POST['sec_unit_price_type'] ?? '') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                            </select>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label">Extra Charge Value</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" name="sec_unit_extra_charge"
                                                       class="form-control text-end" id="secUnitExtraCharge"
                                                       value="<?= htmlspecialchars($_POST['sec_unit_extra_charge'] ?? '0') ?>"
                                                       onchange="calculateSecondaryPrices()"
                                                       placeholder="0.00">
                                                <span class="input-group-text" id="secUnitExtraUnit">₹</span>
                                            </div>
                                            <div class="form-text" id="extraChargeHelp">
                                                Extra charge per secondary unit
                                            </div>
                                        </div>

                                        <!-- Secondary Unit Price Preview -->
                                        <div class="col-md-12 mt-3" id="secondaryPricePreview" style="display:none;">
                                            <div class="alert alert-info py-3">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <h6 class="mb-2"><i class="bx bx-store-alt me-1"></i> Retail Price (Secondary Unit)</h6>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Price per <?php echo $_POST['secondary_unit'] ?? 'secondary unit'; ?>:</span>
                                                            <strong id="secRetailPricePerUnit">₹0.00</strong>
                                                        </div>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Base price (no extra):</span>
                                                            <span id="secRetailBasePrice">₹0.00</span>
                                                        </div>
                                                        <div class="d-flex justify-content-between">
                                                            <span>Extra charge:</span>
                                                            <span id="secRetailExtraCharge">₹0.00</span>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <h6 class="mb-2"><i class="bx bx-building-house me-1"></i> Wholesale Price (Secondary Unit)</h6>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Price per <?php echo $_POST['secondary_unit'] ?? 'secondary unit'; ?>:</span>
                                                            <strong id="secWholesalePricePerUnit">₹0.00</strong>
                                                        </div>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Base price (no extra):</span>
                                                            <span id="secWholesaleBasePrice">₹0.00</span>
                                                        </div>
                                                        <div class="d-flex justify-content-between">
                                                            <span>Extra charge:</span>
                                                            <span id="secWholesaleExtraCharge">₹0.00</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <hr class="my-2">
                                                <div class="row">
                                                    <div class="col-md-12">
                                                        <small class="text-muted">
                                                            <i class="bx bx-info-circle me-1"></i>
                                                            <strong>Example:</strong> If 1 coil = 90 meters and Retail Price = ₹900 per coil, 
                                                            then price per meter = ₹10.00 + extra charge
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Description</label>
                                            <textarea name="description" class="form-control" rows="3" 
                                                      placeholder="Features, brand, specifications..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4"><i class="bx bx-image me-1"></i> Product Image</h5>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><strong>Product Image</strong></label>
                                                <input type="file" name="product_image" id="productImage" class="form-control" accept="image/*">
                                                <div class="form-text">
                                                    Upload product image (max 2MB). Supported formats: JPG, PNG, GIF, WEBP
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Image Alt Text</label>
                                                <input type="text" name="image_alt_text" class="form-control" 
                                                       value="<?= htmlspecialchars($_POST['image_alt_text'] ?? '') ?>" 
                                                       placeholder="Brief description of image for accessibility">
                                                <div class="form-text">Describe the image for screen readers (optional)</div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="text-center">
                                                <div id="imagePreview" class="border rounded p-3 mb-3" style="min-height: 200px; background-color: #f8f9fa;">
                                                    <i class="bx bx-image fs-1 text-muted"></i>
                                                    <p class="text-muted mt-2 mb-0">Image preview will appear here</p>
                                                </div>
                                                <div class="form-text">Preview of selected image</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4"><i class="bx bx-rupee me-1"></i> Pricing & Tax</h5>
                            
                                    <div class="row g-3">
                                        <!-- GST Type and Rate Section -->
                                        <div class="col-md-12">
                                            <h6 class="border-bottom pb-2 mb-3">
                                                <i class="bx bx-receipt me-1"></i> GST Configuration
                                                <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="openGSTModal()"><i class="bx bx-plus"></i> Quick Add GST</button>
                                            </h6>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label"><strong>GST Rate</strong></label>
                                            <select name="gst_id" id="gstSelect" class="form-select" onchange="calculateAllGST()">
                                                <option value="">-- Select GST Rate --</option>
                                                <?php foreach($gst_rates as $g): ?>
                                                <option value="<?= $g['id'] ?>" 
                                                    data-rate="<?= $g['total_gst_rate'] ?>"
                                                    <?= (isset($_POST['gst_id']) && $_POST['gst_id'] == $g['id']) ? 'selected' : '' ?>>
                                                    <?= $g['hsn_code'] ?> - Total GST: <?= $g['total_gst_rate'] ?>%
                                                    (CGST: <?= $g['cgst_rate'] ?>%, SGST: <?= $g['sgst_rate'] ?>%, IGST: <?= $g['igst_rate'] ?>%)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">Select applicable GST rate</div>
                                            <?php if (empty($gst_rates)): ?>
                                            <div class="form-text text-warning">
                                                <i class="bx bx-info-circle"></i> No GST rates configured. 
                                                <button type="button" class="btn btn-link p-0" onclick="openGSTModal()">Add GST rates</button>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label"><strong>GST Type</strong></label>
                                            <div class="d-flex align-items-center">
                                                <div class="form-check form-switch me-3">
                                                    <input class="form-check-input" type="checkbox" id="gstTypeToggle" 
                                                           name="gst_type" value="exclusive"
                                                           <?= ($_POST['gst_type'] ?? 'exclusive') == 'exclusive' ? 'checked' : '' ?>
                                                           onchange="updateGSTType()">
                                                    <label class="form-check-label" for="gstTypeToggle">
                                                        <span id="gstTypeLabel">GST Exclusive</span>
                                                    </label>
                                                </div>
                                                <div id="gstTypeHelp" class="form-text">
                                                    <i class="bx bx-info-circle"></i>
                                                    <span id="gstTypeDescription">GST will be added to prices</span>
                                                </div>
                                            </div>
                                            <input type="hidden" name="gst_type" id="gstTypeHidden" value="<?= $_POST['gst_type'] ?? 'exclusive' ?>">
                                        </div>

                                        <!-- Purchase Price Section -->
                                        <div class="col-md-12 mt-3">
                                            <h6 class="border-bottom pb-2 mb-3">
                                                <i class="bx bx-cart me-1"></i> Purchase Price
                                            </h6>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Base Purchase Price (Without GST) <span class="text-danger">*</span></strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="purchase_price_base" 
                                                       class="form-control form-control-lg text-end" 
                                                       value="<?= htmlspecialchars($_POST['purchase_price_base'] ?? '') ?>" 
                                                       id="purchasePriceBase" required
                                                       oninput="calculateAllGST()">
                                            </div>
                                            <div class="form-text">Enter the base purchase price without GST</div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Final Purchase Price (With GST)</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="text" class="form-control form-control-lg text-end" 
                                                       id="finalPurchasePriceDisplay" readonly disabled>
                                            </div>
                                            <div class="form-text">
                                                <span class="text-danger">This will be stored as Stock Price</span>
                                            </div>
                                        </div>

                                        <!-- Purchase GST Calculation Preview -->
                                        <div class="col-md-12 mt-2" id="purchaseGstPreview" style="display:none;">
                                            <div class="alert alert-info py-2">
                                                <div class="d-flex justify-content-between">
                                                    <span>Base Purchase Price:</span>
                                                    <strong id="purchaseBaseDisplay">₹0.00</strong>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>GST Amount (<?= $gst_rate_percentage ?? 0 ?>%):</span>
                                                    <strong id="purchaseGstAmountDisplay">₹0.00</strong>
                                                </div>
                                                <hr class="my-1">
                                                <div class="d-flex justify-content-between">
                                                    <span>Final Purchase Price:</span>
                                                    <strong class="text-success" id="purchaseFinalDisplay">₹0.00</strong>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Retail Price Section -->
                                        <div class="col-md-12 mt-4">
                                            <h6 class="border-bottom pb-2 mb-3">
                                                <i class="bx bx-store-alt me-1"></i> Sale / Retail Price (For Customers)
                                            </h6>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Base Retail Price (Without GST) <span class="text-danger">*</span></strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="retail_price_base" 
                                                       class="form-control form-control-lg text-end" 
                                                       value="<?= htmlspecialchars($_POST['retail_price_base'] ?? '') ?>" 
                                                       id="retailPriceBase" required
                                                       oninput="calculateAllGST()">
                                            </div>
                                            <div class="form-text">Enter the base retail price without GST</div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Final Retail Price (With GST)</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="text" class="form-control form-control-lg text-end" 
                                                       id="finalRetailPriceDisplay" readonly disabled>
                                            </div>
                                            <div class="form-text">
                                                <span class="text-success">This will be the selling price</span>
                                            </div>
                                        </div>

                                        <!-- Retail GST Calculation Preview -->
                                        <div class="col-md-12 mt-2" id="retailGstPreview" style="display:none;">
                                            <div class="alert alert-info py-2">
                                                <div class="d-flex justify-content-between">
                                                    <span>Base Retail Price:</span>
                                                    <strong id="retailBaseDisplay">₹0.00</strong>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>GST Amount (<?= $gst_rate_percentage ?? 0 ?>%):</span>
                                                    <strong id="retailGstAmountDisplay">₹0.00</strong>
                                                </div>
                                                <hr class="my-1">
                                                <div class="d-flex justify-content-between">
                                                    <span>Final Retail Price:</span>
                                                    <strong class="text-success" id="retailFinalDisplay">₹0.00</strong>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Profit Margin Display -->
                                        <div class="col-md-12 mt-3">
                                            <div class="alert alert-success py-2">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span><i class="bx bx-trending-up me-1"></i> Profit Margin:</span>
                                                    <div>
                                                        <span class="fw-bold" id="profitMarginDisplay">0.00%</span>
                                                        <span class="ms-3">Profit Amount: <strong id="profitAmountDisplay">₹0.00</strong></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Warranty Section -->
                                        <div class="col-md-12 mt-4">
                                            <h6 class="border-bottom pb-2 mb-3">
                                                <i class="bx bx-shield me-1"></i> Warranty Information
                                            </h6>
                                        </div>

                                        <div class="col-md-12">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="warrantyCheckbox" 
                                                       name="is_warranty_applicable" value="1"
                                                       <?= isset($_POST['is_warranty_applicable']) ? 'checked' : '' ?>
                                                       onchange="toggleWarrantyFields()">
                                                <label class="form-check-label fw-bold" for="warrantyCheckbox">
                                                    This product comes with warranty
                                                </label>
                                            </div>
                                        </div>

                                        <div id="warrantyFields" style="display: none;">
                                            <div class="col-md-4">
                                                <label class="form-label"><strong>Warranty Type</strong></label>
                                                <select name="warranty_type" id="warrantyType" class="form-select">
                                                    <option value="none">-- Select Warranty Type --</option>
                                                    <option value="manufacturer" <?= ($_POST['warranty_type'] ?? '') == 'manufacturer' ? 'selected' : '' ?>>Manufacturer Warranty</option>
                                                    <option value="seller" <?= ($_POST['warranty_type'] ?? '') == 'seller' ? 'selected' : '' ?>>Seller Warranty</option>
                                                    <option value="extended" <?= ($_POST['warranty_type'] ?? '') == 'extended' ? 'selected' : '' ?>>Extended Warranty</option>
                                                    <option value="international" <?= ($_POST['warranty_type'] ?? '') == 'international' ? 'selected' : '' ?>>International Warranty</option>
                                                </select>
                                            </div>

                                            <div class="col-md-4">
                                                <label class="form-label"><strong>Warranty Period</strong></label>
                                                <div class="input-group">
                                                    <input type="number" name="warranty_period" 
                                                           class="form-control text-end" 
                                                           value="<?= htmlspecialchars($_POST['warranty_period'] ?? '12') ?>"
                                                           min="0" max="120" step="1">
                                                    <select name="warranty_unit" class="form-select" style="width: auto;">
                                                        <option value="days" <?= ($_POST['warranty_unit'] ?? 'months') == 'days' ? 'selected' : '' ?>>Days</option>
                                                        <option value="months" <?= ($_POST['warranty_unit'] ?? 'months') == 'months' ? 'selected' : '' ?>>Months</option>
                                                        <option value="years" <?= ($_POST['warranty_unit'] ?? '') == 'years' ? 'selected' : '' ?>>Years</option>
                                                    </select>
                                                </div>
                                                <div class="form-text">Enter warranty duration (max 10 years / 120 months)</div>
                                            </div>

                                            <div class="col-md-4">
                                                <label class="form-label"><strong>Warranty Description</strong></label>
                                                <textarea name="warranty_description" class="form-control" rows="2" 
                                                          placeholder="e.g., Covers manufacturing defects, 1 year free service..."><?= htmlspecialchars($_POST['warranty_description'] ?? '') ?></textarea>
                                            </div>
                                        </div>

                                        <!-- Other Fields -->
                                        <div class="col-md-3 mt-3">
                                            <label class="form-label">Min Stock Level</label>
                                            <input type="number" name="min_stock_level" class="form-control text-end" 
                                                   value="<?= htmlspecialchars($_POST['min_stock_level'] ?? '0') ?>">
                                            <div class="form-text">Low stock alert level</div>
                                        </div>

                                        <div class="col-md-3 mt-3">
                                            <label class="form-label">Initial Stock</label>
                                            <div class="input-group">
                                                <input type="number" name="initial_stock" class="form-control text-end" 
                                                       value="<?= htmlspecialchars($_POST['initial_stock'] ?? '0') ?>" 
                                                       min="0" placeholder="0">
                                                <span class="input-group-text">units</span>
                                            </div>
                                            <div class="form-text">Add initial stock to current shop (valued at Final Purchase Price with GST)</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-gift me-1"></i> Referral Commission
                                    </h5>

                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="referralEnabled" 
                                               name="referral_enabled"
                                               <?= isset($_POST['referral_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold" for="referralEnabled">
                                            Enable referral commission for this product
                                        </label>
                                    </div>

                                    <div id="referralBox" style="display:none;">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Commission Type</label>
                                                <select name="referral_type" class="form-select">
                                                    <option value="percentage" <?= ($_POST['referral_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                                    <option value="fixed" <?= ($_POST['referral_type'] ?? '') == 'fixed' ? 'selected' : '' ?>>Fixed Amount (₹)</option>
                                                </select>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Commission Value</label>
                                                <div class="input-group">
                                                    <input type="number" step="0.01" min="0"
                                                           name="referral_value"
                                                           class="form-control text-end"
                                                           value="<?= htmlspecialchars($_POST['referral_value'] ?? '') ?>"
                                                           placeholder="Enter value">
                                                    <span class="input-group-text">
                                                        <span id="commissionUnit">%</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-text mt-2">
                                            <i class="bx bx-info-circle me-1"></i>
                                            This commission will be credited to referrers when sales are completed.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Actions -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">Quick Actions</h5>
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                                        <button type="submit" name="submit" value="save" class="btn btn-success btn-lg px-4">
                                            <i class="bx bx-save me-2"></i> Save Product
                                        </button>
                                        <button type="submit" name="submit" value="add_another" class="btn btn-primary px-4">
                                            <i class="bx bx-plus-circle me-1"></i> Save & Add Another
                                        </button>
                                        <button type="button" class="btn btn-outline-info px-4" onclick="populateSampleData()">
                                            <i class="bx bx-test-tube me-1"></i> Fill Sample Data
                                        </button>
                                        <a href="products.php" class="btn btn-outline-secondary px-4">
                                            <i class="bx bx-x me-1"></i> Cancel
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quick Tips -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h6 class="card-title mb-3"><i class="bx bx-info-circle me-1"></i> Quick Tips</h6>
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Step 1:</strong> Select GST rate and type (Exclusive/Inclusive)</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Step 2:</strong> Enter Base Purchase Price (without GST)</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Step 3:</strong> Enter Base Retail Price (without GST)</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Step 4:</strong> GST will be calculated automatically for both prices</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Step 5:</strong> Final Purchase Price (with GST) will be stored as Stock Price</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Example:</strong> Base Purchase ₹100, Base Retail ₹150 + 18% GST = Purchase ₹118, Retail ₹177</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Profit Margin:</strong> ((Final Retail - Final Purchase) / Final Retail) × 100</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Secondary Unit:</strong> Convert primary units (coil) to secondary units (mtr)</li>
                                        <li><i class="bx bx-check text-success me-1"></i> <strong>Note:</strong> MRP and Discount are not used for this business type</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Quick Add Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-category"></i> Quick Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="quickCategoryForm">
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="categoryName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category Code (Optional)</label>
                        <input type="text" class="form-control" id="categoryCode">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="categoryDescription" rows="2"></textarea>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveCategoryBtn">
                            <i class="bx bx-save"></i> Save Category
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Quick Add Subcategory Modal -->
<div class="modal fade" id="subcategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-category-alt"></i> Quick Add Subcategory</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="quickSubcategoryForm">
                    <div class="mb-3">
                        <label class="form-label">Parent Category <span class="text-danger">*</span></label>
                        <select class="form-select" id="subcategoryParentCategory" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subcategory Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="subcategoryName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subcategory Code (Optional)</label>
                        <input type="text" class="form-control" id="subcategoryCode">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="subcategoryDescription" rows="2"></textarea>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveSubcategoryBtn">
                            <i class="bx bx-save"></i> Save Subcategory
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Quick Add GST Modal -->
<div class="modal fade" id="gstModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-receipt"></i> Quick Add GST Rate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="quickGSTForm">
                    <div class="mb-3">
                        <label class="form-label">HSN Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="hsnCode" required placeholder="e.g., 7318">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" id="gstDescription" placeholder="Product/Service description">
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">CGST Rate (%)</label>
                            <input type="number" step="0.01" class="form-control" id="cgstRate" value="0" onchange="updateTotalGST()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">SGST Rate (%)</label>
                            <input type="number" step="0.01" class="form-control" id="sgstRate" value="0" onchange="updateTotalGST()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IGST Rate (%)</label>
                            <input type="number" step="0.01" class="form-control" id="igstRate" value="0" onchange="updateTotalGST()">
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 mb-3">
                        <strong>Total GST Rate:</strong> <span id="totalGSTRate">0.00</span>%
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveGSTBtn">
                            <i class="bx bx-save"></i> Save GST Rate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>
<script>
// Global variables
let gstRate = 0;
let gstType = 'exclusive';

// Sweet Toast Alert Function
function showSweetToast(title, message, type = 'info', duration = 3000) {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: duration,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });
    
    Toast.fire({
        icon: type,
        title: title,
        text: message
    });
}

function showSweetError(title, message) {
    Swal.fire({
        icon: 'error',
        title: title,
        text: message,
        confirmButtonColor: '#3085d6'
    });
}

function showSweetSuccess(title, message, duration = 2000) {
    Swal.fire({
        icon: 'success',
        title: title,
        text: message,
        timer: duration,
        showConfirmButton: false
    });
}

// Warranty toggle
function toggleWarrantyFields() {
    const checkbox = document.getElementById('warrantyCheckbox');
    const warrantyFields = document.getElementById('warrantyFields');
    if (warrantyFields) {
        warrantyFields.style.display = checkbox.checked ? 'block' : 'none';
    }
}

// Update GST Type based on toggle
function updateGSTType() {
    const gstToggle = document.getElementById('gstTypeToggle');
    const gstTypeLabel = document.getElementById('gstTypeLabel');
    const gstTypeDescription = document.getElementById('gstTypeDescription');
    const gstTypeHidden = document.getElementById('gstTypeHidden');
    
    if (gstToggle && gstToggle.checked) {
        gstType = 'exclusive';
        if (gstTypeLabel) gstTypeLabel.textContent = 'GST Exclusive';
        if (gstTypeDescription) gstTypeDescription.textContent = 'GST will be added to prices';
        if (gstTypeHidden) gstTypeHidden.value = 'exclusive';
    } else {
        gstType = 'inclusive';
        if (gstTypeLabel) gstTypeLabel.textContent = 'GST Inclusive';
        if (gstTypeDescription) gstTypeDescription.textContent = 'GST is included in prices';
        if (gstTypeHidden) gstTypeHidden.value = 'inclusive';
    }
    
    calculateAllGST();
}

// Calculate all GST (both purchase and retail)
function calculateAllGST() {
    const gstSelect = document.getElementById('gstSelect');
    const selectedOption = gstSelect ? gstSelect.options[gstSelect.selectedIndex] : null;
    const purchaseBase = parseFloat(document.getElementById('purchasePriceBase').value) || 0;
    const retailBase = parseFloat(document.getElementById('retailPriceBase').value) || 0;
    
    gstRate = 0;
    
    if (selectedOption && selectedOption.value) {
        gstRate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
    }
    
    // Calculate Purchase GST
    calculatePurchaseGST(purchaseBase, gstRate);
    
    // Calculate Retail GST
    calculateRetailGST(retailBase, gstRate);
    
    // Calculate Profit Margin
    calculateProfitMargin();
    
    // Calculate Secondary Prices
    calculateSecondaryPrices();
}

// Calculate Purchase GST
function calculatePurchaseGST(basePrice, rate) {
    const purchasePreview = document.getElementById('purchaseGstPreview');
    const purchaseBaseDisplay = document.getElementById('purchaseBaseDisplay');
    const purchaseGstAmountDisplay = document.getElementById('purchaseGstAmountDisplay');
    const purchaseFinalDisplay = document.getElementById('purchaseFinalDisplay');
    const finalPurchasePriceDisplay = document.getElementById('finalPurchasePriceDisplay');
    
    if (basePrice > 0 && rate > 0) {
        if (purchasePreview) purchasePreview.style.display = 'block';
        
        let gstAmount = 0;
        let finalPrice = basePrice;
        
        if (gstType === 'exclusive') {
            gstAmount = basePrice * (rate / 100);
            finalPrice = basePrice + gstAmount;
        } else {
            gstAmount = (basePrice * rate) / (100 + rate);
            finalPrice = basePrice;
        }
        
        if (purchaseBaseDisplay) purchaseBaseDisplay.textContent = '₹' + basePrice.toFixed(2);
        if (purchaseGstAmountDisplay) purchaseGstAmountDisplay.textContent = '₹' + gstAmount.toFixed(2);
        if (purchaseFinalDisplay) purchaseFinalDisplay.textContent = '₹' + finalPrice.toFixed(2);
        if (finalPurchasePriceDisplay) finalPurchasePriceDisplay.value = '₹' + finalPrice.toFixed(2);
    } else {
        if (purchasePreview) purchasePreview.style.display = 'none';
        if (finalPurchasePriceDisplay && basePrice > 0) {
            finalPurchasePriceDisplay.value = '₹' + basePrice.toFixed(2);
        } else if (finalPurchasePriceDisplay) {
            finalPurchasePriceDisplay.value = '';
        }
    }
}

// Calculate Retail GST
function calculateRetailGST(basePrice, rate) {
    const retailPreview = document.getElementById('retailGstPreview');
    const retailBaseDisplay = document.getElementById('retailBaseDisplay');
    const retailGstAmountDisplay = document.getElementById('retailGstAmountDisplay');
    const retailFinalDisplay = document.getElementById('retailFinalDisplay');
    const finalRetailPriceDisplay = document.getElementById('finalRetailPriceDisplay');
    
    if (basePrice > 0 && rate > 0) {
        if (retailPreview) retailPreview.style.display = 'block';
        
        let gstAmount = 0;
        let finalPrice = basePrice;
        
        if (gstType === 'exclusive') {
            gstAmount = basePrice * (rate / 100);
            finalPrice = basePrice + gstAmount;
        } else {
            gstAmount = (basePrice * rate) / (100 + rate);
            finalPrice = basePrice;
        }
        
        if (retailBaseDisplay) retailBaseDisplay.textContent = '₹' + basePrice.toFixed(2);
        if (retailGstAmountDisplay) retailGstAmountDisplay.textContent = '₹' + gstAmount.toFixed(2);
        if (retailFinalDisplay) retailFinalDisplay.textContent = '₹' + finalPrice.toFixed(2);
        if (finalRetailPriceDisplay) finalRetailPriceDisplay.value = '₹' + finalPrice.toFixed(2);
    } else {
        if (retailPreview) retailPreview.style.display = 'none';
        if (finalRetailPriceDisplay && basePrice > 0) {
            finalRetailPriceDisplay.value = '₹' + basePrice.toFixed(2);
        } else if (finalRetailPriceDisplay) {
            finalRetailPriceDisplay.value = '';
        }
    }
}

// Calculate Profit Margin
function calculateProfitMargin() {
    const finalPurchaseDisplay = document.getElementById('finalPurchasePriceDisplay');
    const finalRetailDisplay = document.getElementById('finalRetailPriceDisplay');
    const profitMarginDisplay = document.getElementById('profitMarginDisplay');
    const profitAmountDisplay = document.getElementById('profitAmountDisplay');
    
    if (finalPurchaseDisplay && finalRetailDisplay) {
        const purchasePrice = parseFloat(finalPurchaseDisplay.value.replace('₹', '')) || 0;
        const retailPrice = parseFloat(finalRetailDisplay.value.replace('₹', '')) || 0;
        
        if (purchasePrice > 0 && retailPrice > 0) {
            const profitAmount = retailPrice - purchasePrice;
            const profitMargin = retailPrice > 0 ? (profitAmount / retailPrice) * 100 : 0;
            
            if (profitMarginDisplay) {
                profitMarginDisplay.textContent = profitMargin.toFixed(2) + '%';
                // Color coding
                if (profitMargin > 20) {
                    profitMarginDisplay.style.color = '#198754';
                } else if (profitMargin > 10) {
                    profitMarginDisplay.style.color = '#fd7e14';
                } else if (profitMargin > 0) {
                    profitMarginDisplay.style.color = '#0d6efd';
                } else {
                    profitMarginDisplay.style.color = '#dc3545';
                }
            }
            
            if (profitAmountDisplay) {
                profitAmountDisplay.textContent = '₹' + profitAmount.toFixed(2);
                if (profitAmount > 0) {
                    profitAmountDisplay.style.color = '#198754';
                } else {
                    profitAmountDisplay.style.color = '#dc3545';
                }
            }
        } else {
            if (profitMarginDisplay) profitMarginDisplay.textContent = '0.00%';
            if (profitAmountDisplay) profitAmountDisplay.textContent = '₹0.00';
        }
    }
}

// Calculate Secondary Unit Prices
function calculateSecondaryPrices() {
    const secondaryUnit = document.querySelector('input[name="secondary_unit"]')?.value.trim() || '';
    const conversion = parseFloat(document.getElementById('secUnitConversion')?.value) || 0;
    const extraType = document.getElementById('secUnitPriceType')?.value || 'fixed';
    const extraCharge = parseFloat(document.getElementById('secUnitExtraCharge')?.value) || 0;
    const finalRetailDisplay = document.getElementById('finalRetailPriceDisplay');
    const retailPrice = finalRetailDisplay ? parseFloat(finalRetailDisplay.value.replace('₹', '')) || 0 : 0;

    const previewBox = document.getElementById('secondaryPricePreview');
    const secondaryUnitLabel = document.getElementById('secondaryUnitLabel');
    const extraChargeHelp = document.getElementById('extraChargeHelp');

    if (secondaryUnitLabel) {
        secondaryUnitLabel.textContent = secondaryUnit || 'units';
    }
    
    if (extraChargeHelp) {
        extraChargeHelp.innerHTML = extraType === 'fixed' 
            ? `Extra charge per ${secondaryUnit || 'secondary unit'}` 
            : `Extra charge percentage per ${secondaryUnit || 'secondary unit'}`;
    }

    if (secondaryUnit && conversion > 0 && conversion < 1000000 && previewBox) {
        previewBox.style.display = 'block';

        let retailBasePricePerUnit = retailPrice / conversion;
        let retailExtraPerUnit = 0;

        if (extraType === 'fixed') {
            retailExtraPerUnit = extraCharge;
        } else {
            retailExtraPerUnit = retailBasePricePerUnit * (extraCharge / 100);
        }

        let retailPerUnit = retailBasePricePerUnit + retailExtraPerUnit;

        const secRetailPricePerUnit = document.getElementById('secRetailPricePerUnit');
        const secRetailBasePrice = document.getElementById('secRetailBasePrice');
        const secRetailExtraCharge = document.getElementById('secRetailExtraCharge');
        
        if (secRetailPricePerUnit) secRetailPricePerUnit.textContent = `₹${retailPerUnit.toFixed(2)}`;
        if (secRetailBasePrice) secRetailBasePrice.textContent = `₹${retailBasePricePerUnit.toFixed(2)}`;
        if (secRetailExtraCharge) {
            secRetailExtraCharge.textContent = extraType === 'fixed' 
                ? `₹${retailExtraPerUnit.toFixed(2)} (fixed)` 
                : `₹${retailExtraPerUnit.toFixed(2)} (${extraCharge}%)`;
        }
    } else if (previewBox) {
        previewBox.style.display = 'none';
    }
}

// Update secondary unit extra charge unit
function updateSecUnitExtraUnit() {
    const extraType = document.getElementById('secUnitPriceType');
    const secUnitExtraUnit = document.getElementById('secUnitExtraUnit');
    if (extraType && secUnitExtraUnit) {
        secUnitExtraUnit.textContent = extraType.value === 'percentage' ? '%' : '₹';
    }
    calculateSecondaryPrices();
}

// Generate random barcode
function generateBarcode() {
    const prefix = '89';
    const random = Math.floor(Math.random() * 10000000000).toString().padStart(10, '0');
    const barcode = prefix + random;
    const barcodeField = document.querySelector('input[name="barcode"]');
    if (barcodeField) barcodeField.value = barcode;
    showSweetToast('Barcode Generated', 'New barcode has been generated', 'success', 2000);
}

// Populate sample data
async function populateSampleData() {
    const result = await Swal.fire({
        title: 'Populate Sample Data',
        text: 'This will fill the form with sample data. Continue?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, populate',
        cancelButtonText: 'Cancel'
    });
    
    if (result.isConfirmed) {
        document.querySelector('input[name="product_name"]').value = 'Sample Product';
        document.querySelector('input[name="product_code"]').value = 'PROD' + Math.floor(Math.random() * 10000);
        document.querySelector('input[name="secondary_unit"]').value = 'mtr';
        document.getElementById('secUnitConversion').value = '10';
        document.getElementById('secUnitExtraCharge').value = '0.50';
        document.getElementById('purchasePriceBase').value = '100.00';
        document.getElementById('retailPriceBase').value = '150.00';
        document.querySelector('input[name="min_stock_level"]').value = '20';
        document.querySelector('textarea[name="description"]').value = 'Sample product with GST calculation on both purchase and retail prices.';
        document.querySelector('input[name="image_alt_text"]').value = 'Sample product';
        
        document.getElementById('warrantyCheckbox').checked = true;
        toggleWarrantyFields();
        document.getElementById('warrantyType').value = 'manufacturer';
        document.querySelector('input[name="warranty_period"]').value = '12';
        document.querySelector('select[name="warranty_unit"]').value = 'months';
        document.querySelector('textarea[name="warranty_description"]').value = '1 year manufacturer warranty against manufacturing defects.';
        
        generateBarcode();
        
        calculateAllGST();
        calculateSecondaryPrices();
        
        await showSweetSuccess('Sample Data Loaded', 'Sample product data has been populated successfully!');
    }
}

// Form submission validation
document.getElementById('addProductForm')?.addEventListener('submit', async function(e) {
    const purchasePriceBase = parseFloat(document.getElementById('purchasePriceBase').value) || 0;
    const retailPriceBase = parseFloat(document.getElementById('retailPriceBase').value) || 0;
    const gstSelect = document.getElementById('gstSelect');
    const gstToggle = document.getElementById('gstTypeToggle');
    const gstType = gstToggle && gstToggle.checked ? 'exclusive' : 'inclusive';
    const warrantyCheckbox = document.getElementById('warrantyCheckbox');
    const warrantyType = document.getElementById('warrantyType')?.value || 'none';
    const warrantyPeriod = parseFloat(document.querySelector('input[name="warranty_period"]')?.value) || 0;
    const secondaryUnit = document.querySelector('input[name="secondary_unit"]')?.value.trim() || '';
    const conversion = parseFloat(document.getElementById('secUnitConversion')?.value) || 0;
    
    let errors = [];
    
    if (purchasePriceBase <= 0) {
        errors.push('Base purchase price must be greater than 0.');
    }
    
    if (retailPriceBase <= 0) {
        errors.push('Base retail price must be greater than 0.');
    }
    
    if (gstType === 'exclusive' && gstSelect && gstSelect.value === '') {
        errors.push('Please select GST rate when GST is Exclusive.');
    }
    
    const finalPurchaseDisplay = document.getElementById('finalPurchasePriceDisplay');
    const finalRetailDisplay = document.getElementById('finalRetailPriceDisplay');
    const finalPurchasePrice = finalPurchaseDisplay ? parseFloat(finalPurchaseDisplay.value.replace('₹', '')) || 0 : 0;
    const finalRetailPrice = finalRetailDisplay ? parseFloat(finalRetailDisplay.value.replace('₹', '')) || 0 : 0;
    
    if (finalPurchasePrice > 0 && finalRetailPrice > 0 && finalRetailPrice <= finalPurchasePrice) {
        errors.push('Final Retail Price (with GST) must be greater than Final Purchase Price (with GST).');
    }
    
    if (secondaryUnit && conversion <= 0) {
        errors.push('If secondary unit is specified, conversion rate must be greater than 0.');
    }
    
    if (!secondaryUnit && conversion > 0) {
        errors.push('Please specify a secondary unit name if entering conversion rate.');
    }
    
    if (warrantyCheckbox && warrantyCheckbox.checked) {
        if (warrantyType === 'none') {
            errors.push('Please select warranty type if warranty is applicable.');
        }
        if (warrantyPeriod <= 0) {
            errors.push('Warranty period must be greater than 0.');
        }
        if (warrantyPeriod > 120) {
            errors.push('Warranty period cannot exceed 120 months/10 years.');
        }
    }
    
    if (errors.length > 0) {
        e.preventDefault();
        await showSweetError('Validation Errors', errors.join('\n'));
        return;
    }
    
    const fileInput = document.getElementById('productImage');
    if (fileInput && fileInput.files.length > 0) {
        const fileSize = fileInput.files[0].size;
        const maxSize = 2 * 1024 * 1024;
        if (fileSize > maxSize) {
            e.preventDefault();
            await showSweetError('File Too Large', 'File size exceeds 2MB limit. Please choose a smaller image.');
            fileInput.focus();
        }
    }
});

// AJAX: Load subcategories when category changes
const categorySelect = document.getElementById('categorySelect');
if (categorySelect) {
    categorySelect.addEventListener('change', function() {
        const categoryId = this.value;
        const subcategorySelect = document.getElementById('subcategorySelect');
        const loadingDiv = document.getElementById('subcategoryLoading');
        
        if (!categoryId || !subcategorySelect) {
            if (subcategorySelect) subcategorySelect.innerHTML = '<option value="">-- Select Subcategory --</option>';
            return;
        }
        
        if (loadingDiv) loadingDiv.style.display = 'block';
        subcategorySelect.disabled = true;
        
        fetch(`ajax/get_subcategories.php?category_id=${categoryId}&business_id=<?= $current_business_id ?>`)
            .then(response => response.json())
            .then(data => {
                subcategorySelect.innerHTML = '<option value="">-- Select Subcategory --</option>';
                
                if (data.success && data.subcategories && data.subcategories.length > 0) {
                    data.subcategories.forEach(subcat => {
                        const option = document.createElement('option');
                        option.value = subcat.id;
                        option.textContent = subcat.subcategory_name;
                        subcategorySelect.appendChild(option);
                    });
                } else {
                    const option = document.createElement('option');
                    option.textContent = 'No subcategories available';
                    option.disabled = true;
                    subcategorySelect.appendChild(option);
                }
            })
            .catch(error => {
                console.error('Error loading subcategories:', error);
                subcategorySelect.innerHTML = '<option value="">Error loading subcategories</option>';
            })
            .finally(() => {
                if (loadingDiv) loadingDiv.style.display = 'none';
                subcategorySelect.disabled = false;
            });
    });
}

// Referral commission toggle
const referralToggle = document.getElementById('referralEnabled');
const referralBox = document.getElementById('referralBox');
const commissionUnit = document.getElementById('commissionUnit');
const referralTypeSelect = document.querySelector('select[name="referral_type"]');

function toggleReferralBox() {
    if (referralBox) {
        referralBox.style.display = referralToggle && referralToggle.checked ? 'block' : 'none';
    }
}

function updateCommissionUnit() {
    if (commissionUnit && referralTypeSelect) {
        commissionUnit.textContent = referralTypeSelect.value === 'percentage' ? '%' : '₹';
    }
}

if (referralToggle) {
    referralToggle.addEventListener('change', toggleReferralBox);
}
if (referralTypeSelect) {
    referralTypeSelect.addEventListener('change', updateCommissionUnit);
}

// Image preview
const productImageInput = document.getElementById('productImage');
if (productImageInput) {
    productImageInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        const preview = document.getElementById('imagePreview');
        
        if (file && preview) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                preview.innerHTML = `
                    <img src="${e.target.result}" class="img-fluid rounded" style="max-height: 200px; object-fit: contain;">
                    <p class="mt-2 mb-0"><small>${file.name} (${(file.size / 1024).toFixed(1)} KB)</small></p>
                `;
                showSweetToast('Image Loaded', 'Image preview is ready', 'success', 1500);
            };
            
            reader.readAsDataURL(file);
        } else if (preview) {
            preview.innerHTML = `
                <i class="bx bx-image fs-1 text-muted"></i>
                <p class="text-muted mt-2 mb-0">Image preview will appear here</p>
            `;
        }
    });
}

// Event listeners
document.getElementById('purchasePriceBase')?.addEventListener('input', calculateAllGST);
document.getElementById('retailPriceBase')?.addEventListener('input', calculateAllGST);
document.getElementById('gstSelect')?.addEventListener('change', calculateAllGST);
document.getElementById('secUnitPriceType')?.addEventListener('change', updateSecUnitExtraUnit);
document.getElementById('secUnitConversion')?.addEventListener('input', calculateSecondaryPrices);
document.getElementById('secUnitExtraCharge')?.addEventListener('input', calculateSecondaryPrices);
document.querySelector('input[name="secondary_unit"]')?.addEventListener('input', function() {
    calculateSecondaryPrices();
    const secondaryUnitLabel = document.getElementById('secondaryUnitLabel');
    if (secondaryUnitLabel) {
        secondaryUnitLabel.textContent = this.value || 'units';
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateGSTType();
    toggleReferralBox();
    updateCommissionUnit();
    updateSecUnitExtraUnit();
    toggleWarrantyFields();
    
    const purchasePriceBase = parseFloat(document.getElementById('purchasePriceBase').value) || 0;
    const retailPriceBase = parseFloat(document.getElementById('retailPriceBase').value) || 0;
    if (purchasePriceBase > 0 || retailPriceBase > 0) {
        calculateAllGST();
    }
    
    calculateSecondaryPrices();
});

// Quick Add Category Functions
function openCategoryModal() {
    const categoryNameField = document.getElementById('categoryName');
    if (categoryNameField) categoryNameField.value = '';
    const categoryCodeField = document.getElementById('categoryCode');
    if (categoryCodeField) categoryCodeField.value = '';
    const categoryDescriptionField = document.getElementById('categoryDescription');
    if (categoryDescriptionField) categoryDescriptionField.value = '';
    const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
    modal.show();
}

function openSubcategoryModal() {
    const categorySelect = document.getElementById('categorySelect');
    if (!categorySelect || !categorySelect.value) {
        showSweetToast('Select Category First', 'Please select a category before adding a subcategory', 'warning');
        return;
    }
    
    const subcategoryNameField = document.getElementById('subcategoryName');
    if (subcategoryNameField) subcategoryNameField.value = '';
    const subcategoryCodeField = document.getElementById('subcategoryCode');
    if (subcategoryCodeField) subcategoryCodeField.value = '';
    const subcategoryDescriptionField = document.getElementById('subcategoryDescription');
    if (subcategoryDescriptionField) subcategoryDescriptionField.value = '';
    const subcategoryParentCategoryField = document.getElementById('subcategoryParentCategory');
    if (subcategoryParentCategoryField) subcategoryParentCategoryField.value = categorySelect.value;
    const modal = new bootstrap.Modal(document.getElementById('subcategoryModal'));
    modal.show();
}

function openGSTModal() {
    const hsnCodeField = document.getElementById('hsnCode');
    if (hsnCodeField) hsnCodeField.value = '';
    const gstDescriptionField = document.getElementById('gstDescription');
    if (gstDescriptionField) gstDescriptionField.value = '';
    const cgstRateField = document.getElementById('cgstRate');
    if (cgstRateField) cgstRateField.value = '0';
    const sgstRateField = document.getElementById('sgstRate');
    if (sgstRateField) sgstRateField.value = '0';
    const igstRateField = document.getElementById('igstRate');
    if (igstRateField) igstRateField.value = '0';
    updateTotalGST();
    const modal = new bootstrap.Modal(document.getElementById('gstModal'));
    modal.show();
}

function updateTotalGST() {
    const cgst = parseFloat(document.getElementById('cgstRate')?.value) || 0;
    const sgst = parseFloat(document.getElementById('sgstRate')?.value) || 0;
    const igst = parseFloat(document.getElementById('igstRate')?.value) || 0;
    const total = cgst + sgst + igst;
    const totalGSTRateSpan = document.getElementById('totalGSTRate');
    if (totalGSTRateSpan) totalGSTRateSpan.textContent = total.toFixed(2);
}

// Quick Category Form Submit
document.getElementById('quickCategoryForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const categoryName = document.getElementById('categoryName')?.value.trim();
    if (!categoryName) {
        await showSweetError('Missing Information', 'Please enter category name');
        return;
    }
    
    const saveBtn = document.getElementById('saveCategoryBtn');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="bx bx-loader bx-spin"></i> Saving...';
    }
    
    fetch('ajax/quick_add_category.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `category_name=${encodeURIComponent(categoryName)}&category_code=${encodeURIComponent(document.getElementById('categoryCode')?.value || '')}&description=${encodeURIComponent(document.getElementById('categoryDescription')?.value || '')}&business_id=<?= $current_business_id ?>`
    })
    .then(response => response.json())
    .then(async data => {
        if (data.success) {
            const categorySelectField = document.getElementById('categorySelect');
            if (categorySelectField) {
                const newOption = document.createElement('option');
                newOption.value = data.category_id;
                newOption.textContent = data.category_name;
                categorySelectField.appendChild(newOption);
                categorySelectField.value = data.category_id;
            }
            
            bootstrap.Modal.getInstance(document.getElementById('categoryModal'))?.hide();
            await showSweetSuccess('Success!', 'Category added successfully!');
            
            if (categorySelectField) {
                categorySelectField.dispatchEvent(new Event('change'));
            }
        } else {
            await showSweetError('Error', data.message || 'Failed to add category');
        }
    })
    .catch(async error => {
        console.error('Error:', error);
        await showSweetError('Error', 'Failed to add category. Please try again.');
    })
    .finally(() => {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bx bx-save"></i> Save Category';
        }
    });
});

// Quick Subcategory Form Submit
document.getElementById('quickSubcategoryForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const categoryId = document.getElementById('subcategoryParentCategory')?.value;
    const subcategoryName = document.getElementById('subcategoryName')?.value.trim();
    
    if (!categoryId) {
        await showSweetError('Missing Information', 'Please select a parent category');
        return;
    }
    
    if (!subcategoryName) {
        await showSweetError('Missing Information', 'Please enter subcategory name');
        return;
    }
    
    const saveBtn = document.getElementById('saveSubcategoryBtn');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="bx bx-loader bx-spin"></i> Saving...';
    }
    
    fetch('ajax/quick_add_subcategory.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `subcategory_name=${encodeURIComponent(subcategoryName)}&category_id=${categoryId}&subcategory_code=${encodeURIComponent(document.getElementById('subcategoryCode')?.value || '')}&description=${encodeURIComponent(document.getElementById('subcategoryDescription')?.value || '')}&business_id=<?= $current_business_id ?>`
    })
    .then(response => response.json())
    .then(async data => {
        if (data.success) {
            const subcategorySelectField = document.getElementById('subcategorySelect');
            if (subcategorySelectField) {
                const newOption = document.createElement('option');
                newOption.value = data.subcategory_id;
                newOption.textContent = data.subcategory_name;
                subcategorySelectField.appendChild(newOption);
                subcategorySelectField.value = data.subcategory_id;
            }
            
            bootstrap.Modal.getInstance(document.getElementById('subcategoryModal'))?.hide();
            await showSweetSuccess('Success!', 'Subcategory added successfully!');
        } else {
            await showSweetError('Error', data.message || 'Failed to add subcategory');
        }
    })
    .catch(async error => {
        console.error('Error:', error);
        await showSweetError('Error', 'Failed to add subcategory. Please try again.');
    })
    .finally(() => {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bx bx-save"></i> Save Subcategory';
        }
    });
});

// Quick GST Form Submit
document.getElementById('quickGSTForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const hsnCode = document.getElementById('hsnCode')?.value.trim();
    if (!hsnCode) {
        await showSweetError('Missing Information', 'Please enter HSN code');
        return;
    }
    
    const cgstRate = parseFloat(document.getElementById('cgstRate')?.value) || 0;
    const sgstRate = parseFloat(document.getElementById('sgstRate')?.value) || 0;
    const igstRate = parseFloat(document.getElementById('igstRate')?.value) || 0;
    
    if (cgstRate === 0 && sgstRate === 0 && igstRate === 0) {
        await showSweetError('Missing Information', 'Please enter at least one GST rate');
        return;
    }
    
    const saveBtn = document.getElementById('saveGSTBtn');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="bx bx-loader bx-spin"></i> Saving...';
    }
    
    fetch('ajax/quick_add_gst.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `hsn_code=${encodeURIComponent(hsnCode)}&description=${encodeURIComponent(document.getElementById('gstDescription')?.value || '')}&cgst_rate=${cgstRate}&sgst_rate=${sgstRate}&igst_rate=${igstRate}&business_id=<?= $current_business_id ?>`
    })
    .then(response => response.json())
    .then(async data => {
        if (data.success) {
            const gstSelectField = document.getElementById('gstSelect');
            if (gstSelectField) {
                const newOption = document.createElement('option');
                newOption.value = data.gst_id;
                newOption.setAttribute('data-rate', data.total_gst_rate);
                newOption.textContent = `${data.hsn_code} - Total GST: ${data.total_gst_rate}% (CGST: ${data.cgst_rate}%, SGST: ${data.sgst_rate}%, IGST: ${data.igst_rate}%)`;
                gstSelectField.appendChild(newOption);
                gstSelectField.value = data.gst_id;
            }
            
            bootstrap.Modal.getInstance(document.getElementById('gstModal'))?.hide();
            await showSweetSuccess('Success!', 'GST rate added successfully!');
            calculateAllGST();
        } else {
            await showSweetError('Error', data.message || 'Failed to add GST rate');
        }
    })
    .catch(async error => {
        console.error('Error:', error);
        await showSweetError('Error', 'Failed to add GST rate. Please try again.');
    })
    .finally(() => {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bx bx-save"></i> Save GST Rate';
        }
    });
});

// Trigger category change on page load if category is already selected
<?php if (isset($_POST['category_id']) && $_POST['category_id']): ?>
document.addEventListener('DOMContentLoaded', function() {
    const categorySelectField = document.getElementById('categorySelect');
    if (categorySelectField) {
        categorySelectField.dispatchEvent(new Event('change'));
    }
});
<?php endif; ?>
</script>

<style>
.form-control-lg { 
    font-size: 1.1rem; 
    font-weight: 500;
}
.text-end::-webkit-inner-spin-button, 
.text-end::-webkit-outer-spin-button {
    opacity: 1;
}
.card-title {
    border-bottom: 2px solid var(--bs-primary);
    padding-bottom: 0.75rem;
}
.input-group-text {
    background-color: #f8f9fa;
    font-weight: 500;
}
#referralBox {
    padding: 15px;
    background-color: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #dee2e6;
    margin-top: 10px;
}
#imagePreview {
    display: flex;
    flex-direction:column;
    align-items: center;
    justify-content: center;
}
#imagePreview img {
    max-width: 100%;
    max-height: 200px;
}
.border-bottom {
    border-color: #dee2e6 !important;
}
.alert-info {
    border-left: 4px solid #0dcaf0;
}
.btn-outline-secondary {
    border-color: #dee2e6;
}
#secondaryPricePreview .alert-info {
    background-color: #f0f9ff;
    border: 1px solid #b6e0fe;
}
#secondaryPricePreview h6 {
    color: #0d6efd;
    font-size: 0.9rem;
}
#secRetailPricePerUnit {
    color: #198754;
    font-size: 1.1rem;
}
#secRetailBasePrice {
    color: #6c757d;
    font-size: 0.85rem;
}
#secRetailExtraCharge {
    color: #fd7e14;
    font-size: 0.85rem;
}
#purchaseGstPreview .alert-info, #retailGstPreview .alert-info {
    background-color: #f0f9ff;
    border: 1px solid #b6e0fe;
}
#finalPurchasePriceDisplay, #finalRetailPriceDisplay {
    background-color: #f8f9fa !important;
    font-weight: 600;
}
#finalPurchasePriceDisplay {
    color: #dc3545 !important;
}
#finalRetailPriceDisplay {
    color: #198754 !important;
}
.form-check.form-switch .form-check-input {
    width: 3.5em;
    height: 1.8em;
}
.form-check.form-switch .form-check-input:checked {
    background-color: #198754;
    border-color: #198754;
}
#gstTypeLabel {
    font-weight: 600;
    font-size: 1rem;
}
#gstTypeHelp {
    color: #6c757d;
}
#warrantyFields {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-top: 10px;
}
.modal-content {
    border-radius: 12px;
}
.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
.modal-header .btn-close {
    filter: brightness(0) invert(1);
}
#profitMarginDisplay {
    font-size: 1.2rem;
}
#profitAmountDisplay {
    font-size: 1rem;
}
</style>
</body>
</html>