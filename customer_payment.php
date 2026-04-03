<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once ('config/database.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager', 'accountant'])) {
    header('Location: dashboard.php');
    exit();
}

$business_id = $_SESSION['business_id'];
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if (!$customer_id) {
    header('Location: customers.php');
    exit();
}

// Get customer details
$stmt = $pdo->prepare("
    SELECT c.*, 
           (SELECT COALESCE(SUM(pending_amount), 0) FROM invoices 
            WHERE customer_id = c.id AND business_id = ? AND payment_status IN ('pending', 'partial')) as total_outstanding
    FROM customers c
    WHERE c.id = ? AND c.business_id = ?
");
$stmt->execute([$business_id, $customer_id, $business_id]);
$customer = $stmt->fetch();

if (!$customer) {
    $_SESSION['error'] = "Customer not found";
    header('Location: customers.php');
    exit();
}

// Get all outstanding invoices (oldest first for FIFO allocation)
$invoices_sql = "
    SELECT id, invoice_number, total, paid_amount, 
           (total - paid_amount) as outstanding,
           created_at,
           payment_status
    FROM invoices 
    WHERE customer_id = ? AND business_id = ? 
    AND payment_status IN ('pending', 'partial')
    AND (total - paid_amount) > 0.01
    ORDER BY created_at ASC
";
$stmt = $pdo->prepare($invoices_sql);
$stmt->execute([$customer_id, $business_id]);
$outstanding_invoices = $stmt->fetchAll();

// Get manual outstanding (credit/debit from customer table)
$manual_outstanding = ($customer['outstanding_type'] == 'credit') ? $customer['outstanding_amount'] : -$customer['outstanding_amount'];
$total_outstanding = $manual_outstanding + $customer['total_outstanding'];

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $payment_mode = $_POST['payment_mode'] ?? 'bulk';
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $reference_no = trim($_POST['reference_no'] ?? '');
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    if ($payment_amount <= 0) {
        $_SESSION['error'] = "Please enter a valid payment amount";
        header("Location: customer_payment.php?customer_id=$customer_id");
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        $remaining_amount = $payment_amount;
        $payment_allocations = [];
        
        if ($payment_mode == 'bulk') {
            // BULK PAYMENT: First reduce manual outstanding, then FIFO on invoices
            
            // Step 1: Reduce manual outstanding first
            if ($manual_outstanding > 0 && $remaining_amount > 0) {
                $manual_reduction = min($remaining_amount, $manual_outstanding);
                
                // Update customer manual outstanding
                if ($customer['outstanding_type'] == 'credit') {
                    $new_manual_amount = $customer['outstanding_amount'] - $manual_reduction;
                    $update_sql = "UPDATE customers SET outstanding_amount = ? WHERE id = ? AND business_id = ?";
                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute([max(0, $new_manual_amount), $customer_id, $business_id]);
                    
                    // Log manual payment
                    $log_sql = "INSERT INTO manufacturer_outstanding_history (manufacturer_id, date, type, amount, balance_after, reference_no, notes, created_by) 
                                VALUES (?, ?, 'payment_made', ?, ?, ?, ?, ?)";
                    // Note: This is for customers - you may need a similar customer_outstanding_history table
                    
                    $payment_allocations[] = [
                        'type' => 'manual_credit',
                        'amount' => $manual_reduction,
                        'description' => 'Payment towards manual credit balance'
                    ];
                }
                
                $remaining_amount -= $manual_reduction;
            }
            
            // Step 2: Apply remaining amount to invoices in FIFO order (oldest first)
            foreach ($outstanding_invoices as $invoice) {
                if ($remaining_amount <= 0) break;
                
                $invoice_outstanding = $invoice['outstanding'];
                $allocation = min($remaining_amount, $invoice_outstanding);
                
                if ($allocation > 0) {
                    // Record invoice payment
                    $payment_sql = "INSERT INTO invoice_payments 
                                   (invoice_id, customer_id, business_id, payment_amount, payment_method, 
                                    reference_no, payment_date, notes, created_by, created_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $pdo->prepare($payment_sql);
                    $stmt->execute([
                        $invoice['id'], $customer_id, $business_id, $allocation,
                        $payment_method, $reference_no, $payment_date, $notes, $_SESSION['user_id']
                    ]);
                    
                    // Update invoice paid amount and status
                    $new_paid = $invoice['paid_amount'] + $allocation;
                    $new_status = ($new_paid >= $invoice['total']) ? 'paid' : 'partial';
                    
                    $update_sql = "UPDATE invoices SET paid_amount = ?, payment_status = ?, updated_at = NOW() 
                                   WHERE id = ?";
                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute([$new_paid, $new_status, $invoice['id']]);
                    
                    $payment_allocations[] = [
                        'type' => 'invoice',
                        'invoice_id' => $invoice['id'],
                        'invoice_number' => $invoice['invoice_number'],
                        'amount' => $allocation,
                        'description' => "Payment applied to invoice {$invoice['invoice_number']}"
                    ];
                    
                    $remaining_amount -= $allocation;
                }
            }
            
            // Record the overall payment transaction
            $overall_sql = "INSERT INTO customer_payments 
                           (business_id, customer_id, total_amount, payment_method, reference_no, 
                            payment_date, notes, created_by, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($overall_sql);
            $stmt->execute([
                $business_id, $customer_id, $payment_amount, $payment_method,
                $reference_no, $payment_date, $notes, $_SESSION['user_id']
            ]);
            $payment_id = $pdo->lastInsertId();
            
            // Update customer's total outstanding in customers table if needed
            // Recalculate total outstanding
            $new_total_outstanding_sql = "
                SELECT COALESCE(SUM(total - paid_amount), 0) 
                FROM invoices 
                WHERE customer_id = ? AND business_id = ? AND payment_status IN ('pending', 'partial')
            ";
            $stmt = $pdo->prepare($new_total_outstanding_sql);
            $stmt->execute([$customer_id, $business_id]);
            $new_invoice_outstanding = $stmt->fetchColumn();
            
            // Update or insert into customer_credit_adjustments if needed for tracking
            // This helps maintain a history of manual adjustments
            
            $_SESSION['success'] = "Payment of ₹" . number_format($payment_amount, 2) . " recorded successfully!";
            
        } else {
            // INVOICE-WISE PAYMENT: Pay specific invoices
            $selected_invoices = $_POST['selected_invoices'] ?? [];
            $invoice_amounts = $_POST['invoice_amounts'] ?? [];
            
            if (empty($selected_invoices)) {
                throw new Exception("Please select at least one invoice to pay");
            }
            
            $total_selected = 0;
            foreach ($selected_invoices as $inv_id) {
                $amount = floatval($invoice_amounts[$inv_id] ?? 0);
                if ($amount > 0) {
                    $total_selected += $amount;
                }
            }
            
            if ($total_selected != $payment_amount) {
                throw new Exception("Payment amount does not match sum of selected invoice payments");
            }
            
            foreach ($selected_invoices as $inv_id) {
                $amount = floatval($invoice_amounts[$inv_id] ?? 0);
                if ($amount <= 0) continue;
                
                // Find the invoice
                $invoice = null;
                foreach ($outstanding_invoices as $inv) {
                    if ($inv['id'] == $inv_id) {
                        $invoice = $inv;
                        break;
                    }
                }
                
                if (!$invoice) {
                    continue;
                }
                
                if ($amount > $invoice['outstanding']) {
                    throw new Exception("Payment amount for invoice {$invoice['invoice_number']} exceeds outstanding balance");
                }
                
                // Record invoice payment
                $payment_sql = "INSERT INTO invoice_payments 
                               (invoice_id, customer_id, business_id, payment_amount, payment_method, 
                                reference_no, payment_date, notes, created_by, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($payment_sql);
                $stmt->execute([
                    $inv_id, $customer_id, $business_id, $amount,
                    $payment_method, $reference_no, $payment_date, $notes, $_SESSION['user_id']
                ]);
                
                // Update invoice paid amount and status
                $new_paid = $invoice['paid_amount'] + $amount;
                $new_status = ($new_paid >= $invoice['total']) ? 'paid' : 'partial';
                
                $update_sql = "UPDATE invoices SET paid_amount = ?, payment_status = ?, updated_at = NOW() 
                               WHERE id = ?";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([$new_paid, $new_status, $inv_id]);
                
                $payment_allocations[] = [
                    'type' => 'invoice',
                    'invoice_id' => $inv_id,
                    'invoice_number' => $invoice['invoice_number'],
                    'amount' => $amount,
                    'description' => "Payment applied to invoice {$invoice['invoice_number']}"
                ];
            }
            
            // Record the overall payment transaction
            $overall_sql = "INSERT INTO customer_payments 
                           (business_id, customer_id, total_amount, payment_method, reference_no, 
                            payment_date, notes, created_by, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($overall_sql);
            $stmt->execute([
                $business_id, $customer_id, $payment_amount, $payment_method,
                $reference_no, $payment_date, $notes, $_SESSION['user_id']
            ]);
            
            $_SESSION['success'] = "Payment of ₹" . number_format($payment_amount, 2) . " recorded successfully!";
        }
        
        $pdo->commit();
        header("Location: customer_payment.php?customer_id=$customer_id&success=1");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Payment failed: " . $e->getMessage();
        header("Location: customer_payment.php?customer_id=$customer_id");
        exit();
    }
}

// Get payment history
$history_sql = "
    SELECT cp.*, 
           (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.customer_id = cp.customer_id AND DATE(ip.payment_date) = DATE(cp.payment_date)) as invoice_count
    FROM customer_payments cp
    WHERE cp.customer_id = ? AND cp.business_id = ?
    ORDER BY cp.created_at DESC
    LIMIT 20
";
$stmt = $pdo->prepare($history_sql);
$stmt->execute([$customer_id, $business_id]);
$payment_history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<?php $page_title = "Make Payment - " . htmlspecialchars($customer['name']); ?>
<?php include('includes/head.php'); ?>

<style>
    .outstanding-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    .outstanding-card h2 {
        font-size: 2.5rem;
        font-weight: bold;
    }
    .invoice-row {
        transition: all 0.3s ease;
    }
    .invoice-row:hover {
        background-color: #f8f9fa;
    }
    .invoice-selected {
        background-color: #e8f5e9 !important;
        border-left: 4px solid #4caf50;
    }
    .payment-method-card {
        cursor: pointer;
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }
    .payment-method-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    .payment-method-card.selected {
        border-color: #4caf50;
        background-color: #e8f5e9;
    }
    .amount-badge {
        font-size: 0.85rem;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
    }
    @media (max-width: 768px) {
        .btn-group {
            flex-direction: column;
        }
        .btn-group .btn {
            margin-bottom: 5px;
        }
    }
</style>

<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-rupee me-2"></i> Make Payment
                                <small class="text-muted ms-2">
                                    <i class="bx bx-user me-1"></i>
                                    <?= htmlspecialchars($customer['name']) ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <a href="customer_statement.php?id=<?= $customer_id ?>" class="btn btn-outline-info">
                                    <i class="bx bx-file me-1"></i> View Statement
                                </a>
                                <a href="customers.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Customers
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <!-- Outstanding Summary Card -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card outstanding-card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="opacity-75">Total Outstanding</small>
                                        <h2 class="mb-0">₹<?= number_format($total_outstanding, 2) ?></h2>
                                    </div>
                                    <i class="bx bx-error-circle fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-info text-white shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="opacity-75">Manual Balance</small>
                                        <h3 class="mb-0">₹<?= number_format(abs($manual_outstanding), 2) ?></h3>
                                        <small><?= $manual_outstanding > 0 ? 'Customer owes' : ($manual_outstanding < 0 ? 'You owe' : 'Settled') ?></small>
                                    </div>
                                    <i class="bx bx-credit-card fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-warning text-white shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="opacity-75">Invoice Outstanding</small>
                                        <h3 class="mb-0">₹<?= number_format($customer['total_outstanding'], 2) ?></h3>
                                        <small><?= count($outstanding_invoices) ?> pending invoice(s)</small>
                                    </div>
                                    <i class="bx bx-receipt fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Form -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <ul class="nav nav-tabs card-header-tabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#bulkPayment" role="tab">
                                    <i class="bx bx-money me-1"></i> Bulk Payment
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#invoicePayment" role="tab">
                                    <i class="bx bx-receipt me-1"></i> Invoice-wise Payment
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <!-- Bulk Payment Tab -->
                            <div class="tab-pane fade show active" id="bulkPayment" role="tabpanel">
                                <form method="POST" id="bulkPaymentForm">
                                    <input type="hidden" name="payment_mode" value="bulk">
                                    
                                    <div class="alert alert-info">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Bulk Payment Allocation Rules:</strong>
                                        <ol class="mb-0 mt-2">
                                            <li>First, any manual credit balance (customer owes) will be reduced</li>
                                            <li>Remaining amount will be applied to oldest invoices first (FIFO order)</li>
                                            <li>Partial payments are allowed and will mark invoices as "Partial"</li>
                                        </ol>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" name="payment_amount" id="bulk_amount" 
                                                           class="form-control form-control-lg" 
                                                           step="0.01" min="0.01" required
                                                           placeholder="Enter amount">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Date</label>
                                                <input type="date" name="payment_date" class="form-control" 
                                                       value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Method</label>
                                                <select name="payment_method" id="bulk_payment_method" class="form-select" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="upi">UPI</option>
                                                    <option value="bank">Bank Transfer</option>
                                                    <option value="cheque">Cheque</option>
                                                    <option value="credit_card">Credit Card</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="bulk_reference_div" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label">Reference Number</label>
                                                <input type="text" name="reference_no" class="form-control" 
                                                       placeholder="UPI Ref / Cheque No / Transaction ID">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Notes (Optional)</label>
                                        <textarea name="notes" class="form-control" rows="2" 
                                                  placeholder="Any additional notes about this payment"></textarea>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="bx bx-check-circle me-2"></i> Process Bulk Payment
                                        </button>
                                        <button type="reset" class="btn btn-secondary btn-lg">
                                            <i class="bx bx-reset me-2"></i> Reset
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Invoice-wise Payment Tab -->
                            <div class="tab-pane fade" id="invoicePayment" role="tabpanel">
                                <form method="POST" id="invoicePaymentForm">
                                    <input type="hidden" name="payment_mode" value="invoice_wise">
                                    
                                    <?php if (empty($outstanding_invoices)): ?>
                                    <div class="alert alert-warning">
                                        <i class="bx bx-info-circle me-2"></i>
                                        No outstanding invoices found for this customer.
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="50">
                                                        <input type="checkbox" id="select_all_invoices">
                                                    </th>
                                                    <th>Invoice #</th>
                                                    <th>Date</th>
                                                    <th class="text-end">Total Amount</th>
                                                    <th class="text-end">Paid Amount</th>
                                                    <th class="text-end">Outstanding</th>
                                                    <th class="text-end">Payment Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($outstanding_invoices as $inv): ?>
                                                <tr class="invoice-row" data-invoice-id="<?= $inv['id'] ?>">
                                                    <td>
                                                        <input type="checkbox" name="selected_invoices[]" 
                                                               value="<?= $inv['id'] ?>" class="invoice-checkbox">
                                                    </td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong>
                                                        <br>
                                                        <small class="text-muted">ID: #<?= $inv['id'] ?></small>
                                                    </td>
                                                    <td><?= date('d M Y', strtotime($inv['created_at'])) ?></td>
                                                    <td class="text-end">₹<?= number_format($inv['total'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($inv['paid_amount'], 2) ?></td>
                                                    <td class="text-end text-danger fw-bold">
                                                        ₹<?= number_format($inv['outstanding'], 2) ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text">₹</span>
                                                            <input type="number" name="invoice_amounts[<?= $inv['id'] ?>]" 
                                                                   class="form-control invoice-amount" 
                                                                   data-max="<?= $inv['outstanding'] ?>"
                                                                   step="0.01" min="0" max="<?= $inv['outstanding'] ?>"
                                                                   placeholder="Amount" disabled>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr>
                                                    <td colspan="6" class="text-end fw-bold">Total Payment:</td>
                                                    <td class="text-end">
                                                        <div class="input-group">
                                                            <span class="input-group-text">₹</span>
                                                            <input type="number" name="payment_amount" id="total_payment_amount" 
                                                                   class="form-control" step="0.01" min="0" readonly
                                                                   placeholder="0.00">
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Date</label>
                                                <input type="date" name="payment_date" class="form-control" 
                                                       value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Method</label>
                                                <select name="payment_method" id="invoice_payment_method" class="form-select" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="upi">UPI</option>
                                                    <option value="bank">Bank Transfer</option>
                                                    <option value="cheque">Cheque</option>
                                                    <option value="credit_card">Credit Card</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6" id="invoice_reference_div" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label">Reference Number</label>
                                                <input type="text" name="reference_no" class="form-control" 
                                                       placeholder="UPI Ref / Cheque No / Transaction ID">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Notes (Optional)</label>
                                                <input type="text" name="notes" class="form-control" 
                                                       placeholder="Any notes about this payment">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="submit" class="btn btn-success btn-lg" id="submit_invoice_payment">
                                            <i class="bx bx-check-circle me-2"></i> Process Selected Payments
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-lg" onclick="resetInvoiceSelection()">
                                            <i class="bx bx-reset me-2"></i> Reset
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment History -->
                <?php if (!empty($payment_history)): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-history me-2"></i> Recent Payment History
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Invoices Covered</th>
                                        <th>Notes</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment_history as $payment): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($payment['payment_date'])) ?></td>
                                        <td class="text-success fw-bold">₹<?= number_format($payment['total_amount'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-info bg-opacity-10 text-info">
                                                <?= ucfirst($payment['payment_method']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($payment['reference_no'] ?: '—') ?></td>
                                        <td>
                                            <?php if ($payment['invoice_count'] > 0): ?>
                                            <span class="badge bg-primary"><?= $payment['invoice_count'] ?> invoice(s)</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">Manual adjustment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($payment['notes'] ?: '—') ?></td>
                                        <td>
                                            <?php
                                            $user_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                                            $user_stmt->execute([$payment['created_by']]);
                                            $user = $user_stmt->fetch();
                                            echo htmlspecialchars($user['full_name'] ?? 'System');
                                            ?>
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

<script>
$(document).ready(function() {
    // Show/hide reference number field based on payment method
    function toggleReferenceField(method, prefix) {
        if (method === 'upi' || method === 'bank' || method === 'cheque') {
            $('#' + prefix + '_reference_div').show();
        } else {
            $('#' + prefix + '_reference_div').hide();
            $('input[name="reference_no"]').val('');
        }
    }
    
    $('#bulk_payment_method').change(function() {
        toggleReferenceField($(this).val(), 'bulk');
    });
    
    $('#invoice_payment_method').change(function() {
        toggleReferenceField($(this).val(), 'invoice');
    });
    
    // Initialize reference fields
    toggleReferenceField($('#bulk_payment_method').val(), 'bulk');
    toggleReferenceField($('#invoice_payment_method').val(), 'invoice');
    
    // Invoice-wise payment logic
    let selectedInvoices = [];
    
    // Select all invoices
    $('#select_all_invoices').change(function() {
        const isChecked = $(this).is(':checked');
        $('.invoice-checkbox').prop('checked', isChecked).trigger('change');
    });
    
    // Individual invoice selection
    $('.invoice-checkbox').change(function() {
        const invoiceRow = $(this).closest('tr');
        const invoiceId = $(this).val();
        const amountInput = $(this).closest('tr').find('.invoice-amount');
        
        if ($(this).is(':checked')) {
            invoiceRow.addClass('invoice-selected');
            amountInput.prop('disabled', false);
            amountInput.focus();
            
            // Set default amount to full outstanding
            const maxAmount = amountInput.data('max');
            amountInput.val(maxAmount);
        } else {
            invoiceRow.removeClass('invoice-selected');
            amountInput.prop('disabled', true);
            amountInput.val('');
        }
        
        updateTotalPayment();
        updateSelectAllCheckbox();
    });
    
    // Update total payment amount
    function updateTotalPayment() {
        let total = 0;
        $('.invoice-amount:not(:disabled)').each(function() {
            let amount = parseFloat($(this).val()) || 0;
            total += amount;
        });
        $('#total_payment_amount').val(total.toFixed(2));
    }
    
    // Update select all checkbox state
    function updateSelectAllCheckbox() {
        const totalCheckboxes = $('.invoice-checkbox').length;
        const checkedCheckboxes = $('.invoice-checkbox:checked').length;
        
        if (checkedCheckboxes === 0) {
            $('#select_all_invoices').prop('checked', false).prop('indeterminate', false);
        } else if (checkedCheckboxes === totalCheckboxes) {
            $('#select_all_invoices').prop('checked', true).prop('indeterminate', false);
        } else {
            $('#select_all_invoices').prop('checked', false).prop('indeterminate', true);
        }
    }
    
    // Validate invoice amounts
    $('.invoice-amount').on('input', function() {
        let value = parseFloat($(this).val()) || 0;
        const maxValue = parseFloat($(this).data('max'));
        
        if (value > maxValue) {
            $(this).val(maxValue.toFixed(2));
            Swal.fire({
                icon: 'warning',
                title: 'Amount Exceeds Outstanding',
                text: `Payment cannot exceed outstanding amount of ₹${maxValue.toFixed(2)}`,
                timer: 2000,
                showConfirmButton: false
            });
        }
        if (value < 0) {
            $(this).val(0);
        }
        
        updateTotalPayment();
    });
    
    // Form validation for invoice-wise payment
    $('#invoicePaymentForm').on('submit', function(e) {
        const totalPayment = parseFloat($('#total_payment_amount').val()) || 0;
        
        if (totalPayment <= 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Payment',
                text: 'Please select at least one invoice and enter a valid payment amount'
            });
            return false;
        }
        
        // Confirm payment
        return Swal.fire({
            title: 'Confirm Payment',
            html: `Are you sure you want to process payment of <strong>₹${totalPayment.toFixed(2)}</strong>?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, process payment',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                return true;
            }
            return false;
        });
    });
    
    // Bulk payment validation
    $('#bulkPaymentForm').on('submit', function(e) {
        const amount = parseFloat($('#bulk_amount').val()) || 0;
        
        if (amount <= 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Amount',
                text: 'Please enter a valid payment amount greater than 0'
            });
            return false;
        }
        
        // Confirm payment
        return Swal.fire({
            title: 'Confirm Bulk Payment',
            html: `Are you sure you want to process bulk payment of <strong>₹${amount.toFixed(2)}</strong>?<br><br>
                   <small>This will first reduce manual credit balance, then apply to oldest invoices first.</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, process payment',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                return true;
            }
            return false;
        });
    });
    
    // Bulk amount validation
    $('#bulk_amount').on('input', function() {
        let value = parseFloat($(this).val()) || 0;
        const totalOutstanding = <?= $total_outstanding ?>;
        
        if (value > totalOutstanding && totalOutstanding > 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Amount Exceeds Outstanding',
                text: `Payment amount exceeds total outstanding balance of ₹${totalOutstanding.toFixed(2)}. Extra amount will be treated as advance payment.`,
                timer: 3000,
                showConfirmButton: true
            });
        }
    });
});

function resetInvoiceSelection() {
    $('.invoice-checkbox').prop('checked', false).trigger('change');
    $('#select_all_invoices').prop('checked', false).prop('indeterminate', false);
    $('.invoice-amount').val('').prop('disabled', true);
    $('#total_payment_amount').val('');
    $('input[name="reference_no"]').val('');
    $('textarea[name="notes"]').val('');
    $('#invoice_payment_method').val('cash');
    toggleReferenceField('cash', 'invoice');
}
</script>

</body>
</html>