<?php
// config/database.php
// Indian Standard Time (IST) + Proper PDO Connection

$host     = 'localhost';
$dbname   = 'u329947844_traders';  // Change only if different
$username = 'u329947844_traders';                        // Change for live server
$password = 'Hifi11@25';                            // Change for live server

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, $options);

    // Force Indian Standard Time (UTC +05:30) for ALL queries
    $pdo->exec("SET time_zone = '+05:30';");

    // Optional: Ensure dates are formatted in Indian style
    $pdo->exec("SET lc_time_names = 'en_IN';");

} catch (PDOException $e) {
    // Hide error details in production
    die("Database connection failed. Please try again later.");
    // For development only: die($e->getMessage());
}
?>