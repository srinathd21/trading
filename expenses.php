<?php
session_start();
require_once 'config/database.php';

// ==================== LOGIN & ROLE CHECK ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$business_id = $_SESSION['business_id'] ?? 1;
$user_id   = $_SESSION['user_id'];

// Allowed to view expenses
$can_view_expenses = in_array($user_role, ['admin', 'shop_manager', 'cashier']);
if (!$can_view_expenses) {
    $_SESSION['error'] = "Access denied. You don't have permission to view expenses.";
    header('Location: dashboard.php');
    exit();
}

// Allowed to edit & delete
$can_edit_delete = in_array($user_role, ['admin', 'shop_manager']);

// Shop selection logic
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
if ($user_role !== 'admin' && !$current_shop_id) {
    header('Location: select_shop.php');
    exit();
}

// ==================== HANDLE DELETE EXPENSE ====================
$message = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_expense') {
    if (!$can_edit_delete) {
        $error = "You don't have permission to delete expenses.";
    } else {
        $expense_id = (int)$_POST['expense_id'];

        // Verify expense exists and belongs to allowed shop
        $stmt = $pdo->prepare("SELECT id, shop_id FROM expenses WHERE id = ? AND business_id = ?");
        $stmt->execute([$expense_id, $business_id]);
        $exp = $stmt->fetch();

        if (!$exp) {
            $error = "Expense not found.";
        } elseif ($user_role !== 'admin' && $exp['shop_id'] != $current_shop_id) {
            $error = "You can only delete expenses from your own shop.";
        } else {
            try {
                $del = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
                $del->execute([$expense_id]);
                $message = "Expense deleted successfully!";
            } catch (Exception $e) {
                $error = "Failed to delete expense. Please try again.";
            }
        }
    }
}

// ==================== FILTERS ====================
$search          = trim($_GET['search'] ?? '');
$category        = $_GET['category'] ?? 'all';
$status          = $_GET['status'] ?? 'all';
$payment_method  = $_GET['payment_method'] ?? 'all';
$date_from       = $_GET['date_from'] ?? date('Y-m-01');
$date_to         = $_GET['date_to'] ?? date('Y-m-t');

$where = ["e.business_id = ?"];
$params = [$business_id];

if ($user_role !== 'admin' && $current_shop_id) {
    $where[] = "e.shop_id = ?";
    $params[] = $current_shop_id;
}

if ($search !== '') {
    $where[] = "(e.description LIKE ? OR e.reference LIKE ? OR e.payment_reference LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($category !== 'all') {
    $where[] = "e.category = ?";
    $params[] = $category;
}

if ($status !== 'all') {
    $where[] = "e.status = ?";
    $params[] = $status;
}

if ($payment_method !== 'all') {
    $where[] = "e.payment_method = ?";
    $params[] = $payment_method;
}

if ($date_from && $date_to) {
    $where[] = "e.date BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}

$where_clause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ==================== FETCH EXPENSES ====================
$sql = "
    SELECT e.*,
           s.shop_name,
           u.full_name as added_by_name,
           DATE_FORMAT(e.date, '%d/%m/%Y') as date_formatted,
           DATE_FORMAT(e.created_at, '%d/%m/%Y %h:%i %p') as created_formatted
    FROM expenses e
    LEFT JOIN shops s ON e.shop_id = s.id AND s.business_id = ?
    LEFT JOIN users u ON e.added_by = u.id
    $where_clause
    ORDER BY e.date DESC, e.created_at DESC
";
array_unshift($params, $business_id);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================== FETCH CATEGORIES FOR FILTER ====================
$cat_sql = "SELECT DISTINCT category FROM expenses WHERE business_id = ?";
if ($user_role !== 'admin' && $current_shop_id) {
    $cat_sql .= " AND shop_id = ?";
}
$cat_sql .= " ORDER BY category";
$cat_stmt = $pdo->prepare($cat_sql);
if ($user_role !== 'admin' && $current_shop_id) {
    $cat_stmt->execute([$business_id, $current_shop_id]);
} else {
    $cat_stmt->execute([$business_id]);
}
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

// ==================== STATISTICS ====================
$stats_sql = "SELECT 
    COUNT(*) as total_expenses,
    COALESCE(SUM(amount), 0) as total_amount,
    COALESCE(SUM(CASE WHEN DATE(e.date) = CURDATE() THEN amount ELSE 0 END), 0) as today_total,
    COALESCE(SUM(CASE WHEN e.date >= CURDATE() - INTERVAL 30 DAY THEN amount ELSE 0 END), 0) as last_30_days
    FROM expenses e WHERE e.business_id = ?";
if ($user_role !== 'admin' && $current_shop_id) {
    $stats_sql .= " AND e.shop_id = ?";
}
$stats_stmt = $pdo->prepare($stats_sql);
if ($user_role !== 'admin' && $current_shop_id) {
    $stats_stmt->execute([$business_id, $current_shop_id]);
} else {
    $stats_stmt->execute([$business_id]);
}
$stats = $stats_stmt->fetch();

// Shop name for header
$shop_name = '';
if ($current_shop_id) {
    $shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND business_id = ?");
    $shop_stmt->execute([$current_shop_id, $business_id]);
    $shop = $shop_stmt->fetch();
    $shop_name = $shop['shop_name'] ?? '';
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Expenses Management"; ?>
<?php include 'includes/head.php'; ?>
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
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-credit-card me-2"></i> Expenses Management
                                <small class="text-muted ms-2">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" onclick="exportExpenses()">
                                    <i class="bx bx-download me-1"></i> Export Excel
                                </button>
                                <a href="expense_add.php" class="btn btn-primary">
                                    <i class="bx bx-plus-circle me-1"></i> Add Expense
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-filter-alt me-1"></i> Filter Expenses
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Search Expenses</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Description, Reference..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select">
                                        <option value="all">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method" class="form-select">
                                        <option value="all">All Methods</option>
                                        <?php foreach (['cash','bank_transfer','credit_card','cheque','upi','other'] as $m): ?>
                                            <option value="<?= $m ?>" <?= $payment_method === $m ? 'selected' : '' ?>>
                                                <?= ucfirst(str_replace('_', ' ', $m)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                                </div>
                                <div class="col-lg-1 col-md-12">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply
                                        </button>
                                        <?php if ($search || $category !== 'all' || $payment_method !== 'all' || $date_from != date('Y-m-01') || $date_to != date('Y-m-t')): ?>
                                        <a href="expenses.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Expenses</h6>
                                        <h3 class="mb-0 text-primary"><?= number_format($stats['total_expenses']) ?></h3>
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
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Amount</h6>
                                        <h3 class="mb-0 text-danger">₹<?= number_format($stats['total_amount'], 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-danger"></i>
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
                                        <h6 class="text-muted mb-1">Last 30 Days</h6>
                                        <h3 class="mb-0 text-info">₹<?= number_format($stats['last_30_days'], 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-calendar text-info"></i>
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
                                        <h6 class="text-muted mb-1">Today's Expenses</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($stats['today_total'], 2) ?></h3>
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
                </div>

                <!-- Expenses Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="expensesTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Expense Details</th>
                                        <th class="text-center">Category & Status</th>
                                        <th class="text-center">Payment Details</th>
                                        <?php if ($user_role === 'admin'): ?>
                                        <th class="text-center">Shop</th>
                                        <?php endif; ?>
                                        <th class="text-center">Added By</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($expenses)): ?>
                                    
                                    <?php else: 
                                        $total = 0;
                                        foreach ($expenses as $e): 
                                        $total += $e['amount'];
                                    ?>
                                    <tr class="expense-row" data-id="<?= $e['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx bx-credit-card fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($e['description']) ?></strong>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-calendar me-1"></i><?= $e['date_formatted'] ?>
                                                        <?php if ($e['reference']): ?>
                                                            <span class="ms-2">
                                                                <i class="bx bx-hash me-1"></i><?= htmlspecialchars($e['reference']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </small>
                                                    <div class="mt-2">
                                                        <span class="text-danger fs-5"><strong>₹<?= number_format($e['amount'], 2) ?></strong></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-1">
                                                    <i class="bx bx-category me-1"></i><?= htmlspecialchars($e['category']) ?>
                                                </span>
                                            </div>
                                            <div>
                                                <?php 
                                                $status_badge = match($e['status']) {
                                                    'approved' => 'bg-success bg-opacity-10 text-success',
                                                    'pending' => 'bg-warning bg-opacity-10 text-warning',
                                                    'rejected' => 'bg-danger bg-opacity-10 text-danger',
                                                    'reconciled' => 'bg-primary bg-opacity-10 text-primary',
                                                    default => 'bg-secondary bg-opacity-10 text-secondary'
                                                };
                                                ?>
                                                <span class="badge <?= $status_badge ?> px-3 py-1">
                                                    <i class="bx bx-<?= $e['status'] == 'approved' ? 'check-circle' : ($e['status'] == 'pending' ? 'time-five' : ($e['status'] == 'rejected' ? 'x-circle' : 'check')) ?> me-1"></i>
                                                    <?= ucfirst($e['status']) ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1">
                                                    <i class="bx bx-credit-card-front me-1"></i>
                                                    <?= ucfirst(str_replace('_', ' ', $e['payment_method'])) ?>
                                                </span>
                                            </div>
                                            <?php if ($e['payment_reference']): ?>
                                            <small class="text-muted">
                                                <i class="bx bx-hash me-1"></i><?= htmlspecialchars($e['payment_reference']) ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($user_role === 'admin'): ?>
                                        <td class="text-center">
                                            <small class="text-muted">
                                                <i class="bx bx-store me-1"></i><?= htmlspecialchars($e['shop_name']) ?>
                                            </small>
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-center">
                                            <div class="d-flex flex-column align-items-center">
                                                <small class="text-muted mb-1">
                                                    <i class="bx bx-user me-1"></i><?= htmlspecialchars($e['added_by_name']) ?>
                                                </small>
                                                <small class="text-muted">
                                                    <i class="bx bx-time me-1"></i><?= $e['created_formatted'] ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-info" 
                                                        onclick="viewExpense(<?= $e['id'] ?>)"
                                                        data-bs-toggle="tooltip" title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </button>
                                                <?php if ($can_edit_delete): ?>
                                                    <a href="expense_edit.php?id=<?= $e['id'] ?>" 
                                                       class="btn btn-outline-warning"
                                                       data-bs-toggle="tooltip" title="Edit Expense">
                                                        <i class="bx bx-edit"></i>
                                                    </a>
                                                    <?php 
                                                    $can_delete_this = ($user_role === 'admin') || ($e['shop_id'] == $current_shop_id);
                                                    if ($can_delete_this): 
                                                    ?>
                                                    <button type="button" class="btn btn-outline-danger"
                                                            onclick="confirmDelete(<?= $e['id'] ?>, '<?= addslashes(htmlspecialchars($e['description'])) ?>', <?= $e['amount'] ?>)"
                                                            data-bs-toggle="tooltip" title="Delete Expense">
                                                        <i class="bx bx-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($e['attachment_path']): ?>
                                                    <a href="<?= $e['attachment_path'] ?>" target="_blank" 
                                                       class="btn btn-outline-success"
                                                       data-bs-toggle="tooltip" title="View Attachment">
                                                        <i class="bx bx-paperclip"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if (!empty($expenses)): ?>
                        <div class="mt-4 pt-3 border-top">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-muted">
                                    Showing <?= count($expenses) ?> expenses
                                </div>
                                <div class="text-end">
                                    <h5 class="text-danger mb-0">Total: ₹<?= number_format($total, 2) ?></h5>
                                    <small class="text-muted">Total expenses in selected period</small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="delete_expense">
                <input type="hidden" name="expense_id" id="deleteExpenseId">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-trash me-2 text-danger"></i>
                        Delete Expense
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <h6 class="alert-heading"><i class="bx bx-error-circle me-2"></i>Warning!</h6>
                        <p class="mb-0">Are you sure you want to permanently delete this expense?</p>
                    </div>
                    <div class="p-3 border rounded bg-light mb-3">
                        <strong id="deleteDesc" class="d-block mb-2"></strong>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Amount:</span>
                            <strong class="text-danger">₹<span id="deleteAmount"></span></strong>
                        </div>
                    </div>
                    <p class="text-danger fw-bold mb-0">
                        <i class="bx bx-info-circle me-2"></i>This action cannot be undone!
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bx bx-x me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bx bx-trash me-1"></i>Delete Permanently
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#expensesTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search in table:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ expenses",
            infoFiltered: "(filtered from <?= $stats['total_expenses'] ?> total expenses)",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Real-time search debounce
    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => $('#filterForm').submit(), 500);
    });

    // Auto-submit on filter change
    $('select[name="category"], select[name="payment_method"]').on('change', function() {
        $('#filterForm').submit();
    });

    // Row hover effect
    $('.expense-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // Export function
    window.exportExpenses = function() {
        const btn = event.target.closest('button');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
        btn.disabled = true;
        
        // Build export URL with current search parameters
        const params = new URLSearchParams(window.location.search);
        const exportUrl = 'expenses_export.php' + (params.toString() ? '?' + params.toString() : '');
        
        window.location = exportUrl;
        
        // Reset button after 3 seconds
        setTimeout(() => {
            btn.innerHTML = original;
            btn.disabled = false;
        }, 3000);
    };

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

function confirmDelete(id, desc, amount) {
    document.getElementById('deleteExpenseId').value = id;
    document.getElementById('deleteDesc').textContent = desc;
    document.getElementById('deleteAmount').textContent = parseFloat(amount).toFixed(2);
    new bootstrap.Modal(document.getElementById('deleteExpenseModal')).show();
}

function viewExpense(id) {
    fetch(`ajax_get_expense.php?id=${id}`)
        .then(r => r.text())
        .then(html => {
            document.getElementById('expenseDetails').innerHTML = html;
            new bootstrap.Modal(document.getElementById('viewExpenseModal')).show();
        });
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
.btn-group .btn {
    padding: 0.375rem 0.75rem;
    font-size: 14px;
}
.btn-group .btn:hover {
    transform: translateY(-1px);
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
.expense-row:hover .avatar-sm .bg-danger {
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

<!-- View Modal -->
<div class="modal fade" id="viewExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-credit-card me-2"></i>
                    Expense Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="expenseDetails"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bx bx-x me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

</body>
</html>