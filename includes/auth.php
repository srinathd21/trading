<?php
// includes/auth.php
session_start();

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit();
    }
    return $_SESSION['user_id'];
}

function getBusinessId() {
    return $_SESSION['current_business_id'] ?? 1;
}

function getShopId() {
    return $_SESSION['current_shop_id'] ?? 1;
}

function getUserId() {
    return $_SESSION['user_id'] ?? 1;
}

function getUserRole() {
    return $_SESSION['role'] ?? 'sales';
}
?>