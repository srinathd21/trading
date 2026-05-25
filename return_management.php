<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$business_id = (int)($_SESSION['current_business_id'] ?? ($_SESSION['business_id'] ?? 1));
$user_role = $_SESSION['role'] ?? '';
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

$can_manage_returns = in_array($user_role, ['admin', 'shop_manager'], true);
$can_view_returns = in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier'], true);

if (!$can_view_returns) {
    $_SESSION['error'] = "You don't have permission to view return management.";
    header('Location: invoices.php');
    exit();
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return number_format((float)$value, 2);
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function ensureReturnProductsManageTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `return_products_manage` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `business_id` int(11) NOT NULL,
            `shop_id` int(11) DEFAULT NULL,

            `return_id` int(11) NOT NULL,
            `return_item_id` int(11) NOT NULL,
            `invoice_id` int(11) NOT NULL,
            `invoice_item_id` int(11) NOT NULL,

            `customer_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `product_name` varchar(255) DEFAULT NULL,

            `returned_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
            `managed_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,

            `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
            `return_value` decimal(12,2) NOT NULL DEFAULT 0.00,

            `return_condition` enum('good','damaged','expired','wrong_item','defective','other') NOT NULL DEFAULT 'good',
            `manage_action` enum('pending','restocked','damaged_stock','scrap','supplier_return','hold') NOT NULL DEFAULT 'pending',

            `stock_updated` tinyint(1) NOT NULL DEFAULT 0,
            `reason` varchar(255) DEFAULT NULL,
            `notes` text DEFAULT NULL,

            `managed_by` int(11) DEFAULT NULL,
            `managed_at` datetime DEFAULT NULL,

            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

            PRIMARY KEY (`id`),
            KEY `idx_rpm_business` (`business_id`),
            KEY `idx_rpm_shop` (`shop_id`),
            KEY `idx_rpm_return` (`return_id`),
            KEY `idx_rpm_return_item` (`return_item_id`),
            KEY `idx_rpm_invoice` (`invoice_id`),
            KEY `idx_rpm_invoice_item` (`invoice_item_id`),
            KEY `idx_rpm_customer` (`customer_id`),
            KEY `idx_rpm_product` (`product_id`),
            KEY `idx_rpm_action` (`manage_action`),
            KEY `idx_rpm_stock_updated` (`stock_updated`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensureStockRow(PDO $pdo, int $business_id, int $shop_id, int $product_id): void
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM product_stocks
        WHERE business_id = ?
          AND shop_id = ?
          AND product_id = ?
        LIMIT 1
    ");
    $stmt->execute([$business_id, $shop_id, $product_id]);
    $stock_id = $stmt->fetchColumn();

    if (!$stock_id) {
        $insert = $pdo->prepare("
            INSERT INTO product_stocks (
                product_id,
                shop_id,
                business_id,
                quantity,
                old_qty,
                last_updated
            ) VALUES (?, ?, ?, 0, 0, NOW())
        ");
        $insert->execute([$product_id, $shop_id, $business_id]);
    }
}

ensureReturnProductsManageTable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manage_id'], $_POST['manage_action'])) {
    if (!$can_manage_returns) {
        $_SESSION['error'] = "Only admin or shop manager can manage returned products.";
        header('Location: return_management.php');
        exit();
    }

    $manage_id = (int)($_POST['manage_id'] ?? 0);
    $action = $_POST['manage_action'] ?? '';
    $condition = $_POST['return_condition'] ?? 'good';
    $notes = trim((string)($_POST['notes'] ?? ''));

    $allowed_actions = ['restocked', 'damaged_stock', 'scrap', 'supplier_return', 'hold'];
    $allowed_conditions = ['good', 'damaged', 'expired', 'wrong_item', 'defective', 'other'];

    if ($manage_id <= 0 || !in_array($action, $allowed_actions, true)) {
        $_SESSION['error'] = "Invalid return management request.";
        header('Location: return_management.php');
        exit();
    }

    if (!in_array($condition, $allowed_conditions, true)) {
        $condition = 'other';
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT *
            FROM return_products_manage
            WHERE id = ?
              AND business_id = ?
            LIMIT 1
        ");
        $stmt->execute([$manage_id, $business_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("Returned product not found.");
        }

        if ($user_role !== 'admin' && !empty($current_shop_id)) {
            if ((int)$row['shop_id'] !== (int)$current_shop_id) {
                throw new Exception("This return does not belong to your current shop.");
            }
        }

        if ((int)$row['stock_updated'] === 1 || $row['manage_action'] !== 'pending') {
            throw new Exception("This return item is already managed.");
        }

        $returned_qty = (float)$row['returned_qty'];
        $shop_id = (int)$row['shop_id'];
        $product_id = (int)$row['product_id'];

        if ($action === 'restocked') {
            ensureStockRow($pdo, $business_id, $shop_id, $product_id);

            $stock_stmt = $pdo->prepare("
                UPDATE product_stocks
                SET old_qty = quantity,
                    quantity = quantity + ?,
                    last_updated = NOW()
                WHERE business_id = ?
                  AND shop_id = ?
                  AND product_id = ?
            ");
            $stock_stmt->execute([$returned_qty, $business_id, $shop_id, $product_id]);

            if (tableExists($pdo, 'stock_movements')) {
                $movement_stmt = $pdo->prepare("
                    INSERT INTO stock_movements (
                        product_id,
                        shop_id,
                        business_id,
                        movement_type,
                        quantity,
                        reference_type,
                        reference_id,
                        notes,
                        created_by,
                        created_at
                    ) VALUES (?, ?, ?, 'return', ?, 'return_management', ?, ?, ?, NOW())
                ");
                $movement_stmt->execute([
                    $product_id,
                    $shop_id,
                    $business_id,
                    $returned_qty,
                    $manage_id,
                    "Restocked from Return Management. " . $notes,
                    $user_id
                ]);
            }

            $stock_updated = 1;
            $managed_qty = $returned_qty;
        } else {
            $stock_updated = 0;
            $managed_qty = $returned_qty;
        }

        $update = $pdo->prepare("
            UPDATE return_products_manage
            SET return_condition = ?,
                manage_action = ?,
                managed_qty = ?,
                stock_updated = ?,
                notes = ?,
                managed_by = ?,
                managed_at = NOW()
            WHERE id = ?
              AND business_id = ?
        ");
        $update->execute([
            $condition,
            $action,
            $managed_qty,
            $stock_updated,
            $notes,
            $user_id,
            $manage_id,
            $business_id
        ]);

        $pdo->commit();

        $_SESSION['success'] = "Return product updated successfully.";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['error'] = "Failed to update return product: " . $e->getMessage();
    }

    header('Location: return_management.php');
    exit();
}

$status = $_GET['status'] ?? 'pending';
$search = trim((string)($_GET['search'] ?? ''));
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$where = "WHERE rpm.business_id = ?";
$params = [$business_id];

if ($user_role !== 'admin' && !empty($current_shop_id)) {
    $where .= " AND rpm.shop_id = ?";
    $params[] = $current_shop_id;
}

if ($status !== 'all') {
    $where .= " AND rpm.manage_action = ?";
    $params[] = $status;
}

if ($search !== '') {
    $where .= " AND (
        rpm.product_name LIKE ?
        OR i.invoice_number LIKE ?
        OR c.name LIKE ?
        OR c.phone LIKE ?
        OR r.return_reason LIKE ?
    )";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($start_date !== '') {
    $where .= " AND DATE(r.return_date) >= ?";
    $params[] = $start_date;
}

if ($end_date !== '') {
    $where .= " AND DATE(r.return_date) <= ?";
    $params[] = $end_date;
}

$stmt = $pdo->prepare("
    SELECT
        rpm.*,
        r.return_date,
        r.return_reason,
        i.invoice_number,
        c.name AS customer_name,
        c.phone AS customer_phone,
        s.shop_name,
        u.full_name AS managed_by_name
    FROM return_products_manage rpm
    LEFT JOIN returns r ON r.id = rpm.return_id
    LEFT JOIN invoices i ON i.id = rpm.invoice_id
    LEFT JOIN customers c ON c.id = rpm.customer_id
    LEFT JOIN shops s ON s.id = rpm.shop_id
    LEFT JOIN users u ON u.id = rpm.managed_by
    $where
    ORDER BY rpm.created_at DESC, rpm.id DESC
");
$stmt->execute($params);
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_items,
        COALESCE(SUM(CASE WHEN manage_action = 'pending' THEN 1 ELSE 0 END), 0) AS pending_items,
        COALESCE(SUM(CASE WHEN manage_action = 'restocked' THEN 1 ELSE 0 END), 0) AS restocked_items,
        COALESCE(SUM(CASE WHEN manage_action IN ('damaged_stock','scrap','supplier_return','hold') THEN 1 ELSE 0 END), 0) AS other_items,
        COALESCE(SUM(return_value), 0) AS total_value
    FROM return_products_manage
    WHERE business_id = ?
");
$stats_stmt->execute([$business_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

function actionBadge(string $action): string
{
    $map = [
        'pending' => 'warning',
        'restocked' => 'success',
        'damaged_stock' => 'danger',
        'scrap' => 'dark',
        'supplier_return' => 'info',
        'hold' => 'secondary'
    ];

    return $map[$action] ?? 'secondary';
}

function actionText(string $action): string
{
    return ucwords(str_replace('_', ' ', $action));
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Return Management"; include 'includes/head.php'; ?>

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
                        <div class="page-title-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bx bx-refresh me-2"></i> Return Management
                                </h4>
                                <small class="text-muted">
                                    Returned products are managed here before adding back to stock.
                                </small>
                            </div>

                            <div class="d-flex gap-2">
                                <a href="invoices.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Invoices
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bx bx-check-circle me-2"></i><?= h($_SESSION['success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bx bx-error-circle me-2"></i><?= h($_SESSION['error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card shadow-sm border-start border-primary border-4">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Total Returned Items</h6>
                                <h3 class="mb-0 text-primary"><?= number_format((int)($stats['total_items'] ?? 0)) ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card shadow-sm border-start border-warning border-4">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Pending Manage</h6>
                                <h3 class="mb-0 text-warning"><?= number_format((int)($stats['pending_items'] ?? 0)) ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card shadow-sm border-start border-success border-4">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Restocked</h6>
                                <h3 class="mb-0 text-success"><?= number_format((int)($stats['restocked_items'] ?? 0)) ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card shadow-sm border-start border-danger border-4">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Return Value</h6>
                                <h3 class="mb-0 text-danger">₹<?= money($stats['total_value'] ?? 0) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="restocked" <?= $status === 'restocked' ? 'selected' : '' ?>>Restocked</option>
                                        <option value="damaged_stock" <?= $status === 'damaged_stock' ? 'selected' : '' ?>>Damaged Stock</option>
                                        <option value="scrap" <?= $status === 'scrap' ? 'selected' : '' ?>>Scrap</option>
                                        <option value="supplier_return" <?= $status === 'supplier_return' ? 'selected' : '' ?>>Supplier Return</option>
                                        <option value="hold" <?= $status === 'hold' ? 'selected' : '' ?>>Hold</option>
                                    </select>
                                </div>

                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="start_date" class="form-control" value="<?= h($start_date) ?>">
                                </div>

                                <div class="col-lg-2 col-md-6">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="end_date" class="form-control" value="<?= h($end_date) ?>">
                                </div>

                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Search</label>
                                    <input type="text" name="search" class="form-control" placeholder="Invoice / Product / Customer / Phone" value="<?= h($search) ?>">
                                </div>

                                <div class="col-lg-2 col-md-12">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bx bx-search me-1"></i> Search
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="returnManageTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th>Return Details</th>
                                        <th>Product</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Value</th>
                                        <th class="text-center">Condition</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Managed</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                <?php if (empty($returns)): ?>
                                    
                                <?php else: ?>
                                    <?php foreach ($returns as $row): ?>
                                        <?php
                                            $badge = actionBadge($row['manage_action']);
                                            $is_pending = $row['manage_action'] === 'pending';
                                        ?>
                                        <tr>
                                            <td>
                                                <strong class="text-primary"><?= h($row['invoice_number']) ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    Return Date:
                                                    <?= !empty($row['return_date']) ? date('d M Y', strtotime($row['return_date'])) : '-' ?>
                                                </small>
                                                <br>
                                                <small>
                                                    Customer:
                                                    <strong><?= h($row['customer_name'] ?? '-') ?></strong>
                                                    <?= !empty($row['customer_phone']) ? '(' . h($row['customer_phone']) . ')' : '' ?>
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    Shop: <?= h($row['shop_name'] ?? '-') ?>
                                                </small>
                                            </td>

                                            <td>
                                                <strong><?= h($row['product_name']) ?></strong>
                                                <br>
                                                <small class="text-muted">Reason: <?= h($row['reason'] ?? $row['return_reason'] ?? '-') ?></small>
                                            </td>

                                            <td class="text-center">
                                                <span class="badge bg-danger fs-6">
                                                    <?= rtrim(rtrim(number_format((float)$row['returned_qty'], 4), '0'), '.') ?>
                                                </span>
                                            </td>

                                            <td class="text-end">
                                                <strong>₹<?= money($row['return_value']) ?></strong>
                                                <br>
                                                <small class="text-muted">Unit: ₹<?= money($row['unit_price']) ?></small>
                                            </td>

                                            <td class="text-center">
                                                <?= h(actionText($row['return_condition'])) ?>
                                            </td>

                                            <td class="text-center">
                                                <span class="badge bg-<?= $badge ?>">
                                                    <?= h(actionText($row['manage_action'])) ?>
                                                </span>
                                                <?php if ((int)$row['stock_updated'] === 1): ?>
                                                    <br><small class="text-success">Stock Updated</small>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center">
                                                <?php if (!empty($row['managed_at'])): ?>
                                                    <small>
                                                        <?= date('d M Y h:i A', strtotime($row['managed_at'])) ?>
                                                        <br>
                                                        <?= h($row['managed_by_name'] ?? '-') ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">Not managed</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center">
                                                <?php if ($is_pending && $can_manage_returns): ?>
                                                    <button type="button"
                                                            class="btn btn-sm btn-primary manage-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#manageModal"
                                                            data-id="<?= (int)$row['id'] ?>"
                                                            data-product="<?= h($row['product_name']) ?>"
                                                            data-qty="<?= h($row['returned_qty']) ?>"
                                                            data-invoice="<?= h($row['invoice_number']) ?>">
                                                        <i class="bx bx-cog me-1"></i> Manage
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                                        Completed
                                                    </button>
                                                <?php endif; ?>
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

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<div class="modal fade" id="manageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bx bx-cog me-2"></i> Manage Returned Product
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="manage_id" id="manage_id">

                <div class="alert alert-info">
                    <strong>Invoice:</strong> <span id="modal_invoice"></span><br>
                    <strong>Product:</strong> <span id="modal_product"></span><br>
                    <strong>Returned Qty:</strong> <span id="modal_qty"></span>
                </div>

                <div class="mb-3">
                    <label class="form-label">Product Condition</label>
                    <select name="return_condition" class="form-select" required>
                        <option value="good">Good</option>
                        <option value="damaged">Damaged</option>
                        <option value="expired">Expired</option>
                        <option value="wrong_item">Wrong Item</option>
                        <option value="defective">Defective</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Manage Action</label>
                    <select name="manage_action" class="form-select" required>
                        <option value="">-- Select Action --</option>
                        <option value="restocked">Restock to Stock</option>
                        <option value="damaged_stock">Move to Damaged Stock</option>
                        <option value="scrap">Scrap</option>
                        <option value="supplier_return">Return to Supplier</option>
                        <option value="hold">Hold for Checking</option>
                    </select>
                    <small class="text-muted">
                        Only <strong>Restock to Stock</strong> will increase product stock.
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Enter notes..."></textarea>
                </div>

                <div class="alert alert-warning mb-0">
                    <i class="bx bx-info-circle me-1"></i>
                    Once managed, this item cannot be managed again.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bx bx-check me-1"></i> Save Action
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function () {
    if ($.fn.DataTable) {
        $('#returnManageTable').DataTable({
            pageLength: 25,
            ordering: false
        });
    }

    $('.manage-btn').on('click', function () {
        $('#manage_id').val($(this).data('id'));
        $('#modal_invoice').text($(this).data('invoice'));
        $('#modal_product').text($(this).data('product'));
        $('#modal_qty').text($(this).data('qty'));
    });
});
</script>

<style>
.border-start {
    border-left-width: 4px !important;
}
.table th {
    font-weight: 600;
    vertical-align: middle;
}
.table td {
    vertical-align: middle;
}
</style>

</body>
</html>