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
            getNextQuotationNumber();
            break;
        case 'save':
            saveQuotation();
            break;
        case 'list':
            listQuotations();
            break;
        case 'get_items':
            getQuotationItems();
            break;
        case 'delete':
            deleteQuotation();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Quotations API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

function getNextQuotationNumber() {
    global $pdo, $business_id, $shop_id;
    
    $year_month = date('Ym');
    $prefix = 'QTN' . $year_month . '-';
    
    $sql = "SELECT quotation_number
            FROM quotations
            WHERE business_id = ?
              AND shop_id = ?
              AND quotation_number LIKE ?
            ORDER BY CAST(SUBSTRING_INDEX(quotation_number, '-', -1) AS UNSIGNED) DESC
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$business_id, $shop_id, $prefix . '%']);
    $last = $stmt->fetch();
    
    $seq = 1;
    if ($last && isset($last['quotation_number'])) {
        $last_num = (int)substr($last['quotation_number'], strlen($prefix));
        $seq = $last_num + 1;
    }
    
    $quotation_number = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    
    echo json_encode([
        'success' => true,
        'quotation_number' => $quotation_number
    ]);
}

function saveQuotation() {
    global $pdo, $business_id, $shop_id, $user_id;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // Insert quotation
        $sql = "INSERT INTO quotations (
                    business_id, shop_id, quotation_number, quotation_date, valid_until,
                    customer_name, customer_phone, customer_email, customer_address, customer_gstin,
                    subtotal, total_discount, total_tax, grand_total, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $business_id,
            $shop_id,
            $data['quotation_number'],
            $data['quotation_date'],
            $data['valid_until'],
            $data['customer_name'],
            $data['customer_phone'] ?? '',
            $data['customer_email'] ?? '',
            $data['customer_address'] ?? '',
            $data['customer_gstin'] ?? '',
            $data['subtotal'],
            $data['total_discount'] ?? 0,
            $data['total_tax'] ?? 0,
            $data['grand_total'],
            $data['notes'] ?? '',
            $user_id
        ]);
        
        $quotation_id = $pdo->lastInsertId();
        
        // Insert quotation items
        if (isset($data['items']) && is_array($data['items'])) {
            $item_sql = "INSERT INTO quotation_items (
                            quotation_id, product_id, product_name, quantity, unit_price,
                            discount_amount, discount_type, total_price, hsn_code,
                            cgst_rate, sgst_rate, igst_rate, tax_amount, price_type
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $item_stmt = $pdo->prepare($item_sql);
            
            foreach ($data['items'] as $item) {
                $item_stmt->execute([
                    $quotation_id,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['discount_amount'] ?? 0,
                    $item['discount_type'] ?? 'percent',
                    $item['total_price'],
                    $item['hsn_code'] ?? '',
                    $item['cgst_rate'] ?? 0,
                    $item['sgst_rate'] ?? 0,
                    $item['igst_rate'] ?? 0,
                    $item['tax_amount'] ?? 0,
                    $item['price_type'] ?? 'retail'
                ]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Quotation saved successfully',
            'quotation_id' => $quotation_id
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to save quotation: ' . $e->getMessage()]);
    }
}

function listQuotations() {
    global $pdo, $business_id, $shop_id;
    
    $sql = "SELECT 
                q.*,
                DATE_FORMAT(q.quotation_date, '%Y-%m-%d') as formatted_date,
                DATE_FORMAT(q.valid_until, '%Y-%m-%d') as formatted_valid_until,
                (SELECT COUNT(*) FROM quotations WHERE shop_id = ? AND business_id = ?) as total_count
            FROM quotations q
            WHERE q.shop_id = ? AND q.business_id = ?
            ORDER BY q.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$shop_id, $business_id, $shop_id, $business_id]);
    $quotations = $stmt->fetchAll();
    
    // Update expired status
    updateExpiredQuotations();
    
    echo json_encode([
        'success' => true,
        'quotations' => $quotations,
        'total' => count($quotations)
    ]);
}

function updateExpiredQuotations() {
    global $pdo, $business_id, $shop_id;
    
    $sql = "UPDATE quotations 
            SET status = 'expired' 
            WHERE valid_until < CURDATE() 
            AND status NOT IN ('accepted', 'rejected', 'expired')
            AND shop_id = ? 
            AND business_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$shop_id, $business_id]);
}

function getQuotationItems() {
    global $pdo;
    
    $quotation_id = $_GET['quotation_id'] ?? 0;
    
    if (!$quotation_id) {
        echo json_encode(['success' => false, 'message' => 'Quotation ID required']);
        return;
    }
    
    $sql = "SELECT * FROM quotation_items WHERE quotation_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$quotation_id]);
    $items = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'items' => $items
    ]);
}

function deleteQuotation() {
    global $pdo, $business_id, $shop_id;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $quotation_id = $data['quotation_id'] ?? 0;
    
    if (!$quotation_id) {
        echo json_encode(['success' => false, 'message' => 'Quotation ID required']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // Delete quotation items first
        $delete_items_sql = "DELETE FROM quotation_items WHERE quotation_id = ?";
        $delete_items_stmt = $pdo->prepare($delete_items_sql);
        $delete_items_stmt->execute([$quotation_id]);
        
        // Delete quotation
        $delete_sql = "DELETE FROM quotations WHERE id = ? AND shop_id = ? AND business_id = ?";
        $delete_stmt = $pdo->prepare($delete_sql);
        $delete_stmt->execute([$quotation_id, $shop_id, $business_id]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Quotation deleted successfully'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to delete quotation: ' . $e->getMessage()]);
    }
}
?>