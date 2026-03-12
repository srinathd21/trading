<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$business_id = $_SESSION['business_id'] ?? 1;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
// Check if business and shop are selected
if (!$business_id || !$current_shop_id) {
    header('Location: select_shop.php');
    exit();
}

// ==================== HANDLE FORM SUBMISSIONS ====================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add_customer' || $action == 'edit_customer') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $gstin = trim($_POST['gstin'] ?? '');
        $customer_type = $_POST['customer_type'] ?? 'retail';
        $referral_id = !empty($_POST['referral_id']) ? (int)$_POST['referral_id'] : null;
        
        // New fields for credit management
        $credit_limit = isset($_POST['credit_limit']) && $_POST['credit_limit'] !== '' ? floatval($_POST['credit_limit']) : null;
        $outstanding_type = $_POST['outstanding_type'] ?? 'credit'; // credit or debit
        $outstanding_amount = isset($_POST['outstanding_amount']) && $_POST['outstanding_amount'] !== '' ? floatval($_POST['outstanding_amount']) : 0;
        
        // Validate required fields
        if (empty($name)) {
            $_SESSION['error'] = "Customer name is required!";
            header('Location: customers.php');
            exit();
        }
        
        try {
            if ($action == 'add_customer') {
                // Add new customer with credit fields
                $sql = "INSERT INTO customers (business_id, name, phone, email, address, gstin, customer_type, referral_id, 
                        credit_limit, outstanding_type, outstanding_amount, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$business_id, $name, $phone, $email, $address, $gstin, $customer_type, $referral_id, 
                              $credit_limit, $outstanding_type, $outstanding_amount]);
                
                $_SESSION['success'] = "Customer added successfully!";
            } else {
                // Edit existing customer
                $customer_id = (int)$_POST['id'];
                
                $sql = "UPDATE customers SET 
                        name = ?, 
                        phone = ?, 
                        email = ?, 
                        address = ?, 
                        gstin = ?, 
                        customer_type = ?, 
                        referral_id = ?,
                        credit_limit = ?,
                        outstanding_type = ?,
                        outstanding_amount = ?
                        WHERE id = ? AND business_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$name, $phone, $email, $address, $gstin, $customer_type, $referral_id, 
                              $credit_limit, $outstanding_type, $outstanding_amount, $customer_id, $business_id]);
                
                $_SESSION['success'] = "Customer updated successfully!";
            }
            
            header('Location: customers.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
            header('Location: customers.php');
            exit();
        }
    } 
    // Remove the delete_customer action completely
}

// ==================== SEARCH AND FILTER ====================
$search = trim($_GET['search'] ?? '');
$customer_type = $_GET['customer_type'] ?? '';
$where = "WHERE c.business_id = ?";
$params = [$business_id];
if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.gstin LIKE ? OR c.address LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($customer_type && in_array($customer_type, ['retail', 'wholesale'])) {
    $where .= " AND c.customer_type = ?";
    $params[] = $customer_type;
}

// Main query with credit/outstanding and points calculation
$sql = "
    SELECT c.*,
           rp.full_name as referral_name,
           cp.available_points,
           (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id AND i.business_id = ?) as total_invoices,
           (SELECT COALESCE(SUM(i.total), 0) FROM invoices i WHERE i.customer_id = c.id AND i.business_id = ?) as total_spent,
           (SELECT COALESCE(SUM(i.pending_amount), 0) FROM invoices i WHERE i.customer_id = c.id AND i.business_id = ?) as invoice_outstanding,
           (SELECT MAX(i.created_at) FROM invoices i WHERE i.customer_id = c.id AND i.business_id = ?) as last_purchase,
           (SELECT invoice_number FROM invoices WHERE customer_id = c.id AND business_id = ? ORDER BY created_at DESC LIMIT 1) as last_invoice_number,
           (SELECT id FROM invoices WHERE customer_id = c.id AND business_id = ? ORDER BY created_at DESC LIMIT 1) as last_invoice_id,
           (SELECT total FROM invoices WHERE customer_id = c.id AND business_id = ? ORDER BY created_at DESC LIMIT 1) as last_invoice_total
    FROM customers c
    LEFT JOIN referral_person rp ON c.referral_id = rp.id AND c.business_id = rp.business_id
    LEFT JOIN customer_points cp ON cp.customer_id = c.id AND cp.business_id = ?
    $where
    ORDER BY (CASE 
                WHEN c.outstanding_type = 'credit' THEN c.outstanding_amount 
                WHEN c.outstanding_type = 'debit' THEN -c.outstanding_amount 
                ELSE 0 
            END + 
            COALESCE((SELECT SUM(pending_amount) FROM invoices i WHERE i.customer_id = c.id AND i.business_id = ?), 0)
    ) DESC, c.name ASC
";
$params = array_merge([$business_id, $business_id, $business_id, $business_id, $business_id, $business_id, $business_id, $business_id, $business_id], $params);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Get referral persons for dropdown
$referrals_stmt = $pdo->prepare("SELECT id, full_name, phone FROM referral_person WHERE business_id = ? AND is_active = 1 ORDER BY full_name");
$referrals_stmt->execute([$business_id]);
$referrals = $referrals_stmt->fetchAll();

// Total outstanding across all customers (including manual outstanding)
$total_outstanding_stmt = $pdo->prepare("
    SELECT COALESCE(
        SUM(CASE 
            WHEN c.outstanding_type = 'credit' THEN c.outstanding_amount 
            WHEN c.outstanding_type = 'debit' THEN -c.outstanding_amount 
            ELSE 0 
        END) + 
        COALESCE(SUM(i.pending_amount), 0), 0
    ) 
    FROM customers c 
    LEFT JOIN invoices i ON c.id = i.customer_id AND i.business_id = ?
    WHERE c.business_id = ?
");
$total_outstanding_stmt->execute([$business_id, $business_id]);
$total_outstanding = $total_outstanding_stmt->fetchColumn();

// Get customer type statistics
$type_stats_stmt = $pdo->prepare("SELECT
                                  SUM(CASE WHEN customer_type = 'retail' THEN 1 ELSE 0 END) as retail_count,
                                  SUM(CASE WHEN customer_type = 'wholesale' THEN 1 ELSE 0 END) as wholesale_count,
                                  SUM(CASE WHEN credit_limit IS NOT NULL AND credit_limit > 0 THEN 1 ELSE 0 END) as credit_limit_count,
                                  SUM(CASE 
                                      WHEN outstanding_type = 'credit' THEN outstanding_amount 
                                      WHEN outstanding_type = 'debit' THEN -outstanding_amount 
                                      ELSE 0 
                                  END) as total_manual_outstanding
                                  FROM customers WHERE business_id = ?");
$type_stats_stmt->execute([$business_id]);
$type_stats = $type_stats_stmt->fetch();
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php') ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php') ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php') ?>
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
                                    <i class="bx bx-user me-2"></i> Customers
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i>
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <p class="mb-0 text-muted">
                                    Manage your retail and wholesale customers
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" onclick="exportCustomers()">
                                    <i class="bx bx-download me-1"></i> Export Excel
                                </button>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                                    <i class="bx bx-plus-circle me-1"></i> Add Customer
                                </button>
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
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Customers</h6>
                                        <h3 class="mb-0 text-primary"><?= count($customers) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-user text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        Retail: <?= $type_stats['retail_count'] ?> |
                                        Wholesale: <?= $type_stats['wholesale_count'] ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Credit Limit Enabled</h6>
                                        <h3 class="mb-0 text-warning"><?= $type_stats['credit_limit_count'] ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-credit-card text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted">Customers with credit limit</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Revenue</h6>
                                        <?php $revenue = $pdo->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE business_id = $business_id")->fetchColumn(); ?>
                                        <h3 class="mb-0 text-info">₹<?= number_format($revenue, 0) ?></h3>
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
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Outstanding</h6>
                                        <h3 class="mb-0 text-danger">₹<?= number_format($total_outstanding, 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-error-circle text-danger"></i>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted">Includes invoice + manual dues</small>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Filter Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="bx bx-filter me-1"></i> Search & Filter Customers
                        </h5>
                        <form method="GET" id="filterForm">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-4">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bx bx-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Name, phone, email, GSTIN, address..."
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="col-lg-3">
                                    <label class="form-label">Customer Type</label>
                                    <select name="customer_type" class="form-select">
                                        <option value="">All Types</option>
                                        <option value="retail" <?= $customer_type === 'retail' ? 'selected' : '' ?>>Retail</option>
                                        <option value="wholesale" <?= $customer_type === 'wholesale' ? 'selected' : '' ?>>Wholesale</option>
                                    </select>
                                </div>
                                <div class="col-lg-3">
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-grow-1">
                                            <i class="bx bx-search me-1"></i> Search
                                        </button>
                                        <?php if ($search || $customer_type): ?>
                                        <a href="customers.php" class="btn btn-outline-secondary">
                                            <i class="bx bx-reset me-1"></i> Clear
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- Customers Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="customersTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Customer Details</th>
                                        <th class="text-center">Contact Info</th>
                                        <th class="text-center">Purchase History</th>
                                        <th class="text-center">Credit Info</th>
                                        <th class="text-center">Outstanding Dues</th>
                                        <th class="text-center">Last Purchase</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($customers)): ?>
                                    
                                    <?php else: ?>
                                    <?php foreach ($customers as $c): 
                                        // Calculate total outstanding (manual + invoice)
                                        $manual_outstanding = ($c['outstanding_type'] == 'debit') ? -$c['outstanding_amount'] : $c['outstanding_amount'];
                                        $total_outstanding_customer = $manual_outstanding + $c['invoice_outstanding'];
                                        $credit_limit = $c['credit_limit'] ?? 0;
                                        $credit_utilization = $credit_limit > 0 ? ($total_outstanding_customer / $credit_limit) * 100 : 0;
                                    ?>
                                    <tr class="customer-row" data-id="<?= $c['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 48px; height: 48px;">
                                                        <i class="bx bx-user fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block mb-1"><?= htmlspecialchars($c['name']) ?></strong>
                                                    <span class="badge bg-<?= $c['customer_type'] === 'wholesale' ? 'success' : 'primary' ?> bg-opacity-10 text-<?= $c['customer_type'] === 'wholesale' ? 'success' : 'primary' ?> rounded-pill">
                                                        <?= ucfirst($c['customer_type']) ?>
                                                    </span>
                                                    <?php if ($c['gstin']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-barcode me-1"></i><?= htmlspecialchars($c['gstin']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                    <?php if ($c['address']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bx bx-map me-1"></i><?= htmlspecialchars(substr($c['address'], 0, 60)) ?>
                                                        <?= strlen($c['address']) > 60 ? '...' : '' ?>
                                                    </small>
                                                    <?php endif; ?>
                                                    <?php if ($c['referral_name']): ?>
                                                    <br>
                                                    <small class="text-info">
                                                        <i class="bx bx-user-plus me-1"></i>Referred by: <?= htmlspecialchars($c['referral_name']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($c['phone']): ?>
                                            <div class="mb-2">
                                                <a href="tel:<?= htmlspecialchars($c['phone']) ?>"
                                                   class="text-decoration-none d-flex align-items-center justify-content-center">
                                                    <i class="bx bx-phone text-primary me-2"></i>
                                                    <?= htmlspecialchars($c['phone']) ?>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($c['email']): ?>
                                            <div>
                                                <a href="mailto:<?= htmlspecialchars($c['email']) ?>"
                                                   class="text-decoration-none d-flex align-items-center justify-content-center">
                                                    <i class="bx bx-envelope text-info me-2"></i>
                                                    <?= htmlspecialchars($c['email']) ?>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 fs-6">
                                                    <i class="bx bx-receipt me-1"></i> <?= $c['total_invoices'] ?>
                                                    <small class="ms-1">invoices</small>
                                                </span>
                                            </div>
                                            <div>
                                                <strong class="text-success">₹<?= number_format($c['total_spent'], 2) ?></strong>
                                                <small class="text-muted d-block">Total spent</small>
                                            </div>
                                            <?php if ($c['last_invoice_number']): ?>
                                            <small class="text-muted">
                                                Last invoice: <?= htmlspecialchars($c['last_invoice_number']) ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($credit_limit > 0): ?>
                                            <div class="mb-2">
                                                <strong class="text-warning">₹<?= number_format($credit_limit, 2) ?></strong>
                                                <small class="text-muted d-block">Credit Limit</small>
                                            </div>
                                            <?php if ($credit_utilization > 0): ?>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-<?= $credit_utilization > 80 ? 'danger' : ($credit_utilization > 50 ? 'warning' : 'success') ?>" 
                                                     role="progressbar" 
                                                     style="width: <?= min($credit_utilization, 100) ?>%"
                                                     aria-valuenow="<?= $credit_utilization ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                </div>
                                            </div>
                                            <small class="text-muted"><?= number_format($credit_utilization, 1) ?>% used</small>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-muted">No credit limit</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($total_outstanding_customer > 0): ?>
                                                <strong class="text-danger fs-5">₹<?= number_format($total_outstanding_customer, 2) ?></strong>
                                                <small class="text-muted d-block">Total Due</small>
                                                <?php if ($c['outstanding_amount'] > 0): ?>
                                                <small class="text-info">
                                                    <i class="bx bx-info-circle me-1"></i>
                                                    <?= $c['outstanding_type'] == 'credit' ? 'Credit' : 'Debit' ?>: ₹<?= number_format($c['outstanding_amount'], 2) ?>
                                                </small>
                                                <?php endif; ?>
                                                <a href="customer_credit_statement.php?customer_id=<?= $c['id'] ?>"
                                                   class="btn btn-sm btn-outline-danger mt-1">
                                                    <i class="bx bx-receipt me-1"></i> View Statement
                                                </a>
                                            <?php elseif ($total_outstanding_customer < 0): ?>
                                                <strong class="text-success fs-5">₹<?= number_format(abs($total_outstanding_customer), 2) ?></strong>
                                                <small class="text-muted d-block">Advance/Overpayment</small>
                                            <?php else: ?>
                                                <span class="text-success fw-medium">
                                                    <i class="bx bx-check-circle me-1"></i> No dues
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($c['last_purchase']):
                                                $days_ago = round((time() - strtotime($c['last_purchase'])) / (60*60*24));
                                                $last_purchase_class = $days_ago > 90 ? 'text-danger' : ($days_ago > 30 ? 'text-warning' : 'text-success');
                                            ?>
                                            <div class="mb-1">
                                                <span class="<?= $last_purchase_class ?>">
                                                    <?= date('d M Y', strtotime($c['last_purchase'])) ?>
                                                </span>
                                            </div>
                                            <small class="text-muted">
                                                <?= $days_ago ?> days ago
                                            </small>
                                            <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1">
                                                <i class="bx bx-time me-1"></i>No purchase
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button class="btn btn-outline-info view-customer-btn"
                                                        data-id="<?= $c['id'] ?>"
                                                        data-bs-toggle="tooltip" title="View Details">
                                                    <i class="bx bx-show"></i>
                                                </button>
                                                <button class="btn btn-outline-warning edit-customer-btn"
                                                        data-id="<?= $c['id'] ?>"
                                                        data-name="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>"
                                                        data-phone="<?= htmlspecialchars($c['phone'] ?? '', ENT_QUOTES) ?>"
                                                        data-email="<?= htmlspecialchars($c['email'] ?? '', ENT_QUOTES) ?>"
                                                        data-address="<?= htmlspecialchars($c['address'] ?? '', ENT_QUOTES) ?>"
                                                        data-gstin="<?= htmlspecialchars($c['gstin'] ?? '', ENT_QUOTES) ?>"
                                                        data-customer_type="<?= htmlspecialchars($c['customer_type'], ENT_QUOTES) ?>"
                                                        data-referral_id="<?= htmlspecialchars($c['referral_id'] ?? '', ENT_QUOTES) ?>"
                                                        data-credit_limit="<?= htmlspecialchars($c['credit_limit'] ?? '', ENT_QUOTES) ?>"
                                                        data-outstanding_type="<?= htmlspecialchars($c['outstanding_type'] ?? 'credit', ENT_QUOTES) ?>"
                                                        data-outstanding_amount="<?= htmlspecialchars($c['outstanding_amount'] ?? '0', ENT_QUOTES) ?>"
                                                        data-bs-toggle="tooltip" title="Edit">
                                                    <i class="bx bx-edit"></i>
                                                </button>
                                                <a href="pos.php?customer_id=<?= $c['id'] ?>"
                                                   class="btn btn-outline-success"
                                                   data-bs-toggle="tooltip" title="New Sale">
                                                    <i class="bx bx-plus"></i>
                                                </a>
                                              
                                                <!-- WhatsApp Send Button -->
                                                <?php if ($c['last_invoice_id'] && $c['phone']):
                                                    $customer_name = $c['name'];
                                                    $invoice_number = $c['last_invoice_number'];
                                                    $invoice_amount = $c['last_invoice_total'];
                                                    $pdf_url = 'https://' . $_SERVER['HTTP_HOST'] . '/billing/trading/invoice_print.php?invoice_id=' . $c['last_invoice_id'] . '&download=1';
                                                   
                                                    $whatsapp_message = rawurlencode(
                                                        "Dear " . $customer_name . ",\n\n" .
                                                        "Your invoice " . $invoice_number . " for ₹" . number_format($invoice_amount, 2) . " is ready.\n\n" .
                                                        "Download link: " . $pdf_url . "\n\n" .
                                                        "Thank you for your business!"
                                                    );
                                                   
                                                    $clean_phone = preg_replace('/\D/', '', $c['phone']);
                                                    $whatsapp_url = 'https://wa.me/91' . $clean_phone . '?text=' . $whatsapp_message;
                                                ?>
                                                <a href="<?= $whatsapp_url ?>"
                                                   target="_blank"
                                                   class="btn btn-outline-success"
                                                   data-bs-toggle="tooltip"
                                                   title="Send Last Invoice via WhatsApp">
                                                    <i class="bx bxl-whatsapp"></i>
                                                </a>
                                                <?php else: ?>
                                                <button class="btn btn-outline-success disabled"
                                                        data-bs-toggle="tooltip" title="No invoice or phone to send WhatsApp">
                                                    <i class="bx bxl-whatsapp"></i>
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
        <?php include('includes/footer.php') ?>
    </div>
</div>

<!-- Add/Edit Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="customers.php" id="customerForm">
                <input type="hidden" name="action" id="formAction" value="add_customer">
                <input type="hidden" name="id" id="editId" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-user-plus me-2"></i>
                        <span id="modalTitle">Add New Customer</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="custName" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" name="phone" id="custPhone" class="form-control" maxlength="15">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" id="custEmail" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Customer Type</label>
                                <select name="customer_type" id="custType" class="form-select">
                                    <option value="retail">Retail Customer</option>
                                    <option value="wholesale">Wholesale Customer</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">GSTIN Number</label>
                                <input type="text" name="gstin" id="custGstin" class="form-control text-uppercase" maxlength="15" placeholder="22ABCDE1234F1Z5">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Credit Limit (₹)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" name="credit_limit" id="custCreditLimit" class="form-control" 
                                           min="0" step="0.01" placeholder="0.00">
                                </div>
                                <small class="text-muted">Set 0 or leave empty for no credit limit</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Initial Outstanding Type</label>
                                <select name="outstanding_type" id="custOutstandingType" class="form-select">
                                    <option value="credit">Credit (Customer owes you)</option>
                                    <option value="debit">Debit (You owe customer)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">Initial Outstanding Amount (₹)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" name="outstanding_amount" id="custOutstandingAmount" 
                                           class="form-control" min="0" step="0.01" placeholder="0.00" value="0">
                                </div>
                                <small class="text-muted">Enter any initial outstanding balance</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Referral (Optional)</label>
                        <select name="referral_id" id="custReferral" class="form-select">
                            <option value="">Select Referral Person</option>
                            <?php foreach ($referrals as $ref): ?>
                            <option value="<?= $ref['id'] ?>"><?= htmlspecialchars($ref['full_name']) ?> (<?= htmlspecialchars($ref['phone']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" id="custAddress" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bx bx-save me-2"></i> <span id="submitBtnText">Save Customer</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>

<script>
$(document).ready(function() {
     // Initialize DataTable
    $('#customersTable').DataTable({
        responsive: true,
       pageLength: 25,
ordering: false,

        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search in table:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ customers",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });
    
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Edit customer button (FIXED)
    $(document).on('click', '.edit-customer-btn', function () {
        $('#editId').val($(this).data('id'));
        $('#custName').val($(this).data('name'));
        $('#custPhone').val($(this).data('phone'));
        $('#custEmail').val($(this).data('email'));
        $('#custAddress').val($(this).data('address'));
        $('#custGstin').val($(this).data('gstin'));
        $('#custType').val($(this).data('customer_type'));
        $('#custReferral').val($(this).data('referral_id'));
        $('#custCreditLimit').val($(this).data('credit_limit'));
        $('#custOutstandingType').val($(this).data('outstanding_type'));
        $('#custOutstandingAmount').val($(this).data('outstanding_amount'));

        $('#formAction').val('edit_customer');
        $('#modalTitle').text('Edit Customer');
        $('#submitBtnText').text('Update Customer');

        const modal = new bootstrap.Modal(document.getElementById('addCustomerModal'));
        modal.show();
    });

    // View customer details (FIXED)
    $(document).on('click', '.view-customer-btn', function () {
        const customerId = $(this).data('id');
        window.location.href = 'customer_details.php?id=' + customerId;
    });

    // Re-enable tooltips after table redraw
    $('#customersTable').on('draw.dt', function () {
        $('[data-bs-toggle="tooltip"]').tooltip();
    });

    // Reset form when add modal is closed
    $('#addCustomerModal').on('hidden.bs.modal', function () {
        $('#modalTitle').text('Add New Customer');
        $('#submitBtnText').text('Save Customer');
        $('#formAction').val('add_customer');
        $('#editId').val('');
        $('#customerForm')[0].reset();
        $('#custType').val('retail');
        $('#custReferral').val('');
        $('#custOutstandingType').val('credit');
        $('#custOutstandingAmount').val('0');
    });

    // Auto-search with debounce
    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => $('#filterForm').submit(), 500);
    });

    // Export customers function
    window.exportCustomers = function() {
        const btn = event.target.closest('button');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bx bx-loader bx-spin me-1"></i> Exporting...';
        btn.disabled = true;
        
        const params = new URLSearchParams(window.location.search);
        const exportUrl = 'customers_export.php' + (params.toString() ? '?' + params.toString() : '');
        window.location = exportUrl;
        
        setTimeout(() => {
            btn.innerHTML = original;
            btn.disabled = false;
        }, 3000);
    };

    // Phone number formatting
    $('#custPhone').on('input', function() {
        let phone = $(this).val().replace(/\D/g, '');
        if (phone.length > 10) {
            phone = phone.substring(0, 10);
        }
        $(this).val(phone);
    });

    // GSTIN formatting
    $('#custGstin').on('input', function() {
        $(this).val($(this).val().toUpperCase());
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
});
</script>

<style>
.empty-state i { font-size: 4rem; opacity: 0.5; }
.avatar-sm { width: 48px; height: 48px; }
.table th { font-weight: 600; background-color: #f8f9fa; }
.card-hover { transition: transform 0.3s ease, box-shadow 0.3s ease; }
.card-hover:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important; }
.border-start { border-left-width: 4px !important; }
.btn-group .btn {
    padding: 0.375rem 0.5rem;
    font-size: 14px;
}
.credit-progress {
    height: 6px;
    margin-bottom: 5px;
}
.credit-progress .progress-bar {
    transition: width 0.3s ease;
}
@media (max-width: 768px) {
    .btn-group { flex-wrap: wrap; gap: 3px; }
    .btn-group .btn { flex: 1; min-width: 40px; padding: 0.375rem 0.5rem; }
}
</style>
</body>
</html>