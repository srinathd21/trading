<?php
// api/gst_rates.php
require_once '../includes/auth.php';
require_once '../config/database.php';

checkAuth();

$business_id = getBusinessId();

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            hsn_code,
            COALESCE(description, '') AS description,
            cgst_rate,
            sgst_rate,
            igst_rate,
            (cgst_rate + sgst_rate + igst_rate) AS total_gst
        FROM gst_rates
        WHERE business_id = ?
          AND status = 'active'
        ORDER BY hsn_code ASC
    ");
    $stmt->execute([$business_id]);

    echo json_encode([
        'success' => true,
        'business_id' => $business_id,
        'rates' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load GST rates: ' . $e->getMessage()
    ]);
}
