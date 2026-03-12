<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['exists' => false, 'message' => 'Unauthorized']);
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$current_customer_id = isset($_POST['current_customer_id']) ? (int)$_POST['current_customer_id'] : 0;

$response = ['exists' => false, 'message' => ''];

if (!empty($phone)) {
    if ($current_customer_id > 0) {
        // For edit: check if phone exists for another customer
        $sql = "SELECT id, name FROM customers WHERE business_id = ? AND phone = ? AND id != ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$business_id, $phone, $current_customer_id]);
    } else {
        // For add: check if phone exists for any customer
        $sql = "SELECT id, name FROM customers WHERE business_id = ? AND phone = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$business_id, $phone]);
    }
    
    $existing = $stmt->fetch();
    if ($existing) {
        $response['exists'] = true;
        $response['message'] = "Customer with phone number '$phone' already exists in this business! (Customer: " . $existing['name'] . ")";
        echo json_encode($response);
        exit();
    }
}

if (!empty($email)) {
    if ($current_customer_id > 0) {
        // For edit: check if email exists for another customer
        $sql = "SELECT id, name FROM customers WHERE business_id = ? AND email = ? AND id != ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$business_id, $email, $current_customer_id]);
    } else {
        // For add: check if email exists for any customer
        $sql = "SELECT id, name FROM customers WHERE business_id = ? AND email = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$business_id, $email]);
    }
    
    $existing = $stmt->fetch();
    if ($existing) {
        $response['exists'] = true;
        $response['message'] = "Customer with email '$email' already exists in this business! (Customer: " . $existing['name'] . ")";
        echo json_encode($response);
        exit();
    }
}

echo json_encode($response);
?>