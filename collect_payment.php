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

$invoiceState = normalizeInvoicePaymentState($invoice); 
$total_amount = $invoiceState['total']; 
$paid_amount = $invoiceState['paid']; 
$pending_amount = $invoiceState['pending']; 
$balance_amount = $invoiceState['pending']; 

if ($invoiceState['status'] === 'paid' || $balance_amount <= 0) { 
    $_SESSION['error'] = "This invoice is already fully paid."; 
    header("Location: invoice_view.php?invoice_id=$invoice_id"); 
    exit(); 
} 

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
            
            // Record payment in invoice_payments 
            $paymentStmt = $pdo->prepare(" 
                INSERT INTO invoice_payments (business_id, invoice_id, customer_id, payment_amount, payment_date, payment_method, reference_no, notes, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) 
            "); 
            $paymentStmt->execute([ 
                $business_id, $invoice_id, $liveInvoice['customer_id'] ?? null, 
                $payment_amount, $payment_date, $payment_method, 
                $reference !== '' ? $reference : null, 
                $notes !== '' ? $notes : null, 
                $user_id 
            ]); 
            
            // Store summary row for customer ledger 
            $summaryStmt = $pdo->prepare(" 
                INSERT INTO customer_payments (business_id, customer_id, invoice_id, total_amount, payment_method, reference_no, payment_date, notes, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) 
            "); 
            $summaryStmt->execute([ 
                $business_id, $liveInvoice['customer_id'] ?? null, $invoice_id, 
                $payment_amount, $payment_method, 
                $reference !== '' ? $reference : null, 
                $payment_date, $notes !== '' ? $notes : null, 
                $user_id 
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
    SELECT payment_amount AS amount, payment_method, reference_no AS reference, payment_date, notes, created_by, created_at 
    FROM invoice_payments 
    WHERE invoice_id = ? AND business_id = ? 
    ORDER BY payment_date DESC, created_at DESC, id DESC 
"); 
$paymentsStmt->execute([$invoice_id, $business_id]); 
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
                                                <th>Collected By</th>
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
                                                    <td><?= !empty($pay['created_by']) ? 'User #' . (int) $pay['created_by'] : '—'; ?>
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
    <script> document.addEventListener('DOMContentLoaded', function () { const amountInput = document.getElementById('payment_amount'); const methodSelect = document.getElementById('payment_method'); const refLabel = document.getElementById('reference_label'); const payHalfBtn = document.getElementById('pay_half_btn'); const payFullBtn = document.getElementById('pay_full_btn'); const balanceAmount = <?= json_encode((float) $balance_amount); ?>; function updateReferenceLabel() { const method = methodSelect.value; if (method === 'upi') { refLabel.textContent = 'UPI Reference'; } else if (method === 'bank') { refLabel.textContent = 'Bank Reference'; } else if (method === 'cheque') { refLabel.textContent = 'Cheque Number'; } else if (method === 'credit_card') { refLabel.textContent = 'Card Reference'; } else { refLabel.textContent = 'Reference / Transaction No'; } } if (methodSelect) { methodSelect.addEventListener('change', updateReferenceLabel); updateReferenceLabel(); } if (payHalfBtn) { payHalfBtn.addEventListener('click', function () { amountInput.value = (Math.round((balanceAmount / 2) * 100) / 100).toFixed(2); }); } if (payFullBtn) { payFullBtn.addEventListener('click', function () { amountInput.value = balanceAmount.toFixed(2); }); } }); </script>
</body>

</html>