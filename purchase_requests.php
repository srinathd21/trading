<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// ==================== AUTHORIZATION ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$business_id = $_SESSION['business_id'] ?? 1;

// Check if user has permission
if (!in_array($user_role, ['admin', 'warehouse_manager','shop_manager','stock_manager'])) {
    $_SESSION['error'] = "Access denied. You don't have permission to view purchase requests.";
    header('Location: dashboard.php');
    exit();
}

$success = isset($_GET['success']) && $_GET['success'] == '1';

// ==================== FILTERS ====================
$where = ["pr.business_id = ?"];
$params = [$business_id];

$status = $_GET['status'] ?? '';
if ($status !== '' && in_array($status, ['draft','sent','quotation_received','approved','rejected'])) {
    $where[] = "pr.status = ?";
    $params[] = $status;
}

$manufacturer_id = (int)($_GET['manufacturer_id'] ?? 0);
if ($manufacturer_id > 0) {
    $where[] = "pr.manufacturer_id = ?";
    $params[] = $manufacturer_id;
}

$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';
if ($from_date) { $where[] = "DATE(pr.created_at) >= ?"; $params[] = $from_date; }
if ($to_date)   { $where[] = "DATE(pr.created_at) <= ?"; $params[] = $to_date; }

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $where[] = "(pr.request_number LIKE ? OR m.name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm; $params[] = $searchTerm;
}

// ==================== FETCH REQUESTS ====================
$sql = "
    SELECT 
        pr.id, pr.request_number, pr.status, pr.total_estimated_amount, pr.created_at,
        m.name AS manufacturer_name,
        u.full_name AS requested_by_name,
        (SELECT COUNT(*) FROM purchase_request_items pri WHERE pri.purchase_request_id = pr.id) AS item_count
    FROM purchase_requests pr
    LEFT JOIN manufacturers m ON pr.manufacturer_id = m.id AND m.business_id = ?
    LEFT JOIN users u ON pr.requested_by = u.id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY pr.created_at DESC
";

// Add business_id for manufacturers join
array_unshift($params, $business_id);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================== FETCH MANUFACTURERS ====================
$manufacturers = $pdo->prepare("SELECT id, name FROM manufacturers WHERE business_id = ? AND is_active = 1 ORDER BY name");
$manufacturers->execute([$business_id]);
$manufacturers = $manufacturers->fetchAll();

// ==================== STATISTICS ====================
$stats_sql = "
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
        SUM(CASE WHEN status = 'quotation_received' THEN 1 ELSE 0 END) as quotation_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
        COALESCE(SUM(total_estimated_amount), 0) as total_estimated_amount
    FROM purchase_requests 
    WHERE business_id = ?
";

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([$business_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Messages
$message = $_SESSION['message'] ?? ''; unset($_SESSION['message']);
$error = $_SESSION['error'] ?? ''; unset($_SESSION['error']);
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Purchase Requests"; 
include 'includes/head.php'; 
?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu"><div data-simplebar class="h-100">
        <?php include 'includes/sidebar.php'; ?>
    </div></div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-file-find me-2"></i>
                                    Purchase Requests
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i> 
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">Manage and track all purchase requests</p>
                            </div>
                            <div class="d-flex gap-2">
                                <button onclick="exportRequests()" class="btn btn-outline-secondary">
                                    <i class="bx bx-download me-1"></i> Export
                                </button>
                                <a href="add_purchase_request.php" class="btn btn-primary">
                                    <i class="bx bx-plus-circle me-1"></i> New Request
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($message): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bx bx-info-circle me-2"></i><?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i>
                    <strong>Purchase Request Created!</strong>
                    <?= htmlspecialchars($_GET['pr'] ?? 'Request') ?> created successfully.
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
                                        <h6 class="text-muted mb-1">Total Requests</h6>
                                        <h3 class="mb-0 text-primary"><?= $stats['total_requests'] ?></h3>
                                        <small class="text-muted">₹<?= number_format($stats['total_estimated_amount']) ?> estimated</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-file text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Pending Approval</h6>
                                        <h3 class="mb-0 text-info"><?= $stats['draft_count'] + $stats['sent_count'] + $stats['quotation_count'] ?></h3>
                                        <small class="text-muted">
                                            <?= $stats['draft_count'] ?> draft, <?= $stats['sent_count'] ?> sent
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-time-five text-info"></i>
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
                                        <h6 class="text-muted mb-1">Approved</h6>
                                        <h3 class="mb-0 text-success"><?= $stats['approved_count'] ?></h3>
                                        <small class="text-muted">Ready for purchase</small>
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
                                        <h6 class="text-muted mb-1">Status Overview</h6>
                                        <div class="d-flex align-items-center">
                                            <?php if ($stats['total_requests'] > 0): 
                                                $approved_percent = ($stats['approved_count'] / $stats['total_requests']) * 100;
                                                $pending_percent = (($stats['draft_count'] + $stats['sent_count'] + $stats['quotation_count']) / $stats['total_requests']) * 100;
                                            ?>
                                            <div class="flex-grow-1 me-3">
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar bg-success" style="width: <?= $approved_percent ?>%"></div>
                                                    <div class="progress-bar bg-info" style="width: <?= $pending_percent ?>%"></div>
                                                </div>
                                            </div>
                                            <div>
                                                <small class="text-muted"><?= number_format($approved_percent, 0) ?>%</small>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-muted">No requests</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-pie-chart-alt text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Purchase Requests
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
                                               placeholder="Request No. or Supplier"
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="draft" <?= $status==='draft'?'selected':'' ?>>Draft</option>
                                        <option value="sent" <?= $status==='sent'?'selected':'' ?>>Sent</option>
                                        <option value="quotation_received" <?= $status==='quotation_received'?'selected':'' ?>>Quotation Received</option>
                                        <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
                                        <option value="rejected" <?= $status==='rejected'?'selected':'' ?>>Rejected</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Supplier</label>
                                    <select name="manufacturer_id" class="form-select">
                                        <option value="">All Suppliers</option>
                                        <?php foreach($manufacturers as $m): ?>
                                        <option value="<?= $m['id'] ?>" <?= $manufacturer_id==$m['id']?'selected':'' ?>>
                                            <?= htmlspecialchars($m['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
                                </div>
                                <div class="col-lg-1 col-md-12">
                                    <label class="form-label d-none d-lg-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply
                                        </button>
                                        <?php if ($search || $status || $manufacturer_id || $from_date || $to_date): ?>
                                        <a href="purchase_requests.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Requests Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="requestsTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Request Details</th>
                                        <th class="text-center">Supplier</th>
                                        <th class="text-center">Date</th>
                                        <th class="text-center">Items</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Estimated Amount</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($requests)): ?>
                                   
                                    <?php else: ?>
                                    <?php foreach ($requests as $i => $r): 
                                        $status_classes = [
                                            'draft' => 'secondary',
                                            'sent' => 'info',
                                            'quotation_received' => 'warning',
                                            'approved' => 'success',
                                            'rejected' => 'danger'
                                        ];
                                        $status_color = $status_classes[$r['status']] ?? 'dark';
                                        $status_icon = [
                                            'draft' => 'bx-edit',
                                            'sent' => 'bx-send',
                                            'quotation_received' => 'bx-receipt',
                                            'approved' => 'bx-check-circle',
                                            'rejected' => 'bx-x-circle'
                                        ][$r['status']] ?? 'bx-file';
                                        $status_text = ucfirst(str_replace('_', ' ', $r['status']));
                                    ?>
                                    <tr class="request-row" data-id="<?= $r['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx <?= $status_icon ?> fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($r['request_number']) ?></strong>
                                                    <small class="text-muted">
                                                        <i class="bx bx-user me-1"></i><?= htmlspecialchars($r['requested_by_name']) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                    <i class="bx bx-building me-1"></i><?= htmlspecialchars($r['manufacturer_name'] ?? '—') ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <strong class="d-block"><?= date('d M Y', strtotime($r['created_at'])) ?></strong>
                                                <small class="text-muted"><?= date('h:i A', strtotime($r['created_at'])) ?></small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 fs-6">
                                                    <i class="bx bx-package me-1"></i> <?= $r['item_count'] ?>
                                                </span>
                                                <small class="text-muted d-block">Items</small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> px-3 py-2">
                                                    <i class="bx <?= $status_icon ?> me-1"></i><?= $status_text ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-dark bg-opacity-10 text-dark px-3 py-2 fs-6">
                                                    <i class="bx bx-rupee me-1"></i> <?= number_format($r['total_estimated_amount']) ?>
                                                </span>
                                                <small class="text-muted d-block">Estimated</small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="purchase_request_view.php?id=<?= $r['id'] ?>" 
                                                   class="btn btn-outline-info"
                                                   data-bs-toggle="tooltip"
                                                   title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </a>
                                                <?php if (in_array($r['status'], ['draft', 'sent'])): ?>
                                                <a href="purchase_request_edit.php?id=<?= $r['id'] ?>" 
                                                   class="btn btn-outline-warning"
                                                   data-bs-toggle="tooltip"
                                                   title="Edit Request">
                                                    <i class="bx bx-edit"></i>
                                                </a>
                                                <?php endif; ?>
                                                <button type="button" 
                                                        onclick="printRequest(<?= $r['id'] ?>)"
                                                        class="btn btn-outline-dark"
                                                        data-bs-toggle="tooltip"
                                                        title="Print Request">
                                                    <i class="bx bx-printer"></i>
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
    $('#requestsTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search requests:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ requests",
            infoFiltered: "(filtered from <?= count($requests) ?> total requests)",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Auto-submit on filter change
    $('select[name="status"], select[name="manufacturer_id"], input[name="from_date"], input[name="to_date"]').on('change', function() {
        if ($(this).val() !== '') {
            $('#filterForm').submit();
        }
    });

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Row hover effect
    $('.request-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );
});

function printRequest(id) {
    window.open('purchase_request_print.php?id=' + id, 'PrintPR', 
                'width=900,height=700,scrollbars=yes,resizable=yes');
}

function exportRequests() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
    btn.disabled = true;
    
    // Build export URL with current search parameters
    const params = new URLSearchParams(window.location.search);
    const exportUrl = 'purchase_requests_export.php' + (params.toString() ? '?' + params.toString() : '');
    
    window.location = exportUrl;
    
    // Reset button after 3 seconds
    setTimeout(() => {
        btn.innerHTML = original;
        btn.disabled = false;
    }, 3000);
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
.request-row:hover .avatar-sm .rounded-circle {
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