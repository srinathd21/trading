<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

redirect_if_not_logged_in();

$shop_id = $_GET['shop_id'] ?? 0;

if ($shop_id) {
    if (switch_shop($pdo, $shop_id)) {
        set_flash_message('success', 'Switched to new shop successfully');
        
        // Redirect to previous page or dashboard
        $redirect = $_SESSION['redirect_after_shop'] ?? 'dashboard.php';
        unset($_SESSION['redirect_after_shop']);
        header("Location: $redirect");
        exit();
    } else {
        set_flash_message('error', 'Failed to switch shop. Access denied.');
        header("Location: dashboard.php");
        exit();
    }
} else {
    set_flash_message('error', 'Invalid shop ID');
    header("Location: dashboard.php");
    exit();
}
?>