<?php
// functions.php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==================== DATABASE CONNECTION ====================
/**
 * Get database connection
 */
function get_db_connection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $host = '127.0.0.1';
            $db = 'u329947844_vidhya_traders';
            $user = 'u329947844_vidhya_traders'; // Change this to your database user
            $pass = 'Hifi11@25'; // Change this to your database password
            $charset = 'utf8mb4';
            
            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

// ==================== AUTHENTICATION FUNCTIONS ====================

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Redirect to login if not logged in
 */
function redirect_if_not_logged_in() {
    if (!is_logged_in()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: login.php");
        exit();
    }
}

/**
 * Check if user is admin
 */
function is_admin() {
    return ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Check if user is shop manager
 */
function is_shop_manager() {
    $role = $_SESSION['role'] ?? '';
    return in_array($role, ['admin', 'shop_manager']);
}

/**
 * Check if user is seller/cashier
 */
function is_seller() {
    $role = $_SESSION['role'] ?? '';
    return in_array($role, ['admin', 'shop_manager', 'seller', 'cashier']);
}

/**
 * Check if user can manage stock
 */
function can_manage_stock() {
    $role = $_SESSION['role'] ?? '';
    return in_array($role, ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager']);
}

// ==================== SHOP MANAGEMENT FUNCTIONS ====================

/**
 * Get current shop ID from session
 */
function get_current_shop_id() {
    return $_SESSION['current_shop_id'] ?? null;
}

/**
 * Get current shop name from session
 */
function get_current_shop_name() {
    return $_SESSION['current_shop_name'] ?? 'No Shop Selected';
}

/**
 * Get user's available shops
 */
function get_user_shops($user_id = null) {
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    if (!$user_id) return [];
    
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.* 
        FROM shops s
        INNER JOIN user_shop_access usa ON s.id = usa.shop_id
        WHERE usa.user_id = ? AND s.is_active = 1
        
        UNION
        
        SELECT DISTINCT s.* 
        FROM shops s
        INNER JOIN users u ON s.id = u.shop_id
        WHERE u.id = ? AND s.is_active = 1
        
        UNION
        
        SELECT s.* 
        FROM shops s 
        WHERE ? IN (SELECT id FROM users WHERE role = 'admin') 
        AND s.is_active = 1
        
        ORDER BY shop_name
    ");
    $stmt->execute([$user_id, $user_id, $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get user's default shop (if assigned directly)
 */
function get_user_default_shop($user_id = null) {
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    if (!$user_id) return null;
    
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT s.* 
        FROM shops s
        INNER JOIN users u ON s.id = u.shop_id
        WHERE u.id = ? AND s.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if user has access to a specific shop
 */
function can_access_shop($shop_id, $user_id = null) {
    if (!$shop_id) return false;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    if (!$user_id) return false;
    
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT 1 
        FROM user_shop_access 
        WHERE user_id = ? AND shop_id = ?
        
        UNION
        
        SELECT 1 
        FROM users 
        WHERE id = ? AND shop_id = ?
        
        UNION
        
        SELECT 1 
        FROM users 
        WHERE id = ? AND role = 'admin'
        
        LIMIT 1
    ");
    $stmt->execute([$user_id, $shop_id, $user_id, $shop_id, $user_id]);
    return (bool)$stmt->fetch();
}

/**
 * Get shop stock location
 */
function get_shop_stock_location($shop_id) {
    if (!$shop_id) return null;
    
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT id, location_name 
        FROM stock_locations 
        WHERE shop_id = ? AND location_type = 'shop'
        LIMIT 1
    ");
    $stmt->execute([$shop_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get warehouse location (shared or shop-specific)
 */
function get_warehouse_location($shop_id = null) {
    $pdo = get_db_connection();
    
    if ($shop_id) {
        // Try to get shop-specific warehouse
        $stmt = $pdo->prepare("
            SELECT id, location_name 
            FROM stock_locations 
            WHERE shop_id = ? AND location_type = 'warehouse'
            LIMIT 1
        ");
        $stmt->execute([$shop_id]);
        $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($warehouse) return $warehouse;
    }
    
    // Fallback to main warehouse
    $stmt = $pdo->prepare("
        SELECT id, location_name 
        FROM stock_locations 
        WHERE location_type = 'warehouse' AND shop_id IS NULL
        LIMIT 1
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get product stock for a specific shop
 */
function get_product_stock_for_shop($product_id, $shop_id) {
    $location = get_shop_stock_location($shop_id);
    if (!$location) return 0;
    
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT COALESCE(quantity, 0) as quantity 
        FROM product_stocks 
        WHERE product_id = ? AND location_id = ?
    ");
    $stmt->execute([$product_id, $location['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (int)$result['quantity'] : 0;
}

/**
 * Get total product stock across all locations (including warehouse)
 */
function get_product_total_stock($product_id, $shop_id = null) {
    $pdo = get_db_connection();
    
    if ($shop_id) {
        // Get stock in shop location + warehouse
        $shop_location = get_shop_stock_location($shop_id);
        $warehouse = get_warehouse_location($shop_id);
        
        $total = 0;
        if ($shop_location) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(quantity, 0) as quantity 
                FROM product_stocks 
                WHERE product_id = ? AND location_id = ?
            ");
            $stmt->execute([$product_id, $shop_location['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total += $result ? (int)$result['quantity'] : 0;
        }
        
        if ($warehouse) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(quantity, 0) as quantity 
                FROM product_stocks 
                WHERE product_id = ? AND location_id = ?
            ");
            $stmt->execute([$product_id, $warehouse['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total += $result ? (int)$result['quantity'] : 0;
        }
        
        return $total;
    } else {
        // Get stock across all locations
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantity), 0) as total 
            FROM product_stocks 
            WHERE product_id = ?
        ");
        $stmt->execute([$product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['total'] : 0;
    }
}

/**
 * Require shop selection (redirect if no shop selected)
 */
function require_shop_selection() {
    if (!isset($_SESSION['current_shop_id']) && !isset($_SESSION['shop_selection_skipped'])) {
        $_SESSION['redirect_after_shop'] = $_SERVER['REQUEST_URI'];
        header('Location: select_shop.php');
        exit();
    }
}

/**
 * Check if current user can sell in current shop
 */
function can_sell_in_current_shop() {
    $user_id = $_SESSION['user_id'] ?? 0;
    $shop_id = get_current_shop_id();
    
    if (!$user_id || !$shop_id) return false;
    
    // Admin can always sell
    if (is_admin()) return true;
    
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT can_sell 
        FROM user_shop_access 
        WHERE user_id = ? AND shop_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $shop_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? (bool)$result['can_sell'] : false;
}

// ==================== FORMATTING FUNCTIONS ====================

/**
 * Format money with currency symbol
 */
function format_money($amount) {
    return '₹' . number_format((float)$amount, 2);
}

/**
 * Escape HTML special characters
 */
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Format date for display
 */
function format_date($date_string, $format = 'd/m/Y') {
    if (empty($date_string) || $date_string === '0000-00-00') return '';
    
    try {
        $date = new DateTime($date_string);
        return $date->format($format);
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Format date with time
 */
function format_datetime($datetime_string, $format = 'd/m/Y h:i A') {
    if (empty($datetime_string) || $datetime_string === '0000-00-00 00:00:00') return '';
    
    try {
        $date = new DateTime($datetime_string);
        return $date->format($format);
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Get time ago string
 */
function time_ago($datetime_string) {
    if (empty($datetime_string) || $datetime_string === '0000-00-00 00:00:00') return '';
    
    try {
        $time = strtotime($datetime_string);
        $time_difference = time() - $time;
        
        if ($time_difference < 1) {
            return 'just now';
        }
        
        $condition = [
            12 * 30 * 24 * 60 * 60 => 'year',
            30 * 24 * 60 * 60 => 'month',
            24 * 60 * 60 => 'day',
            60 * 60 => 'hour',
            60 => 'minute',
            1 => 'second'
        ];
        
        foreach ($condition as $secs => $str) {
            $d = $time_difference / $secs;
            if ($d >= 1) {
                $r = round($d);
                return $r . ' ' . $str . ($r > 1 ? 's' : '') . ' ago';
            }
        }
        
        return 'just now';
    } catch (Exception $e) {
        return '';
    }
}

// ==================== VALIDATION FUNCTIONS ====================

/**
 * Validate email address
 */
function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Indian format)
 */
function is_valid_phone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return preg_match('/^[6-9]\d{9}$/', $phone);
}

/**
 * Validate GSTIN
 */
function is_valid_gstin($gstin) {
    if (empty($gstin) || trim($gstin) === '') return true; // GSTIN is optional
    
    // Basic GSTIN validation (15 alphanumeric characters)
    $gstin = strtoupper(trim($gstin));
    if (!preg_match('/^[0-9A-Z]{15}$/', $gstin)) {
        return false;
    }
    
    return true;
}

/**
 * Validate HSN code (6-8 digits)
 */
function is_valid_hsn($hsn) {
    if (empty($hsn) || trim($hsn) === '') return true; // HSN is optional for some products
    
    return preg_match('/^\d{6,8}$/', $hsn);
}

// ==================== DATABASE HELPER FUNCTIONS ====================

/**
 * Generate unique invoice number
 */
function generate_invoice_number($shop_id = null) {
    $prefix = 'INV';
    $year_month = date('Ym');
    
    $pdo = get_db_connection();
    
    if ($shop_id) {
        $shop_code = '';
        $stmt = $pdo->prepare("SELECT shop_code FROM shops WHERE id = ?");
        $stmt->execute([$shop_id]);
        $shop = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($shop) {
            $shop_code = substr(strtoupper($shop['shop_code']), 0, 2);
        }
        $prefix = $shop_code . 'INV';
    }
    
    // Get last sequence number for this month
    $stmt = $pdo->prepare("
        SELECT invoice_number FROM invoices 
        WHERE invoice_number LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$prefix . $year_month . '-%']);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_num = intval(substr($last['invoice_number'], -4));
        $seq = $last_num + 1;
    } else {
        $seq = 1;
    }
    
    return $prefix . $year_month . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Get shop setting
 */
function get_shop_setting($shop_id, $key, $default = null) {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT setting_value 
        FROM shop_settings 
        WHERE shop_id = ? AND setting_key = ?
    ");
    $stmt->execute([$shop_id, $key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['setting_value'] : $default;
}

/**
 * Set shop setting
 */
function set_shop_setting($shop_id, $key, $value) {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        INSERT INTO shop_settings (shop_id, setting_key, setting_value) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    return $stmt->execute([$shop_id, $key, $value]);
}

// ==================== NOTIFICATION FUNCTIONS ====================

/**
 * Set flash message
 */
function set_flash_message($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 */
function get_flash_message() {
    $message = $_SESSION['flash_message'] ?? null;
    unset($_SESSION['flash_message']);
    return $message;
}

/**
 * Display flash message
 */
function display_flash_message() {
    $flash = get_flash_message();
    if ($flash) {
        $type = $flash['type'];
        $message = $flash['message'];
        
        $alert_class = '';
        $icon = '';
        
        switch ($type) {
            case 'success':
                $alert_class = 'alert-success';
                $icon = 'fas fa-check-circle';
                break;
            case 'error':
                $alert_class = 'alert-danger';
                $icon = 'fas fa-exclamation-circle';
                break;
            case 'warning':
                $alert_class = 'alert-warning';
                $icon = 'fas fa-exclamation-triangle';
                break;
            case 'info':
                $alert_class = 'alert-info';
                $icon = 'fas fa-info-circle';
                break;
            default:
                $alert_class = 'alert-primary';
                $icon = 'fas fa-bell';
        }
        
        echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
        echo '<i class="' . $icon . ' me-2"></i>';
        echo h($message);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }
}

// ==================== PERMISSION CHECK FUNCTIONS ====================

/**
 * Check if user has permission
 */
function has_permission($permission, $shop_id = null) {
    $user_id = $_SESSION['user_id'] ?? 0;
    $role = $_SESSION['role'] ?? '';
    
    if (!$user_id) return false;
    
    // Admin has all permissions
    if ($role === 'admin') return true;
    
    if (!$shop_id) {
        $shop_id = get_current_shop_id();
    }
    
    if ($shop_id) {
        $pdo = get_db_connection();
        // Check shop-specific permissions
        $stmt = $pdo->prepare("
            SELECT * FROM user_shop_access 
            WHERE user_id = ? AND shop_id = ?
        ");
        $stmt->execute([$user_id, $shop_id]);
        $access = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$access) {
            // Check if user is directly assigned to shop
            $stmt = $pdo->prepare("
                SELECT shop_id FROM users 
                WHERE id = ? AND shop_id = ?
            ");
            $stmt->execute([$user_id, $shop_id]);
            $assigned = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$assigned) {
                return false;
            }
        }
        
        switch ($permission) {
            case 'can_sell':
                return (bool)($access['can_sell'] ?? in_array($role, ['seller', 'cashier', 'shop_manager']));
            case 'can_manage_stock':
                return (bool)($access['can_manage_stock'] ?? in_array($role, ['stock_manager', 'warehouse_manager', 'shop_manager']));
            case 'can_view_reports':
                return (bool)($access['can_view_reports'] ?? in_array($role, ['shop_manager', 'stock_manager', 'seller']));
            default:
                return false;
        }
    }
    
    return false;
}

// ==================== SHOP SWITCHING FUNCTIONS ====================

/**
 * Get shop switch URL
 */
function get_shop_switch_url($shop_id) {
    return "switch_shop.php?shop_id=" . $shop_id;
}

/**
 * Get shop dashboard URL
 */
function get_shop_dashboard_url($shop_id = null) {
    if ($shop_id) {
        return "shop_dashboard.php?shop_id=" . $shop_id;
    }
    return "dashboard.php";
}

// ==================== STOCK MANAGEMENT FUNCTIONS ====================

/**
 * Update product stock
 */
function update_product_stock($product_id, $location_id, $quantity_change, $reason = '') {
    $pdo = get_db_connection();
    
    // Get current stock
    $stmt = $pdo->prepare("
        SELECT quantity FROM product_stocks 
        WHERE product_id = ? AND location_id = ?
    ");
    $stmt->execute([$product_id, $location_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $old_quantity = $current ? (int)$current['quantity'] : 0;
    $new_quantity = max(0, $old_quantity + $quantity_change);
    
    // Update or insert stock record
    if ($current) {
        $stmt = $pdo->prepare("
            UPDATE product_stocks 
            SET quantity = ?, last_updated = NOW() 
            WHERE product_id = ? AND location_id = ?
        ");
        $stmt->execute([$new_quantity, $product_id, $location_id]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO product_stocks (product_id, location_id, quantity, last_updated) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$product_id, $location_id, $new_quantity]);
    }
    
    // Record stock adjustment
    if ($quantity_change != 0 && !empty($reason)) {
        $user_id = $_SESSION['user_id'] ?? 0;
        
        // Generate adjustment number
        $adjustment_number = 'ADJ' . date('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("
            INSERT INTO stock_adjustments 
            (adjustment_number, product_id, location_id, adjustment_type, quantity, old_stock, new_stock, reason, adjusted_by, adjusted_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $adjustment_type = $quantity_change > 0 ? 'add' : 'remove';
        $stmt->execute([
            $adjustment_number,
            $product_id,
            $location_id,
            $adjustment_type,
            abs($quantity_change),
            $old_quantity,
            $new_quantity,
            $reason,
            $user_id
        ]);
    }
    
    return $new_quantity;
}

/**
 * Transfer stock between locations
 */
function transfer_stock($product_id, $from_location_id, $to_location_id, $quantity, $reason = 'Stock Transfer') {
    if ($quantity <= 0) {
        throw new Exception("Invalid quantity for transfer");
    }
    
    $pdo = get_db_connection();
    
    try {
        $pdo->beginTransaction();
        
        // Remove from source
        update_product_stock($product_id, $from_location_id, -$quantity, "Transfer to other location: " . $reason);
        
        // Add to destination
        update_product_stock($product_id, $to_location_id, $quantity, "Transfer from other location: " . $reason);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ==================== ENHANCED FUNCTIONS ====================

/**
 * Initialize session with shop data
 */
function initialize_user_session($user_id, $shop_id = null) {
    $pdo = get_db_connection();
    
    // Get user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) return false;
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    
    // Get user's shops
    $user_shops = get_user_shops($user_id);
    
    if (empty($user_shops)) {
        // No shops assigned
        $_SESSION['shop_selection_skipped'] = true;
        return true;
    }
    
    // Set default shop
    if (!$shop_id && $user['shop_id']) {
        // Use user's assigned shop
        $shop_id = $user['shop_id'];
    } elseif (!$shop_id) {
        // Use first shop
        $shop_id = $user_shops[0]['id'];
    }
    
    // Validate shop access
    if (!can_access_shop($shop_id, $user_id)) {
        return false;
    }
    
    // Set shop session
    $shop = null;
    foreach ($user_shops as $s) {
        if ($s['id'] == $shop_id) {
            $shop = $s;
            break;
        }
    }
    
    if ($shop) {
        $_SESSION['current_shop_id'] = $shop['id'];
        $_SESSION['current_shop_name'] = $shop['shop_name'];
        $_SESSION['current_shop_code'] = $shop['shop_code'];
        
        // Get shop location
        $shop_location = get_shop_stock_location($shop['id']);
        if ($shop_location) {
            $_SESSION['current_shop_location_id'] = $shop_location['id'];
        }
        
        // Get warehouse location
        $warehouse = get_warehouse_location($shop['id']);
        if ($warehouse) {
            $_SESSION['warehouse_location_id'] = $warehouse['id'];
        }
    }
    
    return true;
}

/**
 * Switch to another shop
 */
function switch_shop($shop_id) {
    $user_id = $_SESSION['user_id'] ?? 0;
    
    if (!$user_id || !$shop_id) {
        return false;
    }
    
    // Verify shop access
    if (!can_access_shop($shop_id, $user_id)) {
        return false;
    }
    
    // Get shop details
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM shops WHERE id = ? AND is_active = 1");
    $stmt->execute([$shop_id]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shop) {
        return false;
    }
    
    // Update session
    $_SESSION['current_shop_id'] = $shop['id'];
    $_SESSION['current_shop_name'] = $shop['shop_name'];
    $_SESSION['current_shop_code'] = $shop['shop_code'];
    
    // Get shop location
    $shop_location = get_shop_stock_location($shop['id']);
    if ($shop_location) {
        $_SESSION['current_shop_location_id'] = $shop_location['id'];
    } else {
        unset($_SESSION['current_shop_location_id']);
    }
    
    // Get warehouse location
    $warehouse = get_warehouse_location($shop['id']);
    if ($warehouse) {
        $_SESSION['warehouse_location_id'] = $warehouse['id'];
    } else {
        unset($_SESSION['warehouse_location_id']);
    }
    
    return true;
}

/**
 * Check if user can perform action in current shop
 */
function can_perform_action($action, $shop_id = null) {
    if (!$shop_id) {
        $shop_id = get_current_shop_id();
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $role = $_SESSION['role'] ?? '';
    
    if (!$user_id || !$shop_id) {
        return false;
    }
    
    // Admin can do everything
    if ($role === 'admin') {
        return true;
    }
    
    // Get user's access rights for this shop
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT * FROM user_shop_access 
        WHERE user_id = ? AND shop_id = ?
    ");
    $stmt->execute([$user_id, $shop_id]);
    $access = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$access) {
        // Check if user is directly assigned to shop
        $stmt = $pdo->prepare("
            SELECT shop_id FROM users 
            WHERE id = ? AND shop_id = ?
        ");
        $stmt->execute([$user_id, $shop_id]);
        $assigned = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assigned) {
            return false;
        }
        
        // Default permissions for directly assigned users
        $default_permissions = [
            'sell' => in_array($role, ['seller', 'cashier', 'shop_manager']),
            'manage_stock' => in_array($role, ['stock_manager', 'warehouse_manager', 'shop_manager']),
            'view_reports' => in_array($role, ['shop_manager', 'stock_manager', 'seller']),
            'manage_products' => in_array($role, ['shop_manager']),
            'view_invoices' => in_array($role, ['seller', 'cashier', 'shop_manager']),
        ];
        
        return $default_permissions[$action] ?? false;
    }
    
    // Check based on access rights
    switch ($action) {
        case 'sell':
            return (bool)$access['can_sell'];
        case 'manage_stock':
            return (bool)$access['can_manage_stock'];
        case 'view_reports':
            return (bool)$access['can_view_reports'];
        case 'manage_products':
            return $role === 'shop_manager' || $role === 'admin';
        case 'view_invoices':
            return $access['can_sell'] || $access['can_view_reports'];
        default:
            return false;
    }
}

/**
 * Get consolidated stock for a product (shop + warehouse)
 */
function get_consolidated_product_stock($product_id, $shop_id = null) {
    if (!$shop_id) {
        $shop_id = get_current_shop_id();
    }
    
    if (!$shop_id) {
        return [
            'shop_stock' => 0,
            'warehouse_stock' => 0,
            'total_stock' => 0,
            'available_for_sale' => 0
        ];
    }
    
    $shop_stock = get_product_stock_for_shop($product_id, $shop_id);
    
    // Get warehouse stock
    $warehouse_stock = 0;
    $warehouse = get_warehouse_location($shop_id);
    if ($warehouse) {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT COALESCE(quantity, 0) as quantity 
            FROM product_stocks 
            WHERE product_id = ? AND location_id = ?
        ");
        $stmt->execute([$product_id, $warehouse['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $warehouse_stock = $result ? (int)$result['quantity'] : 0;
    }
    
    $total_stock = $shop_stock + $warehouse_stock;
    
    return [
        'shop_stock' => $shop_stock,
        'warehouse_stock' => $warehouse_stock,
        'total_stock' => $total_stock,
        'available_for_sale' => $shop_stock // Only shop stock is immediately available
    ];
}

/**
 * Check if product is available for sale in current shop
 */
function is_product_available_for_sale($product_id, $quantity = 1) {
    $shop_id = get_current_shop_id();
    if (!$shop_id) return false;
    
    $stock = get_consolidated_product_stock($product_id, $shop_id);
    return $stock['shop_stock'] >= $quantity;
}

/**
 * Get shop dashboard statistics
 */
function get_shop_dashboard_stats($shop_id) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $this_month = date('Y-m');
    
    $pdo = get_db_connection();
    
    // Today's sales
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as today_invoices,
            COALESCE(SUM(total), 0) as today_sales,
            COALESCE(SUM(cash_amount), 0) as today_cash,
            COALESCE(SUM(upi_amount), 0) as today_upi,
            COALESCE(SUM(pending_amount), 0) as today_pending
        FROM invoices 
        WHERE shop_id = ? AND DATE(created_at) = ?
    ");
    $stmt->execute([$shop_id, $today]);
    $today_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Yesterday's sales for comparison
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(total), 0) as yesterday_sales
        FROM invoices 
        WHERE shop_id = ? AND DATE(created_at) = ?
    ");
    $stmt->execute([$shop_id, $yesterday]);
    $yesterday_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // This month sales
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(total), 0) as month_sales,
            COUNT(*) as month_invoices
        FROM invoices 
        WHERE shop_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?
    ");
    $stmt->execute([$shop_id, $this_month]);
    $month_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Low stock products
    $stmt = $pdo->prepare("
        SELECT 
            p.id, p.product_name, p.product_code,
            COALESCE(ps.quantity, 0) as shop_stock,
            p.min_stock_level
        FROM products p
        LEFT JOIN product_stocks ps ON p.id = ps.product_id
        LEFT JOIN stock_locations sl ON ps.location_id = sl.id
        WHERE sl.shop_id = ? AND sl.location_type = 'shop'
        AND p.is_active = 1
        AND COALESCE(ps.quantity, 0) <= p.min_stock_level
        LIMIT 10
    ");
    $stmt->execute([$shop_id]);
    $low_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent invoices
    $stmt = $pdo->prepare("
        SELECT i.*, c.name as customer_name, u.full_name as seller_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN users u ON i.seller_id = u.id
        WHERE i.shop_id = ?
        ORDER BY i.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$shop_id]);
    $recent_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'today' => $today_stats,
        'yesterday' => $yesterday_stats,
        'month' => $month_stats,
        'low_stock' => $low_stock,
        'recent_invoices' => $recent_invoices
    ];
}

// ==================== SECURITY FUNCTIONS ====================

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate random string
 */
function generate_random_string($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

// ==================== INPUT VALIDATION ====================

/**
 * Sanitize input
 */
function sanitize_input($data) {
    $data = trim($data ?? '');
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate required fields
 */
function validate_required($fields) {
    foreach ($fields as $field => $value) {
        if (empty(trim($value ?? ''))) {
            return false;
        }
    }
    return true;
}

// ==================== LOGGING FUNCTIONS ====================

/**
 * Log activity
 */
function log_activity($action, $details = '', $user_id = null) {
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        INSERT INTO user_activity_log (user_id, action, details, ip_address, user_agent) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    return $stmt->execute([$user_id, $action, $details, $ip_address, $user_agent]);
}

// ==================== ERROR HANDLING ====================

/**
 * Handle database errors gracefully
 */
function handle_db_error($error) {
    error_log("Database Error: " . $error->getMessage());
    
    if (isset($_SESSION['user_id']) && is_admin()) {
        // Show detailed error to admin
        return "Database Error: " . $error->getMessage();
    } else {
        // Generic error for users
        return "A system error occurred. Please try again later.";
    }
}

// ==================== SIDEBAR MENU ====================

/**
 * Generate sidebar menu items based on permissions
 */
function generate_sidebar_menu() {
    $menu = [];
    $role = $_SESSION['role'] ?? '';
    $shop_id = get_current_shop_id();
    
    // Always show dashboard
    $menu['dashboard'] = [
        'name' => 'Dashboard',
        'icon' => 'bx-home-alt',
        'url' => 'dashboard.php',
        'show' => true
    ];
    
    // POS Billing (only if shop selected and can sell)
    $menu['pos'] = [
        'name' => 'POS Billing',
        'icon' => 'bx-cart-add',
        'url' => 'pos.php',
        'show' => $shop_id && can_perform_action('sell', $shop_id),
        'badge' => 'HOT'
    ];
    
    // Sales Management
    $menu['sales'] = [
        'name' => 'Sales',
        'icon' => 'bx-receipt',
        'show' => can_perform_action('view_invoices', $shop_id),
        'submenu' => [
            ['name' => 'All Invoices', 'url' => 'invoices.php'],
            ['name' => 'Sales Returns', 'url' => 'sales_returns.php'],
        ]
    ];
    
    // Add shop-specific sales link
    if ($shop_id) {
        $menu['sales']['submenu'][] = [
            'name' => 'Shop Sales', 
            'url' => 'shop_sales.php?shop_id=' . $shop_id
        ];
    }
    
    // Products Management
    $menu['products'] = [
        'name' => 'Products',
        'icon' => 'bx-package',
        'show' => can_perform_action('manage_products', $shop_id) || $role === 'admin',
        'submenu' => [
            ['name' => 'All Products', 'url' => 'products.php'],
            ['name' => 'Categories', 'url' => 'categories.php'],
            ['name' => 'Manufacturers', 'url' => 'manufacturers.php'],
            ['name' => 'GST Rates', 'url' => 'gst_rates.php'],
        ]
    ];
    
    // Stock Management
    if (can_perform_action('manage_stock', $shop_id)) {
        $menu['stock'] = [
            'name' => 'Stock Management',
            'icon' => 'bx-transfer-alt',
            'show' => true,
            'submenu' => []
        ];
        
        if ($shop_id) {
            $menu['stock']['submenu'][] = [
                'name' => 'Shop Stock', 
                'url' => 'shop_stock.php?shop_id=' . $shop_id
            ];
        }
        
        $menu['stock']['submenu'] = array_merge($menu['stock']['submenu'], [
            ['name' => 'Warehouse Stock', 'url' => 'warehouse_stock.php'],
            ['name' => 'Stock Adjustment', 'url' => 'stock_adjustment.php'],
            ['name' => 'Stock History', 'url' => 'stock_history.php'],
            ['name' => 'Low Stock Alert', 'url' => 'low_stock.php'],
            ['name' => 'Stock Transfers', 'url' => 'stock_transfers.php']
        ]);
    }
    
    // Customers
    $menu['customers'] = [
        'name' => 'Customers',
        'icon' => 'bx-user',
        'url' => 'customers.php',
        'show' => is_logged_in()
    ];
    
    // Reports
    if (can_perform_action('view_reports', $shop_id)) {
        $menu['reports'] = [
            'name' => 'Reports',
            'icon' => 'bx-bar-chart-alt-2',
            'show' => true,
            'submenu' => [
                ['name' => 'Daily Sales', 'url' => 'report_daily.php'],
                ['name' => 'Shop Report', 'url' => 'report_shop.php'],
                ['name' => 'Stock Report', 'url' => 'stock_report.php']
            ]
        ];
    }
    
    // Purchases
    if ($role === 'admin' || $role === 'shop_manager') {
        $menu['purchases'] = [
            'name' => 'Purchases',
            'icon' => 'bx-purchase-tag',
            'show' => true,
            'submenu' => [
                ['name' => 'Purchase Orders', 'url' => 'purchases.php'],
                ['name' => 'Add Purchase', 'url' => 'purchase_add.php'],
                ['name' => 'Suppliers', 'url' => 'manufacturers.php'],
                ['name' => 'Payments', 'url' => 'purchase_payments.php']
            ]
        ];
    }
    
    // Admin Settings
    if ($role === 'admin') {
        $menu['settings'] = [
            'name' => 'System Settings',
            'icon' => 'bx-cog',
            'show' => true,
            'submenu' => [
                ['name' => 'User Management', 'url' => 'users.php'],
                ['name' => 'Shop Management', 'url' => 'shops.php'],
                ['name' => 'Location Management', 'url' => 'locations.php'],
                ['name' => 'Database Backup', 'url' => 'backup.php']
            ]
        ];
    }
    
    // Profile & Logout
    $menu['profile'] = [
        'name' => $_SESSION['full_name'] ?? 'Profile',
        'icon' => 'bx-user-circle',
        'show' => is_logged_in(),
        'submenu' => [
            ['name' => 'My Profile', 'url' => 'profile.php'],
            ['name' => 'Change Password', 'url' => 'change_password.php'],
            ['name' => 'Logout', 'url' => 'logout.php']
        ]
    ];
    
    return $menu;
}
?>