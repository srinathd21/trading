<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Authorization
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 1;
$user_role = $_SESSION['role'] ?? '';

if (!in_array($user_role, ['admin', 'warehouse_manager', 'stock_manager'])) {
    header('Location: dashboard.php');
    exit();
}

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$min_amount = trim($_GET['min_amount'] ?? '');
$max_amount = trim($_GET['max_amount'] ?? '');

// === Build WHERE clause with filters ===
$where = ["gc.business_id = ?"];
$params = [$business_id];

// Search filter
if (!empty($search)) {
    $where[] = "(gc.purchase_number LIKE ? OR gc.purchase_invoice_no LIKE ? OR p.reference LIKE ? OR m.name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Status filter
if (!empty($status) && in_array($status, ['claimed', 'not_claimed'])) {
    $where[] = "gc.status = ?";
    $params[] = $status;
}

// Date range filters
if (!empty($date_from)) {
    $where[] = "DATE(gc.created_at) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where[] = "DATE(gc.created_at) <= ?";
    $params[] = $date_to;
}

// Amount range filters
if (!empty($min_amount) && is_numeric($min_amount)) {
    $where[] = "gc.credit_amount >= ?";
    $params[] = floatval($min_amount);
}
if (!empty($max_amount) && is_numeric($max_amount)) {
    $where[] = "gc.credit_amount <= ?";
    $params[] = floatval($max_amount);
}

// Build WHERE clause string
$whereClause = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

// === Summary Stats with filters ===
$stats_sql = "
    SELECT
        COUNT(*) AS total_credits,
        COALESCE(SUM(credit_amount), 0) AS total_credit_amount,
        COALESCE(SUM(CASE WHEN status = 'claimed' THEN credit_amount ELSE 0 END), 0) AS claimed_amount,
        COALESCE(SUM(CASE WHEN status = 'not_claimed' THEN credit_amount ELSE 0 END), 0) AS unclaimed_amount,
        SUM(CASE WHEN status = 'not_claimed' THEN 1 ELSE 0 END) AS unclaimed_count
    FROM gst_credits gc
    LEFT JOIN purchases p ON gc.purchase_id = p.id AND gc.business_id = p.business_id
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    $whereClause
";

$stmt = $pdo->prepare($stats_sql);
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// === Fetch Filtered GST Credits ===
$credits_sql = "
    SELECT
        gc.id,
        gc.purchase_id,
        gc.purchase_number,
        gc.purchase_invoice_no,
        gc.credit_amount,
        gc.status,
        gc.created_at,
        gc.updated_at,
        p.purchase_date,
        p.total_amount,
        p.payment_status,
        p.reference,
        m.name AS manufacturer_name,
        u.full_name AS created_by_name
    FROM gst_credits gc
    LEFT JOIN purchases p ON gc.purchase_id = p.id AND gc.business_id = p.business_id
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    LEFT JOIN users u ON p.created_by = u.id
    $whereClause
    ORDER BY gc.created_at DESC, gc.id DESC
";

$credits_stmt = $pdo->prepare($credits_sql);
$credits_stmt->execute($params);
$credits = $credits_stmt->fetchAll(PDO::FETCH_ASSOC);

// Messages
$success = $_SESSION['success'] ?? ''; unset($_SESSION['success']);
$error = $_SESSION['error'] ?? ''; unset($_SESSION['error']);
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "GST Input Credit"; 
include 'includes/head.php'; 
?>
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
                <!-- Page Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-credit-card me-2"></i> GST Input Credit
                                <small class="text-muted ms-2">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <button onclick="exportGSTCredits()" class="btn btn-outline-secondary">
                                    <i class="bx bx-download me-1"></i> Export Excel
                                </button>
                                
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Credits</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($stats['total_credit_amount'], 2) ?></h3>
                                        <small class="text-muted"><?= $stats['total_credits'] ?> records</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-credit-card text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Claimed Amount</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($stats['claimed_amount'], 2) ?></h3>
                                        <small class="text-muted">
                                            <?= $stats['total_credit_amount'] > 0 ? number_format(($stats['claimed_amount'] / $stats['total_credit_amount']) * 100, 1) : '0' ?>% claimed
                                        </small>
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
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Unclaimed Amount</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($stats['unclaimed_amount'], 2) ?></h3>
                                        <small class="text-muted"><?= $stats['unclaimed_count'] ?> pending records</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-time-five text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Claim Status</h6>
                                        <div class="d-flex align-items-center">
                                            <?php if ($stats['total_credit_amount'] > 0): 
                                                $claimed_percent = ($stats['claimed_amount'] / $stats['total_credit_amount']) * 100;
                                                $unclaimed_percent = ($stats['unclaimed_amount'] / $stats['total_credit_amount']) * 100;
                                            ?>
                                            <div class="flex-grow-1 me-3">
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar bg-success" style="width: <?= $claimed_percent ?>%"></div>
                                                    <div class="progress-bar bg-warning" style="width: <?= $unclaimed_percent ?>%"></div>
                                                </div>
                                            </div>
                                            <div>
                                                <small class="text-muted"><?= number_format($claimed_percent, 1) ?>%</small>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-muted">No credits</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-pie-chart-alt text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search & Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter GST Credits
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="PO number, invoice, supplier..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="claimed" <?= $status == 'claimed' ? 'selected' : '' ?>>Claimed</option>
                                        <option value="not_claimed" <?= $status == 'not_claimed' ? 'selected' : '' ?>>Not Claimed</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="date_from" class="form-control" 
                                           value="<?= htmlspecialchars($date_from) ?>">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="date_to" class="form-control" 
                                           value="<?= htmlspecialchars($date_to) ?>">
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Amount Range</label>
                                    <div class="input-group">
                                        <input type="number" name="min_amount" class="form-control" 
                                               placeholder="Min" 
                                               value="<?= htmlspecialchars($min_amount) ?>"
                                               step="0.01">
                                        <span class="input-group-text">to</span>
                                        <input type="number" name="max_amount" class="form-control" 
                                               placeholder="Max" 
                                               value="<?= htmlspecialchars($max_amount) ?>"
                                               step="0.01">
                                    </div>
                                </div>
                                <div class="col-lg-12">
                                    <div class="d-flex gap-2 justify-content-end">
                                        <?php if ($search || $status || $date_from || $date_to || $min_amount || $max_amount): ?>
                                        <a href="gst_input_credit.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear All
                                        </a>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- GST Credits Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="gstCreditsTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Credit Details</th>
                                        <th class="text-center">Purchase Info</th>
                                        <th class="text-center">Date</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Amount Details</th>
                                        <th class="text-center">Supplier</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($credits)): ?>
                                    
                                    <?php else: ?>
                                    <?php foreach ($credits as $credit): 
                                        $status_classes = [
                                            'claimed' => 'success',
                                            'not_claimed' => 'warning'
                                        ];
                                        $status_color = $status_classes[$credit['status']] ?? 'secondary';
                                        $status_icon = [
                                            'claimed' => 'bx-check-circle',
                                            'not_claimed' => 'bx-time-five'
                                        ][$credit['status']] ?? 'bx-credit-card';
                                        
                                        $payment_status_classes = [
                                            'paid' => 'success',
                                            'partial' => 'warning',
                                            'unpaid' => 'danger'
                                        ];
                                        $payment_status_color = $payment_status_classes[$credit['payment_status']] ?? 'secondary';
                                        $payment_status_icon = [
                                            'paid' => 'bx-check-circle',
                                            'partial' => 'bx-time-five',
                                            'unpaid' => 'bx-x-circle'
                                        ][$credit['payment_status']] ?? 'bx-receipt';
                                    ?>
                                    <tr class="credit-row" data-id="<?= $credit['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx <?= $status_icon ?> fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1">Credit #GC<?= str_pad($credit['id'], 4, '0', STR_PAD_LEFT) ?></strong>
                                                    <?php if ($credit['purchase_invoice_no']): ?>
                                                    <small class="text-muted">
                                                        <i class="bx bx-receipt me-1"></i><?= htmlspecialchars($credit['purchase_invoice_no']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-calendar me-1"></i>Created: <?= date('d M Y', strtotime($credit['created_at'])) ?>
                                                    </small>
                                                    <?php if ($credit['updated_at'] != $credit['created_at']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-history me-1"></i>Updated: <?= date('d M Y', strtotime($credit['updated_at'])) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <strong class="d-block">
                                                    <a href="purchase_view.php?id=<?= $credit['purchase_id'] ?>" 
                                                       class="text-primary">
                                                        <?= htmlspecialchars($credit['purchase_number']) ?>
                                                    </a>
                                                </strong>
                                                <?php if ($credit['reference']): ?>
                                                <small class="text-muted">
                                                    <i class="bx bx-hash me-1"></i><?= htmlspecialchars($credit['reference']) ?>
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <span class="badge bg-<?= $payment_status_color ?> bg-opacity-10 text-<?= $payment_status_color ?> px-2 py-1">
                                                    <i class="bx <?= $payment_status_icon ?> me-1"></i><?= ucfirst($credit['payment_status']) ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <strong class="d-block"><?= date('d M Y', strtotime($credit['purchase_date'])) ?></strong>
                                                <small class="text-muted">Purchase Date</small>
                                            </div>
                                            <div>
                                                <small class="text-muted">
                                                    <?= date('h:i A', strtotime($credit['created_at'])) ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> px-3 py-2">
                                                    <i class="bx <?= $status_icon ?> me-1"></i><?= ucfirst(str_replace('_', ' ', $credit['status'])) ?>
                                                </span>
                                            </div>
                                            <?php if ($credit['status'] == 'not_claimed'): ?>
                                            <small class="text-warning">
                                                <i class="bx bx-alarm me-1"></i>Pending claim
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="amount-details">
                                                <div class="mb-2">
                                                    <span class="badge bg-dark bg-opacity-10 text-dark px-3 py-2 fs-6">
                                                        <i class="bx bx-rupee me-1"></i> <?= number_format($credit['credit_amount'], 2) ?>
                                                    </span>
                                                    <small class="text-muted d-block">Credit Amount</small>
                                                </div>
                                                <div>
                                                    <small class="text-muted">
                                                        PO Total: ₹<?= number_format($credit['total_amount'], 2) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                    <i class="bx bx-building me-1"></i><?= htmlspecialchars($credit['manufacturer_name'] ?? '—') ?>
                                                </span>
                                            </div>
                                            <?php if ($credit['created_by_name']): ?>
                                            <small class="text-muted d-block">
                                                <i class="bx bx-user me-1"></i><?= htmlspecialchars($credit['created_by_name']) ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="purchase_view.php?id=<?= $credit['purchase_id'] ?>" 
                                                   class="btn btn-outline-info"
                                                   data-bs-toggle="tooltip"
                                                   title="View Purchase">
                                                    <i class="bx bx-show"></i>
                                                </a>
                                                <a href="purchase_edit.php?id=<?= $credit['purchase_id'] ?>" 
                                                   class="btn btn-outline-primary"
                                                   data-bs-toggle="tooltip"
                                                   title="Edit Purchase">
                                                    <i class="bx bx-edit"></i>
                                                </a>
                                                <?php if ($credit['status'] == 'not_claimed'): ?>
                                                <button type="button" 
                                                        onclick="markAsClaimed(<?= $credit['id'] ?>, '<?= htmlspecialchars($credit['purchase_number']) ?>')"
                                                        class="btn btn-outline-success"
                                                        data-bs-toggle="tooltip"
                                                        title="Mark as Claimed">
                                                    <i class="bx bx-check"></i>
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" 
                                                        onclick="viewCreditDetails(<?= $credit['id'] ?>)"
                                                        class="btn btn-outline-secondary"
                                                        data-bs-toggle="tooltip"
                                                        title="Credit Details">
                                                    <i class="bx bx-detail"></i>
                                                </button>
                                            </div>
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
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#gstCreditsTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search credits:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ credits",
            infoFiltered: "(filtered from <?= count($credits) ?> total credits)",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Row hover effect
    $('.credit-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );
});

function markAsClaimed(creditId, purchaseNumber) {
    if (confirm(`Are you sure you want to mark GST credit for PO ${purchaseNumber} as claimed?`)) {
        // Show loading
        const btn = event.target.closest('button');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i>';
        btn.disabled = true;
        
        // Make AJAX request
        $.ajax({
            url: 'ajax_mark_gst_claimed.php',
            method: 'POST',
            data: { 
                credit_id: creditId,
                action: 'mark_claimed'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Show success message and reload page
                    showAlert('success', response.message);
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('danger', response.message || 'Failed to mark as claimed');
                    btn.innerHTML = original;
                    btn.disabled = false;
                }
            },
            error: function() {
                showAlert('danger', 'An error occurred. Please try again.');
                btn.innerHTML = original;
                btn.disabled = false;
            }
        });
    }
}

function viewCreditDetails(creditId) {
    // Open modal or redirect to detailed view
    $.ajax({
        url: 'ajax_get_gst_credit_details.php',
        method: 'GET',
        data: { credit_id: creditId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Create modal with credit details
                const modalHtml = `
                    <div class="modal fade" id="creditDetailsModal" tabindex="-1" aria-labelledby="creditDetailsModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="creditDetailsModalLabel">
                                        <i class="bx bx-credit-card me-2"></i>GST Credit Details
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Credit ID</label>
                                            <p class="fw-bold">GC${String(response.data.id).padStart(4, '0')}</p>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Status</label>
                                            <p>
                                                <span class="badge bg-${response.data.status == 'claimed' ? 'success' : 'warning'} bg-opacity-10 text-${response.data.status == 'claimed' ? 'success' : 'warning'} px-3 py-1">
                                                    <i class="bx bx-${response.data.status == 'claimed' ? 'check-circle' : 'time-five'} me-1"></i>
                                                    ${response.data.status.replace('_', ' ').toUpperCase()}
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Purchase Order</label>
                                            <p class="fw-bold">${response.data.purchase_number}</p>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Invoice Number</label>
                                            <p>${response.data.purchase_invoice_no || '—'}</p>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Credit Amount</label>
                                            <h4 class="text-primary">₹${parseFloat(response.data.credit_amount).toFixed(2)}</h4>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-muted">Created Date</label>
                                            <p>${new Date(response.data.created_at).toLocaleDateString('en-IN')}</p>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Note:</strong> This GST credit was automatically generated from purchase order.
                                        <br>To claim this credit, ensure you file your GST returns with the purchase invoice details.
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    <a href="purchase_view.php?id=${response.data.purchase_id}" class="btn btn-primary">
                                        <i class="bx bx-show me-1"></i> View Purchase
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Add modal to DOM
                $('body').append(modalHtml);
                const modal = new bootstrap.Modal(document.getElementById('creditDetailsModal'));
                modal.show();
                
                // Remove modal from DOM when hidden
                $('#creditDetailsModal').on('hidden.bs.modal', function () {
                    $(this).remove();
                });
            } else {
                showAlert('danger', 'Failed to load credit details');
            }
        },
        error: function() {
            showAlert('danger', 'An error occurred while loading credit details');
        }
    });
}

function exportGSTCredits() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
    btn.disabled = true;
    
    // Build export URL with current search parameters
    const params = new URLSearchParams(window.location.search);
    const exportUrl = 'gst_credits_export.php' + (params.toString() ? '?' + params.toString() : '');
    
    window.location = exportUrl;
    
    // Reset button after 3 seconds
    setTimeout(() => {
        btn.innerHTML = original;
        btn.disabled = false;
    }, 3000);
}

function showAlert(type, message) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="bx ${type === 'success' ? 'bx-check-circle' : 'bx-error-circle'} me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    // Remove existing alerts
    $('.alert').alert('close');
    
    // Add new alert at the top of page content
    $('.page-content .container-fluid').prepend(alertHtml);
    
    // Auto-close after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
}
</script>

<style>
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}
.empty-state i {
    font-size: 4rem;
    opacity: 0.5;
}
.avatar-sm {
    width: 48px;
    height: 48px;
}
.badge.bg-opacity-10 {
    opacity: 0.9;
}
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
}
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
.credit-row:hover .avatar-sm .rounded-circle {
    transform: scale(1.1);
    transition: transform 0.3s ease;
}
@media (max-width: 768px) {
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .btn-group .btn {
        width: 100%;
    }
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
}
</style>
</body>
</html>