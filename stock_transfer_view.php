<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['admin', 'warehouse_manager', 'stock_manager', 'shop_manager'];
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied.";
    header('Location: dashboard.php');
    exit();
}

$transfer_id = (int)($_GET['id'] ?? 0);
if (!$transfer_id) {
    header('Location: stock_transfers.php');
    exit();
}

// Fetch transfer details
$stmt = $pdo->prepare("
    SELECT st.*,
           fs.shop_name as from_shop_name,
           ts.shop_name as to_shop_name,
           u.full_name as created_by_name,
           u2.full_name as approved_by_name,
           u3.full_name as received_by_name
    FROM stock_transfers st
    LEFT JOIN shops fs ON st.from_shop_id = fs.id
    LEFT JOIN shops ts ON st.to_shop_id = ts.id
    LEFT JOIN users u ON st.created_by = u.id
    LEFT JOIN users u2 ON st.approved_by = u2.id
    LEFT JOIN users u3 ON st.received_by = u3.id
    WHERE st.id = ?
");
$stmt->execute([$transfer_id]);
$transfer = $stmt->fetch();

if (!$transfer) {
    $_SESSION['error'] = "Transfer not found!";
    header('Location: stock_transfers.php');
    exit();
}

// Fetch items
$items = $pdo->prepare("
    SELECT sti.*, p.product_name, p.product_code, p.barcode
    FROM stock_transfer_items sti
    JOIN products p ON sti.product_id = p.id
    WHERE sti.stock_transfer_id = ?
    ORDER BY p.product_name
");
$items->execute([$transfer_id]);
$items = $items->fetchAll();
?>
<!doctype html>
<html lang="en">
<?php 
$page_title = "Transfer #" . htmlspecialchars($transfer['transfer_number']);
include 'includes/head.php'; 
?>
<!-- Add DataTables CSS -->
<link href="assets/libs/datatables.net-bs5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="assets/libs/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css" rel="stylesheet">
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
                                    <a href="stock_transfers.php" class="text-muted me-2">
                                        <i class="bx bx-arrow-back"></i>
                                    </a>
                                    Stock Transfer Details
                                    <small class="text-muted ms-2">
                                        #<?= htmlspecialchars($transfer['transfer_number']) ?>
                                    </small>
                                </h4>
                                <p class="text-muted mb-0">
                                    <i class="bx bx-store me-1"></i>
                                    <?= htmlspecialchars($_SESSION['current_shop_name'] ?? 'All Shops') ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <button onclick="window.print()" class="btn btn-outline-secondary">
                                    <i class="bx bx-printer me-1"></i> Print
                                </button>
                                <a href="stock_transfer_print.php?id=<?= $transfer_id ?>" target="_blank" class="btn btn-primary">
                                    <i class="bx bx-file me-1"></i> Print Report
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transfer Summary Cards -->
                <div class="row mb-4">
                    <!-- Transfer Info Card -->
                    <div class="col-xl-6">
                        <div class="card card-hover border-start border-primary border-4 shadow-sm">
                            <div class="card-body">
                                <h6 class="card-title text-muted mb-3">
                                    <i class="bx bx-transfer-alt me-2"></i> Transfer Information
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <p class="text-muted mb-1">Transfer Number</p>
                                        <h5 class="text-primary"><?= htmlspecialchars($transfer['transfer_number']) ?></h5>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="text-muted mb-1">Transfer Date</p>
                                        <h5><?= date('d M Y', strtotime($transfer['transfer_date'])) ?></h5>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="text-muted mb-1">From Shop</p>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2">
                                            <i class="bx bx-store me-1"></i>
                                            <?= htmlspecialchars($transfer['from_shop_name']) ?>
                                        </span>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="text-muted mb-1">To Shop</p>
                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2">
                                            <i class="bx bx-store me-1"></i>
                                            <?= htmlspecialchars($transfer['to_shop_name']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status & Stats Card -->
                    <div class="col-xl-6">
                        <div class="card card-hover border-start border-success border-4 shadow-sm">
                            <div class="card-body">
                                <h6 class="card-title text-muted mb-3">
                                    <i class="bx bx-stats me-2"></i> Transfer Status & Summary
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <p class="text-muted mb-1">Status</p>
                                        <?php 
                                        $status_class = match($transfer['status']) {
                                            'pending' => 'warning',
                                            'approved' => 'info',
                                            'in_transit' => 'primary',
                                            'delivered' => 'success',
                                            'cancelled' => 'danger',
                                            default => 'secondary'
                                        };
                                        $status_icon = match($transfer['status']) {
                                            'pending' => 'bx bx-time',
                                            'approved' => 'bx bx-check-circle',
                                            'in_transit' => 'bx bx-truck',
                                            'delivered' => 'bx bx-check-double',
                                            'cancelled' => 'bx bx-x-circle',
                                            default => 'bx bx-info-circle'
                                        };
                                        $status_text = ucwords(str_replace('_', ' ', $transfer['status']));
                                        ?>
                                        <span class="badge bg-<?= $status_class ?> bg-opacity-10 text-<?= $status_class ?> px-3 py-2">
                                            <i class="<?= $status_icon ?> me-1"></i><?= $status_text ?>
                                        </span>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="text-muted mb-1">Created By</p>
                                        <h6><?= htmlspecialchars($transfer['created_by_name'] ?? '—') ?></h6>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="text-muted mb-1">Total Items</p>
                                        <h4 class="text-info"><?= $transfer['total_items'] ?? 0 ?></h4>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="text-muted mb-1">Total Quantity</p>
                                        <h4 class="text-success"><?= $transfer['total_quantity'] ?? 0 ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Timeline Card -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-4">
                                    <i class="bx bx-history me-2"></i> Transfer Timeline
                                </h5>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="card border-start border-success border-3">
                                            <div class="card-body text-center">
                                                <i class="bx bx-check-circle text-success fs-1 mb-2"></i>
                                                <h6>Created</h6>
                                                <p class="text-muted mb-0 small">
                                                    <?= date('d M Y', strtotime($transfer['created_at'])) ?><br>
                                                    <?= date('h:i A', strtotime($transfer['created_at'])) ?>
                                                </p>
                                                <small class="text-muted">by <?= htmlspecialchars($transfer['created_by_name'] ?? '—') ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($transfer['status'] !== 'pending'): ?>
                                    <div class="col-md-3">
                                        <div class="card border-start border-info border-3">
                                            <div class="card-body text-center">
                                                <i class="bx bx-check-double text-info fs-1 mb-2"></i>
                                                <h6>Approved</h6>
                                                <p class="text-muted mb-0 small">
                                                    <?php if ($transfer['approved_by_name']): ?>
                                                    by <?= htmlspecialchars($transfer['approved_by_name']) ?>
                                                    <?php else: ?>
                                                    <em>System</em>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($transfer['status'], ['in_transit', 'delivered'])): ?>
                                    <div class="col-md-3">
                                        <div class="card border-start border-primary border-3">
                                            <div class="card-body text-center">
                                                <i class="bx bx-truck text-primary fs-1 mb-2"></i>
                                                <h6>In Transit</h6>
                                                <p class="text-muted mb-0">Dispatched</p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($transfer['status'] === 'delivered'): ?>
                                    <div class="col-md-3">
                                        <div class="card border-start border-success border-3">
                                            <div class="card-body text-center">
                                                <i class="bx bx-package text-success fs-1 mb-2"></i>
                                                <h6>Delivered</h6>
                                                <p class="text-muted mb-0 small">
                                                    <?php if ($transfer['received_by_name']): ?>
                                                    Received by <?= htmlspecialchars($transfer['received_by_name']) ?>
                                                    <?php else: ?>
                                                    <em>Awaiting confirmation</em>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Table Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="card-title mb-0">
                                        <i class="bx bx-package me-2"></i> Transferred Products
                                        <span class="badge bg-primary ms-2"><?= count($items) ?> items</span>
                                    </h5>
                                    <div class="text-muted">
                                        Total Quantity: <strong><?= $transfer['total_quantity'] ?? 0 ?></strong>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table id="itemsTable" class="table table-hover align-middle w-100">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="5%">#</th>
                                                <th>Product</th>
                                                <th>Code / Barcode</th>
                                                <th class="text-center">Quantity</th>
                                                <th class="text-center">Status</th>
                                                <?php if ($transfer['status'] === 'pending'): ?>
                                                <th class="text-center">Actions</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($items)): ?>
                                            <tr>
                                                <td colspan="<?= $transfer['status'] === 'pending' ? '6' : '5' ?>" class="text-center py-5">
                                                    <div class="empty-state">
                                                        <i class="bx bx-package fs-1 text-muted mb-3"></i>
                                                        <h5>No Items Found</h5>
                                                        <p class="text-muted">No products added to this transfer</p>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($items as $i => $item): ?>
                                            <tr class="item-row" data-id="<?= $item['id'] ?>">
                                                <td><?= $i + 1 ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm me-3">
                                                            <div class="avatar-title bg-light text-primary rounded">
                                                                <i class="bx bx-package fs-4"></i>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <strong class="d-block mb-1"><?= htmlspecialchars($item['product_name']) ?></strong>
                                                            <?php if ($item['barcode']): ?>
                                                            <br><small class="text-muted"><i class="bx bx-barcode me-1"></i><?= htmlspecialchars($item['barcode']) ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark"><?= htmlspecialchars($item['product_code']) ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-info rounded-pill px-3 py-2 fs-6">
                                                        <?= $item['quantity'] ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-success bg-opacity-10 text-success px-3 py-1">
                                                        <i class="bx bx-check-circle me-1"></i> Transferred
                                                    </span>
                                                </td>
                                                <?php if ($transfer['status'] === 'pending'): ?>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-warning edit-item-btn"
                                                                data-id="<?= $item['id'] ?>"
                                                                data-product="<?= htmlspecialchars($item['product_name']) ?>"
                                                                data-quantity="<?= $item['quantity'] ?>"
                                                                data-bs-toggle="tooltip"
                                                                title="Edit Quantity">
                                                            <i class="bx bx-edit"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger remove-item-btn"
                                                                data-id="<?= $item['id'] ?>"
                                                                data-product="<?= htmlspecialchars($item['product_name']) ?>"
                                                                data-bs-toggle="tooltip"
                                                                title="Remove Item">
                                                            <i class="bx bx-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <?php endif; ?>
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

                <!-- Notes Card -->
                <?php if ($transfer['notes']): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-3">
                                    <i class="bx bx-note me-2"></i> Transfer Notes
                                </h5>
                                <div class="p-3 bg-light rounded">
                                    <?= nl2br(htmlspecialchars($transfer['notes'])) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <?php if (in_array($transfer['status'], ['pending', 'approved', 'in_transit'])): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Transfer Actions</h5>
                                <div class="d-flex justify-content-center gap-3">
                                    <?php if ($transfer['status'] === 'pending'): ?>
                                    <button onclick="updateStatus(<?= $transfer_id ?>, 'approved')" class="btn btn-success btn-lg px-4">
                                        <i class="bx bx-check-circle me-2"></i> Approve Transfer
                                    </button>
                                    <button onclick="updateStatus(<?= $transfer_id ?>, 'cancelled')" class="btn btn-danger btn-lg px-4">
                                        <i class="bx bx-x-circle me-2"></i> Cancel Transfer
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($transfer['status'] === 'approved'): ?>
                                    <button onclick="updateStatus(<?= $transfer_id ?>, 'in_transit')" class="btn btn-primary btn-lg px-4">
                                        <i class="bx bx-truck me-2"></i> Mark as Dispatched
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($transfer['status'] === 'in_transit'): ?>
                                    <button onclick="updateStatus(<?= $transfer_id ?>, 'delivered')" class="btn btn-success btn-lg px-4">
                                        <i class="bx bx-package me-2"></i> Mark as Delivered
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Edit Item Modal -->
<?php if ($transfer['status'] === 'pending'): ?>
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Item Quantity</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editItemForm">
                    <input type="hidden" id="editItemId">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" id="editProductName" class="form-control bg-light" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" id="editQuantity" class="form-control" min="1" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveItemEdit">
                    <i class="bx bx-check me-1"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/scripts.php'; ?>
<!-- Add DataTables JS -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs5/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#itemsTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        language: {
            search: "Search items:",
            lengthMenu: "Show _MENU_ items",
            info: "Showing _START_ to _END_ of _TOTAL_ items",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        },
        columnDefs: [
            {
                targets: [3, 4], // Quantity and Status columns
                className: 'dt-body-center'
            }
        ]
    });

    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Row hover
    $('.item-row').hover(
        function() { $(this).addClass('bg-light'); },
        function() { $(this).removeClass('bg-light'); }
    );

    // Edit item modal
    const editItemModal = new bootstrap.Modal('#editItemModal');
    $('.edit-item-btn').click(function() {
        $('#editItemId').val($(this).data('id'));
        $('#editProductName').val($(this).data('product'));
        $('#editQuantity').val($(this).data('quantity'));
        editItemModal.show();
    });

    // Save item edit
    $('#saveItemEdit').click(function() {
        const itemId = $('#editItemId').val();
        const quantity = $('#editQuantity').val();
        
        if (!quantity || quantity < 1) {
            showToast('error', 'Please enter a valid quantity');
            return;
        }
        
        const originalText = $(this).html();
        $(this).html('<i class="bx bx-loader bx-spin me-1"></i> Saving...').prop('disabled', true);
        
        fetch('stock_transfer_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=edit_item&item_id=${itemId}&quantity=${quantity}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('success', 'Item quantity updated!');
                editItemModal.hide();
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', data.message || 'Error updating item');
                $(this).html(originalText).prop('disabled', false);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', 'Network error. Please try again.');
            $(this).html(originalText).prop('disabled', false);
        });
    });

    // Remove item
    $('.remove-item-btn').click(function() {
        const itemId = $(this).data('id');
        const productName = $(this).data('product');
        
        if (confirm(`Remove "${productName}" from this transfer?`)) {
            fetch('stock_transfer_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=remove_item&item_id=${itemId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('success', 'Item removed from transfer!');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('error', data.message || 'Error removing item');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('error', 'Network error. Please try again.');
            });
        }
    });
});

function updateStatus(id, status) {
    const actions = {
        'approved': 'Approve this transfer? This will lock the items for transfer.',
        'cancelled': 'Cancel this transfer? This action cannot be undone.',
        'in_transit': 'Mark as Dispatched? The items are now in transit.',
        'delivered': 'Mark as Delivered? This will update stock levels at destination.'
    };
    
    if (confirm(actions[status])) {
        fetch('stock_transfer_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=status_update&id=${id}&status=${status}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('success', 'Transfer status updated!');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', data.message || 'Error updating status');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('error', 'Network error. Please try again.');
        });
    }
}

function showToast(type, message) {
    $('.toast').remove();
    const toast = $(`<div class="toast align-items-center text-bg-${type} border-0" role="alert"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
    if ($('.toast-container').length === 0) $('body').append('<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999"></div>');
    $('.toast-container').append(toast);
    new bootstrap.Toast(toast[0]).show();
}
</script>

<style>
.table-hover tbody tr:hover {
    background-color: rgba(91, 115, 232, 0.05) !important;
}
.border-start {
    border-left-width: 4px !important;
}
.card-hover {
    transition: all 0.3s ease;
}
.card-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}
.avatar-sm {
    width: 40px;
    height: 40px;
}
.avatar-title {
    display: flex;
    align-items: center;
    justify-content: center;
}
.empty-state {
    padding: 3rem 1rem;
    text-align: center;
}
.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
}
.empty-state h5 {
    margin-bottom: 0.5rem;
}
.empty-state p {
    margin-bottom: 1.5rem;
}
.btn-group .btn {
    border-radius: 0.25rem !important;
}
.btn-group .btn:first-child {
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
}
.btn-group .btn:last-child {
    border-top-left-radius: 0 !important;
    border-bottom-left-radius: 0 !important;
}
@media print {
    .no-print, .btn, .card-hover:hover { display: none !important; }
    body { background: white !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
}
</style>
</body>
</html>