<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'shop_manager'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$business_id = (int)($_SESSION['business_id'] ?? 1);
$order_id = (int)($_GET['id'] ?? 0);
$success = '';
$error = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function format_money($amount) {
    return number_format((float)$amount, 2);
}

function normalize_status_badge($status) {
    $status = strtolower(trim((string)$status));
    $map = [
        'pending' => ['warning', 'Pending'],
        'confirmed' => ['info', 'Confirmed'],
        'processing' => ['primary', 'Processing'],
        'packed' => ['primary', 'Packed'],
        'shipped' => ['info', 'Shipped'],
        'out_for_delivery' => ['info', 'Out For Delivery'],
        'delivered' => ['success', 'Delivered'],
        'completed' => ['success', 'Completed'],
        'cancelled' => ['danger', 'Cancelled'],
        'returned' => ['dark', 'Returned'],
        'paid' => ['success', 'Paid'],
        'partial' => ['warning', 'Partial'],
        'unpaid' => ['danger', 'Unpaid'],
    ];
    return $map[$status] ?? ['secondary', ucfirst($status !== '' ? str_replace('_', ' ', $status) : 'Unknown')];
}

// Get order details
$order = null;
$order_items = [];

try {
    // Update order status if requested
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
        $new_status = $_POST['order_status'] ?? '';
        $valid_statuses = ['pending', 'confirmed', 'processing', 'packed', 'shipped', 'out_for_delivery', 'delivered', 'completed', 'cancelled', 'returned'];
        
        if (in_array($new_status, $valid_statuses)) {
            $stmt = $pdo->prepare("
                UPDATE online_store_orders 
                SET order_status = :status, updated_at = NOW() 
                WHERE id = :id AND business_id = :business_id
            ");
            $stmt->execute([
                ':status' => $new_status,
                ':id' => $order_id,
                ':business_id' => $business_id
            ]);
            $success = "Order status updated to " . ucfirst(str_replace('_', ' ', $new_status));
        }
    }
    
    // Get order details
    $stmt = $pdo->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM online_store_order_items WHERE order_id = o.id) as total_items_count
        FROM online_store_orders o
        WHERE o.id = :id AND o.business_id = :business_id
    ");
    $stmt->execute([':id' => $order_id, ':business_id' => $business_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('Location: online_store_orders.php?error=Order not found');
        exit();
    }
    
    // Get order items
    $stmt = $pdo->prepare("
        SELECT * FROM online_store_order_items 
        WHERE order_id = :order_id 
        ORDER BY id ASC
    ");
    $stmt->execute([':order_id' => $order_id]);
    $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Order Details #" . ($order['order_number'] ?? 'N/A'); include 'includes/head.php'; ?>
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
                
                <div class="row">
                    <div class="col-12">
                        <div class="d-flex align-items-center justify-content-between mb-4">
                            <div>
                                <h4 class="mb-1">Order Details</h4>
                                <p class="text-muted mb-0">#<?= h($order['order_number'] ?? 'N/A') ?></p>
                            </div>
                            <div>
                                <a href="online_store_orders.php" class="btn btn-light">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Orders
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
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
                
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="bx bx-package me-2"></i>Order Items</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th>Image</th>
                                                <th class="text-end">Price</th>
                                                <th class="text-center">Qty</th>
                                                <th class="text-end">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($order_items)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">No items found</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($order_items as $index => $item): ?>
                                                    <tr>
                                                        <td><?= $index + 1 ?></td>
                                                        <td class="fw-semibold"><?= h($item['product_name']) ?></td>
                                                        <td>
                                                            <?php if (!empty($item['product_image'])): ?>
                                                                <img src="<?= h($item['product_image']) ?>" alt="<?= h($item['product_name']) ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px;">
                                                            <?php else: ?>
                                                                <div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                    <i class="bx bx-image text-muted fs-4"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end">₹<?= format_money($item['unit_price']) ?></td>
                                                        <td class="text-center"><?= (int)$item['quantity'] ?></td>
                                                        <td class="text-end fw-semibold">₹<?= format_money($item['line_total']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                        <?php if (!empty($order_items)): ?>
                                        <tfoot class="table-light">
                                            <tr>
                                                <td colspan="5" class="text-end fw-bold">Subtotal:</td>
                                                <td class="text-end fw-bold">₹<?= format_money($order['subtotal'] ?? 0) ?></td>
                                            </tr>
                                            <tr>
                                                <td colspan="5" class="text-end">Delivery Charge:</td>
                                                <td class="text-end">₹<?= format_money($order['delivery_charge'] ?? 0) ?></td>
                                            </tr>
                                            <tr>
                                                <td colspan="5" class="text-end">Discount:</td>
                                                <td class="text-end text-danger">-₹<?= format_money($order['discount_amount'] ?? 0) ?></td>
                                            </tr>
                                            <tr>
                                                <td colspan="5" class="text-end fw-bold fs-5">Grand Total:</td>
                                                <td class="text-end fw-bold fs-5 text-primary">₹<?= format_money($order['grand_total'] ?? 0) ?></td>
                                            </tr>
                                        </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="bx bx-info-circle me-2"></i>Order Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="text-muted small mb-1">Order Number</label>
                                    <div class="fw-semibold"><?= h($order['order_number'] ?? '-') ?></div>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted small mb-1">Order Date</label>
                                    <div><?= h(date('d-m-Y h:i A', strtotime($order['created_at'] ?? 'now'))) ?></div>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted small mb-1">Customer Name</label>
                                    <div class="fw-semibold"><?= h($order['customer_name'] ?? '-') ?></div>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted small mb-1">Phone</label>
                                    <div><?= h($order['phone'] ?? '-') ?></div>
                                </div>
                                <?php if (!empty($order['alt_phone'])): ?>
                                    <div class="mb-3">
                                        <label class="text-muted small mb-1">Alternate Phone</label>
                                        <div><?= h($order['alt_phone']) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($order['email'])): ?>
                                    <div class="mb-3">
                                        <label class="text-muted small mb-1">Email</label>
                                        <div><?= h($order['email']) ?></div>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label class="text-muted small mb-1">Delivery Address</label>
                                    <div><?= nl2br(h($order['address_line'] ?? '')) ?></div>
                                    <div><?= h($order['city'] ?? '') ?>, <?= h($order['state'] ?? '') ?> - <?= h($order['pincode'] ?? '') ?></div>
                                    <?php if (!empty($order['landmark'])): ?>
                                        <div><small class="text-muted">Landmark: <?= h($order['landmark']) ?></small></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted small mb-1">Payment Method</label>
                                    <div class="fw-semibold"><?= h(strtoupper($order['payment_method'] ?? 'COD')) ?></div>
                                </div>
                                <?php if (!empty($order['order_note'])): ?>
                                    <div class="mb-3">
                                        <label class="text-muted small mb-1">Order Note</label>
                                        <div><?= nl2br(h($order['order_note'])) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="bx bx-cog me-2"></i>Update Status</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label">Current Status</label>
                                        <div class="mb-2">
                                            <?php 
                                            [$statusClass, $statusLabel] = normalize_status_badge($order['order_status'] ?? 'pending');
                                            ?>
                                            <span class="badge bg-<?= h($statusClass) ?> fs-6"><?= h($statusLabel) ?></span>
                                        </div>
                                        <label class="form-label">Change Status To</label>
                                        <select name="order_status" class="form-select mb-3">
                                            <option value="pending" <?= ($order['order_status'] ?? '') == 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="confirmed" <?= ($order['order_status'] ?? '') == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                            <option value="processing" <?= ($order['order_status'] ?? '') == 'processing' ? 'selected' : '' ?>>Processing</option>
                                            <option value="packed" <?= ($order['order_status'] ?? '') == 'packed' ? 'selected' : '' ?>>Packed</option>
                                            <option value="shipped" <?= ($order['order_status'] ?? '') == 'shipped' ? 'selected' : '' ?>>Shipped</option>
                                            <option value="out_for_delivery" <?= ($order['order_status'] ?? '') == 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
                                            <option value="delivered" <?= ($order['order_status'] ?? '') == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                            <option value="completed" <?= ($order['order_status'] ?? '') == 'completed' ? 'selected' : '' ?>>Completed</option>
                                            <option value="cancelled" <?= ($order['order_status'] ?? '') == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                            <option value="returned" <?= ($order['order_status'] ?? '') == 'returned' ? 'selected' : '' ?>>Returned</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="update_status" class="btn btn-primary w-100">
                                        <i class="bx bx-save me-1"></i> Update Status
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="bx bx-stats me-2"></i>Order Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Items:</span>
                                    <span class="fw-semibold"><?= (int)($order['total_items'] ?? count($order_items)) ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Payment Status:</span>
                                    <?php 
                                    [$paymentClass, $paymentLabel] = normalize_status_badge($order['payment_status'] ?? 'pending');
                                    ?>
                                    <span class="badge bg-<?= h($paymentClass) ?>"><?= h($paymentLabel) ?></span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <span class="fw-bold">Grand Total:</span>
                                    <span class="fw-bold fs-5 text-primary">₹<?= format_money($order['grand_total'] ?? 0) ?></span>
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
.form-control, .form-select, .btn {
    border-radius: 10px;
}
.badge {
    padding: 6px 12px;
    font-weight: 500;
}
</style>
</body>
</html>