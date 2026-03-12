<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;
$customer_id = (int)($_GET['customer_id'] ?? 0);

if (!$customer_id) {
    $_SESSION['error'] = "Invalid customer.";
    header('Location: customers.php');
    exit();
}

// Fetch customer details with proper outstanding calculation
$stmt = $pdo->prepare("
    SELECT c.*, 
           COALESCE(SUM(i.pending_amount), 0) as invoice_outstanding
    FROM customers c
    LEFT JOIN invoices i ON i.customer_id = c.id AND i.business_id = ? AND i.pending_amount > 0
    WHERE c.id = ? AND c.business_id = ?
    GROUP BY c.id
");
$stmt->execute([$business_id, $customer_id, $business_id]);
$customer = $stmt->fetch();

if (!$customer) {
    $_SESSION['error'] = "Customer not found.";
    header('Location: customers.php');
    exit();
}

// Calculate total outstanding (manual + invoice)
$manual_outstanding = ($customer['outstanding_type'] == 'debit') ? -$customer['outstanding_amount'] : $customer['outstanding_amount'];
$total_outstanding = $manual_outstanding + $customer['invoice_outstanding'];

// Calculate credit limit usage
$credit_limit = $customer['credit_limit'] ?? 0;
$available_credit = max(0, $credit_limit - $total_outstanding);
$credit_used = min($total_outstanding, $credit_limit);
$credit_utilization = $credit_limit > 0 ? ($credit_used / $credit_limit) * 100 : 0;

// =============================
// FETCH COMPLETE CREDIT/DEBIT STATEMENT
// =============================
$statement_data = [];

// 1. Initial outstanding from customer record (if any)
if ($customer['outstanding_amount'] > 0) {
    $statement_data[] = [
        'date' => $customer['created_at'],
        'type' => 'opening_balance',
        'description' => 'Opening Balance',
        'credit' => $customer['outstanding_type'] == 'credit' ? $customer['outstanding_amount'] : 0,
        'debit' => $customer['outstanding_type'] == 'debit' ? $customer['outstanding_amount'] : 0,
        'balance' => $customer['outstanding_type'] == 'credit' ? $customer['outstanding_amount'] : -$customer['outstanding_amount'],
        'reference' => 'SYSTEM',
        'invoice_id' => null,
        'payment_id' => null
    ];
}

// 2. All invoices (credit transactions)
$invoices_stmt = $pdo->prepare("
    SELECT id, invoice_number, created_at, total, pending_amount, 
           paid_amount, payment_status, cash_received, change_given
    FROM invoices
    WHERE customer_id = ? AND business_id = ?
    ORDER BY created_at ASC
");
$invoices_stmt->execute([$customer_id, $business_id]);
$invoices = $invoices_stmt->fetchAll();

foreach ($invoices as $inv) {
    $statement_data[] = [
        'date' => $inv['created_at'],
        'type' => 'invoice',
        'description' => 'Invoice: ' . $inv['invoice_number'],
        'credit' => $inv['total'],
        'debit' => 0,
        'balance' => 0, // Will calculate in next step
        'reference' => $inv['invoice_number'],
        'invoice_id' => $inv['id'],
        'payment_id' => null
    ];
    
    // If invoice was partially paid on creation, add that payment immediately
    if ($inv['paid_amount'] > 0) {
        $statement_data[] = [
            'date' => $inv['created_at'],
            'type' => 'payment',
            'description' => 'Payment against Invoice: ' . $inv['invoice_number'] . ' (initial)',
            'credit' => 0,
            'debit' => $inv['paid_amount'],
            'balance' => 0,
            'reference' => $inv['invoice_number'],
            'invoice_id' => $inv['id'],
            'payment_id' => null
        ];
    }
}

// 3. All invoice payments (debit transactions)
$payments_stmt = $pdo->prepare("
    SELECT ip.*, i.invoice_number, u.full_name as recorded_by
    FROM invoice_payments ip
    LEFT JOIN invoices i ON ip.invoice_id = i.id
    LEFT JOIN users u ON ip.created_by = u.id
    WHERE ip.customer_id = ? AND ip.business_id = ?
    ORDER BY ip.payment_date ASC, ip.created_at ASC
");
$payments_stmt->execute([$customer_id, $business_id]);
$payments = $payments_stmt->fetchAll();

foreach ($payments as $pay) {
    $statement_data[] = [
        'date' => $pay['payment_date'] . ' ' . date('H:i:s', strtotime($pay['created_at'])),
        'type' => 'payment',
        'description' => 'Payment - ' . ($pay['notes'] ?? 'Against Invoice: ' . $pay['invoice_number']),
        'credit' => 0,
        'debit' => $pay['payment_amount'],
        'balance' => 0,
        'reference' => $pay['invoice_number'] ?? $pay['reference_no'] ?? 'PAY-' . $pay['id'],
        'invoice_id' => $pay['invoice_id'],
        'payment_id' => $pay['id']
    ];
}

// 4. All manual credit adjustments
$adjustments_stmt = $pdo->prepare("
    SELECT * FROM customer_credit_adjustments 
    WHERE customer_id = ? AND business_id = ?
    ORDER BY adjustment_date ASC, created_at ASC
");
$adjustments_stmt->execute([$customer_id, $business_id]);
$adjustments = $adjustments_stmt->fetchAll();

foreach ($adjustments as $adj) {
    if ($adj['adjustment_type'] == 'credit') {
        $statement_data[] = [
            'date' => $adj['adjustment_date'] . ' ' . date('H:i:s', strtotime($adj['created_at'])),
            'type' => 'adjustment_credit',
            'description' => 'Credit Adjustment: ' . ($adj['description'] ?? 'Manual adjustment'),
            'credit' => $adj['amount'],
            'debit' => 0,
            'balance' => 0,
            'reference' => 'ADJ-CR-' . $adj['id'],
            'invoice_id' => null,
            'payment_id' => null
        ];
    } else {
        $statement_data[] = [
            'date' => $adj['adjustment_date'] . ' ' . date('H:i:s', strtotime($adj['created_at'])),
            'type' => 'adjustment_debit',
            'description' => 'Debit Adjustment: ' . ($adj['description'] ?? 'Manual adjustment'),
            'credit' => 0,
            'debit' => $adj['amount'],
            'balance' => 0,
            'reference' => 'ADJ-DR-' . $adj['id'],
            'invoice_id' => null,
            'payment_id' => null
        ];
    }
}

// Sort all transactions by date
usort($statement_data, function($a, $b) {
    return strtotime($a['date']) <=> strtotime($b['date']);
});

// Calculate running balance
$running_balance = 0;
foreach ($statement_data as &$transaction) {
    if ($transaction['type'] == 'opening_balance') {
        $running_balance = $transaction['balance'];
        $transaction['balance'] = $running_balance;
    } else {
        $running_balance += $transaction['credit'];
        $running_balance -= $transaction['debit'];
        $transaction['balance'] = $running_balance;
    }
}

// Calculate totals for summary
$total_credit = array_sum(array_column($statement_data, 'credit'));
$total_debit = array_sum(array_column($statement_data, 'debit'));
$opening_balance = $statement_data[0]['balance'] ?? 0;
$closing_balance = end($statement_data)['balance'] ?? $opening_balance;

// =============================
// EXISTING LOGIC (for backward compatibility)
// =============================

// Fetch all invoices with pending amount > 0 (for unpaid invoices section)
$unpaid_invoices_stmt = $pdo->prepare("
    SELECT i.id, i.invoice_number, i.created_at, i.total, i.pending_amount,
           i.paid_amount, i.payment_status, i.cash_received, i.change_given
    FROM invoices i
    WHERE i.customer_id = ? AND i.business_id = ? AND i.pending_amount > 0
    ORDER BY i.created_at ASC
");
$unpaid_invoices_stmt->execute([$customer_id, $business_id]);
$unpaid_invoices = $unpaid_invoices_stmt->fetchAll();

// Process overall payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['overall_payment'])) {
        $payment_amount = (float)($_POST['payment_amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $reference = trim($_POST['reference'] ?? '');
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($payment_amount <= 0 || $payment_amount > $total_outstanding) {
            $_SESSION['error'] = "Invalid payment amount. Must be between ₹0.01 and ₹" . number_format($total_outstanding, 2);
            header("Location: customer_credit_statement.php?customer_id=$customer_id");
            exit();
        }
        
        try {
            $pdo->beginTransaction();
            
            $remaining_payment = $payment_amount;
            
            // Distribute payment across invoices (oldest first)
            foreach ($unpaid_invoices as $invoice) {
                if ($remaining_payment <= 0) break;
                
                $invoice_payment = min($remaining_payment, $invoice['pending_amount']);
                
                if ($invoice_payment > 0) {
                    // Calculate new amounts for this invoice
                    $new_paid = $invoice['paid_amount'] + $invoice_payment;
                    $new_pending = $invoice['pending_amount'] - $invoice_payment;
                    
                    // Determine new payment status
                    if ($new_pending <= 0) {
                        $new_status = 'paid';
                    } elseif ($new_paid > 0) {
                        $new_status = 'partial';
                    } else {
                        $new_status = 'pending';
                    }
                    
                    // Update invoice with payment details
                    $update_stmt = $pdo->prepare("
                        UPDATE invoices 
                        SET paid_amount = ?, 
                            pending_amount = ?,
                            payment_status = ?,
                            cash_amount = cash_amount + ?,
                            upi_amount = upi_amount + ?,
                            bank_amount = bank_amount + ?,
                            cheque_amount = cheque_amount + ?,
                            upi_reference = COALESCE(?, upi_reference),
                            bank_reference = COALESCE(?, bank_reference),
                            cheque_number = COALESCE(?, cheque_number),
                            updated_at = NOW()
                        WHERE id = ? AND business_id = ?
                    ");
                    
                    $cash_amt = $payment_method === 'cash' ? $invoice_payment : 0;
                    $upi_amt = $payment_method === 'upi' ? $invoice_payment : 0;
                    $bank_amt = $payment_method === 'bank' ? $invoice_payment : 0;
                    $cheque_amt = $payment_method === 'cheque' ? $invoice_payment : 0;
                    $upi_ref = $payment_method === 'upi' ? $reference : null;
                    $bank_ref = $payment_method === 'bank' ? $reference : null;
                    $cheque_no = $payment_method === 'cheque' ? $reference : null;
                    
                    $update_stmt->execute([
                        $new_paid,
                        $new_pending,
                        $new_status,
                        $cash_amt,
                        $upi_amt,
                        $bank_amt,
                        $cheque_amt,
                        $upi_ref,
                        $bank_ref,
                        $cheque_no,
                        $invoice['id'],
                        $business_id
                    ]);
                    
                    // Record individual invoice payment
                    try {
                        $payment_stmt = $pdo->prepare("
                            INSERT INTO invoice_payments 
                            (business_id, invoice_id, customer_id, payment_amount, 
                             payment_date, payment_method, reference_no, 
                             notes, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $payment_stmt->execute([
                            $business_id,
                            $invoice['id'],
                            $customer_id,
                            $invoice_payment,
                            $payment_date,
                            $payment_method,
                            $reference,
                            "Overall payment - " . $notes,
                            $_SESSION['user_id']
                        ]);
                    } catch (Exception $e) {
                        error_log("Failed to record payment: " . $e->getMessage());
                    }
                    
                    $remaining_payment -= $invoice_payment;
                }
            }
            
            // If there's remaining payment after clearing invoices, adjust manual outstanding
            if ($remaining_payment > 0 && $manual_outstanding > 0) {
                // Reduce manual outstanding
                $new_manual_outstanding = max(0, $manual_outstanding - $remaining_payment);
                $new_outstanding_type = $new_manual_outstanding >= 0 ? 'credit' : 'debit';
                
                $update_customer_stmt = $pdo->prepare("
                    UPDATE customers 
                    SET outstanding_amount = ?, outstanding_type = ?
                    WHERE id = ? AND business_id = ?
                ");
                $update_customer_stmt->execute([
                    abs($new_manual_outstanding),
                    $new_outstanding_type,
                    $customer_id,
                    $business_id
                ]);
            }
            
            // Record overall payment summary
            try {
                $summary_stmt = $pdo->prepare("
                    INSERT INTO customer_payments 
                    (business_id, customer_id, total_amount, payment_method, 
                     reference_no, payment_date, notes, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $summary_stmt->execute([
                    $business_id,
                    $customer_id,
                    $payment_amount,
                    $payment_method,
                    $reference,
                    $payment_date,
                    "Overall payment distributed across invoices - " . $notes,
                    $_SESSION['user_id']
                ]);
            } catch (Exception $e) {
                error_log("Failed to record overall payment: " . $e->getMessage());
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = "Overall payment of ₹" . number_format($payment_amount, 2) . " successfully distributed!";
            header("Location: customer_credit_statement.php?customer_id=$customer_id");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Failed to process payment: " . $e->getMessage();
            header("Location: customer_credit_statement.php?customer_id=$customer_id");
            exit();
        }
    }
    
    // Handle manual credit adjustment
    if (isset($_POST['manual_adjustment'])) {
        $adjustment_type = $_POST['adjustment_type'] ?? 'credit';
        $adjustment_amount = (float)($_POST['adjustment_amount'] ?? 0);
        $adjustment_date = $_POST['adjustment_date'] ?? date('Y-m-d');
        $description = trim($_POST['adjustment_description'] ?? '');
        
        if ($adjustment_amount <= 0) {
            $_SESSION['error'] = "Adjustment amount must be greater than 0.";
            header("Location: customer_credit_statement.php?customer_id=$customer_id");
            exit();
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO customer_credit_adjustments 
                (business_id, customer_id, adjustment_type, amount, 
                 adjustment_date, description, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $business_id,
                $customer_id,
                $adjustment_type,
                $adjustment_amount,
                $adjustment_date,
                $description,
                $_SESSION['user_id']
            ]);
            
            // Update customer's outstanding amount
            if ($adjustment_type == 'credit') {
                $new_outstanding = $customer['outstanding_amount'] + $adjustment_amount;
                $outstanding_type = 'credit';
            } else {
                $new_outstanding = $customer['outstanding_amount'] - $adjustment_amount;
                if ($new_outstanding < 0) {
                    $new_outstanding = abs($new_outstanding);
                    $outstanding_type = 'debit';
                } else {
                    $outstanding_type = 'credit';
                }
            }
            
            $update_stmt = $pdo->prepare("
                UPDATE customers 
                SET outstanding_amount = ?, outstanding_type = ?
                WHERE id = ? AND business_id = ?
            ");
            $update_stmt->execute([
                $new_outstanding,
                $outstanding_type,
                $customer_id,
                $business_id
            ]);
            
            $_SESSION['success'] = "Manual " . $adjustment_type . " adjustment of ₹" . number_format($adjustment_amount, 2) . " added successfully!";
            header("Location: customer_credit_statement.php?customer_id=$customer_id");
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to add adjustment: " . $e->getMessage();
            header("Location: customer_credit_statement.php?customer_id=$customer_id");
            exit();
        }
    }
}

// Fetch payment history
$payments_history_stmt = $pdo->prepare("
    SELECT ip.payment_amount, ip.payment_method, ip.reference_no, 
           ip.payment_date, ip.notes, u.full_name as recorded_by
    FROM invoice_payments ip
    LEFT JOIN users u ON ip.created_by = u.id
    WHERE ip.customer_id = ? AND ip.business_id = ?
    ORDER BY ip.payment_date DESC, ip.created_at DESC
");
$payments_history_stmt->execute([$customer_id, $business_id]);
$payments_history = $payments_history_stmt->fetchAll();
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Credit Statement - " . htmlspecialchars($customer['name']); include 'includes/head.php'; ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php') ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-file me-2"></i> Credit Statement - <?= htmlspecialchars($customer['name']) ?>
                                </h4>
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb mb-0">
                                        <li class="breadcrumb-item"><a href="customers.php">Customers</a></li>
                                        <li class="breadcrumb-item active">Credit Statement</li>
                                    </ol>
                                </nav>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" onclick="window.print()">
                                    <i class="bx bx-printer me-1"></i> Print
                                </button>
                                <a href="customers.php" class="btn btn-outline-primary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Customers
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= $_SESSION['success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= $_SESSION['error'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <!-- Customer Summary Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-lg-8">
                                <h5 class="mb-3"><?= htmlspecialchars($customer['name']) ?></h5>
                                <?php if ($customer['phone']): ?>
                                <p class="mb-1"><i class="bx bx-phone me-2"></i><?= htmlspecialchars($customer['phone']) ?></p>
                                <?php endif; ?>
                                <?php if ($customer['email']): ?>
                                <p class="mb-1"><i class="bx bx-envelope me-2"></i><?= htmlspecialchars($customer['email']) ?></p>
                                <?php endif; ?>
                                <?php if ($customer['address']): ?>
                                <p class="mb-1"><i class="bx bx-map me-2"></i><?= nl2br(htmlspecialchars($customer['address'])) ?></p>
                                <?php endif; ?>
                                <?php if ($customer['gstin']): ?>
                                <p class="mb-0"><i class="bx bx-barcode me-2"></i>GSTIN: <?= htmlspecialchars($customer['gstin']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-lg-4 text-lg-end">
                                <div class="border-start border-danger border-4 ps-4">
                                    <h6 class="text-muted">Total Outstanding Dues</h6>
                                    <h2 class="<?= $total_outstanding > 0 ? 'text-danger' : ($total_outstanding < 0 ? 'text-success' : 'text-muted') ?> mb-0">
                                        ₹<?= number_format(abs($total_outstanding), 2) ?>
                                    </h2>
                                    <small class="text-muted">
                                        <?php if ($total_outstanding > 0): ?>
                                        Customer Owes You
                                        <?php elseif ($total_outstanding < 0): ?>
                                        You Owe Customer
                                        <?php else: ?>
                                        No Dues
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Credit Limit Info -->
                        <?php if ($credit_limit > 0): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h6 class="text-muted mb-2"><i class="bx bx-credit-card me-2"></i>Credit Limit Information</h6>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center p-2">
                                                <h6 class="text-muted mb-1">Credit Limit</h6>
                                                <h4 class="text-primary mb-0">₹<?= number_format($credit_limit, 2) ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center p-2">
                                                <h6 class="text-muted mb-1">Used</h6>
                                                <h4 class="text-warning mb-0">₹<?= number_format($credit_used, 2) ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center p-2">
                                                <h6 class="text-muted mb-1">Available</h6>
                                                <h4 class="text-success mb-0">₹<?= number_format($available_credit, 2) ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center p-2">
                                                <h6 class="text-muted mb-1">Utilization</h6>
                                                <h4 class="<?= $credit_utilization > 80 ? 'text-danger' : ($credit_utilization > 50 ? 'text-warning' : 'text-success') ?> mb-0">
                                                    <?= number_format($credit_utilization, 1) ?>%
                                                </h4>
                                                <div class="progress mt-2" style="height: 6px;">
                                                    <div class="progress-bar bg-<?= $credit_utilization > 80 ? 'danger' : ($credit_utilization > 50 ? 'warning' : 'success') ?>" 
                                                         role="progressbar" 
                                                         style="width: <?= min($credit_utilization, 100) ?>%"
                                                         aria-valuenow="<?= $credit_utilization ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ============================= -->
                <!-- COMPLETE CREDIT/DEBIT STATEMENT -->
                <!-- ============================= -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bx bx-list-check me-2"></i> Complete Credit/Debit Statement</h5>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addAdjustmentModal">
                                <i class="bx bx-plus-circle me-1"></i> Manual Adjustment
                            </button>
                            <a href="statement_export.php?customer_id=<?= $customer_id ?>" class="btn btn-sm btn-outline-success">
                                <i class="bx bx-download me-1"></i> Export Excel
                            </a>
                            <a href="statement_pdf.php?customer_id=<?= $customer_id ?>" class="btn btn-sm btn-outline-danger" target="_blank">
                                <i class="bx bx-file me-1"></i> Export PDF
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Statement Summary -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center p-3">
                                        <h6 class="text-muted mb-1">Opening Balance</h6>
                                        <h4 class="<?= $opening_balance >= 0 ? 'text-danger' : 'text-success' ?> mb-0">
                                            ₹<?= number_format(abs($opening_balance), 2) ?>
                                        </h4>
                                        <small class="text-muted">
                                            <?= $opening_balance >= 0 ? 'Cr (Customer Owes)' : 'Dr (You Owe)' ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center p-3">
                                        <h6 class="text-muted mb-1">Total Credit</h6>
                                        <h4 class="text-success mb-0">₹<?= number_format($total_credit, 2) ?></h4>
                                        <small class="text-muted">Amount Added</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center p-3">
                                        <h6 class="text-muted mb-1">Total Debit</h6>
                                        <h4 class="text-danger mb-0">₹<?= number_format($total_debit, 2) ?></h4>
                                        <small class="text-muted">Amount Received</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center p-3">
                                        <h6 class="text-muted mb-1">Closing Balance</h6>
                                        <h4 class="<?= $closing_balance >= 0 ? 'text-danger' : 'text-success' ?> mb-0">
                                            ₹<?= number_format(abs($closing_balance), 2) ?>
                                        </h4>
                                        <small class="text-muted">
                                            <?= $closing_balance >= 0 ? 'Cr (Customer Owes)' : 'Dr (You Owe)' ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Statement Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle" id="statementTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="120">Date</th>
                                        <th width="150">Type</th>
                                        <th>Description</th>
                                        <th width="120">Reference</th>
                                        <th width="120" class="text-end">Credit (₹)</th>
                                        <th width="120" class="text-end">Debit (₹)</th>
                                        <th width="140" class="text-end">Balance (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($statement_data)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="bx bx-file-empty fs-1 text-muted mb-2 d-block"></i>
                                            <h6 class="text-muted">No transactions found</h6>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($statement_data as $transaction): 
                                        $type_class = '';
                                        switch ($transaction['type']) {
                                            case 'invoice': $type_class = 'bg-info bg-opacity-10 text-info'; break;
                                            case 'payment': $type_class = 'bg-success bg-opacity-10 text-success'; break;
                                            case 'adjustment_credit': $type_class = 'bg-warning bg-opacity-10 text-warning'; break;
                                            case 'adjustment_debit': $type_class = 'bg-warning bg-opacity-10 text-warning'; break;
                                            case 'opening_balance': $type_class = 'bg-secondary bg-opacity-10 text-secondary'; break;
                                        }
                                        
                                        $type_text = '';
                                        switch ($transaction['type']) {
                                            case 'invoice': $type_text = 'Invoice'; break;
                                            case 'payment': $type_text = 'Payment'; break;
                                            case 'adjustment_credit': $type_text = 'Credit Adj'; break;
                                            case 'adjustment_debit': $type_text = 'Debit Adj'; break;
                                            case 'opening_balance': $type_text = 'Opening'; break;
                                        }
                                    ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($transaction['date'])) ?></td>
                                        <td>
                                            <span class="badge <?= $type_class ?>">
                                                <?= $type_text ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($transaction['description']) ?></td>
                                        <td>
                                            <?php if ($transaction['invoice_id']): ?>
                                            <a href="invoice_view.php?invoice_id=<?= $transaction['invoice_id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($transaction['reference']) ?>
                                            </a>
                                            <?php else: ?>
                                            <?= htmlspecialchars($transaction['reference']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end <?= $transaction['credit'] > 0 ? 'text-success fw-bold' : '' ?>">
                                            <?php if ($transaction['credit'] > 0): ?>
                                            ₹<?= number_format($transaction['credit'], 2) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end <?= $transaction['debit'] > 0 ? 'text-danger fw-bold' : '' ?>">
                                            <?php if ($transaction['debit'] > 0): ?>
                                            ₹<?= number_format($transaction['debit'], 2) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold <?= $transaction['balance'] >= 0 ? 'text-danger' : 'text-success' ?>">
                                            ₹<?= number_format(abs($transaction['balance']), 2) ?>
                                            <small class="d-block text-muted">
                                                <?= $transaction['balance'] >= 0 ? 'Cr' : 'Dr' ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($statement_data)): ?>
                                <tfoot class="table-active">
                                    <tr>
                                        <th colspan="4" class="text-end">Totals:</th>
                                        <th class="text-end text-success">₹<?= number_format($total_credit, 2) ?></th>
                                        <th class="text-end text-danger">₹<?= number_format($total_debit, 2) ?></th>
                                        <th class="text-end fw-bold <?= $closing_balance >= 0 ? 'text-danger' : 'text-success' ?>">
                                            ₹<?= number_format(abs($closing_balance), 2) ?>
                                            <small class="d-block"><?= $closing_balance >= 0 ? 'Cr (Customer Owes)' : 'Dr (You Owe)' ?></small>
                                        </th>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Overall Payment Form -->
                <?php if ($total_outstanding > 0): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bx bx-money me-2"></i> Collect Overall Payment</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="overallPaymentForm">
                            <input type="hidden" name="overall_payment" value="1">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" name="payment_amount" id="paymentAmount" 
                                               class="form-control form-control-lg text-end" 
                                               step="0.01" min="0.01" max="<?= $total_outstanding ?>" 
                                               value="<?= $total_outstanding ?>" required>
                                    </div>
                                    <small class="text-muted">Max: ₹<?= number_format($total_outstanding, 2) ?></small>
                                    <div class="btn-group btn-group-sm mt-2">
                                        <button type="button" class="btn btn-outline-primary" onclick="setPaymentAmount(<?= $total_outstanding * 0.25 ?>)">25%</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setPaymentAmount(<?= $total_outstanding * 0.5 ?>)">50%</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setPaymentAmount(<?= $total_outstanding * 0.75 ?>)">75%</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setPaymentAmount(<?= $total_outstanding ?>)">100%</button>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Payment Date</label>
                                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method" class="form-select" id="paymentMethod">
                                        <option value="cash">Cash</option>
                                        <option value="upi">UPI</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="cheque">Cheque</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" id="referenceLabel">Reference (Optional)</label>
                                    <input type="text" name="reference" class="form-control" id="referenceInput" 
                                           placeholder="Enter reference...">
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes about this overall payment..."></textarea>
                                </div>
                                
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Note:</strong> This payment will be automatically distributed across all unpaid invoices (oldest first).
                                    </div>
                                </div>
                            </div>

                            <div class="text-end mt-4">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bx bx-check-circle me-2"></i> Collect Overall Payment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-success text-center py-4 mb-4">
                    <i class="bx bx-check-circle fs-1"></i>
                    <h5 class="mt-3">No Outstanding Dues</h5>
                    <p>This customer has cleared all payments.</p>
                </div>
                <?php endif; ?>

                <!-- Unpaid Invoices -->
                <?php if (!empty($unpaid_invoices)): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bx bx-receipt me-2"></i> Unpaid / Partially Paid Invoices</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle">
                                <thead class="table-danger">
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Total Amount</th>
                                        <th>Paid Amount</th>
                                        <th>Pending Amount</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $running_balance = 0;
                                    foreach ($unpaid_invoices as $inv): 
                                        $running_balance += $inv['pending_amount'];
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($inv['invoice_number']) ?></strong></td>
                                        <td><?= date('d M Y', strtotime($inv['created_at'])) ?></td>
                                        <td class="text-end">₹<?= number_format($inv['total'], 2) ?></td>
                                        <td class="text-end text-success">₹<?= number_format($inv['paid_amount'], 2) ?></td>
                                        <td class="text-end text-danger fw-bold">₹<?= number_format($inv['pending_amount'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $inv['payment_status'] === 'paid' ? 'success' : ($inv['payment_status'] === 'partial' ? 'warning' : 'danger') ?>">
                                                <?= ucfirst($inv['payment_status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="invoice_view.php?invoice_id=<?= $inv['id'] ?>" 
                                               class="btn btn-sm btn-outline-info">
                                                <i class="bx bx-show"></i> View
                                            </a>
                                            <?php if ($inv['pending_amount'] > 0): ?>
                                            <a href="collect_payment.php?invoice_id=<?= $inv['id'] ?>" 
                                               class="btn btn-sm btn-success">
                                                <i class="bx bx-money"></i> Collect
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-active fw-bold">
                                        <td colspan="4" class="text-end">Total Outstanding</td>
                                        <td class="text-end text-danger">₹<?= number_format($customer['invoice_outstanding'], 2) ?></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment History -->
                <?php if (!empty($payments_history)): ?>
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bx bx-history me-2"></i> Payment History</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Notes</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments_history as $p): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                                        <td class="text-success fw-bold">₹<?= number_format($p['payment_amount'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-primary bg-opacity-10 text-primary">
                                                <?= ucfirst($p['payment_method']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($p['reference_no'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['notes'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['recorded_by'] ?? 'System') ?></td>
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
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Manual Adjustment Modal -->
<div class="modal fade" id="addAdjustmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="manual_adjustment" value="1">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bx bx-edit-alt me-2"></i> Manual Credit/Debit Adjustment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select name="adjustment_type" class="form-select" id="adjustmentType">
                            <option value="credit">Credit (Customer owes you more)</option>
                            <option value="debit">Debit (You owe customer)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Amount (₹) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" name="adjustment_amount" class="form-control" 
                                   step="0.01" min="0.01" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Adjustment Date</label>
                        <input type="date" name="adjustment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <textarea name="adjustment_description" class="form-control" rows="3" 
                                  placeholder="Reason for this adjustment..." required></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bx bx-info-circle me-2"></i>
                        <strong>Note:</strong> 
                        <span id="adjustmentNote">
                            Credit adjustments increase customer's outstanding balance.
                        </span>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .rightbar, #overallPaymentForm, .btn, 
    .card-header .btn, #addAdjustmentModal, .modal { display: none !important; }
    body { padding: 20px !important; background: white !important; }
    .card { box-shadow: none; border: 1px solid #ddd; }
    .page-content { margin-left: 0 !important; }
}
.border-start.border-4 { border-left-width: 6px !important; }
.table thead th { background-color: #f8d7da !important; color: #721c24; }
#statementTable tbody tr:hover { background-color: #f8f9fa; }
</style>

<script>
function setPaymentAmount(amount) {
    const input = document.getElementById('paymentAmount');
    const maxAmount = <?= $total_outstanding ?>;
    const roundedAmount = Math.min(amount, maxAmount).toFixed(2);
    input.value = roundedAmount;
}

// Update reference placeholder based on payment method
document.getElementById('paymentMethod').addEventListener('change', function() {
    const method = this.value;
    const referenceLabel = document.getElementById('referenceLabel');
    const referenceInput = document.getElementById('referenceInput');
    
    switch(method) {
        case 'upi':
            referenceLabel.innerHTML = 'UPI Reference/ID <span class="text-muted">(Optional)</span>';
            referenceInput.placeholder = 'Enter UPI transaction ID or UPI ID...';
            break;
        case 'bank':
            referenceLabel.innerHTML = 'Bank Reference <span class="text-muted">(Optional)</span>';
            referenceInput.placeholder = 'Enter transaction reference or bank details...';
            break;
        case 'cheque':
            referenceLabel.innerHTML = 'Cheque Number <span class="text-muted">(Optional)</span>';
            referenceInput.placeholder = 'Enter cheque number...';
            break;
        default:
            referenceLabel.innerHTML = 'Reference <span class="text-muted">(Optional)</span>';
            referenceInput.placeholder = 'Enter reference...';
    }
});

// Update adjustment note based on type
document.getElementById('adjustmentType').addEventListener('change', function() {
    const note = document.getElementById('adjustmentNote');
    if (this.value === 'credit') {
        note.textContent = 'Credit adjustments increase customer\'s outstanding balance.';
    } else {
        note.textContent = 'Debit adjustments reduce customer\'s outstanding balance (or create advance).';
    }
});

// Form validation for overall payment
document.getElementById('overallPaymentForm').addEventListener('submit', function(e) {
    const amountInput = document.getElementById('paymentAmount');
    const amount = parseFloat(amountInput.value);
    const maxAmount = <?= $total_outstanding ?>;
    
    if (!amount || amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid payment amount');
        amountInput.focus();
        return false;
    }
    
    if (amount > maxAmount) {
        e.preventDefault();
        alert('Payment amount cannot exceed total outstanding of ₹' + maxAmount.toFixed(2));
        amountInput.value = maxAmount.toFixed(2);
        amountInput.focus();
        return false;
    }
    
    if (!confirm(`Are you sure you want to collect ₹${amount.toFixed(2)} overall payment?\nThis will be distributed across all unpaid invoices.`)) {
        e.preventDefault();
        return false;
    }
    
    // Disable button to prevent double submission
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bx bx-loader bx-spin me-2"></i> Processing...';
    
    return true;
});

// Initialize payment method reference
document.getElementById('paymentMethod').dispatchEvent(new Event('change'));
document.getElementById('adjustmentType').dispatchEvent(new Event('change'));

// Initialize DataTable for statement
$(document).ready(function() {
    $('#statementTable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search transactions:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });
    
    $('[data-bs-toggle="tooltip"]').tooltip();
});
</script>
</body>
</html>