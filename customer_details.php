<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$customer_id) {
    header('Location: customers.php');
    exit();
}

// ==================== HANDLE INVOICE DELETION ====================
if (isset($_GET['delete_invoice_id'])) {
    $delete_invoice_id = (int)$_GET['delete_invoice_id'];

    try {
        $inv_sql = "SELECT id, invoice_number, customer_id, total, payment_method, created_at
                    FROM invoices WHERE id = ? AND business_id = ?";
        $inv_stmt = $pdo->prepare($inv_sql);
        $inv_stmt->execute([$delete_invoice_id, $business_id]);
        $invoice = $inv_stmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice) {
            $record_details = json_encode($invoice);

            // Log deletion
            $log_sql = "INSERT INTO deletion_logs (table_name, record_id, record_details, deleted_by, business_id)
                        VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute(['invoices', $delete_invoice_id, $record_details, $_SESSION['user_id'], $business_id]);

            // Delete invoice items first
            $delete_items_sql = "DELETE FROM invoice_items WHERE invoice_id = ?";
            $delete_items_stmt = $pdo->prepare($delete_items_sql);
            $delete_items_stmt->execute([$delete_invoice_id]);

            // Delete invoice
            $delete_inv_sql = "DELETE FROM invoices WHERE id = ? AND business_id = ?";
            $delete_inv_stmt = $pdo->prepare($delete_inv_sql);
            $delete_inv_stmt->execute([$delete_invoice_id, $business_id]);

            $_SESSION['success'] = 'Invoice deleted successfully';
        } else {
            $_SESSION['error'] = 'Invoice not found';
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error deleting invoice: ' . $e->getMessage();
    }

    header("Location: view-customer.php?id=" . $customer_id);
    exit();
}

// ==================== HANDLE CUSTOMER DELETION ====================
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];

    try {
        // Prevent deletion if customer has invoices
        $check_sql = "SELECT COUNT(*) as invoice_count FROM invoices WHERE customer_id = ? AND business_id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$delete_id, $business_id]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['invoice_count'] > 0) {
            $_SESSION['error'] = 'Cannot delete customer with existing invoices.';
            header('Location: view-customer.php?id=' . $delete_id);
            exit();
        }

        $cust_sql = "SELECT id, name, phone, created_at FROM customers WHERE id = ? AND business_id = ?";
        $cust_stmt = $pdo->prepare($cust_sql);
        $cust_stmt->execute([$delete_id, $business_id]);
        $customer_to_delete = $cust_stmt->fetch(PDO::FETCH_ASSOC);

        if ($customer_to_delete) {
            $record_details = json_encode($customer_to_delete);

            // Log deletion
            $log_sql = "INSERT INTO deletion_logs (table_name, record_id, record_details, deleted_by, business_id)
                        VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute(['customers', $delete_id, $record_details, $_SESSION['user_id'], $business_id]);

            // Delete customer
            $delete_sql = "DELETE FROM customers WHERE id = ? AND business_id = ?";
            $delete_stmt = $pdo->prepare($delete_sql);
            $delete_stmt->execute([$delete_id, $business_id]);

            $_SESSION['success'] = 'Customer deleted successfully.';
            header('Location: customers.php');
            exit();
        } else {
            $_SESSION['error'] = 'Customer not found';
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error deleting customer: ' . $e->getMessage();
    }

    header('Location: view-customer.php?id=' . $delete_id);
    exit();
}

// ==================== FETCH CUSTOMER DETAILS WITH POINTS ====================
$sql = "
    SELECT 
        c.*,
        rp.full_name as referral_name,
        COALESCE(cp.available_points, 0) as available_points,
        (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id AND i.business_id = c.business_id) as total_invoices,
        (SELECT COALESCE(SUM(i.total), 0) FROM invoices i WHERE i.customer_id = c.id AND i.business_id = c.business_id) as total_spent,
        (SELECT COALESCE(SUM(i.pending_amount), 0) FROM invoices i WHERE i.customer_id = c.id AND i.business_id = c.business_id) as invoice_outstanding,
        (SELECT MAX(i.created_at) FROM invoices i WHERE i.customer_id = c.id AND i.business_id = c.business_id) as last_purchase
    FROM customers c
    LEFT JOIN referral_person rp ON c.referral_id = rp.id AND c.business_id = rp.business_id
    LEFT JOIN customer_points cp ON cp.customer_id = c.id AND cp.business_id = c.business_id
    WHERE c.id = ? AND c.business_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$customer_id, $business_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    $_SESSION['error'] = 'Customer not found.';
    header('Location: customers.php');
    exit();
}

// Calculate total outstanding (manual + invoice)
$manual_outstanding = ($customer['outstanding_type'] == 'debit') ? -$customer['outstanding_amount'] : $customer['outstanding_amount'];
$total_outstanding_customer = $manual_outstanding + $customer['invoice_outstanding'];
$credit_limit = $customer['credit_limit'] ?? 0;
$credit_utilization = $credit_limit > 0 ? ($total_outstanding_customer / $credit_limit) * 100 : 0;

// ==================== PURCHASE HISTORY ====================
$purchase_sql = "
    SELECT
        i.*,
        u.full_name as seller_name,
        s.shop_name,
        (SELECT COUNT(*) FROM invoice_items ii WHERE ii.invoice_id = i.id) as item_count,
        (SELECT GROUP_CONCAT(CONCAT(p.product_name, ' (', ii.quantity, 'x)') SEPARATOR ', ')
         FROM invoice_items ii
         JOIN products p ON ii.product_id = p.id AND p.business_id = i.business_id
         WHERE ii.invoice_id = i.id LIMIT 3) as products_list
    FROM invoices i
    LEFT JOIN users u ON i.seller_id = u.id AND u.business_id = i.business_id
    LEFT JOIN shops s ON i.shop_id = s.id AND s.business_id = i.business_id
    WHERE i.customer_id = ? AND i.business_id = ?
    ORDER BY i.created_at DESC";

$purchase_stmt = $pdo->prepare($purchase_sql);
$purchase_stmt->execute([$customer_id, $business_id]);
$purchases = $purchase_stmt->fetchAll(PDO::FETCH_ASSOC);

// Days since last purchase
$days_ago = $customer['last_purchase'] ? round((time() - strtotime($customer['last_purchase'])) / 86400) : 0;

// Referral details
$referral = null;
if ($customer['referral_id']) {
    $ref_stmt = $pdo->prepare("SELECT * FROM referral_person WHERE id = ? AND business_id = ?");
    $ref_stmt->execute([$customer['referral_id'], $business_id]);
    $referral = $ref_stmt->fetch(PDO::FETCH_ASSOC);
}

// Return history
$returns_sql = "
    SELECT r.*, i.invoice_number, u.full_name as processed_by_name
    FROM returns r
    LEFT JOIN invoices i ON r.invoice_id = i.id
    LEFT JOIN users u ON r.processed_by = u.id
    WHERE r.customer_id = ? AND r.business_id = ?
    ORDER BY r.return_date DESC";

$returns_stmt = $pdo->prepare($returns_sql);
$returns_stmt->execute([$customer_id, $business_id]);
$returns = $returns_stmt->fetchAll(PDO::FETCH_ASSOC);
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
                                <h4 class="mb-0">
                                    <i class="bx bx-user-detail me-2"></i>
                                    Customer Details: <?= htmlspecialchars($customer['name']) ?>
                                </h4>
                                <small class="text-muted">
                                    <i class="bx bx-buildings me-1"></i>
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="customers.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back
                                </a>
                                <a href="pos.php?customer_id=<?= $customer_id ?>" class="btn btn-success">
                                    <i class="bx bx-plus-circle me-1"></i> New Sale
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

                <!-- Customer Information Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-4">
                            <h5 class="card-title mb-0">
                                <i class="bx bx-info-circle me-2"></i> Customer Information
                            </h5>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-warning edit-customer-btn"
                                        data-id="<?= $customer['id'] ?>"
                                        data-name="<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>"
                                        data-phone="<?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES) ?>"
                                        data-email="<?= htmlspecialchars($customer['email'] ?? '', ENT_QUOTES) ?>"
                                        data-address="<?= htmlspecialchars($customer['address'] ?? '', ENT_QUOTES) ?>"
                                        data-gstin="<?= htmlspecialchars($customer['gstin'] ?? '', ENT_QUOTES) ?>"
                                        data-customer_type="<?= htmlspecialchars($customer['customer_type'], ENT_QUOTES) ?>"
                                        data-referral_id="<?= htmlspecialchars($customer['referral_id'] ?? '', ENT_QUOTES) ?>"
                                        data-credit_limit="<?= htmlspecialchars($customer['credit_limit'] ?? '', ENT_QUOTES) ?>"
                                        data-outstanding_type="<?= htmlspecialchars($customer['outstanding_type'] ?? 'credit', ENT_QUOTES) ?>"
                                        data-outstanding_amount="<?= htmlspecialchars($customer['outstanding_amount'] ?? '0', ENT_QUOTES) ?>"
                                        title="Edit Customer">
                                    <i class="bx bx-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger delete-customer-btn"
                                        data-id="<?= $customer['id'] ?>"
                                        data-name="<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>"
                                        title="Delete Customer">
                                    <i class="bx bx-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row align-items-center">
                            <div class="col-lg-8">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr><th style="width:180px;">Customer ID</th><td>#<?= $customer['id'] ?></td></tr>
                                    <tr><th>Name</th><td class="fs-5 fw-bold"><?= htmlspecialchars($customer['name']) ?></td></tr>
                                    <tr><th>Type</th><td><span class="badge bg-<?= $customer['customer_type'] === 'wholesale' ? 'warning' : 'info' ?>"><?= ucfirst($customer['customer_type']) ?></span></td></tr>
                                    <tr><th>Credit Limit</th>
                                        <td>
                                            <?php if ($credit_limit > 0): ?>
                                            <strong class="text-warning">₹<?= number_format($credit_limit, 2) ?></strong>
                                            <?php if ($credit_utilization > 0): ?>
                                            <div class="progress mt-1" style="height: 6px; width: 200px;">
                                                <div class="progress-bar bg-<?= $credit_utilization > 80 ? 'danger' : ($credit_utilization > 50 ? 'warning' : 'success') ?>" 
                                                     role="progressbar" 
                                                     style="width: <?= min($credit_utilization, 100) ?>%">
                                                </div>
                                            </div>
                                            <small class="text-muted"><?= number_format($credit_utilization, 1) ?>% used</small>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-muted">No credit limit</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr><th>Loyalty Points</th><td><strong class="text-info"><?= number_format($customer['available_points'], 2) ?> <i class="bx bx-star"></i></strong></td></tr>
                                    <tr><th>Created</th><td><?= date('d M Y, h:i A', strtotime($customer['created_at'])) ?></td></tr>
                                    <?php if ($referral): ?>
                                    <tr><th>Referred By</th><td><a href="referrals.php?view=<?= $referral['id'] ?>" class="text-decoration-none"><i class="bx bx-user-check me-1"></i><?= htmlspecialchars($referral['full_name']) ?></a></td></tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <div class="col-lg-4 text-lg-end">
                                <?php if ($total_outstanding_customer > 0): ?>
                                <div class="border-start border-danger border-5 ps-4">
                                    <h6 class="text-muted mb-1">Total Outstanding</h6>
                                    <h3 class="text-danger mb-1">₹<?= number_format($total_outstanding_customer, 2) ?></h3>
                                    <?php if ($customer['outstanding_amount'] > 0): ?>
                                    <small class="text-info d-block mb-2">
                                        <i class="bx bx-info-circle me-1"></i>
                                        <?= $customer['outstanding_type'] == 'credit' ? 'Credit' : 'Debit' ?>: ₹<?= number_format($customer['outstanding_amount'], 2) ?>
                                    </small>
                                    <?php endif; ?>
                                    <div class="d-flex gap-2 justify-content-end mt-3">
                                        <a href="customer_credit_statement.php?customer_id=<?= $customer_id ?>" class="btn btn-outline-danger btn-sm">
                                            <i class="bx bx-receipt me-1"></i> View Statement
                                        </a>
                                        <a href="collect_payment.php?customer_id=<?= $customer_id ?>" class="btn btn-danger btn-sm">
                                            <i class="bx bx-money me-1"></i> Collect Payment
                                        </a>
                                    </div>
                                </div>
                                <?php elseif ($total_outstanding_customer < 0): ?>
                                <div class="border-start border-success border-5 ps-4">
                                    <h6 class="text-success mb-1">Advance Balance</h6>
                                    <h3 class="text-success mb-1">₹<?= number_format(abs($total_outstanding_customer), 2) ?></h3>
                                    <small class="text-muted">Customer has advance payment</small>
                                </div>
                                <?php else: ?>
                                <div class="border-start border-success border-5 ps-4">
                                    <h6 class="text-success mb-1"><i class="bx bx-check-circle me-2"></i> No Outstanding Dues</h6>
                                    <p class="text-muted small">All payments cleared</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="row">
                            <div class="col-md-6">
                                <?php if ($customer['phone']): ?>
                                <div class="mb-3">
                                    <strong>Phone:</strong>
                                    <a href="tel:<?= htmlspecialchars($customer['phone']) ?>" class="ms-2">
                                        <i class="bx bx-phone text-primary"></i> <?= htmlspecialchars($customer['phone']) ?>
                                    </a>
                                    <a href="https://wa.me/91<?= preg_replace('/\D/', '', $customer['phone']) ?>?text=Hi%20<?= urlencode($customer['name']) ?>"
                                       target="_blank" class="btn btn-sm btn-success ms-3">
                                        <i class="bx bxl-whatsapp"></i> WhatsApp
                                    </a>
                                </div>
                                <?php endif; ?>

                                <?php if ($customer['email']): ?>
                                <div class="mb-3">
                                    <strong>Email:</strong>
                                    <a href="mailto:<?= htmlspecialchars($customer['email']) ?>" class="ms-2">
                                        <i class="bx bx-envelope text-info"></i> <?= htmlspecialchars($customer['email']) ?>
                                    </a>
                                </div>
                                <?php endif; ?>

                                <?php if ($customer['gstin']): ?>
                                <div class="mb-3">
                                    <strong>GSTIN:</strong> <?= htmlspecialchars($customer['gstin']) ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <?php if ($customer['address']): ?>
                                <strong>Address:</strong>
                                <div class="border rounded p-3 bg-light mt-2">
                                    <?= nl2br(htmlspecialchars($customer['address'])) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-xl-2 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Invoices</h6>
                                <h3 class="text-primary"><?= $customer['total_invoices'] ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Spent</h6>
                                <h3 class="text-success">₹<?= number_format($customer['total_spent'], 0) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Loyalty Points</h6>
                                <h3 class="text-info"><?= number_format($customer['available_points'], 2) ?> <i class="bx bx-star"></i></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Credit Limit</h6>
                                <h3 class="text-warning">₹<?= number_format($credit_limit, 0) ?></h3>
                                <?php if ($credit_limit > 0): ?>
                                <small class="text-muted"><?= number_format($credit_utilization, 1) ?>% used</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start <?= $total_outstanding_customer > 0 ? 'border-danger' : ($total_outstanding_customer < 0 ? 'border-success' : 'border-success') ?> border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Balance</h6>
                                <h3 class="<?= $total_outstanding_customer > 0 ? 'text-danger' : ($total_outstanding_customer < 0 ? 'text-success' : 'text-success') ?>">
                                    <?= $total_outstanding_customer < 0 ? '-' : '' ?>₹<?= number_format(abs($total_outstanding_customer), 2) ?>
                                </h3>
                                <small class="text-muted">
                                    <?= $total_outstanding_customer > 0 ? 'Customer owes' : ($total_outstanding_customer < 0 ? 'Advance payment' : 'Cleared') ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Purchase History -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="card-title mb-0">
                                <i class="bx bx-history me-2"></i> Purchase History
                                <span class="badge bg-primary ms-2"><?= count($purchases) ?> invoices</span>
                            </h5>
                            <?php if (!empty($purchases)): ?>
                            <button class="btn btn-sm btn-outline-primary" onclick="window.print()">
                                <i class="bx bx-printer me-1"></i> Print
                            </button>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($purchases)): ?>
                        <div class="text-center py-5">
                            <i class="bx bx-receipt display-4 text-muted mb-3"></i>
                            <h5 class="text-muted">No Purchase History</h5>
                            <p class="text-muted mb-4">This customer hasn't made any purchases yet.</p>
                            <a href="pos.php?customer_id=<?= $customer_id ?>" class="btn btn-success">
                                <i class="bx bx-plus-circle me-1"></i> Create First Sale
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="purchaseTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Invoice #</th>
                                        <th>Date & Time</th>
                                        <th>Items</th>
                                        <th>Payment</th>
                                        <th>Total (₹)</th>
                                        <th>Status</th>
                                        <th>Seller</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $total_grand = 0;
                                    $i = 1;
                                    foreach ($purchases as $purchase):
                                        $total = $purchase['total'];
                                        $total_grand += $total;
                                        $pending = $purchase['pending_amount'] ?? 0;
                                        $status = $pending == 0 ? 'paid' : ($pending == $total ? 'pending' : 'partial');
                                    ?>
                                    <tr>
                                        <td><?= $i++ ?></td>
                                        <td>
                                            <a href="invoice_view.php?invoice_id=<?= $purchase['id'] ?>" class="fw-bold">
                                                <?= htmlspecialchars($purchase['invoice_number']) ?>
                                            </a>
                                            <br><small class="text-muted"><?= $purchase['invoice_type'] == 'tax_invoice' ? 'Tax' : 'Retail' ?></small>
                                        </td>
                                        <td>
                                            <?= date('d M Y', strtotime($purchase['created_at'])) ?><br>
                                            <small class="text-muted"><?= date('h:i A', strtotime($purchase['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= $purchase['item_count'] ?> items</span>
                                            <?php if ($purchase['products_list']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($purchase['products_list']) ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $purchase['payment_method'] == 'cash' ? 'success' : 'primary' ?>">
                                                <?= ucfirst($purchase['payment_method']) ?>
                                            </span>
                                        </td>
                                        <td class="text-success fw-bold">₹<?= number_format($total, 2) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $status == 'paid' ? 'success' : ($status == 'partial' ? 'warning' : 'danger') ?>">
                                                <?= ucfirst($status) ?>
                                            </span>
                                            <?php if ($pending > 0): ?>
                                            <br><small>₹<?= number_format($pending, 2) ?> due</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($purchase['seller_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="invoice_print.php?invoice_id=<?= $purchase['id'] ?>" target="_blank" class="btn btn-outline-primary" title="Print"><i class="bx bx-printer"></i></a>
                                                <a href="invoice_view.php?invoice_id=<?= $purchase['id'] ?>" class="btn btn-outline-info" title="View"><i class="bx bx-show"></i></a>
                                                <?php if ($pending > 0): ?>
                                                <a href="collect_payment.php?invoice_id=<?= $purchase['id'] ?>" class="btn btn-outline-success" title="Collect"><i class="bx bx-money"></i></a>
                                                <?php endif; ?>
                                                <button class="btn btn-outline-warning return-btn" data-invoice-id="<?= $purchase['id'] ?>" title="Return"><i class="bx bx-refresh"></i></button>
                                                <button class="btn btn-outline-danger delete-invoice-btn" data-invoice-id="<?= $purchase['id'] ?>" data-invoice-number="<?= htmlspecialchars($purchase['invoice_number']) ?>" title="Delete"><i class="bx bx-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-active fw-bold">
                                        <td colspan="5" class="text-end">Grand Total:</td>
                                        <td class="text-success">₹<?= number_format($total_grand, 2) ?></td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Return History -->
                <?php if (!empty($returns)): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="bx bx-refresh me-2"></i> Return History
                            <span class="badge bg-warning ms-2"><?= count($returns) ?> returns</span>
                        </h5>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Return #</th>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Reason</th>
                                        <th>Notes</th>
                                        <th>Processed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($returns as $return): ?>
                                    <tr>
                                        <td>#<?= $return['id'] ?></td>
                                        <td><?= htmlspecialchars($return['invoice_number'] ?? 'N/A') ?></td>
                                        <td><?= date('d M Y, h:i A', strtotime($return['return_date'])) ?></td>
                                        <td class="text-danger">₹<?= number_format($return['total_return_amount'], 2) ?></td>
                                        <td><?= htmlspecialchars($return['return_reason']) ?></td>
                                        <td><?= htmlspecialchars($return['notes'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($return['processed_by_name'] ?? 'N/A') ?></td>
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
        <?php include('includes/footer.php') ?>
    </div>
</div>

<!-- Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="returnModalLabel"><i class="bx bx-refresh me-2"></i> <span id="returnModalTitle">Return/Exchange Items</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="process_return.php" id="returnForm">
                <input type="hidden" name="invoice_id" id="returnInvoiceId">
                <input type="hidden" name="customer_id" value="<?= $customer_id ?>">
                <div class="modal-body" id="returnModalBody">
                    <div class="text-center py-5 text-muted">
                        <i class="bx bx-package display-4 mb-3"></i>
                        <p>Click the Return button on an invoice to load items.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="processReturnBtn" disabled>Process Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="customers.php" id="editCustomerForm">
                <input type="hidden" name="action" value="edit_customer">
                <input type="hidden" name="id" id="editCustomerId" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-user-plus me-2"></i>
                        <span id="modalTitle">Edit Customer</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="editCustName" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" name="phone" id="editCustPhone" class="form-control" maxlength="15">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" id="editCustEmail" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Customer Type</label>
                                <select name="customer_type" id="editCustType" class="form-select">
                                    <option value="retail">Retail Customer</option>
                                    <option value="wholesale">Wholesale Customer</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">GSTIN Number</label>
                                <input type="text" name="gstin" id="editCustGstin" class="form-control text-uppercase" maxlength="15" placeholder="22ABCDE1234F1Z5">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Credit Limit (₹)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" name="credit_limit" id="editCustCreditLimit" class="form-control" 
                                           min="0" step="0.01" placeholder="0.00">
                                </div>
                                <small class="text-muted">Set 0 or leave empty for no credit limit</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Outstanding Type</label>
                                <select name="outstanding_type" id="editCustOutstandingType" class="form-select">
                                    <option value="credit">Credit (Customer owes you)</option>
                                    <option value="debit">Debit (You owe customer)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">Outstanding Amount (₹)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" name="outstanding_amount" id="editCustOutstandingAmount" 
                                           class="form-control" min="0" step="0.01" placeholder="0.00" value="0">
                                </div>
                                <small class="text-muted">Enter any outstanding balance</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php
                    // Get referral persons for dropdown
                    $referrals_stmt = $pdo->prepare("SELECT id, full_name, phone FROM referral_person WHERE business_id = ? AND is_active = 1 ORDER BY full_name");
                    $referrals_stmt->execute([$business_id]);
                    $referrals = $referrals_stmt->fetchAll();
                    ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Referral (Optional)</label>
                        <select name="referral_id" id="editCustReferral" class="form-select">
                            <option value="">Select Referral Person</option>
                            <?php foreach ($referrals as $ref): ?>
                            <option value="<?= $ref['id'] ?>"><?= htmlspecialchars($ref['full_name']) ?> (<?= htmlspecialchars($ref['phone']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" id="editCustAddress" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bx bx-save me-2"></i> Update Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>

<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    $('#purchaseTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[2, 'desc']],
        language: {
            search: "Search invoices:",
            paginate: { previous: "<i class='bx bx-chevron-left'></i>", next: "<i class='bx bx-chevron-right'></i>" }
        }
    });

    $('[data-bs-toggle="tooltip"]').tooltip();

    // Delete customer confirmation
    $('.delete-customer-btn').click(function() {
        const name = $(this).data('name');
        const id = $(this).data('id');
        if (confirm(`Are you sure you want to delete customer "${name}"? This cannot be undone.`)) {
            window.location.href = `view-customer.php?delete_id=${id}`;
        }
    });

    // Delete invoice confirmation
    $('.delete-invoice-btn').click(function() {
        const number = $(this).data('invoice-number');
        const id = $(this).data('invoice-id');
        if (confirm(`Delete invoice #${number}? This action cannot be undone.`)) {
            window.location.href = `view-customer.php?id=<?= $customer_id ?>&delete_invoice_id=${id}`;
        }
    });

    // Edit customer button
    $('.edit-customer-btn').click(function() {
        $('#editCustomerId').val($(this).data('id'));
        $('#editCustName').val($(this).data('name'));
        $('#editCustPhone').val($(this).data('phone'));
        $('#editCustEmail').val($(this).data('email'));
        $('#editCustAddress').val($(this).data('address'));
        $('#editCustGstin').val($(this).data('gstin'));
        $('#editCustType').val($(this).data('customer_type'));
        $('#editCustReferral').val($(this).data('referral_id'));
        $('#editCustCreditLimit').val($(this).data('credit_limit'));
        $('#editCustOutstandingType').val($(this).data('outstanding_type'));
        $('#editCustOutstandingAmount').val($(this).data('outstanding_amount'));

        const modal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
        modal.show();
    });

    // Return modal
    $('.return-btn').click(function() {
        const invoiceId = $(this).data('invoice-id');
        const invoiceNumber = $(this).closest('tr').find('td:eq(1) a.fw-bold').text().trim();
        $('#returnInvoiceId').val(invoiceId);
        $('#returnModalTitle').text(`Return/Exchange - Invoice #${invoiceNumber}`);
        $('#returnModalBody').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-3">Loading items...</p></div>');
        $('#processReturnBtn').prop('disabled', true);

        const modal = new bootstrap.Modal(document.getElementById('returnModal'));
        modal.show();

        $.ajax({
            url: 'get_invoice_items.php',
            method: 'GET',
            data: { invoice_id: invoiceId, for_return: 1 },
            success: function(response) {
                $('#returnModalBody').html(response);
                $('#processReturnBtn').prop('disabled', false);
            },
            error: function() {
                $('#returnModalBody').html('<div class="alert alert-danger">Failed to load items.</div>');
            }
        });
    });

    // Auto-close alerts
    setTimeout(() => $('.alert').alert('close'), 5000);
});
</script>

<style>
.card-hover:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important; }
.border-start { border-left-width: 4px !important; }
.progress { height: 6px; }
.progress-bar { transition: width 0.3s ease; }
</style>
</body>
</html>