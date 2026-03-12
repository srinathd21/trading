<?php

// api/products.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// api/products.php
require_once '../includes/auth.php';
require_once '../config/database.php';

checkAuth();
$business_id = getBusinessId();
$shop_id = getShopId();
$user_id = getUserId();

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            getProducts();
            break;
        case 'search':
            searchProducts();
            break;
        case 'barcode':
            getProductByBarcode();
            break;
        case 'stock':
            getStock();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Products API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

function getProducts() {
    global $pdo, $business_id, $shop_id;
    
    // Get warehouse ID for this business
    $warehouse_sql = "SELECT id FROM shops WHERE business_id = ? AND is_warehouse = 1 LIMIT 1";
    $warehouse_stmt = $pdo->prepare($warehouse_sql);
    $warehouse_stmt->execute([$business_id]);
    $warehouse = $warehouse_stmt->fetch();
    $warehouse_id = $warehouse['id'] ?? 0;
    
    // Get all active products for this business with stock info including secondary units and old_qty
    $sql = "SELECT 
                p.id, p.product_name, p.product_code, p.barcode,
                p.retail_price, p.wholesale_price, p.stock_price,
                p.mrp, p.hsn_code, p.unit_of_measure,
                p.secondary_unit, p.sec_unit_conversion,
                p.discount_type, p.discount_value,
                p.retail_price_type, p.retail_price_value,
                p.wholesale_price_type, p.wholesale_price_value,
                p.referral_enabled, p.referral_type, p.referral_value,
                p.sec_unit_price_type, p.sec_unit_extra_charge,
                c.category_name, s.subcategory_name,
                g.cgst_rate, g.sgst_rate, g.igst_rate,
                COALESCE(ps_shop.quantity, 0) as shop_stock_primary,
                COALESCE(ps_shop.old_qty, 0) as shop_old_qty,
                COALESCE(ps_shop.total_secondary_units, 0) as shop_stock_secondary,
                COALESCE(ps_shop.use_batch_tracking, 0) as use_batch_tracking,
                COALESCE(ps_shop.batch_id, NULL) as current_batch_id,
                COALESCE(ps_warehouse.quantity, 0) as warehouse_stock_primary,
                COALESCE(ps_warehouse.old_qty, 0) as warehouse_old_qty,
                COALESCE(ps_warehouse.total_secondary_units, 0) as warehouse_stock_secondary,
                COALESCE(ps_warehouse.use_batch_tracking, 0) as warehouse_use_batch_tracking,
                COALESCE(ps_warehouse.batch_id, NULL) as warehouse_batch_id,
                pb.purchase_price as batch_purchase_price,
                pb.retail_price as batch_retail_price,
                pb.wholesale_price as batch_wholesale_price,
                pb.new_mrp as batch_mrp
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN subcategories s ON p.subcategory_id = s.id
            LEFT JOIN gst_rates g ON p.gst_id = g.id
            LEFT JOIN product_stocks ps_shop ON p.id = ps_shop.product_id 
                AND ps_shop.shop_id = ?
            LEFT JOIN product_stocks ps_warehouse ON p.id = ps_warehouse.product_id 
                AND ps_warehouse.shop_id = ?
            LEFT JOIN purchase_batches pb ON ps_shop.batch_id = pb.id AND pb.business_id = p.business_id
            WHERE p.is_active = 1 AND p.business_id = ?
            ORDER BY p.product_name";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$shop_id, $warehouse_id, $business_id]);
        $products = $stmt->fetchAll();
        
        // Process each product to calculate secondary units if needed
        foreach ($products as &$product) {
            // Calculate secondary stock if conversion exists
            if (!empty($product['secondary_unit']) && $product['sec_unit_conversion'] > 0) {
                // Calculate shop secondary stock
                if ($product['shop_stock_secondary'] == 0 && $product['shop_stock_primary'] > 0) {
                    $product['shop_stock_secondary'] = $product['shop_stock_primary'] * $product['sec_unit_conversion'];
                }
                
                // Calculate warehouse secondary stock
                if ($product['warehouse_stock_secondary'] == 0 && $product['warehouse_stock_primary'] > 0) {
                    $product['warehouse_stock_secondary'] = $product['warehouse_stock_primary'] * $product['sec_unit_conversion'];
                }
                
                // Calculate total stock (shop + warehouse)
                $total_primary = $product['shop_stock_primary'] + $product['warehouse_stock_primary'];
                $total_secondary = $product['shop_stock_secondary'] + $product['warehouse_stock_secondary'];
                
                // Format display strings
                $product['total_stock_display'] = sprintf(
                    "%s %s = %s %s",
                    $total_primary,
                    $product['unit_of_measure'],
                    $total_secondary,
                    $product['secondary_unit']
                );
                
                $product['shop_stock_display'] = sprintf(
                    "%s %s = %s %s (Old Stock: %s)",
                    $product['shop_stock_primary'],
                    $product['unit_of_measure'],
                    $product['shop_stock_secondary'],
                    $product['secondary_unit'],
                    $product['shop_old_qty']
                );
                
                $product['warehouse_stock_display'] = sprintf(
                    "%s %s = %s %s (Old Stock: %s)",
                    $product['warehouse_stock_primary'],
                    $product['unit_of_measure'],
                    $product['warehouse_stock_secondary'],
                    $product['secondary_unit'],
                    $product['warehouse_old_qty']
                );
            } else {
                // No secondary unit, just show primary
                $total_primary = $product['shop_stock_primary'] + $product['warehouse_stock_primary'];
                $product['total_stock_display'] = $total_primary . " " . $product['unit_of_measure'];
                $product['shop_stock_display'] = $product['shop_stock_primary'] . " " . $product['unit_of_measure'] . 
                                                " (Old Stock: " . $product['shop_old_qty'] . ")";
                $product['warehouse_stock_display'] = $product['warehouse_stock_primary'] . " " . $product['unit_of_measure'] . 
                                                     " (Old Stock: " . $product['warehouse_old_qty'] . ")";
                $product['shop_stock_secondary'] = 0;
                $product['warehouse_stock_secondary'] = 0;
            }
            
            // Calculate total stock for quick reference
            $product['total_stock_primary'] = $product['shop_stock_primary'] + $product['warehouse_stock_primary'];
            $product['total_stock_secondary'] = $product['shop_stock_secondary'] + $product['warehouse_stock_secondary'];
            $product['total_old_qty'] = $product['shop_old_qty'] + $product['warehouse_old_qty'];
            
            // Determine if using batch tracking
            $product['is_using_batch_tracking'] = ($product['use_batch_tracking'] == 1) ? true : false;
            
            // Add batch info if available
            if ($product['current_batch_id']) {
                $product['batch_info'] = [
                    'batch_id' => $product['current_batch_id'],
                    'purchase_price' => $product['batch_purchase_price'],
                    'retail_price' => $product['batch_retail_price'],
                    'wholesale_price' => $product['batch_wholesale_price'],
                    'mrp' => $product['batch_mrp']
                ];
            } else {
                $product['batch_info'] = null;
            }
        }
        unset($product); // Unset reference
        
        // Create barcode map for quick lookup
        $barcode_map = [];
        foreach ($products as $product) {
            if (!empty($product['barcode'])) {
                $barcode_map[$product['barcode']] = $product['id'];
            }
            if (!empty($product['product_code'])) {
                $barcode_map[$product['product_code']] = $product['id'];
            }
        }
        
        echo json_encode([
            'success' => true,
            'products' => $products,
            'barcode_map' => $barcode_map
        ]);
        
    } catch (PDOException $e) {
        error_log("Database Error in getProducts: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred'
        ]);
    }
}

function searchProducts() {
    global $pdo, $business_id, $shop_id;
    
    $search = $_GET['q'] ?? '';
    if (empty($search)) {
        echo json_encode(['success' => true, 'products' => []]);
        return;
    }
    
    $warehouse_sql = "SELECT id FROM shops WHERE business_id = ? AND is_warehouse = 1 LIMIT 1";
    $warehouse_stmt = $pdo->prepare($warehouse_sql);
    $warehouse_stmt->execute([$business_id]);
    $warehouse = $warehouse_stmt->fetch();
    $warehouse_id = $warehouse['id'] ?? 0;
    
    $sql = "SELECT 
                p.id, p.product_name, p.product_code, p.barcode,
                p.retail_price, p.wholesale_price,
                p.mrp, p.unit_of_measure,
                p.secondary_unit, p.sec_unit_conversion,
                COALESCE(ps_shop.quantity, 0) as shop_stock_primary,
                COALESCE(ps_shop.old_qty, 0) as shop_old_qty,
                COALESCE(ps_shop.total_secondary_units, 0) as shop_stock_secondary,
                COALESCE(ps_shop.use_batch_tracking, 0) as use_batch_tracking
            FROM products p
            LEFT JOIN product_stocks ps_shop ON p.id = ps_shop.product_id 
                AND ps_shop.shop_id = ?
            WHERE p.is_active = 1 AND p.business_id = ?
            AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)
            ORDER BY p.product_name
            LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $searchTerm = "%{$search}%";
    $stmt->execute([$shop_id, $business_id, $searchTerm, $searchTerm, $searchTerm]);
    $products = $stmt->fetchAll();
    
    // Add calculated secondary units for display
    foreach ($products as &$product) {
        if (!empty($product['secondary_unit']) && $product['sec_unit_conversion'] > 0) {
            if ($product['shop_stock_secondary'] == 0 && $product['shop_stock_primary'] > 0) {
                $product['shop_stock_secondary'] = $product['shop_stock_primary'] * $product['sec_unit_conversion'];
            }
            $product['stock_display'] = sprintf(
                "%s %s = %s %s (Old Stock: %s)",
                $product['shop_stock_primary'],
                $product['unit_of_measure'],
                $product['shop_stock_secondary'],
                $product['secondary_unit'],
                $product['shop_old_qty']
            );
        } else {
            $product['stock_display'] = $product['shop_stock_primary'] . " " . $product['unit_of_measure'] . 
                                       " (Old Stock: " . $product['shop_old_qty'] . ")";
        }
    }
    unset($product);
    
    echo json_encode(['success' => true, 'products' => $products]);
}

function getProductByBarcode() {
    global $pdo, $business_id, $shop_id;
    
    $barcode = $_GET['barcode'] ?? '';
    
    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'Barcode required']);
        return;
    }
    
    $warehouse_sql = "SELECT id FROM shops WHERE business_id = ? AND is_warehouse = 1 LIMIT 1";
    $warehouse_stmt = $pdo->prepare($warehouse_sql);
    $warehouse_stmt->execute([$business_id]);
    $warehouse = $warehouse_stmt->fetch();
    $warehouse_id = $warehouse['id'] ?? 0;
    
    $sql = "SELECT 
                p.id, p.product_name, p.product_code, p.barcode,
                p.retail_price, p.wholesale_price, p.stock_price,
                p.mrp, p.hsn_code, p.unit_of_measure,
                p.secondary_unit, p.sec_unit_conversion,
                p.discount_type, p.discount_value,
                p.retail_price_type, p.retail_price_value,
                p.wholesale_price_type, p.wholesale_price_value,
                p.referral_enabled, p.referral_type, p.referral_value,
                p.sec_unit_price_type, p.sec_unit_extra_charge,
                g.cgst_rate, g.sgst_rate, g.igst_rate,
                COALESCE(ps_shop.quantity, 0) as shop_stock_primary,
                COALESCE(ps_shop.old_qty, 0) as shop_old_qty,
                COALESCE(ps_shop.total_secondary_units, 0) as shop_stock_secondary,
                COALESCE(ps_shop.use_batch_tracking, 0) as use_batch_tracking,
                COALESCE(ps_shop.batch_id, NULL) as current_batch_id,
                COALESCE(ps_warehouse.quantity, 0) as warehouse_stock_primary,
                COALESCE(ps_warehouse.old_qty, 0) as warehouse_old_qty,
                COALESCE(ps_warehouse.total_secondary_units, 0) as warehouse_stock_secondary,
                COALESCE(ps_warehouse.use_batch_tracking, 0) as warehouse_use_batch_tracking,
                COALESCE(ps_warehouse.batch_id, NULL) as warehouse_batch_id,
                pb.purchase_price as batch_purchase_price,
                pb.retail_price as batch_retail_price,
                pb.wholesale_price as batch_wholesale_price,
                pb.new_mrp as batch_mrp
            FROM products p
            LEFT JOIN gst_rates g ON p.gst_id = g.id
            LEFT JOIN product_stocks ps_shop ON p.id = ps_shop.product_id 
                AND ps_shop.shop_id = ?
            LEFT JOIN product_stocks ps_warehouse ON p.id = ps_warehouse.product_id 
                AND ps_warehouse.shop_id = ?
            LEFT JOIN purchase_batches pb ON ps_shop.batch_id = pb.id AND pb.business_id = p.business_id
            WHERE p.is_active = 1 AND p.business_id = ? 
            AND (p.barcode = ? OR p.product_code = ?)
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$shop_id, $warehouse_id, $business_id, $barcode, $barcode]);
    $product = $stmt->fetch();
    
    if ($product) {
        // Calculate secondary units if needed
        if (!empty($product['secondary_unit']) && $product['sec_unit_conversion'] > 0) {
            // Calculate shop secondary stock
            if ($product['shop_stock_secondary'] == 0 && $product['shop_stock_primary'] > 0) {
                $product['shop_stock_secondary'] = $product['shop_stock_primary'] * $product['sec_unit_conversion'];
            }
            
            // Calculate warehouse secondary stock
            if ($product['warehouse_stock_secondary'] == 0 && $product['warehouse_stock_primary'] > 0) {
                $product['warehouse_stock_secondary'] = $product['warehouse_stock_primary'] * $product['sec_unit_conversion'];
            }
            
            // Calculate total
            $product['total_stock_primary'] = $product['shop_stock_primary'] + $product['warehouse_stock_primary'];
            $product['total_stock_secondary'] = $product['shop_stock_secondary'] + $product['warehouse_stock_secondary'];
            $product['total_old_qty'] = $product['shop_old_qty'] + $product['warehouse_old_qty'];
            
            // Format display
            $product['stock_display'] = sprintf(
                "%s %s = %s %s (Old Stock: %s)",
                $product['total_stock_primary'],
                $product['unit_of_measure'],
                $product['total_stock_secondary'],
                $product['secondary_unit'],
                $product['total_old_qty']
            );
            
            $product['shop_stock_display'] = sprintf(
                "%s %s = %s %s (Old Stock: %s)",
                $product['shop_stock_primary'],
                $product['unit_of_measure'],
                $product['shop_stock_secondary'],
                $product['secondary_unit'],
                $product['shop_old_qty']
            );
            
            $product['warehouse_stock_display'] = sprintf(
                "%s %s = %s %s (Old Stock: %s)",
                $product['warehouse_stock_primary'],
                $product['unit_of_measure'],
                $product['warehouse_stock_secondary'],
                $product['secondary_unit'],
                $product['warehouse_old_qty']
            );
        } else {
            $product['total_stock_primary'] = $product['shop_stock_primary'] + $product['warehouse_stock_primary'];
            $product['total_stock_secondary'] = 0;
            $product['total_old_qty'] = $product['shop_old_qty'] + $product['warehouse_old_qty'];
            $product['stock_display'] = $product['total_stock_primary'] . " " . $product['unit_of_measure'] . 
                                       " (Old Stock: " . $product['total_old_qty'] . ")";
            $product['shop_stock_display'] = $product['shop_stock_primary'] . " " . $product['unit_of_measure'] . 
                                            " (Old Stock: " . $product['shop_old_qty'] . ")";
            $product['warehouse_stock_display'] = $product['warehouse_stock_primary'] . " " . $product['unit_of_measure'] . 
                                                 " (Old Stock: " . $product['warehouse_old_qty'] . ")";
        }
        
        // Determine if using batch tracking
        $product['is_using_batch_tracking'] = ($product['use_batch_tracking'] == 1) ? true : false;
        
        // Add batch info if available
        if ($product['current_batch_id']) {
            $product['batch_info'] = [
                'batch_id' => $product['current_batch_id'],
                'purchase_price' => $product['batch_purchase_price'],
                'retail_price' => $product['batch_retail_price'],
                'wholesale_price' => $product['batch_wholesale_price'],
                'mrp' => $product['batch_mrp']
            ];
        } else {
            $product['batch_info'] = null;
        }
        
        echo json_encode(['success' => true, 'product' => $product]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
    }
}
?>