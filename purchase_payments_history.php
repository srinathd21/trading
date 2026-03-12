<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$purchase_id = $_GET['id'] ?? 0;
if (!$purchase_id || !is_numeric($purchase_id)) {
    header('Location: purchases.php');
    exit();
}

// Fetch Purchase Info
$stmt = $pdo->prepare("
    SELECT p.purchase_number, p.total_amount, p.paid_amount, m.name as supplier
    FROM purchases p
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    WHERE p.id = ?
");
$stmt->execute([$purchase_id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    header('Location: purchases.php');
    exit();
}

// Fetch Payment History
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

<!doctype html>
<html lang="en">
<?php $page_title = "Payment History - {$purchase['purchase_number']}"; include 'includes/head.php'; ?>

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
                        <div class="d-flex align-items-center justify-content-between">
                            <h4>
                                Payment History
                            </h4>
                            <a href="purchases.php" class="btn btn-outline-secondary">
                                Back to Purchases
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            Purchase Order: <?= htmlspecialchars($purchase['purchase_number']) ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <p><strong>Supplier:</strong> <?= htmlspecialchars($purchase['supplier']) ?></p>
                                <p><strong>Total Amount:</strong> ₹<?= number_format($purchase['total_amount'], 2) ?></p>
                                <p><strong>Paid Amount:</strong> <span class="text-success fw-bold">₹<?= number_format($purchase['paid_amount'], 2) ?></span></p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <h4 class="text-<?= $purchase['paid_amount'] >= $purchase['total_amount'] ? 'success' : 'warning' ?>">
                                    <?= $purchase['paid_amount'] >= $purchase['total_amount'] ? 'PAID' : 'PARTIAL' ?>
                                </h4>
                            </div>
                        </div>

                        <h5>Payment Records</h5>
                        <?php if ($payments): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Notes</th>
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
                                        <td><?= $pay['notes'] ? '<small>' . htmlspecialchars($pay['notes']) . '</small>' : '—' ?></td>
                                        <td><?= htmlspecialchars($pay['recorded_by']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bx bx-history" style="font-size: 4rem; opacity: 0.3;"></i>
                            <p class="mt-3">No payment records found</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>
</body>
</html>