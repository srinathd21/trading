<?php
session_start();
require_once 'config/database.php';

$customer_id = $_GET['customer_id'] ?? 0;
$business_id = $_SESSION['business_id'] ?? 1;

if (!$customer_id) {
    echo '<div class="alert alert-danger">Invalid customer ID</div>';
    exit();
}

// Get customer invoices
$stmt = $pdo->prepare("
    SELECT i.id, i.invoice_number, i.total, i.created_at, i.payment_status, 
           i.total - i.paid_amount as due_amount
    FROM invoices i
    WHERE i.customer_id = ? AND i.business_id = ?
    ORDER BY i.created_at DESC
");
$stmt->execute([$customer_id, $business_id]);
$invoices = $stmt->fetchAll();

if (empty($invoices)) {
    echo '<div class="alert alert-info">No invoices found for this customer</div>';
    exit();
}
?>

<table class="table table-hover">
    <thead class="table-light">
        <tr>
            <th>Invoice No</th>
            <th class="text-center">Date</th>
            <th class="text-center">Amount</th>
            <th class="text-center">Due</th>
            <th class="text-center">Status</th>
            <th class="text-center">Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($invoices as $invoice): ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong>
            </td>
            <td class="text-center">
                <?= date('d M Y', strtotime($invoice['created_at'])) ?>
            </td>
            <td class="text-center">
                <strong class="text-success">₹<?= number_format($invoice['total'], 2) ?></strong>
            </td>
            <td class="text-center">
                <?php if ($invoice['due_amount'] > 0): ?>
                <span class="text-danger">₹<?= number_format($invoice['due_amount'], 2) ?></span>
                <?php else: ?>
                <span class="text-success">Paid</span>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <?php if ($invoice['payment_status'] === 'paid'): ?>
                <span class="badge bg-success">Paid</span>
                <?php elseif ($invoice['payment_status'] === 'partial'): ?>
                <span class="badge bg-warning">Partial</span>
                <?php else: ?>
                <span class="badge bg-danger">Pending</span>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <button class="btn btn-sm btn-success select-invoice-btn"
                        data-invoice-id="<?= $invoice['id'] ?>"
                        data-invoice-number="<?= htmlspecialchars($invoice['invoice_number']) ?>"
                        data-total-amount="<?= $invoice['total'] ?>">
                    <i class="bx bxl-whatsapp me-1"></i> Send
                </button>
                <a href="invoice_print.php?invoice_id=<?= $invoice['id'] ?>&print=1" 
                   target="_blank"
                   class="btn btn-sm btn-secondary">
                    <i class="bx bx-printer me-1"></i> Print
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>