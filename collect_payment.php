<?php
// collect_payment.php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['current_business_id'] ?? 1;

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if (!$invoice_id) {
    header('Location: invoices.php');
    exit();
}

$success = $error = '';

// Fetch invoice details
$stmt = $pdo->prepare("
    SELECT i.*, c.name as customer_name, c.phone as customer_phone,
           c.address as customer_address
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.id = ? AND i.business_id = ?
");
$stmt->execute([$invoice_id, $business_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    $_SESSION['error'] = "Invoice not found!";
    header('Location: invoices.php');
    exit();
}

// Calculate amounts - Using correct column names from your invoices table
$total_amount = (float)($invoice['total'] ?? 0);
$paid_amount = (float)($invoice['paid_amount'] ?? 0);
$pending_amount = (float)($invoice['pending_amount'] ?? 0);

// Calculate balance amount based on payment_status
if ($invoice['payment_status'] === 'paid') {
    $balance_amount = 0;
} else {
    $balance_amount = $total_amount - $paid_amount;
    
    // If pending_amount exists, use that for accuracy
    if ($pending_amount > 0) {
        $balance_amount = $pending_amount;
    }
}

if ($balance_amount <= 0) {
    $_SESSION['error'] = "This invoice is already fully paid.";
    header("Location: invoice_view.php?invoice_id=$invoice_id");
    exit();
}

// Process payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_amount = (float)($_POST['payment_amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $reference = trim($_POST['reference'] ?? '');
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    // Get payment method specific fields
    $cash_amount = $payment_method === 'cash' ? $payment_amount : 0;
    $upi_amount = $payment_method === 'upi' ? $payment_amount : 0;
    $bank_amount = $payment_method === 'bank' ? $payment_amount : 0;
    $cheque_amount = $payment_method === 'cheque' ? $payment_amount : 0;
    $upi_reference = $payment_method === 'upi' ? $reference : null;
    $bank_reference = $payment_method === 'bank' ? $reference : null;
    $cheque_number = $payment_method === 'cheque' ? $reference : null;

    if ($payment_amount <= 0 || $payment_amount > $balance_amount) {
        $error = "Invalid payment amount. Must be between ₹0.01 and ₹" . number_format($balance_amount, 2);
    } else {
        try {
            $pdo->beginTransaction();

            // Calculate new amounts
            $new_paid = $paid_amount + $payment_amount;
            $new_pending = max(0, $total_amount - $new_paid);
            
            // Determine new payment status
            if ($new_paid >= $total_amount) {
                $new_status = 'paid';
            } elseif ($new_paid > 0) {
                $new_status = 'partial';
            } else {
                $new_status = 'pending';
            }

            // Update invoice with payment details
            $stmt = $pdo->prepare("
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
            $stmt->execute([
                $new_paid,
                $new_pending,
                $new_status,
                $cash_amount,
                $upi_amount,
                $bank_amount,
                $cheque_amount,
                $upi_reference,
                $bank_reference,
                $cheque_number,
                $invoice_id,
                $business_id
            ]);

            // Record payment in payments table (if it exists)
            try {
                // Check if payments table exists
                $table_check = $pdo->prepare("SHOW TABLES LIKE 'payments'");
                $table_check->execute();
                
                if ($table_check->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO payments 
                        (business_id, invoice_id, customer_id, amount, 
                         payment_date, payment_method, reference_number, 
                         notes, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $business_id,
                        $invoice_id,
                        $invoice['customer_id'] ?? null,
                        $payment_amount,
                        $payment_date,
                        $payment_method,
                        $reference,
                        $notes,
                        $user_id
                    ]);
                }
            } catch (Exception $e) {
                // Payments table might not exist, continue without error
                error_log("Payments table not found or error: " . $e->getMessage());
            }

            // Update loyalty points if payment is successful
            if ($new_status === 'paid' || $new_status === 'partial') {
                // Trigger points calculation (similar to your trigger logic)
                $total_amount = (float)($invoice['total'] ?? 0);
                $points_discount = (float)($invoice['points_discount_amount'] ?? 0);
                
                // Calculate basis for points (subtotal minus points discount)
                $subtotal = (float)($invoice['subtotal'] ?? $total_amount);
                $points_basis = $subtotal - $points_discount;
                
                if ($points_basis > 0) {
                    // Get loyalty settings
                    $loyalty_stmt = $pdo->prepare("
                        SELECT is_active, points_per_amount 
                        FROM loyalty_settings 
                        WHERE business_id = ?
                    ");
                    $loyalty_stmt->execute([$business_id]);
                    $loyalty = $loyalty_stmt->fetch();
                    
                    if ($loyalty && $loyalty['is_active'] == 1 && $loyalty['points_per_amount'] > 0) {
                        $points_earned = floor($points_basis * $loyalty['points_per_amount'] * 100) / 100;
                        
                        if ($points_earned > 0) {
                            // Update customer points
                            $points_stmt = $pdo->prepare("
                                INSERT INTO customer_points (customer_id, business_id, total_points_earned, available_points)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    total_points_earned = total_points_earned + ?,
                                    available_points = available_points + ?,
                                    last_updated = NOW()
                            ");
                            $points_stmt->execute([
                                $invoice['customer_id'],
                                $business_id,
                                $points_earned,
                                $points_earned,
                                $points_earned,
                                $points_earned
                            ]);
                            
                            // Log point transaction
                            $point_trans_stmt = $pdo->prepare("
                                INSERT INTO point_transactions 
                                (customer_id, business_id, invoice_id, transaction_type, points, amount_basis, created_by)
                                VALUES (?, ?, ?, 'earned', ?, ?, ?)
                            ");
                            $point_trans_stmt->execute([
                                $invoice['customer_id'],
                                $business_id,
                                $invoice_id,
                                $points_earned,
                                $points_basis,
                                $user_id
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
            $pdo->rollBack();
            $error = "Failed to record payment: " . $e->getMessage();
        }
    }
}

// Fetch payment history from invoice updates
$payments_stmt = $pdo->prepare("
    SELECT 
        i.updated_at as payment_date,
        (i.paid_amount - COALESCE(LAG(i.paid_amount) OVER (ORDER BY i.updated_at), 0)) as amount,
        CASE 
            WHEN i.cash_amount > 0 THEN 'cash'
            WHEN i.upi_amount > 0 THEN 'upi'
            WHEN i.bank_amount > 0 THEN 'bank'
            WHEN i.cheque_amount > 0 THEN 'cheque'
            ELSE 'unknown'
        END as payment_method,
        COALESCE(i.upi_reference, i.bank_reference, i.cheque_number) as reference,
        u.username as collected_by
    FROM invoices i
    LEFT JOIN users u ON i.seller_id = u.id
    WHERE i.id = ? AND i.business_id = ? AND i.paid_amount > 0
    ORDER BY i.updated_at DESC
    LIMIT 10
");
$payments_stmt->execute([$invoice_id, $business_id]);
$payments = $payments_stmt->fetchAll();
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Collect Payment - Invoice #" . htmlspecialchars($invoice['invoice_number']); include 'includes/head.php'; ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-money me-2"></i>
                                    Collect Payment
                                </h4>
                                <p class="mb-0 text-muted">
                                    Invoice #<strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong>
                                    • Customer: <strong><?= htmlspecialchars($invoice['customer_name'] ?? 'Walk-in Customer') ?></strong>
                                </p>
                            </div>
                            <a href="invoice_view.php?invoice_id=<?= $invoice_id ?>" class="btn btn-outline-primary">
                                <i class="bx bx-arrow-back me-1"></i> Back to Invoice
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Payment Cards -->
                <div class="row mb-4 g-3">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Invoice Total</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($total_amount, 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-receipt text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Paid So Far</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($paid_amount, 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-check-circle text-success"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Balance Due</h6>
                                        <h3 class="mb-0 text-danger">₹<?= number_format($balance_amount, 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-time-five text-danger"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm h-100">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Payment Status</h6>
                                        <span class="badge bg-<?= $invoice['payment_status'] === 'paid' ? 'success' : ($invoice['payment_status'] === 'partial' ? 'warning' : 'danger') ?> bg-opacity-10 text-<?= $invoice['payment_status'] === 'paid' ? 'success' : ($invoice['payment_status'] === 'partial' ? 'warning' : 'danger') ?> px-3 py-2 fs-5">
                                            <i class="bx bx-<?= $invoice['payment_status'] === 'paid' ? 'check-circle' : ($invoice['payment_status'] === 'partial' ? 'time-five' : 'x-circle') ?> me-1"></i>
                                            <?= ucfirst($invoice['payment_status']) ?>
                                        </span>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-credit-card text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Form -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-money me-2"></i> Record Payment
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" name="payment_amount" class="form-control form-control-lg text-end" 
                                               step="0.01" min="0.01" max="<?= $balance_amount ?>" 
                                               value="<?= $balance_amount ?>" required>
                                    </div>
                                    <small class="text-muted">Max: ₹<?= number_format($balance_amount, 2) ?></small>
                                    <div class="btn-group btn-group-sm mt-2">
                                        <button type="button" class="btn btn-outline-primary" onclick="setPaymentAmount(<?= $balance_amount * 0.25 ?>)">25%</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setPaymentAmount(<?= $balance_amount * 0.5 ?>)">50%</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setPaymentAmount(<?= $balance_amount * 0.75 ?>)">75%</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setPaymentAmount(<?= $balance_amount ?>)">100%</button>
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
                                    <textarea name="notes" class="form-control" rows="3" placeholder="Any additional notes about this payment..."></textarea>
                                </div>
                            </div>

                            <div class="text-end mt-4">
                                <a href="invoice_view.php?invoice_id=<?= $invoice_id ?>" class="btn btn-outline-secondary me-2">
                                    <i class="bx bx-x me-1"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bx bx-check-circle me-2"></i> Record Payment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Recent Payments -->
                <?php if ($payments && count($payments) > 0): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-history me-2"></i> Recent Payments
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Collected By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $p): ?>
                                    <?php if ($p['amount'] > 0): ?>
                                    <tr>
                                        <td><?= date('d M Y, h:i A', strtotime($p['payment_date'])) ?></td>
                                        <td class="text-success fw-bold">₹<?= number_format($p['amount'], 2) ?></td>
                                        <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= ucfirst($p['payment_method']) ?></span></td>
                                        <td><?= htmlspecialchars($p['reference'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['collected_by'] ?? 'System') ?></td>
                                    </tr>
                                    <?php endif; ?>
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

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
function setPaymentAmount(amount) {
    const input = document.querySelector('input[name="payment_amount"]');
    const maxAmount = <?= $balance_amount ?>;
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

// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
    const amountInput = document.querySelector('input[name="payment_amount"]');
    const amount = parseFloat(amountInput.value);
    const maxAmount = <?= $balance_amount ?>;
    
    if (!amount || amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid payment amount');
        amountInput.focus();
        return false;
    }
    
    if (amount > maxAmount) {
        e.preventDefault();
        alert('Payment amount cannot exceed balance due of ₹' + maxAmount.toFixed(2));
        amountInput.value = maxAmount.toFixed(2);
        amountInput.focus();
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
</script>

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
.avatar-sm {
    width: 48px;
    height: 48px;
}
</style>
</body>
</html>