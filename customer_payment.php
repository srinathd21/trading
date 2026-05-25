<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once ('config/database.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager', 'accountant'])) {
    header('Location: dashboard.php');
    exit();
}

$business_id = $_SESSION['business_id'];
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if (!$customer_id) {
    header('Location: customers.php');
    exit();
}

function normalizePaymentMethod($method) {
    $allowed_methods = ['cash', 'upi', 'bank', 'cheque', 'other'];
    return in_array($method, $allowed_methods, true) ? $method : 'other';
}

function computeInvoiceStatus($total, $paid) {
    $pending = max(0, round((float)$total - (float)$paid, 2));

    if ($pending <= 0.01) {
        return ['paid', 0.00];
    }

    if ((float)$paid > 0) {
        return ['partial', $pending];
    }

    return ['pending', $pending];
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
        // MariaDB 11 supports IF NOT EXISTS. This prevents duplicate-column errors
        // when two requests try to prepare the schema at the same time.
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `$column` $definition");
    } catch (Exception $e) {
        // Fallback for older MySQL/MariaDB versions or servers that reject IF NOT EXISTS.
        try {
            if (!tableHasColumn($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (Exception $inner) {
            error_log("Unable to add column {$table}.{$column}: " . $inner->getMessage());
        }
    }
}

function ensureCustomerPaymentTrackingSchema(PDO $pdo): void {
    try {
        if (!tableExists($pdo, 'customer_payment_allocations')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS customer_payment_allocations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_id BIGINT UNSIGNED NOT NULL,
                business_id INT NOT NULL,
                customer_id INT NOT NULL,
                allocation_type ENUM('manual_credit','invoice','advance') NOT NULL,
                invoice_id INT DEFAULT NULL,
                invoice_number VARCHAR(100) DEFAULT NULL,
                allocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                invoice_paid_before DECIMAL(12,2) DEFAULT NULL,
                invoice_paid_after DECIMAL(12,2) DEFAULT NULL,
                invoice_pending_before DECIMAL(12,2) DEFAULT NULL,
                invoice_pending_after DECIMAL(12,2) DEFAULT NULL,
                customer_outstanding_type_before VARCHAR(20) DEFAULT NULL,
                customer_outstanding_amount_before DECIMAL(12,2) DEFAULT NULL,
                customer_outstanding_type_after VARCHAR(20) DEFAULT NULL,
                customer_outstanding_amount_after DECIMAL(12,2) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                deleted_at DATETIME DEFAULT NULL,
                deleted_by INT DEFAULT NULL,
                delete_reason TEXT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_payment_id (payment_id),
                KEY idx_customer_business (customer_id, business_id),
                KEY idx_invoice_id (invoice_id),
                KEY idx_is_deleted (is_deleted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $e) {
        error_log('Unable to create customer_payment_allocations: ' . $e->getMessage());
    }

    // Store invoice/outstanding collection in customer_payments only.
    // Do not write to invoice_payments from this page.
    $customerPaymentColumns = [
        'invoice_id' => 'INT DEFAULT NULL',
        'payment_mode' => 'VARCHAR(30) DEFAULT NULL',
        'payment_type' => "ENUM('invoice','outstanding') NOT NULL DEFAULT 'invoice'",
        'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'deleted_at' => 'DATETIME DEFAULT NULL',
        'deleted_by' => 'INT DEFAULT NULL',
        'delete_reason' => 'TEXT DEFAULT NULL'
    ];

    foreach ($customerPaymentColumns as $column => $definition) {
        addColumnIfMissing($pdo, 'customer_payments', $column, $definition);
    }
}

ensureCustomerPaymentTrackingSchema($pdo);

function logCustomerPaymentActivity(PDO $pdo, int $business_id, int $user_id, string $action, string $description, array $details = []): void {
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
            'module' => 'customer_payment',
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

        $fieldSql = '`' . implode('`, `', array_keys($insert)) . '`';
        $placeholderSql = implode(', ', array_fill(0, count($insert), '?'));
        $sql = "INSERT INTO activity_logs ($fieldSql) VALUES ($placeholderSql)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($insert));
    } catch (Exception $e) {
        // Activity logging should never block payment operations.
    }
}

function createCustomerPaymentRecord(PDO $pdo, int $business_id, int $customer_id, float $payment_amount, string $payment_method, string $reference_no, string $payment_date, string $notes, int $user_id, string $payment_mode, string $payment_type = 'invoice'): int {
    $columns = ['business_id', 'customer_id', 'total_amount', 'payment_method', 'reference_no', 'payment_date', 'notes', 'created_by', 'created_at'];
    $values = [$business_id, $customer_id, round($payment_amount, 2), $payment_method, $reference_no, $payment_date, $notes, $user_id, date('Y-m-d H:i:s')];

    if (tableHasColumn($pdo, 'customer_payments', 'payment_mode')) {
        $columns[] = 'payment_mode';
        $values[] = $payment_mode;
    }

    if (tableHasColumn($pdo, 'customer_payments', 'payment_type')) {
        $columns[] = 'payment_type';
        $values[] = in_array($payment_type, ['invoice', 'outstanding'], true) ? $payment_type : 'invoice';
    }

    $sql = "INSERT INTO customer_payments (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    return (int)$pdo->lastInsertId();
}

function updateCustomerPaymentStoredAmount(PDO $pdo, int $payment_id, int $business_id, float $stored_amount, string $payment_type): void {
    $sets = ['total_amount = ?'];
    $params = [round(max(0, $stored_amount), 2)];

    if (tableHasColumn($pdo, 'customer_payments', 'payment_type')) {
        $sets[] = 'payment_type = ?';
        $params[] = in_array($payment_type, ['invoice', 'outstanding'], true) ? $payment_type : 'invoice';
    }

    $params[] = $payment_id;
    $params[] = $business_id;

    $sql = "UPDATE customer_payments SET " . implode(', ', $sets) . " WHERE id = ? AND business_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function recordCustomerPaymentAllocation(PDO $pdo, array $data): int {
    $columns = array_keys($data);
    $sql = "INSERT INTO customer_payment_allocations (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    return (int)$pdo->lastInsertId();
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

function applyAdvanceToCustomerWithAudit(PDO $pdo, int $customer_id, int $business_id, float $advance_amount): array {
    $before = getCustomerOutstandingForUpdate($pdo, $customer_id, $business_id);
    $current_type = $before['outstanding_type'] ?? 'credit';
    $current_amount = (float)($before['outstanding_amount'] ?? 0);

    if ($current_type === 'debit') {
        $new_type = 'debit';
        $new_amount = $current_amount + $advance_amount;
    } else {
        if ($current_amount > $advance_amount) {
            $new_type = 'credit';
            $new_amount = $current_amount - $advance_amount;
        } elseif ($current_amount < $advance_amount) {
            $new_type = 'debit';
            $new_amount = $advance_amount - $current_amount;
        } else {
            $new_type = 'credit';
            $new_amount = 0;
        }
    }

    setCustomerOutstanding($pdo, $customer_id, $business_id, $new_type, $new_amount);

    return [
        'before_type' => $current_type,
        'before_amount' => $current_amount,
        'after_type' => $new_type,
        'after_amount' => round($new_amount, 2)
    ];
}

function applyAdvanceToCustomer(PDO $pdo, $customer_id, $business_id, $advance_amount) {
    if ($advance_amount <= 0) {
        return;
    }

    $stmt = $pdo->prepare("SELECT outstanding_type, outstanding_amount FROM customers WHERE id = ? AND business_id = ? FOR UPDATE");
    $stmt->execute([$customer_id, $business_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return;
    }

    $current_type = $row['outstanding_type'] ?? 'credit';
    $current_amount = (float)($row['outstanding_amount'] ?? 0);

    if ($current_type === 'debit') {
        $new_type = 'debit';
        $new_amount = $current_amount + $advance_amount;
    } else {
        if ($current_amount > $advance_amount) {
            $new_type = 'credit';
            $new_amount = $current_amount - $advance_amount;
        } elseif ($current_amount < $advance_amount) {
            $new_type = 'debit';
            $new_amount = $advance_amount - $current_amount;
        } else {
            $new_type = 'credit';
            $new_amount = 0;
        }
    }

    $update = $pdo->prepare("UPDATE customers SET outstanding_type = ?, outstanding_amount = ? WHERE id = ? AND business_id = ?");
    $update->execute([$new_type, round($new_amount, 2), $customer_id, $business_id]);
}

// Get customer details using real outstanding balance, not stale pending_amount values
$stmt = $pdo->prepare("
    SELECT c.*, 
           (
               SELECT COALESCE(SUM(GREATEST(total - paid_amount, 0)), 0)
               FROM invoices
               WHERE customer_id = c.id
                 AND business_id = ?
                 AND GREATEST(total - paid_amount, 0) > 0.01
           ) AS total_outstanding
    FROM customers c
    WHERE c.id = ? AND c.business_id = ?
");
$stmt->execute([$business_id, $customer_id, $business_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    $_SESSION['error'] = "Customer not found";
    header('Location: customers.php');
    exit();
}

// Get all outstanding invoices (oldest first for FIFO allocation)
$invoices_sql = "
    SELECT id,
           invoice_number,
           total,
           paid_amount,
           GREATEST(total - paid_amount, 0) AS outstanding,
           created_at,
           CASE
               WHEN GREATEST(total - paid_amount, 0) <= 0.01 THEN 'paid'
               WHEN paid_amount > 0 THEN 'partial'
               ELSE 'pending'
           END AS payment_status
    FROM invoices 
    WHERE customer_id = ?
      AND business_id = ?
      AND GREATEST(total - paid_amount, 0) > 0.01
    ORDER BY created_at ASC
";
$stmt = $pdo->prepare($invoices_sql);
$stmt->execute([$customer_id, $business_id]);
$outstanding_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get manual outstanding (credit/debit from customer table)
$manual_outstanding = (($customer['outstanding_type'] ?? 'credit') === 'credit')
    ? (float)$customer['outstanding_amount']
    : -(float)$customer['outstanding_amount'];
$total_outstanding = $manual_outstanding + (float)$customer['total_outstanding'];

// Handle payment deletion first (soft delete + restore balances)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_payment') {
    $delete_payment_id = (int)($_POST['payment_id'] ?? 0);
    $delete_reason = trim($_POST['delete_reason'] ?? 'Payment deleted by user');

    if ($delete_payment_id <= 0) {
        $_SESSION['error'] = "Invalid payment selected for deletion.";
        header("Location: customer_payment.php?customer_id=$customer_id");
        exit();
    }

    try {
        ensureCustomerPaymentTrackingSchema($pdo);
        $pdo->beginTransaction();

        $payment_stmt = $pdo->prepare("SELECT * FROM customer_payments WHERE id = ? AND customer_id = ? AND business_id = ? FOR UPDATE");
        $payment_stmt->execute([$delete_payment_id, $customer_id, $business_id]);
        $payment = $payment_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new Exception("Payment record not found.");
        }

        if (!empty($payment['is_deleted'])) {
            throw new Exception("This payment is already deleted.");
        }

        $alloc_stmt = $pdo->prepare("SELECT * FROM customer_payment_allocations WHERE payment_id = ? AND business_id = ? AND is_deleted = 0 ORDER BY id DESC FOR UPDATE");
        $alloc_stmt->execute([$delete_payment_id, $business_id]);
        $allocations = $alloc_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allocations as $allocation) {
            $allocation_type = $allocation['allocation_type'];
            $amount = (float)$allocation['allocated_amount'];

            if ($allocation_type === 'invoice' && !empty($allocation['invoice_id'])) {
                $invoice_stmt = $pdo->prepare("SELECT id, invoice_number, total, paid_amount, pending_amount, payment_status FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE");
                $invoice_stmt->execute([(int)$allocation['invoice_id'], $business_id]);
                $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);

                if ($invoice) {
                    $restored_paid = max(0, round((float)$invoice['paid_amount'] - $amount, 2));
                    [$restored_status, $restored_pending] = computeInvoiceStatus($invoice['total'], $restored_paid);

                    $update_invoice = $pdo->prepare("UPDATE invoices SET paid_amount = ?, pending_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ? AND business_id = ?");
                    $update_invoice->execute([$restored_paid, $restored_pending, $restored_status, $invoice['id'], $business_id]);
                }
            } elseif ($allocation_type === 'manual_credit' || $allocation_type === 'advance') {
                $before_type = $allocation['customer_outstanding_type_before'] ?: 'credit';
                $before_amount = (float)($allocation['customer_outstanding_amount_before'] ?? 0);
                setCustomerOutstanding($pdo, $customer_id, $business_id, $before_type, $before_amount);
            }
        }

        $mark_alloc_deleted = $pdo->prepare("UPDATE customer_payment_allocations SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, delete_reason = ?, updated_at = NOW() WHERE payment_id = ? AND business_id = ?");
        $mark_alloc_deleted->execute([$_SESSION['user_id'], $delete_reason, $delete_payment_id, $business_id]);

        // Soft delete parent customer payment.
        // Do not block with a manual tableHasColumn() check here. Some servers can return
        // false for SHOW COLUMNS even when the column exists, while the UPDATE itself works.
        // Try the full soft-delete update first, then retry with smaller safe updates.
        $softDeletedPayment = false;
        $softDeleteErrors = [];

        try {
            $soft_delete_payment = $pdo->prepare("
                UPDATE customer_payments
                SET is_deleted = 1,
                    deleted_at = NOW(),
                    deleted_by = ?,
                    delete_reason = ?
                WHERE id = ? AND business_id = ?
            ");
            $soft_delete_payment->execute([$_SESSION['user_id'], $delete_reason, $delete_payment_id, $business_id]);
            $softDeletedPayment = true;
        } catch (Exception $e) {
            $softDeleteErrors[] = $e->getMessage();
        }

        if (!$softDeletedPayment) {
            try {
                $soft_delete_payment = $pdo->prepare("
                    UPDATE customer_payments
                    SET is_deleted = 1,
                        deleted_at = NOW()
                    WHERE id = ? AND business_id = ?
                ");
                $soft_delete_payment->execute([$delete_payment_id, $business_id]);
                $softDeletedPayment = true;
            } catch (Exception $e) {
                $softDeleteErrors[] = $e->getMessage();
            }
        }

        if (!$softDeletedPayment) {
            try {
                $soft_delete_payment = $pdo->prepare("
                    UPDATE customer_payments
                    SET is_deleted = 1
                    WHERE id = ? AND business_id = ?
                ");
                $soft_delete_payment->execute([$delete_payment_id, $business_id]);
                $softDeletedPayment = true;
            } catch (Exception $e) {
                $softDeleteErrors[] = $e->getMessage();
            }
        }

        if (!$softDeletedPayment) {
            throw new Exception('Unable to soft delete customer payment. SQL error: ' . implode(' | ', $softDeleteErrors));
        }

        // No invoice_payments write/delete here. Invoice/customer restoration is handled using customer_payment_allocations only.

        logCustomerPaymentActivity(
            $pdo,
            (int)$business_id,
            (int)$_SESSION['user_id'],
            'customer_payment_deleted',
            'Customer payment was soft deleted and balances were restored.',
            [
                'payment_id' => $delete_payment_id,
                'customer_id' => $customer_id,
                'amount' => (float)$payment['total_amount'],
                'delete_reason' => $delete_reason,
                'allocations' => $allocations
            ]
        );

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $_SESSION['success'] = "Payment deleted successfully. Invoice/customer balances were restored.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['error'] = "Payment delete failed: " . $e->getMessage();
    }

    header("Location: customer_payment.php?customer_id=$customer_id");
    exit();
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $payment_mode = $_POST['payment_mode'] ?? 'bulk';
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $payment_method = normalizePaymentMethod($_POST['payment_method'] ?? 'cash');
    $reference_no = trim($_POST['reference_no'] ?? '');
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    if (($payment_mode ?? '') === 'delete_payment') {
        // delete_payment is handled above
    }

    if ($payment_amount <= 0) {
        $_SESSION['error'] = "Please enter a valid payment amount";
        header("Location: customer_payment.php?customer_id=$customer_id");
        exit();
    }

    try {
        $pdo->beginTransaction();

        $remaining_amount = round($payment_amount, 2);
        $payment_allocations = [];
        $manual_collected_total = 0.00;
        $invoice_collected_total = 0.00;
        $advance_collected_total = 0.00;

        // Create parent row with 0 first. After allocation, update total_amount.
        // For bulk/overall collection, total_amount stores invoice-applied amount only.
        // For separate outstanding collection, total_amount stores outstanding-collected amount.
        $payment_id = createCustomerPaymentRecord(
            $pdo,
            (int)$business_id,
            (int)$customer_id,
            0.00,
            $payment_method,
            $reference_no,
            $payment_date,
            $notes,
            (int)$_SESSION['user_id'],
            $payment_mode,
            $payment_mode === 'outstanding_only' ? 'outstanding' : 'invoice'
        );

        if ($payment_mode === 'outstanding_only') {
            // OUTSTANDING ONLY: reduce manual credit balance only. Do not touch invoices.
            $before_customer = getCustomerOutstandingForUpdate($pdo, $customer_id, $business_id);
            $current_type = $before_customer['outstanding_type'] ?? 'credit';
            $current_amount = (float)($before_customer['outstanding_amount'] ?? 0);

            if ($current_type !== 'credit' || $current_amount <= 0.01) {
                throw new Exception("This customer does not have manual outstanding to collect.");
            }

            if ($payment_amount > $current_amount + 0.009) {
                throw new Exception("Outstanding collection amount cannot exceed manual outstanding balance of ₹" . number_format($current_amount, 2));
            }

            $new_manual_amount = max(0, round($current_amount - $payment_amount, 2));
            setCustomerOutstanding($pdo, $customer_id, $business_id, 'credit', $new_manual_amount);

            $allocation_id = recordCustomerPaymentAllocation($pdo, [
                'payment_id' => $payment_id,
                'business_id' => $business_id,
                'customer_id' => $customer_id,
                'allocation_type' => 'manual_credit',
                'invoice_id' => null,
                'invoice_number' => null,
                'allocated_amount' => round($payment_amount, 2),
                'invoice_paid_before' => null,
                'invoice_paid_after' => null,
                'invoice_pending_before' => null,
                'invoice_pending_after' => null,
                'customer_outstanding_type_before' => $current_type,
                'customer_outstanding_amount_before' => $current_amount,
                'customer_outstanding_type_after' => 'credit',
                'customer_outstanding_amount_after' => $new_manual_amount,
                'description' => 'Separate collection towards manual outstanding balance',
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $manual_collected_total = round($payment_amount, 2);
            $payment_allocations[] = [
                'allocation_id' => $allocation_id,
                'type' => 'manual_credit',
                'amount' => $manual_collected_total,
                'before_amount' => $current_amount,
                'after_amount' => $new_manual_amount,
                'description' => 'Separate collection towards manual outstanding balance'
            ];

            updateCustomerPaymentStoredAmount($pdo, $payment_id, $business_id, $manual_collected_total, 'outstanding');
        } elseif ($payment_mode === 'bulk') {
            // OVERALL/BULK PAYMENT: First reduce manual outstanding, then FIFO on invoices.
            // customer_payments.total_amount is updated with invoice_collected_total only.
            if ($remaining_amount > 0) {
                $before_customer = getCustomerOutstandingForUpdate($pdo, $customer_id, $business_id);
                $current_type = $before_customer['outstanding_type'] ?? 'credit';
                $current_amount = (float)($before_customer['outstanding_amount'] ?? 0);

                if ($current_type === 'credit' && $current_amount > 0.01) {
                    $manual_reduction = min($remaining_amount, $current_amount);
                    $new_manual_amount = max(0, round($current_amount - $manual_reduction, 2));
                    setCustomerOutstanding($pdo, $customer_id, $business_id, 'credit', $new_manual_amount);

                    $allocation_id = recordCustomerPaymentAllocation($pdo, [
                        'payment_id' => $payment_id,
                        'business_id' => $business_id,
                        'customer_id' => $customer_id,
                        'allocation_type' => 'manual_credit',
                        'invoice_id' => null,
                        'invoice_number' => null,
                        'allocated_amount' => round($manual_reduction, 2),
                        'invoice_paid_before' => null,
                        'invoice_paid_after' => null,
                        'invoice_pending_before' => null,
                        'invoice_pending_after' => null,
                        'customer_outstanding_type_before' => $current_type,
                        'customer_outstanding_amount_before' => $current_amount,
                        'customer_outstanding_type_after' => 'credit',
                        'customer_outstanding_amount_after' => $new_manual_amount,
                        'description' => 'Overall payment first reduced manual outstanding balance',
                        'created_by' => $_SESSION['user_id'],
                        'created_at' => date('Y-m-d H:i:s')
                    ]);

                    $manual_collected_total = round($manual_collected_total + $manual_reduction, 2);
                    $payment_allocations[] = [
                        'allocation_id' => $allocation_id,
                        'type' => 'manual_credit',
                        'amount' => $manual_reduction,
                        'before_amount' => $current_amount,
                        'after_amount' => $new_manual_amount,
                        'description' => 'Overall payment first reduced manual outstanding balance'
                    ];

                    $remaining_amount = round($remaining_amount - $manual_reduction, 2);
                }
            }

            foreach ($outstanding_invoices as $invoice) {
                if ($remaining_amount <= 0.01) break;

                $invoice_stmt = $pdo->prepare("
                    SELECT id, invoice_number, total, paid_amount,
                           GREATEST(total - paid_amount, 0) AS outstanding
                    FROM invoices
                    WHERE id = ? AND customer_id = ? AND business_id = ?
                    FOR UPDATE
                ");
                $invoice_stmt->execute([(int)$invoice['id'], $customer_id, $business_id]);
                $locked_invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$locked_invoice) {
                    continue;
                }

                $invoice_outstanding = (float)$locked_invoice['outstanding'];
                if ($invoice_outstanding <= 0.01) {
                    continue;
                }

                $allocation = min($remaining_amount, $invoice_outstanding);
                $invoice_paid_before = (float)$locked_invoice['paid_amount'];
                $invoice_pending_before = (float)$locked_invoice['outstanding'];
                $new_paid = round($invoice_paid_before + $allocation, 2);
                [$new_status, $new_pending] = computeInvoiceStatus($locked_invoice['total'], $new_paid);

                $update_sql = "UPDATE invoices SET paid_amount = ?, pending_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ? AND business_id = ?";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([$new_paid, $new_pending, $new_status, $locked_invoice['id'], $business_id]);

                $allocation_id = recordCustomerPaymentAllocation($pdo, [
                    'payment_id' => $payment_id,
                    'business_id' => $business_id,
                    'customer_id' => $customer_id,
                    'allocation_type' => 'invoice',
                    'invoice_id' => $locked_invoice['id'],
                    'invoice_number' => $locked_invoice['invoice_number'],
                    'allocated_amount' => round($allocation, 2),
                    'invoice_paid_before' => $invoice_paid_before,
                    'invoice_paid_after' => $new_paid,
                    'invoice_pending_before' => $invoice_pending_before,
                    'invoice_pending_after' => $new_pending,
                    'customer_outstanding_type_before' => null,
                    'customer_outstanding_amount_before' => null,
                    'customer_outstanding_type_after' => null,
                    'customer_outstanding_amount_after' => null,
                    'description' => "Payment applied to invoice {$locked_invoice['invoice_number']}",
                    'created_by' => $_SESSION['user_id'],
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                // IMPORTANT: Do not insert into invoice_payments. Invoice tracking is inside customer_payments + allocations.
                $invoice_collected_total = round($invoice_collected_total + $allocation, 2);
                $payment_allocations[] = [
                    'allocation_id' => $allocation_id,
                    'type' => 'invoice',
                    'invoice_id' => $locked_invoice['id'],
                    'invoice_number' => $locked_invoice['invoice_number'],
                    'amount' => $allocation,
                    'paid_before' => $invoice_paid_before,
                    'paid_after' => $new_paid,
                    'pending_before' => $invoice_pending_before,
                    'pending_after' => $new_pending,
                    'description' => "Payment applied to invoice {$locked_invoice['invoice_number']}"
                ];

                $remaining_amount = round($remaining_amount - $allocation, 2);
            }

            if ($remaining_amount > 0.01) {
                $audit = applyAdvanceToCustomerWithAudit($pdo, $customer_id, $business_id, round($remaining_amount, 2));

                $allocation_id = recordCustomerPaymentAllocation($pdo, [
                    'payment_id' => $payment_id,
                    'business_id' => $business_id,
                    'customer_id' => $customer_id,
                    'allocation_type' => 'advance',
                    'invoice_id' => null,
                    'invoice_number' => null,
                    'allocated_amount' => round($remaining_amount, 2),
                    'invoice_paid_before' => null,
                    'invoice_paid_after' => null,
                    'invoice_pending_before' => null,
                    'invoice_pending_after' => null,
                    'customer_outstanding_type_before' => $audit['before_type'],
                    'customer_outstanding_amount_before' => $audit['before_amount'],
                    'customer_outstanding_type_after' => $audit['after_type'],
                    'customer_outstanding_amount_after' => $audit['after_amount'],
                    'description' => 'Excess payment stored as advance balance',
                    'created_by' => $_SESSION['user_id'],
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                $advance_collected_total = round($remaining_amount, 2);
                $payment_allocations[] = [
                    'allocation_id' => $allocation_id,
                    'type' => 'advance',
                    'amount' => $advance_collected_total,
                    'description' => 'Excess payment stored as advance balance'
                ];

                $remaining_amount = 0;
            }

            $stored_amount = $invoice_collected_total > 0.01 ? $invoice_collected_total : $manual_collected_total;
            $stored_type = $invoice_collected_total > 0.01 ? 'invoice' : 'outstanding';
            updateCustomerPaymentStoredAmount($pdo, $payment_id, $business_id, $stored_amount, $stored_type);
        } else {
            // INVOICE-WISE PAYMENT: Pay specific invoices only.
            // Do not use invoice_payments table.
            $selected_invoices = $_POST['selected_invoices'] ?? [];
            $invoice_amounts = $_POST['invoice_amounts'] ?? [];

            if (empty($selected_invoices)) {
                throw new Exception("Please select at least one invoice to pay");
            }

            $total_selected = 0;
            foreach ($selected_invoices as $inv_id) {
                $amount = floatval($invoice_amounts[$inv_id] ?? 0);
                if ($amount > 0) {
                    $total_selected += $amount;
                }
            }

            if (abs($total_selected - $payment_amount) > 0.009) {
                throw new Exception("Payment amount does not match sum of selected invoice payments");
            }

            foreach ($selected_invoices as $inv_id) {
                $amount = floatval($invoice_amounts[$inv_id] ?? 0);
                if ($amount <= 0) continue;

                $invoice_stmt = $pdo->prepare("
                    SELECT id, invoice_number, total, paid_amount,
                           GREATEST(total - paid_amount, 0) AS outstanding
                    FROM invoices
                    WHERE id = ? AND customer_id = ? AND business_id = ?
                    FOR UPDATE
                ");
                $invoice_stmt->execute([(int)$inv_id, $customer_id, $business_id]);
                $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$invoice) {
                    continue;
                }

                if ($amount > (float)$invoice['outstanding'] + 0.009) {
                    throw new Exception("Payment amount for invoice {$invoice['invoice_number']} exceeds outstanding balance");
                }

                $invoice_paid_before = (float)$invoice['paid_amount'];
                $invoice_pending_before = (float)$invoice['outstanding'];
                $new_paid = round($invoice_paid_before + $amount, 2);
                [$new_status, $new_pending] = computeInvoiceStatus($invoice['total'], $new_paid);

                $update_sql = "UPDATE invoices SET paid_amount = ?, pending_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ? AND business_id = ?";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([$new_paid, $new_pending, $new_status, $inv_id, $business_id]);

                $allocation_id = recordCustomerPaymentAllocation($pdo, [
                    'payment_id' => $payment_id,
                    'business_id' => $business_id,
                    'customer_id' => $customer_id,
                    'allocation_type' => 'invoice',
                    'invoice_id' => $inv_id,
                    'invoice_number' => $invoice['invoice_number'],
                    'allocated_amount' => round($amount, 2),
                    'invoice_paid_before' => $invoice_paid_before,
                    'invoice_paid_after' => $new_paid,
                    'invoice_pending_before' => $invoice_pending_before,
                    'invoice_pending_after' => $new_pending,
                    'customer_outstanding_type_before' => null,
                    'customer_outstanding_amount_before' => null,
                    'customer_outstanding_type_after' => null,
                    'customer_outstanding_amount_after' => null,
                    'description' => "Payment applied to invoice {$invoice['invoice_number']}",
                    'created_by' => $_SESSION['user_id'],
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                // IMPORTANT: Do not insert into invoice_payments.
                $invoice_collected_total = round($invoice_collected_total + $amount, 2);
                $payment_allocations[] = [
                    'allocation_id' => $allocation_id,
                    'type' => 'invoice',
                    'invoice_id' => $inv_id,
                    'invoice_number' => $invoice['invoice_number'],
                    'amount' => $amount,
                    'paid_before' => $invoice_paid_before,
                    'paid_after' => $new_paid,
                    'pending_before' => $invoice_pending_before,
                    'pending_after' => $new_pending,
                    'description' => "Payment applied to invoice {$invoice['invoice_number']}"
                ];
            }

            updateCustomerPaymentStoredAmount($pdo, $payment_id, $business_id, $invoice_collected_total, 'invoice');
        }

        logCustomerPaymentActivity(
            $pdo,
            (int)$business_id,
            (int)$_SESSION['user_id'],
            'customer_payment_created',
            'Customer payment recorded successfully.',
            [
                'payment_id' => $payment_id,
                'customer_id' => $customer_id,
                'payment_mode' => $payment_mode,
                'collected_amount' => $payment_amount,
                'stored_customer_payment_amount' => $payment_mode === 'outstanding_only'
                    ? $manual_collected_total
                    : ($invoice_collected_total > 0.01 ? $invoice_collected_total : $manual_collected_total),
                'manual_outstanding_collected' => $manual_collected_total,
                'invoice_amount_collected' => $invoice_collected_total,
                'advance_amount' => $advance_collected_total,
                'payment_method' => $payment_method,
                'reference_no' => $reference_no,
                'payment_date' => $payment_date,
                'allocations' => $payment_allocations
            ]
        );

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        if ($payment_mode === 'outstanding_only') {
            $_SESSION['success'] = "Outstanding amount of ₹" . number_format($manual_collected_total, 2) . " collected successfully!";
        } elseif ($payment_mode === 'bulk') {
            $_SESSION['success'] = "Overall payment of ₹" . number_format($payment_amount, 2) . " processed successfully! Invoice amount stored: ₹" . number_format($invoice_collected_total, 2);
        } else {
            $_SESSION['success'] = "Invoice payment of ₹" . number_format($invoice_collected_total, 2) . " recorded successfully!";
        }

        header("Location: customers.php");
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['error'] = "Payment failed: " . $e->getMessage();
        header("Location: customer_payment.php?customer_id=$customer_id");
        exit();
    }
}

// Get payment history with allocation summary
$history_sql = "
    SELECT cp.*, 
           COALESCE(u.full_name, 'System') AS recorded_by,
           (
               SELECT COUNT(*)
               FROM customer_payment_allocations cpa
               WHERE cpa.payment_id = cp.id
                 AND cpa.business_id = cp.business_id
                 AND cpa.allocation_type = 'invoice'
                 AND cpa.is_deleted = 0
           ) AS invoice_count,
           (
               SELECT GROUP_CONCAT(
                    CONCAT(
                        CASE
                            WHEN cpa.allocation_type = 'invoice' THEN COALESCE(cpa.invoice_number, CONCAT('Invoice #', cpa.invoice_id))
                            WHEN cpa.allocation_type = 'manual_credit' THEN 'Manual Credit'
                            WHEN cpa.allocation_type = 'advance' THEN 'Advance'
                            ELSE cpa.allocation_type
                        END,
                        ': ₹', FORMAT(cpa.allocated_amount, 2)
                    )
                    ORDER BY cpa.id ASC SEPARATOR '<br>'
               )
               FROM customer_payment_allocations cpa
               WHERE cpa.payment_id = cp.id
                 AND cpa.business_id = cp.business_id
                 AND cpa.is_deleted = 0
           ) AS allocation_details
    FROM customer_payments cp
    LEFT JOIN users u ON u.id = cp.created_by
    WHERE cp.customer_id = ?
      AND cp.business_id = ?
      AND COALESCE(cp.is_deleted, 0) = 0
    ORDER BY cp.created_at DESC
    LIMIT 20
";
$stmt = $pdo->prepare($history_sql);
$stmt->execute([$customer_id, $business_id]);
$payment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
$default_payment_tab = $manual_outstanding > 0.01 ? 'outstanding' : 'bulk';
?>
<!DOCTYPE html>
<html lang="en">
<?php $page_title = "Make Payment - " . htmlspecialchars($customer['name']); ?>
<?php include('includes/head.php'); ?>

<style>
    .avatar-sm { width: 48px; height: 48px; }
    .table th { font-weight: 600; background-color: #f8f9fa; }
    .card-hover { transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .card-hover:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important; }
    .border-start { border-left-width: 4px !important; }
    .invoice-row { transition: all 0.3s ease; }
    .invoice-row:hover { background-color: #f8f9fa; }
    .invoice-selected { background-color: #e8f5e9 !important; border-left: 4px solid #4caf50; }
    .payment-tabs-card .card-header { padding-bottom: 0; }
    .collection-note { border-left: 4px solid #0d6efd; }
    .form-section-title { font-size: 15px; font-weight: 600; color: #495057; margin-bottom: 14px; }
    @media (max-width: 768px) {
        .page-title-box { flex-direction: column; align-items: flex-start !important; gap: 12px; }
        .page-title-box .d-flex { width: 100%; flex-wrap: wrap; }
        .btn-group { flex-direction: column; }
        .btn-group .btn { margin-bottom: 5px; }
    }
</style>

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
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-rupee me-2"></i> Make Payment
                                <small class="text-muted ms-2">
                                    <i class="bx bx-user me-1"></i>
                                    <?= htmlspecialchars($customer['name']) ?>
                                </small>
                            </h4>
                            <div class="d-flex gap-2">
                                <a href="customer_statement.php?id=<?= $customer_id ?>" class="btn btn-outline-info">
                                    <i class="bx bx-file me-1"></i> View Statement
                                </a>
                                <a href="customers.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Customers
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-4 col-md-6">
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Outstanding</h6>
                                        <h3 class="mb-0 text-danger">₹<?= number_format(max(0, $total_outstanding), 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-error-circle text-danger"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted">Manual + invoice dues</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Manual Balance</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format(abs($manual_outstanding), 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-credit-card text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted"><?= $manual_outstanding > 0 ? 'Customer owes you' : ($manual_outstanding < 0 ? 'You owe customer' : 'Settled') ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Invoice Outstanding</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format(max(0, (float)$customer['total_outstanding']), 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-receipt text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted"><?= count($outstanding_invoices) ?> pending invoice(s)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Form -->
                <div class="card shadow-sm payment-tabs-card">
                    <div class="card-header bg-light">
                        <ul class="nav nav-tabs card-header-tabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link <?= $default_payment_tab === 'outstanding' ? 'active' : '' ?>" data-bs-toggle="tab" href="#outstandingPayment" role="tab">
                                    <i class="bx bx-wallet me-1"></i> Outstanding Collection
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $default_payment_tab === 'bulk' ? 'active' : '' ?>" data-bs-toggle="tab" href="#bulkPayment" role="tab">
                                    <i class="bx bx-money me-1"></i> Overall Collection
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $default_payment_tab === 'invoice' ? 'active' : '' ?>" data-bs-toggle="tab" href="#invoicePayment" role="tab">
                                    <i class="bx bx-receipt me-1"></i> Invoice-wise Payment
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <!-- Outstanding Collection Tab -->
                            <div class="tab-pane fade <?= $default_payment_tab === 'outstanding' ? 'show active' : '' ?>" id="outstandingPayment" role="tabpanel">
                                <form method="POST" id="outstandingPaymentForm">
                                    <input type="hidden" name="payment_mode" value="outstanding_only">

                                    <div class="alert alert-primary">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Outstanding Collection:</strong>
                                        This section collects only the customer's manual outstanding balance. It will not reduce invoice dues.
                                    </div>

                                    <div class="alert alert-light border collection-note mb-3">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <div>
                                                <small class="text-muted d-block mb-1">Manual Outstanding Available</small>
                                                <h4 class="mb-0 text-primary">₹<?= number_format(max(0, $manual_outstanding), 2) ?></h4>
                                                <small class="text-muted">
                                                    <?= $manual_outstanding > 0 ? 'Customer owes you' : ($manual_outstanding < 0 ? 'Advance/debit balance exists' : 'No manual outstanding') ?>
                                                </small>
                                            </div>
                                            <i class="bx bx-wallet fs-1 text-primary opacity-50"></i>
                                        </div>
                                    </div>

                                    <?php if ($manual_outstanding <= 0.01): ?>
                                        <div class="alert alert-warning mb-0">
                                            <i class="bx bx-info-circle me-2"></i>
                                            No manual outstanding balance is available to collect separately.
                                        </div>
                                    <?php else: ?>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Outstanding Amount <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₹</span>
                                                        <input type="number" name="payment_amount" id="outstanding_amount"
                                                               class="form-control form-control-lg"
                                                               step="0.01" min="0.01" max="<?= max(0, $manual_outstanding) ?>" required
                                                               placeholder="Enter outstanding amount">
                                                    </div>
                                                    <small class="text-muted">Maximum: ₹<?= number_format(max(0, $manual_outstanding), 2) ?></small>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Payment Date</label>
                                                    <input type="date" name="payment_date" class="form-control"
                                                           value="<?= date('Y-m-d') ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Payment Method</label>
                                                    <select name="payment_method" id="outstanding_payment_method" class="form-select" required>
                                                        <option value="cash">Cash</option>
                                                        <option value="upi">UPI</option>
                                                        <option value="bank">Bank Transfer</option>
                                                        <option value="cheque">Cheque</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6" id="outstanding_reference_div" style="display: none;">
                                                <div class="mb-3">
                                                    <label class="form-label">Reference Number</label>
                                                    <input type="text" name="reference_no" class="form-control"
                                                           placeholder="UPI Ref / Cheque No / Transaction ID">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Notes (Optional)</label>
                                            <textarea name="notes" class="form-control" rows="2"
                                                      placeholder="Any additional notes about this outstanding collection"></textarea>
                                        </div>

                                        <div class="mt-3">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bx bx-check-circle me-2"></i> Collect Outstanding Amount
                                            </button>
                                            <button type="reset" class="btn btn-secondary btn-lg">
                                                <i class="bx bx-reset me-2"></i> Reset
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            </div>

                            <!-- Bulk Payment Tab -->
                            <div class="tab-pane fade <?= $default_payment_tab === 'bulk' ? 'show active' : '' ?>" id="bulkPayment" role="tabpanel">
                                <form method="POST" id="bulkPaymentForm">
                                    <input type="hidden" name="payment_mode" value="bulk">
                                    
                                    <div class="alert alert-info">
                                        <i class="bx bx-info-circle me-2"></i>
                                        <strong>Overall Collection Allocation Rules:</strong>
                                        <ol class="mb-0 mt-2">
                                            <li>First, manual outstanding balance will be reduced</li>
                                            <li>Remaining amount will be applied to oldest invoices first (FIFO order)</li>
                                            <li>Only the invoice-applied amount is stored in <code>customer_payments.total_amount</code> for overall collection</li>
                                            <li>No entry is created in the <code>invoice_payments</code> table</li>
                                        </ol>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" name="payment_amount" id="bulk_amount" 
                                                           class="form-control form-control-lg" 
                                                           step="0.01" min="0.01" required
                                                           placeholder="Enter amount">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Date</label>
                                                <input type="date" name="payment_date" class="form-control" 
                                                       value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Method</label>
                                                <select name="payment_method" id="bulk_payment_method" class="form-select" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="upi">UPI</option>
                                                    <option value="bank">Bank Transfer</option>
                                                    <option value="cheque">Cheque</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="bulk_reference_div" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label">Reference Number</label>
                                                <input type="text" name="reference_no" class="form-control" 
                                                       placeholder="UPI Ref / Cheque No / Transaction ID">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Notes (Optional)</label>
                                        <textarea name="notes" class="form-control" rows="2" 
                                                  placeholder="Any additional notes about this payment"></textarea>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="bx bx-check-circle me-2"></i> Process Overall Collection
                                        </button>
                                        <button type="reset" class="btn btn-secondary btn-lg">
                                            <i class="bx bx-reset me-2"></i> Reset
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Invoice-wise Payment Tab -->
                            <div class="tab-pane fade <?= $default_payment_tab === 'invoice' ? 'show active' : '' ?>" id="invoicePayment" role="tabpanel">
                                <form method="POST" id="invoicePaymentForm">
                                    <input type="hidden" name="payment_mode" value="invoice_wise">
                                    
                                    <?php if (empty($outstanding_invoices)): ?>
                                    <div class="alert alert-warning">
                                        <i class="bx bx-info-circle me-2"></i>
                                        No outstanding invoices found for this customer.
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="50">
                                                        <input type="checkbox" id="select_all_invoices">
                                                    </th>
                                                    <th>Invoice #</th>
                                                    <th>Date</th>
                                                    <th class="text-end">Total Amount</th>
                                                    <th class="text-end">Paid Amount</th>
                                                    <th class="text-end">Outstanding</th>
                                                    <th class="text-end">Payment Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($outstanding_invoices as $inv): ?>
                                                <tr class="invoice-row" data-invoice-id="<?= $inv['id'] ?>">
                                                    <td>
                                                        <input type="checkbox" name="selected_invoices[]" 
                                                               value="<?= $inv['id'] ?>" class="invoice-checkbox">
                                                    </td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong>
                                                        <br>
                                                        <small class="text-muted">ID: #<?= $inv['id'] ?></small>
                                                    </td>
                                                    <td><?= date('d M Y', strtotime($inv['created_at'])) ?></td>
                                                    <td class="text-end">₹<?= number_format($inv['total'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($inv['paid_amount'], 2) ?></td>
                                                    <td class="text-end text-danger fw-bold">
                                                        ₹<?= number_format($inv['outstanding'], 2) ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text">₹</span>
                                                            <input type="number" name="invoice_amounts[<?= $inv['id'] ?>]" 
                                                                   class="form-control invoice-amount" 
                                                                   data-max="<?= $inv['outstanding'] ?>"
                                                                   step="0.01" min="0" max="<?= $inv['outstanding'] ?>"
                                                                   placeholder="Amount" disabled>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr>
                                                    <td colspan="6" class="text-end fw-bold">Total Payment:</td>
                                                    <td class="text-end">
                                                        <div class="input-group">
                                                            <span class="input-group-text">₹</span>
                                                            <input type="number" name="payment_amount" id="total_payment_amount" 
                                                                   class="form-control" step="0.01" min="0" readonly
                                                                   placeholder="0.00">
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Date</label>
                                                <input type="date" name="payment_date" class="form-control" 
                                                       value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Method</label>
                                                <select name="payment_method" id="invoice_payment_method" class="form-select" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="upi">UPI</option>
                                                    <option value="bank">Bank Transfer</option>
                                                    <option value="cheque">Cheque</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6" id="invoice_reference_div" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label">Reference Number</label>
                                                <input type="text" name="reference_no" class="form-control" 
                                                       placeholder="UPI Ref / Cheque No / Transaction ID">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Notes (Optional)</label>
                                                <input type="text" name="notes" class="form-control" 
                                                       placeholder="Any notes about this payment">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="submit" class="btn btn-success btn-lg" id="submit_invoice_payment">
                                            <i class="bx bx-check-circle me-2"></i> Process Selected Payments
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-lg" onclick="resetInvoiceSelection()">
                                            <i class="bx bx-reset me-2"></i> Reset
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment History -->
                <?php if (!empty($payment_history)): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-history me-2"></i> Recent Payment History
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Allocation Details</th>
                                        <th>Notes</th>
                                        <th>Recorded By</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment_history as $payment): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($payment['payment_date'])) ?></td>
                                        <td class="text-success fw-bold">₹<?= number_format($payment['total_amount'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-info bg-opacity-10 text-info">
                                                <?= ucfirst($payment['payment_method']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($payment['reference_no'] ?: '—') ?></td>
                                        <td>
                                            <?php if (!empty($payment['allocation_details'])): ?>
                                                <div class="small"><?= $payment['allocation_details'] ?></div>
                                                <?php if ((int)$payment['invoice_count'] > 0): ?>
                                                    <span class="badge bg-primary mt-1"><?= (int)$payment['invoice_count'] ?> invoice(s)</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No allocation rows</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($payment['notes'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($payment['recorded_by'] ?? 'System') ?></td>
                                        <td class="text-center">
                                            <form method="POST" class="delete-payment-form d-inline">
                                                <input type="hidden" name="action" value="delete_payment">
                                                <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                                                <input type="hidden" name="delete_reason" value="Soft deleted from customer payment page">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        data-amount="<?= number_format((float)$payment['total_amount'], 2, '.', '') ?>"
                                                        data-date="<?= htmlspecialchars(date('d M Y', strtotime($payment['payment_date'])), ENT_QUOTES) ?>">
                                                    <i class="bx bx-trash"></i> Delete
                                                </button>
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

<script>
function toggleReferenceField(method, prefix) {
    if (method === 'upi' || method === 'bank' || method === 'cheque') {
        $('#' + prefix + '_reference_div').show();
    } else {
        $('#' + prefix + '_reference_div').hide();
        $('#' + prefix + '_reference_div').find('input[name="reference_no"]').val('');
    }
}

function resetInvoiceSelection() {
    $('.invoice-checkbox').prop('checked', false).trigger('change');
    $('#select_all_invoices').prop('checked', false).prop('indeterminate', false);
    $('.invoice-amount').val('').prop('disabled', true);
    $('#total_payment_amount').val('');
    $('#invoice_reference_div').find('input[name="reference_no"]').val('');
    $('#invoicePaymentForm').find('input[name="notes"]').val('');
    $('#invoice_payment_method').val('cash');
    toggleReferenceField('cash', 'invoice');
}

$(document).ready(function() {
    $('#outstanding_payment_method').on('change', function() {
        toggleReferenceField($(this).val(), 'outstanding');
    });

    $('#bulk_payment_method').on('change', function() {
        toggleReferenceField($(this).val(), 'bulk');
    });

    $('#invoice_payment_method').on('change', function() {
        toggleReferenceField($(this).val(), 'invoice');
    });

    toggleReferenceField($('#outstanding_payment_method').val(), 'outstanding');
    toggleReferenceField($('#bulk_payment_method').val(), 'bulk');
    toggleReferenceField($('#invoice_payment_method').val(), 'invoice');

    $('#select_all_invoices').on('change', function() {
        const isChecked = $(this).is(':checked');
        $('.invoice-checkbox').prop('checked', isChecked).trigger('change');
    });

    function updateTotalPayment() {
        let total = 0;
        $('.invoice-amount:not(:disabled)').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        $('#total_payment_amount').val(total.toFixed(2));
    }

    function updateSelectAllCheckbox() {
        const totalCheckboxes = $('.invoice-checkbox').length;
        const checkedCheckboxes = $('.invoice-checkbox:checked').length;

        if (checkedCheckboxes === 0) {
            $('#select_all_invoices').prop('checked', false).prop('indeterminate', false);
        } else if (checkedCheckboxes === totalCheckboxes) {
            $('#select_all_invoices').prop('checked', true).prop('indeterminate', false);
        } else {
            $('#select_all_invoices').prop('checked', false).prop('indeterminate', true);
        }
    }

    $('.invoice-checkbox').on('change', function() {
        const invoiceRow = $(this).closest('tr');
        const amountInput = invoiceRow.find('.invoice-amount');

        if ($(this).is(':checked')) {
            invoiceRow.addClass('invoice-selected');
            amountInput.prop('disabled', false);
            const maxAmount = parseFloat(amountInput.data('max')) || 0;
            amountInput.val(maxAmount.toFixed(2)).focus();
        } else {
            invoiceRow.removeClass('invoice-selected');
            amountInput.prop('disabled', true).val('');
        }

        updateTotalPayment();
        updateSelectAllCheckbox();
    });

    $('.invoice-amount').on('input', function() {
        let value = parseFloat($(this).val()) || 0;
        const maxValue = parseFloat($(this).data('max')) || 0;

        if (value > maxValue) {
            value = maxValue;
            $(this).val(maxValue.toFixed(2));
            Swal.fire({
                icon: 'warning',
                title: 'Amount Exceeds Outstanding',
                text: `Payment cannot exceed outstanding amount of ₹${maxValue.toFixed(2)}`,
                timer: 2000,
                showConfirmButton: false
            });
        }

        if (value < 0) {
            $(this).val('0.00');
        }

        updateTotalPayment();
    });

    $('#outstandingPaymentForm').on('submit', function(e) {
        if ($(this).data('confirmed') === true) {
            return true;
        }

        e.preventDefault();
        const form = this;
        const amount = parseFloat($('#outstanding_amount').val()) || 0;
        const maxOutstanding = <?= json_encode(max(0, $manual_outstanding)) ?>;

        if (amount <= 0) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Amount',
                text: 'Please enter a valid outstanding amount greater than 0'
            });
            return false;
        }

        if (amount > maxOutstanding) {
            Swal.fire({
                icon: 'warning',
                title: 'Amount Exceeds Manual Outstanding',
                text: `Outstanding collection cannot exceed ₹${Number(maxOutstanding).toFixed(2)}`
            });
            return false;
        }

        Swal.fire({
            title: 'Confirm Outstanding Collection',
            html: `Collect manual outstanding amount of <strong>₹${amount.toFixed(2)}</strong>?<br><br><small>This will only reduce customer manual outstanding. It will not adjust invoices.</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, collect outstanding',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $(form).data('confirmed', true);
                form.submit();
            }
        });
    });

    $('#invoicePaymentForm').on('submit', function(e) {
        if ($(this).data('confirmed') === true) {
            return true;
        }

        e.preventDefault();
        const form = this;
        const totalPayment = parseFloat($('#total_payment_amount').val()) || 0;

        if (totalPayment <= 0) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Payment',
                text: 'Please select at least one invoice and enter a valid payment amount'
            });
            return false;
        }

        Swal.fire({
            title: 'Confirm Payment',
            html: `Are you sure you want to process payment of <strong>₹${totalPayment.toFixed(2)}</strong>?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, process payment',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $(form).data('confirmed', true);
                form.submit();
            }
        });
    });

    $('#bulkPaymentForm').on('submit', function(e) {
        if ($(this).data('confirmed') === true) {
            return true;
        }

        e.preventDefault();
        const form = this;
        const amount = parseFloat($('#bulk_amount').val()) || 0;

        if (amount <= 0) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Amount',
                text: 'Please enter a valid payment amount greater than 0'
            });
            return false;
        }

        Swal.fire({
            title: 'Confirm Overall Collection',
            html: `Are you sure you want to process overall collection of <strong>₹${amount.toFixed(2)}</strong>?<br><br><small>This will first reduce manual outstanding, then apply to oldest invoices first. Only invoice-applied amount is stored as invoice collection. Any extra amount will be stored as advance.</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, process payment',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $(form).data('confirmed', true);
                form.submit();
            }
        });
    });

    $('#bulk_amount').on('input', function() {
        const value = parseFloat($(this).val()) || 0;
        const totalOutstanding = <?= json_encode(max(0, $total_outstanding)) ?>;

        if (value > totalOutstanding && totalOutstanding > 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Amount Exceeds Outstanding',
                text: `Payment amount exceeds total outstanding balance of ₹${Number(totalOutstanding).toFixed(2)}. Extra amount will be stored as advance.`,
                timer: 3000,
                showConfirmButton: true
            });
        }
    });

    $('.delete-payment-form').on('submit', function(e) {
        e.preventDefault();
        const form = this;
        const amount = $(form).find('button').data('amount');
        const date = $(form).find('button').data('date');

        Swal.fire({
            title: 'Delete payment?',
            html: `This will soft delete the payment of <strong>₹${amount}</strong> dated <strong>${date}</strong> and restore invoice/customer balances.`,
            icon: 'warning',
            input: 'text',
            inputLabel: 'Delete reason',
            inputValue: 'Soft deleted from customer payment page',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete and restore',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $(form).find('input[name="delete_reason"]').val(result.value || 'Soft deleted from customer payment page');
                form.submit();
            }
        });
    });
});
</script>

</body>
</html>
