<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Allow all logged-in users to add/edit customers
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gstin = strtoupper(trim($_POST['gstin'] ?? ''));

    if (empty($name)) {
        $error = "Customer name is required.";
    } else {
        try {
            if ($id > 0) {
                // Update existing customer
                $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, gstin = ? WHERE id = ?");
                $stmt->execute([$name, $phone, $email, $address, $gstin, $id]);
                $success = "Customer updated successfully!";
            } else {
                // Add new customer
                $stmt = $pdo->prepare("INSERT INTO customers (name, phone, email, address, gstin, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $phone, $email, $address, $gstin]);
                $success = "Customer added successfully!";
            }
        } catch (PDOException $e) {
            $error = "Failed to save customer. Phone or GSTIN may already exist.";
        }
    }
}

// Redirect back with message
$redirect = "customers.php";
if ($success) {
    $redirect .= "?success=" . urlencode($success);
} elseif ($error) {
    $redirect .= "?error=" . urlencode($error);
}

header("Location: $redirect");
exit();
?>