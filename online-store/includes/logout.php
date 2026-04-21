<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['customer_id']);
unset($_SESSION['online_customer_id']);
unset($_SESSION['storefront_customer_id']);
unset($_SESSION['customer_name']);
unset($_SESSION['customer_email']);
unset($_SESSION['customer_phone']);
unset($_SESSION['customer_is_online']);

header('Location: storefront.php?slug=' . urlencode($_GET['slug'] ?? '') . '&page=login');
exit();