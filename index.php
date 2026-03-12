<?php
// index.php - Main entry point
date_default_timezone_set('Asia/Kolkata');   // Indian Standard Time
session_start();

// Include DB & functions
require_once 'config/database.php';
require_once 'includes/functions.php';        // Create this file if not exists

// If user is already logged in → go to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// If not logged in → go to login page
header("Location: login.php");
exit();
?>