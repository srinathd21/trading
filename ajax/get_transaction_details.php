<?php
session_start();

// Use absolute path
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    die('<div class="alert alert-danger">Unauthorized access</div>');
}

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($transaction_id <= 0) {
    die('<div class="alert alert-danger">Invalid transaction ID</div>');
}

$stmt = $pdo->prepare("
    SELECT rt.*,
           i.invoice_number,
           i.total as invoice_total,
           c.name as customer_name,
           c.phone as customer_phone,
           rp.full_name as referral_name,
           rp.referral_code,
           u.name as processed_by_name
    FROM referral_transactions rt
    LEFT JOIN invoices i ON rt.invoice_id = i.id
    LEFT JOIN customers c ON rt.customer_id = c.id
    LEFT JOIN referral_person rp ON rt.referral_id = rp.id
    LEFT JOIN users u ON rt.processed_by = u.id
    WHERE rt.id = ?
");

$stmt->execute([$transaction_id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    die('<div class="alert alert-danger">Transaction not found</div>');
}

$type_class = $transaction['transaction_type'] == 'credit' ? 'success' : 'danger';
$type_icon = $transaction['transaction_type'] == 'credit' ? 'bx-plus-circle' : 'bx-minus-circle';
$status_class = $transaction['status'] == 'completed' ? 'success' : 
               ($transaction['status'] == 'pending' ? 'warning' : 'danger');
?>

<div class="transaction-details">
    <div class="text-center mb-4">
        <div class="avatar-lg mx-auto mb-3">
            <div class="avatar-title bg-<?= $type_class ?> bg-opacity-10 text-<?= $type_class ?> rounded-circle" style="width: 80px; height: 80px;">
                <i class="bx <?= $type_icon ?> fs-1"></i>
            </div>
        </div>
        <h4 class="mb-1">₹<?= number_format($transaction['amount'], 2) ?></h4>
        <span class="badge bg-<?= $type_class ?> px-3 py-1 mb-2">
            <?= strtoupper($transaction['transaction_type']) ?> TRANSACTION
        </span>
        <div>
            <span class="badge bg-<?= $status_class ?>">
                <?= ucfirst($transaction['status']) ?>
            </span>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12">
            <h6 class="text-muted mb-2">Transaction Information</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <td width="40%"><strong>Transaction ID:</strong></td>
                            <td>TXN-<?= str_pad($transaction['id'], 6, '0', STR_PAD_LEFT) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Date & Time:</strong></td>
                            <td><?= date('d M Y h:i A', strtotime($transaction['created_at'])) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Type:</strong></td>
                            <td>
                                <span class="badge bg-<?= $type_class ?> bg-opacity-10 text-<?= $type_class ?>">
                                    <?= ucfirst($transaction['transaction_type']) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Description:</strong></td>
                            <td><?= htmlspecialchars($transaction['description']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($transaction['invoice_number']): ?>
        <div class="col-12">
            <h6 class="text-muted mb-2">Invoice Details</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <td width="40%"><strong>Invoice Number:</strong></td>
                            <td><?= htmlspecialchars($transaction['invoice_number']) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Invoice Amount:</strong></td>
                            <td>₹<?= number_format($transaction['invoice_total'], 2) ?></td>
                        </tr>
                        <?php if ($transaction['customer_name']): ?>
                        <tr>
                            <td><strong>Customer:</strong></td>
                            <td><?= htmlspecialchars($transaction['customer_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($transaction['referral_name']): ?>
        <div class="col-12">
            <h6 class="text-muted mb-2">Referral Person</h6>
            <div class="d-flex align-items-center">
                <div class="avatar-sm me-3">
                    <div class="avatar-title bg-primary bg-opacity-10 text-primary rounded-circle">
                        <i class="bx bx-user"></i>
                    </div>
                </div>
                <div>
                    <strong class="d-block"><?= htmlspecialchars($transaction['referral_name']) ?></strong>
                    <small class="text-muted">Code: <?= htmlspecialchars($transaction['referral_code']) ?></small>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($transaction['payment_method']): ?>
        <div class="col-12">
            <h6 class="text-muted mb-2">Payment Details</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <td width="40%"><strong>Payment Method:</strong></td>
                            <td><?= ucfirst($transaction['payment_method']) ?></td>
                        </tr>
                        <?php if ($transaction['payment_reference']): ?>
                        <tr>
                            <td><strong>Reference:</strong></td>
                            <td><?= htmlspecialchars($transaction['payment_reference']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($transaction['processed_by_name']): ?>
                        <tr>
                            <td><strong>Processed By:</strong></td>
                            <td><?= htmlspecialchars($transaction['processed_by_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>