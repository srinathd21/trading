<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'shop_manager'])) {
    header('Location: login.php');
    exit();
}

$user_id     = (int)($_SESSION['user_id'] ?? 0);
$business_id = (int)($_SESSION['business_id'] ?? 1);
$success     = '';
$error       = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function format_money($amount) {
    return number_format((float)$amount, 2);
}

function table_exists(PDO $pdo, $tableName) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ");
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function get_columns(PDO $pdo, $tableName) {
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ?
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([$tableName]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

function first_existing_table(PDO $pdo, array $candidates) {
    foreach ($candidates as $candidate) {
        if (table_exists($pdo, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function first_existing_column(array $columns, array $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function normalize_status_badge($status) {
    $status = strtolower(trim((string)$status));

    $map = [
        'pending'          => ['warning', 'Pending'],
        'new'              => ['warning', 'New'],
        'confirmed'        => ['info', 'Confirmed'],
        'accepted'         => ['info', 'Accepted'],
        'processing'       => ['primary', 'Processing'],
        'process'          => ['primary', 'Processing'],
        'packed'           => ['primary', 'Packed'],
        'packing'          => ['primary', 'Packing'],
        'shipped'          => ['info', 'Shipped'],
        'out_for_delivery' => ['info', 'Out For Delivery'],
        'delivered'        => ['success', 'Delivered'],
        'completed'        => ['success', 'Completed'],
        'cancelled'        => ['danger', 'Cancelled'],
        'canceled'         => ['danger', 'Cancelled'],
        'returned'         => ['dark', 'Returned'],
        'failed'           => ['danger', 'Failed'],
        'paid'             => ['success', 'Paid'],
        'partial'          => ['warning', 'Partial'],
        'unpaid'           => ['danger', 'Unpaid'],
    ];

    return $map[$status] ?? ['secondary', ucfirst($status !== '' ? str_replace('_', ' ', $status) : 'Unknown')];
}

$orders = [];
$stats = [
    'total_orders'     => 0,
    'pending_orders'   => 0,
    'completed_orders' => 0,
    'total_sales'      => 0
];
$totalRows  = 0;
$totalPages = 1;
$page       = 1;
$storeInfo  = [
    'store_title'   => 'Online Store',
    'display_name'  => 'Online Store',
    'store_status'  => 'draft',
    'support_phone' => '',
    'support_email' => '',
    'address'       => '',
    'theme_color'   => '#0d6efd'
];

try {
    if (!empty($_SESSION['flash_success'])) {
        $success = $_SESSION['flash_success'];
        unset($_SESSION['flash_success']);
    }

    if (!empty($_SESSION['flash_error'])) {
        $error = $_SESSION['flash_error'];
        unset($_SESSION['flash_error']);
    }

    // Store info
    if (table_exists($pdo, 'online_store_setup')) {
        $setupCols = get_columns($pdo, 'online_store_setup');
        $setupBusinessCol = first_existing_column($setupCols, ['business_id']);
        if ($setupBusinessCol) {
            $stmtStore = $pdo->prepare("SELECT * FROM online_store_setup WHERE `$setupBusinessCol` = ? LIMIT 1");
            $stmtStore->execute([$business_id]);
            $setupRow = $stmtStore->fetch(PDO::FETCH_ASSOC);
            if ($setupRow) {
                $storeInfo = array_merge($storeInfo, $setupRow);
            }
        }
    }

    if (table_exists($pdo, 'online_store_settings')) {
        $settingsCols = get_columns($pdo, 'online_store_settings');
        $settingsBusinessCol = first_existing_column($settingsCols, ['business_id']);
        if ($settingsBusinessCol) {
            $stmtStore = $pdo->prepare("SELECT * FROM online_store_settings WHERE `$settingsBusinessCol` = ? LIMIT 1");
            $stmtStore->execute([$business_id]);
            $settingsRow = $stmtStore->fetch(PDO::FETCH_ASSOC);
            if ($settingsRow) {
                $storeInfo = array_merge($storeInfo, $settingsRow);
            }
        }
    }

    $orderTable = first_existing_table($pdo, [
        'online_store_orders',
        'orders',
        'customer_orders',
        'sales_orders',
        'invoices'
    ]);

    $customerTable = first_existing_table($pdo, [
        'customers',
        'customer_master',
        'buyers'
    ]);

    $paymentTable = first_existing_table($pdo, [
        'payments',
        'invoice_payments'
    ]);

    if (!$orderTable) {
        throw new Exception('No order table found. Create one of these tables: online_store_orders, orders, customer_orders, sales_orders, or use invoices.');
    }

    $orderCols    = get_columns($pdo, $orderTable);
    $customerCols = $customerTable ? get_columns($pdo, $customerTable) : [];
    $paymentCols  = $paymentTable ? get_columns($pdo, $paymentTable) : [];

    $orderPkCol       = first_existing_column($orderCols, ['id', 'order_id', 'invoice_id']);
    $orderNoCol       = first_existing_column($orderCols, ['order_number', 'order_no', 'invoice_number', 'invoice_no', 'reference_no']);
    $orderDateCol     = first_existing_column($orderCols, ['order_date', 'created_at', 'invoice_date', 'date']);
    $orderStatusCol   = first_existing_column($orderCols, ['order_status', 'status', 'delivery_status']);
    $paymentStatusCol = first_existing_column($orderCols, ['payment_status', 'status_payment']);
    $totalCol         = first_existing_column($orderCols, ['grand_total', 'total_amount', 'net_amount', 'final_amount', 'amount', 'total']);
    $paidCol          = first_existing_column($orderCols, ['paid_amount', 'amount_paid']);
    $pendingCol       = first_existing_column($orderCols, ['pending_amount', 'balance_amount', 'due_amount']);
    $customerIdCol    = first_existing_column($orderCols, ['customer_id', 'buyer_id']);
    $businessIdCol    = first_existing_column($orderCols, ['business_id']);
    $addressCol       = first_existing_column($orderCols, ['delivery_address', 'shipping_address', 'address']);
    $phoneColInOrder  = first_existing_column($orderCols, ['customer_phone', 'phone', 'mobile', 'mobile_number']);
    $nameColInOrder   = first_existing_column($orderCols, ['customer_name', 'buyer_name', 'name']);
    $sourceCol        = first_existing_column($orderCols, ['order_source', 'source', 'channel']);

    $customerPkCol      = first_existing_column($customerCols, ['id', 'customer_id', 'buyer_id']);
    $customerNameCol    = first_existing_column($customerCols, ['customer_name', 'name', 'full_name']);
    $customerPhoneCol   = first_existing_column($customerCols, ['phone', 'mobile', 'mobile_number', 'customer_phone']);
    $customerAddressCol = first_existing_column($customerCols, ['address', 'billing_address', 'shipping_address']);

    $paymentRefCol    = first_existing_column($paymentCols, ['reference_id', 'invoice_id', 'order_id']);
    $paymentAmountCol = first_existing_column($paymentCols, ['amount', 'paid_amount']);
    $paymentTypeCol   = first_existing_column($paymentCols, ['type']);
    $paymentDateCol   = first_existing_column($paymentCols, ['payment_date', 'created_at']);

    $search        = trim($_GET['search'] ?? '');
    $statusFilter  = trim($_GET['status'] ?? '');
    $paymentFilter = trim($_GET['payment_status'] ?? '');
    $dateFrom      = trim($_GET['date_from'] ?? '');
    $dateTo        = trim($_GET['date_to'] ?? '');
    $perPage       = 20;
    $page          = max(1, (int)($_GET['page'] ?? 1));
    $offset        = ($page - 1) * $perPage;

    $where  = [];
    $params = [];

    if ($businessIdCol) {
        $where[] = "o.`$businessIdCol` = :business_id";
        $params[':business_id'] = $business_id;
    }

    if ($sourceCol) {
        $where[] = "(LOWER(COALESCE(o.`$sourceCol`, '')) IN ('online','online_store','ecommerce','e-commerce','website','web','store') OR o.`$sourceCol` IS NULL OR o.`$sourceCol` = '')";
    }

    if ($statusFilter !== '' && $orderStatusCol) {
        $where[] = "LOWER(COALESCE(o.`$orderStatusCol`, '')) = :status_filter";
        $params[':status_filter'] = strtolower($statusFilter);
    }

    if ($paymentFilter !== '' && $paymentStatusCol) {
        $where[] = "LOWER(COALESCE(o.`$paymentStatusCol`, '')) = :payment_filter";
        $params[':payment_filter'] = strtolower($paymentFilter);
    }

    if ($dateFrom !== '' && $orderDateCol) {
        $where[] = "DATE(o.`$orderDateCol`) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }

    if ($dateTo !== '' && $orderDateCol) {
        $where[] = "DATE(o.`$orderDateCol`) <= :date_to";
        $params[':date_to'] = $dateTo;
    }

    $customerJoin = '';
    $customerJoined = false;
    if ($customerTable && $customerPkCol && $customerIdCol) {
        $customerJoin = " LEFT JOIN `$customerTable` c ON c.`$customerPkCol` = o.`$customerIdCol` ";
        $customerJoined = true;
    }

    if ($search !== '') {
        $searchParts = [];

        if ($orderNoCol) {
            $searchParts[] = "o.`$orderNoCol` LIKE :search";
        }
        if ($nameColInOrder) {
            $searchParts[] = "o.`$nameColInOrder` LIKE :search";
        }
        if ($phoneColInOrder) {
            $searchParts[] = "o.`$phoneColInOrder` LIKE :search";
        }
        if ($customerJoined && $customerNameCol) {
            $searchParts[] = "c.`$customerNameCol` LIKE :search";
        }
        if ($customerJoined && $customerPhoneCol) {
            $searchParts[] = "c.`$customerPhoneCol` LIKE :search";
        }

        if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
            $params[':search'] = '%' . $search . '%';
        }
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $paymentJoin = '';
    $paymentSelect = "0.00 AS total_payment_received, NULL AS last_payment_date";
    if ($paymentTable && $paymentRefCol && $paymentAmountCol) {
        if ($paymentTypeCol && $orderTable === 'invoices') {
            $paymentJoin = "
                LEFT JOIN (
                    SELECT `$paymentRefCol` AS ref_id,
                           SUM(`$paymentAmountCol`) AS total_payment_received,
                           MAX(`" . ($paymentDateCol ?: $paymentAmountCol) . "`) AS last_payment_date
                    FROM `$paymentTable`
                    WHERE LOWER(COALESCE(`$paymentTypeCol`, '')) = 'customer'
                    GROUP BY `$paymentRefCol`
                ) pay ON pay.ref_id = o.`$orderPkCol`
            ";
        } else {
            $paymentJoin = "
                LEFT JOIN (
                    SELECT `$paymentRefCol` AS ref_id,
                           SUM(`$paymentAmountCol`) AS total_payment_received,
                           MAX(`" . ($paymentDateCol ?: $paymentAmountCol) . "`) AS last_payment_date
                    FROM `$paymentTable`
                    GROUP BY `$paymentRefCol`
                ) pay ON pay.ref_id = o.`$orderPkCol`
            ";
        }
        $paymentSelect = "COALESCE(pay.total_payment_received, 0) AS total_payment_received, pay.last_payment_date";
    }

    // SAFE SELECT PARTS: no hardcoded c.name
    if ($nameColInOrder) {
        $customerNameSelect = "COALESCE(NULLIF(o.`$nameColInOrder`, ''), '-')";
        if ($customerJoined && $customerNameCol) {
            $customerNameSelect = "COALESCE(NULLIF(o.`$nameColInOrder`, ''), c.`$customerNameCol`, '-')";
        }
    } else {
        $customerNameSelect = "'-'";
        if ($customerJoined && $customerNameCol) {
            $customerNameSelect = "COALESCE(c.`$customerNameCol`, '-')";
        }
    }

    if ($phoneColInOrder) {
        $customerPhoneSelect = "COALESCE(o.`$phoneColInOrder`, '')";
    } else {
        $customerPhoneSelect = "''";
        if ($customerJoined && $customerPhoneCol) {
            $customerPhoneSelect = "COALESCE(c.`$customerPhoneCol`, '')";
        }
    }

    if ($addressCol) {
        $addressSelect = "COALESCE(o.`$addressCol`, '')";
    } else {
        $addressSelect = "''";
        if ($customerJoined && $customerAddressCol) {
            $addressSelect = "COALESCE(c.`$customerAddressCol`, '')";
        }
    }

    $selectSql = "
        SELECT
            o.`$orderPkCol` AS order_id,
            " . ($orderNoCol ? "COALESCE(o.`$orderNoCol`, CONCAT('ORD-', o.`$orderPkCol`))" : "CONCAT('ORD-', o.`$orderPkCol`)") . " AS order_number,
            " . ($orderDateCol ? "o.`$orderDateCol`" : "NULL") . " AS order_date,
            " . ($orderStatusCol ? "COALESCE(o.`$orderStatusCol`, 'pending')" : "'pending'") . " AS order_status,
            " . ($paymentStatusCol ? "COALESCE(o.`$paymentStatusCol`, '')" : "''") . " AS payment_status,
            " . ($totalCol ? "COALESCE(o.`$totalCol`, 0)" : "0") . " AS total_amount,
            " . ($paidCol ? "COALESCE(o.`$paidCol`, 0)" : "NULL") . " AS paid_amount_raw,
            " . ($pendingCol ? "COALESCE(o.`$pendingCol`, 0)" : "NULL") . " AS pending_amount_raw,
            $customerNameSelect AS customer_name_display,
            $customerPhoneSelect AS customer_phone_display,
            $addressSelect AS address_display,
            $paymentSelect
        FROM `$orderTable` o
        $customerJoin
        $paymentJoin
        $whereSql
    ";

    $countSql = "SELECT COUNT(*) FROM ($selectSql) x";
    $stmtCount = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    $finalSql = $selectSql . " ORDER BY order_date DESC, order_id DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($finalSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orders as &$row) {
        $totalAmount = (float)($row['total_amount'] ?? 0);
        $paidAmount  = $row['paid_amount_raw'] !== null ? (float)$row['paid_amount_raw'] : (float)($row['total_payment_received'] ?? 0);

        if ($row['pending_amount_raw'] !== null) {
            $pendingAmount = (float)$row['pending_amount_raw'];
        } else {
            $pendingAmount = max(0, $totalAmount - $paidAmount);
        }

        $row['paid_amount'] = $paidAmount;
        $row['pending_amount'] = $pendingAmount;
    }
    unset($row);

    $statsSql = "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN LOWER(COALESCE(order_status, '')) IN ('pending','new','confirmed') THEN 1 ELSE 0 END) AS pending_orders,
            SUM(CASE WHEN LOWER(COALESCE(order_status, '')) IN ('delivered','completed') THEN 1 ELSE 0 END) AS completed_orders,
            SUM(COALESCE(total_amount, 0)) AS total_sales
        FROM (
            SELECT
                " . ($orderStatusCol ? "COALESCE(o.`$orderStatusCol`, 'pending')" : "'pending'") . " AS order_status,
                " . ($totalCol ? "COALESCE(o.`$totalCol`, 0)" : "0") . " AS total_amount
            FROM `$orderTable` o
            $whereSql
        ) s
    ";

    $stmtStats = $pdo->prepare($statsSql);
    foreach ($params as $key => $value) {
        $stmtStats->bindValue($key, $value);
    }
    $stmtStats->execute();
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Online Store Orders"; include 'includes/head.php'; ?>
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

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bx bx-check-circle me-2"></i><?= h($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bx bx-error-circle me-2"></i><?= h($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row mb-4">
                    <div class="col-xl-8">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-body">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle me-3 online-icon-wrap">
                                            <i class="bx bx-cart fs-3"></i>
                                        </div>
                                        <div>
                                            <h4 class="mb-1">Online Store Orders</h4>
                                            <p class="text-muted mb-0">
                                                Manage online customer orders in the same UI template.
                                                <span class="d-block small mt-1">
                                                    Store: <strong><?= h($storeInfo['store_title'] ?? $storeInfo['display_name'] ?? 'Online Store') ?></strong>
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-md-end">
                                        <div class="small text-muted">Business ID</div>
                                        <div class="fw-semibold"><?= (int)$business_id ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 mt-3 mt-xl-0">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h5 class="mb-0"><i class="bx bx-store me-2"></i>Store Status</h5>
                                    <?php
                                    $storeStatus = strtolower((string)($storeInfo['store_status'] ?? $storeInfo['maintenance_mode'] ?? 'draft'));
                                    $isLive = in_array($storeStatus, ['live', 'active', '0'], true);
                                    ?>
                                    <?php if ($isLive): ?>
                                        <span class="badge bg-success">Live</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><?= h(ucfirst($storeStatus ?: 'Draft')) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="mb-1"><strong>Support:</strong> <?= h($storeInfo['support_phone'] ?? '-') ?></p>
                                <p class="mb-1"><strong>Email:</strong> <?= h($storeInfo['support_email'] ?? '-') ?></p>
                                <p class="mb-0"><strong>Address:</strong> <?= h($storeInfo['address'] ?? '-') ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card shadow-sm stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-soft-primary text-primary">
                                        <i class="bx bx-package"></i>
                                    </div>
                                    <div class="ms-3">
                                        <p class="text-muted mb-1">Total Orders</p>
                                        <h4 class="mb-0"><?= (int)($stats['total_orders'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mt-3 mt-md-0">
                        <div class="card shadow-sm stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-soft-warning text-warning">
                                        <i class="bx bx-time-five"></i>
                                    </div>
                                    <div class="ms-3">
                                        <p class="text-muted mb-1">Pending Orders</p>
                                        <h4 class="mb-0"><?= (int)($stats['pending_orders'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mt-3 mt-xl-0">
                        <div class="card shadow-sm stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-soft-success text-success">
                                        <i class="bx bx-check-double"></i>
                                    </div>
                                    <div class="ms-3">
                                        <p class="text-muted mb-1">Completed Orders</p>
                                        <h4 class="mb-0"><?= (int)($stats['completed_orders'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mt-3 mt-xl-0">
                        <div class="card shadow-sm stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-soft-info text-info">
                                        <i class="bx bx-rupee"></i>
                                    </div>
                                    <div class="ms-3">
                                        <p class="text-muted mb-1">Total Sales</p>
                                        <h4 class="mb-0">₹<?= format_money($stats['total_sales'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mt-4 border-0">
                    <div class="card-header bg-white">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                            <h5 class="mb-0">
                                <i class="bx bx-filter-alt me-2"></i> Filter Orders
                            </h5>
                            <div class="d-flex gap-2 flex-wrap">
                                <a href="online_store_orders.php" class="btn btn-light">
                                    <i class="bx bx-refresh me-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET">
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Search</label>
                                    <input type="text" name="search" class="form-control"
                                           placeholder="Order no / customer / phone"
                                           value="<?= h($search ?? '') ?>">
                                </div>

                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Order Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All</option>
                                        <?php
                                        $statusOptions = ['pending','confirmed','processing','packed','shipped','delivered','completed','cancelled','returned'];
                                        foreach ($statusOptions as $opt):
                                        ?>
                                            <option value="<?= h($opt) ?>" <?= (($statusFilter ?? '') === $opt) ? 'selected' : '' ?>>
                                                <?= h(ucfirst(str_replace('_', ' ', $opt))) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Payment Status</label>
                                    <select name="payment_status" class="form-select">
                                        <option value="">All</option>
                                        <?php
                                        $paymentOptions = ['pending','partial','paid','failed','unpaid'];
                                        foreach ($paymentOptions as $opt):
                                        ?>
                                            <option value="<?= h($opt) ?>" <?= (($paymentFilter ?? '') === $opt) ? 'selected' : '' ?>>
                                                <?= h(ucfirst($opt)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom ?? '') ?>">
                                </div>

                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="date_to" class="form-control" value="<?= h($dateTo ?? '') ?>">
                                </div>

                                <div class="col-lg-1 col-md-6 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bx bx-search-alt"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm mt-4 border-0">
                    <div class="card-header bg-white">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                            <h5 class="mb-0">
                                <i class="bx bx-list-ul me-2"></i> Orders List
                            </h5>
                            <span class="badge bg-light text-dark border">
                                <?= (int)$totalRows ?> record(s)
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($orders)): ?>
                            <div class="table-responsive">
                                <table class="table align-middle table-hover order-table mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Order No</th>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Address</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">Paid</th>
                                            <th class="text-end">Pending</th>
                                            <th>Order Status</th>
                                            <th>Payment</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($orders as $index => $row): ?>
                                        <?php
                                        [$statusClass, $statusLabel] = normalize_status_badge($row['order_status'] ?? '');
                                        [$paymentClass, $paymentLabel] = normalize_status_badge($row['payment_status'] ?? (($row['pending_amount'] ?? 0) > 0 ? 'pending' : 'paid'));
                                        ?>
                                        <tr>
                                            <td><?= (int)($offset + $index + 1) ?></td>
                                            <td><div class="fw-semibold"><?= h($row['order_number']) ?></div></td>
                                            <td>
                                                <?php if (!empty($row['order_date'])): ?>
                                                    <div><?= h(date('d-m-Y', strtotime($row['order_date']))) ?></div>
                                                    <small class="text-muted"><?= h(date('h:i A', strtotime($row['order_date']))) ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?= h($row['customer_name_display'] ?: '-') ?></div>
                                                <?php if (!empty($row['customer_phone_display'])): ?>
                                                    <small class="text-muted"><?= h($row['customer_phone_display']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="address-col"><?= h($row['address_display'] ?: '-') ?></td>
                                            <td class="text-end fw-semibold">₹<?= format_money($row['total_amount'] ?? 0) ?></td>
                                            <td class="text-end text-success fw-semibold">₹<?= format_money($row['paid_amount'] ?? 0) ?></td>
                                            <td class="text-end text-danger fw-semibold">₹<?= format_money($row['pending_amount'] ?? 0) ?></td>
                                            <td><span class="badge bg-<?= h($statusClass) ?>"><?= h($statusLabel) ?></span></td>
                                            <td><span class="badge bg-<?= h($paymentClass) ?>"><?= h($paymentLabel) ?></span></td>
                                            <td class="text-center">
                                                <button type="button"
                                                        class="btn btn-sm btn-primary view-order-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#orderViewModal"
                                                        data-order-number="<?= h($row['order_number']) ?>"
                                                        data-order-date="<?= h(!empty($row['order_date']) ? date('d-m-Y h:i A', strtotime($row['order_date'])) : '-') ?>"
                                                        data-customer="<?= h($row['customer_name_display'] ?: '-') ?>"
                                                        data-phone="<?= h($row['customer_phone_display'] ?: '-') ?>"
                                                        data-address="<?= h($row['address_display'] ?: '-') ?>"
                                                        data-total="<?= h(format_money($row['total_amount'] ?? 0)) ?>"
                                                        data-paid="<?= h(format_money($row['paid_amount'] ?? 0)) ?>"
                                                        data-pending="<?= h(format_money($row['pending_amount'] ?? 0)) ?>"
                                                        data-status="<?= h($statusLabel) ?>"
                                                        data-payment="<?= h($paymentLabel) ?>">
                                                    <i class="bx bx-show"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-4">
                                    <div class="text-muted small">
                                        Page <?= (int)$page ?> of <?= (int)$totalPages ?>
                                    </div>

                                    <nav>
                                        <ul class="pagination pagination-sm mb-0">
                                            <?php $queryParams = $_GET; ?>

                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <?php $queryParams['page'] = max(1, $page - 1); ?>
                                                <a class="page-link" href="?<?= h(http_build_query($queryParams)) ?>">Previous</a>
                                            </li>

                                            <?php
                                            $startPage = max(1, $page - 2);
                                            $endPage   = min($totalPages, $page + 2);
                                            for ($p = $startPage; $p <= $endPage; $p++):
                                                $queryParams['page'] = $p;
                                            ?>
                                                <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?<?= h(http_build_query($queryParams)) ?>"><?= (int)$p ?></a>
                                                </li>
                                            <?php endfor; ?>

                                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                <?php $queryParams['page'] = min($totalPages, $page + 1); ?>
                                                <a class="page-link" href="?<?= h(http_build_query($queryParams)) ?>">Next</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="text-center py-5">
                                <div class="mb-3">
                                    <i class="bx bx-cart-alt text-muted" style="font-size: 54px;"></i>
                                </div>
                                <h5 class="mb-2">No online orders found</h5>
                                <p class="text-muted mb-0">Try changing the filters or check whether your online orders are stored in orders/invoices table.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<div class="modal fade" id="orderViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="bx bx-receipt me-2"></i>Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><div class="info-box"><div class="label">Order Number</div><div class="value" id="modal_order_number">-</div></div></div>
                    <div class="col-md-6"><div class="info-box"><div class="label">Order Date</div><div class="value" id="modal_order_date">-</div></div></div>
                    <div class="col-md-6"><div class="info-box"><div class="label">Customer</div><div class="value" id="modal_customer">-</div></div></div>
                    <div class="col-md-6"><div class="info-box"><div class="label">Phone</div><div class="value" id="modal_phone">-</div></div></div>
                    <div class="col-12"><div class="info-box"><div class="label">Address</div><div class="value" id="modal_address">-</div></div></div>
                    <div class="col-md-4"><div class="info-box"><div class="label">Total Amount</div><div class="value text-primary">₹<span id="modal_total">0.00</span></div></div></div>
                    <div class="col-md-4"><div class="info-box"><div class="label">Paid Amount</div><div class="value text-success">₹<span id="modal_paid">0.00</span></div></div></div>
                    <div class="col-md-4"><div class="info-box"><div class="label">Pending Amount</div><div class="value text-danger">₹<span id="modal_pending">0.00</span></div></div></div>
                    <div class="col-md-6"><div class="info-box"><div class="label">Order Status</div><div class="value" id="modal_status">-</div></div></div>
                    <div class="col-md-6"><div class="info-box"><div class="label">Payment Status</div><div class="value" id="modal_payment">-</div></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        document.querySelectorAll('.alert').forEach(function (alert) {
            try { bootstrap.Alert.getOrCreateInstance(alert).close(); } catch (e) {}
        });
    }, 5000);

    document.querySelectorAll('.view-order-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('modal_order_number').textContent = this.getAttribute('data-order-number') || '-';
            document.getElementById('modal_order_date').textContent   = this.getAttribute('data-order-date') || '-';
            document.getElementById('modal_customer').textContent     = this.getAttribute('data-customer') || '-';
            document.getElementById('modal_phone').textContent        = this.getAttribute('data-phone') || '-';
            document.getElementById('modal_address').textContent      = this.getAttribute('data-address') || '-';
            document.getElementById('modal_total').textContent        = this.getAttribute('data-total') || '0.00';
            document.getElementById('modal_paid').textContent         = this.getAttribute('data-paid') || '0.00';
            document.getElementById('modal_pending').textContent      = this.getAttribute('data-pending') || '0.00';
            document.getElementById('modal_status').textContent       = this.getAttribute('data-status') || '-';
            document.getElementById('modal_payment').textContent      = this.getAttribute('data-payment') || '-';
        });
    });
});
</script>

<style>
.card { border: 0; }
.card-header { border-bottom: 1px solid #f1f3f5; }
.form-label { font-weight: 600; }
.form-control, .form-select, .btn, .pagination .page-link { border-radius: 10px; }
.online-icon-wrap{ width:56px; height:56px; background:rgba(13,110,253,.10); color:#0d6efd; display:flex; align-items:center; justify-content:center; }
.stats-card .stat-icon{ width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; }
.bg-soft-primary{background:rgba(13,110,253,.12)!important;}
.bg-soft-success{background:rgba(25,135,84,.12)!important;}
.bg-soft-warning{background:rgba(255,193,7,.18)!important;}
.bg-soft-info{background:rgba(13,202,240,.12)!important;}
.order-table thead th{ white-space: nowrap; font-weight: 700; }
.address-col{ min-width: 220px; max-width: 320px; white-space: normal; }
.info-box{ border:1px solid #eef1f5; border-radius:12px; padding:14px; background:#fff; height:100%; }
.info-box .label{ font-size:12px; font-weight:600; color:#6c757d; margin-bottom:6px; text-transform:uppercase; letter-spacing:.4px; }
.info-box .value{ font-size:15px; font-weight:600; color:#212529; word-break:break-word; }
@media (max-width: 767.98px){
    .order-table{ min-width: 1100px; }
}
</style>
</body>
</html>