<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Not authenticated']));
}

$expense_id = (int)($_GET['id'] ?? 0);
if ($expense_id <= 0) {
    die("Invalid expense ID");
}

$stmt = $pdo->prepare("
    SELECT e.*, 
           s.shop_name,
           u.full_name as added_by_name,
           DATE_FORMAT(e.date, '%d %M %Y') as date_formatted,
           DATE_FORMAT(e.created_at, '%d/%m/%Y %h:%i %p') as created_formatted,
           DATE_FORMAT(e.updated_at, '%d/%m/%Y %h:%i %p') as updated_formatted
    FROM expenses e
    LEFT JOIN shops s ON e.shop_id = s.id
    LEFT JOIN users u ON e.added_by = u.id
    WHERE e.id = ?
");

$stmt->execute([$expense_id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    die("Expense not found");
}

// Status badge color
$status_badge = match($expense['status']) {
    'approved' => 'success',
    'pending' => 'warning',
    'rejected' => 'danger',
    'reconciled' => 'primary',
    default => 'secondary'
};

// Payment method display
$payment_methods = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'credit_card' => 'Credit Card',
    'cheque' => 'Cheque',
    'upi' => 'UPI',
    'other' => 'Other'
];
?>

<div class="row">
    <div class="col-md-6">
        <h6 class="text-muted">Basic Information</h6>
        <div class="mb-3">
            <label class="form-label fw-bold">Description</label>
            <p><?= htmlspecialchars($expense['description']) ?></p>
        </div>
        
        <div class="row">
            <div class="col-6">
                <label class="form-label fw-bold">Amount</label>
                <h4 class="text-danger">₹<?= number_format($expense['amount'], 2) ?></h4>
            </div>
            <div class="col-6">
                <label class="form-label fw-bold">Date</label>
                <p><?= $expense['date_formatted'] ?></p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-6">
                <label class="form-label fw-bold">Category</label>
                <p><span class="badge bg-info"><?= htmlspecialchars($expense['category']) ?></span></p>
            </div>
            <div class="col-6">
                <label class="form-label fw-bold">Status</label>
                <p><span class="badge bg-<?= $status_badge ?>"><?= ucfirst($expense['status']) ?></span></p>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <h6 class="text-muted">Payment Details</h6>
        <div class="mb-3">
            <label class="form-label fw-bold">Payment Method</label>
            <p><?= $payment_methods[$expense['payment_method']] ?? ucfirst($expense['payment_method']) ?></p>
        </div>
        
        <?php if ($expense['payment_reference']): ?>
        <div class="mb-3">
            <label class="form-label fw-bold">Payment Reference</label>
            <p><?= htmlspecialchars($expense['payment_reference']) ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($expense['reference']): ?>
        <div class="mb-3">
            <label class="form-label fw-bold">Reference</label>
            <p><?= htmlspecialchars($expense['reference']) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-6">
        <h6 class="text-muted">Additional Information</h6>
        <div class="mb-3">
            <label class="form-label fw-bold">Added By</label>
            <p><?= htmlspecialchars($expense['added_by_name']) ?></p>
        </div>
        
        <div class="row">
            <div class="col-6">
                <label class="form-label fw-bold">Created</label>
                <p><?= $expense['created_formatted'] ?></p>
            </div>
            <div class="col-6">
                <label class="form-label fw-bold">Updated</label>
                <p><?= $expense['updated_formatted'] ?></p>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <h6 class="text-muted">Shop Information</h6>
        <div class="mb-3">
            <label class="form-label fw-bold">Shop</label>
            <p><?= htmlspecialchars($expense['shop_name']) ?></p>
        </div>
        
        <?php if ($expense['attachment_path']): ?>
        <div class="mb-3">
            <label class="form-label fw-bold">Attachment</label>
            <p>
                <a href="<?= $expense['attachment_path'] ?>" target="_blank" class="btn btn-sm btn-outline-success">
                    <i class="bx bx-paperclip me-1"></i> View Attachment
                </a>
            </p>
        </div>
        <?php endif; ?>
            
        <?php if ($expense['notes']): ?>
        <div class="mb-3">
            <label class="form-label fw-bold">Notes</label>
            <p class="border p-2 rounded bg-light"><?= nl2br(htmlspecialchars($expense['notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>