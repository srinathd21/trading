<?php
session_start();
require_once 'config/database.php';

// Clear remember me token from database
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (Exception $e) {
        // Log error but continue with logout
    }
}

// Clear cookie
setcookie('remember_token', '', time() - 3600, '/');

// Destroy session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>