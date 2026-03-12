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

if (!in_array($user_role, ['admin', 'warehouse_manager', 'shop_manager','stock_manager'])) {
    header('Location: dashboard.php');
    exit();
}

// Success message after payment
$payment_success = isset($_GET['success']) && $_GET['success'] === 'payment';
$paid_po = htmlspecialchars($_GET['po'] ?? '', ENT_QUOTES);

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$manufacturer_id = isset($_GET['manufacturer']) ? (int)$_GET['manufacturer'] : 0;

// Get manufacturer name if manufacturer_id is provided
$manufacturer_name = '';
if ($manufacturer_id > 0) {
    $man_stmt = $pdo->prepare("SELECT name FROM manufacturers WHERE id = ? AND business_id = ?");
    $man_stmt->execute([$manufacturer_id, $business_id]);
    $manufacturer = $man_stmt->fetch();
    $manufacturer_name = $manufacturer ? $manufacturer['name'] : '';
}

// === Build WHERE clause with filters ===
$where = ["p.business_id = ?"];
$params = [$business_id];

// Manufacturer filter
if ($manufacturer_id > 0) {
    $where[] = "p.manufacturer_id = ?";
    $params[] = $manufacturer_id;
}

// Search filter
if (!empty($search)) {
    $where[] = "(p.purchase_number LIKE ? OR p.reference LIKE ? OR m.name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Status filter
if (!empty($status) && in_array($status, ['paid', 'partial', 'unpaid'])) {
    $where[] = "p.payment_status = ?";
    $params[] = $status;
}

// Date range filters
if (!empty($date_from)) {
    $where[] = "DATE(p.purchase_date) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where[] = "DATE(p.purchase_date) <= ?";
    $params[] = $date_to;
}

// Build WHERE clause string
$whereClause = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

// === Summary Stats with filters ===
$stats_sql = "
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(total_amount), 0) AS total_amount,
        COALESCE(SUM(paid_amount), 0) AS total_paid,
        COALESCE(SUM(total_amount - paid_amount), 0) AS pending_amount,
        SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count
    FROM purchases p
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    $whereClause
";

$stmt = $pdo->prepare($stats_sql);
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// === Fetch Filtered Purchase Orders ===
$purchases_sql = "
    SELECT
        p.id,
        p.purchase_number,
        p.purchase_date,
        p.total_amount,
        p.paid_amount,
        p.payment_status,
        p.reference,
        p.manufacturer_id,
        m.name AS manufacturer_name,
        u.full_name AS created_by_name,
        (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = p.id) AS item_count
    FROM purchases p
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    LEFT JOIN users u ON p.created_by = u.id
    $whereClause
    ORDER BY p.purchase_date DESC, p.id DESC
";

$purchases_stmt = $pdo->prepare($purchases_sql);
$purchases_stmt->execute($params);
$purchases = $purchases_stmt->fetchAll(PDO::FETCH_ASSOC);

// Messages
$success = $_SESSION['success'] ?? ''; unset($_SESSION['success']);
$error = $_SESSION['error'] ?? ''; unset($_SESSION['error']);
?>
<!doctype html>
<html lang="en">
<?php 
$page_title = "Purchase Orders"; 
include 'includes/head.php'; 
?>
<!-- Add SweetAlert2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
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
.purchase-row:hover .avatar-sm .rounded-circle {
    transform: scale(1.1);
    transition: transform 0.3s ease;
}
.btn-group-action {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    justify-content: center;
}
.btn-group-action .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}
.manufacturer-filter-badge {
    background-color: #e7f5ff;
    color: #0c63e4;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 1rem;
}
.manufacturer-filter-badge a {
    color: #0c63e4;
    text-decoration: none;
    font-weight: 500;
}
.manufacturer-filter-badge a:hover {
    text-decoration: underline;
}
@media (max-width: 768px) {
    .btn-group-action {
        flex-direction: column;
    }
    .btn-group-action .btn {
        width: 100%;
    }
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
}
</style>
</head>
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
                                <i class="bx bx-shopping-bag me-2"></i> Purchase Orders
                                <small class="text-muted ms-2">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <button onclick="exportPurchases()" class="btn btn-outline-secondary">
                                    <i class="bx bx-download me-1"></i> Export Excel
                                </button>
                                <a href="purchase_add.php" class="btn btn-primary">
                                    <i class="bx bx-plus-circle me-1"></i> New Purchase Order
                                </a>
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
                <?php if ($payment_success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i>
                    <strong>Payment Recorded!</strong> Successfully added payment for Purchase Order
                    <strong><?= $paid_po ?: 'the PO' ?></strong>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Manufacturer Filter Badge (if filtering by manufacturer) -->
                <?php if ($manufacturer_id > 0 && $manufacturer_name): ?>
                <div class="manufacturer-filter-badge">
                    <i class="bx bx-building"></i>
                    <span>Showing purchases for: <strong><?= htmlspecialchars($manufacturer_name) ?></strong></span>
                    <a href="purchases.php" class="ms-2">
                        <i class="bx bx-x-circle"></i> Clear filter
                    </a>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Purchases</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($stats['total_amount']) ?></h3>
                                        <small class="text-muted"><?= $stats['total_orders'] ?> orders</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-shopping-bag text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Total Paid</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($stats['total_paid']) ?></h3>
                                        <small class="text-muted">
                                            <?= $stats['total_orders'] > 0 ? number_format(($stats['total_paid'] / $stats['total_amount']) * 100, 1) : '0' ?>% paid
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
                                        <h6 class="text-muted mb-1">Pending Amount</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($stats['pending_amount']) ?></h3>
                                        <small class="text-muted"><?= $stats['unpaid_count'] ?> unpaid orders</small>
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
                                        <h6 class="text-muted mb-1">Payment Status</h6>
                                        <div class="d-flex align-items-center">
                                            <?php if ($stats['total_orders'] > 0): 
                                                $paid_percent = ($stats['total_paid'] / $stats['total_amount']) * 100;
                                                $pending_percent = ($stats['pending_amount'] / $stats['total_amount']) * 100;
                                            ?>
                                            <div class="flex-grow-1 me-3">
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar bg-success" style="width: <?= $paid_percent ?>%"></div>
                                                    <div class="progress-bar bg-warning" style="width: <?= $pending_percent ?>%"></div>
                                                </div>
                                            </div>
                                            <div>
                                                <small class="text-muted"><?= number_format($paid_percent, 1) ?>%</small>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-muted">No purchases</div>
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
                            <i class="bx bx-filter-alt me-1"></i> Filter Purchase Orders
                        </h5>
                        <form method="GET" id="filterForm">
                            <?php if ($manufacturer_id > 0): ?>
                            <input type="hidden" name="manufacturer" value="<?= $manufacturer_id ?>">
                            <?php endif; ?>
                            <div class="row g-3">
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="PO number, supplier, reference..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="paid" <?= $status == 'paid' ? 'selected' : '' ?>>Paid</option>
                                        <option value="partial" <?= $status == 'partial' ? 'selected' : '' ?>>Partial</option>
                                        <option value="unpaid" <?= $status == 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
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
                                <div class="col-lg-2 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply
                                        </button>
                                        <?php if ($search || $status || $date_from || $date_to || $manufacturer_id): ?>
                                        <a href="purchases.php<?= $manufacturer_id > 0 ? '?manufacturer=' . $manufacturer_id : '' ?>" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Purchases Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="purchasesTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>PO Details</th>
                                        <th class="text-center">Supplier</th>
                                        <th class="text-center">Date</th>
                                        <th class="text-center">Items</th>
                                        <th class="text-center">Payment Status</th>
                                        <th class="text-center">Amount Details</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($purchases)): ?>
                                    
                                    <?php else: ?>
                                    <?php foreach ($purchases as $p): 
                                        $status_classes = [
                                            'paid' => 'success',
                                            'partial' => 'warning',
                                            'unpaid' => 'danger'
                                        ];
                                        $status_color = $status_classes[$p['payment_status']] ?? 'secondary';
                                        $status_icon = [
                                            'paid' => 'bx-check-circle',
                                            'partial' => 'bx-time-five',
                                            'unpaid' => 'bx-x-circle'
                                        ][$p['payment_status']] ?? 'bx-receipt';
                                        $balance = $p['total_amount'] - $p['paid_amount'];
                                    ?>
                                    <tr class="purchase-row" data-id="<?= $p['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx <?= $status_icon ?> fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($p['purchase_number']) ?></strong>
                                                    <?php if ($p['reference']): ?>
                                                    <small class="text-muted">
                                                        <i class="bx bx-hash me-1"></i><?= htmlspecialchars($p['reference']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-user me-1"></i><?= htmlspecialchars($p['created_by_name']) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <?php if ($p['manufacturer_id']): ?>
                                                <a href="purchases.php?manufacturer=<?= $p['manufacturer_id'] ?>" class="text-decoration-none">
                                                    <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                        <i class="bx bx-building me-1"></i><?= htmlspecialchars($p['manufacturer_name'] ?? '—') ?>
                                                    </span>
                                                </a>
                                                <?php else: ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1">
                                                    <i class="bx bx-building me-1"></i>—
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <strong class="d-block"><?= date('d M Y', strtotime($p['purchase_date'])) ?></strong>
                                                <small class="text-muted"><?= date('D', strtotime($p['purchase_date'])) ?></small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 fs-6">
                                                    <i class="bx bx-package me-1"></i> <?= $p['item_count'] ?>
                                                </span>
                                                <small class="text-muted d-block">Items</small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> px-3 py-2">
                                                    <i class="bx <?= $status_icon ?> me-1"></i><?= ucfirst($p['payment_status']) ?>
                                                </span>
                                            </div>
                                            <?php if ($p['payment_status'] !== 'paid'): ?>
                                            <small class="text-danger">
                                                <i class="bx bx-alarm me-1"></i>₹<?= number_format($balance) ?> pending
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="amount-details">
                                                <div class="mb-2">
                                                    <span class="badge bg-dark bg-opacity-10 text-dark px-3 py-2 fs-6">
                                                        <i class="bx bx-rupee me-1"></i> <?= number_format($p['total_amount']) ?>
                                                    </span>
                                                    <small class="text-muted d-block">Total Amount</small>
                                                </div>
                                                <div class="d-flex justify-content-center gap-2">
                                                    <small class="text-success">
                                                        <i class="bx bx-check"></i> ₹<?= number_format($p['paid_amount']) ?>
                                                    </small>
                                                    <?php if ($balance > 0): ?>
                                                    <small class="text-danger">
                                                        <i class="bx bx-time"></i> ₹<?= number_format($balance) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group-action">
                                                <a href="purchase_view.php?id=<?= $p['id'] ?>" 
                                                   class="btn btn-sm btn-outline-info"
                                                   data-bs-toggle="tooltip"
                                                   title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </a>
                                                <?php if ($user_role === 'admin' || $user_role === 'warehouse_manager'): ?>
                                                <a href="purchase_edit.php?id=<?= $p['id'] ?>" 
                                                   class="btn btn-sm btn-outline-primary"
                                                   data-bs-toggle="tooltip"
                                                   title="Edit Purchase">
                                                    <i class="bx bx-edit"></i>
                                                </a>
                                                <?php endif; ?>
                                                <a href="purchase_payments_history.php?id=<?= $p['id'] ?>" 
                                                   class="btn btn-sm btn-outline-secondary"
                                                   data-bs-toggle="tooltip"
                                                   title="Payment History">
                                                    <i class="bx bx-history"></i>
                                                </a>
                                                <?php if ($p['payment_status'] !== 'paid'): ?>
                                                <a href="purchase_payment.php?id=<?= $p['id'] ?>" 
                                                   class="btn btn-sm btn-outline-success"
                                                   data-bs-toggle="tooltip"
                                                   title="Add Payment">
                                                    <i class="bx bx-money"></i>
                                                </a>
                                                <?php endif; ?>
                                                <button type="button" 
                                                        onclick="printPurchase(<?= $p['id'] ?>)"
                                                        class="btn btn-sm btn-outline-dark"
                                                        data-bs-toggle="tooltip"
                                                        title="Print PO">
                                                    <i class="bx bx-printer"></i>
                                                </button>
                                                <?php if ($user_role === 'admin'): ?>
                                                <button type="button" 
                                                        onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars($p['purchase_number']) ?>')"
                                                        class="btn btn-sm btn-outline-danger"
                                                        data-bs-toggle="tooltip"
                                                        title="Delete Purchase">
                                                    <i class="bx bx-trash"></i>
                                                </button>
                                                <?php endif; ?>
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
<!-- Add SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#purchasesTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search purchases:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ orders",
            infoFiltered: "(filtered from <?= count($purchases) ?> total orders)",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        },
        columnDefs: [
            { orderable: false, targets: [6] } // Disable sorting on actions column
        ]
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Auto-submit on filter change
    $('select[name="status"], input[name="date_from"], input[name="date_to"]').on('change', function() {
        if ($(this).val() !== '') {
            $('#filterForm').submit();
        }
    });

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Row hover effect
    $('.purchase-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );
});

function printPurchase(id) {
    window.open('purchase_print.php?id=' + id, 'PrintPO', 'width=900,height=700,scrollbars=yes,resizable=yes');
}

function exportPurchases() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
    btn.disabled = true;
    
    // Build export URL with current search parameters
    const params = new URLSearchParams(window.location.search);
    const exportUrl = 'purchases_export.php' + (params.toString() ? '?' + params.toString() : '');
    
    window.location = exportUrl;
    
    // Reset button after 3 seconds
    setTimeout(() => {
        btn.innerHTML = original;
        btn.disabled = false;
    }, 3000);
}

function confirmDelete(id, poNumber) {
    Swal.fire({
        title: 'Delete Purchase Order?',
        html: `Are you sure you want to delete <strong>${poNumber}</strong>?<br><br>
               <span class="text-danger">This will:</span>
               <ul class="text-start mt-2">
                   <li>Remove all items from this purchase</li>
                   <li>Restore stock quantities</li>
                   <li>Restore previous product prices</li>
                   <li>Delete all payment records</li>
               </ul>
               <strong class="text-danger">This action cannot be undone!</strong>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            return fetch('purchase_delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'id=' + id
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        console.error('Server response:', text);
                        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message);
                }
                return data;
            })
            .catch(error => {
                Swal.showValidationMessage(`Request failed: ${error.message}`);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleted!',
                text: result.value.message,
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                window.location.reload();
            });
        }
    });
}
</script>
</body>
</html>