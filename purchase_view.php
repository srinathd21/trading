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

$purchase_id = $_GET['id'] ?? 0;
$business_id = $_SESSION['business_id'] ?? 1;

if (!$purchase_id || !is_numeric($purchase_id)) {
    header('Location: purchases.php');
    exit();
}

// Fetch Purchase Header with business_id filter - REMOVED shop_id JOIN
$stmt = $pdo->prepare("
    SELECT p.*, 
           m.name as manufacturer_name,
           m.contact_person,
           m.phone as m_phone, 
           m.email as m_email,
           m.address as m_address,
           m.gstin as m_gstin,
           m.account_holder_name,
           m.bank_name,
           m.account_number,
           m.ifsc_code,
           m.branch_name,
           u.full_name as created_by_name
    FROM purchases p
    LEFT JOIN manufacturers m ON p.manufacturer_id = m.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.id = ? AND p.business_id = ?
");
$stmt->execute([$purchase_id, $business_id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    header('Location: purchases.php');
    exit();
}

// Fetch GST Credit details for this purchase
$stmt_gst = $pdo->prepare("
    SELECT * FROM gst_credits 
    WHERE purchase_id = ? AND business_id = ?
    ORDER BY created_at DESC
");
$stmt_gst->execute([$purchase_id, $business_id]);
$gst_credit = $stmt_gst->fetch();

// Fetch Items with business_id filter - REMOVED shop_id from JOIN condition
// Modified to also get batch information for MRP comparison
$stmt_items = $pdo->prepare("
    SELECT pi.*, 
           p.product_name, 
           p.product_code, 
           p.hsn_code,
           p.unit_of_measure,
           p.mrp as current_product_mrp,
           ps.quantity as current_stock,
           pb.old_mrp,
           pb.new_mrp,
           pb.batch_number,
           pb.expiry_date
    FROM purchase_items pi
    JOIN products p ON pi.product_id = p.id AND p.business_id = ?
    LEFT JOIN product_stocks ps ON ps.product_id = p.id AND ps.business_id = p.business_id
    LEFT JOIN purchase_batches pb ON pb.purchase_id = pi.purchase_id 
        AND pb.product_id = pi.product_id 
        AND pb.business_id = p.business_id
    WHERE pi.purchase_id = ? AND pi.business_id = ?
    ORDER BY pi.id
");
$stmt_items->execute([$business_id, $purchase_id, $business_id]);
$items = $stmt_items->fetchAll();

// Calculate totals
$subtotal = $cgst_total = $sgst_total = $igst_total = 0;
foreach ($items as $item) {
    $taxable = $item['quantity'] * $item['purchase_price'];
    $subtotal      += $taxable;
    $cgst_total    += $item['cgst_amount'];
    $sgst_total    += $item['sgst_amount'];
    $igst_total    += $item['igst_amount'];
}

// Payment status color mapping
$status_classes = [
    'paid' => 'success',
    'partial' => 'warning',
    'unpaid' => 'danger'
];
$status_color = $status_classes[$purchase['payment_status']] ?? 'secondary';
$status_icon = [
    'paid' => 'bx-check-circle',
    'partial' => 'bx-time-five',
    'unpaid' => 'bx-x-circle'
][$purchase['payment_status']] ?? 'bx-receipt';

// GST Credit status color mapping
$gst_status_classes = [
    'claimed' => 'success',
    'not_claimed' => 'warning'
];
$gst_status_color = $gst_status_classes[$gst_credit['status'] ?? 'not_claimed'] ?? 'secondary';
$gst_status_icon = [
    'claimed' => 'bx-check-circle',
    'not_claimed' => 'bx-time-five'
][$gst_credit['status'] ?? 'not_claimed'] ?? 'bx-receipt';

// Calculate percentages for progress bars
$paid_percent = $purchase['total_amount'] > 0 ? ($purchase['paid_amount'] / $purchase['total_amount']) * 100 : 0;
$pending_amount = $purchase['total_amount'] - $purchase['paid_amount'];
$pending_percent = $purchase['total_amount'] > 0 ? ($pending_amount / $purchase['total_amount']) * 100 : 0;

// Check if bank details exist
$has_bank_details = !empty($purchase['account_holder_name']) || 
                    !empty($purchase['bank_name']) || 
                    !empty($purchase['account_number']) || 
                    !empty($purchase['ifsc_code']) || 
                    !empty($purchase['branch_name']);

// Check if optional fields exist
$has_purchase_invoice = !empty($purchase['purchase_invoice_no']);
$has_reference = !empty($purchase['reference']);
$has_bill_image = !empty($purchase['bill_image']);
$has_notes = !empty($purchase['notes']);

// Determine file type for bill image
$bill_file_type = '';
$bill_file_name = '';
$bill_file_ext = '';

if ($has_bill_image) {
    $bill_file_name = basename($purchase['bill_image']);
    $bill_file_ext = strtolower(pathinfo($bill_file_name, PATHINFO_EXTENSION));
    
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    
    if (in_array($bill_file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $bill_file_type = 'image';
    } elseif ($bill_file_ext === 'pdf') {
        $bill_file_type = 'pdf';
    } else {
        $bill_file_type = 'other';
    }
}
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Purchase Order #{$purchase['purchase_number']}"; 
include 'includes/head.php'; 
?>
<style>
.avatar-sm {
    width: 48px;
    height: 48px;
}
.badge.bg-opacity-10 {
    opacity: 0.9;
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
.item-row:hover {
    background-color: rgba(0,0,0,0.02);
}
.mrp-change-indicator {
    font-size: 0.8rem;
    padding: 2px 6px;
    border-radius: 3px;
    margin-left: 5px;
}
.mrp-increased {
    background: #d1f7c4;
    color: #0d7a3f;
}
.mrp-decreased {
    background: #ffe6e6;
    color: #c00;
}
.mrp-unchanged {
    background: #f0f0f0;
    color: #666;
}
.gst-credit-card {
    border-left: 4px solid #20c997 !important;
}
.file-type-badge {
    font-size: 0.75rem;
    padding: 2px 8px;
    border-radius: 12px;
}
.file-type-image {
    background: #4CAF50;
    color: white;
}
.file-type-pdf {
    background: #F44336;
    color: white;
}
.file-type-other {
    background: #9E9E9E;
    color: white;
}
@media print {
    .no-print, .vertical-menu, .topbar, .page-title-box .btn-group,
    .card-header, .btn-group, .alert, .text-end .btn-group {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .badge {
        border: 1px solid #ccc !important;
        background-color: #fff !important;
        color: #000 !important;
    }
}
</style>
</head>
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
                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-receipt me-2"></i> Purchase Order Details
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-hash me-1"></i> <?= htmlspecialchars($purchase['purchase_number']) ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    <i class="bx bx-buildings me-1"></i> 
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="purchases.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to List
                                </a>
                               
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PO Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">PO Amount</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($purchase['total_amount'], 2) ?></h3>
                                        <small class="text-muted">
                                            <?= count($items) ?> items
                                            <?php if ($purchase['total_gst'] > 0): ?>
                                            | GST: ₹<?= number_format($purchase['total_gst'], 2) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Paid Amount</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($purchase['paid_amount'], 2) ?></h3>
                                        <small class="text-muted">
                                            <?= number_format($paid_percent, 1) ?>% paid
                                        </small>
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
                                        <h6 class="text-muted mb-1">Balance Due</h6>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($pending_amount, 2) ?></h3>
                                        <small class="text-muted">
                                            <?= number_format($pending_percent, 1) ?>% pending
                                        </small>
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
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Payment Status</h6>
                                        <div class="mb-2">
                                            <span class="badge bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> px-3 py-2">
                                                <i class="bx <?= $status_icon ?> me-1"></i><?= ucfirst($purchase['payment_status']) ?>
                                            </span>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar bg-success" style="width: <?= $paid_percent ?>%"></div>
                                            <div class="progress-bar bg-warning" style="width: <?= $pending_percent ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-pie-chart-alt text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GST Credit Card -->
                <?php if ($gst_credit): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card card-hover gst-credit-card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-credit-card me-2"></i> GST Credit Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-2 p-3">
                                                    <i class="bx bx-credit-card-alt text-success"></i>
                                                </span>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h5 class="mb-1">₹<?= number_format($gst_credit['credit_amount'], 2) ?></h5>
                                                <p class="text-muted mb-0">Available GST Credit</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <div class="d-flex flex-column align-items-end">
                                            <span class="badge bg-<?= $gst_status_color ?> bg-opacity-10 text-<?= $gst_status_color ?> px-3 py-2 mb-2">
                                                <i class="bx <?= $gst_status_icon ?> me-1"></i>
                                                <?= ucfirst(str_replace('_', ' ', $gst_credit['status'])) ?>
                                            </span>
                                            <?php if ($gst_credit['purchase_invoice_no']): ?>
                                            <p class="text-muted mb-0">
                                                <i class="bx bx-receipt me-1"></i>
                                                Invoice: <?= htmlspecialchars($gst_credit['purchase_invoice_no']) ?>
                                            </p>
                                            <?php endif; ?>
                                            <p class="text-muted mb-0">
                                                <i class="bx bx-calendar me-1"></i>
                                                Created: <?= date('d M Y', strtotime($gst_credit['created_at'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- PO Details Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bx bx-detail me-2"></i> Purchase Order Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="border rounded p-4 bg-light h-100">
                                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">
                                        <i class="bx bx-building me-2"></i>Supplier Details
                                    </h6>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label small text-muted mb-1">Supplier Name</label>
                                            <p class="fw-bold mb-0 fs-5"><?= htmlspecialchars($purchase['manufacturer_name']) ?></p>
                                        </div>
                                        <?php if ($purchase['contact_person']): ?>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Contact Person</label>
                                            <p class="mb-0"><?= htmlspecialchars($purchase['contact_person']) ?></p>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($purchase['m_phone']): ?>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Phone</label>
                                            <p class="mb-0"><?= htmlspecialchars($purchase['m_phone']) ?></p>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($purchase['m_email']): ?>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Email</label>
                                            <p class="mb-0"><?= htmlspecialchars($purchase['m_email']) ?></p>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($purchase['m_gstin']): ?>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">GSTIN</label>
                                            <p class="mb-0"><?= htmlspecialchars($purchase['m_gstin']) ?></p>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($purchase['m_address']): ?>
                                        <div class="col-12">
                                            <label class="form-label small text-muted mb-1">Address</label>
                                            <p class="mb-0"><?= nl2br(htmlspecialchars($purchase['m_address'])) ?></p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Bank Details Section -->
                                        <?php if ($has_bank_details): ?>
                                        <div class="col-12 mt-4">
                                            <h6 class="fw-bold text-success mb-3 border-bottom pb-2">
                                                <i class="bx bx-bank me-2"></i>Bank Details
                                            </h6>
                                            <div class="row g-3">
                                                <?php if ($purchase['account_holder_name']): ?>
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted mb-1">Account Holder</label>
                                                    <p class="fw-bold mb-0"><?= htmlspecialchars($purchase['account_holder_name']) ?></p>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($purchase['bank_name']): ?>
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted mb-1">Bank Name</label>
                                                    <p class="mb-0"><?= htmlspecialchars($purchase['bank_name']) ?></p>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($purchase['account_number']): ?>
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted mb-1">Account Number</label>
                                                    <p class="mb-0">
                                                        <code><?= htmlspecialchars($purchase['account_number']) ?></code>
                                                    </p>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($purchase['ifsc_code']): ?>
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted mb-1">IFSC Code</label>
                                                    <p class="mb-0">
                                                        <span class="badge bg-info bg-opacity-10 text-info"><?= htmlspecialchars($purchase['ifsc_code']) ?></span>
                                                    </p>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($purchase['branch_name']): ?>
                                                <div class="col-12">
                                                    <label class="form-label small text-muted mb-1">Branch Name</label>
                                                    <p class="mb-0"><?= htmlspecialchars($purchase['branch_name']) ?></p>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="border rounded p-4 bg-light h-100">
                                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">
                                        <i class="bx bx-info-circle me-2"></i>PO Information
                                    </h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">PO Number</label>
                                            <p class="fw-bold mb-0"><?= htmlspecialchars($purchase['purchase_number']) ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Date</label>
                                            <p class="mb-0">
                                                <i class="bx bx-calendar me-1"></i>
                                                <?= date('d M Y', strtotime($purchase['purchase_date'])) ?>
                                            </p>
                                        </div>
                                        
                                        <!-- Purchase Invoice No -->
                                        <?php if ($has_purchase_invoice): ?>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Purchase Invoice No.</label>
                                            <p class="mb-0">
                                                <i class="bx bx-receipt me-1"></i>
                                                <?= htmlspecialchars($purchase['purchase_invoice_no']) ?>
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Bill/Reference No -->
                                        <?php if ($has_reference): ?>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Bill/Reference No.</label>
                                            <p class="mb-0">
                                                <i class="bx bx-hash me-1"></i>
                                                <?= htmlspecialchars($purchase['reference']) ?>
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Bill Image with View Button -->
                                        <?php if ($has_bill_image): ?>
                                        <div class="col-12">
                                            <label class="form-label small text-muted mb-1">Bill Image/Document</label>
                                            <div class="d-flex align-items-center gap-3 mb-2">
                                                <div>
                                                    <span class="file-type-badge file-type-<?= $bill_file_type ?>">
                                                        <?php 
                                                        if ($bill_file_type === 'image') {
                                                            echo '<i class="bx bx-image me-1"></i>Image';
                                                        } elseif ($bill_file_type === 'pdf') {
                                                            echo '<i class="bx bx-file me-1"></i>PDF';
                                                        } else {
                                                            echo '<i class="bx bx-file me-1"></i>Document';
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <small class="text-muted">
                                                        <i class="bx bx-file me-1"></i>
                                                        <?= htmlspecialchars($bill_file_name) ?>
                                                        (<?= strtoupper($bill_file_ext) ?>)
                                                    </small>
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-outline-primary btn-sm" 
                                                    onclick="viewBill('<?= htmlspecialchars($purchase['bill_image']) ?>', '<?= $bill_file_type ?>')">
                                                <i class="bx bx-show me-1"></i> View Bill
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Created By</label>
                                            <p class="mb-0">
                                                <i class="bx bx-user me-1"></i>
                                                <?= htmlspecialchars($purchase['created_by_name']) ?>
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Created On</label>
                                            <p class="mb-0">
                                                <i class="bx bx-time me-1"></i>
                                                <?= date('d M Y, h:i A', strtotime($purchase['created_at'])) ?>
                                            </p>
                                        </div>
                                        
                                        <!-- Notes -->
                                        <?php if ($has_notes): ?>
                                        <div class="col-12">
                                            <label class="form-label small text-muted mb-1">Notes</label>
                                            <div class="alert alert-info py-2 px-3 mb-0">
                                                <i class="bx bx-note me-2"></i><?= nl2br(htmlspecialchars($purchase['notes'])) ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Table Card -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bx bx-package me-2"></i> Purchase Items
                                <small class="text-muted ms-2">(<?= count($items) ?> items)</small>
                            </h5>
                            <span class="badge bg-primary">
                                Total: ₹<?= number_format($purchase['total_amount'], 2) ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Product Details</th>
                                        <th class="text-center">HSN</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-center">Unit</th>
                                        <th class="text-end">Purchase Price</th>
                                        <th class="text-end">MRP Comparison</th>
                                        <th class="text-end">Taxable</th>
                                        <th class="text-center">Tax Rate</th>
                                        <th class="text-end">Tax Amount</th>
                                        <th class="text-end">Total</th>
                                        <th class="text-center">Stock</th>
                                        <th class="text-center">Batch</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $i => $item): 
                                        $taxable = $item['quantity'] * $item['purchase_price'];
                                        $total_gst = $item['cgst_amount'] + $item['sgst_amount'] + $item['igst_amount'];
                                        $stock_class = $item['current_stock'] <= 10 ? 'danger' : 'success';
                                        
                                        // MRP Comparison
                                        $old_mrp = $item['old_mrp'] ?: $item['current_product_mrp'];
                                        $new_mrp = $item['new_mrp'] ?: $item['mrp'];
                                        $mrp_diff = $new_mrp - $old_mrp;
                                        $mrp_percent_change = $old_mrp > 0 ? ($mrp_diff / $old_mrp) * 100 : 0;
                                        
                                        if ($mrp_diff > 0) {
                                            $mrp_change_class = 'mrp-increased';
                                            $mrp_change_icon = 'bx-up-arrow-alt';
                                            $mrp_change_text = '+' . number_format($mrp_percent_change, 1) . '%';
                                        } elseif ($mrp_diff < 0) {
                                            $mrp_change_class = 'mrp-decreased';
                                            $mrp_change_icon = 'bx-down-arrow-alt';
                                            $mrp_change_text = number_format($mrp_percent_change, 1) . '%';
                                        } else {
                                            $mrp_change_class = 'mrp-unchanged';
                                            $mrp_change_icon = 'bx-minus';
                                            $mrp_change_text = 'No change';
                                        }
                                    ?>
                                    <tr class="item-row">
                                        <td class="text-center fw-bold"><?= $i + 1 ?></td>
                                        <td>
                                            <strong class="d-block"><?= htmlspecialchars($item['product_name']) ?></strong>
                                            <small class="text-muted">
                                                <i class="bx bx-hash me-1"></i><?= htmlspecialchars($item['product_code'] ?: 'N/A') ?>
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <?= $item['hsn_code'] ?: '<span class="text-muted">—</span>' ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary rounded-pill px-3 py-1 fs-6"><?= $item['quantity'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= $item['unit_of_measure'] ?></span>
                                        </td>
                                        <td class="text-end fw-bold">
                                            ₹<?= number_format($item['purchase_price'], 2) ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex flex-column align-items-end">
                                                <div>
                                                    <span class="fw-bold">₹<?= number_format($new_mrp, 2) ?></span>
                                                    <span class="mrp-change-indicator <?= $mrp_change_class ?>">
                                                        <i class="bx <?= $mrp_change_icon ?> me-1"></i><?= $mrp_change_text ?>
                                                    </span>
                                                </div>
                                                <?php if ($old_mrp != $new_mrp): ?>
                                                <small class="text-muted">
                                                    Old: ₹<?= number_format($old_mrp, 2) ?>
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-end">₹<?= number_format($taxable, 2) ?></td>
                                        <td class="text-center">
                                            <div class="d-flex flex-column small">
                                                <?php if ($item['cgst_rate'] > 0): ?>
                                                <span class="text-success">C: <?= $item['cgst_rate'] ?>%</span>
                                                <?php endif; ?>
                                                <?php if ($item['sgst_rate'] > 0): ?>
                                                <span class="text-info">S: <?= $item['sgst_rate'] ?>%</span>
                                                <?php endif; ?>
                                                <?php if ($item['igst_rate'] > 0): ?>
                                                <span class="text-warning">I: <?= $item['igst_rate'] ?>%</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex flex-column small">
                                                <?php if ($item['cgst_amount'] > 0): ?>
                                                <span class="text-success">C: ₹<?= number_format($item['cgst_amount'], 2) ?></span>
                                                <?php endif; ?>
                                                <?php if ($item['sgst_amount'] > 0): ?>
                                                <span class="text-info">S: ₹<?= number_format($item['sgst_amount'], 2) ?></span>
                                                <?php endif; ?>
                                                <?php if ($item['igst_amount'] > 0): ?>
                                                <span class="text-warning">I: ₹<?= number_format($item['igst_amount'], 2) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-end fw-bold text-primary">
                                            ₹<?= number_format($item['total_price'], 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $stock_class ?> bg-opacity-10 text-<?= $stock_class ?> px-3 py-1">
                                                <?= $item['current_stock'] ?? 0 ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($item['batch_number']): ?>
                                            <div class="d-flex flex-column small">
                                                <span class="badge bg-info bg-opacity-10 text-info mb-1">
                                                    <?= htmlspecialchars($item['batch_number']) ?>
                                                </span>
                                                <?php if ($item['expiry_date']): ?>
                                                <span class="text-muted">
                                                    Exp: <?= date('M Y', strtotime($item['expiry_date'])) ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="7" class="text-end fw-bold">Subtotal:</td>
                                        <td class="text-end fw-bold">₹<?= number_format($subtotal, 2) ?></td>
                                        <td colspan="2" class="text-end fw-bold">Total GST:</td>
                                        <td class="text-end fw-bold text-success">₹<?= number_format($purchase['total_gst'], 2) ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                    <tr class="table-success">
                                        <td colspan="10" class="text-end fw-bold fs-5">GRAND TOTAL:</td>
                                        <td class="text-end fw-bold fs-5 text-primary">₹<?= number_format($purchase['total_amount'], 2) ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                    <?php if ($gst_credit): ?>
                                    <tr class="table-info">
                                        <td colspan="10" class="text-end fw-bold fs-6">GST Credit Available:</td>
                                        <td class="text-end fw-bold fs-6 text-success">₹<?= number_format($gst_credit['credit_amount'], 2) ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                    <?php endif; ?>
                                </tfoot>
                            </table>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="alert alert-info mb-0">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <i class="bx bx-info-circle fs-4"></i>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="alert-heading mb-1">Payment Status</h6>
                                            <div class="d-flex align-items-center">
                                                <div class="flex-grow-1 me-3">
                                                    <div class="progress" style="height: 8px;">
                                                        <div class="progress-bar bg-success" style="width: <?= $paid_percent ?>%"></div>
                                                        <div class="progress-bar bg-warning" style="width: <?= $pending_percent ?>%"></div>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-success me-2">Paid: ₹<?= number_format($purchase['paid_amount'], 2) ?></span>
                                                    <?php if ($pending_amount > 0): ?>
                                                    <span class="badge bg-warning">Due: ₹<?= number_format($pending_amount, 2) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group">
                                    <a href="purchase_payments_history.php?id=<?= $purchase['id'] ?>" 
                                       class="btn btn-outline-secondary">
                                        <i class="bx bx-history me-1"></i> Payment History
                                    </a>
                                    <?php if ($purchase['payment_status'] !== 'paid'): ?>
                                    <a href="purchase_payment.php?id=<?= $purchase['id'] ?>" 
                                       class="btn btn-outline-success">
                                        <i class="bx bx-money me-1"></i> Add Payment
                                    </a>
                                    <?php endif; ?>
                                    <a href="purchase_edit.php?id=<?= $purchase['id'] ?>" 
                                       class="btn btn-outline-primary">
                                        <i class="bx bx-edit me-1"></i> Edit
                                    </a>
                                </div>
                            </div>
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
    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Row hover effect
    $('.item-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );
});

// Function to open bill in new window
function viewBill(billPath, fileType) {
    if (!billPath) {
        alert('Bill image path not found.');
        return;
    }
    
    // Create a new window for viewing the bill
    const viewerWindow = window.open('', '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
    
    // Create HTML content for the viewer
    const htmlContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>View Bill - <?= htmlspecialchars($purchase['purchase_number']) ?></title>
            <style>
                body {
                    margin: 0;
                    padding: 20px;
                    font-family: Arial, sans-serif;
                    background: #f5f5f5;
                }
                .viewer-header {
                    background: white;
                    padding: 15px;
                    margin-bottom: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .viewer-header h3 {
                    margin: 0;
                    color: #333;
                }
                .close-btn {
                    background: #dc3545;
                    color: white;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 14px;
                }
                .close-btn:hover {
                    background: #c82333;
                }
                .bill-container {
                    background: white;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    text-align: center;
                }
                .bill-image {
                    max-width: 100%;
                    max-height: 500px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
                .pdf-container {
                    width: 100%;
                    height: 500px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
                .unsupported-message {
                    padding: 40px;
                    text-align: center;
                    color: #666;
                }
                .unsupported-message i {
                    font-size: 48px;
                    margin-bottom: 20px;
                    color: #6c757d;
                }
                .download-btn {
                    background: #0d6efd;
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 4px;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-block;
                    margin-top: 20px;
                }
                .download-btn:hover {
                    background: #0b5ed7;
                    text-decoration: none;
                    color: white;
                }
                .file-info {
                    background: #e9ecef;
                    padding: 10px;
                    border-radius: 4px;
                    margin-bottom: 20px;
                    font-size: 14px;
                    color: #495057;
                }
                .controls {
                    margin-top: 20px;
                    display: flex;
                    gap: 10px;
                    justify-content: center;
                }
                .zoom-controls {
                    display: flex;
                    gap: 10px;
                    align-items: center;
                }
                .zoom-btn {
                    background: #6c757d;
                    color: white;
                    border: none;
                    width: 36px;
                    height: 36px;
                    border-radius: 50%;
                    cursor: pointer;
                    font-size: 16px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .zoom-btn:hover {
                    background: #5a6268;
                }
                .zoom-level {
                    font-size: 14px;
                    color: #495057;
                    min-width: 60px;
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <div class="viewer-header">
                <h3>Bill - Purchase Order: <?= htmlspecialchars($purchase['purchase_number']) ?></h3>
                <button class="close-btn" onclick="window.close()">Close</button>
            </div>
            
            <div class="file-info">
                <strong>File:</strong> ${billPath.split('/').pop()} | 
                <strong>Type:</strong> ${fileType.toUpperCase()} | 
                <strong>Date:</strong> <?= date('d M Y', strtotime($purchase['purchase_date'])) ?>
            </div>
            
            <div class="bill-container" id="billContainer">
                ${getViewerContent(billPath, fileType)}
            </div>
            
            <div class="controls">
                <a href="${billPath}" class="download-btn" download>
                    <i class="bx bx-download"></i> Download Bill
                </a>
            </div>
            
            <script>
                let zoomLevel = 1;
                
                function getViewerContent(path, type) {
                    if (type === 'image') {
                        return \`
                            <div class="zoom-controls">
                                <button class="zoom-btn" onclick="zoomOut()">-</button>
                                <span class="zoom-level">\${Math.round(zoomLevel * 100)}%</span>
                                <button class="zoom-btn" onclick="zoomIn()">+</button>
                            </div>
                            <img src="\${path}" 
                                 alt="Purchase Bill" 
                                 class="bill-image" 
                                 id="billImage"
                                 style="transform: scale(\${zoomLevel}); margin: 20px 0;">
                        \`;
                    } else if (type === 'pdf') {
                        return \`
                            <embed src="\${path}" 
                                   type="application/pdf" 
                                   class="pdf-container">
                        \`;
                    } else {
                        return \`
                            <div class="unsupported-message">
                                <i class="bx bx-file"></i>
                                <h4>File Preview Not Available</h4>
                                <p>This file format cannot be previewed in the browser.</p>
                                <p>Please download the file to view it.</p>
                            </div>
                        \`;
                    }
                }
                
                function zoomIn() {
                    if (zoomLevel < 3) {
                        zoomLevel += 0.1;
                        updateZoom();
                    }
                }
                
                function zoomOut() {
                    if (zoomLevel > 0.5) {
                        zoomLevel -= 0.1;
                        updateZoom();
                    }
                }
                
                function updateZoom() {
                    const image = document.getElementById('billImage');
                    const zoomLevelDisplay = document.querySelector('.zoom-level');
                    if (image) {
                        image.style.transform = \`scale(\${zoomLevel})\`;
                    }
                    if (zoomLevelDisplay) {
                        zoomLevelDisplay.textContent = \`\${Math.round(zoomLevel * 100)}%\`;
                    }
                }
                
                // Keyboard shortcuts
                document.addEventListener('keydown', function(e) {
                    if (e.key === '+' || e.key === '=') {
                        e.preventDefault();
                        zoomIn();
                    } else if (e.key === '-' || e.key === '_') {
                        e.preventDefault();
                        zoomOut();
                    } else if (e.key === 'Escape') {
                        window.close();
                    }
                });
                
                // Handle PDF viewer errors
                window.onload = function() {
                    const embed = document.querySelector('embed[type="application/pdf"]');
                    if (embed) {
                        embed.onerror = function() {
                            const container = document.getElementById('billContainer');
                            container.innerHTML = \`
                                <div class="unsupported-message">
                                    <i class="bx bx-error"></i>
                                    <h4>PDF Preview Error</h4>
                                    <p>Unable to load PDF preview. Your browser may not support PDF embedding.</p>
                                    <p>Please download the PDF to view it.</p>
                                </div>
                            \`;
                        };
                    }
                };
            <\/script>
        </body>
        </html>
    `;
    
    // Write the HTML content to the new window
    viewerWindow.document.write(htmlContent);
    viewerWindow.document.close();
    
    // Focus on the new window
    viewerWindow.focus();
}

// Helper function to determine viewer content
function getViewerContent(path, type) {
    if (type === 'image') {
        return `
            <div class="zoom-controls">
                <button class="zoom-btn" onclick="zoomOut()">-</button>
                <span class="zoom-level">100%</span>
                <button class="zoom-btn" onclick="zoomIn()">+</button>
            </div>
            <img src="${path}" 
                 alt="Purchase Bill" 
                 class="bill-image" 
                 id="billImage"
                 style="transform: scale(1); margin: 20px 0;">
        `;
    } else if (type === 'pdf') {
        return `
            <embed src="${path}" 
                   type="application/pdf" 
                   class="pdf-container">
        `;
    } else {
        return `
            <div class="unsupported-message">
                <i class="bx bx-file"></i>
                <h4>File Preview Not Available</h4>
                <p>This file format cannot be previewed in the browser.</p>
                <p>Please download the file to view it.</p>
            </div>
        `;
    }
}
</script>
</body>
</html>