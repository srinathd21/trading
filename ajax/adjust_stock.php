<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$current_business_id = (int) $_SESSION['current_business_id'];
$current_shop_id = (int) ($_SESSION['current_shop_id'] ?? 0);
$user_id = (int) $_SESSION['user_id'];

// Check if user has permission
$allowed_roles = ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$required = ['product_id', 'type', 'quantity'];
foreach ($required as $field) {
    if (!isset($_POST[$field]) || empty($_POST[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$product_id = (int) $_POST['product_id'];
$type = $_POST['type'];
$quantity = (int) $_POST['quantity'];
$reason = trim($_POST['reason'] ?? 'Manual adjustment');
$shop_id = isset($_POST['shop_id']) ? (int) $_POST['shop_id'] : $current_shop_id;

try {
    // Verify product belongs to business
    $check_sql = "SELECT id FROM products WHERE id = ? AND business_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$product_id, $current_business_id]);
    
    if ($check_stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    
    // Get current stock
    $stock_sql = "SELECT quantity FROM product_stocks WHERE product_id = ? AND shop_id = ?";
    $stock_stmt = $pdo->prepare($stock_sql);
    $stock_stmt->execute([$product_id, $shop_id]);
    $current_stock = $stock_stmt->rowCount() > 0 ? $stock_stmt->fetchColumn() : 0;
    
    // Calculate new stock
    switch ($type) {
        case 'add':
            $new_stock = $current_stock + $quantity;
            break;
        case 'remove':
            if ($quantity > $current_stock) {
                echo json_encode(['success' => false, 'message' => 'Cannot remove more than current stock']);
                exit;
            }
            $new_stock = $current_stock - $quantity;
            break;
        case 'set':
            if ($quantity < 0) {
                echo json_encode(['success' => false, 'message' => 'Stock cannot be negative']);
                exit;
            }
            $new_stock = $quantity;
            $quantity = $quantity - $current_stock; // For adjustment record
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid adjustment type']);
            exit;
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Update or insert stock
    if ($stock_stmt->rowCount() > 0) {
        $update_sql = "UPDATE product_stocks SET quantity = ?, last_updated = NOW() WHERE product_id = ? AND shop_id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$new_stock, $product_id, $shop_id]);
    } else {
        $insert_sql = "INSERT INTO product_stocks (product_id, shop_id, business_id, quantity, last_updated) VALUES (?, ?, ?, ?, NOW())";
        $insert_stmt = $pdo->prepare($insert_sql);
        $insert_stmt->execute([$product_id, $shop_id, $current_business_id, $new_stock]);
    }
    
    // Create adjustment record
    $adj_number = 'ADJ' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    $adj_sql = "INSERT INTO stock_adjustments (adjustment_number, product_id, shop_id, adjustment_type, quantity, old_stock, new_stock, reason, adjusted_by, adjusted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $adj_stmt = $pdo->prepare($adj_sql);
    $adj_stmt->execute([$adj_number, $product_id, $shop_id, $type, $quantity, $current_stock, $new_stock, $reason, $user_id]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Stock adjusted successfully',
        'data' => [
            'old_stock' => $current_stock,
            'new_stock' => $new_stock,
            'adjustment_id' => $adj_number
        ]
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Stock adjustment error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}