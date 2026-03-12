<?php
// ajax/quick_add_product.php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check authorization
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$current_business_id = $_SESSION['current_business_id'] ?? null;

if (!$current_business_id) {
    echo json_encode(['success' => false, 'message' => 'No business selected']);
    exit();
}

// Get POST data
$category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
$subcategory_id = isset($_POST['subcategory_id']) && $_POST['subcategory_id'] ? (int)$_POST['subcategory_id'] : null;
$product_name = trim($_POST['product_name'] ?? '');
$product_code = trim($_POST['product_code'] ?? '');
$barcode = !empty($_POST['barcode']) ? trim($_POST['barcode']) : null;
$unit = $_POST['unit'] ?? 'pcs';
$secondary_unit = !empty($_POST['secondary_unit']) ? trim($_POST['secondary_unit']) : null;
$sec_unit_conversion = !empty($_POST['sec_unit_conversion']) ? (float)$_POST['sec_unit_conversion'] : null;
$mrp = (float)($_POST['mrp'] ?? 0);
$stock_price = (float)($_POST['stock_price'] ?? 0);
$gst_id = !empty($_POST['gst_id']) ? (int)$_POST['gst_id'] : null;
$hsn_code = !empty($_POST['hsn_code']) ? trim($_POST['hsn_code']) : null;
$retail_price_type = $_POST['retail_price_type'] ?? 'percentage';
$retail_price_value = (float)($_POST['retail_price_value'] ?? 0);
$wholesale_price_type = $_POST['wholesale_price_type'] ?? 'percentage';
$wholesale_price_value = (float)($_POST['wholesale_price_value'] ?? 0);
$min_stock_level = (int)($_POST['min_stock_level'] ?? 10);
$description = !empty($_POST['description']) ? trim($_POST['description']) : null;
$cgst_rate = (float)($_POST['cgst_rate'] ?? 0);
$sgst_rate = (float)($_POST['sgst_rate'] ?? 0);
$igst_rate = (float)($_POST['igst_rate'] ?? 0);

// Calculate retail and wholesale prices based on markup
$retail_price = $stock_price;
$wholesale_price = $stock_price;

if ($retail_price_value > 0) {
    if ($retail_price_type === 'percentage') {
        $retail_price = $stock_price + ($stock_price * $retail_price_value / 100);
    } else {
        $retail_price = $stock_price + $retail_price_value;
    }
}

if ($wholesale_price_value > 0) {
    if ($wholesale_price_type === 'percentage') {
        $wholesale_price = $stock_price + ($stock_price * $wholesale_price_value / 100);
    } else {
        $wholesale_price = $stock_price + $wholesale_price_value;
    }
}

// Validate required fields
if (!$category_id || !$product_name || $mrp <= 0 || $stock_price <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

try {
    // Check if product code already exists
    if (!empty($product_code)) {
        $check_stmt = $pdo->prepare("SELECT id FROM products WHERE product_code = ? AND business_id = ?");
        $check_stmt->execute([$product_code, $current_business_id]);
        if ($check_stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Product code already exists']);
            exit();
        }
    }
    
    // Check if barcode already exists
    if (!empty($barcode)) {
        $check_stmt = $pdo->prepare("SELECT id FROM products WHERE barcode = ? AND business_id = ?");
        $check_stmt->execute([$barcode, $current_business_id]);
        if ($check_stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Barcode already exists']);
            exit();
        }
    }
    
    // Generate product code if empty
    if (empty($product_code)) {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $product_name), 0, 3));
        $counter = 1;
        while (true) {
            $product_code = $prefix . str_pad($counter, 4, '0', STR_PAD_LEFT);
            $check_stmt = $pdo->prepare("SELECT id FROM products WHERE product_code = ? AND business_id = ?");
            $check_stmt->execute([$product_code, $current_business_id]);
            if (!$check_stmt->fetch()) break;
            $counter++;
        }
    }
    
    // Insert product
    $stmt = $pdo->prepare("
        INSERT INTO products (
            business_id, product_name, product_code, barcode,
            category_id, subcategory_id, description, unit_of_measure,
            secondary_unit, sec_unit_conversion,
            stock_price, retail_price, wholesale_price,
            mrp, retail_price_type, retail_price_value,
            wholesale_price_type, wholesale_price_value,
            min_stock_level, gst_id, hsn_code,
            gst_type, gst_amount,
            referral_enabled, referral_type, referral_value,
            created_by, created_at, is_active
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            'inclusive', 0,
            0, 'percentage', 0,
            ?, NOW(), 1
        )
    ");
    
    $stmt->execute([
        $current_business_id,
        $product_name,
        $product_code,
        $barcode,
        $category_id,
        $subcategory_id,
        $description,
        $unit,
        $secondary_unit,
        $sec_unit_conversion,
        $stock_price,
        $retail_price,
        $wholesale_price,
        $mrp,
        $retail_price_type,
        $retail_price_value,
        $wholesale_price_type,
        $wholesale_price_value,
        $min_stock_level,
        $gst_id,
        $hsn_code,
        $user_id
    ]);
    
    $product_id = $pdo->lastInsertId();
    
    // Calculate markup percentages
    $retail_markup_percent = 0;
    $wholesale_markup_percent = 0;
    
    if ($stock_price > 0) {
        if ($retail_price > $stock_price) {
            $retail_markup_percent = (($retail_price - $stock_price) / $stock_price) * 100;
        }
        if ($wholesale_price > $stock_price) {
            $wholesale_markup_percent = (($wholesale_price - $stock_price) / $stock_price) * 100;
        }
    }
    
    // Return product data for dropdown
    echo json_encode([
        'success' => true,
        'product' => [
            'id' => $product_id,
            'name' => $product_name,
            'code' => $product_code,
            'barcode' => $barcode,
            'mrp' => $mrp,
            'stock_price' => $stock_price,
            'retail_price' => $retail_price,
            'wholesale_price' => $wholesale_price,
            'retail_markup_percent' => $retail_markup_percent,
            'wholesale_markup_percent' => $wholesale_markup_percent,
            'secondary_unit' => $secondary_unit,
            'sec_unit_conversion' => $sec_unit_conversion,
            'hsn' => $hsn_code,
            'cgst' => $cgst_rate,
            'sgst' => $sgst_rate,
            'igst' => $igst_rate,
            'total_gst' => $cgst_rate + $sgst_rate + $igst_rate,
            'shop_stock' => 0,
            'warehouse_stock' => 0,
            'total_stock' => 0,
            'last_batch_price' => null
        ]
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>