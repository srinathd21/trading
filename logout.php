<?php
// logout.php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Log activity (optional)
if (isset($_SESSION['user_id'])) {
    // You can log logout time here if needed
}

// Destroy all session data
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Redirect to login
header("Location: login.php");
exit();
?>