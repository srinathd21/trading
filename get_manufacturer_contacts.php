<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['manufacturer_id'])) {
    echo json_encode([]);
    exit();
}

$manufacturer_id = (int)$_POST['manufacturer_id'];
$business_id = $_SESSION['business_id'];

// Verify manufacturer belongs to this business
$stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE id = ? AND business_id = ?");
$stmt->execute([$manufacturer_id, $business_id]);
if (!$stmt->fetch()) {
    echo json_encode([]);
    exit();
}

// Get contacts
$stmt = $pdo->prepare("
    SELECT contact_person, designation, phone, mobile, email, is_primary 
    FROM manufacturer_contacts 
    WHERE manufacturer_id = ? 
    ORDER BY is_primary DESC, id ASC
");
$stmt->execute([$manufacturer_id]);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($contacts);
?>