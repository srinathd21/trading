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

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager','stock_manager', 'shop_manager'])) {
    set_flash_message('error', 'You do not have permission to edit products');
    header('Location: dashboard.php');
    exit();
}

// Get product ID
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$product_id) {
    set_flash_message('error', 'Invalid product ID');
    header('Location: products.php');
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

// Fetch product data
try {
    // Get product details
    $product_stmt = $pdo->prepare("
        SELECT p.*, 
               ps.id as stock_id,
               ps.quantity as current_stock,
               ps.total_secondary_units
        FROM products p
        LEFT JOIN product_stocks ps ON p.id = ps.product_id AND ps.shop_id = ?
        WHERE p.id = ? AND p.business_id = ?
    ");
    $product_stmt->execute([$current_shop_id, $product_id, $current_business_id]);
    $product = $product_stmt->fetch();

    if (!$product) {
        set_flash_message('error', 'Product not found');
        header('Location: products.php');
        exit();
    }

    // Fetch categories
    $categories = $pdo->prepare("
        SELECT id, category_name 
        FROM categories 
        WHERE business_id = ? AND status = 'active' AND parent_id IS NULL 
        ORDER BY category_name
    ");
    $categories->execute([$current_business_id]);
    $categories = $categories->fetchAll();

    // Fetch subcategories if category is selected
    $subcategories = [];
    if ($product['category_id']) {
        $subcat_stmt = $pdo->prepare("
            SELECT id, subcategory_name 
            FROM subcategories 
            WHERE category_id = ? AND status = 'active' 
            ORDER BY subcategory_name
        ");
        $subcat_stmt->execute([$product['category_id']]);
        $subcategories = $subcat_stmt->fetchAll();
    }

    // Fetch GST rates
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
    $error = "Failed to load data: " . $e->getMessage();
}

// Thumbnail function (same as add page)
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
        $gst_type = $_POST['gst_type'] ?? 'inclusive';
        $gst_id = !empty($_POST['gst_id']) ? (int)$_POST['gst_id'] : null;

        // Secondary unit fields
        $secondary_unit = !empty($_POST['secondary_unit']) ? trim($_POST['secondary_unit']) : null;
        $sec_unit_conversion = !empty($_POST['sec_unit_conversion']) ? (float)$_POST['sec_unit_conversion'] : null;
        $sec_unit_price_type = $_POST['sec_unit_price_type'] ?? 'fixed';
        $sec_unit_extra_charge = !empty($_POST['sec_unit_extra_charge']) ? (float)$_POST['sec_unit_extra_charge'] : 0.00;

        // MRP and GST Calculation
        $mrp_input = (float)($_POST['mrp_input'] ?? 0);
        $mrp = 0;
        
        // Fetch GST rates if selected
        $gst_rate_percentage = 0;
        $gst_amount = 0;
        $hsn_code = '';
        
        if ($gst_id) {
            $gst_stmt = $pdo->prepare("SELECT hsn_code, cgst_rate, sgst_rate, igst_rate FROM gst_rates WHERE id = ? AND business_id = ?");
            $gst_stmt->execute([$gst_id, $current_business_id]);
            $gst_row = $gst_stmt->fetch();
            if ($gst_row) {
                $hsn_code = $gst_row['hsn_code'] ?? '';
                $gst_rate_percentage = $gst_row['cgst_rate'] + $gst_row['sgst_rate'] + $gst_row['igst_rate'];
                
                if ($mrp_input > 0) {
                    if ($gst_type === 'exclusive') {
                        $gst_amount = $mrp_input * ($gst_rate_percentage / 100);
                        $mrp = $mrp_input + $gst_amount;
                    } else {
                        $mrp = $mrp_input;
                        $gst_amount = ($mrp * $gst_rate_percentage) / (100 + $gst_rate_percentage);
                    }
                }
            }
        } else {
            $mrp = $mrp_input;
        }
        
        // Combined discount field handling
        $discount_input = trim($_POST['discount'] ?? '');
        $discount_type = 'percentage';
        $discount_value = 0;
        
        if (!empty($discount_input)) {
            if (strpos($discount_input, '%') !== false) {
                $discount_type = 'percentage';
                $discount_value = (float)str_replace('%', '', $discount_input);
            } else {
                $discount_type = 'fixed';
                $discount_value = (float)$discount_input;
            }
        }
        
        $stock_price = (float)($_POST['stock_price'] ?? 0);
        $retail_price_type = $_POST['retail_price_type'] ?? 'percentage';
        $retail_price_value = (float)($_POST['retail_price_value'] ?? 0);
        $retail_price = (float)($_POST['retail_price'] ?? 0);
        
        $wholesale_price_type = $_POST['wholesale_price_type'] ?? 'percentage';
        $wholesale_price_value = (float)($_POST['wholesale_price_value'] ?? 0);
        $wholesale_price = (float)($_POST['wholesale_price'] ?? 0);
        
        $min_stock_level = (int)($_POST['min_stock_level'] ?? 10);
        $image_alt_text = trim($_POST['image_alt_text'] ?? '');
        $referral_enabled = isset($_POST['referral_enabled']) ? 1 : 0;
        $referral_type = $_POST['referral_type'] ?? 'percentage';
        $referral_value = (float)($_POST['referral_value'] ?? 0);
        
        // Stock adjustment
        $stock_adjustment = (float)($_POST['stock_adjustment'] ?? 0);
        $stock_adjustment_type = $_POST['stock_adjustment_type'] ?? 'add';
        $adjust_in_secondary = isset($_POST['adjust_in_secondary']) ? 1 : 0;

        // Delete image flag
        $delete_image = isset($_POST['delete_image']) ? true : false;

        $image_path = $product['image_path'];
        $image_thumbnail_path = $product['image_thumbnail_path'];

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
                if ($product['image_path']) {
                    @unlink('../' . $product['image_path']);
                }
                if ($product['image_thumbnail_path']) {
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
                } else {
                    $errors[] = "Upload failed.";
                }
            }
            if (!empty($errors)) {
                $error = implode("<br>", $errors);
            }
        } elseif ($delete_image) {
            // Delete image if requested
            if ($product['image_path']) {
                @unlink('../' . $product['image_path']);
            }
            if ($product['image_thumbnail_path']) {
                @unlink('../' . $product['image_thumbnail_path']);
            }
            $image_path = null;
            $image_thumbnail_path = null;
        }

        if (empty($error)) {
            $errors = [];
            if (empty($product_name)) $errors[] = "Product name required.";
            if ($mrp_input <= 0) $errors[] = "MRP is required and must be greater than 0.";
            if ($stock_price <= 0) $errors[] = "Stock price must be greater than 0.";
            if ($retail_price <= 0) $errors[] = "Retail price must be greater than 0.";
            if ($wholesale_price <= 0) $errors[] = "Wholesale price must be greater than 0.";
            if ($mrp < 0) $errors[] = "MRP cannot be negative.";
            
            // GST validation
            if ($gst_type === 'exclusive' && !$gst_id) {
                $errors[] = "Please select GST rate when product is GST Exclusive.";
            }
            
            // Price hierarchy validation
            if ($stock_price > 0 && $wholesale_price > 0 && $wholesale_price < $stock_price) {
                $errors[] = "Wholesale price must be equal to or greater than Stock Price.";
            }
            
            if ($stock_price > 0 && $retail_price > 0 && $retail_price <= $stock_price) {
                $errors[] = "Retail price must be greater than Stock Price.";
            }
            
            if ($wholesale_price > 0 && $retail_price > 0 && $wholesale_price > $retail_price) {
                $errors[] = "Wholesale price should be less than or equal to Retail Price.";
            }
            
            if ($mrp > 0) {
                if ($retail_price > $mrp) $errors[] = "Retail price cannot be higher than MRP.";
                if ($wholesale_price > $mrp) $errors[] = "Wholesale price cannot be higher than MRP.";
                if ($stock_price > $mrp) $errors[] = "Stock price cannot be higher than MRP.";
            }

            if ($discount_value < 0) $errors[] = "Discount cannot be negative.";
            if ($discount_type === 'percentage' && $discount_value > 100) $errors[] = "Discount % cannot exceed 100.";
            if ($discount_type === 'fixed' && $discount_value > $mrp && $mrp > 0) $errors[] = "Discount amount cannot exceed MRP.";

            if ($retail_price_value < 0) $errors[] = "Retail markup cannot be negative.";
            if ($wholesale_price_value < 0) $errors[] = "Wholesale markup cannot be negative.";

            if ($referral_enabled && $referral_value <= 0) $errors[] = "Referral value must be > 0.";
            if ($referral_enabled && $referral_type === 'percentage' && $referral_value > 100) $errors[] = "Referral % cannot exceed 100.";

            // Secondary unit validation
            if ($secondary_unit && $sec_unit_conversion <= 0) {
                $errors[] = "If secondary unit is specified, conversion rate must be greater than 0.";
            }
            if (!$secondary_unit && $sec_unit_conversion > 0) {
                $errors[] = "Please specify secondary unit name if entering conversion rate.";
            }
            if ($sec_unit_conversion && $sec_unit_conversion > 1000000) {
                $errors[] = "Conversion rate is too high. Please use a reasonable value.";
            }
            if ($sec_unit_conversion && $sec_unit_conversion < 0.0001) {
                $errors[] = "Conversion rate is too small. Please use a reasonable value.";
            }

            // Duplicate checks (skip if unchanged)
            if (!empty($barcode) && $barcode != $product['barcode']) {
                $check = $pdo->prepare("SELECT id FROM products WHERE barcode = ? AND business_id = ? AND id != ?");
                $check->execute([$barcode, $current_business_id, $product_id]);
                if ($check->fetch()) $errors[] = "Barcode already exists.";
            }
            if (!empty($product_code) && $product_code != $product['product_code']) {
                $check = $pdo->prepare("SELECT id FROM products WHERE product_code = ? AND business_id = ? AND id != ?");
                $check->execute([$product_code, $current_business_id, $product_id]);
                if ($check->fetch()) $errors[] = "Product code already exists.";
            }

            if (!empty($errors)) {
                $error = implode("<br>", $errors);
            } else {
                try {
                    $pdo->beginTransaction();

                    // Update product
                    $stmt = $pdo->prepare("
                        UPDATE products SET
                            product_name = ?, product_code = ?, barcode = ?,
                            image_path = ?, image_thumbnail_path = ?, image_alt_text = ?,
                            category_id = ?, subcategory_id = ?, description = ?, unit_of_measure = ?,
                            secondary_unit = ?, sec_unit_conversion = ?, sec_unit_price_type = ?, sec_unit_extra_charge = ?,
                            stock_price = ?, retail_price = ?, wholesale_price = ?,
                            min_stock_level = ?, gst_id = ?, hsn_code = ?, gst_type = ?, gst_amount = ?,
                            referral_enabled = ?, referral_type = ?, referral_value = ?,
                            mrp = ?, discount_type = ?, discount_value = ?,
                            retail_price_type = ?, retail_price_value = ?,
                            wholesale_price_type = ?, wholesale_price_value = ?,
                            updated_at = NOW()
                        WHERE id = ? AND business_id = ?
                    ");

                    $stmt->execute([
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
                        $stock_price,
                        $retail_price,
                        $wholesale_price,
                        $min_stock_level,
                        $gst_id,
                        $hsn_code,
                        $gst_type,
                        $gst_amount,
                        $referral_enabled,
                        $referral_type,
                        $referral_value,
                        $mrp,
                        $discount_type,
                        $discount_value,
                        $retail_price_type,
                        $retail_price_value,
                        $wholesale_price_type,
                        $wholesale_price_value,
                        $product_id,
                        $current_business_id
                    ]);

                    // Update stock if adjustment is made
                    if ($stock_adjustment != 0) {
                        $current_quantity = $product['current_stock'] ?? 0;
                        $old_quantity = $current_quantity;
                        
                        // Calculate quantity in primary units
                        $primary_quantity = $stock_adjustment;
                        if ($adjust_in_secondary && $sec_unit_conversion > 0) {
                            $primary_quantity = $stock_adjustment / $sec_unit_conversion;
                        }
                        
                        $new_quantity = $stock_adjustment_type === 'add' 
                            ? $current_quantity + $primary_quantity 
                            : $current_quantity - $primary_quantity;
                        
                        if ($new_quantity < 0) $new_quantity = 0;

                        // Calculate secondary units total
                        $total_secondary_units = null;
                        if ($sec_unit_conversion && $sec_unit_conversion > 0) {
                            $total_secondary_units = $new_quantity * $sec_unit_conversion;
                        }

                        // Update stock
                        $update_stmt = $pdo->prepare("
                            UPDATE product_stocks 
                            SET quantity = ?, total_secondary_units = ?, last_updated = NOW()
                            WHERE product_id = ? AND shop_id = ?
                        ");
                        $update_stmt->execute([
                            $new_quantity,
                            $total_secondary_units,
                            $product_id,
                            $current_shop_id
                        ]);

                        // Generate unique adjustment number
                        $date = new DateTime();
                        $adjustment_number = 'ADJ' . $date->format('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        
                        // Ensure uniqueness of adjustment number
                        $check_adj = $pdo->prepare("SELECT id FROM stock_adjustments WHERE adjustment_number = ?");
                        $check_adj->execute([$adjustment_number]);
                        while ($check_adj->fetch()) {
                            $adjustment_number = 'ADJ' . $date->format('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                            $check_adj->execute([$adjustment_number]);
                        }
                        
                        // Determine adjustment type
                        $adjustment_type = $stock_adjustment_type === 'add' ? 'add' : 'remove';
                        
                        // Prepare reason and notes
                        $reason = "Manual stock adjustment during product update";
                        $notes = "Stock adjusted from $old_quantity to $new_quantity";
                        
                        if ($adjust_in_secondary) {
                            $notes .= " (adjusted in secondary units: $stock_adjustment $secondary_unit)";
                        } else {
                            $notes .= " (adjusted in primary units: $primary_quantity $unit)";
                        }
                        
                        // Calculate secondary quantity for logging
                        $secondary_quantity_log = 0;
                        if ($sec_unit_conversion > 0) {
                            if ($adjust_in_secondary) {
                                $secondary_quantity_log = $stock_adjustment;
                            } else {
                                $secondary_quantity_log = $primary_quantity * $sec_unit_conversion;
                            }
                        }
                        
                        if ($secondary_unit && $sec_unit_conversion > 0) {
                            $notes .= " | Secondary unit conversion: 1 $unit = $sec_unit_conversion $secondary_unit";
                        }

                        // Record stock adjustment in stock_adjustments table
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
                            $adjustment_type,
                            $primary_quantity, // Store primary quantity in the quantity field
                            $old_quantity,
                            $new_quantity,
                            $reason,
                            'manual_adjustment',
                            $notes,
                            $_SESSION['user_id']
                        ]);
                    }

                    $pdo->commit();
                    
                    $_SESSION['success_message'] = "Product '$product_name' updated successfully!";
                    header('Location: products.php');
                    exit();
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Database error: " . $e->getMessage();
                    error_log("Edit product error: " . $e->getMessage());
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Edit Product - " . htmlspecialchars($product['product_name']); ?>
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
                                <i class="bx bx-edit me-2"></i> Edit Product: <?= htmlspecialchars($product['product_name']) ?>
                            </h4>
                            <div>
                                <a href="products.php" class="btn btn-outline-secondary me-2">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Products
                                </a>
                                <a href="view_product.php?id=<?= $product_id ?>" class="btn btn-outline-info">
                                    <i class="bx bx-show me-1"></i> View Product
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

                <form method="POST" id="editProductForm" enctype="multipart/form-data">
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
                                            <label class="form-label"><strong>Category</strong></label>
                                            <select name="category_id" id="categorySelect" class="form-select" required>
                                                <option value="">-- Select Category --</option>
                                                <?php foreach($categories as $c): ?>
                                                <option value="<?= $c['id'] ?>" 
                                                    <?= ($product['category_id'] == $c['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['category_name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Subcategory</strong></label>
                                            <select name="subcategory_id" id="subcategorySelect" class="form-select">
                                                <option value="">-- Select Subcategory --</option>
                                                <?php foreach($subcategories as $sub): ?>
                                                <option value="<?= $sub['id'] ?>" 
                                                    <?= ($product['subcategory_id'] == $sub['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sub['subcategory_name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div id="subcategoryLoading" class="form-text text-muted" style="display: none;">
                                                <i class="bx bx-loader bx-spin"></i> Loading subcategories...
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Product Name <span class="text-danger">*</span></strong></label>
                                            <input type="text" name="product_name" class="form-control form-control-lg" 
                                                   value="<?= htmlspecialchars($product['product_name']) ?>" 
                                                   required autofocus>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Product Code</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">#</span>
                                                <input type="text" name="product_code" class="form-control" 
                                                       value="<?= htmlspecialchars($product['product_code'] ?? '') ?>" 
                                                       placeholder="e.g., PROD001">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
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

                                        <div class="col-md-4">
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
                                                   value="<?= htmlspecialchars($product['secondary_unit'] ?? '') ?>"
                                                   placeholder="e.g., mtr, kg, ft"
                                                   onchange="calculateSecondaryPrices()">
                                        </div>

                                        <div class="col-md-3">
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

                                        <div class="col-md-3">
                                            <label class="form-label">Extra Charge Type</label>
                                            <select name="sec_unit_price_type" id="secUnitPriceType" class="form-select"
                                                    onchange="updateSecUnitExtraUnit(); calculateSecondaryPrices()">
                                                <option value="fixed" <?= ($product['sec_unit_price_type'] ?? 'fixed') == 'fixed' ? 'selected' : '' ?>>Fixed (₹)</option>
                                                <option value="percentage" <?= ($product['sec_unit_price_type'] ?? '') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                            </select>
                                        </div>

                                        <div class="col-md-3">
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
                                                    <div class="col-md-6">
                                                        <h6 class="mb-2"><i class="bx bx-store-alt me-1"></i> Retail Price (Secondary Unit)</h6>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Price per <?= htmlspecialchars($product['secondary_unit'] ?? 'secondary unit'); ?>:</span>
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
                                                            <span>Price per <?= htmlspecialchars($product['secondary_unit'] ?? 'secondary unit'); ?>:</span>
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
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Description</label>
                                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
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
                                                    Upload new image to replace existing (max 2MB). Supported: JPG, PNG, GIF, WEBP
                                                </div>
                                            </div>

                                            <?php if ($product['image_path']): ?>
                                            <div class="mb-3 form-check">
                                                <input type="checkbox" name="delete_image" id="deleteImage" class="form-check-input">
                                                <label class="form-check-label text-danger" for="deleteImage">
                                                    Delete current image
                                                </label>
                                            </div>
                                            <?php endif; ?>

                                            <div class="mb-3">
                                                <label class="form-label">Image Alt Text</label>
                                                <input type="text" name="image_alt_text" class="form-control" 
                                                       value="<?= htmlspecialchars($product['image_alt_text'] ?? '') ?>" 
                                                       placeholder="Brief description of image for accessibility">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="text-center">
                                                <div id="imagePreview" class="border rounded p-3 mb-3" style="min-height: 200px; background-color: #f8f9fa;">
                                                    <?php if ($product['image_thumbnail_path']): ?>
                                                        <img src="../<?= htmlspecialchars($product['image_thumbnail_path']) ?>" 
                                                             class="img-fluid rounded" style="max-height: 200px; object-fit: contain;">
                                                        <p class="mt-2 mb-0"><small>Current image</small></p>
                                                    <?php else: ?>
                                                        <i class="bx bx-image fs-1 text-muted"></i>
                                                        <p class="text-muted mt-2 mb-0">No image uploaded</p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="form-text">Current image preview</div>
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
                                            </h6>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label"><strong>GST Rate</strong></label>
                                            <select name="gst_id" id="gstSelect" class="form-select" onchange="calculateGST()">
                                                <option value="">-- Select GST Rate --</option>
                                                <?php foreach($gst_rates as $g): ?>
                                                <option value="<?= $g['id'] ?>" 
                                                    data-rate="<?= $g['total_gst_rate'] ?>"
                                                    <?= ($product['gst_id'] == $g['id']) ? 'selected' : '' ?>>
                                                    <?= $g['hsn_code'] ?> - Total GST: <?= $g['total_gst_rate'] ?>%
                                                    (CGST: <?= $g['cgst_rate'] ?>%, SGST: <?= $g['sgst_rate'] ?>%, IGST: <?= $g['igst_rate'] ?>%)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label"><strong>GST Type</strong></label>
                                            <div class="d-flex align-items-center">
                                                <div class="form-check form-switch me-3">
                                                    <input class="form-check-input" type="checkbox" id="gstTypeToggle" 
                                                           name="gst_type" value="exclusive"
                                                           <?= ($product['gst_type'] ?? 'inclusive') == 'exclusive' ? 'checked' : '' ?>
                                                           onchange="updateGSTType()">
                                                    <label class="form-check-label" for="gstTypeToggle">
                                                        <span id="gstTypeLabel"><?= ($product['gst_type'] ?? 'inclusive') == 'exclusive' ? 'GST Exclusive' : 'GST Inclusive' ?></span>
                                                    </label>
                                                </div>
                                                <div id="gstTypeHelp" class="form-text">
                                                    <i class="bx bx-info-circle"></i>
                                                    <span id="gstTypeDescription">
                                                        <?= ($product['gst_type'] ?? 'inclusive') == 'exclusive' 
                                                            ? 'GST will be added to entered price' 
                                                            : 'GST is included in product price' ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <input type="hidden" name="gst_type" id="gstTypeHidden" value="<?= $product['gst_type'] ?? 'inclusive' ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label"><strong>Enter MRP <span class="text-danger">*</span></strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="mrp_input" 
                                                       class="form-control form-control-lg text-end" 
                                                       value="<?= number_format($product['gst_type'] == 'exclusive' 
                                                           ? ($product['mrp'] - $product['gst_amount']) 
                                                           : $product['mrp'], 2, '.', '') ?>" 
                                                       id="mrpInput" required
                                                       oninput="calculateGST()">
                                            </div>
                                            <div class="form-text" id="mrpHelpText">
                                                <?= ($product['gst_type'] ?? 'inclusive') == 'exclusive' 
                                                    ? 'Enter price without GST' 
                                                    : 'Enter price including GST' ?>
                                            </div>
                                        </div>

                                        <!-- GST Calculation Preview -->
                                        <div class="col-md-12 mt-3" id="gstCalculationPreview" style="<?= $product['gst_id'] ? 'display:block;' : 'display:none;' ?>">
                                            <div class="alert alert-info py-3">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <h6 class="mb-2"><i class="bx bx-calculator me-1"></i> GST Calculation</h6>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Entered MRP:</span>
                                                            <strong id="enteredMRP">₹0.00</strong>
                                                        </div>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>GST Rate:</span>
                                                            <strong id="gstRateDisplay">0%</strong>
                                                        </div>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>GST Amount:</span>
                                                            <strong id="gstAmountDisplay">₹0.00</strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <h6 class="mb-2"><i class="bx bx-dollar me-1"></i> Final MRP</h6>
                                                        <div class="d-flex justify-content-between mb-2">
                                                            <span>Final MRP (Including GST):</span>
                                                            <strong class="text-success" id="finalMRP">₹0.00</strong>
                                                        </div>
                                                        <div class="d-flex justify-content-between">
                                                            <small class="text-muted" id="gstCalculationDescription">
                                                                GST calculation details will appear here
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- MRP and Discount Section -->
                                        <div class="col-md-12 mt-4">
                                            <h6 class="border-bottom pb-2 mb-3">
                                                <i class="bx bx-tag me-1"></i> Pricing Details
                                            </h6>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Final MRP</strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="mrp" 
                                                       class="form-control form-control-lg text-end" 
                                                       value="<?= number_format($product['mrp'], 2, '.', '') ?>" 
                                                       id="mrp" readonly>
                                            </div>
                                            <div class="form-text" id="finalMRPText">
                                                Final MRP after GST calculation
                                            </div>
                                        </div>

                                        <!-- Combined Discount Field -->
                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Discount</strong></label>
                                            <div class="input-group">
                                                <input type="text" name="discount" 
                                                       class="form-control text-end" 
                                                       value="<?= $product['discount_value'] > 0 
                                                           ? ($product['discount_type'] == 'percentage' 
                                                               ? $product['discount_value'] . '%' 
                                                               : number_format($product['discount_value'], 2, '.', '')) 
                                                           : '' ?>"
                                                       id="discount"
                                                       placeholder="e.g., 10% or 50">
                                                <button type="button" class="btn btn-outline-secondary dropdown-toggle" 
                                                        data-bs-toggle="dropdown" aria-expanded="false">
                                                    <span id="discountSymbol"><?= $product['discount_type'] == 'percentage' ? '%' : '₹' ?></span>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="#" onclick="setDiscountSymbol('%')">Percentage (%)</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="setDiscountSymbol('₹')">Fixed Amount (₹)</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item" href="#" onclick="clearDiscount()">Clear Discount</a></li>
                                                </ul>
                                            </div>
                                        </div>

                                        <!-- Stock Price Section -->
                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Purchase Price (Cost) <span class="text-danger">*</span></strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="stock_price" 
                                                       class="form-control form-control-lg text-end" 
                                                       value="<?= number_format($product['stock_price'], 2, '.', '') ?>" 
                                                       id="stockPrice" required>
                                                <button type="button" class="btn btn-outline-secondary" onclick="clearManualStockPrice()" title="Clear manual entry">
                                                    <i class="bx bx-refresh"></i>
                                                </button>
                                            </div>
                                            <div class="form-text" id="stockPriceText">
                                                <?= $product['discount_value'] > 0 
                                                    ? 'Calculated from Final MRP & Discount' 
                                                    : 'Same as Final MRP (no discount)' ?>
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>You Save</strong></label>
                                            <div class="input-group">
                                                <input type="text" class="form-control text-end" id="youSave" readonly
                                                       value="<?= number_format($product['mrp'] - $product['stock_price'], 2, '.', '') ?>">
                                                <span class="input-group-text">₹</span>
                                            </div>
                                            <div class="form-text" id="discountPercentageText">
                                                Discount: <?= $product['mrp'] > 0 
                                                    ? round((($product['mrp'] - $product['stock_price']) / $product['mrp']) * 100, 1) . '%' 
                                                    : '0%' ?>
                                            </div>
                                        </div>

                                        <!-- Retail Price Section -->
                                        <div class="col-md-12 mt-4">
                                            <h6 class="border-bottom pb-2 mb-3">
                                                <i class="bx bx-store-alt me-1"></i> Sale / Retail Price (For Customers)
                                            </h6>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Markup Type</strong></label>
                                            <select name="retail_price_type" id="retailPriceType" class="form-select">
                                                <option value="percentage" <?= ($product['retail_price_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                                <option value="fixed" <?= ($product['retail_price_type'] ?? '') == 'fixed' ? 'selected' : '' ?>>Fixed Amount (₹)</option>
                                            </select>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Markup Value</strong></label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" name="retail_price_value" 
                                                       class="form-control text-end" 
                                                       value="<?= number_format($product['retail_price_value'] ?? 0, 2, '.', '') ?>"
                                                       id="retailPriceValue">
                                                <span class="input-group-text">
                                                    <span id="retailPriceUnit"><?= ($product['retail_price_type'] ?? 'percentage') == 'percentage' ? '%' : '₹' ?></span>
                                                </span>
                                            </div>
                                            <div class="form-text" id="retailMarkupText">
                                                Markup: ₹<?= number_format($product['retail_price'] - $product['stock_price'], 2, '.', '') ?>
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Retail Price <span class="text-danger">*</span></strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="retail_price" 
                                                       class="form-control form-control-lg text-end" 
                                                       value="<?= number_format($product['retail_price'], 2, '.', '') ?>" 
                                                       id="retailPrice" required>
                                                <button type="button" class="btn btn-outline-secondary" onclick="clearManualRetailPrice()" title="Clear manual entry">
                                                    <i class="bx bx-refresh"></i>
                                                </button>
                                            </div>
                                            <div class="form-text" id="retailPriceText">
                                                Based on stock price + markup
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Profit Margin</strong></label>
                                            <div class="input-group">
                                                <input type="text" class="form-control text-end" id="retailProfitMargin" readonly
                                                       value="<?= $product['retail_price'] > 0 
                                                           ? round((($product['retail_price'] - $product['stock_price']) / $product['retail_price']) * 100, 2) 
                                                           : '' ?>">
                                                <span class="input-group-text">%</span>
                                            </div>
                                            <div class="form-text" id="retailProfitAmountText">
                                                Profit: ₹<?= number_format($product['retail_price'] - $product['stock_price'], 2, '.', '') ?>
                                            </div>
                                        </div>

                                        <!-- Wholesale Price Section -->
                                        <div class="col-md-12 mt-4">
                                            <h6 class="border-bottom pb-2 mb-3">
                                                <i class="bx bx-building-house me-1"></i> Wholesale Price (For Customers)
                                            </h6>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Markup Type</strong></label>
                                            <select name="wholesale_price_type" id="wholesalePriceType" class="form-select">
                                                <option value="percentage" <?= ($product['wholesale_price_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                                <option value="fixed" <?= ($product['wholesale_price_type'] ?? '') == 'fixed' ? 'selected' : '' ?>>Fixed Amount (₹)</option>
                                            </select>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Markup Value</strong></label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" name="wholesale_price_value" 
                                                       class="form-control text-end" 
                                                       value="<?= number_format($product['wholesale_price_value'] ?? 0, 2, '.', '') ?>"
                                                       id="wholesalePriceValue">
                                                <span class="input-group-text">
                                                    <span id="wholesalePriceUnit"><?= ($product['wholesale_price_type'] ?? 'percentage') == 'percentage' ? '%' : '₹' ?></span>
                                                </span>
                                            </div>
                                            <div class="form-text" id="wholesaleMarkupText">
                                                Markup: ₹<?= number_format($product['wholesale_price'] - $product['stock_price'], 2, '.', '') ?>
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Wholesale Price <span class="text-danger">*</span></strong></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="wholesale_price" 
                                                       class="form-control form-control-lg text-end" 
                                                       value="<?= number_format($product['wholesale_price'], 2, '.', '') ?>"
                                                       id="wholesalePrice" required>
                                                <button type="button" class="btn btn-outline-secondary" onclick="clearManualWholesalePrice()" title="Clear manual entry">
                                                    <i class="bx bx-refresh"></i>
                                                </button>
                                            </div>
                                            <div class="form-text" id="wholesalePriceText">
                                                Based on stock price + markup
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label"><strong>Profit Margin</strong></label>
                                            <div class="input-group">
                                                <input type="text" class="form-control text-end" id="wholesaleProfitMargin" readonly
                                                       value="<?= $product['wholesale_price'] > 0 
                                                           ? round((($product['wholesale_price'] - $product['stock_price']) / $product['wholesale_price']) * 100, 2) 
                                                           : '' ?>">
                                                <span class="input-group-text">%</span>
                                            </div>
                                            <div class="form-text" id="wholesaleProfitAmountText">
                                                Profit: ₹<?= number_format($product['wholesale_price'] - $product['stock_price'], 2, '.', '') ?>
                                            </div>
                                        </div>

                                        <!-- Other Fields -->
                                        <div class="col-md-3">
                                            <label class="form-label">Min Stock Level</label>
                                            <input type="number" name="min_stock_level" class="form-control text-end" 
                                                   value="<?= $product['min_stock_level'] ?? 0 ?>">
                                        </div>

                                        <!-- Stock Adjustment Section -->
                                        <div class="col-md-9">
                                            <div class="border rounded p-3 bg-light">
                                                <h6 class="mb-3"><i class="bx bx-package me-1"></i> Stock Adjustment</h6>
                                                <div class="row">
                                                    <div class="col-md-4">
                                                        <label class="form-label">Current Stock</label>
                                                        <input type="text" class="form-control" readonly 
                                                               value="<?= number_format($product['current_stock'] ?? 0, 2) ?> <?= $product['unit_of_measure'] ?? 'pcs' ?>">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Adjustment Type</label>
                                                        <select name="stock_adjustment_type" class="form-select">
                                                            <option value="add">Add Stock</option>
                                                            <option value="remove">Remove Stock</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Quantity</label>
                                                        <input type="number" name="stock_adjustment" class="form-control" 
                                                               min="0" step="1" value="0" placeholder="0">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">&nbsp;</label>
                                                        <div class="form-text">Leave 0 for no change</div>
                                                    </div>
                                                </div>
                                            </div>
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
                                               name="referral_enabled" <?= $product['referral_enabled'] ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold" for="referralEnabled">
                                            Enable referral commission for this product
                                        </label>
                                    </div>

                                    <div id="referralBox" style="<?= $product['referral_enabled'] ? 'display:block;' : 'display:none;' ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Commission Type</label>
                                                <select name="referral_type" class="form-select">
                                                    <option value="percentage" <?= ($product['referral_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                                    <option value="fixed" <?= ($product['referral_type'] ?? '') == 'fixed' ? 'selected' : '' ?>>Fixed Amount (₹)</option>
                                                </select>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Commission Value</label>
                                                <div class="input-group">
                                                    <input type="number" step="0.01" min="0"
                                                           name="referral_value"
                                                           class="form-control text-end"
                                                           value="<?= number_format($product['referral_value'] ?? 0, 2, '.', '') ?>"
                                                           placeholder="Enter value">
                                                    <span class="input-group-text">
                                                        <span id="commissionUnit"><?= ($product['referral_type'] ?? 'percentage') == 'percentage' ? '%' : '₹' ?></span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                                        <button type="submit" class="btn btn-success btn-lg px-4">
                                            <i class="bx bx-save me-2"></i> Update Product
                                        </button>
                                        <a href="products.php" class="btn btn-outline-secondary px-4">
                                            <i class="bx bx-x me-1"></i> Cancel
                                        </a>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="quickAddCategory()">
                                            <i class="bx bx-plus"></i> Quick Add Category
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="quickAddSubcategory()">
                                            <i class="bx bx-plus"></i> Quick Add Subcategory
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quick Tips -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <h6 class="card-title mb-3"><i class="bx bx-info-circle me-1"></i> Quick Tips</h6>
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Bidirectional Calculation:</strong> Enter discount/markup OR price - the other field auto-calculates</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Manual Entry:</strong> Click refresh buttons (⟳) to switch between auto and manual modes</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Price Hierarchy:</strong> Stock Price ≤ Wholesale Price ≤ Retail Price ≤ MRP</li>
                                        <li class="mb-2"><i class="bx bx-check text-success me-1"></i> <strong>Profit Margin:</strong> ((Selling Price - Cost Price) / Selling Price) × 100</li>
                                        <li><i class="bx bx-check text-success me-1"></i> <strong>Stock Adjustment:</strong> Add or remove stock without affecting pricing</li>
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

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
// Track manual entries
let manualStockPrice = false;
let manualRetailPrice = false;
let manualWholesalePrice = false;
let discountSymbol = '<?= $product['discount_type'] == 'percentage' ? '%' : '₹' ?>';

// Track calculation modes
let stockPriceCalculationMode = '<?= $product['discount_value'] > 0 ? 'auto' : 'manual' ?>';
let retailPriceCalculationMode = '<?= ($product['retail_price_value'] ?? 0) > 0 ? 'auto' : 'manual' ?>';
let wholesalePriceCalculationMode = '<?= ($product['wholesale_price_value'] ?? 0) > 0 ? 'auto' : 'manual' ?>';

// GST Calculation Variables
let gstRate = <?= $product['gst_id'] ? ($gst_rate_percentage ?? 0) : 0 ?>;
let gstType = '<?= $product['gst_type'] ?? 'inclusive' ?>';

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set manual flags based on existing data
    if (parseFloat(document.getElementById('stockPrice').value) > 0 && <?= $product['discount_value'] ?? 0 ?> == 0) {
        manualStockPrice = true;
        stockPriceCalculationMode = 'manual';
    }
    
    if (parseFloat(document.getElementById('retailPrice').value) > 0 && <?= $product['retail_price_value'] ?? 0 ?> == 0) {
        manualRetailPrice = true;
        retailPriceCalculationMode = 'manual';
    }
    
    if (parseFloat(document.getElementById('wholesalePrice').value) > 0 && <?= $product['wholesale_price_value'] ?? 0 ?> == 0) {
        manualWholesalePrice = true;
        wholesalePriceCalculationMode = 'manual';
    }
    
    // Set discount symbol
    document.getElementById('discountSymbol').textContent = discountSymbol;
    
    // Calculate GST and prices
    calculateGST();
    calculateSecondaryPrices();
    validatePriceHierarchy();
});

function setDiscountSymbol(symbol) {
    discountSymbol = symbol;
    document.getElementById('discountSymbol').textContent = symbol;
    calculateStockPrice();
}

function clearDiscount() {
    document.getElementById('discount').value = '';
    discountSymbol = '%';
    document.getElementById('discountSymbol').textContent = '%';
    calculateStockPrice();
}

// Update GST Type based on toggle
function updateGSTType() {
    const gstToggle = document.getElementById('gstTypeToggle');
    const gstTypeLabel = document.getElementById('gstTypeLabel');
    const gstTypeDescription = document.getElementById('gstTypeDescription');
    const gstTypeHidden = document.getElementById('gstTypeHidden');
    const mrpHelpText = document.getElementById('mrpHelpText');
    
    if (gstToggle.checked) {
        gstType = 'exclusive';
        gstTypeLabel.textContent = 'GST Exclusive';
        gstTypeDescription.textContent = 'GST will be added to entered price';
        gstTypeHidden.value = 'exclusive';
        mrpHelpText.textContent = 'Enter price without GST';
    } else {
        gstType = 'inclusive';
        gstTypeLabel.textContent = 'GST Inclusive';
        gstTypeDescription.textContent = 'GST is included in product price';
        gstTypeHidden.value = 'inclusive';
        mrpHelpText.textContent = 'Enter price including GST';
    }
    
    calculateGST();
}

// Calculate GST based on selected rate and type
function calculateGST() {
    const gstSelect = document.getElementById('gstSelect');
    const selectedOption = gstSelect.options[gstSelect.selectedIndex];
    const mrpInput = parseFloat(document.getElementById('mrpInput').value) || 0;
    const gstPreview = document.getElementById('gstCalculationPreview');
    const finalMRPInput = document.getElementById('mrp');
    const finalMRPText = document.getElementById('finalMRPText');
    
    gstRate = 0;
    
    if (selectedOption && selectedOption.value && mrpInput > 0) {
        gstRate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
        
        gstPreview.style.display = 'block';
        
        document.getElementById('enteredMRP').textContent = '₹' + mrpInput.toFixed(2);
        document.getElementById('gstRateDisplay').textContent = gstRate.toFixed(2) + '%';
        
        let gstAmount = 0;
        let finalMRP = mrpInput;
        
        if (gstType === 'exclusive') {
            gstAmount = mrpInput * (gstRate / 100);
            finalMRP = mrpInput + gstAmount;
            
            document.getElementById('gstAmountDisplay').textContent = '₹' + gstAmount.toFixed(2);
            document.getElementById('finalMRP').textContent = '₹' + finalMRP.toFixed(2);
            document.getElementById('gstCalculationDescription').innerHTML = 
                'Entered MRP (₹' + mrpInput.toFixed(2) + ') + GST (₹' + gstAmount.toFixed(2) + ') = Final MRP (₹' + finalMRP.toFixed(2) + ')';
            
            finalMRPText.innerHTML = `Final MRP (Including GST ₹${gstAmount.toFixed(2)})`;
            finalMRPText.style.color = '#0d6efd';
        } else {
            gstAmount = (mrpInput * gstRate) / (100 + gstRate);
            
            document.getElementById('gstAmountDisplay').textContent = '₹' + gstAmount.toFixed(2);
            document.getElementById('finalMRP').textContent = '₹' + mrpInput.toFixed(2);
            document.getElementById('gstCalculationDescription').innerHTML = 
                'Entered MRP (₹' + mrpInput.toFixed(2) + ') includes GST of ₹' + gstAmount.toFixed(2) + ' (' + gstRate.toFixed(2) + '%)';
            
            finalMRPText.innerHTML = `MRP includes GST ₹${gstAmount.toFixed(2)}`;
            finalMRPText.style.color = '#198754';
        }
        
        finalMRPInput.value = finalMRP.toFixed(2);
        
    } else {
        gstPreview.style.display = 'none';
        finalMRPInput.value = mrpInput.toFixed(2);
        
        if ((!selectedOption || !selectedOption.value) && mrpInput > 0) {
            finalMRPText.innerHTML = 'No GST applied';
            finalMRPText.style.color = '#6c757d';
        } else if (mrpInput <= 0) {
            finalMRPText.innerHTML = 'Enter MRP to see calculation';
            finalMRPText.style.color = '#6c757d';
        }
    }
    
    calculateStockPrice();
}

// Clear manual stock price entry
function clearManualStockPrice() {
    manualStockPrice = false;
    stockPriceCalculationMode = 'auto';
    document.getElementById('stockPrice').value = '';
    document.getElementById('stockPriceText').innerHTML = 'Will be calculated from Final MRP & Discount';
    document.getElementById('stockPriceText').style.color = '#6c757d';
    calculateStockPrice();
}

// Clear manual retail price entry
function clearManualRetailPrice() {
    manualRetailPrice = false;
    retailPriceCalculationMode = 'auto';
    document.getElementById('retailPrice').value = '';
    calculateRetailPrice();
}

// Clear manual wholesale price entry
function clearManualWholesalePrice() {
    manualWholesalePrice = false;
    wholesalePriceCalculationMode = 'auto';
    document.getElementById('wholesalePrice').value = '';
    calculateWholesalePrice();
}

// Calculate Stock Price from Final MRP and discount
function calculateStockPrice() {
    const mrp = parseFloat(document.getElementById('mrp').value) || 0;
    const discountInput = document.getElementById('discount').value.trim();
    const stockPriceInput = document.getElementById('stockPrice');
    const youSaveInput = document.getElementById('youSave');
    const discountPercentageText = document.getElementById('discountPercentageText');
    const stockPriceText = document.getElementById('stockPriceText');
    
    let discountValue = 0;
    let discountAmount = 0;
    let calculatedStockPrice = 0;
    
    if (mrp > 0 && !manualStockPrice) {
        if (discountInput) {
            if (discountSymbol === '%') {
                discountValue = parseFloat(discountInput.replace('%', '')) || 0;
                
                if (discountValue > 100) {
                    alert('Discount percentage cannot exceed 100%');
                    discountValue = 100;
                    document.getElementById('discount').value = '100%';
                }
                
                discountAmount = mrp * discountValue / 100;
                calculatedStockPrice = mrp - discountAmount;
            } else {
                discountValue = parseFloat(discountInput) || 0;
                
                if (discountValue > mrp) {
                    alert('Discount amount cannot exceed MRP');
                    discountValue = mrp;
                    document.getElementById('discount').value = mrp.toFixed(2);
                }
                
                discountAmount = discountValue;
                calculatedStockPrice = mrp - discountValue;
            }
        } else {
            calculatedStockPrice = mrp;
        }
        
        if (calculatedStockPrice < 0) calculatedStockPrice = 0;
        
        stockPriceInput.value = calculatedStockPrice.toFixed(2);
        
        if (discountAmount > 0) {
            stockPriceText.innerHTML = `Calculated from Final MRP (₹${mrp.toFixed(2)}) - Discount`;
            stockPriceText.style.color = '#198754';
        } else {
            stockPriceText.innerHTML = `Same as Final MRP (no discount)`;
            stockPriceText.style.color = '#6c757d';
        }
        
        stockPriceCalculationMode = 'auto';
    } else if (mrp > 0 && manualStockPrice) {
        const currentStockPrice = parseFloat(stockPriceInput.value) || 0;
        discountAmount = mrp - currentStockPrice;
        
        if (discountAmount > 0) {
            if (discountSymbol === '%') {
                discountValue = (discountAmount / mrp) * 100;
                document.getElementById('discount').value = discountValue.toFixed(2) + '%';
            } else {
                document.getElementById('discount').value = discountAmount.toFixed(2);
            }
        } else {
            document.getElementById('discount').value = '';
        }
        
        stockPriceText.innerHTML = `Manually entered - Discount calculated`;
        stockPriceText.style.color = '#0d6efd';
    }
    
    youSaveInput.value = discountAmount.toFixed(2);
    
    if (mrp > 0 && discountAmount > 0) {
        const discountPercentage = (discountAmount / mrp) * 100;
        discountPercentageText.innerHTML = `Discount: ${discountPercentage.toFixed(1)}%`;
        discountPercentageText.style.color = '#198754';
    } else {
        discountPercentageText.innerHTML = `Discount: 0%`;
        discountPercentageText.style.color = '#6c757d';
    }
    
    calculateRetailPrice();
    calculateWholesalePrice();
    calculateProfitMargins();
    calculateSecondaryPrices();
    validatePriceHierarchy();
}

// Calculate Retail Price based on Stock Price and Retail Markup
function calculateRetailPrice() {
    const stockPrice = parseFloat(document.getElementById('stockPrice').value) || 0;
    const retailPriceType = document.getElementById('retailPriceType').value;
    const retailPriceValue = parseFloat(document.getElementById('retailPriceValue').value) || 0;
    const retailPriceInput = document.getElementById('retailPrice');
    const retailMarkupText = document.getElementById('retailMarkupText');
    const retailPriceText = document.getElementById('retailPriceText');
    
    let markupAmount = 0;
    let calculatedRetailPrice = stockPrice;
    
    if (stockPrice > 0 && !manualRetailPrice) {
        if (retailPriceValue > 0) {
            if (retailPriceType === 'percentage') {
                markupAmount = stockPrice * retailPriceValue / 100;
                calculatedRetailPrice = stockPrice + markupAmount;
                retailMarkupText.innerHTML = `Markup: ₹${markupAmount.toFixed(2)} (${retailPriceValue}%)`;
            } else {
                markupAmount = retailPriceValue;
                calculatedRetailPrice = stockPrice + retailPriceValue;
                retailMarkupText.innerHTML = `Markup: ₹${markupAmount.toFixed(2)} (fixed)`;
            }
            
            retailPriceInput.value = calculatedRetailPrice.toFixed(2);
            retailPriceText.innerHTML = `Based on stock price + markup`;
            retailPriceText.style.color = '#198754';
            retailPriceCalculationMode = 'auto';
        } else {
            retailPriceInput.value = stockPrice.toFixed(2);
            retailMarkupText.innerHTML = `Markup: ₹0.00`;
            retailPriceText.innerHTML = `Same as stock price (no markup)`;
            retailPriceText.style.color = '#6c757d';
        }
    } else if (stockPrice > 0 && manualRetailPrice) {
        const currentRetailPrice = parseFloat(retailPriceInput.value) || 0;
        
        if (currentRetailPrice > stockPrice) {
            markupAmount = currentRetailPrice - stockPrice;
            
            if (retailPriceType === 'percentage') {
                const calculatedPercentage = (markupAmount / stockPrice) * 100;
                document.getElementById('retailPriceValue').value = calculatedPercentage.toFixed(2);
                retailMarkupText.innerHTML = `Markup: ₹${markupAmount.toFixed(2)} (${calculatedPercentage.toFixed(2)}%)`;
            } else {
                document.getElementById('retailPriceValue').value = markupAmount.toFixed(2);
                retailMarkupText.innerHTML = `Markup: ₹${markupAmount.toFixed(2)} (fixed)`;
            }
        } else {
            document.getElementById('retailPriceValue').value = '0';
            retailMarkupText.innerHTML = `Markup: ₹0.00`;
        }
        
        retailPriceText.innerHTML = `Manually entered - Markup calculated`;
        retailPriceText.style.color = '#0d6efd';
    }
    
    calculateProfitMargins();
    calculateSecondaryPrices();
    validatePriceHierarchy();
}

// Calculate Wholesale Price based on Stock Price and Wholesale Markup
function calculateWholesalePrice() {
    const stockPrice = parseFloat(document.getElementById('stockPrice').value) || 0;
    const wholesalePriceType = document.getElementById('wholesalePriceType').value;
    const wholesalePriceValue = parseFloat(document.getElementById('wholesalePriceValue').value) || 0;
    const wholesalePriceInput = document.getElementById('wholesalePrice');
    const wholesaleMarkupText = document.getElementById('wholesaleMarkupText');
    const wholesalePriceText = document.getElementById('wholesalePriceText');
    
    let markupAmount = 0;
    let calculatedWholesalePrice = stockPrice;
    
    if (stockPrice > 0 && !manualWholesalePrice) {
        if (wholesalePriceValue > 0) {
            if (wholesalePriceType === 'percentage') {
                markupAmount = stockPrice * wholesalePriceValue / 100;
                calculatedWholesalePrice = stockPrice + markupAmount;
                wholesaleMarkupText.innerHTML = `Markup: ₹${markupAmount.toFixed(2)} (${wholesalePriceValue.toFixed(2)}%)`;
            } else {
                markupAmount = wholesalePriceValue;
                calculatedWholesalePrice = stockPrice + wholesalePriceValue;
                wholesaleMarkupText.innerHTML = `Markup: ₹${markupAmount.toFixed(2)} (fixed)`;
            }
            
            wholesalePriceInput.value = calculatedWholesalePrice.toFixed(2);
            wholesalePriceText.innerHTML = `Based on stock price + markup`;
            wholesalePriceText.style.color = '#0d6efd';
            wholesalePriceCalculationMode = 'auto';
        } else {
            wholesalePriceInput.value = stockPrice.toFixed(2);
            wholesaleMarkupText.innerHTML = `Markup: ₹0.00 (0%)`;
            wholesalePriceText.innerHTML = `Same as stock price (no markup)`;
            wholesalePriceText.style.color = '#6c757d';
        }
    } else if (stockPrice > 0 && manualWholesalePrice) {
        const currentWholesalePrice = parseFloat(wholesalePriceInput.value) || 0;
        
        if (currentWholesalePrice > stockPrice) {
            markupAmount = currentWholesalePrice - stockPrice;
            
            if (wholesalePriceType === 'percentage') {
                const calculatedPercentage = (markupAmount / stockPrice) * 100;
                document.getElementById('wholesalePriceValue').value = calculatedPercentage.toFixed(2);
                wholesaleMarkupText.innerHTML = `Markup: ₹${markupAmount.toFixed(2)} (${calculatedPercentage.toFixed(2)}%)`;
            } else {
                document.getElementById('wholesalePriceValue').value = markupAmount.toFixed(2);
                wholesaleMarkupText.innerHTML = `Markup: ₹${markupAmount.toFixed(2)} (fixed)`;
            }
        } else if (currentWholesalePrice === stockPrice) {
            document.getElementById('wholesalePriceValue').value = '0';
            wholesaleMarkupText.innerHTML = `Markup: ₹0.00 (0%)`;
        } else {
            document.getElementById('wholesalePriceValue').value = '0';
            wholesaleMarkupText.innerHTML = `Markup: ₹0.00 (0%)`;
            wholesalePriceInput.value = stockPrice.toFixed(2);
            manualWholesalePrice = false;
        }
        
        wholesalePriceText.innerHTML = `Manually entered - Markup calculated`;
        wholesalePriceText.style.color = '#0d6efd';
    }
    
    calculateProfitMargins();
    calculateSecondaryPrices();
    validatePriceHierarchy();
}

// Calculate Profit Margins
function calculateProfitMargins() {
    const stockPrice = parseFloat(document.getElementById('stockPrice').value) || 0;
    const retailPrice = parseFloat(document.getElementById('retailPrice').value) || 0;
    const wholesalePrice = parseFloat(document.getElementById('wholesalePrice').value) || 0;
    const retailProfitMarginInput = document.getElementById('retailProfitMargin');
    const retailProfitAmountText = document.getElementById('retailProfitAmountText');
    const wholesaleProfitMarginInput = document.getElementById('wholesaleProfitMargin');
    const wholesaleProfitAmountText = document.getElementById('wholesaleProfitAmountText');
    
    if (stockPrice > 0 && retailPrice > 0) {
        const retailProfit = retailPrice - stockPrice;
        const retailProfitMargin = retailPrice > 0 ? (retailProfit / retailPrice) * 100 : 0;
        
        retailProfitMarginInput.value = retailProfitMargin.toFixed(2);
        retailProfitAmountText.innerHTML = `Profit: ₹${retailProfit.toFixed(2)}`;
        
        if (retailProfitMargin > 20) {
            retailProfitMarginInput.style.color = '#198754';
            retailProfitAmountText.style.color = '#198754';
        } else if (retailProfitMargin > 10) {
            retailProfitMarginInput.style.color = '#fd7e14';
            retailProfitAmountText.style.color = '#fd7e14';
        } else if (retailProfitMargin > 0) {
            retailProfitMarginInput.style.color = '#0d6efd';
            retailProfitAmountText.style.color = '#0d6efd';
        } else {
            retailProfitMarginInput.style.color = '#dc3545';
            retailProfitAmountText.style.color = '#dc3545';
        }
    } else {
        retailProfitMarginInput.value = '';
        retailProfitAmountText.innerHTML = 'Profit: ₹0.00';
    }
    
    if (stockPrice > 0 && wholesalePrice > 0) {
        const wholesaleProfit = wholesalePrice - stockPrice;
        const wholesaleProfitMargin = wholesalePrice > 0 ? (wholesaleProfit / wholesalePrice) * 100 : 0;
        
        wholesaleProfitMarginInput.value = wholesaleProfitMargin.toFixed(2);
        wholesaleProfitAmountText.innerHTML = `Profit: ₹${wholesaleProfit.toFixed(2)}`;
        
        if (wholesaleProfitMargin > 15) {
            wholesaleProfitMarginInput.style.color = '#198754';
            wholesaleProfitAmountText.style.color = '#198754';
        } else if (wholesaleProfitMargin > 5) {
            wholesaleProfitMarginInput.style.color = '#fd7e14';
            wholesaleProfitAmountText.style.color = '#fd7e14';
        } else if (wholesaleProfitMargin > 0) {
            wholesaleProfitMarginInput.style.color = '#0d6efd';
            wholesaleProfitAmountText.style.color = '#0d6efd';
        } else {
            wholesaleProfitMarginInput.style.color = '#dc3545';
            wholesaleProfitAmountText.style.color = '#dc3545';
        }
    } else {
        wholesaleProfitMarginInput.value = '';
        wholesaleProfitAmountText.innerHTML = 'Profit: ₹0.00';
    }
}

// Calculate Secondary Unit Prices
function calculateSecondaryPrices() {
    const secondaryUnit = document.querySelector('input[name="secondary_unit"]').value.trim();
    const conversion = parseFloat(document.getElementById('secUnitConversion').value) || 0;
    const extraType = document.getElementById('secUnitPriceType').value;
    const extraCharge = parseFloat(document.getElementById('secUnitExtraCharge').value) || 0;
    const retailPrice = parseFloat(document.getElementById('retailPrice').value) || 0;
    const wholesalePrice = parseFloat(document.getElementById('wholesalePrice').value) || 0;

    const previewBox = document.getElementById('secondaryPricePreview');
    const secondaryUnitLabel = document.getElementById('secondaryUnitLabel');
    const extraChargeHelp = document.getElementById('extraChargeHelp');

    secondaryUnitLabel.textContent = secondaryUnit || 'units';
    extraChargeHelp.innerHTML = extraType === 'fixed' 
        ? `Extra charge per ${secondaryUnit || 'secondary unit'}` 
        : `Extra charge percentage per ${secondaryUnit || 'secondary unit'}`;

    if (secondaryUnit && conversion > 0 && conversion < 1000000) {
        previewBox.style.display = 'block';

        let retailBasePricePerUnit = retailPrice / conversion;
        let wholesaleBasePricePerUnit = wholesalePrice / conversion;

        let retailExtraPerUnit = 0;
        let wholesaleExtraPerUnit = 0;

        if (extraType === 'fixed') {
            retailExtraPerUnit = extraCharge;
            wholesaleExtraPerUnit = extraCharge;
        } else {
            retailExtraPerUnit = retailBasePricePerUnit * (extraCharge / 100);
            wholesaleExtraPerUnit = wholesaleBasePricePerUnit * (extraCharge / 100);
        }

        let retailPerUnit = retailBasePricePerUnit + retailExtraPerUnit;
        let wholesalePerUnit = wholesaleBasePricePerUnit + wholesaleExtraPerUnit;

        document.getElementById('secRetailPricePerUnit').textContent = `₹${retailPerUnit.toFixed(2)}`;
        document.getElementById('secRetailBasePrice').textContent = `₹${retailBasePricePerUnit.toFixed(2)}`;
        document.getElementById('secRetailExtraCharge').textContent = extraType === 'fixed' 
            ? `₹${retailExtraPerUnit.toFixed(2)} (fixed)` 
            : `₹${retailExtraPerUnit.toFixed(2)} (${extraCharge}%)`;

        document.getElementById('secWholesalePricePerUnit').textContent = `₹${wholesalePerUnit.toFixed(2)}`;
        document.getElementById('secWholesaleBasePrice').textContent = `₹${wholesaleBasePricePerUnit.toFixed(2)}`;
        document.getElementById('secWholesaleExtraCharge').textContent = extraType === 'fixed' 
            ? `₹${wholesaleExtraPerUnit.toFixed(2)} (fixed)` 
            : `₹${wholesaleExtraPerUnit.toFixed(2)} (${extraCharge}%)`;

        if (conversion === 0) {
            alert('Conversion rate cannot be 0');
            document.getElementById('secUnitConversion').value = '';
            previewBox.style.display = 'none';
        }
        
        if (conversion > 10000) {
            document.getElementById('secUnitConversion').style.borderColor = '#ffc107';
        } else {
            document.getElementById('secUnitConversion').style.borderColor = '';
        }
    } else {
        previewBox.style.display = 'none';
    }
}

// Validate price hierarchy
function validatePriceHierarchy() {
    const stockPrice = parseFloat(document.getElementById('stockPrice').value) || 0;
    const wholesalePrice = parseFloat(document.getElementById('wholesalePrice').value) || 0;
    const retailPrice = parseFloat(document.getElementById('retailPrice').value) || 0;
    const mrp = parseFloat(document.getElementById('mrp').value) || 0;
    
    document.getElementById('stockPriceText').style.color = '';
    document.getElementById('wholesalePriceText').style.color = '';
    document.getElementById('retailPriceText').style.color = '';
    
    if (stockPrice > 0 && wholesalePrice > 0 && retailPrice > 0) {
        if (wholesalePrice < stockPrice) {
            document.getElementById('wholesalePriceText').innerHTML = '<span class="text-danger">Error: Must be ≥ Stock Price</span>';
            document.getElementById('wholesalePriceText').style.color = '#dc3545';
        }
        
        if (retailPrice <= stockPrice) {
            document.getElementById('retailPriceText').innerHTML = '<span class="text-danger">Error: Must be > Stock Price</span>';
            document.getElementById('retailPriceText').style.color = '#dc3545';
        }
        
        if (wholesalePrice > retailPrice) {
            document.getElementById('wholesalePriceText').innerHTML = '<span class="text-danger">Error: Must be ≤ Retail Price</span>';
            document.getElementById('wholesalePriceText').style.color = '#dc3545';
        }
        
        if (mrp > 0) {
            if (retailPrice > mrp) {
                document.getElementById('retailPriceText').innerHTML = '<span class="text-danger">Error: Must be ≤ MRP</span>';
                document.getElementById('retailPriceText').style.color = '#dc3545';
            }
            
            if (wholesalePrice > mrp) {
                document.getElementById('wholesalePriceText').innerHTML = '<span class="text-danger">Error: Must be ≤ MRP</span>';
                document.getElementById('wholesalePriceText').style.color = '#dc3545';
            }
            
            if (stockPrice > mrp) {
                document.getElementById('stockPriceText').innerHTML = '<span class="text-danger">Error: Must be ≤ MRP</span>';
                document.getElementById('stockPriceText').style.color = '#dc3545';
            }
        }
    }
}

// Update retail price unit display
function updateRetailPriceUnit() {
    const retailPriceType = document.getElementById('retailPriceType').value;
    document.getElementById('retailPriceUnit').textContent = retailPriceType === 'percentage' ? '%' : '₹';
    calculateRetailPrice();
}

// Update wholesale price unit display
function updateWholesalePriceUnit() {
    const wholesalePriceType = document.getElementById('wholesalePriceType').value;
    document.getElementById('wholesalePriceUnit').textContent = wholesalePriceType === 'percentage' ? '%' : '₹';
    calculateWholesalePrice();
}

// Update secondary unit extra charge unit
function updateSecUnitExtraUnit() {
    const extraType = document.getElementById('secUnitPriceType').value;
    document.getElementById('secUnitExtraUnit').textContent = extraType === 'percentage' ? '%' : '₹';
    calculateSecondaryPrices();
}

// Detect manual entry of prices
document.getElementById('stockPrice').addEventListener('input', function() {
    const value = parseFloat(this.value) || 0;
    if (value > 0) {
        manualStockPrice = true;
        stockPriceCalculationMode = 'manual';
        document.getElementById('stockPriceText').innerHTML = 'Manually entered - Press refresh to auto-calculate';
        document.getElementById('stockPriceText').style.color = '#0d6efd';
        
        calculateStockPrice();
    }
    calculateRetailPrice();
    calculateWholesalePrice();
    calculateProfitMargins();
    calculateSecondaryPrices();
    validatePriceHierarchy();
});

document.getElementById('retailPrice').addEventListener('input', function() {
    const value = parseFloat(this.value) || 0;
    if (value > 0) {
        manualRetailPrice = true;
        retailPriceCalculationMode = 'manual';
        document.getElementById('retailPriceText').innerHTML = 'Manually entered - Press refresh to auto-calculate';
        document.getElementById('retailPriceText').style.color = '#0d6efd';
        
        calculateRetailPrice();
    }
    calculateProfitMargins();
    calculateSecondaryPrices();
    validatePriceHierarchy();
});

document.getElementById('wholesalePrice').addEventListener('input', function() {
    const value = parseFloat(this.value) || 0;

    if (value > 0) {
        manualWholesalePrice = true;
        wholesalePriceCalculationMode = 'manual';

        document.getElementById('wholesalePriceText').innerHTML =
            'Manually entered - Press refresh to auto-calculate';
        document.getElementById('wholesalePriceText').style.color = '#0d6efd';
    }

    // ❌ DO NOT recalculate wholesale price here

    calculateProfitMargins();
    calculateSecondaryPrices();
    validatePriceHierarchy();
});
// Form validation
document.getElementById('editProductForm').addEventListener('submit', function(e) {
    const stockPrice = parseFloat(document.getElementById('stockPrice').value) || 0;
    const wholesalePrice = parseFloat(document.getElementById('wholesalePrice').value) || 0;
    const retailPrice = parseFloat(document.getElementById('retailPrice').value) || 0;
    const mrp = parseFloat(document.getElementById('mrp').value) || 0;
    const mrpInput = parseFloat(document.getElementById('mrpInput').value) || 0;
    const discountInput = document.getElementById('discount').value.trim();
    const secondaryUnit = document.querySelector('input[name="secondary_unit"]').value.trim();
    const conversion = parseFloat(document.getElementById('secUnitConversion').value) || 0;
    const gstSelect = document.getElementById('gstSelect');
    const gstToggle = document.getElementById('gstTypeToggle');
    const gstType = gstToggle.checked ? 'exclusive' : 'inclusive';
    
    let errors = [];
    
    if (mrpInput <= 0) {
        errors.push('MRP is required and must be greater than 0.');
    }
    
    if (stockPrice <= 0) {
        errors.push('Stock price is required and must be greater than 0.');
    }
    
    if (wholesalePrice <= 0) {
        errors.push('Wholesale price is required and must be greater than 0.');
    }
    
    if (retailPrice <= 0) {
        errors.push('Retail price is required and must be greater than 0.');
    }
    
    if (gstType === 'exclusive' && gstSelect.value === '') {
        errors.push('Please select GST rate when product is GST Exclusive.');
    }
    
    if (stockPrice > 0 && wholesalePrice > 0 && retailPrice > 0) {
        if (wholesalePrice < stockPrice) {
            errors.push('Wholesale price must be equal to or greater than Stock Price.');
        }
        
        if (retailPrice <= stockPrice) {
            errors.push('Retail price must be greater than Stock Price.');
        }
        
        if (wholesalePrice > retailPrice) {
            errors.push('Wholesale price should be less than or equal to Retail Price.');
        }
        
        if (mrp > 0) {
            if (retailPrice > mrp) {
                errors.push('Retail price cannot be higher than MRP.');
            }
            
            if (wholesalePrice > mrp) {
                errors.push('Wholesale price cannot be higher than MRP.');
            }
            
            if (stockPrice > mrp) {
                errors.push('Stock price cannot be higher than MRP.');
            }
        }
    }
    
    if (mrp > 0 && discountInput) {
        if (discountSymbol === '%') {
            const discountValue = parseFloat(discountInput.replace('%', '')) || 0;
            if (discountValue > 100) {
                errors.push('Discount percentage cannot exceed 100%.');
            }
        } else {
            const discountValue = parseFloat(discountInput) || 0;
            if (discountValue > mrp) {
                errors.push('Discount amount cannot exceed MRP.');
            }
        }
    }
    
    if (secondaryUnit && conversion <= 0) {
        errors.push('If secondary unit is specified, conversion rate must be greater than 0.');
    }
    
    if (!secondaryUnit && conversion > 0) {
        errors.push('Please specify a secondary unit name if entering conversion rate.');
    }
    
    if (conversion > 0) {
        if (conversion > 1000000) {
            errors.push('Conversion rate is too high. Please use a reasonable value.');
        }
        if (conversion < 0.0001) {
            errors.push('Conversion rate is too small. Please use a reasonable value.');
        }
    }
    
    if (errors.length > 0) {
        e.preventDefault();
        alert('Please fix the following errors:\n\n' + errors.join('\n'));
        return;
    }
    
    const fileInput = document.getElementById('productImage');
    if (fileInput.files.length > 0) {
        const fileSize = fileInput.files[0].size;
        const maxSize = 2 * 1024 * 1024;
        if (fileSize > maxSize) {
            e.preventDefault();
            alert('File size exceeds 2MB limit. Please choose a smaller image.');
            fileInput.focus();
        }
    }
});

// Generate random barcode
function generateBarcode() {
    const prefix = '89';
    const random = Math.floor(Math.random() * 10000000000).toString().padStart(10, '0');
    const barcode = prefix + random;
    document.querySelector('input[name="barcode"]').value = barcode;
}

// Load subcategories when category changes
document.getElementById('categorySelect').addEventListener('change', function() {
    const categoryId = this.value;
    const subcategorySelect = document.getElementById('subcategorySelect');
    const loadingDiv = document.getElementById('subcategoryLoading');
    
    if (!categoryId) {
        subcategorySelect.innerHTML = '<option value="">-- Select Subcategory --</option>';
        return;
    }
    
    loadingDiv.style.display = 'block';
    subcategorySelect.disabled = true;
    
    fetch(`ajax/get_subcategories.php?category_id=${categoryId}&business_id=<?= $current_business_id ?>`)
        .then(response => response.json())
        .then(data => {
            subcategorySelect.innerHTML = '<option value="">-- Select Subcategory --</option>';
            
            if (data.success && data.subcategories.length > 0) {
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
            loadingDiv.style.display = 'none';
            subcategorySelect.disabled = false;
        });
});

// Referral commission toggle
const referralToggle = document.getElementById('referralEnabled');
const referralBox = document.getElementById('referralBox');
const commissionUnit = document.getElementById('commissionUnit');
const referralTypeSelect = document.querySelector('select[name="referral_type"]');

function toggleReferralBox() {
    referralBox.style.display = referralToggle.checked ? 'block' : 'none';
}

function updateCommissionUnit() {
    commissionUnit.textContent = referralTypeSelect.value === 'percentage' ? '%' : '₹';
}

referralToggle.addEventListener('change', toggleReferralBox);
referralTypeSelect.addEventListener('change', updateCommissionUnit);

// Image preview
document.getElementById('productImage').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('imagePreview');
    
    if (file) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.innerHTML = `
                <img src="${e.target.result}" class="img-fluid rounded" style="max-height: 200px; object-fit: contain;">
                <p class="mt-2 mb-0"><small>${file.name} (${(file.size / 1024).toFixed(1)} KB) - New image</small></p>
            `;
        };
        
        reader.readAsDataURL(file);
    }
});

// Discount input handling
document.getElementById('discount').addEventListener('input', function(e) {
    let value = this.value.trim();
    
    if (value.includes('%')) {
        discountSymbol = '%';
        document.getElementById('discountSymbol').textContent = '%';
        value = value.replace(/[%]/g, '');
        this.value = value + '%';
    }
    
    if (manualStockPrice) {
        manualStockPrice = false;
        stockPriceCalculationMode = 'auto';
    }
    
    calculateStockPrice();
});

// Event listeners
document.getElementById('mrpInput').addEventListener('input', function() {
    if (manualStockPrice) {
        manualStockPrice = false;
        stockPriceCalculationMode = 'auto';
    }
    calculateGST();
});

document.getElementById('gstSelect').addEventListener('change', function() {
    if (manualStockPrice) {
        manualStockPrice = false;
        stockPriceCalculationMode = 'auto';
    }
    calculateGST();
});

document.getElementById('retailPriceValue').addEventListener('input', function() {
    if (manualRetailPrice) {
        manualRetailPrice = false;
        retailPriceCalculationMode = 'auto';
    }
    calculateRetailPrice();
});

document.getElementById('retailPriceType').addEventListener('change', function() {
    if (manualRetailPrice) {
        manualRetailPrice = false;
        retailPriceCalculationMode = 'auto';
    }
    updateRetailPriceUnit();
});

document.getElementById('wholesalePriceValue').addEventListener('input', function() {
    if (manualWholesalePrice) {
        manualWholesalePrice = false;
        wholesalePriceCalculationMode = 'auto';
    }
    calculateWholesalePrice();
});

document.getElementById('wholesalePriceType').addEventListener('change', function() {
    if (manualWholesalePrice) {
        manualWholesalePrice = false;
        wholesalePriceCalculationMode = 'auto';
    }
    updateWholesalePriceUnit();
});

document.getElementById('secUnitPriceType').addEventListener('change', updateSecUnitExtraUnit);
document.getElementById('secUnitConversion').addEventListener('input', calculateSecondaryPrices);
document.getElementById('secUnitExtraCharge').addEventListener('input', calculateSecondaryPrices);
document.querySelector('input[name="secondary_unit"]').addEventListener('input', function() {
    calculateSecondaryPrices();
    document.getElementById('secondaryUnitLabel').textContent = this.value || 'units';
});

// Quick add category
function quickAddCategory() {
    const categoryName = prompt('Enter new category name:');
    if (categoryName && categoryName.trim()) {
        fetch('ajax/quick_add_category.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `category_name=${encodeURIComponent(categoryName.trim())}&business_id=<?= $current_business_id ?>`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const categorySelect = document.getElementById('categorySelect');
                const newOption = document.createElement('option');
                newOption.value = data.category_id;
                newOption.textContent = data.category_name;
                categorySelect.appendChild(newOption);
                categorySelect.value = data.category_id;
                alert('Category added successfully!');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to add category');
        });
    }
}

// Quick add subcategory
function quickAddSubcategory() {
    const categorySelect = document.getElementById('categorySelect');
    if (!categorySelect.value) {
        alert('Please select a category first');
        return;
    }
    
    const subcategoryName = prompt('Enter new subcategory name:');
    if (subcategoryName && subcategoryName.trim()) {
        fetch('ajax/quick_add_subcategory.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `subcategory_name=${encodeURIComponent(subcategoryName.trim())}&category_id=${categorySelect.value}&business_id=<?= $current_business_id ?>`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const subcategorySelect = document.getElementById('subcategorySelect');
                const newOption = document.createElement('option');
                newOption.value = data.subcategory_id;
                newOption.textContent = data.subcategory_name;
                subcategorySelect.appendChild(newOption);
                subcategorySelect.value = data.subcategory_id;
                alert('Subcategory added successfully!');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to add subcategory');
        });
    }
}
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
#stockPriceText, #retailMarkupText, #retailPriceText, 
#retailProfitAmountText, #wholesaleMarkupText, #wholesalePriceText, #wholesaleProfitAmountText {
    font-size: 0.875rem;
    font-weight: 500;
}
#stockPriceText {
    color: #0d6efd;
}
#retailMarkupText {
    color: #fd7e14;
}
#retailPriceText {
    color: #6f42c1;
}
#retailProfitAmountText {
    color: #20c997;
}
#wholesaleMarkupText {
    color: #0dcaf0;
}
#wholesalePriceText {
    color: #6610f2;
}
#wholesaleProfitAmountText {
    color: #17a2b8;
}
#discountPercentageText {
    color: #198754;
    font-weight: 500;
}
.border-bottom {
    border-color: #dee2e6 !important;
}
#youSave, #retailProfitMargin, #wholesaleProfitMargin {
    background-color: #f8f9fa;
    cursor: not-allowed;
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
#secRetailPricePerUnit, #secWholesalePricePerUnit {
    color: #198754;
    font-size: 1.1rem;
}
#gstCalculationPreview .alert-info {
    background-color: #f0f9ff;
    border: 1px solid #b6e0fe;
}
#gstCalculationPreview h6 {
    color: #0d6efd;
    font-size: 0.9rem;
}
#finalMRP {
    color: #198754;
    font-size: 1.2rem;
    font-weight: 600;
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
.bg-light {
    background-color: #f8f9fa !important;
}
</style>
</body>
</html>