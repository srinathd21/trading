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
    set_flash_message('error', 'You do not have permission to edit products');
    header('Location: dashboard.php');
    exit();
}

// Get product ID from URL
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$product_id) {
    set_flash_message('error', 'Invalid product ID');
    header('Location: products.php');
    exit();
}

$success = $error = '';
$categories = $gst_rates = [];
$product = null;

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
    
    // Fetch product data
    $product_stmt = $pdo->prepare("
        SELECT p.*, 
               c.category_name,
               s.subcategory_name,
               g.hsn_code,
               g.cgst_rate, g.sgst_rate, g.igst_rate,
               (g.cgst_rate + g.sgst_rate + g.igst_rate) as total_gst_rate
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories s ON p.subcategory_id = s.id
        LEFT JOIN gst_rates g ON p.gst_id = g.id
        WHERE p.id = ? AND p.business_id = ?
    ");
    $product_stmt->execute([$product_id, $current_business_id]);
    $product = $product_stmt->fetch();
    
    if (!$product) {
        set_flash_message('error', 'Product not found');
        header('Location: products.php');
        exit();
    }
    
    // Calculate base prices from stored final prices (with GST)
    $gst_rate = $product['total_gst_rate'] ?? 0;
    $gst_type = $product['gst_type'] ?? 'exclusive';
    
    if ($gst_rate > 0) {
        if ($gst_type === 'exclusive') {
            // Final price = Base price + GST
            // Base price = Final price / (1 + GST rate/100)
            $purchase_price_base = $product['stock_price'] / (1 + ($gst_rate / 100));
            $retail_price_base = $product['retail_price'] / (1 + ($gst_rate / 100));
        } else {
            // Final price = Base price (GST included)
            // Base price = Final price
            $purchase_price_base = $product['stock_price'];
            $retail_price_base = $product['retail_price'];
        }
    } else {
        $purchase_price_base = $product['stock_price'];
        $retail_price_base = $product['retail_price'];
    }
    
    // Fetch subcategories for the selected category
    $subcategories = [];
    if ($product['category_id']) {
        $subcat_stmt = $pdo->prepare("
            SELECT id, subcategory_name 
            FROM subcategories 
            WHERE category_id = ? AND business_id = ? AND status = 'active'
            ORDER BY subcategory_name
        ");
        $subcat_stmt->execute([$product['category_id'], $current_business_id]);
        $subcategories = $subcat_stmt->fetchAll();
    }
    
    // Get current stock quantity
    $stock_stmt = $pdo->prepare("
        SELECT quantity, total_secondary_units 
        FROM product_stocks 
        WHERE product_id = ? AND shop_id = ?
    ");
    $stock_stmt->execute([$product_id, $current_shop_id]);
    $current_stock = $stock_stmt->fetch();
    $current_quantity = $current_stock['quantity'] ?? 0;
    $current_secondary_units = $current_stock['total_secondary_units'] ?? null;
    
} catch (Exception $e) {
    $error = "Failed to load product data: " . $e->getMessage();
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

        // Purchase Price (Base price without GST)
        $purchase_price_base = (float)($_POST['purchase_price_base'] ?? 0);
        
        // Retail Price (Base price without GST)
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
        
        // Wholesale price (same as retail)
        $wholesale_price = $final_retail_price;
        
        $min_stock_level = (int)($_POST['min_stock_level'] ?? 10);
        $image_alt_text = trim($_POST['image_alt_text'] ?? '');
        $referral_enabled = isset($_POST['referral_enabled']) ? 1 : 0;
        $referral_type = $_POST['referral_type'] ?? 'percentage';
        $referral_value = (float)($_POST['referral_value'] ?? 0);
        
        // Stock adjustment fields
        $stock_adjustment_type = $_POST['stock_adjustment_type'] ?? 'none';
        $stock_adjustment_quantity = !empty($_POST['stock_adjustment_quantity']) ? (int)$_POST['stock_adjustment_quantity'] : 0;
        $stock_adjustment_reason = trim($_POST['stock_adjustment_reason'] ?? '');
        
        // Store the FINAL purchase price (including GST) as stock price
        $stock_price = $final_purchase_price;
        
        $image_path = $image_thumbnail_path = null;
        $image_changed = false;

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
                // Delete old images
                if ($product['image_path'] && file_exists('../' . $product['image_path'])) {
                    @unlink('../' . $product['image_path']);
                }
                if ($product['image_thumbnail_path'] && file_exists('../' . $product['image_thumbnail_path'])) {
                    @unlink('../' . $product['image_thumbnail_path']);
                }
                
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
                    $image_changed = true;
                } else {
                    $errors[] = "Upload failed.";
                }
            }
            if (!empty($errors)) {
                $error = implode("<br>", $errors);
            }
        }

        if (empty($error)) {
            $validation_errors = [];
            if (empty($product_name)) $validation_errors[] = "Product name required.";
            if ($purchase_price_base <= 0) $validation_errors[] = "Purchase price must be greater than 0.";
            if ($retail_price_base <= 0) $validation_errors[] = "Retail price must be greater than 0.";
            
            // GST validation
            if ($gst_type === 'exclusive' && !$gst_id) {
                $validation_errors[] = "Please select GST rate when GST is Exclusive.";
            }
            
            // Price hierarchy validation
            if ($final_purchase_price > 0 && $final_retail_price > 0 && $final_retail_price <= $final_purchase_price) {
                $validation_errors[] = "Final Retail Price (with GST) must be greater than Final Purchase Price (with GST).";
            }

            if ($referral_enabled && $referral_value <= 0) $validation_errors[] = "Referral value must be > 0.";
            if ($referral_enabled && $referral_type === 'percentage' && $referral_value > 100) $validation_errors[] = "Referral % cannot exceed 100.";

            // Secondary unit validation
            if ($secondary_unit && $sec_unit_conversion <= 0) {
                $validation_errors[] = "If secondary unit is specified, conversion rate must be greater than 0.";
            }
            if (!$secondary_unit && $sec_unit_conversion > 0) {
                $validation_errors[] = "Please specify secondary unit name if entering conversion rate.";
            }

            // Warranty validation
            if ($is_warranty_applicable) {
                if ($warranty_type === 'none') {
                    $validation_errors[] = "Please select warranty type if warranty is applicable.";
                }
                if ($warranty_period <= 0) {
                    $validation_errors[] = "Warranty period must be greater than 0.";
                }
                if ($warranty_period > 120) {
                    $validation_errors[] = "Warranty period cannot exceed 120 months/10 years.";
                }
            }
            
            // Stock adjustment validation
            if ($stock_adjustment_type !== 'none' && $stock_adjustment_quantity > 0) {
                if ($stock_adjustment_type === 'remove' && $stock_adjustment_quantity > $current_quantity) {
                    $validation_errors[] = "Cannot remove more than current stock ({$current_quantity} units).";
                }
                if (empty($stock_adjustment_reason)) {
                    $validation_errors[] = "Please provide a reason for stock adjustment.";
                }
            }

            // Duplicate checks (exclude current product)
            if (!empty($barcode)) {
                $check = $pdo->prepare("SELECT id FROM products WHERE barcode = ? AND business_id = ? AND id != ?");
                $check->execute([$barcode, $current_business_id, $product_id]);
                if ($check->fetch()) $validation_errors[] = "Barcode already exists.";
            }
            if (!empty($product_code)) {
                $check = $pdo->prepare("SELECT id FROM products WHERE product_code = ? AND business_id = ? AND id != ?");
                $check->execute([$product_code, $current_business_id, $product_id]);
                if ($check->fetch()) $validation_errors[] = "Product code already exists.";
            }

            if (!empty($validation_errors)) {
                $error = implode("<br>", $validation_errors);
                if ($image_changed && $image_path) {
                    @unlink('../' . $image_path);
                    if ($image_thumbnail_path) @unlink('../' . $image_thumbnail_path);
                }
            } else {
                try {
                    $pdo->beginTransaction();

                    // Build update query
                    $update_fields = [
                        "product_name = ?",
                        "product_code = ?",
                        "barcode = ?",
                        "category_id = ?",
                        "subcategory_id = ?",
                        "description = ?",
                        "unit_of_measure = ?",
                        "secondary_unit = ?",
                        "sec_unit_conversion = ?",
                        "sec_unit_price_type = ?",
                        "sec_unit_extra_charge = ?",
                        "stock_price = ?",
                        "retail_price = ?",
                        "wholesale_price = ?",
                        "min_stock_level = ?",
                        "gst_id = ?",
                        "hsn_code = ?",
                        "gst_type = ?",
                        "gst_amount = ?",
                        "referral_enabled = ?",
                        "referral_type = ?",
                        "referral_value = ?",
                        "warranty_type = ?",
                        "warranty_period = ?",
                        "warranty_unit = ?",
                        "warranty_description = ?",
                        "image_alt_text = ?"
                    ];
                    
                    $params = [
                        $product_name,
                        $product_code ?: null,
                        $barcode ?: null,
                        $category_id,
                        $subcategory_id,
                        $description ?: null,
                        $unit,
                        $secondary_unit,
                        $sec_unit_conversion,
                        $sec_unit_price_type,
                        $sec_unit_extra_charge,
                        $stock_price,
                        $final_retail_price,
                        $wholesale_price,
                        $min_stock_level,
                        $gst_id,
                        $hsn_code,
                        $gst_type,
                        $retail_gst_amount,
                        $referral_enabled,
                        $referral_type,
                        $referral_value,
                        $is_warranty_applicable ? $warranty_type : 'none',
                        $is_warranty_applicable ? $warranty_period : 0,
                        $is_warranty_applicable ? $warranty_unit : 'months',
                        $is_warranty_applicable ? $warranty_description : null,
                        $image_alt_text ?: null
                    ];
                    
                    // Add image paths if changed
                    if ($image_changed) {
                        $update_fields[] = "image_path = ?";
                        $update_fields[] = "image_thumbnail_path = ?";
                        $params[] = $image_path;
                        $params[] = $image_thumbnail_path;
                    }
                    
                    $params[] = $product_id;
                    
                    $update_sql = "UPDATE products SET " . implode(", ", $update_fields) . " WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute($params);

                    // Handle stock adjustment
                    if ($stock_adjustment_type !== 'none' && $stock_adjustment_quantity > 0) {
                        $old_quantity = $current_quantity;
                        
                        if ($stock_adjustment_type === 'add') {
                            $new_quantity = $old_quantity + $stock_adjustment_quantity;
                            $adj_type = 'add';
                            $reason = $stock_adjustment_reason;
                        } else {
                            $new_quantity = $old_quantity - $stock_adjustment_quantity;
                            $adj_type = 'remove';
                            $reason = $stock_adjustment_reason;
                        }
                        
                        // Calculate secondary units
                        $total_secondary_units = null;
                        if ($sec_unit_conversion && $sec_unit_conversion > 0) {
                            $total_secondary_units = $new_quantity * $sec_unit_conversion;
                        }
                        
                        // Update product_stocks
                        $check_stock = $pdo->prepare("SELECT id FROM product_stocks WHERE product_id = ? AND shop_id = ?");
                        $check_stock->execute([$product_id, $current_shop_id]);
                        
                        if ($check_stock->fetch()) {
                            $update_stock = $pdo->prepare("
                                UPDATE product_stocks 
                                SET quantity = ?, 
                                    total_secondary_units = ?,
                                    last_updated = NOW()
                                WHERE product_id = ? AND shop_id = ?
                            ");
                            $update_stock->execute([$new_quantity, $total_secondary_units, $product_id, $current_shop_id]);
                        } else {
                            $insert_stock = $pdo->prepare("
                                INSERT INTO product_stocks 
                                (product_id, shop_id, business_id, quantity, total_secondary_units, last_updated) 
                                VALUES (?, ?, ?, ?, ?, NOW())
                            ");
                            $insert_stock->execute([$product_id, $current_shop_id, $current_business_id, $new_quantity, $total_secondary_units]);
                        }
                        
                        // Generate adjustment number
                        $date = new DateTime();
                        $adjustment_number = 'ADJ' . $date->format('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        
                        $check_adj = $pdo->prepare("SELECT id FROM stock_adjustments WHERE adjustment_number = ?");
                        $check_adj->execute([$adjustment_number]);
                        while ($check_adj->fetch()) {
                            $adjustment_number = 'ADJ' . $date->format('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                            $check_adj->execute([$adjustment_number]);
                        }
                        
                        // Record adjustment
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
                        
                        $notes = "Stock {$adj_type} during product edit";
                        if ($total_secondary_units && $secondary_unit) {
                            $notes .= " (Total secondary: {$total_secondary_units} {$secondary_unit})";
                        }
                        
                        $adj_stmt->execute([
                            $adjustment_number,
                            $product_id,
                            $current_shop_id,
                            $adj_type,
                            $stock_adjustment_quantity,
                            $old_quantity,
                            $new_quantity,
                            $reason,
                            'product_edit',
                            $notes,
                            $_SESSION['user_id']
                        ]);
                    }

                    $pdo->commit();
                    
                    $success_message = "Product '{$product_name}' updated successfully!";
                    if ($stock_adjustment_type !== 'none' && $stock_adjustment_quantity > 0) {
                        $success_message .= " Stock adjusted by " . ($stock_adjustment_type === 'add' ? '+' : '-') . $stock_adjustment_quantity . " {$unit}.";
                    }
                    
                    set_flash_message('success', $success_message);
                    header('Location: products.php');
                    exit();
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    if ($image_changed && $image_path) {
                        @unlink('../' . $image_path);
                        if ($image_thumbnail_path) @unlink('../' . $image_thumbnail_path);
                    }
                    $error = "Database error: " . $e->getMessage();
                    error_log("Edit product error: " . $e->getMessage());
                }
            }
        }
    }
}

// Thumbnail function
if (!function_exists('createThumbnail')) {
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
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Edit Product - Business 28"; ?>
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
                                <i class="bx bx-edit me-2"></i> Edit Product
                                <span class="badge bg-info ms-2">Business Type 28</span>
                                <span class="badge bg-warning ms-2">No MRP Required</span>
                                <span class="badge bg-success ms-2">GST on Purchase & Retail Price</span>
                            </h4>
                            <div>
                                <a href="product_view.php?id=<?= $product_id ?>" class="btn btn-outline-info me-2">
                                    <i class="bx bx-show me-1"></i> View Product
                                </a>
                                <a href="products.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Products
                                </a>
                            </div>
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

                <?php if ($product): ?>
                <form method="POST" id="editProductForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token(); ?>">
                    
                    <div class="row">
                        <div class="col-lg-8">
                            <!-- Product Information Card -->
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
                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Category</strong></label>
                                            <select name="category_id" id="categorySelect" class="form-select" required>
                                                <option value="">-- Select Category --</option>
                                                <?php foreach($categories as $c): ?>
                                                <option value="<?= $c['id'] ?>" 
                                                    <?= $product['category_id'] == $c['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['category_name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Subcategory</strong></label>
                                            <select name="subcategory_id" id="subcategorySelect" class="form-select">
                                                <option value="">-- Select Subcategory --</option>
                                                <?php foreach($subcategories as $sc): ?>
                                                <option value="<?= $sc['id'] ?>" 
                                                    <?= $product['subcategory_id'] == $sc['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sc['subcategory_name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div id="subcategoryLoading" class="form-text text-muted" style="display: none;">
                                                <i class="bx bx-loader bx-spin"></i> Loading subcategories...
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Product Name <span class="text-danger">*</span></strong></label>
                                            <input type="text" name="product_name" class="form-control form-control-lg" 
                                                   value="<?= htmlspecialchars($product['product_name']) ?>" 
                                                   required autofocus>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Product Code</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">#</span>
                                                <input type="text" name="product_code" class="form-control" 
                                                       value="<?= htmlspecialchars($product['product_code'] ?? '') ?>" 
                                                       placeholder="e.g., PROD001">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Barcode</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bx bx-barcode"></i></span>
                                                <input type="text" name="barcode" class="form-control" 
                                                       value="<?= htmlspecialchars($product['barcode'] ?? '') ?>" 
                                                       placeholder="Scan or type barcode">
                                                <button type="button" class="btn btn-outline-secondary" onclick="generateBarcode()">
                                                    <i class="bx bx-refresh"></i> Generate
                                                </button>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Unit of Measure</strong></label>
                                            <select name="unit" class="form-select">
                                                <option value="pcs" <?= ($product['unit_of_measure'] ?? 'pcs') == 'pcs' ? 'selected' : '' ?>>Pieces (pcs)</option>
                                                <option value="coil" <?= ($product['unit_of_measure'] ?? '') == 'coil' ? 'selected' : '' ?>>Coil</option>
                                                <option value="mtr" <?= ($product['unit_of_measure'] ?? '') == 'mtr' ? 'selected' : '' ?>>Meter (mtr)</option>
                                                <option value="kg" <?= ($product['unit_of_measure'] ?? '') == 'kg' ? 'selected' : '' ?>>Kilogram (kg)</option>
                                                <option value="ltr" <?= ($product['unit_of_measure'] ?? '') == 'ltr' ? 'selected' : '' ?>>Liter (ltr)</option>
                                                <option value="nos" <?= ($product['unit_of_measure'] ?? '') == 'nos' ? 'selected' : '' ?>>Number (nos)</option>
                                                <option value="box" <?= ($product['unit_of_measure'] ?? '') == 'box' ? 'selected' : '' ?>>Box</option>
                                                <option value="feet" <?= ($product['unit_of_measure'] ?? '') == 'feet' ? 'selected' : '' ?>>Feet</option>
                                                <option value="length" <?= ($product['unit_of_measure'] ?? '') == 'length' ? 'selected' : '' ?>>Length</option>
                                                <option value="roll/mtr" <?= ($product['unit_of_measure'] ?? '') == 'roll/mtr' ? 'selected' : '' ?>>Roll/Meter</option>
                                            </select>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Description</label>
                                            <textarea name="description" class="form-control" rows="3" 
                                                      placeholder="Features, brand, specifications..."><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Secondary Unit Card -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-transfer me-1"></i> Secondary Unit Conversion
                                        <small class="text-muted">– Sell in different units (e.g., coil → meters)</small>
                                    </h5>

                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Secondary Unit</label>
                                            <input type="text" name="secondary_unit" class="form-control"
                                                   value="<?= htmlspecialchars($product['secondary_unit'] ?? '') ?>"
                                                   placeholder="e.g., mtr, kg, ft"
                                                   onchange="calculateSecondaryPrices()">
                                            <div class="form-text">Unit for selling (leave blank if not needed)</div>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Conversion Rate</label>
                                            <div class="input-group">
                                                <span class="input-group-text">1 primary =</span>
                                                <input type="number" step="0.0001" min="0" name="sec_unit_conversion"
                                                       class="form-control text-end" id="secUnitConversion"
                                                       value="<?= htmlspecialchars($product['sec_unit_conversion'] ?? '') ?>"
                                                       onchange="calculateSecondaryPrices()"
                                                       placeholder="e.g., 90">
                                                <span class="input-group-text" id="secondaryUnitLabel">
                                                    <?= htmlspecialchars($product['secondary_unit'] ?? 'units') ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="col-md-2">
                                            <label class="form-label">Extra Charge Type</label>
                                            <select name="sec_unit_price_type" id="secUnitPriceType" class="form-select"
                                                    onchange="updateSecUnitExtraUnit(); calculateSecondaryPrices()">
                                                <option value="fixed" <?= ($product['sec_unit_price_type'] ?? 'fixed') == 'fixed' ? 'selected' : '' ?>>Fixed (₹)</option>
                                                <option value="percentage" <?= ($product['sec_unit_price_type'] ?? '') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                            </select>
                                        </div>

                                        <div class="col-md-2">
                                            <label class="form-label">Extra Charge Value</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" name="sec_unit_extra_charge"
                                                       class="form-control text-end" id="secUnitExtraCharge"
                                                       value="<?= htmlspecialchars($product['sec_unit_extra_charge'] ?? '0') ?>"
                                                       onchange="calculateSecondaryPrices()"
                                                       placeholder="0.00">
                                                <span class="input-group-text" id="secUnitExtraUnit">₹</span>
                                            </div>
                                        </div>

                                        <!-- Secondary Unit Price Preview -->
                                        <div class="col-md-12 mt-3" id="secondaryPricePreview" style="display:none;">
                                            <div class="alert alert-info py-3">
                                                <div class="row">
                                                    <div class="col-md-12">
                                                        <h6 class="mb-2"><i class="bx bx-store-alt me-1"></i> Retail Price (Secondary Unit)</h6>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Price per secondary unit:</span>
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
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Pricing & Tax Card -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4"><i class="bx bx-rupee me-1"></i> Pricing & Tax</h5>
                            
                                    <div class="row g-3">
                                        <!-- GST Configuration -->
                                        <div class="col-md-12">
                                            <h6 class="border-bottom pb-2 mb-3">
                                                <i class="bx bx-receipt me-1"></i> GST Configuration
                                            </h6>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>GST Rate</strong></label>
                                            <select name="gst_id" id="gstSelect" class="form-select" onchange="calculateAllGST()">
                                                <option value="">-- Select GST Rate --</option>
                                                <?php foreach($gst_rates as $g): ?>
                                                <option value="<?= $g['id'] ?>" 
                                                    data-rate="<?= $g['total_gst_rate'] ?>"
                                                    <?= $product['gst_id'] == $g['id'] ? 'selected' : '' ?>>
                                                    <?= $g['hsn_code'] ?> - Total GST: <?= $g['total_gst_rate'] ?>%
                                                    (CGST: <?= $g['cgst_rate'] ?>%, SGST: <?= $g['sgst_rate'] ?>%, IGST: <?= $g['igst_rate'] ?>%)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>GST Type</strong></label>
                                            <div class="d-flex align-items-center">
                                                <div class="form-check form-switch me-3">
                                                    <input class="form-check-input" type="checkbox" id="gstTypeToggle" 
                                                           name="gst_type" value="exclusive"
                                                           <?= ($product['gst_type'] ?? 'exclusive') == 'exclusive' ? 'checked' : '' ?>
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
                                            <input type="hidden" name="gst_type" id="gstTypeHidden" value="<?= $product['gst_type'] ?? 'exclusive' ?>">
                                        </div>

                                        <!-- Purchase Price -->
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
                                                       value="<?= number_format($purchase_price_base, 2, '.', '') ?>" 
                                                       id="purchasePriceBase" required
                                                       oninput="calculateAllGST()">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Final Purchase Price (With GST)</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="text" class="form-control form-control-lg text-end" 
                                                       id="finalPurchasePriceDisplay" readonly disabled>
                                            </div>
                                            <div class="form-text">
                                                <span class="text-danger">This is stored as Stock Price</span>
                                            </div>
                                        </div>

                                        <!-- Purchase GST Preview -->
                                        <div class="col-md-12 mt-2" id="purchaseGstPreview" style="display:none;">
                                            <div class="alert alert-info py-2">
                                                <div class="d-flex justify-content-between">
                                                    <span>Base Purchase Price:</span>
                                                    <strong id="purchaseBaseDisplay">₹0.00</strong>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>GST Amount:</span>
                                                    <strong id="purchaseGstAmountDisplay">₹0.00</strong>
                                                </div>
                                                <hr class="my-1">
                                                <div class="d-flex justify-content-between">
                                                    <span>Final Purchase Price:</span>
                                                    <strong class="text-danger" id="purchaseFinalDisplay">₹0.00</strong>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Retail Price -->
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
                                                       value="<?= number_format($retail_price_base, 2, '.', '') ?>" 
                                                       id="retailPriceBase" required
                                                       oninput="calculateAllGST()">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label"><strong>Final Retail Price (With GST)</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="text" class="form-control form-control-lg text-end" 
                                                       id="finalRetailPriceDisplay" readonly disabled>
                                            </div>
                                            <div class="form-text">
                                                <span class="text-success">This is the selling price</span>
                                            </div>
                                        </div>

                                        <!-- Retail GST Preview -->
                                        <div class="col-md-12 mt-2" id="retailGstPreview" style="display:none;">
                                            <div class="alert alert-info py-2">
                                                <div class="d-flex justify-content-between">
                                                    <span>Base Retail Price:</span>
                                                    <strong id="retailBaseDisplay">₹0.00</strong>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>GST Amount:</span>
                                                    <strong id="retailGstAmountDisplay">₹0.00</strong>
                                                </div>
                                                <hr class="my-1">
                                                <div class="d-flex justify-content-between">
                                                    <span>Final Retail Price:</span>
                                                    <strong class="text-success" id="retailFinalDisplay">₹0.00</strong>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Profit Margin -->
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
                                    </div>
                                </div>
                            </div>

                            <!-- Stock Management Card -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-package me-1"></i> Stock Management
                                    </h5>

                                    <div class="row g-3">
                                        <div class="col-md-12">
                                            <div class="alert alert-secondary">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span><strong>Current Stock:</strong></span>
                                                    <span class="fs-5">
                                                        <strong><?= $current_quantity ?></strong> <?= htmlspecialchars($product['unit_of_measure'] ?? 'units') ?>
                                                        <?php if ($current_secondary_units && $product['secondary_unit']): ?>
                                                        <small class="text-muted ms-2">(<?= $current_secondary_units ?> <?= htmlspecialchars($product['secondary_unit']) ?>)</small>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-12">
                                            <label class="form-label"><strong>Stock Adjustment</strong></label>
                                            <select name="stock_adjustment_type" id="stockAdjustmentType" class="form-select" onchange="toggleStockAdjustmentFields()">
                                                <option value="none" selected>No stock adjustment</option>
                                                <option value="add">Add Stock (+)</option>
                                                <option value="remove">Remove Stock (-)</option>
                                            </select>
                                            <div class="form-text">Add or remove stock for this product</div>
                                        </div>

                                        <div id="stockAdjustmentFields" style="display: none;">
                                            <div class="row g-3 mt-1">
                                                <div class="col-md-6">
                                                    <label class="form-label">Quantity to <span id="adjustmentAction">add</span></label>
                                                    <div class="input-group">
                                                        <input type="number" name="stock_adjustment_quantity" 
                                                               class="form-control text-end" 
                                                               id="stockAdjustmentQuantity"
                                                               min="1" value="0">
                                                        <span class="input-group-text"><?= htmlspecialchars($product['unit_of_measure'] ?? 'units') ?></span>
                                                    </div>
                                                    <div class="form-text" id="stockAdjustmentHelp">
                                                        Enter quantity to add to current stock
                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label">Reason <span class="text-danger adjustment-required" style="display: none;">*</span></label>
                                                    <input type="text" name="stock_adjustment_reason" 
                                                           class="form-control" 
                                                           id="stockAdjustmentReason"
                                                           placeholder="e.g., New shipment received">
                                                    <div class="form-text">Provide reason for stock adjustment</div>
                                                </div>

                                                <div class="col-md-12">
                                                    <div class="alert alert-warning py-2" id="stockAdjustmentPreview">
                                                        <i class="bx bx-info-circle me-1"></i>
                                                        <span id="stockPreviewText">No adjustment selected</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Min Stock Level</label>
                                            <input type="number" name="min_stock_level" class="form-control text-end" 
                                                   value="<?= htmlspecialchars($product['min_stock_level'] ?? '0') ?>">
                                            <div class="form-text">Low stock alert level</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <!-- Product Image Card -->
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title mb-4"><i class="bx bx-image me-1"></i> Product Image</h5>

                                    <div class="mb-3">
                                        <label class="form-label"><strong>Product Image</strong></label>
                                        <input type="file" name="product_image" id="productImage" class="form-control" accept="image/*">
                                        <div class="form-text">
                                            Upload new image to replace existing (max 2MB)
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Image Alt Text</label>
                                        <input type="text" name="image_alt_text" class="form-control" 
                                               value="<?= htmlspecialchars($product['image_alt_text'] ?? '') ?>" 
                                               placeholder="Brief description of image">
                                    </div>

                                    <div class="text-center">
                                        <label class="form-label">Current Image</label>
                                        <div id="imagePreview" class="border rounded p-3 mb-3" style="min-height: 200px; background-color: #f8f9fa;">
                                            <?php if ($product['image_path'] && file_exists('../' . $product['image_path'])): ?>
                                            <img src="../<?= htmlspecialchars($product['image_path']) ?>" 
                                                 class="img-fluid rounded" style="max-height: 200px; object-fit: contain;">
                                            <p class="mt-2 mb-0"><small>Current product image</small></p>
                                            <?php else: ?>
                                            <i class="bx bx-image fs-1 text-muted"></i>
                                            <p class="text-muted mt-2 mb-0">No image uploaded</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Warranty Card -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-shield me-1"></i> Warranty Information
                                    </h5>

                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="warrantyCheckbox" 
                                               name="is_warranty_applicable" value="1"
                                               <?= $product['warranty_type'] && $product['warranty_type'] != 'none' ? 'checked' : '' ?>
                                               onchange="toggleWarrantyFields()">
                                        <label class="form-check-label fw-bold" for="warrantyCheckbox">
                                            This product comes with warranty
                                        </label>
                                    </div>

                                    <div id="warrantyFields" style="display: <?= ($product['warranty_type'] && $product['warranty_type'] != 'none') ? 'block' : 'none' ?>;">
                                        <div class="mb-3">
                                            <label class="form-label"><strong>Warranty Type</strong></label>
                                            <select name="warranty_type" id="warrantyType" class="form-select">
                                                <option value="none">-- Select Warranty Type --</option>
                                                <option value="manufacturer" <?= ($product['warranty_type'] ?? '') == 'manufacturer' ? 'selected' : '' ?>>Manufacturer Warranty</option>
                                                <option value="seller" <?= ($product['warranty_type'] ?? '') == 'seller' ? 'selected' : '' ?>>Seller Warranty</option>
                                                <option value="extended" <?= ($product['warranty_type'] ?? '') == 'extended' ? 'selected' : '' ?>>Extended Warranty</option>
                                                <option value="international" <?= ($product['warranty_type'] ?? '') == 'international' ? 'selected' : '' ?>>International Warranty</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label"><strong>Warranty Period</strong></label>
                                            <div class="input-group">
                                                <input type="number" name="warranty_period" 
                                                       class="form-control text-end" 
                                                       value="<?= htmlspecialchars($product['warranty_period'] ?? '12') ?>"
                                                       min="0" max="120" step="1">
                                                <select name="warranty_unit" class="form-select" style="width: auto;">
                                                    <option value="days" <?= ($product['warranty_unit'] ?? 'months') == 'days' ? 'selected' : '' ?>>Days</option>
                                                    <option value="months" <?= ($product['warranty_unit'] ?? 'months') == 'months' ? 'selected' : '' ?>>Months</option>
                                                    <option value="years" <?= ($product['warranty_unit'] ?? '') == 'years' ? 'selected' : '' ?>>Years</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label"><strong>Warranty Description</strong></label>
                                            <textarea name="warranty_description" class="form-control" rows="2" 
                                                      placeholder="e.g., Covers manufacturing defects..."><?= htmlspecialchars($product['warranty_description'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Referral Card -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-gift me-1"></i> Referral Commission
                                    </h5>

                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="referralEnabled" 
                                               name="referral_enabled"
                                               <?= $product['referral_enabled'] ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold" for="referralEnabled">
                                            Enable referral commission
                                        </label>
                                    </div>

                                    <div id="referralBox" style="display: <?= $product['referral_enabled'] ? 'block' : 'none' ?>;">
                                        <div class="mb-3">
                                            <label class="form-label">Commission Type</label>
                                            <select name="referral_type" id="referralType" class="form-select">
                                                <option value="percentage" <?= ($product['referral_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                                <option value="fixed" <?= ($product['referral_type'] ?? '') == 'fixed' ? 'selected' : '' ?>>Fixed Amount (₹)</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Commission Value</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0"
                                                       name="referral_value"
                                                       class="form-control text-end"
                                                       value="<?= htmlspecialchars($product['referral_value'] ?? '') ?>"
                                                       placeholder="Enter value">
                                                <span class="input-group-text">
                                                    <span id="commissionUnit">%</span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Info Card -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-4"><i class="bx bx-info-circle me-1"></i> Product Details</h5>
                                    
                                    <table class="table table-sm">
                                        <tr>
                                            <td class="text-muted">Created:</td>
                                            <td><?= date('d M Y, h:i A', strtotime($product['created_at'])) ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted">Product ID:</td>
                                            <td>#<?= $product_id ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted">HSN Code:</td>
                                            <td><?= htmlspecialchars($product['hsn_code'] ?? 'N/A') ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                                        <button type="submit" class="btn btn-success btn-lg px-4">
                                            <i class="bx bx-save me-2"></i> Update Product
                                        </button>
                                        <a href="product_view.php?id=<?= $product_id ?>" class="btn btn-outline-secondary px-4">
                                            <i class="bx bx-x me-1"></i> Cancel
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
                <?php endif; ?>

            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>
<script>
// Global variables
let gstRate = <?= $product['total_gst_rate'] ?? 0 ?>;
let gstType = '<?= $product['gst_type'] ?? 'exclusive' ?>';
let currentStock = <?= $current_quantity ?>;

// Sweet Toast Functions
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

// Warranty toggle
function toggleWarrantyFields() {
    const checkbox = document.getElementById('warrantyCheckbox');
    const warrantyFields = document.getElementById('warrantyFields');
    if (warrantyFields) {
        warrantyFields.style.display = checkbox.checked ? 'block' : 'none';
    }
}

// Stock adjustment toggle
function toggleStockAdjustmentFields() {
    const select = document.getElementById('stockAdjustmentType');
    const fields = document.getElementById('stockAdjustmentFields');
    const actionSpan = document.getElementById('adjustmentAction');
    const helpText = document.getElementById('stockAdjustmentHelp');
    const previewText = document.getElementById('stockPreviewText');
    const quantityInput = document.getElementById('stockAdjustmentQuantity');
    const reasonInput = document.getElementById('stockAdjustmentReason');
    const requiredSpan = document.querySelector('.adjustment-required');
    
    if (select && fields) {
        const value = select.value;
        
        if (value !== 'none') {
            fields.style.display = 'block';
            
            if (value === 'add') {
                if (actionSpan) actionSpan.textContent = 'add';
                if (helpText) helpText.textContent = 'Enter quantity to add to current stock';
            } else if (value === 'remove') {
                if (actionSpan) actionSpan.textContent = 'remove';
                if (helpText) helpText.textContent = 'Enter quantity to remove from current stock (cannot exceed current stock)';
            }
            
            // Enable fields when visible
            if (quantityInput) {
                quantityInput.disabled = false;
            }
            if (reasonInput) {
                reasonInput.disabled = false;
            }
            if (requiredSpan) requiredSpan.style.display = 'inline';
            
        } else {
            fields.style.display = 'none';
            
            // Disable fields when hidden
            if (quantityInput) {
                quantityInput.disabled = true;
                quantityInput.value = '0';
            }
            if (reasonInput) {
                reasonInput.disabled = true;
                reasonInput.value = '';
            }
            if (requiredSpan) requiredSpan.style.display = 'none';
        }
        
        updateStockPreview();
    }
}

// Update stock preview
function updateStockPreview() {
    const select = document.getElementById('stockAdjustmentType');
    const quantity = parseInt(document.getElementById('stockAdjustmentQuantity')?.value) || 0;
    const previewText = document.getElementById('stockPreviewText');
    const quantityInput = document.getElementById('stockAdjustmentQuantity');
    
    if (select && previewText) {
        const value = select.value;
        
        if (value === 'none' || quantity === 0) {
            previewText.textContent = 'No adjustment will be made';
            if (quantityInput) quantityInput.style.borderColor = '';
        } else if (value === 'add') {
            const newStock = currentStock + quantity;
            previewText.textContent = `Stock will increase from ${currentStock} to ${newStock} (+${quantity})`;
            if (quantityInput) quantityInput.style.borderColor = '#198754';
        } else if (value === 'remove') {
            if (quantity > currentStock) {
                previewText.textContent = `⚠️ Cannot remove ${quantity} units (only ${currentStock} available)`;
                if (quantityInput) quantityInput.style.borderColor = '#dc3545';
            } else {
                const newStock = currentStock - quantity;
                previewText.textContent = `Stock will decrease from ${currentStock} to ${newStock} (-${quantity})`;
                if (quantityInput) quantityInput.style.borderColor = quantity > 0 ? '#fd7e14' : '';
            }
        }
    }
}

// Update GST Type
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

// Calculate all GST
function calculateAllGST() {
    const gstSelect = document.getElementById('gstSelect');
    const selectedOption = gstSelect ? gstSelect.options[gstSelect.selectedIndex] : null;
    const purchaseBase = parseFloat(document.getElementById('purchasePriceBase').value) || 0;
    const retailBase = parseFloat(document.getElementById('retailPriceBase').value) || 0;
    
    gstRate = 0;
    
    if (selectedOption && selectedOption.value) {
        gstRate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
    }
    
    calculatePurchaseGST(purchaseBase, gstRate);
    calculateRetailGST(retailBase, gstRate);
    calculateProfitMargin();
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
        if (purchaseGstAmountDisplay) purchaseGstAmountDisplay.textContent = '₹' + gstAmount.toFixed(2) + ' (' + rate.toFixed(2) + '%)';
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
        if (retailGstAmountDisplay) retailGstAmountDisplay.textContent = '₹' + gstAmount.toFixed(2) + ' (' + rate.toFixed(2) + '%)';
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
                profitMarginDisplay.style.color = profitMargin > 0 ? '#198754' : '#dc3545';
            }
            
            if (profitAmountDisplay) {
                profitAmountDisplay.textContent = '₹' + profitAmount.toFixed(2);
                profitAmountDisplay.style.color = profitAmount > 0 ? '#198754' : '#dc3545';
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

    if (secondaryUnitLabel) {
        secondaryUnitLabel.textContent = secondaryUnit || 'units';
    }

    if (secondaryUnit && conversion > 0 && previewBox) {
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

// Form validation
document.getElementById('editProductForm')?.addEventListener('submit', async function(e) {
    const purchasePriceBase = parseFloat(document.getElementById('purchasePriceBase').value) || 0;
    const retailPriceBase = parseFloat(document.getElementById('retailPriceBase').value) || 0;
    const gstSelect = document.getElementById('gstSelect');
    const warrantyCheckbox = document.getElementById('warrantyCheckbox');
    const warrantyType = document.getElementById('warrantyType')?.value || 'none';
    const warrantyPeriod = parseFloat(document.querySelector('input[name="warranty_period"]')?.value) || 0;
    const secondaryUnit = document.querySelector('input[name="secondary_unit"]')?.value.trim() || '';
    const conversion = parseFloat(document.getElementById('secUnitConversion')?.value) || 0;
    const stockAdjustmentType = document.getElementById('stockAdjustmentType')?.value || 'none';
    
    let errors = [];
    
    if (purchasePriceBase <= 0) errors.push('Base purchase price must be greater than 0.');
    if (retailPriceBase <= 0) errors.push('Base retail price must be greater than 0.');
    
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
        if (warrantyType === 'none') errors.push('Please select warranty type if warranty is applicable.');
        if (warrantyPeriod <= 0) errors.push('Warranty period must be greater than 0.');
        if (warrantyPeriod > 120) errors.push('Warranty period cannot exceed 120 months/10 years.');
    }
    
    // Only validate stock adjustment if type is not 'none'
    if (stockAdjustmentType !== 'none') {
        const stockAdjustmentQty = parseInt(document.getElementById('stockAdjustmentQuantity')?.value) || 0;
        const stockAdjustmentReason = document.getElementById('stockAdjustmentReason')?.value.trim() || '';
        
        if (stockAdjustmentQty <= 0) {
            errors.push('Stock adjustment quantity must be greater than 0.');
        }
        
        if (stockAdjustmentType === 'remove' && stockAdjustmentQty > currentStock) {
            errors.push(`Cannot remove more than current stock (${currentStock} units).`);
        }
        
        if (!stockAdjustmentReason) {
            errors.push('Please provide a reason for stock adjustment.');
        }
    }
    
    if (errors.length > 0) {
        e.preventDefault();
        await showSweetError('Validation Errors', errors.join('\n'));
        return false;
    }
    
    // Before form submission, enable disabled fields so their values are submitted
    if (stockAdjustmentType !== 'none') {
        document.getElementById('stockAdjustmentQuantity')?.removeAttribute('disabled');
        document.getElementById('stockAdjustmentReason')?.removeAttribute('disabled');
    }
    
    return true;
});

// Event Listeners
document.getElementById('purchasePriceBase')?.addEventListener('input', calculateAllGST);
document.getElementById('retailPriceBase')?.addEventListener('input', calculateAllGST);
document.getElementById('gstSelect')?.addEventListener('change', calculateAllGST);
document.getElementById('secUnitPriceType')?.addEventListener('change', updateSecUnitExtraUnit);
document.getElementById('secUnitConversion')?.addEventListener('input', calculateSecondaryPrices);
document.getElementById('secUnitExtraCharge')?.addEventListener('input', calculateSecondaryPrices);
document.getElementById('stockAdjustmentType')?.addEventListener('change', toggleStockAdjustmentFields);
document.getElementById('stockAdjustmentQuantity')?.addEventListener('input', updateStockPreview);
document.querySelector('input[name="secondary_unit"]')?.addEventListener('input', function() {
    calculateSecondaryPrices();
    const secondaryUnitLabel = document.getElementById('secondaryUnitLabel');
    if (secondaryUnitLabel) secondaryUnitLabel.textContent = this.value || 'units';
});

// Category change - load subcategories
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

// Referral toggle
const referralToggle = document.getElementById('referralEnabled');
const referralBox = document.getElementById('referralBox');
const commissionUnit = document.getElementById('commissionUnit');
const referralTypeSelect = document.getElementById('referralType');

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

if (referralToggle) referralToggle.addEventListener('change', toggleReferralBox);
if (referralTypeSelect) referralTypeSelect.addEventListener('change', updateCommissionUnit);

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
                    <p class="mt-2 mb-0"><small class="text-success">New image: ${file.name} (${(file.size / 1024).toFixed(1)} KB)</small></p>
                `;
            };
            
            reader.readAsDataURL(file);
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateGSTType();
    toggleReferralBox();
    updateCommissionUnit();
    updateSecUnitExtraUnit();
    toggleWarrantyFields();
    
    // Initialize stock adjustment fields as disabled
    const quantityInput = document.getElementById('stockAdjustmentQuantity');
    const reasonInput = document.getElementById('stockAdjustmentReason');
    if (quantityInput) {
        quantityInput.disabled = true;
    }
    if (reasonInput) {
        reasonInput.disabled = true;
    }
    
    toggleStockAdjustmentFields();
    
    calculateAllGST();
    calculateSecondaryPrices();
});
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
#secondaryPricePreview .alert-info {
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
#warrantyFields {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-top: 10px;
}
#stockAdjustmentFields {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-top: 10px;
}
#profitMarginDisplay {
    font-size: 1.2rem;
}
#profitAmountDisplay {
    font-size: 1rem;
}
.table-sm td {
    padding: 0.5rem 0.75rem;
}
</style>
</body>
</html>