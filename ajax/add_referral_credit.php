<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$referral_id = (int)$_POST['referral_id'];
$amount = (float)$_POST['amount'];
$description = trim($_POST['description']);
$payment_method = $_POST['payment_method'];
$payment_reference = trim($_POST['payment_reference'] ?? '');
$business_id = (int)$_POST['business_id'];

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
    exit();
}

if (empty($description)) {
    echo json_encode(['success' => false, 'message' => 'Description is required']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Update referral person's credit
    $update_stmt = $pdo->prepare("
        UPDATE referral_person 
        SET credit_amount = credit_amount + ?, 
            available_credit = available_credit + ?,
            updated_at = NOW()
        WHERE id = ? AND business_id = ?
    ");
    $update_stmt->execute([$amount, $amount, $referral_id, $business_id]);
    
    // Record transaction
    $transaction_stmt = $pdo->prepare("
        INSERT INTO referral_transactions (
            referral_id, business_id, transaction_type, amount, 
            description, payment_method, payment_reference, status, created_at
        ) VALUES (?, ?, 'credit', ?, ?, ?, ?, 'completed', NOW())
    ");
    
    $transaction_stmt->execute([
        $referral_id,
        $business_id,
        $amount,
        $description,
        $payment_method,
        $payment_reference
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Credit added successfully'
    ]);
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}