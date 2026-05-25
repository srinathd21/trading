<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once('config/database.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager', 'accountant'])) {
    header('Location: dashboard.php');
    exit();
}

$business_id = $_SESSION['current_business_id'] ?? $_SESSION['business_id'] ?? 1;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$_SESSION['business_id'] = $business_id;

$user_id = (int)($_SESSION['user_id'] ?? 0);

function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function computeInvoiceStatus($total, $paid): array {
    $pending = max(0, round((float)$total - (float)$paid, 2));
    if ($pending <= 0.01) {
        return ['paid', 0.00];
    }
    if ((float)$paid > 0) {
        return ['partial', $pending];
    }
    return ['pending', $pending];
}

function logRestoreActivity(PDO $pdo, int $business_id, int $user_id, string $action, string $description, array $details = []): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM activity_logs");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($columns)) {
            return;
        }

        $payload = [
            'business_id' => $business_id,
            'user_id' => $user_id,
            'action' => $action,
            'module' => 'customer_payment_restore',
            'description' => $description,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $user_id,
            'record_type' => 'customer_payment',
            'record_id' => $details['payment_id'] ?? null
        ];

        $insert = [];
        foreach ($payload as $column => $value) {
            if (in_array($column, $columns, true)) {
                $insert[$column] = $value;
            }
        }

        if (empty($insert)) {
            return;
        }

        $fields = '`' . implode('`, `', array_keys($insert)) . '`';
        $placeholders = implode(', ', array_fill(0, count($insert), '?'));
        $sql = "INSERT INTO activity_logs ($fields) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($insert));
    } catch (Exception $e) {
        // Do not block restore/delete operations for logging failure.
    }
}

function getCustomerOutstandingForUpdate(PDO $pdo, int $customer_id, int $business_id): array {
    $stmt = $pdo->prepare("SELECT outstanding_type, outstanding_amount FROM customers WHERE id = ? AND business_id = ? FOR UPDATE");
    $stmt->execute([$customer_id, $business_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: ['outstanding_type' => 'credit', 'outstanding_amount' => 0];
}

function setCustomerOutstanding(PDO $pdo, int $customer_id, int $business_id, string $type, float $amount): void {
    $stmt = $pdo->prepare("UPDATE customers SET outstanding_type = ?, outstanding_amount = ? WHERE id = ? AND business_id = ?");
    $stmt->execute([$type, round(max(0, $amount), 2), $customer_id, $business_id]);
}

function reapplyCustomerBalancePayment(PDO $pdo, int $customer_id, int $business_id, float $amount): array {
    $before = getCustomerOutstandingForUpdate($pdo, $customer_id, $business_id);
    $current_type = $before['outstanding_type'] ?? 'credit';
    $current_amount = (float)($before['outstanding_amount'] ?? 0);

    if ($current_type === 'debit') {
        $after_type = 'debit';
        $after_amount = $current_amount + $amount;
    } else {
        if ($current_amount > $amount) {
            $after_type = 'credit';
            $after_amount = $current_amount - $amount;
        } elseif ($current_amount < $amount) {
            $after_type = 'debit';
            $after_amount = $amount - $current_amount;
        } else {
            $after_type = 'credit';
            $after_amount = 0;
        }
    }

    setCustomerOutstanding($pdo, $customer_id, $business_id, $after_type, $after_amount);

    return [
        'before_type' => $current_type,
        'before_amount' => round($current_amount, 2),
        'after_type' => $after_type,
        'after_amount' => round($after_amount, 2)
    ];
}

function canRestorePayment(?string $deleted_at): bool {
    if (empty($deleted_at)) {
        return false;
    }
    $deleted_time = strtotime($deleted_at);
    if (!$deleted_time) {
        return false;
    }
    return $deleted_time >= strtotime('-7 days');
}

function restoreCustomerPayment(PDO $pdo, int $payment_id, int $business_id, int $user_id): array {
    $payment_stmt = $pdo->prepare("\n        SELECT cp.*, c.name AS customer_name\n        FROM customer_payments cp\n        LEFT JOIN customers c ON c.id = cp.customer_id AND c.business_id = cp.business_id\n        WHERE cp.id = ? AND cp.business_id = ?\n        FOR UPDATE\n    ");
    $payment_stmt->execute([$payment_id, $business_id]);
    $payment = $payment_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        throw new Exception('Payment record not found.');
    }
    if (empty($payment['is_deleted'])) {
        throw new Exception('This payment is not deleted.');
    }
    if (!canRestorePayment($payment['deleted_at'] ?? null)) {
        throw new Exception('Restore is allowed only within 7 days from deleted date. Use permanent delete if this record is no longer required.');
    }

    $alloc_stmt = $pdo->prepare("\n        SELECT *\n        FROM customer_payment_allocations\n        WHERE payment_id = ? AND business_id = ? AND is_deleted = 1\n        ORDER BY id ASC\n        FOR UPDATE\n    ");
    $alloc_stmt->execute([$payment_id, $business_id]);
    $allocations = $alloc_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allocations)) {
        throw new Exception('No deleted allocation rows found for this payment. Restore cannot be completed safely.');
    }

    $restore_details = [];

    foreach ($allocations as $allocation) {
        $allocation_type = $allocation['allocation_type'];
        $amount = round((float)$allocation['allocated_amount'], 2);

        if ($amount <= 0) {
            continue;
        }

        if ($allocation_type === 'invoice' && !empty($allocation['invoice_id'])) {
            $invoice_stmt = $pdo->prepare("\n                SELECT id, invoice_number, total, paid_amount, pending_amount, payment_status\n                FROM invoices\n                WHERE id = ? AND business_id = ? AND customer_id = ?\n                FOR UPDATE\n            ");
            $invoice_stmt->execute([(int)$allocation['invoice_id'], $business_id, (int)$payment['customer_id']]);
            $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                throw new Exception('Invoice not found for allocation: ' . htmlspecialchars((string)$allocation['invoice_number']));
            }

            $paid_before_restore = (float)$invoice['paid_amount'];
            $new_paid = round($paid_before_restore + $amount, 2);

            if ($new_paid > ((float)$invoice['total'] + 0.01)) {
                throw new Exception("Cannot restore payment. Invoice {$invoice['invoice_number']} would become overpaid.");
            }

            [$new_status, $new_pending] = computeInvoiceStatus($invoice['total'], $new_paid);
            $update_invoice = $pdo->prepare("\n                UPDATE invoices\n                SET paid_amount = ?, pending_amount = ?, payment_status = ?, updated_at = NOW()\n                WHERE id = ? AND business_id = ?\n            ");
            $update_invoice->execute([$new_paid, $new_pending, $new_status, $invoice['id'], $business_id]);

            $restore_details[] = [
                'type' => 'invoice',
                'invoice_id' => (int)$invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'amount' => $amount,
                'paid_before_restore' => round($paid_before_restore, 2),
                'paid_after_restore' => $new_paid,
                'pending_after_restore' => $new_pending,
                'status_after_restore' => $new_status
            ];
        } elseif ($allocation_type === 'manual_credit' || $allocation_type === 'advance') {
            $balance_audit = reapplyCustomerBalancePayment($pdo, (int)$payment['customer_id'], $business_id, $amount);
            $restore_details[] = [
                'type' => $allocation_type,
                'amount' => $amount,
                'customer_balance' => $balance_audit
            ];
        }
    }

    $restore_alloc = $pdo->prepare("\n        UPDATE customer_payment_allocations\n        SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, updated_at = NOW()\n        WHERE payment_id = ? AND business_id = ?\n    ");
    $restore_alloc->execute([$payment_id, $business_id]);

    $restore_payment = $pdo->prepare("\n        UPDATE customer_payments\n        SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL\n        WHERE id = ? AND business_id = ?\n    ");
    $restore_payment->execute([$payment_id, $business_id]);

    logRestoreActivity(
        $pdo,
        $business_id,
        $user_id,
        'customer_payment_restored',
        'Deleted customer payment was restored within the 7-day restore window.',
        [
            'payment_id' => $payment_id,
            'customer_id' => (int)$payment['customer_id'],
            'customer_name' => $payment['customer_name'] ?? null,
            'amount' => (float)$payment['total_amount'],
            'deleted_at' => $payment['deleted_at'] ?? null,
            'restore_details' => $restore_details
        ]
    );

    return $payment;
}

function permanentlyDeleteCustomerPayment(PDO $pdo, int $payment_id, int $business_id, int $user_id): array {
    $payment_stmt = $pdo->prepare("\n        SELECT cp.*, c.name AS customer_name\n        FROM customer_payments cp\n        LEFT JOIN customers c ON c.id = cp.customer_id AND c.business_id = cp.business_id\n        WHERE cp.id = ? AND cp.business_id = ?\n        FOR UPDATE\n    ");
    $payment_stmt->execute([$payment_id, $business_id]);
    $payment = $payment_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        throw new Exception('Payment record not found.');
    }
    if (empty($payment['is_deleted'])) {
        throw new Exception('Only soft deleted payments can be permanently deleted.');
    }

    $alloc_stmt = $pdo->prepare("SELECT * FROM customer_payment_allocations WHERE payment_id = ? AND business_id = ? ORDER BY id ASC");
    $alloc_stmt->execute([$payment_id, $business_id]);
    $allocations = $alloc_stmt->fetchAll(PDO::FETCH_ASSOC);

    $delete_alloc = $pdo->prepare("DELETE FROM customer_payment_allocations WHERE payment_id = ? AND business_id = ?");
    $delete_alloc->execute([$payment_id, $business_id]);

    $delete_payment = $pdo->prepare("DELETE FROM customer_payments WHERE id = ? AND business_id = ?");
    $delete_payment->execute([$payment_id, $business_id]);

    logRestoreActivity(
        $pdo,
        $business_id,
        $user_id,
        'customer_payment_permanently_deleted',
        'Soft deleted customer payment was permanently deleted from restore page.',
        [
            'payment_id' => $payment_id,
            'customer_id' => (int)$payment['customer_id'],
            'customer_name' => $payment['customer_name'] ?? null,
            'amount' => (float)$payment['total_amount'],
            'deleted_at' => $payment['deleted_at'] ?? null,
            'allocations' => $allocations
        ]
    );

    return $payment;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $payment_id = (int)($_POST['payment_id'] ?? 0);

    if ($payment_id <= 0) {
        $_SESSION['error'] = 'Invalid payment selected.';
        header('Location: customer_payment_restore.php');
        exit();
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'restore_payment') {
            $payment = restoreCustomerPayment($pdo, $payment_id, (int)$business_id, $user_id);
            $pdo->commit();
            $_SESSION['success'] = 'Payment restored successfully for ' . htmlspecialchars($payment['customer_name'] ?? 'customer') . '.';
        } elseif ($action === 'permanent_delete') {
            $payment = permanentlyDeleteCustomerPayment($pdo, $payment_id, (int)$business_id, $user_id);
            $pdo->commit();
            $_SESSION['success'] = 'Payment permanently deleted successfully.';
        } else {
            throw new Exception('Invalid action.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: customer_payment_restore.php');
    exit();
}

$search = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$status_filter = $_GET['status'] ?? '';

$where = "WHERE cp.business_id = ? AND COALESCE(cp.is_deleted, 0) = 1";
$params = [$business_id];

if ($search !== '') {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR cp.reference_no LIKE ? OR cp.payment_method LIKE ? OR cp.notes LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

if ($date_from !== '') {
    $where .= " AND DATE(cp.deleted_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where .= " AND DATE(cp.deleted_at) <= ?";
    $params[] = $date_to;
}

if ($status_filter === 'restorable') {
    $where .= " AND cp.deleted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($status_filter === 'expired') {
    $where .= " AND (cp.deleted_at IS NULL OR cp.deleted_at < DATE_SUB(NOW(), INTERVAL 7 DAY))";
}

$stats_stmt = $pdo->prepare("\n    SELECT\n        COUNT(*) AS deleted_count,\n        SUM(CASE WHEN cp.deleted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS restorable_count,\n        SUM(CASE WHEN cp.deleted_at IS NULL OR cp.deleted_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expired_count,\n        COALESCE(SUM(cp.total_amount), 0) AS deleted_amount\n    FROM customer_payments cp\n    WHERE cp.business_id = ? AND COALESCE(cp.is_deleted, 0) = 1\n");
$stats_stmt->execute([$business_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$sql = "\n    SELECT cp.*,\n           c.name AS customer_name,\n           c.phone AS customer_phone,\n           COALESCE(u.full_name, 'System') AS deleted_by_name,\n           CASE\n               WHEN cp.deleted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1\n               ELSE 0\n           END AS can_restore,\n           DATEDIFF(NOW(), cp.deleted_at) AS days_deleted,\n           (\n               SELECT GROUP_CONCAT(\n                    CONCAT(\n                        CASE\n                            WHEN cpa.allocation_type = 'invoice' THEN COALESCE(cpa.invoice_number, CONCAT('Invoice #', cpa.invoice_id))\n                            WHEN cpa.allocation_type = 'manual_credit' THEN 'Manual Outstanding'\n                            WHEN cpa.allocation_type = 'advance' THEN 'Advance'\n                            ELSE cpa.allocation_type\n                        END,\n                        ': Rs.', FORMAT(cpa.allocated_amount, 2)\n                    )\n                    ORDER BY cpa.id ASC SEPARATOR '<br>'\n               )\n               FROM customer_payment_allocations cpa\n               WHERE cpa.payment_id = cp.id\n                 AND cpa.business_id = cp.business_id\n           ) AS allocation_details\n    FROM customer_payments cp\n    LEFT JOIN customers c ON c.id = cp.customer_id AND c.business_id = cp.business_id\n    LEFT JOIN users u ON u.id = cp.deleted_by\n    $where\n    ORDER BY cp.deleted_at DESC, cp.id DESC\n    LIMIT 200\n";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deleted_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<?php $page_title = 'Restore Deleted Payments'; ?>
<?php include('includes/head.php'); ?>
<head>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .empty-state i { font-size: 4rem; opacity: 0.5; }
        .avatar-sm { width: 48px; height: 48px; }
        .table th { font-weight: 600; background-color: #f8f9fa; }
        .card-hover { transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .card-hover:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important; }
        .border-start { border-left-width: 4px !important; }
        .restore-window { border-left: 4px solid #198754; background: #f6fff9; }
        .restore-expired { border-left: 4px solid #dc3545; background: #fff7f7; }
        @media (max-width: 768px) {
            .btn-group { flex-wrap: wrap; gap: 3px; }
            .btn-group .btn { flex: 1; min-width: 40px; padding: 0.375rem 0.5rem; }
        }
    </style>
</head>
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
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-undo me-2"></i> Restore Deleted Payments
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? $_SESSION['business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="mb-0 text-muted">Restore soft deleted customer payments within 7 days or permanently delete old records.</p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="customers.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Customers
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bx bx-check-circle me-2"></i> <?= $_SESSION['success'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bx bx-error-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Deleted Payments</h6>
                                        <h3 class="mb-0 text-danger"><?= (int)($stats['deleted_count'] ?? 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-trash text-danger"></i>
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
                                        <h6 class="text-muted mb-1">Restorable</h6>
                                        <h3 class="mb-0 text-success"><?= (int)($stats['restorable_count'] ?? 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-undo text-success"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted">Deleted within 7 days</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Restore Expired</h6>
                                        <h3 class="mb-0 text-warning"><?= (int)($stats['expired_count'] ?? 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-time-five text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted">Only permanent delete allowed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Deleted Amount</h6>
                                        <h3 class="mb-0 text-info">₹<?= number_format((float)($stats['deleted_amount'] ?? 0), 2) ?></h3>
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
                </div>

                <div class="alert alert-info shadow-sm">
                    <i class="bx bx-info-circle me-2"></i>
                    Restore is allowed only for payments deleted within the last <strong>7 days</strong>. Permanent delete removes the soft-deleted payment and its allocation rows permanently.
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3"><i class="bx bx-filter me-1"></i> Search & Filter</h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-4">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bx bx-search"></i></span>
                                        <input type="text" name="search" class="form-control" placeholder="Customer, phone, method, reference, notes" value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2">
                                    <label class="form-label">Deleted From</label>
                                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                                </div>
                                <div class="col-lg-2">
                                    <label class="form-label">Deleted To</label>
                                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                                </div>
                                <div class="col-lg-2">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="" <?= $status_filter === '' ? 'selected' : '' ?>>All Deleted</option>
                                        <option value="restorable" <?= $status_filter === 'restorable' ? 'selected' : '' ?>>Restorable</option>
                                        <option value="expired" <?= $status_filter === 'expired' ? 'selected' : '' ?>>Expired</option>
                                    </select>
                                </div>
                                <div class="col-lg-2">
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1"><i class="bx bx-search me-1"></i> Search</button>
                                        <?php if ($search || $date_from || $date_to || $status_filter): ?>
                                            <a href="customer_payment_restore.php" class="btn btn-outline-secondary"><i class="bx bx-reset"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="restoreTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Customer & Payment</th>
                                        <th class="text-center">Amount</th>
                                        <th class="text-center">Method</th>
                                        <th>Allocation Details</th>
                                        <th class="text-center">Deleted Info</th>
                                        <th class="text-center">Restore Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($deleted_payments)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bx bx-check-shield text-muted"></i>
                                                <h5 class="mt-3">No Deleted Payments Found</h5>
                                                <p class="text-muted mb-0">Deleted customer payments will appear here.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deleted_payments as $payment):
                                        $can_restore = (int)$payment['can_restore'] === 1;
                                        $days_deleted = isset($payment['days_deleted']) ? (int)$payment['days_deleted'] : null;
                                        $row_class = $can_restore ? 'restore-window' : 'restore-expired';
                                    ?>
                                        <tr class="<?= $row_class ?>">
                                            <td>
                                                <strong class="d-block"><?= htmlspecialchars($payment['customer_name'] ?? 'Unknown Customer') ?></strong>
                                                <?php if (!empty($payment['customer_phone'])): ?>
                                                    <small class="text-muted"><i class="bx bx-phone me-1"></i><?= htmlspecialchars($payment['customer_phone']) ?></small><br>
                                                <?php endif; ?>
                                                <small class="text-muted">
                                                    Payment #<?= (int)$payment['id'] ?> | Paid: <?= !empty($payment['payment_date']) ? date('d M Y', strtotime($payment['payment_date'])) : '—' ?>
                                                </small>
                                                <?php if (!empty($payment['payment_type'])): ?>
                                                    <br><span class="badge bg-secondary bg-opacity-10 text-secondary mt-1"><?= ucfirst(htmlspecialchars($payment['payment_type'])) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <strong class="text-success fs-5">₹<?= number_format((float)$payment['total_amount'], 2) ?></strong>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-2"><?= ucfirst(htmlspecialchars($payment['payment_method'] ?? 'cash')) ?></span>
                                                <br><small class="text-muted"><?= htmlspecialchars($payment['reference_no'] ?: 'No reference') ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($payment['allocation_details'])): ?>
                                                    <div class="small"><?= $payment['allocation_details'] ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted">No allocation details</span>
                                                <?php endif; ?>
                                                <?php if (!empty($payment['notes'])): ?>
                                                    <div class="text-muted small mt-1"><i class="bx bx-note me-1"></i><?= htmlspecialchars($payment['notes']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <strong><?= !empty($payment['deleted_at']) ? date('d M Y h:i A', strtotime($payment['deleted_at'])) : '—' ?></strong>
                                                <br><small class="text-muted">By: <?= htmlspecialchars($payment['deleted_by_name'] ?? 'System') ?></small>
                                                <?php if (!empty($payment['delete_reason'])): ?>
                                                    <br><small class="text-muted">Reason: <?= htmlspecialchars($payment['delete_reason']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($can_restore): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success px-3 py-2">
                                                        <i class="bx bx-check-circle me-1"></i> Restorable
                                                    </span>
                                                    <br><small class="text-muted"><?= 7 - max(0, $days_deleted ?? 0) ?> day(s) left</small>
                                                <?php else: ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2">
                                                        <i class="bx bx-lock me-1"></i> Restore Expired
                                                    </span>
                                                    <br><small class="text-muted">Older than 7 days</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <?php if ($can_restore): ?>
                                                        <form method="POST" class="restore-payment-form d-inline">
                                                            <input type="hidden" name="action" value="restore_payment">
                                                            <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-success"
                                                                    data-customer="<?= htmlspecialchars($payment['customer_name'] ?? 'Customer', ENT_QUOTES) ?>"
                                                                    data-amount="<?= number_format((float)$payment['total_amount'], 2, '.', '') ?>">
                                                                <i class="bx bx-undo"></i> Restore
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-outline-secondary" disabled title="Restore allowed only within 7 days">
                                                            <i class="bx bx-lock"></i> Restore
                                                        </button>
                                                    <?php endif; ?>

                                                    <form method="POST" class="permanent-delete-form d-inline">
                                                        <input type="hidden" name="action" value="permanent_delete">
                                                        <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger"
                                                                data-customer="<?= htmlspecialchars($payment['customer_name'] ?? 'Customer', ENT_QUOTES) ?>"
                                                                data-amount="<?= number_format((float)$payment['total_amount'], 2, '.', '') ?>">
                                                            <i class="bx bx-trash"></i> Permanent Delete
                                                        </button>
                                                    </form>
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
    if ($('#restoreTable tbody tr').length > 0 && !$('#restoreTable tbody .empty-state').length) {
        $('#restoreTable').DataTable({
            responsive: true,
            pageLength: 25,
            ordering: false,
            language: {
                search: 'Search in table:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ deleted payments',
                paginate: {
                    previous: "<i class='bx bx-chevron-left'></i>",
                    next: "<i class='bx bx-chevron-right'></i>"
                }
            }
        });
    }

    $('.restore-payment-form').on('submit', function(e) {
        e.preventDefault();
        const form = this;
        const btn = $(form).find('button[type="submit"]');
        const customer = btn.data('customer') || 'customer';
        const amount = btn.data('amount') || '0.00';

        Swal.fire({
            title: 'Restore payment?',
            html: `Restore deleted payment of <strong>₹${amount}</strong> for <strong>${customer}</strong>?<br><br><small>This will reapply invoice/customer balance changes.</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, restore',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });

    $('.permanent-delete-form').on('submit', function(e) {
        e.preventDefault();
        const form = this;
        const btn = $(form).find('button[type="submit"]');
        const customer = btn.data('customer') || 'customer';
        const amount = btn.data('amount') || '0.00';

        Swal.fire({
            title: 'Permanent delete?',
            html: `This will permanently delete the soft-deleted payment of <strong>₹${amount}</strong> for <strong>${customer}</strong>.<br><br><strong class="text-danger">This action cannot be undone.</strong>`,
            icon: 'warning',
            input: 'text',
            inputLabel: 'Type DELETE to confirm',
            inputPlaceholder: 'DELETE',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Permanent Delete',
            cancelButtonText: 'Cancel',
            preConfirm: (value) => {
                if ((value || '').trim().toUpperCase() !== 'DELETE') {
                    Swal.showValidationMessage('Please type DELETE to confirm permanent deletion.');
                    return false;
                }
                return true;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });

    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => $('#filterForm').submit(), 500);
    });

    setTimeout(function() {
        $('.alert-dismissible').alert('close');
    }, 5000);
});
</script>
</body>
</html>
