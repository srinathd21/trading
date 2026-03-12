<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$manufacturer_id = (int)($_POST['manufacturer_id'] ?? 0);
$business_id = $_SESSION['business_id'];

if (!$manufacturer_id) {
    echo json_encode(['success' => false, 'error' => 'Manufacturer ID required']);
    exit();
}

try {
    // Get pending purchases
    $stmt = $pdo->prepare("
        SELECT id, purchase_number, purchase_date, total_amount, paid_amount,
               (total_amount - paid_amount) as balance_due
        FROM purchases 
        WHERE manufacturer_id = ? AND business_id = ? 
              AND payment_status IN ('pending', 'partial')
        ORDER BY purchase_date ASC
    ");
    $stmt->execute([$manufacturer_id, $business_id]);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get manufacturer outstanding
    $stmt = $pdo->prepare("SELECT initial_outstanding_type, initial_outstanding_amount FROM manufacturers WHERE id = ? AND business_id = ?");
    $stmt->execute([$manufacturer_id, $business_id]);
    $manufacturer = $stmt->fetch(PDO::FETCH_ASSOC);

    $total_due = 0;
    foreach ($purchases as $purchase) {
        $total_due += $purchase['balance_due'];
    }
    
    $outstanding_amount = $manufacturer ? (float)$manufacturer['initial_outstanding_amount'] : 0;
    $outstanding_type = $manufacturer ? $manufacturer['initial_outstanding_type'] : 'none';

    echo json_encode([
        'success' => true,
        'purchases' => $purchases,
        'total_due' => $total_due,
        'outstanding' => [
            'type' => $outstanding_type,
            'amount' => $outstanding_amount
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>