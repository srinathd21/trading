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

function first_existing_column(array $columns, array $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function buildPaginationUrl(array $params, $page) {
    $params['page'] = $page;
    return '?' . htmlspecialchars(http_build_query($params), ENT_QUOTES, 'UTF-8');
}

$customers = [];
$storeInfo = [
    'store_title'   => 'Online Store',
    'display_name'  => 'Online Store',
    'store_status'  => 'draft',
    'support_phone' => '',
    'support_email' => '',
    'address'       => '',
    'theme_color'   => '#0d6efd'
];

$stats = [
    'total_customers'    => 0,
    'retail_customers'   => 0,
    'wholesale_customers'=> 0,
    'credit_outstanding' => 0
];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$totalRows = 0;
$totalPages = 1;

try {
    if (!empty($_SESSION['flash_success'])) {
        $success = $_SESSION['flash_success'];
        unset($_SESSION['flash_success']);
    }

    if (!empty($_SESSION['flash_error'])) {
        $error = $_SESSION['flash_error'];
        unset($_SESSION['flash_error']);
    }

    // Store info safe fallback
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

    if (!table_exists($pdo, 'customers')) {
        throw new Exception('Customers table not found.');
    }

    $customerCols = get_columns($pdo, 'customers');

    $idCol               = first_existing_column($customerCols, ['id']);
    $businessIdCol       = first_existing_column($customerCols, ['business_id']);
    $customerTypeCol     = first_existing_column($customerCols, ['customer_type']);
    $nameCol             = first_existing_column($customerCols, ['name', 'customer_name', 'full_name']);
    $phoneCol            = first_existing_column($customerCols, ['phone', 'mobile', 'mobile_number']);
    $altPhoneCol         = first_existing_column($customerCols, ['alt_phone', 'alternate_phone']);
    $emailCol            = first_existing_column($customerCols, ['email']);
    $addressCol          = first_existing_column($customerCols, ['address']);
    $gstinCol            = first_existing_column($customerCols, ['gstin']);
    $creditLimitCol      = first_existing_column($customerCols, ['credit_limit']);
    $outstandingTypeCol  = first_existing_column($customerCols, ['outstanding_type']);
    $outstandingAmountCol= first_existing_column($customerCols, ['outstanding_amount']);
    $createdAtCol        = first_existing_column($customerCols, ['created_at']);

    if (!$idCol || !$businessIdCol || !$nameCol) {
        throw new Exception('Required customer columns are missing.');
    }

    $search      = trim($_GET['search'] ?? '');
    $typeFilter  = trim($_GET['customer_type'] ?? '');
    $creditFilter= trim($_GET['credit_type'] ?? '');

    $where = ["c.`$businessIdCol` = :business_id"];
    $params = [':business_id' => $business_id];

    if ($search !== '') {
        $searchParts = [];
        $searchParts[] = "c.`$nameCol` LIKE :search";

        if ($phoneCol) {
            $searchParts[] = "c.`$phoneCol` LIKE :search";
        }
        if ($altPhoneCol) {
            $searchParts[] = "c.`$altPhoneCol` LIKE :search";
        }
        if ($emailCol) {
            $searchParts[] = "c.`$emailCol` LIKE :search";
        }
        if ($gstinCol) {
            $searchParts[] = "c.`$gstinCol` LIKE :search";
        }

        $where[] = '(' . implode(' OR ', $searchParts) . ')';
        $params[':search'] = '%' . $search . '%';
    }

    if ($typeFilter !== '' && $customerTypeCol) {
        $where[] = "c.`$customerTypeCol` = :customer_type";
        $params[':customer_type'] = $typeFilter;
    }

    if ($creditFilter !== '' && $outstandingTypeCol) {
        $where[] = "c.`$outstandingTypeCol` = :credit_type";
        $params[':credit_type'] = $creditFilter;
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);

    // Online order stats join if available
    $onlineOrderJoin = '';
    $onlineStatsSelect = "0 AS total_orders, 0.00 AS total_online_sales";
    if (table_exists($pdo, 'online_store_orders')) {
        $orderCols = get_columns($pdo, 'online_store_orders');
        $orderCustomerIdCol = first_existing_column($orderCols, ['customer_id']);
        $orderAmountCol = first_existing_column($orderCols, ['grand_total', 'total_amount', 'final_amount', 'amount', 'total']);
        $orderBusinessIdCol = first_existing_column($orderCols, ['business_id']);

        if ($orderCustomerIdCol && $orderAmountCol) {
            $onlineOrderJoin = "
                LEFT JOIN (
                    SELECT
                        `{$orderCustomerIdCol}` AS customer_id,
                        COUNT(*) AS total_orders,
                        SUM(COALESCE(`{$orderAmountCol}`, 0)) AS total_online_sales
                    FROM `online_store_orders`
                    " . ($orderBusinessIdCol ? "WHERE `{$orderBusinessIdCol}` = :business_id_stats" : "") . "
                    GROUP BY `{$orderCustomerIdCol}`
                ) os ON os.customer_id = c.`$idCol`
            ";
            $onlineStatsSelect = "COALESCE(os.total_orders, 0) AS total_orders, COALESCE(os.total_online_sales, 0) AS total_online_sales";
        }
    }

    $countSql = "SELECT COUNT(*) FROM `customers` c $whereSql";
    $stmtCount = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    $statsSql = "
        SELECT
            COUNT(*) AS total_customers,
            SUM(CASE WHEN " . ($customerTypeCol ? "c.`$customerTypeCol` = 'retail'" : "0") . " THEN 1 ELSE 0 END) AS retail_customers,
            SUM(CASE WHEN " . ($customerTypeCol ? "c.`$customerTypeCol` = 'wholesale'" : "0") . " THEN 1 ELSE 0 END) AS wholesale_customers,
            SUM(CASE WHEN " . ($outstandingTypeCol && $outstandingAmountCol ? "c.`$outstandingTypeCol` = 'credit'" : "0") . " THEN COALESCE(c.`$outstandingAmountCol`, 0) ELSE 0 END) AS credit_outstanding
        FROM `customers` c
        $whereSql
    ";
    $stmtStats = $pdo->prepare($statsSql);
    foreach ($params as $key => $value) {
        $stmtStats->bindValue($key, $value);
    }
    $stmtStats->execute();
    $statsRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
    if ($statsRow) {
        $stats = array_merge($stats, $statsRow);
    }

    $sql = "
        SELECT
            c.`$idCol` AS customer_id,
            c.`$nameCol` AS customer_name,
            " . ($customerTypeCol ? "COALESCE(c.`$customerTypeCol`, 'retail')" : "'retail'") . " AS customer_type,
            " . ($phoneCol ? "COALESCE(c.`$phoneCol`, '')" : "''") . " AS phone,
            " . ($altPhoneCol ? "COALESCE(c.`$altPhoneCol`, '')" : "''") . " AS alt_phone,
            " . ($emailCol ? "COALESCE(c.`$emailCol`, '')" : "''") . " AS email,
            " . ($addressCol ? "COALESCE(c.`$addressCol`, '')" : "''") . " AS address,
            " . ($gstinCol ? "COALESCE(c.`$gstinCol`, '')" : "''") . " AS gstin,
            " . ($creditLimitCol ? "COALESCE(c.`$creditLimitCol`, 0)" : "0") . " AS credit_limit,
            " . ($outstandingTypeCol ? "COALESCE(c.`$outstandingTypeCol`, 'credit')" : "'credit'") . " AS outstanding_type,
            " . ($outstandingAmountCol ? "COALESCE(c.`$outstandingAmountCol`, 0)" : "0") . " AS outstanding_amount,
            " . ($createdAtCol ? "c.`$createdAtCol`" : "NULL") . " AS created_at,
            $onlineStatsSelect
        FROM `customers` c
        $onlineOrderJoin
        $whereSql
        ORDER BY " . ($createdAtCol ? "c.`$createdAtCol` DESC" : "c.`$idCol` DESC") . "
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    if (strpos($sql, ':business_id_stats') !== false) {
        $stmt->bindValue(':business_id_stats', $business_id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Online Store Customers"; include 'includes/head.php'; ?>
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
                                            <i class="bx bx-user fs-3"></i>
                                        </div>
                                        <div>
                                            <h4 class="mb-1">Online Store Customers</h4>
                                            <p class="text-muted mb-0">
                                                Manage online store customer details in the same UI template.
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
                                        <i class="bx bx-group"></i>
                                    </div>
                                    <div class="ms-3">
                                        <p class="text-muted mb-1">Total Customers</p>
                                        <h4 class="mb-0"><?= (int)($stats['total_customers'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mt-3 mt-md-0">
                        <div class="card shadow-sm stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-soft-success text-success">
                                        <i class="bx bx-user-check"></i>
                                    </div>
                                    <div class="ms-3">
                                        <p class="text-muted mb-1">Retail Customers</p>
                                        <h4 class="mb-0"><?= (int)($stats['retail_customers'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mt-3 mt-xl-0">
                        <div class="card shadow-sm stats-card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-soft-warning text-warning">
                                        <i class="bx bx-briefcase-alt-2"></i>
                                    </div>
                                    <div class="ms-3">
                                        <p class="text-muted mb-1">Wholesale</p>
                                        <h4 class="mb-0"><?= (int)($stats['wholesale_customers'] ?? 0) ?></h4>
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
                                        <p class="text-muted mb-1">Credit Outstanding</p>
                                        <h4 class="mb-0">₹<?= format_money($stats['credit_outstanding'] ?? 0) ?></h4>
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
                                <i class="bx bx-filter-alt me-2"></i> Filter Customers
                            </h5>
                            <div class="d-flex gap-2 flex-wrap">
                                <a href="online_store_customers.php" class="btn btn-light">
                                    <i class="bx bx-refresh me-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET">
                            <div class="row g-3">
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Search</label>
                                    <input type="text" name="search" class="form-control"
                                           placeholder="Name / phone / email / GSTIN"
                                           value="<?= h($search ?? '') ?>">
                                </div>

                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Customer Type</label>
                                    <select name="customer_type" class="form-select">
                                        <option value="">All</option>
                                        <option value="retail" <?= (($typeFilter ?? '') === 'retail') ? 'selected' : '' ?>>Retail</option>
                                        <option value="wholesale" <?= (($typeFilter ?? '') === 'wholesale') ? 'selected' : '' ?>>Wholesale</option>
                                    </select>
                                </div>

                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Outstanding Type</label>
                                    <select name="credit_type" class="form-select">
                                        <option value="">All</option>
                                        <option value="credit" <?= (($creditFilter ?? '') === 'credit') ? 'selected' : '' ?>>Credit</option>
                                        <option value="debit" <?= (($creditFilter ?? '') === 'debit') ? 'selected' : '' ?>>Debit</option>
                                    </select>
                                </div>

                                <div class="col-lg-2 col-md-6 d-flex align-items-end">
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
                                <i class="bx bx-list-ul me-2"></i> Customer List
                            </h5>
                            <span class="badge bg-light text-dark border">
                                <?= (int)$totalRows ?> record(s)
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($customers)): ?>
                            <div class="table-responsive">
                                <table class="table align-middle table-hover customer-table mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Customer</th>
                                            <th>Contact</th>
                                            <th>Address</th>
                                            <th>GSTIN</th>
                                            <th class="text-end">Credit Limit</th>
                                            <th class="text-end">Outstanding</th>
                                            <th class="text-center">Orders</th>
                                            <th class="text-end">Online Sales</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($customers as $index => $row): ?>
                                        <tr>
                                            <td><?= (int)($offset + $index + 1) ?></td>
                                            <td>
                                                <div class="fw-semibold"><?= h($row['customer_name']) ?></div>
                                                <span class="badge bg-<?= $row['customer_type'] === 'wholesale' ? 'warning text-dark' : 'primary' ?>">
                                                    <?= h(ucfirst($row['customer_type'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div><?= h($row['phone'] ?: '-') ?></div>
                                                <?php if (!empty($row['alt_phone'])): ?>
                                                    <small class="text-muted d-block">Alt: <?= h($row['alt_phone']) ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($row['email'])): ?>
                                                    <small class="text-muted d-block"><?= h($row['email']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="address-col"><?= h($row['address'] ?: '-') ?></td>
                                            <td><?= h($row['gstin'] ?: '-') ?></td>
                                            <td class="text-end">₹<?= format_money($row['credit_limit'] ?? 0) ?></td>
                                            <td class="text-end">
                                                <div class="fw-semibold <?= ($row['outstanding_type'] ?? 'credit') === 'credit' ? 'text-danger' : 'text-success' ?>">
                                                    ₹<?= format_money($row['outstanding_amount'] ?? 0) ?>
                                                </div>
                                                <small class="text-muted"><?= h(ucfirst($row['outstanding_type'] ?? 'credit')) ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-info"><?= (int)($row['total_orders'] ?? 0) ?></span>
                                            </td>
                                            <td class="text-end fw-semibold">₹<?= format_money($row['total_online_sales'] ?? 0) ?></td>
                                            <td>
                                                <?php if (!empty($row['created_at'])): ?>
                                                    <div><?= h(date('d-m-Y', strtotime($row['created_at']))) ?></div>
                                                    <small class="text-muted"><?= h(date('h:i A', strtotime($row['created_at']))) ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
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
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($_GET, max(1, $page - 1)) ?>">Previous</a>
                                            </li>

                                            <?php
                                            $startPage = max(1, $page - 2);
                                            $endPage = min($totalPages, $page + 2);
                                            for ($p = $startPage; $p <= $endPage; $p++):
                                            ?>
                                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="<?= buildPaginationUrl($_GET, $p) ?>"><?= $p ?></a>
                                                </li>
                                            <?php endfor; ?>

                                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($_GET, min($totalPages, $page + 1)) ?>">Next</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="text-center py-5">
                                <div class="mb-3">
                                    <i class="bx bx-user-circle text-muted" style="font-size: 54px;"></i>
                                </div>
                                <h5 class="mb-2">No customers found</h5>
                                <p class="text-muted mb-0">Try changing the filters or add customers for this business.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        document.querySelectorAll('.alert').forEach(function (alert) {
            try {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            } catch (e) {}
        });
    }, 5000);
});
</script>

<style>
.card {
    border: 0;
}
.card-header {
    border-bottom: 1px solid #f1f3f5;
}
.form-label {
    font-weight: 600;
}
.form-control,
.form-select,
.btn,
.pagination .page-link {
    border-radius: 10px;
}
.online-icon-wrap{
    width:56px;
    height:56px;
    background:rgba(13,110,253,.10);
    color:#0d6efd;
    display:flex;
    align-items:center;
    justify-content:center;
}
.stats-card .stat-icon{
    width:48px;
    height:48px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
}
.bg-soft-primary{background:rgba(13,110,253,.12)!important;}
.bg-soft-success{background:rgba(25,135,84,.12)!important;}
.bg-soft-warning{background:rgba(255,193,7,.18)!important;}
.bg-soft-info{background:rgba(13,202,240,.12)!important;}
.customer-table thead th{
    white-space: nowrap;
    font-weight: 700;
}
.address-col{
    min-width: 220px;
    max-width: 320px;
    white-space: normal;
}
@media (max-width: 767.98px){
    .customer-table{
        min-width: 1300px;
    }
}
</style>
</body>
</html>