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

$purchase_id = (int)($_GET['id'] ?? 0);
if (!$purchase_id) {
    header('Location: purchases.php');
    exit();
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Exception $e) {
        return false;
    }
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
    if (tableHasColumn($pdo, $table, $column)) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `$column` $definition");
    } catch (Exception $e) {
        try {
            if (!tableHasColumn($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (Exception $inner) {
            error_log("Unable to add {$table}.{$column}: " . $inner->getMessage());
        }
    }
}

function ensureSupplierPaymentSchema(PDO $pdo): void {
    if (!tableExists($pdo, 'supplier_payment_allocations')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_payment_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT UNSIGNED NOT NULL,
            business_id INT DEFAULT NULL,
            manufacturer_id INT NOT NULL,
            purchase_id INT DEFAULT NULL,
            purchase_number VARCHAR(100) DEFAULT NULL,
            allocation_type ENUM('outstanding','purchase_order') NOT NULL,
            allocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            outstanding_before DECIMAL(12,2) DEFAULT NULL,
            outstanding_after DECIMAL(12,2) DEFAULT NULL,
            outstanding_type_before VARCHAR(20) DEFAULT NULL,
            outstanding_type_after VARCHAR(20) DEFAULT NULL,
            po_paid_before DECIMAL(12,2) DEFAULT NULL,
            po_paid_after DECIMAL(12,2) DEFAULT NULL,
            po_status_before VARCHAR(30) DEFAULT NULL,
            po_status_after VARCHAR(30) DEFAULT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by INT DEFAULT NULL,
            delete_reason TEXT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_payment_id (payment_id),
            KEY idx_manufacturer (manufacturer_id),
            KEY idx_purchase (purchase_id),
            KEY idx_deleted (is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Required columns for using payments as the main supplier payment table.
    $paymentColumns = [
        'business_id' => 'INT DEFAULT NULL',
        'manufacturer_id' => 'INT DEFAULT NULL',
        'payment_type' => "VARCHAR(30) DEFAULT 'single'",
        'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'deleted_at' => 'DATETIME DEFAULT NULL',
        'deleted_by' => 'INT DEFAULT NULL',
        'delete_reason' => 'TEXT DEFAULT NULL'
    ];

    foreach ($paymentColumns as $column => $definition) {
        addColumnIfMissing($pdo, 'payments', $column, $definition);
    }

    // If the allocation table already existed from an older version, add missing columns.
    $allocationColumns = [
        'payment_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'business_id' => 'INT DEFAULT NULL',
        'manufacturer_id' => 'INT NOT NULL DEFAULT 0',
        'purchase_id' => 'INT DEFAULT NULL',
        'purchase_number' => 'VARCHAR(100) DEFAULT NULL',
        'allocation_type' => "VARCHAR(30) NOT NULL DEFAULT 'purchase_order'",
        'allocated_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'outstanding_before' => 'DECIMAL(12,2) DEFAULT NULL',
        'outstanding_after' => 'DECIMAL(12,2) DEFAULT NULL',
        'outstanding_type_before' => 'VARCHAR(20) DEFAULT NULL',
        'outstanding_type_after' => 'VARCHAR(20) DEFAULT NULL',
        'po_paid_before' => 'DECIMAL(12,2) DEFAULT NULL',
        'po_paid_after' => 'DECIMAL(12,2) DEFAULT NULL',
        'po_status_before' => 'VARCHAR(30) DEFAULT NULL',
        'po_status_after' => 'VARCHAR(30) DEFAULT NULL',
        'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'deleted_at' => 'DATETIME DEFAULT NULL',
        'deleted_by' => 'INT DEFAULT NULL',
        'delete_reason' => 'TEXT DEFAULT NULL',
        'created_by' => 'INT DEFAULT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'
    ];

    foreach ($allocationColumns as $column => $definition) {
        addColumnIfMissing($pdo, 'supplier_payment_allocations', $column, $definition);
    }
}

function getTableColumns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $cache[$table];
    } catch (Exception $e) {
        $cache[$table] = [];
        return [];
    }
}

function insertRowDynamic(PDO $pdo, string $table, array $data): int {
    $availableColumns = getTableColumns($pdo, $table);
    if (empty($availableColumns)) {
        throw new Exception("Unable to read columns for `$table`.");
    }

    $insert = [];
    foreach ($data as $column => $value) {
        if (in_array($column, $availableColumns, true)) {
            $insert[$column] = $value;
        }
    }

    if (in_array('created_at', $availableColumns, true) && !array_key_exists('created_at', $insert)) {
        $insert['created_at'] = date('Y-m-d H:i:s');
    }

    if (empty($insert)) {
        throw new Exception("No matching columns found for insert into `$table`.");
    }

    $fieldSql = '`' . implode('`, `', array_keys($insert)) . '`';
    $placeholderSql = implode(', ', array_fill(0, count($insert), '?'));

    // This guarantees same count of columns and values and fixes SQLSTATE[21S01].
    $stmt = $pdo->prepare("INSERT INTO `$table` ($fieldSql) VALUES ($placeholderSql)");
    $stmt->execute(array_values($insert));
    return (int)$pdo->lastInsertId();
}

function normalizePaymentMethod(string $method): string {
    $allowed = ['cash', 'bank', 'upi', 'cheque', 'other'];
    return in_array($method, $allowed, true) ? $method : 'other';
}

function computePurchaseStatus(float $total, float $paid): string {
    if ($paid >= $total - 0.01) {
        return 'paid';
    }
    return $paid > 0.01 ? 'partial' : 'pending';
}

function insertPayment(PDO $pdo, array $data): int {
    return insertRowDynamic($pdo, 'payments', $data);
}

function insertSupplierAllocation(PDO $pdo, array $data): int {
    return insertRowDynamic($pdo, 'supplier_payment_allocations', $data);
}

function logSupplierPaymentActivity(PDO $pdo, int $business_id, int $user_id, string $action, array $details = []): void {
    try {
        if (!tableExists($pdo, 'activity_logs')) {
            return;
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM activity_logs");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $payload = [
            'user_id' => $user_id,
            'business_id' => $business_id,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s')
        ];

        $insert = [];
        foreach ($payload as $column => $value) {
            if (in_array($column, $columns, true)) {
                $insert[$column] = $value;
            }
        }

        if (!$insert) {
            return;
        }

        $sql = "INSERT INTO activity_logs (`" . implode('`, `', array_keys($insert)) . "`) VALUES (" . implode(', ', array_fill(0, count($insert), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($insert));
    } catch (Exception $e) {
        // Do not block payment operations for logging failures.
    }
}

function softDeleteSupplierPayment(PDO $pdo, int $payment_id, int $business_id, int $user_id, string $reason): void {
    $paymentStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND COALESCE(is_deleted, 0) = 0 FOR UPDATE");
    $paymentStmt->execute([$payment_id]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        throw new Exception('Payment not found or already deleted.');
    }

    $allocStmt = $pdo->prepare("SELECT * FROM supplier_payment_allocations WHERE payment_id = ? AND COALESCE(is_deleted, 0) = 0 ORDER BY id DESC FOR UPDATE");
    $allocStmt->execute([$payment_id]);
    $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
        Fallback for old supplier payments:
        payments.reference_id is purchases.id.
        If allocation rows are missing, reverse using the purchase from reference_id.

        Requested delete effect:
        - add payment amount back to purchases.total_amount
        - reduce purchases.paid_amount by payment amount
    */
    if (!$allocations && ($payment['type'] ?? '') === 'supplier' && !empty($payment['reference_id'])) {
        $purchase_id_from_payment = (int)$payment['reference_id'];
        $payment_amount = round((float)($payment['amount'] ?? 0), 2);

        if ($payment_amount <= 0) {
            throw new Exception('Invalid payment amount for delete.');
        }

        $poStmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ? FOR UPDATE");
        $poStmt->execute([$purchase_id_from_payment]);
        $po = $poStmt->fetch(PDO::FETCH_ASSOC);

        if (!$po) {
            throw new Exception('Purchase not found for payment reference_id: ' . $purchase_id_from_payment);
        }

        $oldTotal = round((float)$po['total_amount'], 2);
        $oldPaid = round((float)$po['paid_amount'], 2);

        $newTotal = round($oldTotal + $payment_amount, 2);
        $newPaid = max(0, round($oldPaid - $payment_amount, 2));
        $newStatus = computePurchaseStatus($newTotal, $newPaid);

        $stmt = $pdo->prepare("
            UPDATE purchases
            SET total_amount = ?,
                paid_amount = ?,
                payment_status = ?
            WHERE id = ?
        ");
        $stmt->execute([$newTotal, $newPaid, $newStatus, $purchase_id_from_payment]);

        try {
            insertSupplierAllocation($pdo, [
                'payment_id' => $payment_id,
                'business_id' => $business_id ?: ($po['business_id'] ?? null),
                'manufacturer_id' => (int)($po['manufacturer_id'] ?? ($payment['manufacturer_id'] ?? 0)),
                'purchase_id' => $purchase_id_from_payment,
                'purchase_number' => $po['purchase_number'] ?? null,
                'allocation_type' => 'purchase_order',
                'allocated_amount' => $payment_amount,
                'po_paid_before' => $oldPaid,
                'po_paid_after' => $newPaid,
                'po_status_before' => $po['payment_status'] ?? computePurchaseStatus($oldTotal, $oldPaid),
                'po_status_after' => $newStatus,
                'is_deleted' => 1,
                'deleted_at' => date('Y-m-d H:i:s'),
                'deleted_by' => $user_id,
                'delete_reason' => $reason,
                'created_by' => $user_id,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log('Unable to create fallback deleted allocation row: ' . $e->getMessage());
        }

        $stmt = $pdo->prepare("UPDATE payments SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, delete_reason = ? WHERE id = ?");
        $stmt->execute([$user_id, $reason, $payment_id]);

        return;
    }

    foreach ($allocations as $allocation) {
        if ($allocation['allocation_type'] === 'purchase_order' && !empty($allocation['purchase_id'])) {
            $paidBefore = (float)($allocation['po_paid_before'] ?? 0);
            $statusBefore = $allocation['po_status_before'] ?: 'pending';
            $stmt = $pdo->prepare("UPDATE purchases SET paid_amount = ?, payment_status = ? WHERE id = ?");
            $stmt->execute([$paidBefore, $statusBefore, (int)$allocation['purchase_id']]);
        }

        if ($allocation['allocation_type'] === 'outstanding') {
            $beforeAmount = (float)($allocation['outstanding_before'] ?? 0);
            $beforeType = $allocation['outstanding_type_before'] ?: 'none';
            $stmt = $pdo->prepare("UPDATE manufacturers SET initial_outstanding_amount = ?, initial_outstanding_type = ? WHERE id = ?");
            $stmt->execute([$beforeAmount, $beforeType, (int)$allocation['manufacturer_id']]);
        }
    }

    $stmt = $pdo->prepare("UPDATE supplier_payment_allocations SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, delete_reason = ?, updated_at = NOW() WHERE payment_id = ?");
    $stmt->execute([$user_id, $reason, $payment_id]);

    $stmt = $pdo->prepare("UPDATE payments SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, delete_reason = ? WHERE id = ?");
    $stmt->execute([$user_id, $reason, $payment_id]);
}

function restoreSupplierPayment(PDO $pdo, int $payment_id, int $business_id, int $user_id): void {
    $paymentStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND COALESCE(is_deleted, 0) = 1 FOR UPDATE");
    $paymentStmt->execute([$payment_id]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        throw new Exception('Deleted payment not found or already active.');
    }

    $allocStmt = $pdo->prepare("SELECT * FROM supplier_payment_allocations WHERE payment_id = ? AND COALESCE(is_deleted, 0) = 1 ORDER BY id ASC FOR UPDATE");
    $allocStmt->execute([$payment_id]);
    $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$allocations) {
        throw new Exception('No allocation rows found for restore.');
    }

    foreach ($allocations as $allocation) {
        if ($allocation['allocation_type'] === 'outstanding') {
            $afterAmount = (float)($allocation['outstanding_after'] ?? 0);
            $afterType = $allocation['outstanding_type_after'] ?: 'none';
            $stmt = $pdo->prepare("UPDATE manufacturers SET initial_outstanding_amount = ?, initial_outstanding_type = ? WHERE id = ?");
            $stmt->execute([$afterAmount, $afterType, (int)$allocation['manufacturer_id']]);
        }

        if ($allocation['allocation_type'] === 'purchase_order' && !empty($allocation['purchase_id'])) {
            $paidAfter = (float)($allocation['po_paid_after'] ?? 0);
            $statusAfter = $allocation['po_status_after'] ?: 'partial';
            $stmt = $pdo->prepare("UPDATE purchases SET paid_amount = ?, payment_status = ? WHERE id = ?");
            $stmt->execute([$paidAfter, $statusAfter, (int)$allocation['purchase_id']]);
        }
    }

    $stmt = $pdo->prepare("UPDATE supplier_payment_allocations SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, updated_at = NOW() WHERE payment_id = ?");
    $stmt->execute([$payment_id]);

    $stmt = $pdo->prepare("UPDATE payments SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = ?");
    $stmt->execute([$payment_id]);
}

ensureSupplierPaymentSchema($pdo);
$business_id = (int)($_SESSION['business_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT p.*, 
           m.name AS manufacturer_name,
           m.initial_outstanding_amount,
           m.initial_outstanding_type,
           GREATEST(p.total_amount - p.paid_amount, 0) AS balance_due
    FROM purchases p
    JOIN manufacturers m ON p.manufacturer_id = m.id
    WHERE p.id = ?
");
$stmt->execute([$purchase_id]);
$purchase = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$purchase) {
    header('Location: purchases.php');
    exit();
}

$manufacturer_id = (int)$purchase['manufacturer_id'];
if ($business_id <= 0 && isset($purchase['business_id'])) {
    $business_id = (int)$purchase['business_id'];
}

$manufacturer_stmt = $pdo->prepare("SELECT * FROM manufacturers WHERE id = ? FOR UPDATE");
$manufacturer_stmt->execute([$manufacturer_id]);
$manufacturer = $manufacturer_stmt->fetch(PDO::FETCH_ASSOC);

if (!$manufacturer) {
    header('Location: purchases.php');
    exit();
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_payment') {
    $delete_payment_id = (int)($_POST['payment_id'] ?? 0);
    $delete_reason = trim($_POST['delete_reason'] ?? 'Soft deleted from supplier payment page');

    try {
        $pdo->beginTransaction();
        softDeleteSupplierPayment($pdo, $delete_payment_id, $business_id, $user_id, $delete_reason);
        logSupplierPaymentActivity($pdo, $business_id, $user_id, 'supplier_payment_deleted', [
            'payment_id' => $delete_payment_id,
            'purchase_id' => $purchase_id,
            'manufacturer_id' => $manufacturer_id,
            'reason' => $delete_reason
        ]);
        $pdo->commit();
        $_SESSION['success'] = 'Supplier payment deleted and values restored successfully.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Delete failed: ' . $e->getMessage();
    }

    header('Location: purchase_payment.php?id=' . $purchase_id);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_payment') {
    $restore_payment_id = (int)($_POST['payment_id'] ?? 0);

    try {
        $pdo->beginTransaction();
        restoreSupplierPayment($pdo, $restore_payment_id, $business_id, $user_id);
        logSupplierPaymentActivity($pdo, $business_id, $user_id, 'supplier_payment_restored', [
            'payment_id' => $restore_payment_id,
            'purchase_id' => $purchase_id,
            'manufacturer_id' => $manufacturer_id
        ]);
        $pdo->commit();
        $_SESSION['success'] = 'Supplier payment restored successfully.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Restore failed: ' . $e->getMessage();
    }

    header('Location: purchase_payment.php?id=' . $purchase_id);
    exit();
}

// Re-fetch values after possible POST redirect-safe operations are handled.
$manufacturer_stmt = $pdo->prepare("SELECT * FROM manufacturers WHERE id = ?");
$manufacturer_stmt->execute([$manufacturer_id]);
$manufacturer = $manufacturer_stmt->fetch(PDO::FETCH_ASSOC);

$pending_stmt = $pdo->prepare("
    SELECT id, purchase_number, total_amount, paid_amount,
           GREATEST(total_amount - paid_amount, 0) AS due_amount,
           purchase_date,
           payment_status
    FROM purchases 
    WHERE manufacturer_id = ?
      AND GREATEST(total_amount - paid_amount, 0) > 0.01
    ORDER BY purchase_date ASC, id ASC
");
$pending_stmt->execute([$manufacturer_id]);
$pending_purchases = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_purchase_balance = 0.00;
foreach ($pending_purchases as $pp) {
    $total_purchase_balance += (float)$pp['due_amount'];
}

$initial_outstanding = (float)($manufacturer['initial_outstanding_amount'] ?? 0);
$initial_type = $manufacturer['initial_outstanding_type'] ?? 'none';

if ($initial_type === 'credit') {
    $net_payable = max(0, $total_purchase_balance - $initial_outstanding);
    $outstanding_text = 'Credit Balance (Supplier owes you): ₹' . number_format($initial_outstanding, 2);
    $outstanding_class = 'success';
    $outstanding_icon = 'bx-up-arrow-alt';
} elseif ($initial_type === 'debit') {
    $net_payable = $total_purchase_balance + $initial_outstanding;
    $outstanding_text = 'Debit Balance (You owe supplier): ₹' . number_format($initial_outstanding, 2);
    $outstanding_class = 'danger';
    $outstanding_icon = 'bx-down-arrow-alt';
} else {
    $net_payable = $total_purchase_balance;
    $outstanding_text = 'No Outstanding Balance';
    $outstanding_class = 'secondary';
    $outstanding_icon = 'bx-check-circle';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action'])) {
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $payment_method = normalizePaymentMethod($_POST['payment_method'] ?? 'cash');
    $reference_no = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_type = $_POST['payment_type'] ?? 'single';

    if ($amount <= 0) {
        $error = 'Please enter a valid amount.';
    } else {
        try {
            $pdo->beginTransaction();

            $paymentReferenceId = $payment_type === 'single' ? $purchase_id : $manufacturer_id;
            $paymentTypeForTable = $payment_type === 'outstanding_only' ? 'supplier_outstanding' : 'supplier';
            $storedPaymentType = $payment_type === 'outstanding_only' ? 'outstanding' : ($payment_type === 'overall' ? 'overall' : 'single');

            $payment_id = insertPayment($pdo, [
                'payment_date' => $payment_date,
                'type' => $paymentTypeForTable,
                'reference_id' => $paymentReferenceId,
                'amount' => $amount,
                'payment_method' => $payment_method,
                'reference_no' => $reference_no,
                'recorded_by' => $user_id,
                'notes' => $notes,
                'payment_type' => $storedPaymentType,
                'business_id' => $business_id,
                'manufacturer_id' => $manufacturer_id
            ]);

            $allocations = [];

            if ($payment_type === 'single') {
                $poStmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ? FOR UPDATE");
                $poStmt->execute([$purchase_id]);
                $po = $poStmt->fetch(PDO::FETCH_ASSOC);
                if (!$po) {
                    throw new Exception('Purchase order not found.');
                }

                $balanceDue = max(0, (float)$po['total_amount'] - (float)$po['paid_amount']);
                if ($amount > $balanceDue + 0.009) {
                    throw new Exception('Amount cannot exceed PO balance: ₹' . number_format($balanceDue, 2));
                }

                $paidBefore = (float)$po['paid_amount'];
                $paidAfter = round($paidBefore + $amount, 2);
                $statusBefore = $po['payment_status'] ?? 'pending';
                $statusAfter = computePurchaseStatus((float)$po['total_amount'], $paidAfter);

                insertSupplierAllocation($pdo, [
                    'payment_id' => $payment_id,
                    'business_id' => $business_id,
                    'manufacturer_id' => $manufacturer_id,
                    'purchase_id' => $purchase_id,
                    'purchase_number' => $po['purchase_number'],
                    'allocation_type' => 'purchase_order',
                    'allocated_amount' => $amount,
                    'po_paid_before' => $paidBefore,
                    'po_paid_after' => $paidAfter,
                    'po_status_before' => $statusBefore,
                    'po_status_after' => $statusAfter,
                    'created_by' => $user_id,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                $stmt = $pdo->prepare("UPDATE purchases SET paid_amount = ?, payment_status = ? WHERE id = ?");
                $stmt->execute([$paidAfter, $statusAfter, $purchase_id]);

                $allocations[] = ['type' => 'purchase_order', 'purchase_id' => $purchase_id, 'amount' => $amount];
            } elseif ($payment_type === 'outstanding_only') {
                $manStmt = $pdo->prepare("SELECT * FROM manufacturers WHERE id = ? FOR UPDATE");
                $manStmt->execute([$manufacturer_id]);
                $man = $manStmt->fetch(PDO::FETCH_ASSOC);

                $currentAmount = (float)($man['initial_outstanding_amount'] ?? 0);
                $currentType = $man['initial_outstanding_type'] ?? 'none';

                if ($currentType !== 'debit' || $currentAmount <= 0.01) {
                    throw new Exception('No supplier debit outstanding balance to pay.');
                }
                if ($amount > $currentAmount + 0.009) {
                    throw new Exception('Amount cannot exceed outstanding balance: ₹' . number_format($currentAmount, 2));
                }

                $afterAmount = max(0, round($currentAmount - $amount, 2));
                $afterType = $afterAmount <= 0.01 ? 'none' : 'debit';

                insertSupplierAllocation($pdo, [
                    'payment_id' => $payment_id,
                    'business_id' => $business_id,
                    'manufacturer_id' => $manufacturer_id,
                    'allocation_type' => 'outstanding',
                    'allocated_amount' => $amount,
                    'outstanding_before' => $currentAmount,
                    'outstanding_after' => $afterAmount,
                    'outstanding_type_before' => $currentType,
                    'outstanding_type_after' => $afterType,
                    'created_by' => $user_id,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                $stmt = $pdo->prepare("UPDATE manufacturers SET initial_outstanding_amount = ?, initial_outstanding_type = ? WHERE id = ?");
                $stmt->execute([$afterAmount, $afterType, $manufacturer_id]);

                $allocations[] = ['type' => 'outstanding', 'manufacturer_id' => $manufacturer_id, 'amount' => $amount];
            } elseif ($payment_type === 'overall') {
                if ($amount > $net_payable + 0.009) {
                    throw new Exception('Amount cannot exceed net payable: ₹' . number_format($net_payable, 2));
                }

                $remaining = $amount;

                $manStmt = $pdo->prepare("SELECT * FROM manufacturers WHERE id = ? FOR UPDATE");
                $manStmt->execute([$manufacturer_id]);
                $man = $manStmt->fetch(PDO::FETCH_ASSOC);

                $currentAmount = (float)($man['initial_outstanding_amount'] ?? 0);
                $currentType = $man['initial_outstanding_type'] ?? 'none';

                if ($currentType === 'debit' && $currentAmount > 0.01 && $remaining > 0.01) {
                    $outstandingPayment = min($remaining, $currentAmount);
                    $afterAmount = max(0, round($currentAmount - $outstandingPayment, 2));
                    $afterType = $afterAmount <= 0.01 ? 'none' : 'debit';

                    insertSupplierAllocation($pdo, [
                        'payment_id' => $payment_id,
                        'business_id' => $business_id,
                        'manufacturer_id' => $manufacturer_id,
                        'allocation_type' => 'outstanding',
                        'allocated_amount' => round($outstandingPayment, 2),
                        'outstanding_before' => $currentAmount,
                        'outstanding_after' => $afterAmount,
                        'outstanding_type_before' => $currentType,
                        'outstanding_type_after' => $afterType,
                        'created_by' => $user_id,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);

                    $stmt = $pdo->prepare("UPDATE manufacturers SET initial_outstanding_amount = ?, initial_outstanding_type = ? WHERE id = ?");
                    $stmt->execute([$afterAmount, $afterType, $manufacturer_id]);

                    $allocations[] = ['type' => 'outstanding', 'amount' => round($outstandingPayment, 2)];
                    $remaining = round($remaining - $outstandingPayment, 2);
                }

                $poStmt = $pdo->prepare("
                    SELECT id, purchase_number, total_amount, paid_amount, payment_status,
                           GREATEST(total_amount - paid_amount, 0) AS due_amount
                    FROM purchases
                    WHERE manufacturer_id = ?
                      AND GREATEST(total_amount - paid_amount, 0) > 0.01
                    ORDER BY purchase_date ASC, id ASC
                    FOR UPDATE
                ");
                $poStmt->execute([$manufacturer_id]);
                $lockedPOs = $poStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($lockedPOs as $po) {
                    if ($remaining <= 0.01) {
                        break;
                    }

                    $poDue = (float)$po['due_amount'];
                    if ($poDue <= 0.01) {
                        continue;
                    }

                    $paymentForPO = min($remaining, $poDue);
                    $paidBefore = (float)$po['paid_amount'];
                    $paidAfter = round($paidBefore + $paymentForPO, 2);
                    $statusBefore = $po['payment_status'] ?? 'pending';
                    $statusAfter = computePurchaseStatus((float)$po['total_amount'], $paidAfter);

                    insertSupplierAllocation($pdo, [
                        'payment_id' => $payment_id,
                        'business_id' => $business_id,
                        'manufacturer_id' => $manufacturer_id,
                        'purchase_id' => $po['id'],
                        'purchase_number' => $po['purchase_number'],
                        'allocation_type' => 'purchase_order',
                        'allocated_amount' => round($paymentForPO, 2),
                        'po_paid_before' => $paidBefore,
                        'po_paid_after' => $paidAfter,
                        'po_status_before' => $statusBefore,
                        'po_status_after' => $statusAfter,
                        'created_by' => $user_id,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);

                    $stmt = $pdo->prepare("UPDATE purchases SET paid_amount = ?, payment_status = ? WHERE id = ?");
                    $stmt->execute([$paidAfter, $statusAfter, (int)$po['id']]);

                    $allocations[] = ['type' => 'purchase_order', 'purchase_id' => (int)$po['id'], 'amount' => round($paymentForPO, 2)];
                    $remaining = round($remaining - $paymentForPO, 2);
                }
            } else {
                throw new Exception('Invalid payment type.');
            }

            logSupplierPaymentActivity($pdo, $business_id, $user_id, 'supplier_payment_created', [
                'payment_id' => $payment_id,
                'purchase_id' => $purchase_id,
                'manufacturer_id' => $manufacturer_id,
                'amount' => $amount,
                'payment_type' => $payment_type,
                'payment_method' => $payment_method,
                'reference_no' => $reference_no,
                'payment_date' => $payment_date,
                'allocations' => $allocations
            ]);

            $pdo->commit();
            $_SESSION['success'] = 'Supplier payment recorded successfully.';
            header('Location: purchase_payment.php?id=' . $purchase_id);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed: ' . $e->getMessage();
        }
    }
}

// Refresh after payment attempts.
$stmt = $pdo->prepare("
    SELECT p.*, 
           m.name AS manufacturer_name,
           m.initial_outstanding_amount,
           m.initial_outstanding_type,
           GREATEST(p.total_amount - p.paid_amount, 0) AS balance_due
    FROM purchases p
    JOIN manufacturers m ON p.manufacturer_id = m.id
    WHERE p.id = ?
");
$stmt->execute([$purchase_id]);
$purchase = $stmt->fetch(PDO::FETCH_ASSOC);

$manufacturer_stmt = $pdo->prepare("SELECT * FROM manufacturers WHERE id = ?");
$manufacturer_stmt->execute([$manufacturer_id]);
$manufacturer = $manufacturer_stmt->fetch(PDO::FETCH_ASSOC);

$pending_stmt = $pdo->prepare("
    SELECT id, purchase_number, total_amount, paid_amount,
           GREATEST(total_amount - paid_amount, 0) AS due_amount,
           purchase_date,
           payment_status
    FROM purchases 
    WHERE manufacturer_id = ?
      AND GREATEST(total_amount - paid_amount, 0) > 0.01
    ORDER BY purchase_date ASC, id ASC
");
$pending_stmt->execute([$manufacturer_id]);
$pending_purchases = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_purchase_balance = 0.00;
foreach ($pending_purchases as $pp) {
    $total_purchase_balance += (float)$pp['due_amount'];
}

$initial_outstanding = (float)($manufacturer['initial_outstanding_amount'] ?? 0);
$initial_type = $manufacturer['initial_outstanding_type'] ?? 'none';

if ($initial_type === 'credit') {
    $net_payable = max(0, $total_purchase_balance - $initial_outstanding);
    $outstanding_text = 'Credit Balance (Supplier owes you): ₹' . number_format($initial_outstanding, 2);
    $outstanding_class = 'success';
    $outstanding_icon = 'bx-up-arrow-alt';
} elseif ($initial_type === 'debit') {
    $net_payable = $total_purchase_balance + $initial_outstanding;
    $outstanding_text = 'Debit Balance (You owe supplier): ₹' . number_format($initial_outstanding, 2);
    $outstanding_class = 'danger';
    $outstanding_icon = 'bx-down-arrow-alt';
} else {
    $net_payable = $total_purchase_balance;
    $outstanding_text = 'No Outstanding Balance';
    $outstanding_class = 'secondary';
    $outstanding_icon = 'bx-check-circle';
}

$last15Start = date('Y-m-d H:i:s', strtotime('-15 days'));

$paymentsStmt = $pdo->prepare("
    SELECT p.*, u.full_name AS recorded_by_name,
           (
               SELECT GROUP_CONCAT(
                   CONCAT(
                       CASE
                           WHEN spa.allocation_type = 'purchase_order' THEN COALESCE(spa.purchase_number, CONCAT('PO #', spa.purchase_id))
                           WHEN spa.allocation_type = 'outstanding' THEN 'Supplier Outstanding'
                           ELSE spa.allocation_type
                       END,
                       ': ₹', FORMAT(spa.allocated_amount, 2)
                   )
                   ORDER BY spa.id ASC SEPARATOR '<br>'
               )
               FROM supplier_payment_allocations spa
               WHERE spa.payment_id = p.id
                 AND COALESCE(spa.is_deleted, 0) = 0
           ) AS allocation_details,
           (
               SELECT COALESCE(SUM(spa.allocated_amount), 0)
               FROM supplier_payment_allocations spa
               WHERE spa.payment_id = p.id
                 AND spa.purchase_id = {$purchase_id}
                 AND spa.allocation_type = 'purchase_order'
                 AND COALESCE(spa.is_deleted, 0) = 0
           ) AS current_po_allocated_amount
    FROM payments p
    LEFT JOIN users u ON u.id = p.recorded_by
    WHERE (
            p.manufacturer_id = ?
            OR (
                (p.manufacturer_id IS NULL OR p.manufacturer_id = 0)
                AND p.type = 'supplier'
                AND p.reference_id IN (
                    SELECT id FROM purchases WHERE manufacturer_id = ?
                )
            )
        )
      AND p.type IN ('supplier', 'supplier_outstanding')
      AND COALESCE(p.is_deleted, 0) = 0
      AND p.created_at >= ?
    ORDER BY p.created_at DESC, p.payment_date DESC, p.id DESC
");
$paymentsStmt->execute([$manufacturer_id, $manufacturer_id, $last15Start]);
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

$deletedPaymentsStmt = $pdo->prepare("
    SELECT p.*, u.full_name AS recorded_by_name,
           (
               SELECT GROUP_CONCAT(
                   CONCAT(
                       CASE
                           WHEN spa.allocation_type = 'purchase_order' THEN COALESCE(spa.purchase_number, CONCAT('PO #', spa.purchase_id))
                           WHEN spa.allocation_type = 'outstanding' THEN 'Supplier Outstanding'
                           ELSE spa.allocation_type
                       END,
                       ': ₹', FORMAT(spa.allocated_amount, 2)
                   )
                   ORDER BY spa.id ASC SEPARATOR '<br>'
               )
               FROM supplier_payment_allocations spa
               WHERE spa.payment_id = p.id
           ) AS allocation_details,
           (
               SELECT COALESCE(SUM(spa.allocated_amount), 0)
               FROM supplier_payment_allocations spa
               WHERE spa.payment_id = p.id
                 AND spa.purchase_id = {$purchase_id}
                 AND spa.allocation_type = 'purchase_order'
           ) AS current_po_allocated_amount
    FROM payments p
    LEFT JOIN users u ON u.id = p.recorded_by
    WHERE (
            p.manufacturer_id = ?
            OR (
                (p.manufacturer_id IS NULL OR p.manufacturer_id = 0)
                AND p.type = 'supplier'
                AND p.reference_id IN (
                    SELECT id FROM purchases WHERE manufacturer_id = ?
                )
            )
        )
      AND p.type IN ('supplier', 'supplier_outstanding')
      AND COALESCE(p.is_deleted, 0) = 1
      AND p.created_at >= ?
    ORDER BY p.deleted_at DESC, p.id DESC
    LIMIT 20
");
$deletedPaymentsStmt->execute([$manufacturer_id, $manufacturer_id, $last15Start]);
$deleted_payments = $deletedPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

$last15Label = date('d M Y', strtotime($last15Start)) . ' to ' . date('d M Y');

$page_title = 'Payment - PO #' . $purchase['purchase_number'];
?>
<!doctype html>
<html lang="en">
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
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-money me-2"></i> Record Supplier Payment
                                    <small class="text-muted ms-2"><i class="bx bx-hash me-1"></i><?= htmlspecialchars($purchase['purchase_number']) ?></small>
                                </h4>
                                <p class="text-muted mb-0"><i class="bx bx-building me-1"></i><?= htmlspecialchars($purchase['manufacturer_name']) ?></p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="purchases.php" class="btn btn-outline-secondary"><i class="bx bx-arrow-back me-1"></i> Back to Purchases</a>
                                <a href="purchase_view.php?id=<?= $purchase_id ?>" class="btn btn-outline-info"><i class="bx bx-show me-1"></i> View PO</a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Current PO Payment Context -->
                <div class="alert alert-primary border-start border-primary border-4 shadow-sm mb-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <h5 class="mb-1">
                                <i class="bx bx-purchase-tag-alt me-2"></i>
                                Currently Entering Payment For:
                                <span class="badge bg-primary fs-6 ms-1"><?= htmlspecialchars($purchase['purchase_number']) ?></span>
                            </h5>
                            <div class="text-muted">
                                Supplier: <strong><?= htmlspecialchars($purchase['manufacturer_name']) ?></strong>
                                <span class="mx-2">|</span>
                                PO Date: <strong><?= !empty($purchase['purchase_date']) ? date('d M Y', strtotime($purchase['purchase_date'])) : '—' ?></strong>
                            </div>
                        </div>
                        <div class="text-end">
                            <small class="text-muted d-block">Current PO Due</small>
                            <h4 class="mb-0 text-primary">₹<?= number_format((float)$purchase['balance_due'], 2) ?></h4>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-xl-4 col-md-6">
                        <div class="card card-hover border-start border-<?= $outstanding_class ?> border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Supplier Outstanding</h6>
                                        <h3 class="mb-0 text-<?= $outstanding_class ?>">₹<?= number_format($initial_outstanding, 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-<?= $outstanding_class ?> bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx <?= $outstanding_icon ?> text-<?= $outstanding_class ?>"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-2"><?= htmlspecialchars($outstanding_text) ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total PO Balance</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($total_purchase_balance, 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-cart text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-2">From <?= count($pending_purchases) ?> pending purchase(s)</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Net Payable</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($net_payable, 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-money text-success"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-2">Overall payable after outstanding adjustment</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <ul class="nav nav-tabs card-header-tabs" role="tablist">
                            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#single-po"><i class="bx bx-file me-1"></i> Single PO Payment</a></li>
                            <?php if ($initial_type === 'debit' && $initial_outstanding > 0.01): ?>
                                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#outstanding-only"><i class="bx bx-credit-card me-1"></i> Pay Outstanding Only</a></li>
                            <?php endif; ?>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#overall-payment"><i class="bx bx-layer me-1"></i> Overall Payment</a></li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <div class="tab-pane active" id="single-po">
                                <div class="alert alert-info border-start border-info border-4">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <i class="bx bx-info-circle me-2"></i>
                                            You are entering payment for current PO:
                                            <strong class="text-primary"><?= htmlspecialchars($purchase['purchase_number']) ?></strong>
                                        </div>
                                        <div>
                                            <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2">
                                                Due: ₹<?= number_format((float)$purchase['balance_due'], 2) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php if ((float)$purchase['balance_due'] > 0.01): ?>
                                    <form method="POST" class="supplier-payment-form">
                                        <input type="hidden" name="payment_type" value="single">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                                <select name="payment_method" class="form-select" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="bank">Bank Transfer</option>
                                                    <option value="upi">UPI</option>
                                                    <option value="cheque">Cheque</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" name="amount" class="form-control form-control-lg text-end" step="0.01" min="0.01" max="<?= (float)$purchase['balance_due'] ?>" value="<?= number_format((float)$purchase['balance_due'], 2, '.', '') ?>" required>
                                                </div>
                                                <small class="text-muted">Max: ₹<?= number_format((float)$purchase['balance_due'], 2) ?></small>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Reference Number</label>
                                                <input type="text" name="reference_no" class="form-control" placeholder="Cheque no., UPI ref, transaction ID">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Notes</label>
                                                <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                                            </div>
                                        </div>
                                        <div class="text-end mt-4">
                                            <button type="submit" class="btn btn-success btn-lg px-5"><i class="bx bx-check-circle me-2"></i> Record Payment</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="bx bx-check-circle text-success fs-1"></i>
                                        <h5 class="mt-2">Purchase Order Fully Paid</h5>
                                        <p class="text-muted mb-0">No balance amount pending for this PO.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($initial_type === 'debit' && $initial_outstanding > 0.01): ?>
                                <div class="tab-pane" id="outstanding-only">
                                    <form method="POST" class="supplier-payment-form">
                                        <input type="hidden" name="payment_type" value="outstanding_only">
                                        <div class="alert alert-info"><i class="bx bx-info-circle me-2"></i>This will only reduce supplier debit outstanding. It will not affect purchase orders.</div>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                                <select name="payment_method" class="form-select" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="bank">Bank Transfer</option>
                                                    <option value="upi">UPI</option>
                                                    <option value="cheque">Cheque</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" name="amount" class="form-control form-control-lg text-end" step="0.01" min="0.01" max="<?= $initial_outstanding ?>" value="<?= number_format($initial_outstanding, 2, '.', '') ?>" required>
                                                </div>
                                                <small class="text-muted">Max: ₹<?= number_format($initial_outstanding, 2) ?></small>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Reference Number</label>
                                                <input type="text" name="reference_no" class="form-control" placeholder="Cheque no., UPI ref, transaction ID">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Notes</label>
                                                <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                                            </div>
                                        </div>
                                        <div class="text-end mt-4">
                                            <button type="submit" class="btn btn-primary btn-lg px-5"><i class="bx bx-credit-card me-2"></i> Pay Outstanding</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <div class="tab-pane" id="overall-payment">
                                <form method="POST" class="supplier-payment-form">
                                    <input type="hidden" name="payment_type" value="overall">
                                    <div class="alert alert-warning">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Overall Payment Logic:</strong> First reduces supplier debit outstanding, then distributes remaining amount to pending purchase orders oldest first. One row is stored in <code>payments</code>; allocations are stored separately.
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                            <select name="payment_method" class="form-select" required>
                                                <option value="cash">Cash</option>
                                                <option value="bank">Bank Transfer</option>
                                                <option value="upi">UPI</option>
                                                <option value="cheque">Cheque</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" name="amount" id="overall_amount" class="form-control form-control-lg text-end" step="0.01" min="0.01" max="<?= $net_payable ?>" value="<?= number_format($net_payable, 2, '.', '') ?>" required>
                                            </div>
                                            <small class="text-muted">Max Net Payable: ₹<?= number_format($net_payable, 2) ?></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Reference Number</label>
                                            <input type="text" name="reference_no" class="form-control" placeholder="Cheque no., UPI ref, transaction ID">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Notes</label>
                                            <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                                        </div>
                                    </div>
                                    <div class="bg-light rounded p-3 mt-4">
                                        <h6 class="mb-3"><i class="bx bx-git-branch me-2"></i>Payment Distribution Preview</h6>
                                        <div class="d-flex justify-content-between mb-2"><span>Total Payment:</span><strong id="preview-total">₹0.00</strong></div>
                                        <?php if ($initial_type === 'debit' && $initial_outstanding > 0.01): ?>
                                            <div class="d-flex justify-content-between mb-2 text-danger"><span>Debit Outstanding:</span><strong id="preview-outstanding">₹0.00</strong></div>
                                        <?php endif; ?>
                                        <div class="d-flex justify-content-between mb-2 text-warning"><span>Purchase Orders:</span><strong id="preview-pos">₹0.00</strong></div>
                                        <?php if ($pending_purchases): ?>
                                            <hr>
                                            <ul class="list-unstyled mb-0" id="po-preview-list">
                                                <?php foreach ($pending_purchases as $po): ?>
                                                    <li class="d-flex justify-content-between mb-1">
                                                        <span><?= htmlspecialchars($po['purchase_number']) ?></span>
                                                        <span class="po-preview-amount" data-due="<?= (float)$po['due_amount'] ?>">₹0.00</span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end mt-4">
                                        <button type="submit" class="btn btn-success btn-lg px-5"><i class="bx bx-layer me-2"></i> Process Overall Payment</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($pending_purchases): ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-light"><h5 class="mb-0"><i class="bx bx-list-ul me-2"></i>Pending Purchases for <?= htmlspecialchars($purchase['manufacturer_name']) ?></h5></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th>PO Number</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Due</th><th class="text-center">Status</th><th class="text-center">Action</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_purchases as $pp): ?>
                                            <tr class="<?= (int)$pp['id'] === $purchase_id ? 'table-primary' : '' ?>">
                                                <td><strong><?= htmlspecialchars($pp['purchase_number']) ?></strong></td>
                                                <td><?= date('d M Y', strtotime($pp['purchase_date'])) ?></td>
                                                <td class="text-end">₹<?= number_format((float)$pp['total_amount'], 2) ?></td>
                                                <td class="text-end">₹<?= number_format((float)$pp['paid_amount'], 2) ?></td>
                                                <td class="text-end text-warning fw-bold">₹<?= number_format((float)$pp['due_amount'], 2) ?></td>
                                                <td class="text-center"><span class="badge bg-<?= $pp['payment_status'] === 'partial' ? 'warning' : 'danger' ?> bg-opacity-10 text-<?= $pp['payment_status'] === 'partial' ? 'warning' : 'danger' ?>"><?= ucfirst($pp['payment_status']) ?></span></td>
                                                <td class="text-center">
                                                    <?php if ((int)$pp['id'] !== $purchase_id): ?>
                                                        <a href="purchase_payment.php?id=<?= (int)$pp['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bx bx-money"></i> Pay</a>
                                                    <?php else: ?>
                                                        <span class="badge bg-primary px-3 py-2">
                                                            <i class="bx bx-check-circle me-1"></i> Current PO
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$payments): ?>
                    <div class="alert alert-info shadow-sm mt-4">
                        <i class="bx bx-info-circle me-2"></i>
                        No paid records found in the last 15 days based on <code>payments.created_at</code>.
                    </div>
                <?php endif; ?>

                <?php if ($payments): ?>
                    <div class="card shadow-sm mt-4">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><i class="bx bx-history me-2"></i>Last 15 Days Paid Details</h5>
                                <small class="text-muted">Based on payment created date: <?= htmlspecialchars($last15Label) ?></small>
                            </div>
                            <span class="badge bg-primary"><?= count($payments) ?> Active</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th>Created At</th><th>Payment Date</th><th>Type</th><th>Amount</th><th>Current PO</th><th>Method</th><th>Reference</th><th>Allocation</th><th>Recorded By</th><th>Notes</th><th class="text-end">Action</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $pay): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= !empty($pay['created_at']) ? date('d M Y', strtotime($pay['created_at'])) : '—' ?></strong><br>
                                                    <small class="text-muted"><?= !empty($pay['created_at']) ? date('h:i A', strtotime($pay['created_at'])) : '' ?></small>
                                                </td>
                                                <td><strong><?= date('d M Y', strtotime($pay['payment_date'])) ?></strong></td>
                                                <td><span class="badge bg-info bg-opacity-10 text-info"><?= ucfirst(str_replace('_', ' ', $pay['payment_type'] ?? 'single')) ?></span></td>
                                                <td><span class="badge bg-success bg-opacity-10 text-success px-3 py-1">₹<?= number_format((float)$pay['amount'], 2) ?></span></td>
                                                <td>
                                                    <?php if ((float)($pay['current_po_allocated_amount'] ?? 0) > 0.01): ?>
                                                        <span class="badge bg-primary bg-opacity-10 text-primary d-block mb-1">
                                                            <?= htmlspecialchars($purchase['purchase_number']) ?>
                                                        </span>
                                                        <small class="text-success fw-semibold">
                                                            ₹<?= number_format((float)$pay['current_po_allocated_amount'], 2) ?>
                                                        </small>
                                                    <?php elseif (($pay['payment_type'] ?? '') === 'overall'): ?>
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary">Not applied</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="badge bg-light text-dark"><?= ucfirst(htmlspecialchars($pay['payment_method'])) ?></span></td>
                                                <td><?= htmlspecialchars($pay['reference_no'] ?: '—') ?></td>
                                                <td><small><?= $pay['allocation_details'] ?: '<span class="text-muted">No allocation rows</span>' ?></small></td>
                                                <td><small><i class="bx bx-user me-1"></i><?= htmlspecialchars($pay['recorded_by_name'] ?? 'Unknown') ?></small></td>
                                                <td><?= htmlspecialchars($pay['notes'] ?: '—') ?></td>
                                                <td class="text-end">
                                                    <form method="POST" class="delete-payment-form d-inline">
                                                        <input type="hidden" name="action" value="delete_payment">
                                                        <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                                                        <input type="hidden" name="delete_reason" value="Soft deleted from supplier payment page">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" data-amount="<?= number_format((float)$pay['amount'], 2, '.', '') ?>" data-date="<?= htmlspecialchars(date('d M Y', strtotime($pay['payment_date'])), ENT_QUOTES) ?>"><i class="bx bx-trash"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($deleted_payments): ?>
                    <div class="card shadow-sm mt-4">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><i class="bx bx-undo me-2"></i>Deleted Payments / Restore</h5>
                                <small class="text-muted">Only payments created in the last 15 days are shown</small>
                            </div>
                            <span class="badge bg-danger"><?= count($deleted_payments) ?> Deleted</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th>Deleted At</th><th>Payment Date</th><th>Type</th><th>Amount</th><th>Allocation</th><th>Reason</th><th class="text-end">Action</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($deleted_payments as $pay): ?>
                                            <tr>
                                                <td><?= !empty($pay['deleted_at']) ? date('d M Y h:i A', strtotime($pay['deleted_at'])) : '—' ?></td>
                                                <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                                                <td><span class="badge bg-secondary bg-opacity-10 text-secondary"><?= ucfirst(str_replace('_', ' ', $pay['payment_type'] ?? 'single')) ?></span></td>
                                                <td><span class="text-danger fw-bold">₹<?= number_format((float)$pay['amount'], 2) ?></span></td>
                                                <td><small><?= $pay['allocation_details'] ?: '—' ?></small></td>
                                                <td><?= htmlspecialchars($pay['delete_reason'] ?: '—') ?></td>
                                                <td class="text-end">
                                                    <form method="POST" class="restore-payment-form d-inline">
                                                        <input type="hidden" name="action" value="restore_payment">
                                                        <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-success" data-amount="<?= number_format((float)$pay['amount'], 2, '.', '') ?>"><i class="bx bx-undo"></i> Restore</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/scripts.php'); ?>

<style>
.card-hover { transition: transform 0.3s ease, box-shadow 0.3s ease; }
.card-hover:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important; }
.border-start { border-left-width: 4px !important; }
.avatar-sm { width: 48px; height: 48px; }
.table-primary td { background-color: #e7f1ff !important; }
.current-po-pill { font-size: 0.95rem; }
.nav-tabs .nav-link { border: none; color: #6c757d; padding: 0.75rem 1.5rem; }
.nav-tabs .nav-link.active { color: #0d6efd; background: transparent; border-bottom: 2px solid #0d6efd; }
.nav-tabs .nav-link:hover { border: none; color: #0d6efd; }
.btn-lg { border-radius: 10px; }
.input-group-text { background-color: #f8f9fa; border-color: #ced4da; }
@media (max-width: 768px) { .btn-lg { padding: 0.75rem 1rem; } }
</style>

<script>
$(document).ready(function() {
    setTimeout(function() { $('.alert').alert('close'); }, 5000);
    $('[data-bs-toggle="tooltip"]').tooltip();

    function updatePaymentPreview() {
        const totalAmount = parseFloat($('#overall_amount').val()) || 0;
        const outstandingBalance = <?= json_encode($initial_type === 'debit' ? $initial_outstanding : 0) ?>;
        let remaining = totalAmount;
        let outstandingPaid = 0;
        let poTotal = 0;

        $('#preview-total').text('₹' + totalAmount.toFixed(2));

        if (outstandingBalance > 0) {
            outstandingPaid = Math.min(remaining, outstandingBalance);
            remaining -= outstandingPaid;
            $('#preview-outstanding').text('₹' + outstandingPaid.toFixed(2));
        }

        $('.po-preview-amount').each(function() {
            const due = parseFloat($(this).data('due')) || 0;
            let pay = 0;
            if (remaining > 0) {
                pay = Math.min(remaining, due);
                remaining -= pay;
                poTotal += pay;
            }
            $(this).text('₹' + pay.toFixed(2));
        });

        $('#preview-pos').text('₹' + poTotal.toFixed(2));
    }

    $('#overall_amount').on('input', updatePaymentPreview);
    $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
        if ($(e.target).attr('href') === '#overall-payment') {
            updatePaymentPreview();
        }
    });
    updatePaymentPreview();

    $('input[name="amount"]').on('input', function() {
        const max = parseFloat($(this).attr('max')) || 0;
        const value = parseFloat($(this).val()) || 0;
        if (max > 0 && value > max) {
            $(this).val(max.toFixed(2));
            Swal.fire({ icon: 'warning', title: 'Amount Exceeded', text: 'Amount cannot exceed maximum limit', timer: 2000, showConfirmButton: false });
        }
    });

    $('.supplier-payment-form').on('submit', function(e) {
        if ($(this).data('confirmed') === true) {
            return true;
        }
        e.preventDefault();
        const form = this;
        const paymentType = $(form).find('input[name="payment_type"]').val();
        const amount = parseFloat($(form).find('input[name="amount"]').val()) || 0;
        if (amount <= 0) {
            Swal.fire({ icon: 'error', title: 'Invalid Amount', text: 'Enter valid payment amount.' });
            return false;
        }

        Swal.fire({
            title: 'Confirm Payment',
            html: `Process ${paymentType.replace('_', ' ')} payment of <strong>₹${amount.toFixed(2)}</strong>?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, process payment',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (result.isConfirmed) {
                $(form).data('confirmed', true);
                form.submit();
            }
        });
        return false;
    });

    $('.delete-payment-form').on('submit', function(e) {
        e.preventDefault();
        const form = this;
        const amount = $(form).find('button').data('amount');
        const date = $(form).find('button').data('date');

        Swal.fire({
            title: 'Delete payment?',
            html: `This will soft delete this paid record of <strong>₹${amount}</strong> dated <strong>${date}</strong> and restore supplier outstanding / PO balances.`,
            icon: 'warning',
            input: 'text',
            inputLabel: 'Delete reason',
            inputValue: 'Soft deleted from supplier payment page',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete and restore',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (result.isConfirmed) {
                $(form).find('input[name="delete_reason"]').val(result.value || 'Soft deleted from supplier payment page');
                form.submit();
            }
        });
    });

    $('.restore-payment-form').on('submit', function(e) {
        e.preventDefault();
        const form = this;
        const amount = $(form).find('button').data('amount');
        Swal.fire({
            title: 'Restore payment?',
            html: `This will restore payment of <strong>₹${amount}</strong> and reapply supplier outstanding / PO allocations.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, restore',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});
</script>
</body>
</html>
