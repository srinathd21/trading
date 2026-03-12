<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if (!isset($_SESSION['current_business_id'])) {
    header('Location: select_shop.php');
    exit();
}
$current_business_id = (int) $_SESSION['current_business_id'];
$user_role = $_SESSION['role'] ?? '';
$is_admin = in_array($user_role, ['admin', 'shop_manager']);
$success = $error = '';
$referral_persons = [];

// Handle bulk actions
if (isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
   
    if (empty($selected_ids)) {
        $error = "Please select at least one referral person.";
    } else {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
       
        switch ($action) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE referral_person SET is_active = 1 WHERE id IN ($placeholders) AND business_id = ?");
                $params = array_merge($selected_ids, [$current_business_id]);
                $stmt->execute($params);
                $success = count($selected_ids) . " referral person(s) activated.";
                break;
               
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE referral_person SET is_active = 0 WHERE id IN ($placeholders) AND business_id = ?");
                $params = array_merge($selected_ids, [$current_business_id]);
                $stmt->execute($params);
                $success = count($selected_ids) . " referral person(s) deactivated.";
                break;
               
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM referral_person WHERE id IN ($placeholders) AND business_id = ?");
                $params = array_merge($selected_ids, [$current_business_id]);
                $stmt->execute($params);
                $success = count($selected_ids) . " referral person(s) deleted.";
                break;
        }
    }
}

// Handle individual delete
if (isset($_GET['delete'])) {
    $referral_id = (int)$_GET['delete'];
   
    try {
        $stmt = $pdo->prepare("DELETE FROM referral_person WHERE id = ? AND business_id = ?");
        $stmt->execute([$referral_id, $current_business_id]);
        $success = "Referral person deleted successfully!";
    } catch (PDOException $e) {
        $error = "Error: Cannot delete - referral has transactions or dependency.";
    }
}

// Filters
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['search'] ?? '');
$where_conditions = ["rp.business_id = ?"];
$params = [$current_business_id];

if ($filter_status === 'active') {
    $where_conditions[] = "rp.is_active = 1";
} elseif ($filter_status === 'inactive') {
    $where_conditions[] = "rp.is_active = 0";
}

if ($filter_search) {
    $where_conditions[] = "(rp.full_name LIKE ? OR rp.phone LIKE ? OR rp.email LIKE ? OR rp.referral_code LIKE ?)";
    $like = "%$filter_search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch referral persons
$sql = "
    SELECT
        rp.*,
        COALESCE(SUM(i.referral_commission_amount), 0) as total_earnings
    FROM referral_person rp
    LEFT JOIN invoices i ON i.referral_id = rp.id AND i.business_id = rp.business_id
    $where_sql
    GROUP BY rp.id
    ORDER BY rp.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$referral_persons = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_sql = "
    SELECT
        COUNT(*) as total_referrals_persons,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
        SUM(debit_amount) as total_earned,
        SUM(paid_amount) as total_paid,
        SUM(balance_due) as total_due,
        SUM(total_referrals) as total_referral_sales,
        SUM(total_sales_amount) as total_sales_value,
        SUM(CASE 
            WHEN balance_due > 0 THEN balance_due 
            ELSE 0 
        END) as total_debit_outstanding,
        SUM(CASE 
            WHEN balance_due < 0 THEN ABS(balance_due) 
            ELSE 0 
        END) as total_credit_outstanding,
        SUM(CASE 
            WHEN initial_outstanding_type = 'debit' THEN initial_outstanding_amount 
            ELSE 0 
        END) as total_initial_debit,
        SUM(CASE 
            WHEN initial_outstanding_type = 'credit' THEN initial_outstanding_amount 
            ELSE 0 
        END) as total_initial_credit
    FROM referral_person
    WHERE business_id = ?
";
$stmt = $pdo->prepare($stats_sql);
$stmt->execute([$current_business_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Manage Referral Persons"; ?>
<?php include('includes/head.php'); ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
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
                                    <i class="bx bx-user-plus me-2"></i> Referral Persons
                                </h4>
                                <p class="text-muted mb-0">Manage your referral network and track commissions</p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="create_referral.php" class="btn btn-primary">
                                    <i class="bx bx-user-plus me-1"></i> Add Referral Person
                                </a>
                                <a href="referral_transactions.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-transfer me-1"></i> View Transactions
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Referrals</h6>
                                        <h3 class="mb-0 text-primary"><?= $stats['total_referrals_persons'] ?? 0 ?></h3>
                                        <small class="text-muted"><?= $stats['active_count'] ?? 0 ?> active</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-user-circle text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Total Sales via Referral</h6>
                                        <h3 class="mb-0 text-success"><?= $stats['total_referral_sales'] ?? 0 ?></h3>
                                        <small class="text-muted">Successful referrals</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-group text-success"></i>
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
                                        <h6 class="text-muted mb-1">Total Sales Amount</h6>
                                        <h3 class="mb-0 text-info">₹<?= number_format($stats['total_sales_value'] ?? 0, 0) ?></h3>
                                        <small class="text-muted">Generated via referrals</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-info"></i>
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
                                        <h6 class="text-muted mb-1">Net Balance</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($stats['total_due'] ?? 0, 0) ?></h3>
                                        <small class="text-muted d-block">
                                            <span class="text-danger">
                                                <i class="bx bx-down-arrow-alt"></i> Debit: ₹<?= number_format($stats['total_debit_outstanding'] ?? 0, 0) ?>
                                            </span>
                                            <span class="text-success ms-2">
                                                <i class="bx bx-up-arrow-alt"></i> Credit: ₹<?= number_format($stats['total_credit_outstanding'] ?? 0, 0) ?>
                                            </span>
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-wallet text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Initial Outstanding Summary -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Initial Debit Outstanding</h6>
                                        <h3 class="mb-0 text-danger">₹<?= number_format($stats['total_initial_debit'] ?? 0, 0) ?></h3>
                                        <small class="text-muted">
                                            <i class="bx bx-info-circle me-1"></i>
                                            Amount you owe to referral persons
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-down-arrow-alt text-danger"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Initial Credit Outstanding</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($stats['total_initial_credit'] ?? 0, 0) ?></h3>
                                        <small class="text-muted">
                                            <i class="bx bx-info-circle me-1"></i>
                                            Amount referral persons owe to you
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-up-arrow-alt text-success"></i>
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
                        <h5 class="card-title mb-4"><i class="bx bx-filter-alt me-1"></i> Filter Referral Persons</h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3">
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bx bx-search"></i></span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Name, Phone, Email, or Code"
                                               value="<?= htmlspecialchars($filter_search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active Only</option>
                                        <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                                    </select>
                                </div>
                                <div class="col-lg-4 col-md-12 d-flex align-items-end">
                                    <div class="d-flex gap-2 w-100">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-filter me-1"></i> Apply Filters
                                        </button>
                                        <?php if ($filter_search || $filter_status): ?>
                                        <a href="referrals.php" class="btn btn-outline-secondary"><i class="bx bx-reset"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Referral Persons Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show mb-4">
                            <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">
                                <i class="bx bx-list-ul me-1"></i> Referral Persons List
                                <span class="badge bg-primary fs-6 ms-2"><?= count($referral_persons) ?></span>
                            </h5>
                            <?php if ($is_admin): ?>
                            <form method="POST" class="d-flex gap-2" id="bulkActionForm">
                                <select name="bulk_action" class="form-select form-select-sm" style="width: auto;">
                                    <option value="">Bulk Actions</option>
                                    <option value="activate">Activate Selected</option>
                                    <option value="deactivate">Deactivate Selected</option>
                                    <option value="delete">Delete Selected</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bx bx-play-circle me-1"></i> Apply
                                </button>
                                <input type="hidden" name="selected_ids" value="">
                            </form>
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <?php if ($is_admin): ?>
                                        <th style="width: 50px;">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="selectAll">
                                            </div>
                                        </th>
                                        <?php endif; ?>
                                        <th>Referral Person</th>
                                        <th>Contact Info</th>
                                        <th class="text-center">Commission & Earnings</th>
                                        <th class="text-end">Balance & Outstanding</th>
                                        <th class="text-center">Performance</th>
                                        <th>Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($referral_persons)): ?>
                                    <tr>
                                        <td colspan="<?= $is_admin ? '8' : '7' ?>" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bx bx-user-plus display-1 text-muted d-block mb-3"></i>
                                                <h5>No Referral Persons Found</h5>
                                                <p class="text-muted mb-4">Add referral persons to start your referral program</p>
                                                <a href="create_referral.php" class="btn btn-primary">
                                                    <i class="bx bx-user-plus me-1"></i> Add First Referral Person
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($referral_persons as $person):
                                        $status_class = $person['is_active'] ? 'success' : 'danger';
                                        $status_icon = $person['is_active'] ? 'bx-check-circle' : 'bx-x-circle';
                                        
                                        // Determine balance class
                                        if ($person['balance_due'] > 0) {
                                            $balance_class = 'danger'; // You owe them (Debit)
                                            $balance_icon = 'bx-down-arrow-alt';
                                            $balance_type = 'Debit';
                                        } elseif ($person['balance_due'] < 0) {
                                            $balance_class = 'success'; // They owe you (Credit)
                                            $balance_icon = 'bx-up-arrow-alt';
                                            $balance_type = 'Credit';
                                        } else {
                                            $balance_class = 'secondary';
                                            $balance_icon = 'bx-check';
                                            $balance_type = 'Settled';
                                        }
                                        
                                        // Determine initial outstanding class
                                        $initial_outstanding_html = '';
                                        if ($person['initial_outstanding_type'] && $person['initial_outstanding_amount'] > 0) {
                                            if ($person['initial_outstanding_type'] === 'debit') {
                                                $initial_class = 'danger';
                                                $initial_icon = 'bx-down-arrow-alt';
                                                $initial_text = 'Initial Debit Outstanding';
                                            } else {
                                                $initial_class = 'success';
                                                $initial_icon = 'bx-up-arrow-alt';
                                                $initial_text = 'Initial Credit Outstanding';
                                            }
                                            $initial_outstanding_html = '
                                                <small class="text-' . $initial_class . ' d-block">
                                                    <i class="bx ' . $initial_icon . ' me-1"></i>
                                                    ' . $initial_text . ': ₹' . number_format($person['initial_outstanding_amount'], 0) . '
                                                </small>';
                                        }
                                        
                                        $avg_sale = $person['total_referrals'] > 0 ? $person['total_sales_amount'] / $person['total_referrals'] : 0;
                                    ?>
                                    <tr>
                                        <?php if ($is_admin): ?>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input select-checkbox" type="checkbox"
                                                       name="selected_ids[]" value="<?= $person['id'] ?>">
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="avatar-title bg-primary bg-opacity-10 text-primary rounded-circle">
                                                        <i class="bx bx-user fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($person['full_name']) ?></strong>
                                                    <small class="text-muted d-block">
                                                        <i class="bx bx-id-card me-1"></i> <?= htmlspecialchars($person['referral_code']) ?>
                                                    </small>
                                                    <small class="text-muted">
                                                        Joined: <?= date('M d, Y', strtotime($person['created_at'])) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <?php if ($person['phone']): ?>
                                                <div class="d-flex align-items-center mb-1">
                                                    <i class="bx bx-phone text-primary me-2"></i>
                                                    <small><?= htmlspecialchars($person['phone']) ?></small>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($person['email']): ?>
                                                <div class="d-flex align-items-center">
                                                    <i class="bx bx-envelope text-primary me-2"></i>
                                                    <small><?= htmlspecialchars($person['email']) ?></small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div>
                                                <strong>₹<?= number_format($person['debit_amount'], 0) ?></strong>
                                                <small class="text-muted d-block">Total Earned</small>
                                                <small class="text-info">
                                                    <i class="bx bx-percent me-1"></i>
                                                    <?= $person['commission_percent'] ?>% Commission
                                                </small>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div>
                                                <strong class="text-<?= $balance_class ?> fs-5">
                                                    <i class="bx <?= $balance_icon ?> me-1"></i>
                                                    ₹<?= number_format(abs($person['balance_due']), 0) ?>
                                                </strong>
                                                <small class="text-<?= $balance_class ?> d-block mb-1">
                                                    <?= $balance_type ?> Outstanding
                                                </small>
                                                <?= $initial_outstanding_html ?>
                                                <small class="text-muted d-block mt-1">
                                                    Earned: ₹<?= number_format($person['debit_amount'], 0) ?> | 
                                                    Paid: ₹<?= number_format($person['paid_amount'], 0) ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div>
                                                <span class="badge bg-info rounded-pill px-3 py-1 mb-1">
                                                    <?= $person['total_referrals'] ?> sales
                                                </span>
                                                <small class="text-muted d-block">
                                                    ₹<?= number_format($person['total_sales_amount'], 0) ?> total sales
                                                </small>
                                                <small class="text-muted">
                                                    Avg: ₹<?= number_format($avg_sale, 0) ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $status_class ?> bg-opacity-10 text-<?= $status_class ?> px-3 py-1">
                                                <i class="bx <?= $status_icon ?> me-1"></i>
                                                <?= $person['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="referral_view.php?id=<?= $person['id'] ?>"
                                                   class="btn btn-outline-info" title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </a>
                                                <?php if ($is_admin): ?>
                                                <a href="referral_edit.php?id=<?= $person['id'] ?>"
                                                   class="btn btn-outline-warning" title="Edit">
                                                    <i class="bx bx-edit"></i>
                                                </a>
                                                <a href="pay_referral.php?id=<?= $person['id'] ?>"
                                                   class="btn btn-outline-success" title="Make Payment">
                                                    <i class="bx bx-money"></i>
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
                    </div>
                </div>
            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
$(document).ready(function() {
    $('[data-bs-toggle="tooltip"]').tooltip();

    $('#selectAll').change(function() {
        $('.select-checkbox').prop('checked', $(this).prop('checked'));
    });

    $('#bulkActionForm').submit(function(e) {
        const selected = $('.select-checkbox:checked').map(function() {
            return this.value;
        }).get();

        if (selected.length === 0) {
            e.preventDefault();
            alert('Please select at least one referral person.');
            return;
        }

        if ($('select[name="bulk_action"]').val() === 'delete') {
            if (!confirm(`Delete ${selected.length} referral person(s)? This cannot be undone.`)) {
                e.preventDefault();
            }
        }

        $('input[name="selected_ids"]').val(selected.join(','));
    });

    // Auto-hide alerts
    setTimeout(() => $('.alert').fadeOut(), 5000);
    
    // Add data-tooltip for outstanding info
    $('.outstanding-tooltip').each(function() {
        $(this).tooltip({
            title: $(this).data('tooltip'),
            placement: 'top'
        });
    });
});
</script>

<style>
.card-hover { transition: all 0.2s ease; }
.card-hover:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.12) !important; }
.empty-state { padding: 4rem 0; }
.table-hover tbody tr:hover { background-color: #f8f9fa; }
.balance-positive { color: #28a745; }
.balance-negative { color: #dc3545; }
.balance-neutral { color: #6c757d; }
</style>
</body>
</html>