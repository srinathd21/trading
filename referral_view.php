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

$current_business_id = (int) $_SESSION['current_business_id'];
$referral_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($referral_id <= 0) {
    header('Location: referral_persons.php');
    exit();
}

// Fetch referral person details + earnings from invoices
$stmt = $pdo->prepare("
    SELECT 
        rp.*,
        COALESCE(u.full_name, u.username, u.email) AS created_by_name,
        COUNT(i.id) AS total_referral_invoices,
        COALESCE(SUM(i.referral_commission_amount), 0) AS total_earned_commission,
        COALESCE(SUM(i.total), 0) AS total_sales_generated
    FROM referral_person rp
    LEFT JOIN users u ON rp.created_by = u.id
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

// Fetch recent referred invoices (as "transactions")
$invoices_stmt = $pdo->prepare("
    SELECT 
        i.id,
        i.invoice_number,
        i.total,
        i.referral_commission_amount,
        i.created_at,
        c.name AS customer_name,
        c.phone AS customer_phone
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.referral_id = ? AND i.business_id = ?
    ORDER BY i.created_at DESC
    LIMIT 10
");
$invoices_stmt->execute([$referral_id, $current_business_id]);
$recent_invoices = $invoices_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch referred customers
$customers_stmt = $pdo->prepare("
    SELECT 
        c.id, 
        c.name, 
        c.phone, 
        c.email,
        COUNT(i.id) AS total_invoices,
        SUM(i.total) AS total_amount,
        SUM(i.referral_commission_amount) AS commission_earned,
        MAX(i.created_at) AS last_purchase
    FROM customers c
    INNER JOIN invoices i ON c.id = i.customer_id
    WHERE i.referral_id = ? AND i.business_id = ?
    GROUP BY c.id
    ORDER BY last_purchase DESC
    LIMIT 10
");
$customers_stmt->execute([$referral_id, $current_business_id]);
$referred_customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine outstanding type and color
if ($referral['balance_due'] > 0) {
    $balance_class = 'danger';
    $balance_icon = 'bx-down-arrow-alt';
    $balance_type = 'Debit Outstanding';
    $balance_description = 'You owe money to referral person';
} elseif ($referral['balance_due'] < 0) {
    $balance_class = 'success';
    $balance_icon = 'bx-up-arrow-alt';
    $balance_type = 'Credit Outstanding';
    $balance_description = 'Referral person owes money to you';
} else {
    $balance_class = 'secondary';
    $balance_icon = 'bx-check';
    $balance_type = 'Settled';
    $balance_description = 'No outstanding balance';
}

// Determine initial outstanding display
$initial_outstanding_html = '';
if ($referral['initial_outstanding_type'] && $referral['initial_outstanding_amount'] > 0) {
    if ($referral['initial_outstanding_type'] === 'debit') {
        $initial_class = 'danger';
        $initial_icon = 'bx-down-arrow-alt';
        $initial_type = 'Initial Debit';
        $initial_description = 'You initially owed money to referral person';
    } else {
        $initial_class = 'success';
        $initial_icon = 'bx-up-arrow-alt';
        $initial_type = 'Initial Credit';
        $initial_description = 'Referral person initially owed money to you';
    }
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "View Referral - " . htmlspecialchars($referral['full_name']); ?>
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
                                    <i class="bx bx-user-circle me-2"></i> Referral Person Details
                                </h4>
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb mb-0">
                                        <li class="breadcrumb-item"><a href="referrals.php">Referral Persons</a></li>
                                        <li class="breadcrumb-item active"><?= htmlspecialchars($referral['full_name']) ?></li>
                                    </ol>
                                </nav>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="referrals.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back
                                </a>
                                <a href="referral_edit.php?id=<?= $referral_id ?>" class="btn btn-warning">
                                    <i class="bx bx-edit me-1"></i> Edit
                                </a>
                                <a href="pay_referral.php?id=<?= $referral_id ?>" class="btn btn-success">
                                    <i class="bx bx-money me-1"></i> Make Payment
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile & Stats -->
                <div class="row mb-4">
                    <div class="col-lg-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-4">
                                    <div class="avatar-lg me-3">
                                        <div class="avatar-title bg-primary bg-opacity-10 text-primary rounded-circle" style="width: 80px; height: 80px;">
                                            <i class="bx bx-user-circle fs-1"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <h4 class="mb-1"><?= htmlspecialchars($referral['full_name']) ?></h4>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="badge bg-<?= $referral['is_active'] ? 'success' : 'danger' ?> bg-opacity-10 text-<?= $referral['is_active'] ? 'success' : 'danger' ?>">
                                                <i class="bx <?= $referral['is_active'] ? 'bx-check-circle' : 'bx-x-circle' ?> me-1"></i>
                                                <?= $referral['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                            <span class="badge bg-info bg-opacity-10 text-info">
                                                <i class="bx bx-id-card me-1"></i> <?= htmlspecialchars($referral['referral_code']) ?>
                                            </span>
                                        </div>
                                        <p class="text-muted mb-0">
                                            <i class="bx bx-calendar me-1"></i>
                                            Joined <?= date('F d, Y', strtotime($referral['created_at'])) ?>
                                        </p>
                                        <?php if (!empty($referral['created_by_name'])): ?>
                                        <p class="text-muted mb-0">
                                            <i class="bx bx-user me-1"></i>
                                            By: <?= htmlspecialchars($referral['created_by_name']) ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="border-top pt-3">
                                    <h6 class="mb-3">Contact Information</h6>
                                    <?php if (!empty($referral['phone'])): ?>
                                    <div class="d-flex align-items-center mb-3">
                                        <i class="bx bx-phone text-primary me-3 fs-5"></i>
                                        <div>
                                            <small class="text-muted">Phone</small><br>
                                            <strong><?= htmlspecialchars($referral['phone']) ?></strong>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($referral['email'])): ?>
                                    <div class="d-flex align-items-center mb-3">
                                        <i class="bx bx-envelope text-primary me-3 fs-5"></i>
                                        <div>
                                            <small class="text-muted">Email</small><br>
                                            <strong><?= htmlspecialchars($referral['email']) ?></strong>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($referral['address'])): ?>
                                    <div class="d-flex align-items-start">
                                        <i class="bx bx-map text-primary me-3 fs-5 mt-1"></i>
                                        <div>
                                            <small class="text-muted">Address</small><br>
                                            <strong><?= nl2br(htmlspecialchars($referral['address'])) ?></strong>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($referral['department'])): ?>
                                    <div class="d-flex align-items-center mt-3">
                                        <i class="bx bx-briefcase text-primary me-3 fs-5"></i>
                                        <div>
                                            <small class="text-muted">Department</small><br>
                                            <strong><?= htmlspecialchars($referral['department']) ?></strong>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Commission Info -->
                                <div class="border-top pt-3 mt-3">
                                    <h6 class="mb-2">Commission Settings</h6>
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bx bx-percent text-primary me-3 fs-5"></i>
                                        <div>
                                            <small class="text-muted">Commission Rate</small><br>
                                            <strong><?= $referral['commission_percent'] ?>% per sale</strong>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($referral['notes'])): ?>
                                <div class="border-top pt-3 mt-3">
                                    <h6 class="mb-2">Notes</h6>
                                    <p class="text-muted"><?= nl2br(htmlspecialchars($referral['notes'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <!-- Outstanding Summary -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-credit-card me-2"></i> Financial Summary
                                </h5>
                                <div class="row">
                                    <div class="col-md-6 mb-4">
                                        <div class="border-start border-<?= $balance_class ?> border-4 ps-3">
                                            <h6 class="text-muted mb-1">Current Balance</h6>
                                            <h3 class="mb-2 text-<?= $balance_class ?>">
                                                <i class="bx <?= $balance_icon ?> me-2"></i>
                                                ₹<?= number_format(abs($referral['balance_due']), 0) ?>
                                            </h3>
                                            <p class="text-<?= $balance_class ?> mb-1">
                                                <strong><?= $balance_type ?></strong>
                                            </p>
                                            <small class="text-muted"><?= $balance_description ?></small>
                                            <div class="mt-3">
                                                <small class="text-muted">
                                                    <strong>Earned:</strong> ₹<?= number_format($referral['debit_amount'], 0) ?><br>
                                                    <strong>Paid:</strong> ₹<?= number_format($referral['paid_amount'], 0) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($referral['initial_outstanding_type'] && $referral['initial_outstanding_amount'] > 0): ?>
                                    <div class="col-md-6 mb-4">
                                        <div class="border-start border-<?= $initial_class ?> border-4 ps-3">
                                            <h6 class="text-muted mb-1">Initial Outstanding</h6>
                                            <h3 class="mb-2 text-<?= $initial_class ?>">
                                                <i class="bx <?= $initial_icon ?> me-2"></i>
                                                ₹<?= number_format($referral['initial_outstanding_amount'], 0) ?>
                                            </h3>
                                            <p class="text-<?= $initial_class ?> mb-1">
                                                <strong><?= $initial_type ?></strong>
                                            </p>
                                            <small class="text-muted"><?= $initial_description ?></small>
                                            <div class="mt-3">
                                                <small class="text-muted">
                                                    <strong>Type:</strong> <?= ucfirst($referral['initial_outstanding_type']) ?><br>
                                                    <strong>Added on:</strong> <?= date('d M Y', strtotime($referral['created_at'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Stats Cards -->
                        <div class="row mb-4">
                            <div class="col-md-6 col-lg-4">
                                <div class="card card-hover border-start border-primary border-4 h-100">
                                    <div class="card-body">
                                        <h6 class="text-muted mb-1">Total Earned</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($referral['debit_amount'], 0) ?></h3>
                                        <small class="text-muted">From <?= $referral['total_referral_invoices'] ?> invoice(s)</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <div class="card card-hover border-start border-success border-4 h-100">
                                    <div class="card-body">
                                        <h6 class="text-muted mb-1">Sales Generated</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($referral['total_sales_generated'], 0) ?></h3>
                                        <small class="text-muted">Via referrals</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <div class="card card-hover border-start border-info border-4 h-100">
                                    <div class="card-body">
                                        <h6 class="text-muted mb-1">Referral Count</h6>
                                        <h3 class="mb-0 text-info"><?= $referral['total_referrals'] ?></h3>
                                        <small class="text-muted">Successful sales</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Referral Sales (Invoices) -->
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="card-title mb-0">
                                        <i class="bx bx-receipt me-1"></i> Recent Referral Sales
                                    </h5>
                                    <a href="invoices.php?referral_id=<?= $referral_id ?>" class="btn btn-sm btn-outline-primary">
                                        View All Invoices
                                    </a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Invoice</th>
                                                <th>Customer</th>
                                                <th class="text-end">Sale Amount</th>
                                                <th class="text-end">Commission</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recent_invoices)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-5">
                                                    <i class="bx bx-receipt display-4 text-muted d-block mb-3"></i>
                                                    <h6>No Sales Yet</h6>
                                                    <p class="text-muted">This referral person has not generated any sales.</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($recent_invoices as $inv): ?>
                                            <tr>
                                                <td><?= date('d M Y', strtotime($inv['created_at'])) ?></td>
                                                <td>
                                                    <a href="invoice_view.php?id=<?= $inv['id'] ?>" class="text-primary">
                                                        <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong>
                                                    </a>
                                                </td>
                                                <td><?= htmlspecialchars($inv['customer_name'] ?? 'Walk-in') ?></td>
                                                <td class="text-end">₹<?= number_format($inv['total'], 0) ?></td>
                                                <td class="text-end text-success">
                                                    <strong>₹<?= number_format($inv['referral_commission_amount'], 0) ?></strong>
                                                </td>
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

                <!-- Referred Customers -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-group me-1"></i> Referred Customers (<?= count($referred_customers) ?>)
                                </h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Customer</th>
                                                <th>Contact</th>
                                                <th class="text-center">Invoices</th>
                                                <th class="text-end">Total Spent</th>
                                                <th class="text-end">Commission Earned</th>
                                                <th>Last Purchase</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($referred_customers)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-5">
                                                    <i class="bx bx-user-x display-4 text-muted d-block mb-3"></i>
                                                    <h6>No Referred Customers Yet</h6>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($referred_customers as $cust): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($cust['name']) ?></strong>
                                                </td>
                                                <td>
                                                    <?= !empty($cust['phone']) ? htmlspecialchars($cust['phone']) : '<em class="text-muted">No phone</em>' ?><br>
                                                    <small class="text-muted"><?= htmlspecialchars($cust['email'] ?? '') ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-info"><?= $cust['total_invoices'] ?></span>
                                                </td>
                                                <td class="text-end">₹<?= number_format($cust['total_amount'], 0) ?></td>
                                                <td class="text-end text-success">₹<?= number_format($cust['commission_earned'], 0) ?></td>
                                                <td><?= date('d M Y', strtotime($cust['last_purchase'])) ?></td>
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

                <!-- ID Proof Information (if available) -->
                <?php if (!empty($referral['proof_id_type']) || !empty($referral['proof_id_number']) || !empty($referral['proof_id_file'])): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-id-card me-1"></i> ID Proof Information
                                </h5>
                                <div class="row">
                                    <?php if (!empty($referral['proof_id_type'])): ?>
                                    <div class="col-md-4 mb-3">
                                        <small class="text-muted d-block">ID Type</small>
                                        <strong><?= ucfirst(str_replace('_', ' ', $referral['proof_id_type'])) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($referral['proof_id_number'])): ?>
                                    <div class="col-md-4 mb-3">
                                        <small class="text-muted d-block">ID Number</small>
                                        <strong><?= htmlspecialchars($referral['proof_id_number']) ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($referral['proof_id_file'])): ?>
                                    <div class="col-md-4 mb-3">
                                        <small class="text-muted d-block">Uploaded Document</small>
                                        <a href="<?= htmlspecialchars($referral['proof_id_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bx bx-download me-1"></i> View Document
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
});
</script>

<style>
.card-hover:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.12) !important; transition: all 0.2s ease; }
.avatar-lg .avatar-title { font-size: 3.5rem; }
.border-start { border-left-width: 4px !important; }
.h-100 { height: 100%; }
</style>
</body>
</html>