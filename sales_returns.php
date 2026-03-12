<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check user permissions
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$allowed_roles = ['admin', 'shop_manager', 'seller'];
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: index.php');
    exit();
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_return'])) {
        createSalesReturn($pdo, $user_id);
    } elseif (isset($_POST['update_status'])) {
        updateReturnStatus($pdo, $user_id);
    }
}

// Handle GET parameters
$action = $_GET['action'] ?? 'list';
$return_id = (int)($_GET['id'] ?? 0);
$invoice_id = (int)($_GET['invoice_id'] ?? 0);

// Route to appropriate function
switch ($action) {
    case 'create':
        showCreateForm($pdo, $invoice_id);
        break;
    case 'view':
        viewReturn($pdo, $return_id);
        break;
    case 'edit':
        editReturn($pdo, $return_id, $user_id);
        break;
    case 'delete':
        deleteReturn($pdo, $return_id, $user_id);
        break;
    default:
        listReturns($pdo);
}

/**
 * Create a new sales return
 */
function createSalesReturn($pdo, $user_id) {
    try {
        $pdo->beginTransaction();
        
        $invoice_id = (int)$_POST['invoice_id'];
        $customer_id = (int)$_POST['customer_id'];
        $return_date = $_POST['return_date'];
        $reason = $_POST['reason'];
        $items = $_POST['items'] ?? [];
        
        // Validate
        if (empty($items)) {
            throw new Exception("No items selected for return.");
        }
        
        // Get invoice details
        $invoice_stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $invoice_stmt->execute([$invoice_id]);
        $invoice = $invoice_stmt->fetch();
        
        if (!$invoice) {
            throw new Exception("Invoice not found.");
        }
        
        // Generate return number
        $return_number = generateReturnNumber($pdo);
        
        // Calculate totals
        $total_amount = 0;
        foreach ($items as $item) {
            $item_id = (int)$item['item_id'];
            $qty = (int)$item['quantity'];
            
            // Get original invoice item
            $item_stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE id = ? AND invoice_id = ?");
            $item_stmt->execute([$item_id, $invoice_id]);
            $original_item = $item_stmt->fetch();
            
            if (!$original_item || $qty > $original_item['quantity']) {
                throw new Exception("Invalid return quantity for item ID: $item_id");
            }
            
            $total_amount += ($original_item['unit_price'] * $qty);
        }
        
        // Insert sales return
        $stmt = $pdo->prepare("
            INSERT INTO sales_returns 
            (return_number, invoice_id, customer_id, return_date, total_amount, reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $return_number,
            $invoice_id,
            $customer_id,
            $return_date,
            $total_amount,
            $reason,
            $user_id
        ]);
        
        $return_id = $pdo->lastInsertId();
        
        // Insert return items and update stock
        foreach ($items as $item) {
            $item_id = (int)$item['item_id'];
            $qty = (int)$item['quantity'];
            $return_reason = $item['reason'] ?? '';
            
            // Get original invoice item
            $item_stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE id = ?");
            $item_stmt->execute([$item_id]);
            $original_item = $item_stmt->fetch();
            
            // Insert return item
            $item_stmt = $pdo->prepare("
                INSERT INTO sales_return_items 
                (return_id, invoice_item_id, product_id, quantity, unit_price, total_price, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $item_stmt->execute([
                $return_id,
                $item_id,
                $original_item['product_id'],
                $qty,
                $original_item['unit_price'],
                $original_item['unit_price'] * $qty,
                $return_reason
            ]);
            
            // Update product stock (add back to stock)
            updateStockOnReturn($pdo, $original_item['product_id'], $qty, $invoice['shop_id']);
        }
        
        // Update invoice pending amount if needed
        if ($invoice['pending_amount'] > 0) {
            $new_pending = max(0, $invoice['pending_amount'] - $total_amount);
            $update_stmt = $pdo->prepare("UPDATE invoices SET pending_amount = ? WHERE id = ?");
            $update_stmt->execute([$new_pending, $invoice_id]);
        }
        
        $pdo->commit();
        
        $_SESSION['success'] = "Sales return created successfully! Return Number: $return_number";
        header("Location: sales_returns.php?action=view&id=$return_id");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error creating return: " . $e->getMessage();
        header("Location: sales_returns.php?action=create&invoice_id=" . ($invoice_id ?? ''));
        exit();
    }
}

/**
 * Generate unique return number
 */
function generateReturnNumber($pdo) {
    $year_month = date('Ym');
    $stmt = $pdo->prepare("
        SELECT return_number FROM sales_returns 
        WHERE return_number LIKE 'SRN-$year_month-%' 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute();
    $last = $stmt->fetch();
    
    if ($last) {
        $last_num = (int)substr($last['return_number'], -4);
        $next_num = str_pad($last_num + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $next_num = '0001';
    }
    
    return "SRN-$year_month-$next_num";
}

/**
 * Update stock on return
 */
function updateStockOnReturn($pdo, $product_id, $quantity, $shop_id) {
    // Check if stock record exists
    $check_stmt = $pdo->prepare("SELECT * FROM product_stocks WHERE product_id = ? AND shop_id = ?");
    $check_stmt->execute([$product_id, $shop_id]);
    
    if ($check_stmt->rowCount() > 0) {
        // Update existing stock
        $update_stmt = $pdo->prepare("
            UPDATE product_stocks 
            SET quantity = quantity + ? 
            WHERE product_id = ? AND shop_id = ?
        ");
        $update_stmt->execute([$quantity, $product_id, $shop_id]);
    } else {
        // Create new stock record
        $insert_stmt = $pdo->prepare("
            INSERT INTO product_stocks (product_id, shop_id, quantity)
            VALUES (?, ?, ?)
        ");
        $insert_stmt->execute([$product_id, $shop_id, $quantity]);
    }
    
    // Record stock adjustment
    $adj_number = 'ADJ' . date('YmdHis') . rand(100, 999);
    $adj_stmt = $pdo->prepare("
        INSERT INTO stock_adjustments 
        (adjustment_number, product_id, shop_id, adjustment_type, quantity, old_stock, new_stock, reason, adjusted_by)
        VALUES (?, ?, ?, 'add', ?, ?, ?, 'Sales Return', ?)
    ");
    
    // Get current stock for logging
    $current_stmt = $pdo->prepare("SELECT quantity FROM product_stocks WHERE product_id = ? AND shop_id = ?");
    $current_stmt->execute([$product_id, $shop_id]);
    $current = $current_stmt->fetch();
    $old_stock = $current ? $current['quantity'] - $quantity : 0;
    $new_stock = $old_stock + $quantity;
    
    $adj_stmt->execute([
        $adj_number,
        $product_id,
        $shop_id,
        $quantity,
        $old_stock,
        $new_stock,
        $user_id
    ]);
}

/**
 * List all sales returns
 */
function listReturns($pdo) {
    // Pagination
    $page = (int)($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    // Filters
    $search = $_GET['search'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $status = $_GET['status'] ?? '';
    
    // Build query
    $where = [];
    $params = [];
    
    if (!empty($search)) {
        $where[] = "(sr.return_number LIKE ? OR c.name LIKE ? OR i.invoice_number LIKE ?)";
        $search_term = "%$search%";
        array_push($params, $search_term, $search_term, $search_term);
    }
    
    if (!empty($date_from)) {
        $where[] = "sr.return_date >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where[] = "sr.return_date <= ?";
        $params[] = $date_to;
    }
    
    $where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";
    
    // Count total
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM sales_returns sr
        LEFT JOIN customers c ON sr.customer_id = c.id
        LEFT JOIN invoices i ON sr.invoice_id = i.id
        $where_clause
    ");
    $count_stmt->execute($params);
    $total_count = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_count / $limit);
    
    // Fetch returns
    $stmt = $pdo->prepare("
        SELECT sr.*, c.name as customer_name, i.invoice_number, u.full_name as created_by_name
        FROM sales_returns sr
        LEFT JOIN customers c ON sr.customer_id = c.id
        LEFT JOIN invoices i ON sr.invoice_id = i.id
        LEFT JOIN users u ON sr.created_by = u.id
        $where_clause
        ORDER BY sr.id DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $returns = $stmt->fetchAll();
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sales Returns - Vidhya Traders</title>
        <?php include 'includes/head.php'; ?>
        <style>
            .filters-card {
                background: #f8f9fa;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .return-card {
                border-left: 4px solid #dc3545;
                border-radius: 8px;
            }
            .status-badge {
                padding: 5px 12px;
                border-radius: 20px;
                font-size: 0.85rem;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <?php include 'includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row">
                <?php include 'includes/sidebar.php'; ?>
                
                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">Sales Returns</h1>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <a href="sales_returns.php?action=create" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create Return
                            </a>
                        </div>
                    </div>
                    
                    <?php include 'includes/alerts.php'; ?>
                    
                    <!-- Filters -->
                    <div class="filters-card mb-4">
                        <form method="GET" class="row g-3">
                            <input type="hidden" name="action" value="list">
                            <div class="col-md-3">
                                <input type="text" class="form-control" name="search" placeholder="Search..." 
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-2">
                                <input type="date" class="form-control" name="date_from" 
                                       value="<?= htmlspecialchars($date_from) ?>">
                            </div>
                            <div class="col-md-2">
                                <input type="date" class="form-control" name="date_to" 
                                       value="<?= htmlspecialchars($date_to) ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <a href="sales_returns.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Returns Table -->
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Return No.</th>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Invoice No.</th>
                                            <th>Amount</th>
                                            <th>Created By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($returns)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">
                                                No sales returns found.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($returns as $return): ?>
                                            <tr class="return-card">
                                                <td>
                                                    <strong><?= htmlspecialchars($return['return_number']) ?></strong>
                                                </td>
                                                <td><?= date('d M Y', strtotime($return['return_date'])) ?></td>
                                                <td><?= htmlspecialchars($return['customer_name']) ?></td>
                                                <td><?= htmlspecialchars($return['invoice_number']) ?></td>
                                                <td class="fw-bold">₹<?= number_format($return['total_amount'], 2) ?></td>
                                                <td><?= htmlspecialchars($return['created_by_name']) ?></td>
                                                <td>
                                                    <a href="sales_returns.php?action=view&id=<?= $return['id'] ?>" 
                                                       class="btn btn-sm btn-info" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="sales_returns.php?action=edit&id=<?= $return['id'] ?>" 
                                                       class="btn btn-sm btn-warning" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($_SESSION['user_role'] == 'admin'): ?>
                                                    <a href="sales_returns.php?action=delete&id=<?= $return['id'] ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       onclick="return confirm('Are you sure?')" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
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
                            <nav aria-label="Page navigation" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?action=list&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">
                                            Previous
                                        </a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?action=list&page=<?= $i ?>&search=<?= urlencode($search) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?action=list&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">
                                            Next
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </main>
            </div>
        </div>
        
        <?php include 'includes/scripts.php'; ?>
    </body>
    </html>
    <?php
}

/**
 * Show create return form
 */
function showCreateForm($pdo, $invoice_id = 0) {
    // If invoice_id provided, pre-fill form
    $invoice = null;
    $items = [];
    
    if ($invoice_id > 0) {
        $invoice_stmt = $pdo->prepare("
            SELECT i.*, c.name as customer_name, c.id as customer_id
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.id = ?
        ");
        $invoice_stmt->execute([$invoice_id]);
        $invoice = $invoice_stmt->fetch();
        
        if ($invoice) {
            $items_stmt = $pdo->prepare("
                SELECT ii.*, p.product_name, p.product_code
                FROM invoice_items ii
                JOIN products p ON ii.product_id = p.id
                WHERE ii.invoice_id = ?
            ");
            $items_stmt->execute([$invoice_id]);
            $items = $items_stmt->fetchAll();
        }
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Create Sales Return - Vidhya Traders</title>
        <?php include 'includes/head.php'; ?>
        <style>
            .invoice-info {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 10px;
                margin-bottom: 20px;
            }
            .item-row {
                border-bottom: 1px solid #eee;
                padding: 15px 0;
            }
        </style>
    </head>
    <body>
        <?php include 'includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row">
                <?php include 'includes/sidebar.php'; ?>
                
                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">Create Sales Return</h1>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <a href="sales_returns.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                        </div>
                    </div>
                    
                    <?php include 'includes/alerts.php'; ?>
                    
                    <?php if (!$invoice && $invoice_id > 0): ?>
                    <div class="alert alert-danger">
                        Invoice not found! Please select a valid invoice.
                    </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <form method="POST" id="returnForm">
                                        <input type="hidden" name="create_return" value="1">
                                        
                                        <!-- Invoice Selection -->
                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Select Invoice</label>
                                                    <select class="form-select" name="invoice_id" id="invoiceSelect" required
                                                            onchange="if(this.value) window.location='sales_returns.php?action=create&invoice_id='+this.value">
                                                        <option value="">-- Select Invoice --</option>
                                                        <?php if ($invoice): ?>
                                                        <option value="<?= $invoice['id'] ?>" selected>
                                                            <?= $invoice['invoice_number'] ?> - 
                                                            <?= htmlspecialchars($invoice['customer_name']) ?> - 
                                                            ₹<?= number_format($invoice['total'], 2) ?>
                                                        </option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Return Date</label>
                                                    <input type="date" class="form-control" name="return_date" 
                                                           value="<?= date('Y-m-d') ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($invoice): ?>
                                        <input type="hidden" name="customer_id" value="<?= $invoice['customer_id'] ?>">
                                        
                                        <!-- Invoice Info -->
                                        <div class="invoice-info mb-4">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <strong>Invoice:</strong> <?= $invoice['invoice_number'] ?><br>
                                                    <strong>Date:</strong> <?= date('d M Y', strtotime($invoice['created_at'])) ?>
                                                </div>
                                                <div class="col-md-4">
                                                    <strong>Customer:</strong> <?= htmlspecialchars($invoice['customer_name']) ?><br>
                                                    <strong>Type:</strong> <?= ucfirst($invoice['customer_type']) ?>
                                                </div>
                                                <div class="col-md-4">
                                                    <strong>Total:</strong> ₹<?= number_format($invoice['total'], 2) ?><br>
                                                    <strong>Pending:</strong> ₹<?= number_format($invoice['pending_amount'], 2) ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Return Items -->
                                        <div class="mb-4">
                                            <h5>Select Items to Return</h5>
                                            <div class="table-responsive">
                                                <table class="table table-bordered">
                                                    <thead>
                                                        <tr>
                                                            <th width="5%"></th>
                                                            <th>Product</th>
                                                            <th>Sold Qty</th>
                                                            <th>Available Qty</th>
                                                            <th>Return Qty</th>
                                                            <th>Reason</th>
                                                            <th>Amount</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="itemsBody">
                                                        <?php foreach ($items as $i => $item): ?>
                                                        <?php
                                                        // Get current stock for this product
                                                        $stock_stmt = $pdo->prepare("
                                                            SELECT ps.quantity 
                                                            FROM product_stocks ps
                                                            JOIN invoices i ON ps.shop_id = i.shop_id
                                                            WHERE ps.product_id = ? AND i.id = ?
                                                        ");
                                                        $stock_stmt->execute([$item['product_id'], $invoice_id]);
                                                        $stock = $stock_stmt->fetch();
                                                        $available_qty = $stock ? $stock['quantity'] : 0;
                                                        ?>
                                                        <tr class="item-row">
                                                            <td>
                                                                <input type="checkbox" class="form-check-input item-check"
                                                                       name="items[<?= $i ?>][selected]" value="1"
                                                                       data-index="<?= $i ?>">
                                                            </td>
                                                            <td>
                                                                <strong><?= htmlspecialchars($item['product_name']) ?></strong><br>
                                                                <small><?= htmlspecialchars($item['product_code']) ?></small>
                                                                <input type="hidden" name="items[<?= $i ?>][item_id]" value="<?= $item['id'] ?>">
                                                            </td>
                                                            <td><?= $item['quantity'] ?></td>
                                                            <td><?= $available_qty ?></td>
                                                            <td width="15%">
                                                                <input type="number" class="form-control return-qty" 
                                                                       name="items[<?= $i ?>][quantity]" 
                                                                       min="1" max="<?= $item['quantity'] ?>"
                                                                       data-max="<?= $item['quantity'] ?>" 
                                                                       data-price="<?= $item['unit_price'] ?>"
                                                                       disabled>
                                                            </td>
                                                            <td width="20%">
                                                                <select class="form-control return-reason" 
                                                                        name="items[<?= $i ?>][reason]" disabled>
                                                                    <option value="">-- Select Reason --</option>
                                                                    <option value="defective">Defective Product</option>
                                                                    <option value="wrong_item">Wrong Item</option>
                                                                    <option value="customer_request">Customer Request</option>
                                                                    <option value="damaged">Damaged in Transit</option>
                                                                    <option value="other">Other</option>
                                                                </select>
                                                            </td>
                                                            <td class="item-amount">₹0.00</td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="table-primary">
                                                            <td colspan="6" class="text-end"><strong>Total Return Amount:</strong></td>
                                                            <td id="totalAmount" class="fw-bold">₹0.00</td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                        
                                        <!-- Return Reason -->
                                        <div class="mb-4">
                                            <label class="form-label">Overall Return Reason</label>
                                            <textarea class="form-control" name="reason" rows="3" 
                                                      placeholder="Enter reason for return..." required></textarea>
                                        </div>
                                        
                                        <!-- Summary -->
                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <div class="card">
                                                    <div class="card-body">
                                                        <h6>Return Summary</h6>
                                                        <div id="returnSummary">
                                                            No items selected for return
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Submit Button -->
                                        <div class="text-end">
                                            <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                                                Cancel
                                            </button>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-check"></i> Create Return
                                            </button>
                                        </div>
                                        
                                        <?php else: ?>
                                        <div class="alert alert-info">
                                            Please select an invoice to create return.
                                        </div>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </div>
        
        <?php include 'includes/scripts.php'; ?>
        <script>
        $(document).ready(function() {
            // Search invoices
            $('#invoiceSelect').select2({
                placeholder: 'Search invoice...',
                ajax: {
                    url: 'ajax_search.php',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'search_invoices',
                            search: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    }
                },
                minimumInputLength: 1
            });
            
            // Handle item selection
            $('.item-check').change(function() {
                var index = $(this).data('index');
                var isChecked = $(this).is(':checked');
                
                $('.return-qty[data-index="' + index + '"]').prop('disabled', !isChecked);
                $('.return-reason[data-index="' + index + '"]').prop('disabled', !isChecked);
                
                if (!isChecked) {
                    $('.return-qty[data-index="' + index + '"]').val('');
                    $('.return-reason[data-index="' + index + '"]').val('');
                }
                
                updateTotals();
            });
            
            // Handle quantity change
            $('.return-qty').on('input', function() {
                var max = parseInt($(this).data('max'));
                var val = parseInt($(this).val()) || 0;
                
                if (val > max) {
                    $(this).val(max);
                    alert('Cannot return more than sold quantity: ' + max);
                }
                
                updateTotals();
            });
            
            // Calculate and update totals
            function updateTotals() {
                var totalAmount = 0;
                var summary = '';
                
                $('.item-check:checked').each(function() {
                    var index = $(this).data('index');
                    var qtyInput = $('.return-qty[data-index="' + index + '"]');
                    var price = parseFloat(qtyInput.data('price'));
                    var qty = parseInt(qtyInput.val()) || 0;
                    var amount = price * qty;
                    var productName = qtyInput.closest('tr').find('strong').text();
                    
                    totalAmount += amount;
                    
                    if (qty > 0) {
                        summary += '<div>' + productName + ' - ' + qty + ' x ₹' + price.toFixed(2) + ' = ₹' + amount.toFixed(2) + '</div>';
                    }
                });
                
                // Update UI
                $('#totalAmount').text('₹' + totalAmount.toFixed(2));
                
                if (summary) {
                    $('#returnSummary').html(summary + '<hr><strong>Total: ₹' + totalAmount.toFixed(2) + '</strong>');
                } else {
                    $('#returnSummary').html('No items selected for return');
                }
            }
            
            // Form validation
            $('#returnForm').submit(function(e) {
                var hasItems = false;
                $('.item-check:checked').each(function() {
                    var index = $(this).data('index');
                    var qty = $('.return-qty[data-index="' + index + '"]').val();
                    if (qty > 0) {
                        hasItems = true;
                    }
                });
                
                if (!hasItems) {
                    e.preventDefault();
                    alert('Please select at least one item with quantity for return.');
                }
            });
        });
        </script>
    </body>
    </html>
    <?php
}

/**
 * View sales return details
 */
function viewReturn($pdo, $return_id) {
    // Fetch return details
    $stmt = $pdo->prepare("
        SELECT sr.*, c.name as customer_name, c.phone as customer_phone,
               c.gstin as customer_gstin, c.address as customer_address,
               i.invoice_number, i.invoice_type, i.total as invoice_total,
               u.full_name as created_by_name, s.shop_name
        FROM sales_returns sr
        LEFT JOIN customers c ON sr.customer_id = c.id
        LEFT JOIN invoices i ON sr.invoice_id = i.id
        LEFT JOIN users u ON sr.created_by = u.id
        LEFT JOIN shops s ON i.shop_id = s.id
        WHERE sr.id = ?
    ");
    $stmt->execute([$return_id]);
    $return = $stmt->fetch();
    
    if (!$return) {
        $_SESSION['error'] = "Return not found!";
        header('Location: sales_returns.php');
        exit();
    }
    
    // Fetch return items
    $items_stmt = $pdo->prepare("
        SELECT sri.*, p.product_name, p.product_code, ii.quantity as sold_quantity
        FROM sales_return_items sri
        LEFT JOIN invoice_items ii ON sri.invoice_item_id = ii.id
        LEFT JOIN products p ON sri.product_id = p.id
        WHERE sri.return_id = ?
    ");
    $items_stmt->execute([$return_id]);
    $items = $items_stmt->fetchAll();
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>View Return - <?= $return['return_number'] ?></title>
        <?php include 'includes/head.php'; ?>
    </head>
    <body>
        <?php include 'includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row">
                <?php include 'includes/sidebar.php'; ?>
                
                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">Return #<?= htmlspecialchars($return['return_number']) ?></h1>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <a href="sales_returns.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                            <a href="sales_returns.php?action=edit&id=<?= $return_id ?>" class="btn btn-warning ms-2">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <button class="btn btn-primary ms-2" onclick="window.print()">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>
                    
                    <?php include 'includes/alerts.php'; ?>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <!-- Header Info -->
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <h6>Return Details</h6>
                                                    <p class="mb-1"><strong>Return Number:</strong> <?= $return['return_number'] ?></p>
                                                    <p class="mb-1"><strong>Date:</strong> <?= date('d M Y', strtotime($return['return_date'])) ?></p>
                                                    <p class="mb-1"><strong>Created By:</strong> <?= $return['created_by_name'] ?></p>
                                                    <p class="mb-0"><strong>Shop:</strong> <?= $return['shop_name'] ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <h6>Invoice Details</h6>
                                                    <p class="mb-1"><strong>Invoice:</strong> <?= $return['invoice_number'] ?></p>
                                                    <p class="mb-1"><strong>Invoice Type:</strong> <?= ucfirst(str_replace('_', ' ', $return['invoice_type'])) ?></p>
                                                    <p class="mb-1"><strong>Invoice Total:</strong> ₹<?= number_format($return['invoice_total'], 2) ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Customer Info -->
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h6 class="mb-0">Customer Information</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($return['customer_name']) ?></p>
                                                    <p class="mb-1"><strong>Phone:</strong> <?= htmlspecialchars($return['customer_phone']) ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="mb-1"><strong>GSTIN:</strong> <?= htmlspecialchars($return['customer_gstin'] ?? 'N/A') ?></p>
                                                    <p class="mb-0"><strong>Address:</strong> <?= nl2br(htmlspecialchars($return['customer_address'] ?? '')) ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Return Items -->
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h6 class="mb-0">Returned Items</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-bordered">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Product</th>
                                                            <th>Sold Qty</th>
                                                            <th>Return Qty</th>
                                                            <th>Unit Price</th>
                                                            <th>Amount</th>
                                                            <th>Reason</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($items as $i => $item): ?>
                                                        <tr>
                                                            <td><?= $i + 1 ?></td>
                                                            <td>
                                                                <strong><?= htmlspecialchars($item['product_name']) ?></strong><br>
                                                                <small><?= htmlspecialchars($item['product_code']) ?></small>
                                                            </td>
                                                            <td><?= $item['sold_quantity'] ?></td>
                                                            <td><?= $item['quantity'] ?></td>
                                                            <td>₹<?= number_format($item['unit_price'], 2) ?></td>
                                                            <td class="fw-bold">₹<?= number_format($item['total_price'], 2) ?></td>
                                                            <td>
                                                                <?= ucfirst(str_replace('_', ' ', $item['reason'])) ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="table-primary">
                                                            <td colspan="5" class="text-end"><strong>Total Return Amount:</strong></td>
                                                            <td colspan="2" class="fw-bold">₹<?= number_format($return['total_amount'], 2) ?></td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Reason & Notes -->
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">Return Reason</h6>
                                        </div>
                                        <div class="card-body">
                                            <p><?= nl2br(htmlspecialchars($return['reason'])) ?></p>
                                        </div>
                                    </div>
                                    
                                    <!-- Print-friendly version -->
                                    <div class="d-none d-print-block">
                                        <h3>Sales Return #<?= $return['return_number'] ?></h3>
                                        <hr>
                                        <p><strong>Date:</strong> <?= date('d M Y', strtotime($return['return_date'])) ?></p>
                                        <p><strong>Customer:</strong> <?= htmlspecialchars($return['customer_name']) ?></p>
                                        <p><strong>Invoice:</strong> <?= $return['invoice_number'] ?></p>
                                        <hr>
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Qty</th>
                                                    <th>Price</th>
                                                    <th>Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($items as $item): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                    <td><?= $item['quantity'] ?></td>
                                                    <td>₹<?= number_format($item['unit_price'], 2) ?></td>
                                                    <td>₹<?= number_format($item['total_price'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="3" class="text-end"><strong>Total:</strong></td>
                                                    <td><strong>₹<?= number_format($return['total_amount'], 2) ?></strong></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                        <hr>
                                        <p><strong>Reason:</strong> <?= htmlspecialchars($return['reason']) ?></p>
                                        <p class="text-muted">Printed on: <?= date('d M Y H:i:s') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </div>
        
        <?php include 'includes/scripts.php'; ?>
    </body>
    </html>
    <?php
}

/**
 * Edit sales return
 */
function editReturn($pdo, $return_id, $user_id) {
    // Check if user has permission
    if ($_SESSION['user_role'] != 'admin') {
        $_SESSION['error'] = "Only admin can edit returns.";
        header('Location: sales_returns.php');
        exit();
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle edit form submission
        $reason = $_POST['reason'];
        
        $stmt = $pdo->prepare("UPDATE sales_returns SET reason = ? WHERE id = ?");
        $stmt->execute([$reason, $return_id]);
        
        $_SESSION['success'] = "Return updated successfully.";
        header("Location: sales_returns.php?action=view&id=$return_id");
        exit();
    }
    
    // Fetch return details
    $stmt = $pdo->prepare("SELECT * FROM sales_returns WHERE id = ?");
    $stmt->execute([$return_id]);
    $return = $stmt->fetch();
    
    if (!$return) {
        $_SESSION['error'] = "Return not found!";
        header('Location: sales_returns.php');
        exit();
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Edit Return - <?= $return['return_number'] ?></title>
        <?php include 'includes/head.php'; ?>
    </head>
    <body>
        <?php include 'includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row">
                <?php include 'includes/sidebar.php'; ?>
                
                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">Edit Return #<?= htmlspecialchars($return['return_number']) ?></h1>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <a href="sales_returns.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>
                    
                    <?php include 'includes/alerts.php'; ?>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-body">
                                    <form method="POST">
                                        <div class="mb-3">
                                            <label class="form-label">Return Reason</label>
                                            <textarea class="form-control" name="reason" rows="5" required><?= htmlspecialchars($return['reason']) ?></textarea>
                                        </div>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <strong>Note:</strong> Only the reason field can be edited. To modify items, 
                                            please create a new return and delete this one.
                                        </div>
                                        <div class="text-end">
                                            <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                                                Cancel
                                            </button>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save"></i> Save Changes
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </div>
        
        <?php include 'includes/scripts.php'; ?>
    </body>
    </html>
    <?php
}

/**
 * Delete sales return
 */
function deleteReturn($pdo, $return_id, $user_id) {
    // Check if user has permission
    if ($_SESSION['user_role'] != 'admin') {
        $_SESSION['error'] = "Only admin can delete returns.";
        header('Location: sales_returns.php');
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get return details
        $stmt = $pdo->prepare("
            SELECT sr.*, i.shop_id 
            FROM sales_returns sr
            LEFT JOIN invoices i ON sr.invoice_id = i.id
            WHERE sr.id = ?
        ");
        $stmt->execute([$return_id]);
        $return = $stmt->fetch();
        
        if (!$return) {
            throw new Exception("Return not found.");
        }
        
        // Get return items to reverse stock
        $items_stmt = $pdo->prepare("
            SELECT * FROM sales_return_items WHERE return_id = ?
        ");
        $items_stmt->execute([$return_id]);
        $items = $items_stmt->fetchAll();
        
        // Reverse stock adjustments (remove stock added)
        foreach ($items as $item) {
            $reverse_stmt = $pdo->prepare("
                UPDATE product_stocks 
                SET quantity = quantity - ? 
                WHERE product_id = ? AND shop_id = ?
            ");
            $reverse_stmt->execute([
                $item['quantity'],
                $item['product_id'],
                $return['shop_id']
            ]);
        }
        
        // Delete return items
        $delete_items_stmt = $pdo->prepare("DELETE FROM sales_return_items WHERE return_id = ?");
        $delete_items_stmt->execute([$return_id]);
        
        // Delete return
        $delete_stmt = $pdo->prepare("DELETE FROM sales_returns WHERE id = ?");
        $delete_stmt->execute([$return_id]);
        
        $pdo->commit();
        
        $_SESSION['success'] = "Return deleted successfully.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error deleting return: " . $e->getMessage();
    }
    
    header('Location: sales_returns.php');
    exit();
}