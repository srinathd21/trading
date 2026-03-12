<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

/* =======================
   AUTHORIZATION
   ======================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager'])) {
    $_SESSION['error'] = "You don't have permission to delete products.";
    header('Location: products.php');
    exit();
}

$business_id = (int)($_SESSION['current_business_id'] ?? 0);
$product_id  = (int)($_GET['id'] ?? 0);

if ($product_id <= 0) {
    $_SESSION['error'] = "Invalid product selected.";
    header('Location: products.php');
    exit();
}

/* =======================
   VERIFY PRODUCT
   ======================= */
$stmt = $pdo->prepare("
    SELECT id, product_name 
    FROM products 
    WHERE id = ? AND business_id = ? AND is_active = 1
");
$stmt->execute([$product_id, $business_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $_SESSION['error'] = "Product not found or already deleted.";
    header('Location: products.php');
    exit();
}

/* =======================
   DELETE (SOFT)
   ======================= */
try {
    $pdo->beginTransaction();

    // Soft delete product
    $stmt = $pdo->prepare("
        UPDATE products 
        SET is_active = 0, updated_at = NOW()
        WHERE id = ? AND business_id = ?
    ");
    $stmt->execute([$product_id, $business_id]);

    $pdo->commit();

    $_SESSION['success'] = "Product '{$product['product_name']}' deleted successfully.";
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = "Failed to delete product. Please try again.";
}

header('Location: products.php');
exit();
