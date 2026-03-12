<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'warehouse_manager'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$current_shop_name = $_SESSION['current_shop_name'] ?? 'All Shops';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: purchase_requests.php');
    exit();
}

// Fetch Purchase Request with Supplier Email
$stmt = $pdo->prepare("
    SELECT pr.*, 
           m.name AS manufacturer_name, 
           m.email AS manufacturer_email,
           m.phone AS manufacturer_phone,
           m.address AS manufacturer_address,
           u.full_name AS requested_by_name,
           u.email AS requested_by_email,
           (SELECT shop_name FROM shops WHERE id = ? LIMIT 1) as shop_name
    FROM purchase_requests pr
    LEFT JOIN manufacturers m ON pr.manufacturer_id = m.id
    LEFT JOIN users u ON pr.requested_by = u.id
    WHERE pr.id = ? AND pr.business_id = ?
");
$stmt->execute([$current_shop_id, $id, $business_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    die('<div class="alert alert-danger m-4">Purchase Request not found!</div>');
}

// Fetch Items with current stock
$items = $pdo->query("
    SELECT pri.*, p.product_name, p.product_code, p.hsn_code,
           (SELECT COALESCE(SUM(quantity), 0) FROM product_stocks 
            WHERE product_id = p.id AND shop_id = " . (int)$current_shop_id . ") as current_stock
    FROM purchase_request_items pri
    JOIN products p ON pri.product_id = p.id
    WHERE pri.purchase_request_id = $id
    ORDER BY pri.id
")->fetchAll();

$statusLabels = [
    'draft' => ['label' => 'Draft', 'color' => 'secondary', 'icon' => 'bx-edit'],
    'sent' => ['label' => 'Sent to Supplier', 'color' => 'info', 'icon' => 'bx-send'],
    'quotation_received' => ['label' => 'Quotation Received', 'color' => 'warning', 'icon' => 'bx-file'],
    'approved' => ['label' => 'Approved', 'color' => 'success', 'icon' => 'bx-check-circle'],
    'rejected' => ['label' => 'Rejected', 'color' => 'danger', 'icon' => 'bx-x-circle']
];

// === SEND EMAIL TO SUPPLIER ===
$email_sent = false;
$email_error = '';

if (isset($_POST['send_email'])) {
    if (empty($request['manufacturer_email'])) {
        $email_error = "Supplier email not found. Please add email in manufacturer profile.";
    } else {
        $to = $request['manufacturer_email'];
        $subject = "Purchase Request - {$request['request_number']} - " . htmlspecialchars($current_shop_name);

        // Beautiful HTML Email
        $message = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: Arial, sans-serif; background: #f4f6f9; color: #333; line-height: 1.6; }
                .container { max-width: 800px; margin: 20px auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
                .header { background: #556ee6; color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; }
                .content { padding: 30px; }
                .info { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
                th { background: #f0f0f0; font-weight: 600; }
                .total { background: #556ee6; color: white; font-weight: bold; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #777; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Purchase Request</h1>
                    <h2>' . htmlspecialchars($request['request_number']) . '</h2>
                </div>
                <div class="content">
                    <p>Dear <strong>' . htmlspecialchars($request['manufacturer_name']) . '</strong>,</p>
                    <p>We have generated a new purchase request. Please review and send your best quotation at the earliest.</p>

                    <div class="info">
                        <strong>Request Details:</strong><br>
                        Request No: <strong>' . htmlspecialchars($request['request_number']) . '</strong><br>
                        Date: ' . date('d M Y, h:i A', strtotime($request['created_at'])) . '<br>
                        Requested By: ' . htmlspecialchars($request['requested_by_name']) . '<br>
                        From: ' . htmlspecialchars($current_shop_name) . '
                    </div>

                    <h3>Requested Products:</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Code</th>
                                <th>HSN</th>
                                <th>Qty</th>
                                <th>Est. Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>';
                        
        foreach ($items as $i => $item) {
            $message .= '
                            <tr>
                                <td>' . ($i + 1) . '</td>
                                <td>' . htmlspecialchars($item['product_name']) . '</td>
                                <td>' . htmlspecialchars($item['product_code']) . '</td>
                                <td>' . ($item['hsn_code'] ?: '—') . '</td>
                                <td>' . $item['quantity'] . '</td>
                                <td>₹' . number_format($item['estimated_price'], 2) . '</td>
                                <td>₹' . number_format($item['quantity'] * $item['estimated_price'], 2) . '</td>
                            </tr>';
        }

        $message .= '
                        </tbody>
                        <tfoot>
                            <tr class="total">
                                <td colspan="6"><strong>GRAND TOTAL</strong></td>
                                <td><strong>₹' . number_format($request['total_estimated_amount'], 2) . '</strong></td>
                            </tr>
                        </tfoot>
                    </table>';

        if ($request['request_notes']) {
            $message .= '<p><strong>Notes:</strong><br>' . nl2br(htmlspecialchars($request['request_notes'])) . '</p>';
        }

        $message .= '
                    <p>Please reply with your quotation including taxes, delivery time, and validity.</p>
                    <p>Thank you for your cooperation!</p>
                    <p><strong>' . htmlspecialchars($current_shop_name) . '</strong><br>
                       purchase@vidhyatraders.com</p>
                </div>
                <div class="footer">
                    This is an automated message from Jai Vidhya Traders ERP System • ' . date('d M Y, h:i A') . '
                </div>
            </div>
        </body>
        </html>';

        // Headers
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . htmlspecialchars($current_shop_name) . " <noreply@yourdomain.com>\r\n";
        $headers .= "Reply-To: purchase@yourdomain.com\r\n";

        if (mail($to, $subject, $message, $headers)) {
            // Update status to 'sent' if it was draft
            if ($request['status'] === 'draft') {
                $pdo->prepare("UPDATE purchase_requests SET status = 'sent' WHERE id = ?")->execute([$id]);
                $request['status'] = 'sent'; // Update current view
            }
            $email_sent = true;
        } else {
            $email_error = "Failed to send email. Check your mail server configuration.";
        }
    }
}
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Purchase Request #{$request['request_number']}"; 
include 'includes/head.php'; 
?>
<style>
.detail-card {
    border-left: 4px solid #556ee6;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.detail-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}
.stock-indicator {
    width: 80px;
    height: 6px;
    background: #e0e0e0;
    border-radius: 3px;
    overflow: hidden;
}
.stock-fill {
    height: 100%;
    background: #34c38f;
    border-radius: 3px;
}
.status-badge {
    font-size: 0.9rem;
    padding: 8px 16px;
    border-radius: 20px;
}
@media print {
    .no-print, .vertical-menu, .topbar, .page-title-box > div:last-child, .btn { 
        display: none !important; 
    }
    body { background: white !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    .card-header { background: #f8f9fa !important; border-bottom: 2px solid #000 !important; }
}
.low-stock-badge {
    font-size: 0.75rem;
    padding: 2px 6px;
}
</style>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
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
                                    <i class="bx bx-file me-2"></i> Purchase Request Details
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-hash me-1"></i> <?= htmlspecialchars($request['request_number']) ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    <i class="bx bx-store me-1"></i> <?= htmlspecialchars($current_shop_name) ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <?php if (in_array($request['status'], ['draft', 'sent'])): ?>
                                <a href="purchase_request_edit.php?id=<?= $id ?>" class="btn btn-warning">
                                    <i class="bx bx-edit me-1"></i> Edit Request
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($request['manufacturer_email'])): ?>
                                <form method="POST" class="d-inline">
                                    <button type="submit" name="send_email" class="btn btn-success" 
                                            onclick="return confirm('Send this Purchase Request to <?= addslashes($request['manufacturer_name']) ?> at <?= addslashes($request['manufacturer_email']) ?>?')">
                                        <i class="bx bx-mail-send me-1"></i> Send to Supplier
                                    </button>
                                </form>
                                <?php else: ?>
                                <button class="btn btn-secondary" disabled title="Supplier email not available">
                                    <i class="bx bx-mail-send me-1"></i> No Email
                                </button>
                                <?php endif; ?>
                                <button onclick="window.print()" class="btn btn-outline-dark">
                                    <i class="bx bx-printer me-1"></i> Print
                                </button>
                                <a href="purchase_requests.php" class="btn btn-outline-primary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to List
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alerts -->
                <?php if ($email_sent): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bx bx-check-circle fs-4 me-2"></i>
                        <div>
                            <strong>Email sent successfully!</strong>
                            <div class="text-muted small mt-1">
                                Sent to <?= htmlspecialchars($request['manufacturer_email']) ?> at <?= date('h:i A') ?>
                            </div>
                        </div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($email_error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bx bx-error-circle fs-4 me-2"></i>
                        <div><?= $email_error ?></div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Items</h6>
                                        <h3 class="mb-0 text-primary"><?= count($items) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-package text-primary"></i>
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
                                        <h6 class="text-muted mb-1">Estimated Amount</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($request['total_estimated_amount'], 2) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-success"></i>
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
                                        <h6 class="text-muted mb-1">Low Stock Items</h6>
                                        <?php 
                                        $lowStockCount = 0;
                                        foreach ($items as $item) {
                                            if ($item['current_stock'] <= 5) $lowStockCount++;
                                        }
                                        ?>
                                        <h3 class="mb-0 text-warning"><?= $lowStockCount ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-alarm-exclamation text-warning"></i>
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
                                        <h6 class="text-muted mb-1">Request Status</h6>
                                        <?php 
                                        $status = $request['status'];
                                        $statusInfo = $statusLabels[$status] ?? ['label' => ucfirst($status), 'color' => 'dark', 'icon' => 'bx-circle'];
                                        ?>
                                        <h3 class="mb-0 text-<?= $statusInfo['color'] ?>">
                                            <?= $statusInfo['label'] ?>
                                        </h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-<?= $statusInfo['color'] ?> bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx <?= $statusInfo['icon'] ?> text-<?= $statusInfo['color'] ?>"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Request Details Card -->
                    <div class="col-lg-4">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm h-100">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="bx bx-detail me-2"></i> Request Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Request Number</label>
                                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($request['request_number']) ?>" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Status</label>
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-<?= $statusInfo['color'] ?> bg-opacity-10 text-<?= $statusInfo['color'] ?> px-3 py-2 me-2 status-badge">
                                            <i class="bx <?= $statusInfo['icon'] ?> me-1"></i><?= $statusInfo['label'] ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Supplier Information</label>
                                    <div class="bg-light p-3 rounded">
                                        <div class="mb-2">
                                            <i class="bx bx-buildings me-2 text-primary"></i>
                                            <strong><?= htmlspecialchars($request['manufacturer_name'] ?? 'Not specified') ?></strong>
                                        </div>
                                        <?php if ($request['manufacturer_email']): ?>
                                        <div class="mb-2">
                                            <i class="bx bx-envelope me-2 text-primary"></i>
                                            <a href="mailto:<?= htmlspecialchars($request['manufacturer_email']) ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($request['manufacturer_email']) ?>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($request['manufacturer_phone']): ?>
                                        <div class="mb-2">
                                            <i class="bx bx-phone me-2 text-primary"></i>
                                            <a href="tel:<?= htmlspecialchars($request['manufacturer_phone']) ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($request['manufacturer_phone']) ?>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Request Information</label>
                                    <div class="bg-light p-3 rounded">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><i class="bx bx-calendar me-2"></i>Created On:</span>
                                            <strong><?= date('d M Y, h:i A', strtotime($request['created_at'])) ?></strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><i class="bx bx-user me-2"></i>Requested By:</span>
                                            <strong><?= htmlspecialchars($request['requested_by_name']) ?></strong>
                                        </div>
                                        <?php if ($request['updated_at'] != $request['created_at']): ?>
                                        <div class="d-flex justify-content-between">
                                            <span><i class="bx bx-refresh me-2"></i>Last Updated:</span>
                                            <strong><?= date('d M Y, h:i A', strtotime($request['updated_at'])) ?></strong>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="text-center bg-primary text-white rounded p-4">
                                    <h1 class="mb-1">₹<?= number_format($request['total_estimated_amount'], 2) ?></h1>
                                    <small>Estimated Total Value</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Products Section -->
                    <div class="col-lg-8">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bx bx-package me-2"></i> Requested Products
                                        <small class="text-muted ms-2">(<?= count($items) ?> items)</small>
                                    </h5>
                                    <div class="d-flex gap-2">
                                        <?php 
                                        $lowStockCount = 0;
                                        $totalQuantity = 0;
                                        foreach ($items as $item) {
                                            if ($item['current_stock'] <= 5) $lowStockCount++;
                                            $totalQuantity += $item['quantity'];
                                        }
                                        ?>
                                        <?php if ($lowStockCount > 0): ?>
                                        <span class="badge bg-danger">
                                            <i class="bx bx-error-alt me-1"></i><?= $lowStockCount ?> Low Stock
                                        </span>
                                        <?php endif; ?>
                                        <span class="badge bg-primary">
                                            <i class="bx bx-cube me-1"></i> <?= $totalQuantity ?> Units
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Product Table -->
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle" id="productsTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th>Code / HSN</th>
                                                <th class="text-center">Current Stock</th>
                                                <th class="text-center">Req. Qty</th>
                                                <th class="text-end">Est. Price</th>
                                                <th class="text-end">Total</th>
                                                <th class="text-center">Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($items)): ?>
                                            <tr class="text-center">
                                                <td colspan="8" class="py-4">
                                                    <i class="bx bx-package fs-1 text-muted mb-3 d-block"></i>
                                                    <p class="text-muted">No products in this request</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php 
                                            $grandTotal = 0;
                                            $lowStockCount = 0;
                                            ?>
                                            <?php foreach ($items as $i => $item): 
                                                $total = $item['estimated_price'] * $item['quantity'];
                                                $grandTotal += $total;
                                                $isLowStock = $item['current_stock'] <= 5;
                                                if ($isLowStock) $lowStockCount++;
                                            ?>
                                            <tr class="<?= $isLowStock ? 'low-stock' : '' ?>">
                                                <td class="text-center fw-bold"><?= $i + 1 ?></td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                                        <?php if ($item['notes']): ?>
                                                        <small class="text-info mt-1"><i class="bx bx-note me-1"></i><?= htmlspecialchars($item['notes']) ?></small>
                                                        <?php endif; ?>
                                                        <?php if ($isLowStock): ?>
                                                        <span class="badge bg-danger low-stock-badge mt-1">
                                                            <i class="bx bx-error-alt me-1"></i>Low Stock
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <?php if (!empty($item['product_code'])): ?>
                                                        <span class="badge bg-light text-dark"><?= htmlspecialchars($item['product_code']) ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($item['hsn_code'])): ?>
                                                        <div class="mt-1">
                                                            <span class="badge bg-info bg-opacity-10 text-info">
                                                                <i class="bx bx-hash me-1"></i><?= htmlspecialchars($item['hsn_code']) ?>
                                                            </span>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex flex-column align-items-center">
                                                        <span class="badge bg-<?= $isLowStock ? 'danger' : 'success' ?> rounded-pill px-3 py-1 mb-1 fs-6">
                                                            <?= $item['current_stock'] ?>
                                                        </span>
                                                        <?php if ($item['current_stock'] > 0): ?>
                                                        <div class="stock-indicator">
                                                            <div class="stock-fill" style="width: <?= min(100, ($item['current_stock'] / 10) * 100) ?>%"></div>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-primary rounded-pill px-3 py-1"><?= $item['quantity'] ?></span>
                                                </td>
                                                <td class="text-end fw-bold">
                                                    ₹<?= number_format($item['estimated_price'], 2) ?>
                                                </td>
                                                <td class="text-end fw-bold text-primary">
                                                    ₹<span class="total-display"><?= number_format($total, 2) ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($item['notes']): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="popover" data-bs-title="Product Notes" data-bs-content="<?= htmlspecialchars($item['notes']) ?>">
                                                        <i class="bx bx-note"></i>
                                                    </button>
                                                    <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <td colspan="6" class="text-end fw-bold">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div class="text-start">
                                                            <?php if ($lowStockCount > 0): ?>
                                                            <span class="badge bg-danger">
                                                                <i class="bx bx-error-alt me-1"></i> <?= $lowStockCount ?> Low Stock Items
                                                            </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div>
                                                            Grand Total (<?= count($items) ?> items):
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-end fw-bold text-primary fs-5">
                                                    ₹<?= number_format($grandTotal, 2) ?>
                                                </td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <?php if ($request['request_notes']): ?>
                                <div class="mt-4">
                                    <div class="card border-start border-info border-4">
                                        <div class="card-body">
                                            <h6 class="mb-2 text-info">
                                                <i class="bx bx-note me-2"></i> Request Notes
                                            </h6>
                                            <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($request['request_notes'])) ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <hr class="my-4">

                                <!-- Action Buttons -->
                                <div class="text-end">
                                    <?php if (in_array($request['status'], ['draft', 'sent'])): ?>
                                    <a href="purchase_request_edit.php?id=<?= $id ?>" class="btn btn-warning me-2">
                                        <i class="bx bx-edit me-1"></i> Edit Request
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($request['status'] === 'approved'): ?>
                                    <a href="create_purchase_order.php?request_id=<?= $id ?>" class="btn btn-success me-2">
                                        <i class="bx bx-shopping-bag me-1"></i> Create Purchase Order
                                    </a>
                                    <?php endif; ?>
                                    <?php if (!empty($request['manufacturer_email'])): ?>
                                    <form method="POST" class="d-inline">
                                        <button type="submit" name="send_email" class="btn btn-primary me-2">
                                            <i class="bx bx-mail-send me-1"></i> 
                                            <?= $request['status'] === 'draft' ? 'Send Email' : 'Resend Email' ?>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <button onclick="window.print()" class="btn btn-dark">
                                        <i class="bx bx-printer me-1"></i> Print Request
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Timeline Section -->
                        <div class="card shadow-sm mt-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-time me-2"></i> Request Timeline
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="timeline">
                                    <div class="timeline-item <?= $request['status'] === 'draft' ? 'active' : 'completed' ?>">
                                        <div class="timeline-icon bg-secondary">
                                            <i class="bx bx-edit"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <h6>Draft Created</h6>
                                            <small class="text-muted"><?= date('d M Y, h:i A', strtotime($request['created_at'])) ?></small>
                                            <p>Request was created by <?= htmlspecialchars($request['requested_by_name']) ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="timeline-item <?= $request['status'] === 'sent' ? 'active' : ($request['status'] === 'draft' ? 'pending' : 'completed') ?>">
                                        <div class="timeline-icon bg-info">
                                            <i class="bx bx-send"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <h6>Sent to Supplier</h6>
                                            <?php if ($request['status'] === 'sent' || in_array($request['status'], ['quotation_received', 'approved', 'rejected'])): ?>
                                            <small class="text-muted"><?= date('d M Y', strtotime($request['created_at'])) ?></small>
                                            <p>Request sent to <?= htmlspecialchars($request['manufacturer_name']) ?></p>
                                            <?php else: ?>
                                            <small class="text-muted">Pending</small>
                                            <p>Not sent yet</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="timeline-item <?= $request['status'] === 'quotation_received' ? 'active' : ($request['status'] === 'approved' || $request['status'] === 'rejected' ? 'completed' : 'pending') ?>">
                                        <div class="timeline-icon bg-warning">
                                            <i class="bx bx-file"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <h6>Quotation Received</h6>
                                            <?php if (in_array($request['status'], ['quotation_received', 'approved', 'rejected'])): ?>
                                            <small class="text-muted">Waiting for update</small>
                                            <p>Supplier has sent quotation</p>
                                            <?php else: ?>
                                            <small class="text-muted">Pending</small>
                                            <p>Awaiting quotation from supplier</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="timeline-item <?= $request['status'] === 'approved' ? 'active' : ($request['status'] === 'approved' ? 'completed' : 'pending') ?>">
                                        <div class="timeline-icon bg-success">
                                            <i class="bx bx-check-circle"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <h6>Approved</h6>
                                            <?php if ($request['status'] === 'approved'): ?>
                                            <small class="text-muted">Waiting for update</small>
                                            <p>Request approved for purchase</p>
                                            <?php else: ?>
                                            <small class="text-muted">Pending</small>
                                            <p>Awaiting approval</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
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
<!-- Include DataTables for better table handling -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">

<script>
$(document).ready(function() {
    // Initialize DataTables for products table
    $('#productsTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search products:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ products",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    // Initialize popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Auto-close alerts after 6 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            bootstrap.Alert.getInstance(alert)?.close();
        });
    }, 6000);

    // Row hover effect
    $('tbody tr').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // Toast function
    function showToast(type, message) {
        $('.toast').remove();
        const toast = $(`<div class="toast align-items-center text-bg-${type} border-0" role="alert"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
        if ($('.toast-container').length === 0) $('body').append('<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999"></div>');
        $('.toast-container').append(toast);
        new bootstrap.Toast(toast[0]).show();
    }
});
</script>

<style>
.timeline {
    position: relative;
    padding-left: 40px;
}
.timeline::before {
    content: '';
    position: absolute;
    left: 20px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e0e0e0;
}
.timeline-item {
    position: relative;
    margin-bottom: 30px;
}
.timeline-icon {
    position: absolute;
    left: -40px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    z-index: 1;
}
.timeline-content {
    padding-left: 20px;
}
.timeline-item.active .timeline-icon {
    box-shadow: 0 0 0 4px rgba(var(--bs-primary-rgb), 0.1);
}
.timeline-item.completed .timeline-icon {
    background: #34c38f !important;
}
.timeline-item.pending .timeline-icon {
    background: #f0f0f0 !important;
    color: #999;
}
</style>

</body>
</html>