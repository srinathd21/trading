<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'];

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manufacturer_id = (int)($_POST['manufacturer_id'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $amount = (float)($_POST['amount'] ?? 0);
    $reference_no = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $outstanding_amount = (float)($_POST['outstanding_amount'] ?? 0);
    
    // Get selected purchases from POST (they come as array)
    $selected_purchases = $_POST['purchases'] ?? [];
    if (!is_array($selected_purchases)) {
        $selected_purchases = [];
    }
    
    if (!$manufacturer_id) {
        $response['message'] = 'Manufacturer ID is required';
        echo json_encode($response);
        exit();
    }
    
    if ($amount <= 0) {
        $response['message'] = 'Amount must be greater than 0';
        echo json_encode($response);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Verify manufacturer belongs to this business
        $stmt = $pdo->prepare("SELECT id, name, initial_outstanding_type, initial_outstanding_amount FROM manufacturers WHERE id = ? AND business_id = ?");
        $stmt->execute([$manufacturer_id, $business_id]);
        $manufacturer = $stmt->fetch();
        
        if (!$manufacturer) {
            throw new Exception('Manufacturer not found');
        }
        
        $outstanding_type = $manufacturer['initial_outstanding_type'];
        $current_outstanding = (float)$manufacturer['initial_outstanding_amount'];
        $remaining_amount = $amount;
        
        // Handle outstanding payment if this is outstanding-only or has outstanding portion
        if ($outstanding_amount > 0 || (empty($selected_purchases) && $outstanding_type === 'debit' && $current_outstanding > 0)) {
            // If outstanding_amount not specified but this is outstanding-only, use full amount
            if ($outstanding_amount == 0 && empty($selected_purchases)) {
                $outstanding_amount = $amount;
            }
            
            if ($outstanding_amount > 0) {
                if ($outstanding_type !== 'debit') {
                    throw new Exception('Cannot pay outstanding: No debit balance exists');
                }
                
                if ($outstanding_amount > $current_outstanding) {
                    throw new Exception('Outstanding amount exceeds current outstanding balance');
                }
                
                // Record outstanding payment in payments table
                $stmt = $pdo->prepare("INSERT INTO payments 
                    (payment_date, type, reference_id, amount, payment_method, reference_no, recorded_by, notes, created_at)
                    VALUES (?, 'supplier_outstanding', ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $payment_date,
                    $manufacturer_id,
                    $outstanding_amount,
                    $payment_method,
                    $reference_no,
                    $user_id,
                    $notes . (empty($selected_purchases) ? ' (Outstanding Payment)' : ' (Part of bulk payment)')
                ]);
                
                $payment_id = $pdo->lastInsertId();
                
                // Update manufacturer outstanding
                $new_outstanding = $current_outstanding - $outstanding_amount;
                if ($new_outstanding < 0) $new_outstanding = 0;
                
                $stmt = $pdo->prepare("UPDATE manufacturers SET initial_outstanding_amount = ? WHERE id = ?");
                $stmt->execute([$new_outstanding, $manufacturer_id]);
                
                if ($new_outstanding <= 0) {
                    $stmt = $pdo->prepare("UPDATE manufacturers SET initial_outstanding_type = 'none' WHERE id = ?");
                    $stmt->execute([$manufacturer_id]);
                }
                
                // Add to outstanding history
                $stmt = $pdo->prepare("
                    INSERT INTO manufacturer_outstanding_history 
                    (manufacturer_id, date, type, amount, balance_after, reference_id, reference_no, notes, created_by, created_at) 
                    VALUES (?, ?, 'payment_made', ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $manufacturer_id,
                    $payment_date,
                    $outstanding_amount,
                    $new_outstanding,
                    $payment_id,
                    $reference_no,
                    'Payment made towards outstanding' . (empty($selected_purchases) ? '' : ' (part of bulk payment)'),
                    $user_id
                ]);
                
                $remaining_amount -= $outstanding_amount;
            }
        }
        
        // Handle purchase payments if there are selected purchases and remaining amount
        if (!empty($selected_purchases) && $remaining_amount > 0) {
            // Get selected purchases with their balances
            $placeholders = implode(',', array_fill(0, count($selected_purchases), '?'));
            $stmt = $pdo->prepare("
                SELECT id, purchase_number, total_amount, paid_amount, 
                       (total_amount - paid_amount) as balance_due
                FROM purchases 
                WHERE id IN ($placeholders) AND manufacturer_id = ? AND business_id = ?
                ORDER BY purchase_date ASC
            ");
            $params = array_merge($selected_purchases, [$manufacturer_id, $business_id]);
            $stmt->execute($params);
            $purchases = $stmt->fetchAll();
            
            $total_due = array_sum(array_column($purchases, 'balance_due'));
            
            if ($remaining_amount > $total_due) {
                throw new Exception('Payment amount exceeds total due amount');
            }
            
            // Distribute payment across selected purchases
            $remaining_payment = $remaining_amount;
            
            foreach ($purchases as $purchase) {
                if ($remaining_payment <= 0) break;
                
                $balance_due = $purchase['balance_due'];
                $payment_for_this = min($balance_due, $remaining_payment);
                
                if ($payment_for_this > 0) {
                    // Record payment in payments table
                    $stmt = $pdo->prepare("INSERT INTO payments 
                        (payment_date, type, reference_id, amount, payment_method, reference_no, recorded_by, notes, created_at)
                        VALUES (?, 'supplier', ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $payment_date,
                        $purchase['id'],
                        $payment_for_this,
                        $payment_method,
                        $reference_no,
                        $user_id,
                        $notes . ' (Part of bulk payment)'
                    ]);
                    
                    // Update purchase paid amount and status
                    $new_paid = $purchase['paid_amount'] + $payment_for_this;
                    $status = ($new_paid >= $purchase['total_amount']) ? 'paid' : 'partial';
                    
                    $stmt = $pdo->prepare("UPDATE purchases SET paid_amount = ?, payment_status = ? WHERE id = ?");
                    $stmt->execute([$new_paid, $status, $purchase['id']]);
                    
                    $remaining_payment -= $payment_for_this;
                }
            }
        }
        
        $pdo->commit();
        
        $response['success'] = true;
        $response['message'] = 'Payment processed successfully';
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = 'Error: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}
?>