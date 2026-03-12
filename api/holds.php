<?php
require_once '../includes/auth.php';
require_once '../config/database.php';

checkAuth();
$business_id = getBusinessId();
$shop_id = getShopId();
$user_id = getUserId();

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_next_number':
            getNextHoldNumber();
            break;
        case 'save':
            saveHold();
            break;
        case 'list':
            listHolds();
            break;
        case 'get':
            getHold();
            break;
        case 'delete':
            deleteHold();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
    }
} catch (Exception $e) {
    error_log("Holds API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

function getNextHoldNumber() {
    global $pdo, $business_id, $shop_id;
    
    $year_month = date('Ym');
    $prefix = 'HOLD' . $year_month . '-';
    
    $sql = "SELECT hold_number
            FROM held_invoices
            WHERE business_id = ?
              AND shop_id = ?
              AND hold_number LIKE ?
            ORDER BY CAST(SUBSTRING_INDEX(hold_number, '-', -1) AS UNSIGNED) DESC
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$business_id, $shop_id, $prefix . '%']);
    $last = $stmt->fetch();
    
    $seq = 1;
    if ($last && isset($last['hold_number'])) {
        $last_num = (int)substr($last['hold_number'], strlen($prefix));
        $seq = $last_num + 1;
    }
    
    $hold_number = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    
    echo json_encode([
        'success' => true,
        'hold_number' => $hold_number
    ]);
}

function saveHold() {
    global $pdo, $business_id, $shop_id, $user_id;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        return;
    }
    
    // Calculate expiry (24 hours from now)
    $expiry_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    $sql = "INSERT INTO held_invoices (
                hold_number, reference, customer_name, customer_phone, 
                customer_gstin, shop_id, business_id, seller_id,
                subtotal, total, cart_items, cart_json, expiry_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['hold_number'],
            $data['reference'],
            $data['customer_name'],
            $data['customer_phone'] ?? '',
            $data['customer_gstin'] ?? '',
            $shop_id,
            $business_id,
            $user_id,
            $data['subtotal'],
            $data['total'],
            json_encode($data['cart_items']),
            json_encode($data['cart_json']),
            $expiry_at
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Invoice held successfully',
            'hold_id' => $pdo->lastInsertId()
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to save hold: ' . $e->getMessage()]);
    }
}

function listHolds() {
    global $pdo, $business_id, $shop_id, $user_id;
    
    $sql = "SELECT 
                h.*,
                COUNT(DISTINCT hi.id) as item_count,
                (SELECT COUNT(*) FROM held_invoices WHERE shop_id = ? AND business_id = ?) as total_count
            FROM held_invoices h
            LEFT JOIN JSON_TABLE(h.cart_items, '$[*]' COLUMNS(
                id INT PATH '$.product_id'
            )) hi ON 1=1
            WHERE h.shop_id = ? AND h.business_id = ?
            GROUP BY h.id
            ORDER BY h.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$shop_id, $business_id, $shop_id, $business_id]);
    $holds = $stmt->fetchAll();
    
    // Clean up expired holds
    cleanupExpiredHolds();
    
    echo json_encode([
        'success' => true,
        'holds' => $holds,
        'total' => count($holds)
    ]);
}

function getHold() {
    global $pdo, $business_id, $shop_id;
    
    $hold_id = $_GET['hold_id'] ?? 0;
    
    if (!$hold_id) {
        echo json_encode(['success' => false, 'message' => 'Hold ID required']);
        return;
    }
    
    $sql = "SELECT * FROM held_invoices 
            WHERE id = ? AND shop_id = ? AND business_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$hold_id, $shop_id, $business_id]);
    $hold = $stmt->fetch();
    
    if ($hold) {
        echo json_encode([
            'success' => true,
            'hold' => $hold
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Hold not found']);
    }
}

function cleanupExpiredHolds() {
    global $pdo, $business_id, $shop_id;
    
    $sql = "DELETE FROM held_invoices 
            WHERE expiry_at < NOW() 
            AND shop_id = ? 
            AND business_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$shop_id, $business_id]);
}

function deleteHold() {
    global $pdo, $business_id, $shop_id;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $hold_id = $data['hold_id'] ?? 0;
    
    if (!$hold_id) {
        echo json_encode(['success' => false, 'message' => 'Hold ID required']);
        return;
    }
    
    $sql = "DELETE FROM held_invoices 
            WHERE id = ? AND shop_id = ? AND business_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$hold_id, $shop_id, $business_id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Hold deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Hold not found or already deleted']);
    }
}
?>