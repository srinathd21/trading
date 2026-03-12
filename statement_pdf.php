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
        'balance' => 0,
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

// Fetch business details for header
$business_stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
$business_stmt->execute([$business_id]);
$business = $business_stmt->fetch();

// Get current user
$user_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
$user_stmt->execute([$_SESSION['user_id']]);
$current_user = $user_stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Statement - <?= htmlspecialchars($customer['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        @media print {
            body { 
                margin: 0;
                padding: 20px;
                font-size: 12px;
            }
            .no-print { display: none !important; }
            .card { border: 1px solid #ddd !important; box-shadow: none !important; }
            .table { border-collapse: collapse; }
            .table th, .table td { padding: 4px 8px; }
            .statement-container { 
                margin: 0; 
                padding: 0;
                width: 100% !important;
            }
            .header-section { 
                border-bottom: 2px solid #333;
                margin-bottom: 20px;
                padding-bottom: 15px;
            }
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
        
        .statement-container {
            max-width: 210mm;
            margin: 20px auto;
            background: white;
            padding: 25px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            position: relative;
        }
        
        .header-section {
            border-bottom: 2px solid #dc3545;
            margin-bottom: 25px;
            padding-bottom: 20px;
        }
        
        .company-name {
            color: #dc3545;
            font-weight: 700;
            font-size: 1.8rem;
        }
        
        .statement-title {
            color: #2c3e50;
            font-weight: 600;
            border-left: 4px solid #3498db;
            padding-left: 15px;
        }
        
        .customer-info-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .summary-box {
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .summary-credit {
            border-left: 4px solid #28a745;
            background-color: rgba(40, 167, 69, 0.05);
        }
        
        .summary-debit {
            border-left: 4px solid #dc3545;
            background-color: rgba(220, 53, 69, 0.05);
        }
        
        .summary-total {
            border-left: 4px solid #007bff;
            background-color: rgba(0, 123, 255, 0.05);
        }
        
        .table-header {
            background-color: #2c3e50;
            color: white;
        }
        
        .invoice-row {
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .payment-row {
            background-color: rgba(40, 167, 69, 0.05);
        }
        
        .adjustment-row {
            background-color: rgba(255, 193, 7, 0.05);
        }
        
        .balance-positive {
            color: #dc3545;
            font-weight: 600;
        }
        
        .balance-negative {
            color: #28a745;
            font-weight: 600;
        }
        
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 5rem;
            color: rgba(0,0,0,0.1);
            z-index: 0;
            white-space: nowrap;
            pointer-events: none;
        }
        
        .footer-section {
            border-top: 1px dashed #ddd;
            margin-top: 30px;
            padding-top: 15px;
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .compact-table th, .compact-table td {
            padding: 6px 8px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row no-print mb-4">
            <div class="col-12">
                <div class="alert alert-info d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-info-circle me-2"></i>
                        Click the button below to download or print this statement as PDF
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="downloadPDF()">
                            <i class="bi bi-download me-2"></i>Download PDF
                        </button>
                        <button class="btn btn-success" onclick="printPDF()">
                            <i class="bi bi-printer me-2"></i>Print PDF
                        </button>
                        <a href="customer_credit_statement.php?customer_id=<?= $customer_id ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Statement
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statement Content (This will be converted to PDF) -->
        <div id="pdf-content" class="statement-container">
            <!-- Watermark -->
            <div class="watermark"><?= htmlspecialchars($business['name'] ?? 'BUSINESS') ?></div>
            
            <!-- Header Section -->
            <div class="header-section">
                <div class="row">
                    <div class="col-8">
                        <h1 class="company-name mb-1">
                            <?= htmlspecialchars($business['name'] ?? 'YOUR BUSINESS NAME') ?>
                        </h1>
                        <?php if (!empty($business['address'])): ?>
                        <p class="mb-1" style="color: #666;">
                            <?= nl2br(htmlspecialchars($business['address'])) ?>
                        </p>
                        <?php endif; ?>
                        <?php if (!empty($business['phone'])): ?>
                        <p class="mb-1" style="color: #666;">
                            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($business['phone']) ?>
                        </p>
                        <?php endif; ?>
                        <?php if (!empty($business['email'])): ?>
                        <p class="mb-1" style="color: #666;">
                            <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($business['email']) ?>
                        </p>
                        <?php endif; ?>
                        <?php if (!empty($business['gstin'])): ?>
                        <p class="mb-0" style="color: #666;">
                            <i class="bi bi-card-text me-1"></i>GSTIN: <?= htmlspecialchars($business['gstin']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="col-4 text-end">
                        <h2 class="statement-title">CREDIT STATEMENT</h2>
                        <p class="mb-1">
                            <strong>Statement Date:</strong> <?= date('d M Y') ?>
                        </p>
                        <p class="mb-1">
                            <strong>Statement No:</strong> STMT-<?= date('Ymd') . '-' . $customer_id ?>
                        </p>
                        <p class="mb-0">
                            <strong>Generated By:</strong> <?= htmlspecialchars($current_user['full_name'] ?? 'System') ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Customer Information -->
            <div class="customer-info-box">
                <div class="row">
                    <div class="col-7">
                        <h4 class="mb-2"><?= htmlspecialchars($customer['name']) ?></h4>
                        <?php if ($customer['phone']): ?>
                        <p class="mb-1"><strong>Phone:</strong> <?= htmlspecialchars($customer['phone']) ?></p>
                        <?php endif; ?>
                        <?php if ($customer['email']): ?>
                        <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($customer['email']) ?></p>
                        <?php endif; ?>
                        <?php if ($customer['address']): ?>
                        <p class="mb-1"><strong>Address:</strong> <?= nl2br(htmlspecialchars($customer['address'])) ?></p>
                        <?php endif; ?>
                        <?php if ($customer['gstin']): ?>
                        <p class="mb-0"><strong>GSTIN:</strong> <?= htmlspecialchars($customer['gstin']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-5 text-end">
                        <div class="p-3" style="background: white; border-radius: 6px; border: 1px solid #dee2e6;">
                            <h6 class="text-muted mb-1">Current Outstanding</h6>
                            <h2 class="<?= $total_outstanding > 0 ? 'text-danger' : ($total_outstanding < 0 ? 'text-success' : 'text-muted') ?> mb-0">
                                ₹<?= number_format(abs($total_outstanding), 2) ?>
                            </h2>
                            <p class="mb-0 small">
                                <?php if ($total_outstanding > 0): ?>
                                <span class="badge bg-danger">Customer Owes You</span>
                                <?php elseif ($total_outstanding < 0): ?>
                                <span class="badge bg-success">You Owe Customer</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">No Dues</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <?php if ($credit_limit > 0): ?>
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-credit-card me-2"></i>Credit Limit Information</h6>
                            <span class="badge bg-<?= $credit_utilization > 80 ? 'danger' : ($credit_utilization > 50 ? 'warning' : 'success') ?>">
                                Utilization: <?= number_format($credit_utilization, 1) ?>%
                            </span>
                        </div>
                        <div class="progress mt-2" style="height: 10px;">
                            <div class="progress-bar bg-<?= $credit_utilization > 80 ? 'danger' : ($credit_utilization > 50 ? 'warning' : 'success') ?>" 
                                 style="width: <?= min($credit_utilization, 100) ?>%">
                            </div>
                        </div>
                        <div class="row mt-2 text-center">
                            <div class="col-3">
                                <small class="text-muted">Limit</small>
                                <p class="mb-0 fw-bold">₹<?= number_format($credit_limit, 2) ?></p>
                            </div>
                            <div class="col-3">
                                <small class="text-muted">Used</small>
                                <p class="mb-0 fw-bold text-warning">₹<?= number_format($credit_used, 2) ?></p>
                            </div>
                            <div class="col-3">
                                <small class="text-muted">Available</small>
                                <p class="mb-0 fw-bold text-success">₹<?= number_format($available_credit, 2) ?></p>
                            </div>
                            <div class="col-3">
                                <small class="text-muted">Status</small>
                                <p class="mb-0 fw-bold text-<?= $available_credit > 0 ? 'success' : 'danger' ?>">
                                    <?= $available_credit > 0 ? 'Within Limit' : 'Over Limit' ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Statement Summary -->
            <div class="row mb-4">
                <div class="col-3">
                    <div class="summary-box summary-credit">
                        <h6 class="text-muted mb-1">Opening Balance</h6>
                        <h4 class="<?= $opening_balance >= 0 ? 'text-danger' : 'text-success' ?> mb-0">
                            ₹<?= number_format(abs($opening_balance), 2) ?>
                        </h4>
                        <small class="text-muted">
                            <?= $opening_balance >= 0 ? 'Credit' : 'Debit' ?>
                        </small>
                    </div>
                </div>
                <div class="col-3">
                    <div class="summary-box" style="border-left: 4px solid #28a745;">
                        <h6 class="text-muted mb-1">Total Credit</h6>
                        <h4 class="text-success mb-0">₹<?= number_format($total_credit, 2) ?></h4>
                        <small class="text-muted">Added to Account</small>
                    </div>
                </div>
                <div class="col-3">
                    <div class="summary-box" style="border-left: 4px solid #dc3545;">
                        <h6 class="text-muted mb-1">Total Debit</h6>
                        <h4 class="text-danger mb-0">₹<?= number_format($total_debit, 2) ?></h4>
                        <small class="text-muted">Received from Customer</small>
                    </div>
                </div>
                <div class="col-3">
                    <div class="summary-box summary-total">
                        <h6 class="text-muted mb-1">Closing Balance</h6>
                        <h4 class="<?= $closing_balance >= 0 ? 'text-danger' : 'text-success' ?> mb-0">
                            ₹<?= number_format(abs($closing_balance), 2) ?>
                        </h4>
                        <small class="text-muted">
                            <?= $closing_balance >= 0 ? 'Credit (Customer Owes)' : 'Debit (You Owe)' ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Statement Period -->
            <div class="alert alert-light mb-3">
                <div class="row">
                    <div class="col-6">
                        <i class="bi bi-calendar me-2"></i>
                        <strong>Statement Period:</strong> 
                        <?php if (!empty($statement_data)): ?>
                        <?= date('d M Y', strtotime($statement_data[0]['date'])) ?> to <?= date('d M Y', strtotime(end($statement_data)['date'])) ?>
                        <?php else: ?>
                        N/A
                        <?php endif; ?>
                    </div>
                    <div class="col-6 text-end">
                        <i class="bi bi-journal-text me-2"></i>
                        <strong>Total Transactions:</strong> <?= count($statement_data) ?>
                    </div>
                </div>
            </div>

            <!-- Transactions Table -->
            <div class="table-responsive">
                <table class="table table-bordered compact-table">
                    <thead class="table-header">
                        <tr>
                            <th width="80">Date</th>
                            <th width="80">Type</th>
                            <th>Description</th>
                            <th width="100">Reference</th>
                            <th width="100" class="text-end">Credit (₹)</th>
                            <th width="100" class="text-end">Debit (₹)</th>
                            <th width="120" class="text-end">Balance (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($statement_data)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="bi bi-journal-x fs-1 text-muted d-block mb-2"></i>
                                <h6 class="text-muted">No transactions found for this customer</h6>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($statement_data as $transaction): 
                            $row_class = '';
                            $type_badge = '';
                            
                            switch ($transaction['type']) {
                                case 'invoice':
                                    $row_class = 'invoice-row';
                                    $type_badge = '<span class="badge bg-info">INVOICE</span>';
                                    break;
                                case 'payment':
                                    $row_class = 'payment-row';
                                    $type_badge = '<span class="badge bg-success">PAYMENT</span>';
                                    break;
                                case 'adjustment_credit':
                                    $row_class = 'adjustment-row';
                                    $type_badge = '<span class="badge bg-warning">CREDIT ADJ</span>';
                                    break;
                                case 'adjustment_debit':
                                    $row_class = 'adjustment-row';
                                    $type_badge = '<span class="badge bg-warning">DEBIT ADJ</span>';
                                    break;
                                case 'opening_balance':
                                    $type_badge = '<span class="badge bg-secondary">OPENING</span>';
                                    break;
                            }
                        ?>
                        <tr class="<?= $row_class ?>">
                            <td><?= date('d/m/Y', strtotime($transaction['date'])) ?></td>
                            <td><?= $type_badge ?></td>
                            <td><?= htmlspecialchars($transaction['description']) ?></td>
                            <td>
                                <?= htmlspecialchars($transaction['reference']) ?>
                            </td>
                            <td class="text-end <?= $transaction['credit'] > 0 ? 'text-success fw-bold' : '' ?>">
                                <?php if ($transaction['credit'] > 0): ?>
                                ₹<?= number_format($transaction['credit'], 2) ?>
                                <?php else: ?>
                                -
                                <?php endif; ?>
                            </td>
                            <td class="text-end <?= $transaction['debit'] > 0 ? 'text-danger fw-bold' : '' ?>">
                                <?php if ($transaction['debit'] > 0): ?>
                                ₹<?= number_format($transaction['debit'], 2) ?>
                                <?php else: ?>
                                -
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold <?= $transaction['balance'] >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                                ₹<?= number_format(abs($transaction['balance']), 2) ?>
                                <br>
                                <small class="text-muted">
                                    <?= $transaction['balance'] >= 0 ? 'Cr' : 'Dr' ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($statement_data)): ?>
                    <tfoot style="background-color: #f8f9fa;">
                        <tr>
                            <th colspan="4" class="text-end">TOTALS</th>
                            <th class="text-end text-success border-top">
                                ₹<?= number_format($total_credit, 2) ?>
                            </th>
                            <th class="text-end text-danger border-top">
                                ₹<?= number_format($total_debit, 2) ?>
                            </th>
                            <th class="text-end fw-bold border-top <?= $closing_balance >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                                ₹<?= number_format(abs($closing_balance), 2) ?>
                                <br>
                                <small>
                                    <?= $closing_balance >= 0 ? 'Credit (Customer Owes)' : 'Debit (You Owe)' ?>
                                </small>
                            </th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Notes Section -->
            <div class="mt-4">
                <div class="row">
                    <div class="col-6">
                        <div class="card border-light">
                            <div class="card-body p-3">
                                <h6 class="text-muted mb-2"><i class="bi bi-info-circle me-2"></i>Notes</h6>
                                <ul class="mb-0 small" style="padding-left: 15px;">
                                    <li>All amounts are in Indian Rupees (₹)</li>
                                    <li>Positive balance indicates amount customer owes to you</li>
                                    <li>Negative balance indicates amount you owe to customer</li>
                                    <li>Credit adjustments increase customer's outstanding balance</li>
                                    <li>Debit adjustments reduce customer's outstanding balance</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card border-light">
                            <div class="card-body p-3">
                                <h6 class="text-muted mb-2"><i class="bi bi-calendar-check me-2"></i>Important Dates</h6>
                                <ul class="mb-0 small" style="padding-left: 15px;">
                                    <li><strong>Statement Date:</strong> <?= date('d M Y') ?></li>
                                    <li><strong>Next Statement:</strong> <?= date('d M Y', strtotime('+1 month')) ?></li>
                                    <li><strong>Payment Due:</strong> 
                                        <?php if ($total_outstanding > 0): ?>
                                        Immediately
                                        <?php else: ?>
                                        N/A
                                        <?php endif; ?>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer-section">
                <div class="row">
                    <div class="col-6">
                        <p class="mb-1"><strong>Generated On:</strong> <?= date('d M Y, h:i A') ?></p>
                        <p class="mb-1"><strong>Generated By:</strong> <?= htmlspecialchars($current_user['full_name'] ?? 'System') ?></p>
                        <p class="mb-0"><strong>Page:</strong> 1 of 1</p>
                    </div>
                    <div class="col-6 text-end">
                        <p class="mb-1">
                            <strong>Signature:</strong> _________________________
                        </p>
                        <p class="mb-0">
                            <strong>Stamp:</strong>
                        </p>
                        <div class="mt-2" style="border: 1px dashed #ccc; padding: 5px; display: inline-block; min-width: 150px;"></div>
                    </div>
                </div>
                <hr class="my-2">
                <div class="text-center small">
                    <p class="mb-0">
                        <strong><?= htmlspecialchars($business['name'] ?? 'YOUR BUSINESS NAME') ?></strong> | 
                        This is a computer-generated statement. No signature required.
                    </p>
                    <?php if (!empty($business['phone'])): ?>
                    <p class="mb-0">
                        For any queries, contact: <?= htmlspecialchars($business['phone']) ?> | 
                        <?= htmlspecialchars($business['email'] ?? '') ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Function to download PDF
        function downloadPDF() {
            const element = document.getElementById('pdf-content');
            const options = {
                margin: [15, 15, 15, 15],
                filename: 'Credit_Statement_<?= preg_replace('/[^A-Za-z0-9]/', '_', $customer['name']) ?>_<?= date('Ymd') ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2,
                    useCORS: true,
                    letterRendering: true,
                    width: element.scrollWidth,
                    height: element.scrollHeight,
                    scrollX: 0,
                    scrollY: 0
                },
                jsPDF: { 
                    unit: 'mm', 
                    format: 'a4', 
                    orientation: 'portrait',
                    compress: true
                },
                pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
            };

            // Show loading indicator
            showLoading('Generating PDF...');

            // Generate and download PDF
            html2pdf()
                .set(options)
                .from(element)
                .save()
                .then(() => {
                    hideLoading();
                })
                .catch(err => {
                    hideLoading();
                    alert('Error generating PDF: ' + err.message);
                    console.error(err);
                });
        }

        // Function to print PDF
        function printPDF() {
            const element = document.getElementById('pdf-content');
            const options = {
                margin: [10, 10, 10, 10],
                filename: 'Credit_Statement_<?= preg_replace('/[^A-Za-z0-9]/', '_', $customer['name']) ?>_<?= date('Ymd') ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2,
                    useCORS: true,
                    letterRendering: true
                },
                jsPDF: { 
                    unit: 'mm', 
                    format: 'a4', 
                    orientation: 'portrait'
                }
            };

            // Show loading indicator
            showLoading('Preparing for print...');

            // Generate PDF and open in new window for printing
            html2pdf()
                .set(options)
                .from(element)
                .toPdf()
                .get('pdf')
                .then(pdf => {
                    hideLoading();
                    window.open(pdf.output('bloburl'), '_blank');
                })
                .catch(err => {
                    hideLoading();
                    alert('Error preparing PDF for print: ' + err.message);
                    console.error(err);
                });
        }

        // Function to show loading indicator
        function showLoading(message) {
            // Create loading overlay
            const overlay = document.createElement('div');
            overlay.id = 'loading-overlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 9999;
                color: white;
                font-size: 1.2rem;
            `;
            
            const spinner = document.createElement('div');
            spinner.style.cssText = `
                border: 5px solid #f3f3f3;
                border-top: 5px solid #3498db;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                animation: spin 1s linear infinite;
                margin-bottom: 20px;
            `;
            
            const text = document.createElement('div');
            text.textContent = message || 'Processing...';
            text.style.textAlign = 'center';
            
            const container = document.createElement('div');
            container.style.cssText = 'text-align: center;';
            container.appendChild(spinner);
            container.appendChild(text);
            
            overlay.appendChild(container);
            document.body.appendChild(overlay);
            
            // Add CSS for spinner animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
        }

        // Function to hide loading indicator
        function hideLoading() {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                overlay.remove();
            }
        }

        // Auto-download PDF on page load (optional)
        window.addEventListener('load', function() {
            // Uncomment the line below to auto-download PDF when page loads
            // downloadPDF();
        });

        // Handle window resize for better PDF generation
        window.addEventListener('resize', function() {
            // Optional: Handle responsive adjustments
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>