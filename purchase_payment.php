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

$purchase_id = (int)($_GET['id'] ?? 0);
if (!$purchase_id) {
    header('Location: purchases.php');
    exit();
}

// Fetch purchase with manufacturer details including outstanding
$stmt = $pdo->prepare("
    SELECT p.*, 
           m.name as manufacturer_name,
           m.initial_outstanding_amount,
           m.initial_outstanding_type,
           p.total_amount - p.paid_amount as balance_due,
           (SELECT COALESCE(SUM(amount), 0) 
            FROM payments 
            WHERE type = 'supplier' AND reference_id = p.id) as total_paid
    FROM purchases p
    JOIN manufacturers m ON p.manufacturer_id = m.id
    WHERE p.id = ?
");
$stmt->execute([$purchase_id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    header('Location: purchases.php');
    exit();
}

// Calculate manufacturer's overall outstanding
$manufacturer_id = $purchase['manufacturer_id'];

// Get manufacturer details for outstanding handling
$manufacturer_stmt = $pdo->prepare("SELECT * FROM manufacturers WHERE id = ?");
$manufacturer_stmt->execute([$manufacturer_id]);
$manufacturer = $manufacturer_stmt->fetch();

// Get all pending purchases for this manufacturer
$pending_stmt = $pdo->prepare("
    SELECT id, purchase_number, total_amount, paid_amount,
           (total_amount - paid_amount) as due_amount,
           purchase_date
    FROM purchases 
    WHERE manufacturer_id = ? AND payment_status != 'paid'
    ORDER BY purchase_date ASC
");
$pending_stmt->execute([$manufacturer_id]);
$pending_purchases = $pending_stmt->fetchAll();

// Calculate total purchase balance
$total_purchase_balance = 0;
foreach ($pending_purchases as $pp) {
    $total_purchase_balance += $pp['due_amount'];
}

// Calculate net outstanding including initial balance
$initial_outstanding = $purchase['initial_outstanding_amount'] ?? 0;
$initial_type = $purchase['initial_outstanding_type'] ?? 'none';

// Net payable calculation
if ($initial_type === 'credit') {
    // Credit: Supplier owes us - reduces what we owe
    $net_payable = max(0, $total_purchase_balance - $initial_outstanding);
    $outstanding_text = "Credit Balance (Supplier owes you): ₹" . number_format($initial_outstanding, 2);
    $outstanding_class = 'success';
    $outstanding_icon = 'bx-up-arrow-alt';
} elseif ($initial_type === 'debit') {
    // Debit: We owe supplier - increases what we owe
    $net_payable = $total_purchase_balance + $initial_outstanding;
    $outstanding_text = "Debit Balance (You owe supplier): ₹" . number_format($initial_outstanding, 2);
    $outstanding_class = 'danger';
    $outstanding_icon = 'bx-down-arrow-alt';
} else {
    $net_payable = $total_purchase_balance;
    $outstanding_text = "No Outstanding Balance";
    $outstanding_class = 'secondary';
    $outstanding_icon = 'bx-check-circle';
}

$success = $error = '';
$transaction_started = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $reference_no = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_type = $_POST['payment_type'] ?? 'single'; // single, overall, outstanding_only

    if ($amount <= 0) {
        $error = "Please enter a valid amount.";
    } elseif ($payment_type === 'single') {
        // Single PO payment validation
        if ($amount > $purchase['balance_due']) {
            $error = "Amount cannot exceed PO balance: ₹" . number_format($purchase['balance_due'], 2);
        } else {
            try {
                $pdo->beginTransaction();
                $transaction_started = true;

                // Insert payment record
                $stmt = $pdo->prepare("INSERT INTO payments 
                    (payment_date, type, reference_id, amount, payment_method, reference_no, recorded_by, notes, payment_type)
                    VALUES (?, 'supplier', ?, ?, ?, ?, ?, ?, 'single')");
                $stmt->execute([
                    $payment_date,
                    $purchase_id,
                    $amount,
                    $payment_method,
                    $reference_no,
                    $_SESSION['user_id'],
                    $notes
                ]);

                // Update purchase
                $new_paid = $purchase['paid_amount'] + $amount;
                $status = ($new_paid >= $purchase['total_amount']) ? 'paid' : 'partial';

                $pdo->prepare("UPDATE purchases SET paid_amount = ?, payment_status = ? WHERE id = ?")
                    ->execute([$new_paid, $status, $purchase_id]);

                $pdo->commit();
                
                header("Location: purchases.php?success=payment&po=" . $purchase['purchase_number']);
                exit();

            } catch (Exception $e) {
                if ($transaction_started) $pdo->rollBack();
                $error = "Failed: " . $e->getMessage();
            }
        }
    } elseif ($payment_type === 'outstanding_only') {
        // Pay only outstanding balance
        if ($initial_outstanding <= 0) {
            $error = "No outstanding balance to pay.";
        } elseif ($amount > $initial_outstanding) {
            $error = "Amount cannot exceed outstanding balance: ₹" . number_format($initial_outstanding, 2);
        } else {
            try {
                $pdo->beginTransaction();
                $transaction_started = true;

                // Record outstanding payment
                $stmt = $pdo->prepare("INSERT INTO payments 
                    (payment_date, type, reference_id, amount, payment_method, reference_no, recorded_by, notes, payment_type)
                    VALUES (?, 'supplier_outstanding', ?, ?, ?, ?, ?, ?, 'outstanding')");
                $stmt->execute([
                    $payment_date,
                    $manufacturer_id,
                    $amount,
                    $payment_method,
                    $reference_no,
                    $_SESSION['user_id'],
                    $notes
                ]);

                // Update manufacturer outstanding
                if ($initial_type === 'debit') {
                    $new_outstanding = $initial_outstanding - $amount;
                    $update_sql = "UPDATE manufacturers SET initial_outstanding_amount = ? WHERE id = ?";
                    $pdo->prepare($update_sql)->execute([max(0, $new_outstanding), $manufacturer_id]);
                    
                    if ($new_outstanding <= 0) {
                        $pdo->prepare("UPDATE manufacturers SET initial_outstanding_type = 'none' WHERE id = ?")
                            ->execute([$manufacturer_id]);
                    }
                }

                $pdo->commit();
                
                header("Location: purchase_payment.php?id=" . $purchase_id . "&success=outstanding_paid");
                exit();

            } catch (Exception $e) {
                if ($transaction_started) $pdo->rollBack();
                $error = "Failed: " . $e->getMessage();
            }
        }
    } elseif ($payment_type === 'overall') {
        // Overall payment - first deduct outstanding, then distribute to POs
        if ($amount > $net_payable) {
            $error = "Amount cannot exceed net payable: ₹" . number_format($net_payable, 2);
        } else {
            try {
                $pdo->beginTransaction();
                $transaction_started = true;
                
                $remaining_amount = $amount;
                
                // First handle outstanding if applicable
                if ($initial_type === 'debit' && $initial_outstanding > 0) {
                    $outstanding_payment = min($remaining_amount, $initial_outstanding);
                    
                    if ($outstanding_payment > 0) {
                        // Record outstanding payment
                        $stmt = $pdo->prepare("INSERT INTO payments 
                            (payment_date, type, reference_id, amount, payment_method, reference_no, recorded_by, notes, payment_type)
                            VALUES (?, 'supplier_outstanding', ?, ?, ?, ?, ?, ?, 'overall_outstanding')");
                        $stmt->execute([
                            $payment_date,
                            $manufacturer_id,
                            $outstanding_payment,
                            $payment_method,
                            $reference_no . " (Overall)",
                            $_SESSION['user_id'],
                            $notes . " - Part of overall payment"
                        ]);
                        
                        // Update manufacturer outstanding
                        $new_outstanding = $initial_outstanding - $outstanding_payment;
                        $update_sql = "UPDATE manufacturers SET initial_outstanding_amount = ? WHERE id = ?";
                        $pdo->prepare($update_sql)->execute([max(0, $new_outstanding), $manufacturer_id]);
                        
                        if ($new_outstanding <= 0) {
                            $pdo->prepare("UPDATE manufacturers SET initial_outstanding_type = 'none' WHERE id = ?")
                                ->execute([$manufacturer_id]);
                        }
                        
                        $remaining_amount -= $outstanding_payment;
                    }
                } elseif ($initial_type === 'credit' && $initial_outstanding > 0) {
                    // If credit balance exists, we can adjust it against purchases
                    // This would need separate logic - for now, skip
                }
                
                // Distribute remaining amount to pending purchases
                if ($remaining_amount > 0 && !empty($pending_purchases)) {
                    foreach ($pending_purchases as $pending_po) {
                        if ($remaining_amount <= 0) break;
                        
                        $po_due = $pending_po['due_amount'];
                        if ($po_due <= 0) continue;
                        
                        $payment_for_po = min($remaining_amount, $po_due);
                        
                        // Insert payment for this PO
                        $stmt = $pdo->prepare("INSERT INTO payments 
                            (payment_date, type, reference_id, amount, payment_method, reference_no, recorded_by, notes, payment_type)
                            VALUES (?, 'supplier', ?, ?, ?, ?, ?, ?, 'overall_po')");
                        $stmt->execute([
                            $payment_date,
                            $pending_po['id'],
                            $payment_for_po,
                            $payment_method,
                            $reference_no . " (Overall)",
                            $_SESSION['user_id'],
                            $notes . " - Part of overall payment"
                        ]);
                        
                        // Update PO
                        $stmt = $pdo->prepare("SELECT paid_amount, total_amount FROM purchases WHERE id = ?");
                        $stmt->execute([$pending_po['id']]);
                        $po_data = $stmt->fetch();
                        
                        $new_paid = $po_data['paid_amount'] + $payment_for_po;
                        $status = ($new_paid >= $po_data['total_amount']) ? 'paid' : 'partial';
                        
                        $pdo->prepare("UPDATE purchases SET paid_amount = ?, payment_status = ? WHERE id = ?")
                            ->execute([$new_paid, $status, $pending_po['id']]);
                        
                        $remaining_amount -= $payment_for_po;
                    }
                }
                
                $pdo->commit();
                
                header("Location: purchases.php?success=overall_payment&manufacturer=" . urlencode($manufacturer['name']));
                exit();

            } catch (Exception $e) {
                if ($transaction_started) $pdo->rollBack();
                $error = "Failed: " . $e->getMessage();
            }
        }
    }
}

// Fetch payment history for this purchase
$payments = $pdo->prepare("
    SELECT p.*, u.full_name as recorded_by
    FROM payments p
    JOIN users u ON p.recorded_by = u.id
    WHERE p.type IN ('supplier', 'supplier_outstanding') 
    AND (p.reference_id = ? OR (p.type = 'supplier_outstanding' AND p.reference_id = ?))
    ORDER BY p.payment_date DESC, p.created_at DESC
");
$payments->execute([$purchase_id, $manufacturer_id]);
$payments = $payments->fetchAll();
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Payment - PO #{$purchase['purchase_number']}"; 
include('includes/head.php'); 
?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-money me-2"></i> Record Supplier Payment
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-hash me-1"></i> <?= htmlspecialchars($purchase['purchase_number']) ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    <i class="bx bx-building me-1"></i> 
                                    <?= htmlspecialchars($purchase['manufacturer_name']) ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="purchases.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Purchases
                                </a>
                                <a href="purchase_view.php?id=<?= $purchase_id ?>" class="btn btn-outline-info">
                                    <i class="bx bx-show me-1"></i> View PO
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Success Message for Outstanding Payment -->
                <?php if (isset($_GET['success']) && $_GET['success'] == 'outstanding_paid'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i>Outstanding balance paid successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Outstanding Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card bg-<?= $outstanding_class ?> bg-opacity-10 border-<?= $outstanding_class ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-<?= $outstanding_class ?> mb-1">
                                            <i class="bx <?= $outstanding_icon ?> me-1"></i>
                                            <?= ucfirst($initial_type) === 'Credit' ? 'Credit Balance' : (ucfirst($initial_type) === 'Debit' ? 'Debit Balance' : 'Outstanding') ?>
                                        </h6>
                                        <h4 class="text-<?= $outstanding_class ?> mb-0">₹<?= number_format($initial_outstanding, 2) ?></h4>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-<?= $outstanding_class ?> bg-opacity-20 rounded-circle fs-3">
                                            <i class="bx <?= $outstanding_icon ?> text-<?= $outstanding_class ?>"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-2"><?= $outstanding_text ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card bg-warning bg-opacity-10 border-warning">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-warning mb-1">
                                            <i class="bx bx-cart me-1"></i>
                                            Total PO Balance
                                        </h6>
                                        <h4 class="text-warning mb-0">₹<?= number_format($total_purchase_balance, 2) ?></h4>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-20 rounded-circle fs-3">
                                            <i class="bx bx-cart text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-2">From <?= count($pending_purchases) ?> pending purchase(s)</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card bg-success bg-opacity-10 border-success">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-success mb-1">
                                            <i class="bx bx-money me-1"></i>
                                            Net Payable
                                        </h6>
                                        <h4 class="text-success mb-0">₹<?= number_format($net_payable, 2) ?></h4>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-20 rounded-circle fs-3">
                                            <i class="bx bx-money text-success"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-2">
                                    <?php if ($initial_type === 'credit'): ?>
                                        Credit adjusted: -₹<?= number_format($initial_outstanding, 2) ?>
                                    <?php elseif ($initial_type === 'debit'): ?>
                                        Debit added: +₹<?= number_format($initial_outstanding, 2) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Type Selection Tabs -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <ul class="nav nav-tabs card-header-tabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#single-po">
                                    <i class="bx bx-file me-1"></i> Single PO Payment
                                </a>
                            </li>
                            <?php if ($initial_outstanding > 0): ?>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#outstanding-only">
                                    <i class="bx bx-credit-card me-1"></i> Pay Outstanding Only
                                </a>
                            </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#overall-payment">
                                    <i class="bx bx-layer me-1"></i> Overall Payment
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <!-- Single PO Payment Tab -->
                            <div class="tab-pane active" id="single-po">
                                <?php if ($purchase['balance_due'] > 0): ?>
                                <form method="POST">
                                    <input type="hidden" name="payment_type" value="single">
                                    <div class="row g-4">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Payment Date <span class="text-danger">*</span></label>
                                            <input type="date" name="payment_date" class="form-control" 
                                                   value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
                                            <select name="payment_method" class="form-select" required>
                                                <option value="cash">Cash</option>
                                                <option value="bank">Bank Transfer</option>
                                                <option value="upi">UPI</option>
                                                <option value="cheque">Cheque</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Amount <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" name="amount" class="form-control form-control-lg text-end text-success" 
                                                       step="1" min="1" max="<?= $purchase['balance_due'] ?>" 
                                                       value="<?= $purchase['balance_due'] ?>" required>
                                            </div>
                                            <small class="text-muted">Max: ₹<?= number_format($purchase['balance_due'], 2) ?></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Reference Number</label>
                                            <input type="text" name="reference_no" class="form-control" 
                                                   placeholder="e.g. Cheque no., UPI ref, etc.">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Notes (Optional)</label>
                                            <textarea name="notes" class="form-control" rows="1" 
                                                      placeholder="Additional notes..."></textarea>
                                        </div>
                                    </div>
                                    <div class="text-end mt-4">
                                        <a href="purchase_view.php?id=<?= $purchase_id ?>" class="btn btn-outline-secondary me-2">
                                            <i class="bx bx-x me-1"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-success btn-lg px-5">
                                            <i class="bx bx-check-circle me-2"></i> Record Payment
                                        </button>
                                    </div>
                                </form>
                                <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="bx bx-check-circle text-success fs-1"></i>
                                    <h5 class="mt-2">Purchase Order Fully Paid</h5>
                                    <p class="text-muted">No balance amount pending for this PO.</p>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Outstanding Only Payment Tab -->
                            <?php if ($initial_outstanding > 0): ?>
                            <div class="tab-pane" id="outstanding-only">
                                <form method="POST">
                                    <input type="hidden" name="payment_type" value="outstanding_only">
                                    <div class="row g-4">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Payment Date <span class="text-danger">*</span></label>
                                            <input type="date" name="payment_date" class="form-control" 
                                                   value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
                                            <select name="payment_method" class="form-select" required>
                                                <option value="cash">Cash</option>
                                                <option value="bank">Bank Transfer</option>
                                                <option value="upi">UPI</option>
                                                <option value="cheque">Cheque</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Amount <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" name="amount" class="form-control form-control-lg text-end" 
                                                       step="0.01" min="1" max="<?= $initial_outstanding ?>" 
                                                       value="<?= $initial_outstanding ?>" required>
                                            </div>
                                            <small class="text-muted">Outstanding Balance: ₹<?= number_format($initial_outstanding, 2) ?></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Reference Number</label>
                                            <input type="text" name="reference_no" class="form-control" 
                                                   placeholder="e.g. Cheque no., UPI ref, etc.">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Notes (Optional)</label>
                                            <textarea name="notes" class="form-control" rows="1" 
                                                      placeholder="Additional notes..."></textarea>
                                        </div>
                                    </div>
                                    <div class="alert alert-info mt-3">
                                        <i class="bx bx-info-circle me-2"></i>
                                        This payment will only be applied to the outstanding balance of ₹<?= number_format($initial_outstanding, 2) ?>.
                                        It will not affect any purchase orders.
                                    </div>
                                    <div class="text-end mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg px-5">
                                            <i class="bx bx-credit-card me-2"></i> Pay Outstanding
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>

                            <!-- Overall Payment Tab -->
                            <div class="tab-pane" id="overall-payment">
                                <form method="POST">
                                    <input type="hidden" name="payment_type" value="overall">
                                    <div class="row g-4">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Payment Date <span class="text-danger">*</span></label>
                                            <input type="date" name="payment_date" class="form-control" 
                                                   value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
                                            <select name="payment_method" class="form-select" required>
                                                <option value="cash">Cash</option>
                                                <option value="bank">Bank Transfer</option>
                                                <option value="upi">UPI</option>
                                                <option value="cheque">Cheque</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Amount <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" name="amount" class="form-control form-control-lg text-end" 
                                                       step="0.01" min="1" max="<?= $net_payable ?>" 
                                                       value="<?= $net_payable ?>" required>
                                            </div>
                                            <small class="text-muted">Net Payable: ₹<?= number_format($net_payable, 2) ?></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Reference Number</label>
                                            <input type="text" name="reference_no" class="form-control" 
                                                   placeholder="e.g. Cheque no., UPI ref, etc.">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Notes (Optional)</label>
                                            <textarea name="notes" class="form-control" rows="1" 
                                                      placeholder="Additional notes..."></textarea>
                                        </div>
                                    </div>

                                    <!-- Payment Distribution Preview -->
                                    <div class="card mt-4 bg-light">
                                        <div class="card-body">
                                            <h6 class="card-title mb-3">
                                                <i class="bx bx-git-branch me-2"></i> Payment Distribution Preview
                                            </h6>
                                            <div id="distribution-preview">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total Payment:</span>
                                                    <span class="fw-bold" id="preview-total">₹0.00</span>
                                                </div>
                                                <?php if ($initial_type === 'debit' && $initial_outstanding > 0): ?>
                                                <div class="d-flex justify-content-between mb-2 text-danger">
                                                    <span><i class="bx bx-down-arrow-alt me-1"></i> Debit Outstanding:</span>
                                                    <span id="preview-outstanding">₹0.00</span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="d-flex justify-content-between mb-2 text-warning">
                                                    <span><i class="bx bx-cart me-1"></i> Purchase Orders:</span>
                                                    <span id="preview-pos">₹0.00</span>
                                                </div>
                                                <?php if (!empty($pending_purchases)): ?>
                                                <hr>
                                                <div class="small">
                                                    <strong>PO-wise distribution:</strong>
                                                    <ul class="list-unstyled mt-2" id="po-preview-list">
                                                        <?php foreach ($pending_purchases as $po): ?>
                                                        <li class="d-flex justify-content-between mb-1">
                                                            <span><?= htmlspecialchars($po['purchase_number']) ?></span>
                                                            <span class="po-preview-amount" data-due="<?= $po['due_amount'] ?>">₹0.00</span>
                                                        </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="alert alert-warning mt-3">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>How overall payment works:</strong>
                                        <ol class="mb-0 mt-2">
                                            <?php if ($initial_type === 'debit' && $initial_outstanding > 0): ?>
                                            <li>First deducts from debit outstanding (₹<?= number_format($initial_outstanding, 2) ?>)</li>
                                            <?php endif; ?>
                                            <li>Remaining amount is distributed to pending purchase orders (oldest first)</li>
                                            <li>Each PO is paid in full before moving to the next</li>
                                        </ol>
                                    </div>

                                    <div class="text-end mt-4">
                                        <button type="submit" class="btn btn-success btn-lg px-5">
                                            <i class="bx bx-layer me-2"></i> Process Overall Payment
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Purchases List -->
                <?php if (count($pending_purchases) > 1): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-list-ul me-2"></i> All Pending Purchases for <?= htmlspecialchars($purchase['manufacturer_name']) ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>PO Number</th>
                                        <th>Date</th>
                                        <th class="text-end">Total Amount</th>
                                        <th class="text-end">Paid</th>
                                        <th class="text-end">Due</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_purchases as $pp): ?>
                                    <tr class="<?= $pp['id'] == $purchase_id ? 'table-primary' : '' ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($pp['purchase_number']) ?></strong>
                                        </td>
                                        <td><?= date('d M Y', strtotime($pp['purchase_date'])) ?></td>
                                        <td class="text-end">₹<?= number_format($pp['total_amount'], 2) ?></td>
                                        <td class="text-end">₹<?= number_format($pp['paid_amount'], 2) ?></td>
                                        <td class="text-end text-warning fw-bold">₹<?= number_format($pp['due_amount'], 2) ?></td>
                                        <td class="text-center">
                                            <?php 
                                            $status = $pp['due_amount'] >= $pp['total_amount'] ? 'pending' : 'partial';
                                            $badge_color = $status === 'pending' ? 'danger' : 'warning';
                                            ?>
                                            <span class="badge bg-<?= $badge_color ?> bg-opacity-10 text-<?= $badge_color ?>">
                                                <?= ucfirst($status) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($pp['id'] != $purchase_id && $pp['due_amount'] > 0): ?>
                                                <a href="purchase_payment.php?id=<?= $pp['id'] ?>" 
                                                   class="btn btn-sm btn-outline-primary"
                                                   title="Pay this PO">
                                                    <i class="bx bx-money"></i> Pay
                                                </a>
                                            <?php elseif ($pp['id'] == $purchase_id): ?>
                                                <span class="badge bg-info">Current</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment History -->
                <?php if ($payments): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bx bx-history me-2"></i> Payment History
                            </h5>
                            <span class="badge bg-primary"><?= count($payments) ?> Payments</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Recorded By</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $pay): ?>
                                    <tr class="payment-row">
                                        <td>
                                            <div class="d-flex flex-column">
                                                <strong><?= date('d M Y', strtotime($pay['payment_date'])) ?></strong>
                                                <small class="text-muted"><?= date('h:i A', strtotime($pay['created_at'])) ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $type_badge = [
                                                'supplier' => ['info', 'PO Payment'],
                                                'supplier_outstanding' => ['warning', 'Outstanding']
                                            ][$pay['type']] ?? ['secondary', 'Other'];
                                            ?>
                                            <span class="badge bg-<?= $type_badge[0] ?> bg-opacity-10 text-<?= $type_badge[0] ?>">
                                                <?= $type_badge[1] ?>
                                            </span>
                                            <?php if ($pay['payment_type'] === 'overall_po'): ?>
                                            <small class="d-block text-muted">(Overall)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-success bg-opacity-10 text-success px-3 py-1">
                                                <i class="bx bx-rupee me-1"></i> ₹<?= number_format($pay['amount'], 2) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $method_color = [
                                                'cash' => 'info',
                                                'bank' => 'primary',
                                                'upi' => 'success',
                                                'cheque' => 'warning'
                                            ][$pay['payment_method']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $method_color ?> bg-opacity-10 text-<?= $method_color ?>">
                                                <?= ucfirst($pay['payment_method']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($pay['reference_no']): ?>
                                            <span class="badge bg-light text-dark">
                                                <?= htmlspecialchars($pay['reference_no']) ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small>
                                                <i class="bx bx-user me-1"></i>
                                                <?= htmlspecialchars($pay['recorded_by']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($pay['notes']): ?>
                                            <small class="text-muted">
                                                <i class="bx bx-note me-1"></i>
                                                <?= htmlspecialchars($pay['notes']) ?>
                                            </small>
                                            <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/scripts.php'); ?>

<style>
.card-hover {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.card-hover:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important;
}
.border-start {
    border-left-width: 4px !important;
}
.badge.bg-opacity-10 {
    opacity: 0.9;
}
.payment-row:hover {
    background-color: rgba(0,0,0,0.02);
}
.btn-lg {
    border-radius: 10px;
}
.input-group-text {
    background-color: #f8f9fa;
    border-color: #ced4da;
}
.bg-opacity-20 {
    --bs-bg-opacity: 0.2;
}
.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
    padding: 0.75rem 1.5rem;
}
.nav-tabs .nav-link.active {
    color: #0d6efd;
    background: transparent;
    border-bottom: 2px solid #0d6efd;
}
.nav-tabs .nav-link:hover {
    border: none;
    color: #0d6efd;
}
@media (max-width: 768px) {
    .btn-lg {
        padding: 0.75rem 1rem;
    }
}
</style>

<script>
$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Row hover effect
    $('.payment-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // Format amount input for single PO
    $('input[name="amount"]').on('input', function() {
        const max = parseFloat($(this).attr('max'));
        const value = parseFloat($(this).val());
        
        if (value > max) {
            $(this).val(max.toFixed(2));
            Swal.fire({
                icon: 'warning',
                title: 'Amount Exceeded',
                text: 'Amount cannot exceed maximum limit',
                timer: 2000,
                showConfirmButton: false
            });
        }
    });

    // Overall payment preview
    $('input[name="amount"]').on('input', function() {
        if ($(this).closest('form').find('input[name="payment_type"]').val() === 'overall') {
            updatePaymentPreview();
        }
    });

    function updatePaymentPreview() {
        const totalAmount = parseFloat($('#overall-payment input[name="amount"]').val()) || 0;
        const outstandingBalance = <?= $initial_outstanding ?>;
        const poDues = <?= json_encode(array_column($pending_purchases, 'due_amount', 'purchase_number')) ?>;
        
        $('#preview-total').text('₹' + totalAmount.toFixed(2));
        
        let remaining = totalAmount;
        let outstandingPaid = 0;
        let poPaid = 0;
        
        <?php if ($initial_type === 'debit' && $initial_outstanding > 0): ?>
        // Deduct outstanding first
        outstandingPaid = Math.min(remaining, outstandingBalance);
        remaining -= outstandingPaid;
        $('#preview-outstanding').text('₹' + outstandingPaid.toFixed(2));
        <?php endif; ?>
        
        // Distribute to POs
        let poPayments = {};
        let poTotal = 0;
        
        <?php foreach ($pending_purchases as $po): ?>
        if (remaining > 0) {
            let payment = Math.min(remaining, <?= $po['due_amount'] ?>);
            poPayments['<?= $po['purchase_number'] ?>'] = payment;
            remaining -= payment;
            poTotal += payment;
        } else {
            poPayments['<?= $po['purchase_number'] ?>'] = 0;
        }
        <?php endforeach; ?>
        
        $('#preview-pos').text('₹' + poTotal.toFixed(2));
        
        // Update PO list preview
        $('.po-preview-amount').each(function() {
            const poNumber = $(this).closest('li').find('span:first').text().trim();
            if (poPayments[poNumber] !== undefined) {
                $(this).text('₹' + poPayments[poNumber].toFixed(2));
            }
        });
    }

    // Initialize preview on tab show
    $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        if ($(e.target).attr('href') === '#overall-payment') {
            updatePaymentPreview();
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Confirm overall payment
    $('form').on('submit', function() {
        const paymentType = $(this).find('input[name="payment_type"]').val();
        
        if (paymentType === 'overall') {
            const amount = parseFloat($(this).find('input[name="amount"]').val());
            const netPayable = <?= $net_payable ?>;
            
            if (amount > netPayable) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Amount',
                    text: 'Amount cannot exceed net payable of ₹' + netPayable.toFixed(2)
                });
                return false;
            }
            
            return Swal.fire({
                title: 'Confirm Overall Payment',
                html: `You are about to make an overall payment of <strong>₹${amount.toFixed(2)}</strong><br>
                       This will be distributed to outstanding and POs as per the preview.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, process payment',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                return result.isConfirmed;
            });
        }
        
        return true;
    });
});
</script>
</body>
</html>