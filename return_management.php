<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// ==================== LOGIN CHECK ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// ==================== ROLE & USER INFO ====================
$user_role = $_SESSION['role'] ?? '';
$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'User';

$is_admin        = ($user_role === 'admin');
$is_shop_manager = in_array($user_role, ['admin', 'shop_manager']);
$is_seller       = in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier']);
$is_stock_manager= in_array($user_role, ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager']);

// Check permission
if (!in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier', 'stock_manager'])) {
    $_SESSION['error'] = "Access denied. You don't have permission to manage returns.";
    header('Location: dashboard.php');
    exit();
}

// ==================== BUSINESS & SHOP SELECTION ====================
$current_business_id = $_SESSION['current_business_id'] ?? null;
$current_shop_id     = $_SESSION['current_shop_id'] ?? null;
$current_shop_name   = $_SESSION['current_shop_name'] ?? 'All Shops';

if (!$current_business_id || !$current_shop_id) {
    header('Location: select_shop.php');
    exit();
}

$today = date('Y-m-d');

// ==================== PROCESS FORM SUBMISSIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'add_return') {
            // Process new return
            $invoice_id = (int)$_POST['invoice_id'];
            $return_date = $_POST['return_date'] ?? $today;
            $return_reason = $_POST['return_reason'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $refund_to_cash = isset($_POST['refund_to_cash']) ? 1 : 0;
            
            // Validate invoice exists and belongs to current shop/business
            $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name 
                                  FROM invoices i 
                                  JOIN customers c ON i.customer_id = c.id
                                  WHERE i.id = ? AND i.business_id = ? AND i.shop_id = ?");
            $stmt->execute([$invoice_id, $current_business_id, $current_shop_id]);
            $invoice = $stmt->fetch();
            
            if (!$invoice) {
                throw new Exception("Invoice not found or doesn't belong to your shop");
            }
            
            // Get invoice items for return
            $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
            $stmt->execute([$invoice_id]);
            $invoice_items = $stmt->fetchAll();
            
            $total_return_amount = 0;
            
            // Process each item return
            foreach ($invoice_items as $item) {
                $qty_key = 'return_qty_' . $item['id'];
                $return_qty = (int)($_POST[$qty_key] ?? 0);
                
                if ($return_qty > 0 && $return_qty <= $item['quantity']) {
                    // Update invoice item return quantity
                    $stmt = $pdo->prepare("UPDATE invoice_items 
                                          SET return_qty = LEAST(return_qty + ?, quantity),
                                              return_status = CASE WHEN LEAST(return_qty + ?, quantity) = quantity THEN 1 ELSE 0 END
                                          WHERE id = ?");
                    $stmt->execute([$return_qty, $return_qty, $item['id']]);
                    
                    // Calculate return value
                    $return_value = $return_qty * $item['unit_price'];
                    $total_return_amount += $return_value;
                    
                    // Restock the product
                    $stmt = $pdo->prepare("UPDATE product_stocks 
                                          SET quantity = quantity + ?
                                          WHERE product_id = ? 
                                            AND shop_id = ? 
                                            AND business_id = ?");
                    $stmt->execute([$return_qty, $item['product_id'], $current_shop_id, $current_business_id]);
                    
                    // Log stock adjustment
                    $stmt = $pdo->prepare("INSERT INTO stock_adjustments 
                                          (adjustment_number, product_id, shop_id, adjustment_type, 
                                           quantity, old_stock, new_stock, reason, reference_id, 
                                           reference_type, notes, adjusted_by)
                                          VALUES 
                                          (?, ?, ?, 'add', ?, ?, ?, ?, ?, 'return', ?, ?)");
                    $adj_number = 'RTN-' . date('YmdHis');
                    
                    // Get current stock for logging
                    $stmt2 = $pdo->prepare("SELECT quantity FROM product_stocks 
                                            WHERE product_id = ? AND shop_id = ?");
                    $stmt2->execute([$item['product_id'], $current_shop_id]);
                    $stock_data = $stmt2->fetch();
                    $old_stock = $stock_data['quantity'] - $return_qty;
                    $new_stock = $stock_data['quantity'];
                    
                    $stmt->execute([
                        $adj_number,
                        $item['product_id'],
                        $current_shop_id,
                        $return_qty,
                        $old_stock,
                        $new_stock,
                        'Return from invoice ' . $invoice['invoice_number'],
                        $invoice_id,
                        $notes,
                        $user_id
                    ]);
                }
            }
            
            if ($total_return_amount > 0) {
                // Create return record
                $stmt = $pdo->prepare("INSERT INTO returns 
                                      (invoice_id, customer_id, return_date, total_return_amount, 
                                       return_reason, notes, refund_to_cash, processed_by, business_id)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $invoice_id,
                    $invoice['customer_id'],
                    $return_date,
                    $total_return_amount,
                    $return_reason,
                    $notes,
                    $refund_to_cash,
                    $user_id,
                    $current_business_id
                ]);
                $return_id = $pdo->lastInsertId();
                
                // Insert return items for each returned item
                foreach ($invoice_items as $item) {
                    $return_qty = (int)($_POST['return_qty_' . $item['id']] ?? 0);
                    if ($return_qty > 0) {
                        $return_value = $return_qty * $item['unit_price'];
                        
                        $stmt = $pdo->prepare("INSERT INTO return_items 
                                              (return_id, invoice_item_id, product_id, quantity, unit_price, return_value)
                                              VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $return_id,
                            $item['id'],
                            $item['product_id'],
                            $return_qty,
                            $item['unit_price'],
                            $return_value
                        ]);
                    }
                }
                
                // Update invoice totals
                $stmt = $pdo->prepare("UPDATE invoices 
                                      SET total = total - ?,
                                          pending_amount = GREATEST(0, pending_amount - ?)
                                      WHERE id = ?");
                $stmt->execute([$total_return_amount, $total_return_amount, $invoice_id]);
                
                $_SESSION['success'] = "Return #$return_id processed successfully. ₹" . number_format($total_return_amount, 2) . " refunded.";
                
                // Handle cash refund
                if ($refund_to_cash && $total_return_amount > 0) {
                    // Log the cash refund as expense
                    $expense_ref = 'REFUND-INV-' . $invoice['invoice_number'];
                    $stmt = $pdo->prepare("INSERT INTO expenses 
                                          (shop_id, business_id, amount, date, category, 
                                           description, reference, payment_method, added_by, status)
                                          VALUES (?, ?, ?, ?, 'Refund', ?, ?, 'cash', ?, 'approved')");
                    $stmt->execute([
                        $current_shop_id,
                        $current_business_id,
                        $total_return_amount,
                        $return_date,
                        "Cash refund for return on invoice " . $invoice['invoice_number'],
                        $expense_ref,
                        $user_id
                    ]);
                    
                    $_SESSION['success'] .= " Cash refund recorded in expenses.";
                }
            } else {
                throw new Exception("No items selected for return");
            }
            
        } elseif ($action === 'delete_return') {
            $return_id = (int)$_POST['return_id'];
            
            // Verify return belongs to current business/shop
            $stmt = $pdo->prepare("SELECT r.*, i.invoice_number, i.shop_id 
                                  FROM returns r
                                  JOIN invoices i ON r.invoice_id = i.id
                                  WHERE r.id = ? AND r.business_id = ? AND i.shop_id = ?");
            $stmt->execute([$return_id, $current_business_id, $current_shop_id]);
            $return_data = $stmt->fetch();
            
            if (!$return_data) {
                throw new Exception("Return record not found or access denied");
            }
            
            // Get return items
            $stmt = $pdo->prepare("SELECT ri.*, ii.product_id, ii.quantity as original_qty
                                  FROM return_items ri
                                  JOIN invoice_items ii ON ri.invoice_item_id = ii.id
                                  WHERE ri.return_id = ?");
            $stmt->execute([$return_id]);
            $return_items = $stmt->fetchAll();
            
            // Reverse stock adjustments
            foreach ($return_items as $item) {
                // Update invoice item return quantity
                $stmt = $pdo->prepare("UPDATE invoice_items 
                                      SET return_qty = GREATEST(0, return_qty - ?),
                                          return_status = CASE WHEN GREATEST(0, return_qty - ?) = 0 THEN 0 ELSE 1 END
                                      WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['quantity'], $item['invoice_item_id']]);
                
                // Remove from stock
                $stmt = $pdo->prepare("UPDATE product_stocks 
                                      SET quantity = GREATEST(0, quantity - ?)
                                      WHERE product_id = ? 
                                        AND shop_id = ? 
                                        AND business_id = ?");
                $stmt->execute([$item['quantity'], $item['product_id'], $current_shop_id, $current_business_id]);
                
                // Log reverse adjustment
                $adj_number = 'REV-' . date('YmdHis');
                $stmt = $pdo->prepare("INSERT INTO stock_adjustments 
                                      (adjustment_number, product_id, shop_id, adjustment_type, 
                                       quantity, old_stock, new_stock, reason, reference_id, 
                                       reference_type, notes, adjusted_by)
                                      VALUES 
                                      (?, ?, ?, 'remove', ?, ?, ?, ?, ?, 'return_reversal', ?, ?)");
                
                // Get current stock for logging
                $stmt2 = $pdo->prepare("SELECT quantity FROM product_stocks 
                                        WHERE product_id = ? AND shop_id = ?");
                $stmt2->execute([$item['product_id'], $current_shop_id]);
                $stock_data = $stmt2->fetch();
                $old_stock = $stock_data['quantity'] + $item['quantity'];
                $new_stock = $stock_data['quantity'];
                
                $stmt->execute([
                    $adj_number,
                    $item['product_id'],
                    $current_shop_id,
                    $item['quantity'],
                    $old_stock,
                    $new_stock,
                    'Return reversal for invoice ' . $return_data['invoice_number'],
                    $return_id,
                    'Return deletion',
                    $user_id
                ]);
            }
            
            // Delete return items
            $stmt = $pdo->prepare("DELETE FROM return_items WHERE return_id = ?");
            $stmt->execute([$return_id]);
            
            // Delete return record
            $stmt = $pdo->prepare("DELETE FROM returns WHERE id = ?");
            $stmt->execute([$return_id]);
            
            // Update invoice totals
            $stmt = $pdo->prepare("UPDATE invoices 
                                  SET total = total + ?,
                                      pending_amount = pending_amount + ?
                                  WHERE id = ?");
            $stmt->execute([
                $return_data['total_return_amount'],
                $return_data['total_return_amount'],
                $return_data['invoice_id']
            ]);
            
            $_SESSION['success'] = "Return #$return_id deleted and stock updated successfully";
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    header('Location: return_management.php');
    exit();
}

// ==================== GET DATA ====================
$search_invoice = $_GET['search_invoice'] ?? '';
$search_customer = $_GET['search_customer'] ?? '';
$start_date = $_GET['date_from'] ?? date('Y-m-01');
$end_date = $_GET['date_to'] ?? date('Y-m-d');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE conditions for returns
$params = [$current_business_id];
$where_conditions = ["r.business_id = ?"];

// Add shop filter if not admin viewing all shops
if ($current_shop_id) {
    $where_conditions[] = "i.shop_id = ?";
    $params[] = $current_shop_id;
}

if ($search_invoice) {
    $where_conditions[] = "i.invoice_number LIKE ?";
    $params[] = "%$search_invoice%";
}

if ($search_customer) {
    $where_conditions[] = "(c.name LIKE ? OR c.phone LIKE ?)";
    $params[] = "%$search_customer%";
    $params[] = "%$search_customer%";
}

$where_conditions[] = "DATE(r.return_date) BETWEEN ? AND ?";
$params[] = $start_date;
$params[] = $end_date;

$where_sql = implode(' AND ', $where_conditions);

// Count total returns
$count_sql = "SELECT COUNT(r.id) as total 
              FROM returns r
              JOIN invoices i ON r.invoice_id = i.id
              JOIN customers c ON r.customer_id = c.id
              WHERE $where_sql";

$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_returns = $stmt->fetchColumn();
$total_pages = ceil($total_returns / $limit);

// Get returns with pagination
$return_sql = "SELECT 
                r.*,
                i.invoice_number,
                i.total as invoice_total,
                i.pending_amount as invoice_pending,
                i.created_at as invoice_date,
                c.name as customer_name,
                c.phone as customer_phone,
                u.full_name as processed_by_name,
                (SELECT COUNT(*) FROM return_items ri WHERE ri.return_id = r.id) as item_count
              FROM returns r
              JOIN invoices i ON r.invoice_id = i.id
              JOIN customers c ON r.customer_id = c.id
              LEFT JOIN users u ON r.processed_by = u.id
              WHERE $where_sql
              ORDER BY r.return_date DESC, r.id DESC
              LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($return_sql);
$stmt->execute($params);
$returns = $stmt->fetchAll();

// Get recent invoices for return form
$recent_invoices_sql = "SELECT 
                        i.id,
                        i.invoice_number,
                        i.total,
                        i.created_at,
                        i.customer_id,
                        c.name as customer_name,
                        c.phone as customer_phone,
                        (SELECT SUM(return_qty) FROM invoice_items WHERE invoice_id = i.id) as already_returned_qty
                      FROM invoices i
                      JOIN customers c ON i.customer_id = c.id
                      WHERE i.business_id = ? 
                        AND i.shop_id = ?
                        AND DATE(i.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        AND i.total > 0
                      ORDER BY i.created_at DESC
                      LIMIT 50";
$stmt = $pdo->prepare($recent_invoices_sql);
$stmt->execute([$current_business_id, $current_shop_id]);
$recent_invoices = $stmt->fetchAll();

// Get return statistics
$stats_params = [$current_business_id];
$stats_where = "r.business_id = ?";

if ($current_shop_id) {
    $stats_where .= " AND i.shop_id = ?";
    $stats_params[] = $current_shop_id;
}

$stats_where .= " AND DATE(r.return_date) BETWEEN ? AND ?";
$stats_params[] = $start_date;
$stats_params[] = $end_date;

$stats_sql = "SELECT 
                COUNT(DISTINCT r.id) as total_returns,
                COALESCE(SUM(r.total_return_amount), 0) as total_return_amount,
                COUNT(DISTINCT r.customer_id) as customers_affected,
                COUNT(DISTINCT r.invoice_id) as invoices_affected,
                (SELECT COUNT(DISTINCT ri.product_id) FROM return_items ri 
                 JOIN returns r2 ON ri.return_id = r2.id 
                 WHERE r2.business_id = ? AND DATE(r2.return_date) BETWEEN ? AND ?) as products_returned
              FROM returns r
              JOIN invoices i ON r.invoice_id = i.id
              WHERE $stats_where";

$stats_params_for_products = [$current_business_id, $start_date, $end_date];
$all_stats_params = array_merge($stats_params, $stats_params_for_products);

$stmt = $pdo->prepare($stats_sql);
$stmt->execute($all_stats_params);
$stats = $stmt->fetch();

// Get top returned products
$top_returned_sql = "SELECT 
                      p.product_name,
                      p.product_code,
                      SUM(ri.quantity) as total_returned_qty,
                      SUM(ri.return_value) as total_return_value,
                      COUNT(DISTINCT r.id) as return_count
                    FROM return_items ri
                    JOIN returns r ON ri.return_id = r.id
                    JOIN invoices i ON r.invoice_id = i.id
                    JOIN products p ON ri.product_id = p.id
                    WHERE r.business_id = ? 
                      AND i.shop_id = ?
                      AND DATE(r.return_date) BETWEEN ? AND ?
                    GROUP BY p.id, p.product_name, p.product_code
                    HAVING total_returned_qty > 0
                    ORDER BY total_returned_qty DESC
                    LIMIT 10";
$stmt = $pdo->prepare($top_returned_sql);
$stmt->execute([$current_business_id, $current_shop_id, $start_date, $end_date]);
$top_returned_products = $stmt->fetchAll();

// Get return reasons summary
$reasons_sql = "SELECT 
                  r.return_reason, 
                  COUNT(*) as count,
                  SUM(r.total_return_amount) as total_amount
                FROM returns r
                JOIN invoices i ON r.invoice_id = i.id
                WHERE r.business_id = ? 
                  AND i.shop_id = ?
                  AND DATE(r.return_date) BETWEEN ? AND ?
                GROUP BY r.return_reason
                ORDER BY count DESC";
$stmt = $pdo->prepare($reasons_sql);
$stmt->execute([$current_business_id, $current_shop_id, $start_date, $end_date]);
$reasons = $stmt->fetchAll();
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Return Management - " . htmlspecialchars($current_shop_name); ?>
<?php include 'includes/head.php'; ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu"><div data-simplebar class="h-100">
        <?php include 'includes/sidebar.php'; ?>
    </div></div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
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

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-undo me-2"></i>
                                    Return Management
                                    <small class="text-muted ms-2"><?= htmlspecialchars($current_shop_name) ?></small>
                                </h4>
                                <p class="mb-0 text-muted">
                                    Process customer returns, manage refunds, and track return analytics
                                </p>
                                <?php if (($stats['total_return_amount'] ?? 0) > 0): ?>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <small class="text-danger">
                                        <i class="bx bx-undo me-1"></i>
                                        Total Returns: ₹<?= number_format($stats['total_return_amount'], 2) ?>
                                    </small>
                                    <small class="text-warning">
                                        <i class="bx bx-file me-1"></i>
                                        <?= $stats['invoices_affected'] ?> invoices affected
                                    </small>
                                    <small class="text-info">
                                        <i class="bx bx-user me-1"></i>
                                        <?= $stats['customers_affected'] ?> customers
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReturnModal">
                                    <i class="bx bx-plus me-1"></i> New Return
                                </button>
                                <a href="invoices.php" class="btn btn-outline-secondary ms-2">
                                    <i class="bx bx-receipt me-1"></i> View Invoices
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-danger border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Returns</h6>
                                <h3 class="text-danger">₹<?= number_format($stats['total_return_amount'] ?? 0, 2) ?></h3>
                                <small class="text-muted"><?= $stats['total_returns'] ?? 0 ?> return transactions</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Invoices Affected</h6>
                                <h3 class="text-warning"><?= $stats['invoices_affected'] ?? 0 ?></h3>
                                <small class="text-muted">With return items</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Customers</h6>
                                <h3 class="text-info"><?= $stats['customers_affected'] ?? 0 ?></h3>
                                <small class="text-muted">Who returned items</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Products Returned</h6>
                                <h3 class="text-success"><?= $stats['products_returned'] ?? 0 ?></h3>
                                <small class="text-muted">Different products</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-lg-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control" value="<?= $start_date ?>">
                            </div>
                            <div class="col-lg-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control" value="<?= $end_date ?>">
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label">Invoice Number</label>
                                <input type="text" name="search_invoice" class="form-control" value="<?= htmlspecialchars($search_invoice) ?>" placeholder="Search invoice...">
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label">Customer</label>
                                <input type="text" name="search_customer" class="form-control" value="<?= htmlspecialchars($search_customer) ?>" placeholder="Customer name/phone...">
                            </div>
                            <div class="col-lg-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bx bx-filter me-1"></i> Filter
                                </button>
                            </div>
                        </form>
                        <?php if ($search_invoice || $search_customer || $start_date != date('Y-m-01') || $end_date != date('Y-m-d')): ?>
                        <div class="mt-2">
                            <a href="return_management.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bx bx-x me-1"></i> Clear Filters
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Returns List -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Return ID</th>
                                        <th>Invoice</th>
                                        <th>Customer</th>
                                        <th class="text-center">Date</th>
                                        <th class="text-end">Amount</th>
                                        <th class="text-center">Items</th>
                                        <th>Reason</th>
                                        <th>Processed By</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($returns)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <i class="bx bx-package fs-1 text-muted mb-3 d-block"></i>
                                            <p class="text-muted">No returns found for the selected period</p>
                                            <a href="javascript:void(0)" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addReturnModal">
                                                <i class="bx bx-plus me-1"></i> Process First Return
                                            </a>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($returns as $return): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary">#<?= $return['id'] ?></strong>
                                        </td>
                                        <td>
                                            <a href="invoice_view.php?id=<?= $return['invoice_id'] ?>" target="_blank" class="text-primary">
                                                <?= htmlspecialchars($return['invoice_number']) ?>
                                            </a>
                                            <br>
                                            <small class="text-muted">
                                                Original: ₹<?= number_format($return['invoice_total'], 2) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($return['customer_name']) ?>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($return['customer_phone']) ?></small>
                                        </td>
                                        <td class="text-center">
                                            <?= date('d M Y', strtotime($return['return_date'])) ?>
                                            <br>
                                            <small class="text-muted"><?= date('h:i A', strtotime($return['return_date'])) ?></small>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-danger fw-bold">
                                                -₹<?= number_format($return['total_return_amount'], 2) ?>
                                            </span>
                                            <?php if ($return['refund_to_cash']): ?>
                                            <br>
                                            <small class="text-success">
                                                <i class="bx bx-money"></i> Cash refund
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3">
                                                <?= $return['item_count'] ?> items
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= htmlspecialchars($return['return_reason']) ?></small>
                                            <?php if ($return['notes']): ?>
                                            <br>
                                            <small><i><?= htmlspecialchars($return['notes']) ?></i></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?= htmlspecialchars($return['processed_by_name'] ?? 'System') ?></small>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                    onclick="viewReturnDetails(<?= $return['id'] ?>)">
                                                <i class="bx bx-show"></i>
                                            </button>
                                            <?php if ($is_admin || $is_shop_manager): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="confirmDeleteReturn(<?= $return['id'] ?>)">
                                                <i class="bx bx-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav class="mt-3">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="bx bx-chevron-left"></i>
                                    </a>
                                </li>
                                <?php 
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $start_page + 4);
                                $start_page = max(1, $end_page - 4);
                                
                                for ($i = $start_page; $i <= $end_page; $i++): 
                                ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                                        <?= $total_pages ?>
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        <i class="bx bx-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics Section -->
                <div class="row mt-4">
                    <!-- Top Returned Products -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bx bx-trending-down me-2"></i> Top Returned Products</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($top_returned_products)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-center">Returns</th>
                                                <th class="text-center">Qty Returned</th>
                                                <th class="text-end">Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($top_returned_products as $product): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($product['product_name']) ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?= htmlspecialchars($product['product_code']) ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-danger bg-opacity-10 text-danger">
                                                        <?= $product['return_count'] ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="fw-bold"><?= $product['total_returned_qty'] ?></span>
                                                </td>
                                                <td class="text-end text-danger">
                                                    -₹<?= number_format($product['total_return_value'], 2) ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <p class="text-muted text-center">No returned products data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Return Reasons Summary -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bx bx-comment-detail me-2"></i> Return Reasons</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($reasons)): ?>
                                <div class="row g-2">
                                    <?php foreach ($reasons as $reason): 
                                        $percentage = ($stats['total_returns'] ?? 0) > 0 ? ($reason['count'] / $stats['total_returns']) * 100 : 0;
                                    ?>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span><?= htmlspecialchars($reason['return_reason'] ?: 'Not specified') ?></span>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-secondary"><?= $reason['count'] ?> returns</span>
                                                <small class="text-danger">₹<?= number_format($reason['total_amount'], 2) ?></small>
                                            </div>
                                        </div>
                                        <div class="progress mb-3" style="height: 8px;">
                                            <div class="progress-bar bg-warning" role="progressbar" 
                                                 style="width: <?= $percentage ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <p class="text-muted text-center">No return reason data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add Return Modal -->
<div class="modal fade" id="addReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bx bx-undo me-2"></i> Process New Return</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="returnForm">
                <input type="hidden" name="action" value="add_return">
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Select Invoice</label>
                            <select name="invoice_id" id="invoiceSelect" class="form-select" required onchange="loadInvoiceItems(this.value)">
                                <option value="">-- Select Invoice --</option>
                                <?php foreach ($recent_invoices as $invoice): ?>
                                <option value="<?= $invoice['id'] ?>">
                                    <?= htmlspecialchars($invoice['invoice_number']) ?> - 
                                    <?= htmlspecialchars($invoice['customer_name']) ?> - 
                                    ₹<?= number_format($invoice['total'], 2) ?> - 
                                    <?= date('d M', strtotime($invoice['created_at'])) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Return Date</label>
                            <input type="date" name="return_date" class="form-control" value="<?= $today ?>" required>
                        </div>
                        
                        <!-- Invoice Details -->
                        <div class="col-12" id="invoiceDetails" style="display: none;">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title">Invoice Details</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="mb-1" id="customerInfo"></p>
                                            <p class="mb-1" id="invoiceInfo"></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-1" id="invoiceTotal"></p>
                                            <p class="mb-1 text-danger" id="alreadyReturned"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Return Items -->
                        <div class="col-12" id="returnItemsSection" style="display: none;">
                            <hr>
                            <h6>Select Items to Return</h6>
                            <div class="table-responsive">
                                <table class="table table-sm" id="returnItemsTable">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th class="text-center">Original Qty</th>
                                            <th class="text-center">Already Returned</th>
                                            <th class="text-center">Available to Return</th>
                                            <th class="text-center">Return Qty</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Return Value</th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsTableBody">
                                        <!-- Items will be loaded here -->
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-secondary">
                                            <td colspan="6" class="text-end"><strong>Total Return:</strong></td>
                                            <td class="text-end"><strong id="totalReturnValue">₹0.00</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Return Details -->
                        <div class="col-md-12">
                            <label class="form-label">Return Reason <span class="text-danger">*</span></label>
                            <select name="return_reason" class="form-select" required>
                                <option value="">-- Select Reason --</option>
                                <option value="Damaged Product">Damaged Product</option>
                                <option value="Wrong Item">Wrong Item</option>
                                <option value="Customer Changed Mind">Customer Changed Mind</option>
                                <option value="Defective Product">Defective Product</option>
                                <option value="Late Delivery">Late Delivery</option>
                                <option value="Size/Color Issue">Size/Color Issue</option>
                                <option value="Quality Issue">Quality Issue</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes..."></textarea>
                        </div>
                        
                        <div class="col-md-12">
                            <div class="form-check">
                                <input type="checkbox" name="refund_to_cash" id="refund_to_cash" class="form-check-input" value="1">
                                <label class="form-check-label text-success" for="refund_to_cash">
                                    <i class="bx bx-money me-1"></i> Refund as cash payment
                                </label>
                            </div>
                            <small class="text-muted">If checked, cash refund will be recorded as an expense</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitReturnBtn" disabled>
                        <i class="bx bx-check me-1"></i> Process Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Return Details Modal -->
<div class="modal fade" id="viewReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bx bx-show me-2"></i> Return Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="returnDetailsContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bx bx-trash me-2"></i> Delete Return</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="deleteReturnForm">
                <input type="hidden" name="action" value="delete_return">
                <input type="hidden" name="return_id" id="deleteReturnId">
                <div class="modal-body">
                    <div class="text-center">
                        <i class="bx bx-error-circle fs-1 text-danger mb-3"></i>
                        <h5>Are you sure you want to delete this return?</h5>
                        <p class="text-muted">
                            This will:
                            <ul class="text-start text-muted">
                                <li>Remove the return from records</li>
                                <li>Reverse stock adjustments</li>
                                <li>Update invoice totals</li>
                                <li>Delete related return items</li>
                            </ul>
                            <strong class="text-danger">This action cannot be undone!</strong>
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
// Load invoice items for return
function loadInvoiceItems(invoiceId) {
    if (!invoiceId) {
        $('#invoiceDetails').hide();
        $('#returnItemsSection').hide();
        $('#submitReturnBtn').prop('disabled', true);
        return;
    }
    
    $.ajax({
        url: 'ajax/get_invoice_items.php',
        type: 'GET',
        data: { 
            invoice_id: invoiceId,
            for_return: 1
        },
        beforeSend: function() {
            $('#itemsTableBody').html('<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm text-primary"></div> Loading items...</td></tr>');
            $('#returnItemsSection').show();
        },
        success: function(response) {
            $('#returnItemsSection').html(response);
            $('#invoiceDetails').show();
            $('#submitReturnBtn').prop('disabled', false);
            
            // Update invoice details
            $.ajax({
                url: 'ajax/get_invoice_info.php',
                type: 'GET',
                data: { invoice_id: invoiceId },
                success: function(invoiceInfo) {
                    $('#customerInfo').html('<strong>Customer:</strong> ' + invoiceInfo.customer_name + 
                                          ' (' + invoiceInfo.customer_phone + ')');
                    $('#invoiceInfo').html('<strong>Invoice:</strong> ' + invoiceInfo.invoice_number + 
                                         ' - ' + invoiceInfo.created_at);
                    $('#invoiceTotal').html('<strong>Total:</strong> ₹' + parseFloat(invoiceInfo.total).toLocaleString('en-IN'));
                    
                    if (invoiceInfo.already_returned_qty > 0) {
                        $('#alreadyReturned').html('<i class="bx bx-undo"></i> Already returned: ' + 
                                                 invoiceInfo.already_returned_qty + ' items');
                    } else {
                        $('#alreadyReturned').html('');
                    }
                }
            });
        },
        error: function(xhr, status, error) {
            console.error('Error loading items:', error);
            $('#itemsTableBody').html('<tr><td colspan="7" class="text-center text-danger">Error loading items. Please try again.</td></tr>');
            $('#submitReturnBtn').prop('disabled', true);
        }
    });
}

// Calculate return total
function calculateReturnTotal() {
    let total = 0;
    $('.return-qty-input').each(function() {
        const qty = parseInt($(this).val()) || 0;
        const unitPrice = parseFloat($(this).data('unit-price')) || 0;
        total += qty * unitPrice;
    });
    
    $('#totalReturnValue').text('₹' + total.toFixed(2));
    $('#submitReturnBtn').prop('disabled', total === 0);
    
    return total;
}

// View return details
function viewReturnDetails(returnId) {
    $.ajax({
        url: 'ajax/get_return_details.php',
        type: 'GET',
        data: { return_id: returnId },
        beforeSend: function() {
            $('#returnDetailsContent').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div><p>Loading return details...</p></div>');
        },
        success: function(response) {
            $('#returnDetailsContent').html(response);
            $('#viewReturnModal').modal('show');
        },
        error: function() {
            $('#returnDetailsContent').html('<div class="alert alert-danger">Error loading return details.</div>');
        }
    });
}

// Confirm delete return
function confirmDeleteReturn(returnId) {
    $('#deleteReturnId').val(returnId);
    $('#deleteReturnModal').modal('show');
}

// Form validation and submission
$(document).ready(function() {
    // Handle return form submission
    $('#returnForm').on('submit', function(e) {
        e.preventDefault();
        
        const totalReturn = calculateReturnTotal();
        if (totalReturn <= 0) {
            alert('Please select at least one item to return.');
            return false;
        }
        
        if (!$('select[name="return_reason"]').val()) {
            alert('Please select a return reason.');
            return false;
        }
        
        if (!confirm(`Are you sure you want to process this return for ₹${totalReturn.toFixed(2)}? This action cannot be undone.`)) {
            return false;
        }
        
        // Show processing
        const btn = $('#submitReturnBtn');
        const originalText = btn.html();
        btn.html('<span class="spinner-border spinner-border-sm me-2"></span> Processing...');
        btn.prop('disabled', true);
        
        // Submit form
        $.ajax({
            url: 'return_management.php',
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                // Check for success/error in response
                if (response.includes('success') || response.includes('Success')) {
                    $('#addReturnModal').modal('hide');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    alert('Error processing return. Please check the details and try again.');
                    btn.html(originalText);
                    btn.prop('disabled', false);
                }
            },
            error: function() {
                alert('Error processing return. Please try again.');
                btn.html(originalText);
                btn.prop('disabled', false);
            }
        });
    });
    
    // Handle delete form submission
    $('#deleteReturnForm').on('submit', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you absolutely sure? This will permanently delete the return record and cannot be undone.')) {
            return false;
        }
        
        $.ajax({
            url: 'return_management.php',
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                $('#deleteReturnModal').modal('hide');
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            },
            error: function() {
                alert('Error deleting return. Please try again.');
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
});

// Quick search for invoices
function quickInvoiceSearch() {
    const search = $('#quickInvoiceSearch').val();
    if (search.length >= 3) {
        $.ajax({
            url: 'ajax/search_invoices.php',
            type: 'GET',
            data: { 
                search: search, 
                shop_id: <?= $current_shop_id ?>,
                business_id: <?= $current_business_id ?>
            },
            success: function(response) {
                $('#invoiceSelect').html('<option value="">-- Select Invoice --</option>' + response);
            }
        });
    }
}
</script>

<style>
.card-hover:hover {
    transform: translateY(-2px);
    transition: all 0.3s ease;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important;
}

.border-start {
    border-left-width: 4px !important;
}

.return-qty-input {
    width: 70px !important;
    margin: 0 auto;
}

.text-line-through {
    text-decoration: line-through;
}

.bg-danger-soft {
    background-color: rgba(220, 53, 69, 0.1) !important;
}

.bg-warning-soft {
    background-color: rgba(255, 193, 7, 0.1) !important;
}

.bg-success-soft {
    background-color: rgba(40, 167, 69, 0.1) !important;
}

.table th {
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
}

.modal-lg {
    max-width: 900px;
}

.avatar-sm {
    width: 40px;
    height: 40px;
}

.badge.bg-opacity-10 {
    opacity: 0.9;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.progress {
    border-radius: 10px;
}

.progress-bar {
    border-radius: 10px;
}

@media (max-width: 768px) {
    .modal-lg {
        margin: 0.5rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .btn-group-sm .btn {
        margin-bottom: 2px;
    }
}
</style>
</body>
</html>