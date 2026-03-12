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

$purchase_id = $_GET['id'] ?? 0;
if (!$purchase_id || !is_numeric($purchase_id)) {
    header('Location: purchases.php');
    exit();
}

// Fetch Purchase Details
$stmt = $pdo->prepare("
    SELECT p.*, m.name as manufacturer_name,
           (p.total_amount - p.paid_amount) as balance_amount
    FROM purchases p
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    WHERE p.id = ?
");
$stmt->execute([$purchase_id]);
$purchase = $stmt->fetch();

if (!$purchase || $purchase['payment_status'] === 'paid') {
    header('Location: purchases.php');
    exit();
}

$success = $error = '';

// Process Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $reference_no = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($amount <= 0 || $amount > $purchase['balance_amount']) {
        $error = "Invalid amount. Maximum payable: ₹" . number_format($purchase['balance_amount'], 2);
    } else {
        try {
            $pdo->beginTransaction();

            // Update purchase paid amount
            $new_paid = $purchase['paid_amount'] + $amount;
            $new_status = ($new_paid >= $purchase['total_amount']) ? 'paid' : 'partial';

            $pdo->prepare("
                UPDATE purchases 
                SET paid_amount = ?, payment_status = ? 
                WHERE id = ?
            ")->execute([$new_paid, $new_status, $purchase_id]);

            // Record payment in payments table
            $pdo->prepare("
                INSERT INTO payments 
                (payment_date, type, reference_id, amount, payment_method, reference_no, recorded_by, notes, created_at)
                VALUES (?, 'supplier', ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$payment_date, $purchase_id, $amount, $payment_method, $reference_no, $_SESSION['user_id'], $notes]);

            $pdo->commit();

            // Redirect with success
            header("Location: purchases.php?success=payment&po=" . urlencode($purchase['purchase_number']));
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Payment failed: " . $e->getMessage();
        }
    }
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Record Payment - PO #{$purchase['purchase_number']}"; include 'includes/head.php'; ?>

<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include 'includes/sidebar.php'; ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-money text-success me-2"></i>
                                Record Payment
                            </h4>
                            <a href="purchase_view.php?id=<?= $purchase_id ?>" class="btn btn-outline-primary">
                                <i class="bx bx-show me-1"></i> View PO
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bx bx-error me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card shadow-sm">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">
                                    <i class="bx bx-receipt me-2"></i>
                                    Purchase Order: <?= htmlspecialchars($purchase['purchase_number']) ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <strong>Supplier:</strong><br>
                                        <span class="fs-5"><?= htmlspecialchars($purchase['manufacturer_name']) ?></span>
                                    </div>
                                    <div class="col-md-6 text-md-end">
                                        <strong>Purchase Date:</strong><br>
                                        <?= date('d M Y', strtotime($purchase['purchase_date'])) ?>
                                    </div>
                                </div>
                                <hr>
                                <div class="row text-center py-3 bg-light rounded">
                                    <div class="col-4 border-end">
                                        <p class="mb-1 text-muted">Total Amount</p>
                                        <h4 class="mb-0 text-primary">₹<?= number_format($purchase['total_amount'], 2) ?></h4>
                                    </div>
                                    <div class="col-4 border-end">
                                        <p class="mb-1 text-muted">Paid Amount</p>
                                        <h4 class="mb-0 text-success">₹<?= number_format($purchase['paid_amount'], 2) ?></h4>
                                    </div>
                                    <div class="col-4">
                                        <p class="mb-1 text-muted">Balance Due</p>
                                        <h4 class="mb-0 text-danger fw-bold">₹<?= number_format($purchase['balance_amount'], 2) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card shadow-sm border-0">
                            <div class="card-header bg-gradient text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                <h5 class="mb-0">
                                    <i class="bx bx-rupee me-2"></i> Make Payment
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Amount <span class="text-danger">*</span></label>
                                        <input type="number" name="amount" class="form-control form-control-lg text-center" 
                                               step="0.01" min="1" max="<?= $purchase['balance_amount'] ?>" 
                                               placeholder="0.00" required>
                                        <small class="text-muted">Max: ₹<?= number_format($purchase['balance_amount'], 2) ?></small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Payment Date</label>
                                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method" class="form-select">
                                            <option value="cash">Cash</option>
                                            <option value="bank">Bank Transfer</option>
                                            <option value="upi">UPI</option>
                                            <option value="cheque">Cheque</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Reference / Transaction ID</label>
                                        <input type="text" name="reference_no" class="form-control" placeholder="Optional">
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes..."></textarea>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="bx bx-check-circle me-2"></i>
                                            Record Payment
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-history me-2"></i> Payment History
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $payments = $pdo->prepare("
                                    SELECT py.*, u.full_name as recorded_by
                                    FROM payments py
                                    LEFT JOIN users u ON py.recorded_by = u.id
                                    WHERE py.type = 'supplier' AND py.reference_id = ?
                                    ORDER BY py.payment_date DESC, py.id DESC
                                ");
                                $payments->execute([$purchase_id]);
                                $payments = $payments->fetchAll();
                                ?>
                                <?php if ($payments): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Reference</th>
                                                <th>Recorded By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payments as $pay): ?>
                                            <tr>
                                                <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                                                <td class="text-success fw-bold">₹<?= number_format($pay['amount'], 2) ?></td>
                                                <td>
                                                    <span class="badge bg-info"><?= ucfirst($pay['payment_method']) ?></span>
                                                </td>
                                                <td><?= htmlspecialchars($pay['reference_no'] ?: '—') ?></td>
                                                <td><?= htmlspecialchars($pay['recorded_by']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <p class="text-center text-muted py-4">
                                    <i class="bx bx-history fs-1 d-block mb-3 opacity-25"></i>
                                    No payments recorded yet
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<style>
.card-header.bg-gradient {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
}
.form-control-lg { font-size: 1.5rem; text-align: center; }
.btn-lg { padding: 0.75rem 2rem; }
.table-sm { font-size: 0.9rem; }
</style>
</body>
</html>