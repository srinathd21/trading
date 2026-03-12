<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if (!isset($_SESSION['current_business_id'])) {
    header('Location: select_shop.php');
    exit();
}

$current_business_id = (int)$_SESSION['current_business_id'];
$user_id = (int)$_SESSION['user_id'];
$referral_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($referral_id <= 0) {
    header('Location: referral_persons.php');
    exit();
}

$success = $error = '';

// Fetch referral person
$stmt = $pdo->prepare("
    SELECT rp.*, 
           COALESCE(SUM(i.referral_commission_amount), 0) AS total_earned
    FROM referral_person rp
    LEFT JOIN invoices i ON i.referral_id = rp.id AND i.business_id = rp.business_id
    WHERE rp.id = ? AND rp.business_id = ?
    GROUP BY rp.id
");
$stmt->execute([$referral_id, $current_business_id]);
$referral = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$referral) {
    header('Location: referral_persons.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)$_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $reference = trim($_POST['reference'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $paid_at = $_POST['paid_at'];

    if ($amount <= 0) {
        $error = "Payment amount must be greater than zero.";
    } elseif ($amount > $referral['balance_due']) {
        $error = "Payment amount cannot exceed balance due (₹" . number_format($referral['balance_due'], 2) . ").";
    } else {
        try {
            // Only start transaction if possible
            if (!$pdo->beginTransaction()) {
                throw new Exception("Failed to start database transaction.");
            }

            // Insert payment record
            $stmt = $pdo->prepare("
                INSERT INTO referral_payments 
                (business_id, referral_id, amount, payment_method, reference, description, paid_at, processed_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $current_business_id,
                $referral_id,
                $amount,
                $payment_method,
                $reference,
                $description,
                $paid_at,
                $user_id
            ]);

            // Update referral_person balances
            $stmt = $pdo->prepare("
                UPDATE referral_person 
                SET paid_amount = paid_amount + ?,
                    balance_due = balance_due - ?,
                    updated_at = NOW()
                WHERE id = ? AND business_id = ?
            ");
            $stmt->execute([$amount, $amount, $referral_id, $current_business_id]);

            $pdo->commit();
            $success = "Payment of ₹" . number_format($amount, 2) . " recorded successfully!";

            // Refresh referral data after successful payment
            $stmt = $pdo->prepare("
                SELECT rp.*, 
                       COALESCE(SUM(i.referral_commission_amount), 0) AS total_earned
                FROM referral_person rp
                LEFT JOIN invoices i ON i.referral_id = rp.id AND i.business_id = rp.business_id
                WHERE rp.id = ? AND rp.business_id = ?
                GROUP BY rp.id
            ");
            $stmt->execute([$referral_id, $current_business_id]);
            $referral = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            // Only rollback if a transaction is active
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to record payment: " . $e->getMessage();
        }
    }
}
// Fetch recent payments
$payments_stmt = $pdo->prepare("
    SELECT rp.*, u.full_name AS processed_by_name
    FROM referral_payments rp
    LEFT JOIN users u ON rp.processed_by = u.id
    WHERE rp.referral_id = ? AND rp.business_id = ?
    ORDER BY rp.created_at DESC
    LIMIT 10
");
$payments_stmt->execute([$referral_id, $current_business_id]);
$recent_payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Pay Referral - " . htmlspecialchars($referral['full_name']); ?>
<?php include(__DIR__ . '/includes/head.php'); ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include(__DIR__ . '/includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include(__DIR__ . '/includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-money me-2"></i> Make Payment to Referral
                                </h4>
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb mb-0">
                                        <li class="breadcrumb-item"><a href="referral_persons.php">Referral Persons</a></li>
                                        <li class="breadcrumb-item"><a href="referral_view.php?id=<?= $referral_id ?>"><?= htmlspecialchars($referral['full_name']) ?></a></li>
                                        <li class="breadcrumb-item active">Make Payment</li>
                                    </ol>
                                </nav>
                            </div>
                            <a href="referral_view.php?id=<?= $referral_id ?>" class="btn btn-outline-secondary">
                                <i class="bx bx-arrow-back me-1"></i> Back to Profile
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Payment Form -->
                    <div class="col-lg-5">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-wallet me-1"></i> Current Balance Summary
                                </h5>
                                <div class="row text-center mb-4">
                                    <div class="col-6 border-end">
                                        <h6 class="text-muted">Total Earned</h6>
                                        <h3 class="text-primary">₹<?= number_format($referral['debit_amount'], 0) ?></h3>
                                    </div>
                                    <div class="col-6">
                                        <h6 class="text-muted">Balance Due</h6>
                                        <h3 class="text-warning">₹<?= number_format($referral['balance_due'], 0) ?></h3>
                                    </div>
                                </div>

                                <?php if ($success): ?>
                                <div class="alert alert-success alert-dismissible fade show">
                                    <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>

                                <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show">
                                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>

                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                                                   max="<?= $referral['balance_due'] ?>" required
                                                   value="<?= $referral['balance_due'] > 0 ? $referral['balance_due'] : '' ?>">
                                        </div>
                                        
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                        <input type="date" name="paid_at" class="form-control" 
                                               value="<?= date('Y-m-d') ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method" class="form-select">
                                            <option value="cash">Cash</option>
                                            <option value="bank">Bank Transfer</option>
                                            <option value="upi">UPI</option>
                                            <option value="cheque">Cheque</option>
                                            <option value="adjustment">Adjustment</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Reference (Optional)</label>
                                        <input type="text" name="reference" class="form-control" placeholder="e.g. UPI ID, Cheque No., Bank Ref">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description / Notes</label>
                                        <textarea name="description" class="form-control" rows="3" placeholder="Any additional notes..."></textarea>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="bx bx-check me-1"></i> Record Payment
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Payments -->
                    <div class="col-lg-7">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-history me-1"></i> Recent Payments
                                </h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Reference</th>
                                                <th>Processed By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recent_payments)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-5 text-muted">
                                                    <i class="bx bx-history display-4 d-block mb-3"></i>
                                                    No payments recorded yet.
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($recent_payments as $pay): ?>
                                            <tr>
                                                <td><?= date('d M Y', strtotime($pay['paid_at'])) ?></td>
                                                <td><strong class="text-success">₹<?= number_format($pay['amount'], 0) ?></strong></td>
                                                <td>
                                                    <span class="badge bg-info"><?= ucfirst($pay['payment_method']) ?></span>
                                                </td>
                                                <td><?= htmlspecialchars($pay['reference'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($pay['processed_by_name'] ?? 'Unknown') ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include(__DIR__ . '/includes/footer.php'); ?>
    </div>
</div>

<?php include(__DIR__ . '/includes/rightbar.php'); ?>
<?php include(__DIR__ . '/includes/scripts.php'); ?>

<script>
$(document).ready(function() {
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Auto-hide alerts
    setTimeout(() => $('.alert').fadeOut(1000), 5000);
});
</script>

<style>
.card-hover:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.12) !important; }
</style>
</body>
</html>s