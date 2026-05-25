<?php 
// collect_payment.php 
date_default_timezone_set('Asia/Kolkata'); 
session_start(); 
require_once 'config/database.php'; 

if (!isset($_SESSION['user_id'])) { 
    header('Location: login.php'); 
    exit(); 
} 

$user_id = (int)($_SESSION['user_id'] ?? 0); 
$business_id = (int)($_SESSION['current_business_id'] ?? $_SESSION['business_id'] ?? 1); 
$invoice_id = (int)($_GET['invoice_id'] ?? 0); 

if ($invoice_id <= 0) { 
    $_SESSION['error'] = "Invalid invoice selected."; 
    header('Location: invoices.php'); 
    exit(); 
} 

$error = ''; 

try { 
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); 
} catch (Exception $e) { 
    // ignore if already set 
} 

function writeActivityLog(PDO $pdo, int $business_id, int $user_id, string $action, string $description, array $details = []): void
{
    try {
        $columnsStmt = $pdo->query("SHOW COLUMNS FROM activity_logs");
        $available = [];
        foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $available[$col['Field']] = true;
        }

        $data = [];
        $candidates = [
            'business_id' => $business_id,
            'user_id' => $user_id,
            'created_by' => $user_id,
            'action' => $action,
            'activity_type' => $action,
            'module' => 'collect_payment',
            'entity_type' => 'invoice_payment',
            'description' => $description,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'activity_details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];

        foreach ($candidates as $col => $value) {
            if (isset($available[$col])) {
                $data[$col] = $value;
            }
        }

        if (empty($data)) {
            return;
        }

        $cols = array_keys($data);
        $sql = "INSERT INTO activity_logs (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
    } catch (Exception $e) {
        error_log('Activity log failed: ' . $e->getMessage());
    }
}

function computeInvoicePaymentStatus($total, $paid) {
    $pending = max(0, round((float)$total - (float)$paid, 2));
    if ($pending <= 0.01) {
        return ['paid', 0.00];
    }
    if ((float)$paid > 0) {
        return ['partial', $pending];
    }
    return ['pending', $pending];
}

function normalizeInvoicePaymentState(array $invoice) { 
    $total = round(max(0, (float)($invoice['total'] ?? 0)), 2); 
    $stored_paid = round(max(0, (float)($invoice['paid_amount'] ?? 0)), 2); 
    $stored_pending = round(max(0, (float)($invoice['pending_amount'] ?? 0)), 2); 
    
    if ($stored_pending > 0 && $stored_pending <= $total) { 
        $effective_pending = $stored_pending; 
        $effective_paid = round(max(0, $total - $effective_pending), 2); 
    } else { 
        $effective_paid = round(min($total, $stored_paid), 2); 
        $effective_pending = round(max(0, $total - $effective_paid), 2); 
    } 
    
    if ($effective_pending <= 0) { 
        $effective_pending = 0.00; 
        $effective_status = 'paid'; 
    } elseif ($effective_paid > 0) { 
        $effective_status = 'partial'; 
    } else { 
        $effective_status = 'pending'; 
    } 
    
    return [ 
        'total' => $total, 
        'paid' => $effective_paid, 
        'pending' => $effective_pending, 
        'status' => $effective_status 
    ]; 
} 

// Fetch invoice details 
$stmt = $pdo->prepare(" 
    SELECT i.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address 
    FROM invoices i 
    LEFT JOIN customers c ON i.customer_id = c.id 
    WHERE i.id = ? AND i.business_id = ? 
    LIMIT 1 
"); 
$stmt->execute([$invoice_id, $business_id]); 
$invoice = $stmt->fetch(PDO::FETCH_ASSOC); 

if (!$invoice) { 
    $_SESSION['error'] = "Invoice not found!"; 
    header('Location: invoices.php'); 
    exit(); 
} 

// Soft delete payment and restore invoice balance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_payment') {
    $delete_payment_id = (int)($_POST['payment_id'] ?? 0);
    $delete_reason = trim($_POST['delete_reason'] ?? 'Payment deleted from collect payment page');

    try {
        if ($delete_payment_id <= 0) {
            throw new Exception("Invalid payment selected for delete.");
        }

        $pdo->beginTransaction();

        $paymentStmt = $pdo->prepare("
            SELECT * FROM customer_payments
            WHERE id = ? AND business_id = ? AND invoice_id = ? AND is_deleted = 0
            FOR UPDATE
        ");
        $paymentStmt->execute([$delete_payment_id, $business_id, $invoice_id]);
        $paymentRow = $paymentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$paymentRow) {
            throw new Exception("Payment record not found or already deleted.");
        }

        $allocationStmt = $pdo->prepare("
            SELECT * FROM customer_payment_allocations
            WHERE payment_id = ? AND business_id = ? AND is_deleted = 0
            ORDER BY id ASC
            FOR UPDATE
        ");
        $allocationStmt->execute([$delete_payment_id, $business_id]);
        $allocations = $allocationStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($allocations)) {
            throw new Exception("No allocation records found. Cannot safely restore this payment.");
        }

        $restoredDetails = [];
        foreach ($allocations as $allocation) {
            $allocatedAmount = round((float)($allocation['allocated_amount'] ?? 0), 2);

            if (($allocation['allocation_type'] ?? '') === 'invoice' && !empty($allocation['invoice_id']) && $allocatedAmount > 0) {
                $invLock = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE");
                $invLock->execute([(int)$allocation['invoice_id'], $business_id]);
                $lockedInvoice = $invLock->fetch(PDO::FETCH_ASSOC);

                if (!$lockedInvoice) {
                    throw new Exception("Invoice not found while restoring payment allocation.");
                }

                $currentPaid = round((float)($lockedInvoice['paid_amount'] ?? 0), 2);
                $newPaidAfterDelete = round(max(0, $currentPaid - $allocatedAmount), 2);
                [$restoredStatus, $restoredPending] = computeInvoicePaymentStatus((float)$lockedInvoice['total'], $newPaidAfterDelete);

                $method = $paymentRow['payment_method'] ?? '';
                $cashReverse = $method === 'cash' ? $allocatedAmount : 0;
                $upiReverse = $method === 'upi' ? $allocatedAmount : 0;
                $bankReverse = $method === 'bank' ? $allocatedAmount : 0;
                $chequeReverse = $method === 'cheque' ? $allocatedAmount : 0;
                $creditReverse = $method === 'credit_card' ? $allocatedAmount : 0;

                $restoreStmt = $pdo->prepare("
                    UPDATE invoices
                    SET paid_amount = ?,
                        pending_amount = ?,
                        payment_status = ?,
                        cash_amount = GREATEST(COALESCE(cash_amount, 0) - ?, 0),
                        upi_amount = GREATEST(COALESCE(upi_amount, 0) - ?, 0),
                        bank_amount = GREATEST(COALESCE(bank_amount, 0) - ?, 0),
                        cheque_amount = GREATEST(COALESCE(cheque_amount, 0) - ?, 0),
                        credit_amount = GREATEST(COALESCE(credit_amount, 0) - ?, 0),
                        updated_at = NOW()
                    WHERE id = ? AND business_id = ?
                ");
                $restoreStmt->execute([
                    $newPaidAfterDelete,
                    $restoredPending,
                    $restoredStatus,
                    $cashReverse,
                    $upiReverse,
                    $bankReverse,
                    $chequeReverse,
                    $creditReverse,
                    (int)$allocation['invoice_id'],
                    $business_id
                ]);

                $restoredDetails[] = [
                    'invoice_id' => (int)$allocation['invoice_id'],
                    'invoice_number' => $allocation['invoice_number'] ?? $lockedInvoice['invoice_number'],
                    'removed_amount' => $allocatedAmount,
                    'paid_before_delete' => $currentPaid,
                    'paid_after_delete' => $newPaidAfterDelete,
                    'pending_after_delete' => $restoredPending,
                    'status_after_delete' => $restoredStatus
                ];
            }
        }

        $softDeleteInvoicePayments = $pdo->prepare("
            UPDATE invoice_payments
            SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, delete_reason = ?
            WHERE customer_payment_id = ? AND business_id = ? AND is_deleted = 0
        ");
        $softDeleteInvoicePayments->execute([$user_id, $delete_reason, $delete_payment_id, $business_id]);

        $softDeleteAllocations = $pdo->prepare("
            UPDATE customer_payment_allocations
            SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, delete_reason = ?
            WHERE payment_id = ? AND business_id = ? AND is_deleted = 0
        ");
        $softDeleteAllocations->execute([$user_id, $delete_reason, $delete_payment_id, $business_id]);

        $softDeletePayment = $pdo->prepare("
            UPDATE customer_payments
            SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, delete_reason = ?
            WHERE id = ? AND business_id = ? AND is_deleted = 0
        ");
        $softDeletePayment->execute([$user_id, $delete_reason, $delete_payment_id, $business_id]);

        writeActivityLog($pdo, $business_id, $user_id, 'invoice_payment_deleted', 'Invoice payment soft deleted and invoice balance restored', [
            'payment_id' => $delete_payment_id,
            'invoice_id' => $invoice_id,
            'customer_id' => $paymentRow['customer_id'] ?? null,
            'amount' => $paymentRow['total_amount'] ?? null,
            'reason' => $delete_reason,
            'restored' => $restoredDetails
        ]);

        $pdo->commit();
        $_SESSION['success'] = "Payment deleted successfully and invoice balance restored.";
        header("Location: collect_payment.php?invoice_id=$invoice_id");
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = "Failed to delete payment: " . $e->getMessage();
        header("Location: collect_payment.php?invoice_id=$invoice_id");
        exit();
    }
}

$invoiceState = normalizeInvoicePaymentState($invoice); 
$total_amount = $invoiceState['total']; 
$paid_amount = $invoiceState['paid']; 
$pending_amount = $invoiceState['pending']; 
$balance_amount = $invoiceState['pending']; 

// Process payment 
if ($_SERVER['REQUEST_METHOD'] === 'POST') { 
    $payment_amount = round((float)($_POST['payment_amount'] ?? 0), 2); 
    $payment_method = trim($_POST['payment_method'] ?? 'cash'); 
    $reference = trim($_POST['reference'] ?? ''); 
    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d')); 
    $notes = trim($_POST['notes'] ?? ''); 
    
    $allowed_methods = ['cash', 'upi', 'bank', 'cheque', 'credit_card', 'other']; 
    if (!in_array($payment_method, $allowed_methods, true)) { 
        $payment_method = 'cash'; 
    } 
    
    if ($payment_amount <= 0 || $payment_amount > $balance_amount) { 
        $error = "Invalid payment amount. Must be between ₹0.01 and ₹" . number_format($balance_amount, 2); 
    } else { 
        try { 
            $pdo->beginTransaction(); 
            
            // Lock invoice row 
            $lockStmt = $pdo->prepare(" 
                SELECT * FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE 
            "); 
            $lockStmt->execute([$invoice_id, $business_id]); 
            $liveInvoice = $lockStmt->fetch(PDO::FETCH_ASSOC); 
            
            if (!$liveInvoice) { 
                throw new Exception("Invoice not found during update."); 
            } 
            
            $liveState = normalizeInvoicePaymentState($liveInvoice); 
            $live_total = $liveState['total']; 
            $live_paid = $liveState['paid']; 
            $live_pending = $liveState['pending']; 
            $live_balance = $liveState['pending']; 
            
            if ($live_balance <= 0) { 
                throw new Exception("This invoice is already fully paid."); 
            } 
            
            if ($payment_amount > $live_balance) { 
                throw new Exception("Payment amount cannot be greater than pending amount (₹" . number_format($live_balance, 2) . ")."); 
            } 
            
            $cash_amount = $payment_method === 'cash' ? $payment_amount : 0; 
            $upi_amount = $payment_method === 'upi' ? $payment_amount : 0; 
            $bank_amount = $payment_method === 'bank' ? $payment_amount : 0; 
            $cheque_amount = $payment_method === 'cheque' ? $payment_amount : 0; 
            $credit_card_amount = $payment_method === 'credit_card' ? $payment_amount : 0; 
            
            $upi_reference = $payment_method === 'upi' ? ($reference !== '' ? $reference : null) : null; 
            $bank_reference = $payment_method === 'bank' ? ($reference !== '' ? $reference : null) : null; 
            $cheque_number = $payment_method === 'cheque' ? ($reference !== '' ? $reference : null) : null; 
            $credit_reference = $payment_method === 'credit_card' ? ($reference !== '' ? $reference : null) : null; 
            
            $new_pending = round(max(0, $live_pending - $payment_amount), 2); 
            $new_paid = round($live_total - $new_pending, 2); 
            
            if ($new_pending <= 0) { 
                $new_pending = 0.00; 
                $new_status = 'paid'; 
            } elseif ($new_paid > 0) { 
                $new_status = 'partial'; 
            } else { 
                $new_status = 'pending'; 
            } 
            
            $updateStmt = $pdo->prepare(" 
                UPDATE invoices 
                SET paid_amount = ?, pending_amount = ?, payment_status = ?, 
                    cash_amount = COALESCE(cash_amount, 0) + ?, 
                    upi_amount = COALESCE(upi_amount, 0) + ?, 
                    bank_amount = COALESCE(bank_amount, 0) + ?, 
                    cheque_amount = COALESCE(cheque_amount, 0) + ?, 
                    credit_amount = COALESCE(credit_amount, 0) + ?, 
                    upi_reference = CASE WHEN ? IS NOT NULL THEN ? ELSE upi_reference END, 
                    bank_reference = CASE WHEN ? IS NOT NULL THEN ? ELSE bank_reference END, 
                    cheque_number = CASE WHEN ? IS NOT NULL THEN ? ELSE cheque_number END, 
                    credit_reference = CASE WHEN ? IS NOT NULL THEN ? ELSE credit_reference END, 
                    payment_method = ?, updated_at = NOW() 
                WHERE id = ? AND business_id = ? 
            "); 
            
            $updateStmt->execute([ 
                $new_paid, $new_pending, $new_status, 
                $cash_amount, $upi_amount, $bank_amount, $cheque_amount, $credit_card_amount, 
                $upi_reference, $upi_reference, 
                $bank_reference, $bank_reference, 
                $cheque_number, $cheque_number, 
                $credit_reference, $credit_reference, 
                $payment_method, $invoice_id, $business_id 
            ]); 
            
            // Store summary row for customer ledger first, so invoice payment and allocation can reference it
            $summaryStmt = $pdo->prepare(" 
                INSERT INTO customer_payments (business_id, customer_id, invoice_id, total_amount, payment_method, reference_no, payment_date, notes, created_by, created_at, is_deleted) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0) 
            "); 
            $summaryStmt->execute([ 
                $business_id, $liveInvoice['customer_id'] ?? null, $invoice_id, 
                $payment_amount, $payment_method, 
                $reference !== '' ? $reference : null, 
                $payment_date, $notes !== '' ? $notes : null, 
                $user_id 
            ]);
            $customer_payment_id = (int)$pdo->lastInsertId();

            // Record payment in invoice_payments
            $paymentStmt = $pdo->prepare(" 
                INSERT INTO invoice_payments (business_id, invoice_id, customer_id, customer_payment_id, payment_amount, payment_date, payment_method, reference_no, notes, created_by, created_at, is_deleted) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0) 
            "); 
            $paymentStmt->execute([ 
                $business_id, $invoice_id, $liveInvoice['customer_id'] ?? null, $customer_payment_id,
                $payment_amount, $payment_date, $payment_method, 
                $reference !== '' ? $reference : null, 
                $notes !== '' ? $notes : null, 
                $user_id 
            ]);
            $invoice_payment_id = (int)$pdo->lastInsertId();

            // Record exact allocation for delete/restore and reporting
            $allocationStmt = $pdo->prepare("
                INSERT INTO customer_payment_allocations (
                    payment_id, business_id, customer_id, allocation_type, invoice_id, invoice_number, allocated_amount,
                    invoice_paid_before, invoice_paid_after, invoice_pending_before, invoice_pending_after,
                    description, created_by, created_at, is_deleted
                ) VALUES (?, ?, ?, 'invoice', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)
            ");
            $allocationStmt->execute([
                $customer_payment_id,
                $business_id,
                $liveInvoice['customer_id'] ?? null,
                $invoice_id,
                $liveInvoice['invoice_number'] ?? null,
                $payment_amount,
                $live_paid,
                $new_paid,
                $live_pending,
                $new_pending,
                'Payment collected directly for invoice ' . ($liveInvoice['invoice_number'] ?? ('#' . $invoice_id)),
                $user_id
            ]);

            writeActivityLog($pdo, $business_id, $user_id, 'invoice_payment_recorded', 'Invoice payment recorded from collect payment page', [
                'payment_id' => $customer_payment_id,
                'invoice_payment_id' => $invoice_payment_id,
                'invoice_id' => $invoice_id,
                'invoice_number' => $liveInvoice['invoice_number'] ?? null,
                'customer_id' => $liveInvoice['customer_id'] ?? null,
                'amount' => $payment_amount,
                'payment_method' => $payment_method,
                'paid_before' => $live_paid,
                'paid_after' => $new_paid,
                'pending_before' => $live_pending,
                'pending_after' => $new_pending,
                'payment_status_after' => $new_status
            ]);
            
            // Award loyalty points only once per invoice 
            $pointsBasis = round((float)($liveInvoice['subtotal'] ?? $live_total) - (float)($liveInvoice['points_discount_amount'] ?? 0), 2); 
            
            if ($pointsBasis > 0 && $new_paid > 0) { 
                $checkPointStmt = $pdo->prepare(" 
                    SELECT COUNT(*) FROM point_transactions WHERE business_id = ? AND invoice_id = ? AND transaction_type = 'earned' 
                "); 
                $checkPointStmt->execute([$business_id, $invoice_id]); 
                $pointExists = (int)$checkPointStmt->fetchColumn(); 
                
                if ($pointExists === 0) { 
                    $loyaltyStmt = $pdo->prepare(" 
                        SELECT is_active, points_per_amount FROM loyalty_settings WHERE business_id = ? LIMIT 1 
                    "); 
                    $loyaltyStmt->execute([$business_id]); 
                    $loyalty = $loyaltyStmt->fetch(PDO::FETCH_ASSOC); 
                    
                    if ($loyalty && (int)$loyalty['is_active'] === 1 && (float)$loyalty['points_per_amount'] > 0) { 
                        $pointsEarned = floor($pointsBasis * (float)$loyalty['points_per_amount'] * 100) / 100; 
                        
                        if ($pointsEarned > 0 && !empty($liveInvoice['customer_id'])) { 
                            $pointsStmt = $pdo->prepare(" 
                                INSERT INTO customer_points (customer_id, business_id, total_points_earned, total_points_redeemed, available_points) 
                                VALUES (?, ?, ?, 0, ?) 
                                ON DUPLICATE KEY UPDATE 
                                    total_points_earned = total_points_earned + VALUES(total_points_earned), 
                                    available_points = available_points + VALUES(available_points), 
                                    last_updated = NOW() 
                            "); 
                            $pointsStmt->execute([ 
                                $liveInvoice['customer_id'], $business_id, $pointsEarned, $pointsEarned 
                            ]); 
                            
                            $pointTxnStmt = $pdo->prepare(" 
                                INSERT INTO point_transactions (customer_id, business_id, invoice_id, transaction_type, points, amount_basis, created_by) 
                                VALUES (?, ?, ?, 'earned', ?, ?, ?) 
                            "); 
                            $pointTxnStmt->execute([ 
                                $liveInvoice['customer_id'], $business_id, $invoice_id, 
                                $pointsEarned, $pointsBasis, $user_id 
                            ]); 
                        } 
                    } 
                } 
            } 
            
            $pdo->commit(); 
            $_SESSION['success'] = "Payment of ₹" . number_format($payment_amount, 2) . " recorded successfully!"; 
            header("Location: invoice_view.php?invoice_id=$invoice_id"); 
            exit(); 
            
        } catch (Exception $e) { 
            if ($pdo->inTransaction()) { 
                $pdo->rollBack(); 
            } 
            $error = "Failed to record payment: " . $e->getMessage(); 
        } 
    } 
} 

// Refresh invoice details 
$stmt = $pdo->prepare(" 
    SELECT i.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address 
    FROM invoices i 
    LEFT JOIN customers c ON i.customer_id = c.id 
    WHERE i.id = ? AND i.business_id = ? 
    LIMIT 1 
"); 
$stmt->execute([$invoice_id, $business_id]); 
$invoice = $stmt->fetch(PDO::FETCH_ASSOC); 

$invoiceState = normalizeInvoicePaymentState($invoice); 
$total_amount = $invoiceState['total']; 
$paid_amount = $invoiceState['paid']; 
$pending_amount = $invoiceState['pending']; 
$balance_amount = $invoiceState['pending']; 

// Payment history
$paymentsStmt = $pdo->prepare("
    SELECT
        cp.id AS payment_id,
        cp.total_amount AS amount,
        cp.payment_method,
        cp.reference_no AS reference,
        cp.payment_date,
        cp.notes,
        cp.created_by,
        cp.created_at,
        COALESCE(u.full_name, CONCAT('User #', cp.created_by)) AS collected_by,
        cpa.invoice_number,
        cpa.allocated_amount,
        cpa.invoice_paid_before,
        cpa.invoice_paid_after,
        cpa.invoice_pending_before,
        cpa.invoice_pending_after
    FROM customer_payments cp
    INNER JOIN customer_payment_allocations cpa
        ON cpa.payment_id = cp.id
       AND cpa.business_id = cp.business_id
       AND cpa.invoice_id = ?
       AND cpa.allocation_type = 'invoice'
       AND cpa.is_deleted = 0
    LEFT JOIN users u ON u.id = cp.created_by
    WHERE cp.invoice_id = ?
      AND cp.business_id = ?
      AND cp.is_deleted = 0
    ORDER BY cp.payment_date DESC, cp.created_at DESC, cp.id DESC
");
$paymentsStmt->execute([$invoice_id, $invoice_id, $business_id]);
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>

<html lang="en">
<?php $page_title = "Collect Payment - Invoice #" . htmlspecialchars($invoice['invoice_number']);
include 'includes/head.php'; ?>

<body data-sidebar="dark">
    <div id="layout-wrapper"> <?php include 'includes/topbar.php'; ?>
        <div class="vertical-menu">
            <div data-simplebar class="h-100"> <?php include('includes/sidebar.php'); ?> </div>
        </div>
        <div class="main-content">
            <div class="page-content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12">
                            <div
                                class="page-title-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div>
                                    <h4 class="mb-0"> <i class="bx bx-money me-2"></i> Collect Payment </h4>
                                    <p class="mb-0 text-muted"> Invoice
                                        #<strong><?= htmlspecialchars($invoice['invoice_number']); ?></strong> •
                                        Customer:
                                        <strong><?= htmlspecialchars($invoice['customer_name'] ?? 'Walk-in Customer'); ?></strong>
                                    </p>
                                </div>
                                <div class="d-flex gap-2"> <a href="invoice_view.php?invoice_id=<?= $invoice_id; ?>"
                                        class="btn btn-outline-primary"> <i class="bx bx-arrow-back me-1"></i> Back to
                                        Invoice </a> </div>
                            </div>
                        </div>
                    </div> <?php if (!empty($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert"> <i
                                class="bx bx-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']); ?> <button
                                type="button" class="btn-close" data-bs-dismiss="alert"></button> </div>
                        <?php unset($_SESSION['success']); ?> <?php endif; ?> <?php if (!empty($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert"> <i
                                class="bx bx-error-circle me-2"></i><?= htmlspecialchars($_SESSION['error']); ?> <button
                                type="button" class="btn-close" data-bs-dismiss="alert"></button> </div>
                        <?php unset($_SESSION['error']); ?> <?php endif; ?> <?php if ($error !== ''): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert"> <i
                                class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error); ?> <button type="button"
                                class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endif; ?>
                    <div class="row">
                        <div class="col-xl-4 col-md-6">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <p class="text-muted mb-1">Invoice Total</p>
                                    <h3 class="mb-0 text-primary">₹<?= number_format($total_amount, 2); ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-6">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <p class="text-muted mb-1">Paid Amount</p>
                                    <h3 class="mb-0 text-success">₹<?= number_format($paid_amount, 2); ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-12">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <p class="text-muted mb-1">Pending Amount</p>
                                    <h3 class="mb-0 text-danger">₹<?= number_format($balance_amount, 2); ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="bx bx-receipt me-2"></i>Invoice Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4"> <label class="form-label text-muted mb-1">Invoice Number</label>
                                    <div class="fw-semibold"><?= htmlspecialchars($invoice['invoice_number']); ?></div>
                                </div>
                                <div class="col-md-4"> <label class="form-label text-muted mb-1">Invoice Date</label>
                                    <div class="fw-semibold">
                                        <?= !empty($invoice['created_at']) ? date('d M Y h:i A', strtotime($invoice['created_at'])) : '—'; ?>
                                    </div>
                                </div>
                                <div class="col-md-4"> <label class="form-label text-muted mb-1">Payment Status</label>
                                    <div>
                                        <?php $status = $invoiceState['status'];
                                        $statusClass = $status === 'paid' ? 'success' : ($status === 'partial' ? 'warning' : 'danger'); ?>
                                        <span class="badge bg-<?= $statusClass; ?>"><?= ucfirst($status); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6"> <label class="form-label text-muted mb-1">Customer Name</label>
                                    <div class="fw-semibold">
                                        <?= htmlspecialchars($invoice['customer_name'] ?? 'Walk-in Customer'); ?></div>
                                </div>
                                <div class="col-md-6"> <label class="form-label text-muted mb-1">Phone</label>
                                    <div class="fw-semibold"><?= htmlspecialchars($invoice['customer_phone'] ?? '—'); ?>
                                    </div>
                                </div>
                                <div class="col-md-12"> <label class="form-label text-muted mb-1">Address</label>
                                    <div class="fw-semibold">
                                        <?= nl2br(htmlspecialchars($invoice['customer_address'] ?? '—')); ?></div>
                                </div>
                            </div>
                        </div>
                    </div> <?php if ($balance_amount > 0): ?>
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bx bx-wallet me-2"></i>Record Payment</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="paymentForm" novalidate>
                                    <div class="row g-3">
                                        <div class="col-md-4"> <label class="form-label">Payment Amount <span
                                                    class="text-danger">*</span></label>
                                            <div class="input-group"> <span class="input-group-text">₹</span> <input
                                                    type="number" class="form-control" name="payment_amount"
                                                    id="payment_amount" min="0.01"
                                                    max="<?= htmlspecialchars(number_format($balance_amount, 2, '.', '')); ?>"
                                                    step="0.01"
                                                    value="<?= htmlspecialchars(number_format($balance_amount, 2, '.', '')); ?>"
                                                    required> </div> <small class="text-muted">Maximum payable now:
                                                ₹<?= number_format($balance_amount, 2); ?></small>
                                        </div>
                                        <div class="col-md-4"> <label class="form-label">Payment Method <span
                                                    class="text-danger">*</span></label> <select class="form-select"
                                                name="payment_method" id="payment_method" required>
                                                <option value="cash">Cash</option>
                                                <option value="upi">UPI</option>
                                                <option value="bank">Bank</option>
                                                <option value="cheque">Cheque</option>
                                                <option value="credit_card">Credit Card</option>
                                                <option value="other">Other</option>
                                            </select> </div>
                                        <div class="col-md-4"> <label class="form-label">Payment Date <span
                                                    class="text-danger">*</span></label> <input type="date"
                                                class="form-control" name="payment_date"
                                                value="<?= htmlspecialchars(date('Y-m-d')); ?>" required> </div>
                                        <div class="col-md-6"> <label class="form-label" id="reference_label">Reference /
                                                Transaction No</label> <input type="text" class="form-control"
                                                name="reference" id="reference" placeholder="Enter reference if applicable">
                                        </div>
                                        <div class="col-md-6"> <label class="form-label">Notes</label> <input type="text"
                                                class="form-control" name="notes" placeholder="Optional notes"> </div>
                                        <div class="col-12 d-flex gap-2"> <button type="button"
                                                class="btn btn-outline-secondary" id="pay_half_btn"> Pay Half </button>
                                            <button type="button" class="btn btn-outline-primary" id="pay_full_btn"> Pay
                                                Full </button> <button type="submit" class="btn btn-success"> <i
                                                    class="bx bx-check-circle me-1"></i> Save Payment </button> </div>
                                    </div>
                                </form>
                            </div>
                        </div> <?php endif; ?>
                    <div class="card shadow-sm">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bx bx-history me-2"></i>Payment History</h5> <span
                                class="badge bg-primary"><?= count($payments); ?> record(s)</span>
                        </div>
                        <div class="card-body"> <?php if (empty($payments)): ?>
                                <div class="text-center py-4 text-muted"> <i class="bx bx-history fs-1 d-block mb-2"></i> No
                                    payment records found. </div> <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Reference</th>
                                                <th>Notes</th>
                                                <th>Invoice Balance</th>
                                                <th>Collected By</th>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody> <?php foreach ($payments as $pay): ?>
                                                <tr>
                                                    <td> <?= !empty($pay['payment_date']) ? date('d M Y', strtotime($pay['payment_date'])) : '—'; ?>
                                                        <div class="small text-muted">
                                                            <?= !empty($pay['created_at']) ? date('h:i A', strtotime($pay['created_at'])) : ''; ?>
                                                        </div>
                                                    </td>
                                                    <td class="fw-bold text-success">
                                                        ₹<?= number_format((float) $pay['amount'], 2); ?></td>
                                                    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $pay['payment_method'] ?? '—'))); ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($pay['reference'] ?: '—'); ?></td>
                                                    <td><?= htmlspecialchars($pay['notes'] ?: '—'); ?></td>
                                                    <td>
                                                        <small class="text-muted d-block">Allocated: ₹<?= number_format((float)($pay['allocated_amount'] ?? $pay['amount']), 2); ?></small>
                                                        <small class="text-muted d-block">Paid: ₹<?= number_format((float)($pay['invoice_paid_before'] ?? 0), 2); ?> → ₹<?= number_format((float)($pay['invoice_paid_after'] ?? 0), 2); ?></small>
                                                        <small class="text-muted d-block">Pending: ₹<?= number_format((float)($pay['invoice_pending_before'] ?? 0), 2); ?> → ₹<?= number_format((float)($pay['invoice_pending_after'] ?? 0), 2); ?></small>
                                                    </td>
                                                    <td><?= htmlspecialchars($pay['collected_by'] ?? (!empty($pay['created_by']) ? 'User #' . (int) $pay['created_by'] : '—')); ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <form method="POST" class="delete-payment-form d-inline">
                                                            <input type="hidden" name="action" value="delete_payment">
                                                            <input type="hidden" name="payment_id" value="<?= (int)$pay['payment_id']; ?>">
                                                            <input type="hidden" name="delete_reason" value="Deleted from collect payment page">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Payment">
                                                                <i class="bx bx-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr> <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div> <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div> <?php include 'includes/footer.php'; ?>
        </div>
    </div>
    <script> document.addEventListener('DOMContentLoaded', function () { const amountInput = document.getElementById('payment_amount'); const methodSelect = document.getElementById('payment_method'); const refLabel = document.getElementById('reference_label'); const payHalfBtn = document.getElementById('pay_half_btn'); const payFullBtn = document.getElementById('pay_full_btn'); const balanceAmount = <?= json_encode((float) $balance_amount); ?>; function updateReferenceLabel() { const method = methodSelect.value; if (method === 'upi') { refLabel.textContent = 'UPI Reference'; } else if (method === 'bank') { refLabel.textContent = 'Bank Reference'; } else if (method === 'cheque') { refLabel.textContent = 'Cheque Number'; } else if (method === 'credit_card') { refLabel.textContent = 'Card Reference'; } else { refLabel.textContent = 'Reference / Transaction No'; } } if (methodSelect) { methodSelect.addEventListener('change', updateReferenceLabel); updateReferenceLabel(); } if (payHalfBtn) { payHalfBtn.addEventListener('click', function () { amountInput.value = (Math.round((balanceAmount / 2) * 100) / 100).toFixed(2); }); } if (payFullBtn) { payFullBtn.addEventListener('click', function () { amountInput.value = balanceAmount.toFixed(2); }); } document.querySelectorAll('.delete-payment-form').forEach(function(form) { form.addEventListener('submit', function(e) { if (!confirm('Delete this payment? Invoice paid and pending values will be restored. This is a soft delete only.')) { e.preventDefault(); } }); }); }); </script>
</body>

</html>