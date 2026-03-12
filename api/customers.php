<?php
// api/customers.php
require_once '../includes/auth.php';
require_once '../config/database.php';

checkAuth();
$business_id = getBusinessId();

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            getCustomers();
            break;
        case 'search':
            searchCustomers();
            break;
        case 'create':
            createCustomer();
            break;
        case 'credit_check':
    checkCustomerCredit();
    break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Customers API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

function getCustomers() {
    global $pdo, $business_id;
    
    $sql = "SELECT id, name, phone, email, address, gstin, 
                   customer_type, credit_limit, outstanding_amount
            FROM customers 
            WHERE business_id = ?
            ORDER BY name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$business_id]);
    $customers = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'customers' => $customers]);
}

function searchCustomers() {
    global $pdo, $business_id;
    
    $search = $_GET['q'] ?? '';
    if (empty($search)) {
        echo json_encode(['success' => true, 'customers' => []]);
        return;
    }
    
    $sql = "SELECT id, name, phone, email, address, gstin
            FROM customers 
            WHERE business_id = ?
            AND (name LIKE ? OR phone LIKE ?)
            ORDER BY name
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $searchTerm = "%{$search}%";
    $stmt->execute([$business_id, $searchTerm, $searchTerm]);
    $customers = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'customers' => $customers]);
}
function checkCustomerCredit() {
    global $pdo, $business_id;
    
    $customer_id = $_GET['customer_id'] ?? 0;
    $amount = $_GET['amount'] ?? 0;
    
    if (!$customer_id) {
        echo json_encode(['success' => false, 'message' => 'Customer ID required']);
        return;
    }
    
    $sql = "SELECT credit_limit, outstanding_amount 
            FROM customers 
            WHERE id = ? AND business_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customer_id, $business_id]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        return;
    }
    
    $creditLimit = $customer['credit_limit'] ?? 0;
    $outstanding = $customer['outstanding_amount'] ?? 0;
    $availableCredit = $creditLimit - $outstanding;
    
    echo json_encode([
        'success' => true,
        'has_credit_limit' => $creditLimit > 0,
        'credit_limit' => $creditLimit,
        'outstanding_amount' => $outstanding,
        'available_credit' => $availableCredit,
        'can_proceed' => $amount <= $availableCredit || $creditLimit == 0
    ]);
}
function createCustomer() {
    global $pdo, $business_id;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['name'])) {
        echo json_encode(['success' => false, 'message' => 'Customer name is required']);
        return;
    }
    
    $sql = "INSERT INTO customers (business_id, name, phone, email, address, gstin, customer_type)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $business_id,
            $data['name'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['gstin'] ?? null,
            $data['customer_type'] ?? 'retail'
        ]);
        
        $customer_id = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'customer_id' => $customer_id,
            'message' => 'Customer created successfully'
        ]);
    } catch (PDOException $e) {
        error_log("Create Customer Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to create customer']);
    }
}
?>