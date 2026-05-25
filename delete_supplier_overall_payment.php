<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager'])) {
    header('Location: dashboard.php');
    exit();
}

$overall_payment_id = (int)($_GET['id'] ?? 0);
$return_purchase_id = (int)($_GET['purchase_id'] ?? 0);

if (!$overall_payment_id) {
    header('Location: purchases.php');
    exit();
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT *
        FROM supplier_overall_payments
        WHERE id = ? AND is_deleted = 0
        FOR UPDATE
    ");
    $stmt->execute([$overall_payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        throw new Exception("Overall payment not found or already deleted.");
    }

    $business_id = $payment['business_id'];
    $manufacturer_id = $payment['manufacturer_id'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM supplier_overall_payment_allocations
        WHERE overall_payment_id = ?
          AND is_deleted = 0
        ORDER BY id DESC
    ");
    $stmt->execute([$overall_payment_id]);
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allocations as $allocation) {
        if ($allocation['allocation_type'] === 'purchase_order' && !empty($allocation['purchase_id'])) {
            // Restore PO paid amount and status
            $pdo->prepare("
                UPDATE purchases
                SET paid_amount = ?,
                    payment_status = ?
                WHERE id = ?
            ")->execute([
                $allocation['po_paid_before'],
                $allocation['po_status_before'],
                $allocation['purchase_id']
            ]);
        }

        if ($allocation['allocation_type'] === 'outstanding') {
            // Restore manufacturer outstanding
            $pdo->prepare("
                UPDATE manufacturers
                SET initial_outstanding_amount = ?,
                    initial_outstanding_type = ?
                WHERE id = ?
            ")->execute([
                $allocation['outstanding_before'],
                $allocation['outstanding_type_before'],
                $manufacturer_id
            ]);
        }
    }

    // Soft delete allocation rows
    $pdo->prepare("
        UPDATE supplier_overall_payment_allocations
        SET is_deleted = 1
        WHERE overall_payment_id = ?
    ")->execute([$overall_payment_id]);

    // Soft delete parent payment
    $pdo->prepare("
        UPDATE supplier_overall_payments
        SET is_deleted = 1,
            deleted_at = NOW(),
            deleted_by = ?,
            delete_reason = ?
        WHERE id = ?
    ")->execute([
        $_SESSION['user_id'],
        'Deleted from supplier overall payment history',
        $overall_payment_id
    ]);

    // Activity log
    $log_details = json_encode([
        'overall_payment_id' => (int)$overall_payment_id,
        'manufacturer_id' => (int)$manufacturer_id,
        'amount' => (float)$payment['amount'],
        'restored_allocations' => $allocations
    ]);

    $pdo->prepare("
        INSERT INTO activity_logs
        (user_id, business_id, action, details)
        VALUES (?, ?, 'supplier_overall_payment_deleted', ?)
    ")->execute([
        $_SESSION['user_id'],
        $business_id,
        $log_details
    ]);

    $pdo->commit();

    if ($return_purchase_id) {
        header("Location: purchase_payment.php?id=" . $return_purchase_id . "&success=overall_deleted");
    } else {
        header("Location: purchases.php?success=overall_deleted");
    }
    exit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($return_purchase_id) {
        header("Location: purchase_payment.php?id=" . $return_purchase_id . "&error=" . urlencode($e->getMessage()));
    } else {
        header("Location: purchases.php?error=" . urlencode($e->getMessage()));
    }
    exit();
}